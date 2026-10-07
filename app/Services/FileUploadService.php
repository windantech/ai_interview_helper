<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;
use finfo;

/**
 * Validates and stores uploaded files privately.
 *  - extension whitelist + MIME detection with finfo (never trusts the browser-supplied type)
 *  - size limits
 *  - blocks scripts / executables / embedded PHP
 *  - random stored filenames inside /storage (not web-accessible)
 */
final class FileUploadService
{
    /** ext => allowed finfo MIME types */
    public const CV_TYPES = [
        'pdf'  => ['application/pdf', 'application/x-pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'txt'  => ['text/plain', 'text/x-c', 'text/x-asm', 'application/octet-stream', 'text/csv'],
    ];

    /** Page images a camera scan can produce (formats the OpenAI vision input accepts). */
    public const IMAGE_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
    ];

    /** A scanned question paper: page images, or a PDF of the paper. */
    public const SCAN_TYPES = self::IMAGE_TYPES + [
        'pdf' => ['application/pdf', 'application/x-pdf'],
    ];

    /** Canonical MIME to report/send per extension. */
    public const CANONICAL_MIME = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'webm' => 'audio/webm',
        'wav'  => 'audio/wav',
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'mp4'  => 'audio/mp4',
        'ogg'  => 'audio/ogg',
    ];

    public const AUDIO_TYPES = [
        'webm' => ['audio/webm', 'video/webm', 'application/octet-stream'],
        'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
        'mp3'  => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'application/octet-stream'],
        'm4a'  => ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4', 'audio/aac', 'application/octet-stream'],
        'mp4'  => ['audio/mp4', 'video/mp4', 'audio/x-m4a', 'application/octet-stream'],
        'ogg'  => ['audio/ogg', 'video/ogg', 'application/ogg'],
    ];

    private const BLOCKED_EXT = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'dll', 'bat', 'cmd', 'com', 'js', 'mjs', 'html', 'htm', 'svg', 'shtml', 'htaccess', 'jar', 'msi', 'vbs', 'ps1', 'asp', 'aspx', 'jsp'];

    /**
     * Validate an entry from $_FILES.
     * @param array<string,mixed>|null $file
     * @param array<string,list<string>> $allowed
     * @return array{tmp:string,ext:string,mime:string,size:int,original:string}
     */
    public static function validate(?array $file, array $allowed, int $maxBytes, string $kind = 'file'): array
    {
        if (!$file || !isset($file['error']) || is_array($file['error'])) {
            throw new HttpException(400, "No $kind was uploaded.");
        }
        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new HttpException(413, sprintf('The %s is too large. Maximum size is %s.', $kind, format_bytes($maxBytes)));
            case UPLOAD_ERR_PARTIAL:
                throw new HttpException(400, "The $kind upload was interrupted. Please try again.");
            case UPLOAD_ERR_NO_FILE:
                throw new HttpException(400, "Please choose a $kind to upload.");
            default:
                Logger::error('Upload error code', ['code' => $file['error']]);
                throw new HttpException(500, "The server could not save the $kind. Please try again.");
        }

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp) && !(PHP_SAPI === 'cli' && is_file($tmp))) {
            Logger::security('Upload: tmp file not an uploaded file');
            throw new HttpException(400, "Invalid $kind upload.");
        }
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new HttpException(400, "The $kind is empty.");
        }
        if ($size > $maxBytes) {
            throw new HttpException(413, sprintf('The %s is too large. Maximum size is %s.', $kind, format_bytes($maxBytes)));
        }

        $original = self::sanitizeFilename((string) ($file['name'] ?? $kind));
        $parts = explode('.', strtolower($original));
        $ext = count($parts) > 1 ? (string) end($parts) : '';
        foreach ($parts as $i => $p) {
            if ($i > 0 && in_array($p, self::BLOCKED_EXT, true)) {
                Logger::security('Upload: blocked extension', ['ext' => $p]);
                throw new HttpException(415, 'This file type is not allowed.');
            }
        }
        if (!isset($allowed[$ext])) {
            throw new HttpException(415, sprintf('Unsupported %s format. Allowed: %s.', $kind, strtoupper(implode(', ', array_keys($allowed)))));
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, $allowed[$ext], true)) {
            Logger::warning('Upload: MIME mismatch', ['ext' => $ext, 'mime' => $mime]);
            throw new HttpException(415, sprintf('The file content does not look like a valid %s file.', strtoupper($ext)));
        }

        self::assertNotExecutable($tmp, $ext);

        return ['tmp' => $tmp, 'ext' => $ext, 'mime' => self::CANONICAL_MIME[$ext] ?? $mime, 'size' => $size, 'original' => $original];
    }

    /** Extra signature checks for formats where finfo can be ambiguous. */
    private static function assertNotExecutable(string $path, string $ext): void
    {
        $fh = fopen($path, 'rb');
        $head = $fh ? (string) fread($fh, 8192) : '';
        if ($fh) {
            fclose($fh);
        }
        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7FELF") || str_starts_with($head, '#!')) {
            Logger::security('Upload: executable signature detected', ['ext' => $ext]);
            throw new HttpException(415, 'This file type is not allowed.');
        }
        if (in_array($ext, ['txt'], true) && preg_match('/<\?php|<script\b/i', $head)) {
            Logger::security('Upload: script content in text upload');
            throw new HttpException(415, 'The file contains content that is not allowed.');
        }
        $signatures = [
            'pdf'  => fn () => str_starts_with(ltrim($head), '%PDF'),
            'docx' => fn () => str_starts_with($head, "PK\x03\x04"),
            'doc'  => fn () => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'webm' => fn () => str_starts_with($head, "\x1A\x45\xDF\xA3"),
            'wav'  => fn () => str_starts_with($head, 'RIFF'),
            'ogg'  => fn () => str_starts_with($head, 'OggS'),
            'jpg'  => fn () => str_starts_with($head, "\xFF\xD8\xFF"),
            'jpeg' => fn () => str_starts_with($head, "\xFF\xD8\xFF"),
            'png'  => fn () => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'webp' => fn () => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
        ];
        if (isset($signatures[$ext]) && !$signatures[$ext]()) {
            throw new HttpException(415, sprintf('The file does not look like a valid %s file.', strtoupper($ext)));
        }
        if ($ext === 'docx' && !str_contains($head . self::zipNames($path), 'word/')) {
            throw new HttpException(415, 'The file does not look like a valid DOCX document.');
        }
    }

    /** Central-directory names of a zip (cheap check without ZipArchive). */
    private static function zipNames(string $path): string
    {
        if (class_exists(\ZipArchive::class)) {
            $z = new \ZipArchive();
            if ($z->open($path) === true) {
                $names = [];
                for ($i = 0; $i < min($z->numFiles, 200); $i++) {
                    $names[] = (string) $z->getNameIndex($i);
                }
                $z->close();
                return implode("\n", $names);
            }
            return '';
        }
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        fseek($fh, max(0, $size - 65536));
        $tail = (string) fread($fh, 65536);
        fclose($fh);
        return $tail;
    }

    public static function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}\s._()\-]+/u', '_', $name) ?? 'file';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '', ' .');
        return mb_substr($name !== '' ? $name : 'file', 0, 200);
    }

    public static function randomName(string $ext): string
    {
        return bin2hex(random_bytes(20)) . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($ext));
    }

    /** Ensure a private directory exists inside storage. */
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            Logger::error('Could not create storage directory', ['dir' => str_replace(APP_ROOT, '', $dir)]);
            throw new HttpException(500, 'The server could not store the file. Please check storage permissions.');
        }
    }

    /** Move the validated temp file into place. */
    public static function store(string $tmp, string $dir, string $storedName): string
    {
        self::ensureDir($dir);
        $dest = rtrim($dir, '/') . '/' . $storedName;
        $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : (PHP_SAPI === 'cli' && copy($tmp, $dest));
        if (!$ok) {
            Logger::error('Failed to move uploaded file');
            throw new HttpException(500, 'The server could not save the file. Please check storage permissions.');
        }
        @chmod($dest, 0640);
        return $dest;
    }

    public static function deleteFile(string $path): void
    {
        $real = realpath($path);
        $storage = realpath((string) config('app.storage_path'));
        if ($real && $storage && str_starts_with($real, $storage . DIRECTORY_SEPARATOR) && is_file($real)) {
            @unlink($real);
        }
    }

    /** Recursively remove a directory inside storage (used for account deletion). */
    public static function deleteDir(string $dir): void
    {
        $real = realpath($dir);
        $storage = realpath((string) config('app.storage_path'));
        if (!$real || !$storage || !str_starts_with($real, $storage . DIRECTORY_SEPARATOR) || !is_dir($real)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}
