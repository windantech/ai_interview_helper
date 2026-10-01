<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;
use App\Models\CV;
use App\Models\UsageLog;

/**
 * CV upload, private storage, text extraction and AI profiling.
 *
 * Strategy (token-efficient):
 *  - TXT / DOCX: text is extracted locally and sent as text.
 *  - PDF / DOC (or DOCX when ext-zip is missing): the file is uploaded to the OpenAI Files API
 *    (purpose=user_data) and passed to the Responses API as an input_file; the model returns the
 *    plain text alongside the structured profile.
 *  - The compressed profile (cv_profile_json) is what gets sent during interviews, not the full CV.
 */
final class CVService
{
    public const MAX_TEXT_CHARS = 60000;
    private const MIN_LOCAL_TEXT = 200;

    public function __construct(private readonly OpenAIClient $ai = new OpenAIClient())
    {
    }

    public static function userDir(int $userId): string
    {
        return rtrim((string) config('app.storage_path'), '/') . '/users/' . $userId . '/cv';
    }

    public static function filePath(array $cv): string
    {
        return self::userDir((int) $cv['user_id']) . '/' . basename((string) $cv['stored_filename']);
    }

    /**
     * Validate, store, extract and profile a CV. Replaces any existing CV.
     * @param array<string,mixed>|null $file entry from $_FILES
     * @return array<string,mixed> CV metadata
     */
    public function upload(int $userId, ?array $file): array
    {
        $valid = FileUploadService::validate($file, FileUploadService::CV_TYPES, (int) config('app.max_cv_size_bytes'), 'CV');

        $existing = CV::forUser($userId);
        $stored = FileUploadService::randomName($valid['ext']);
        FileUploadService::store($valid['tmp'], self::userDir($userId), $stored);

        if ($existing) {
            $this->removeFiles($existing);
        }
        CV::upsert($userId, $valid['original'], $stored, $valid['mime'], $valid['size']);
        $cv = CV::forUser($userId);

        $this->process($cv);
        return CV::metaForUser($userId) ?? [];
    }

    /** Extract text + build the AI profile. Marks the CV as failed (keeping the file) on error. */
    public function process(array $cv): void
    {
        $userId = (int) $cv['user_id'];
        $path = self::filePath($cv);
        $ext = strtolower(pathinfo((string) $cv['stored_filename'], PATHINFO_EXTENSION));

        $text = null;
        try {
            $text = self::extractLocalText($path, $ext);
        } catch (\Throwable $e) {
            Logger::warning('Local CV text extraction failed', ['ext' => $ext, 'error' => $e->getMessage()]);
        }
        $hasLocalText = $text !== null && mb_strlen(trim($text)) >= self::MIN_LOCAL_TEXT;

        $fileId = null;
        try {
            if ($hasLocalText) {
                $profile = $this->profileFromText($userId, $text);
            } else {
                $fileId = $this->ai->uploadFile($path, self::safeOpenAIName($cv, $ext), FileUploadService::CANONICAL_MIME[$ext] ?? 'application/octet-stream');
                $profile = $this->profileFromFile($userId, $fileId);
                $modelText = trim((string) ($profile['plain_text'] ?? ''));
                $text = $modelText !== '' ? $modelText : $text;
            }
            unset($profile['plain_text']);
            $text = $text !== null ? mb_substr(self::normaliseText($text), 0, self::MAX_TEXT_CHARS) : null;
            CV::saveExtraction($userId, $text, $profile, $fileId);
        } catch (OpenAIException $e) {
            if ($fileId) {
                $this->ai->deleteFile($fileId);
            }
            $text = $text !== null ? mb_substr(self::normaliseText($text), 0, self::MAX_TEXT_CHARS) : null;
            CV::markFailed($userId, $e->getMessage(), $text);
            Logger::error('CV profiling failed', ['user_id' => $userId, 'kind' => $e->kind()]);
            throw new HttpException($e->status(), 'Your CV was saved, but we could not analyse it: ' . $e->getMessage(), ['cv_saved' => true]);
        } catch (HttpException $e) {
            CV::markFailed($userId, $e->getMessage(), $text);
            throw $e;
        }
    }

    /** Remove the CV row, private file and any OpenAI file. */
    public function delete(int $userId): bool
    {
        $cv = CV::forUser($userId);
        if (!$cv) {
            return false;
        }
        $this->removeFiles($cv);
        CV::delete($userId);
        return true;
    }

    private function removeFiles(array $cv): void
    {
        FileUploadService::deleteFile(self::filePath($cv));
        if (!empty($cv['openai_file_id'])) {
            $this->ai->deleteFile((string) $cv['openai_file_id']);
        }
    }

    private static function safeOpenAIName(array $cv, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', pathinfo((string) $cv['original_filename'], PATHINFO_FILENAME)) ?: 'cv';
        return mb_substr($base, 0, 60) . '.' . $ext;
    }

    // ================================================================ text extraction

    public static function extractLocalText(string $path, string $ext): ?string
    {
        return match ($ext) {
            'txt'  => self::toUtf8((string) file_get_contents($path)),
            'docx' => self::docxText($path),
            'doc'  => self::docText($path),
            default => null, // PDF handled by OpenAI file input
        };
    }

    private static function toUtf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        } elseif (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) {
            $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-16');
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $s;
    }

    private static function docxText(string $path): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || strlen($xml) > 30_000_000) {
            return null;
        }
        $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<w:tab[^>]*/>#', "\t", $xml) ?? $xml;
        $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml) ?? $xml;
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Best-effort text from legacy binary .doc (UTF-16LE text runs). OpenAI is used if this is insufficient. */
    private static function docText(string $path): ?string
    {
        $bin = (string) file_get_contents($path);
        if (preg_match_all('/(?:[\x20-\x7E]\x00){4,}/', $bin, $m)) {
            $parts = array_map(fn ($s) => (string) mb_convert_encoding($s, 'UTF-8', 'UTF-16LE'), $m[0]);
            $text = implode("\n", $parts);
            return mb_strlen($text) > 0 ? $text : null;
        }
        return null;
    }

    public static function normaliseText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    // ================================================================ AI profiling

    private function profileFromText(int $userId, string $text): array
    {
        $content = [[
            'type' => 'input_text',
            'text' => "Extract the candidate profile from this CV. Set plain_text to an empty string.\n\n<cv>\n"
                . mb_substr(self::normaliseText($text), 0, self::MAX_TEXT_CHARS) . "\n</cv>",
        ]];
        return $this->runProfile($userId, $content, false);
    }

    private function profileFromFile(int $userId, string $fileId): array
    {
        $content = [
            ['type' => 'input_file', 'file_id' => $fileId],
            ['type' => 'input_text', 'text' => 'Extract the candidate profile from the attached CV. Also return the full CV as plain_text (clean plain text, keep section headings, max ~6000 words).'],
        ];
        return $this->runProfile($userId, $content, true);
    }

    private function runProfile(int $userId, array $content, bool $needsText): array
    {
        $model = (string) config('openai.cv_model');
        $payload = [
            'model' => $model,
            'store' => false,
            'instructions' => self::PROFILE_INSTRUCTIONS,
            'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => [
                'type'   => 'json_schema',
                'name'   => 'candidate_profile',
                'strict' => true,
                'schema' => self::profileSchema(),
            ]],
            'max_output_tokens' => $needsText ? 16000 : 5000,
        ];
        $effort = (string) config('openai.cv_reasoning', '');
        if ($effort !== '') {
            $payload['reasoning'] = ['effort' => $effort];
        }

        $res = $this->ai->createResponse($payload, 120);
        $usage = OpenAIClient::usage($res);
        UsageLog::record($userId, 'cv_profile', $model, $usage['input'], $usage['output']);

        $profile = json_decode(InterviewService::stripJson(OpenAIClient::outputText($res)), true);
        if (!is_array($profile) || !isset($profile['skills'])) {
            throw new OpenAIException(502, 'The AI returned an unreadable CV profile. Please try again.', 'invalid_json');
        }
        return self::normaliseProfile($profile);
    }

    /** Defensive normalisation so the stored profile is always well-formed. */
    public static function normaliseProfile(array $p): array
    {
        $str = fn ($v, $max = 600) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
        $list = fn ($v, $n = 30, $max = 200) => array_values(array_slice(array_filter(array_map(fn ($x) => mb_substr(trim(is_scalar($x) ? (string) $x : ''), 0, $max), is_array($v) ? $v : [])), 0, $n));

        $roles = [];
        foreach (array_slice(is_array($p['roles'] ?? null) ? $p['roles'] : [], 0, 15) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $roles[] = [
                'title'      => $str($r['title'] ?? '', 150),
                'employer'   => $str($r['employer'] ?? '', 150),
                'dates'      => $str($r['dates'] ?? '', 60),
                'highlights' => $list($r['highlights'] ?? [], 6, 220),
            ];
        }
        $edu = [];
        foreach (array_slice(is_array($p['education'] ?? null) ? $p['education'] : [], 0, 8) as $e) {
            if (is_array($e)) {
                $edu[] = ['qualification' => $str($e['qualification'] ?? '', 200), 'institution' => $str($e['institution'] ?? '', 150), 'year' => $str($e['year'] ?? '', 30)];
            }
        }
        $projects = [];
        foreach (array_slice(is_array($p['projects'] ?? null) ? $p['projects'] : [], 0, 10) as $pr) {
            if (is_array($pr)) {
                $projects[] = ['name' => $str($pr['name'] ?? '', 150), 'description' => $str($pr['description'] ?? '', 300)];
            }
        }
        return [
            'full_name'        => $str($p['full_name'] ?? '', 120),
            'headline'         => $str($p['headline'] ?? '', 200),
            'summary'          => $str($p['summary'] ?? '', 900),
            'years_experience' => $str($p['years_experience'] ?? '', 40),
            'skills'           => $list($p['skills'] ?? [], 40, 80),
            'tools'            => $list($p['tools'] ?? [], 30, 80),
            'industries'       => $list($p['industries'] ?? [], 10, 80),
            'roles'            => $roles,
            'education'        => $edu,
            'certifications'   => $list($p['certifications'] ?? [], 15, 150),
            'projects'         => $projects,
            'achievements'     => $list($p['achievements'] ?? [], 15, 250),
            'leadership'       => $list($p['leadership'] ?? [], 10, 250),
            'plain_text'       => is_string($p['plain_text'] ?? null) ? $p['plain_text'] : '',
        ];
    }

    private const PROFILE_INSTRUCTIONS = <<<TXT
You convert a candidate's CV into a compact, factual JSON profile used to personalise interview answers.
Rules:
- Use ONLY information present in the CV. Never invent employers, dates, numbers, qualifications or achievements.
- If a field is not present, return an empty string or empty array.
- Keep wording short and specific. Preserve real metrics exactly as written (e.g. "cut costs 15%").
- roles: most recent first; highlights = up to 5 concrete responsibilities/achievements per role.
- achievements: the strongest quantified or notable results across the CV.
- leadership: concrete evidence of leading people, projects, stakeholders or change.
TXT;

    /** @return array<string,mixed> strict JSON schema for the candidate profile */
    public static function profileSchema(): array
    {
        $strArr = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['full_name', 'headline', 'summary', 'years_experience', 'skills', 'tools', 'industries', 'roles', 'education', 'certifications', 'projects', 'achievements', 'leadership', 'plain_text'],
            'properties' => [
                'full_name'        => ['type' => 'string'],
                'headline'         => ['type' => 'string', 'description' => 'Current or target professional title'],
                'summary'          => ['type' => 'string', 'description' => '2-3 sentence professional summary based only on the CV'],
                'years_experience' => ['type' => 'string'],
                'skills'           => $strArr,
                'tools'            => $strArr,
                'industries'       => $strArr,
                'roles' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['title', 'employer', 'dates', 'highlights'],
                    'properties' => ['title' => ['type' => 'string'], 'employer' => ['type' => 'string'], 'dates' => ['type' => 'string'], 'highlights' => $strArr],
                ]],
                'education' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['qualification', 'institution', 'year'],
                    'properties' => ['qualification' => ['type' => 'string'], 'institution' => ['type' => 'string'], 'year' => ['type' => 'string']],
                ]],
                'certifications' => $strArr,
                'projects' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['name', 'description'],
                    'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string']],
                ]],
                'achievements' => $strArr,
                'leadership'   => $strArr,
                'plain_text'   => ['type' => 'string'],
            ],
        ];
    }
}
