<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** HTML-escape output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_debug(): bool
{
    return config('app.env') === 'development';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Base path of the app derived from APP_URL (e.g. "/interview-copilot"). */
function base_path(): string
{
    $path = parse_url((string) config('app.url', ''), PHP_URL_PATH);
    return is_string($path) ? rtrim($path, '/') : '';
}

/** Build an app-relative URL: url('dashboard.php') => /interview-copilot/dashboard.php */
function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

/** Absolute URL (for emails). */
function absolute_url(string $path = '', array $query = []): string
{
    $base = (string) config('app.url', '');
    if ($base === '') {
        $scheme = is_https() ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $u = rtrim($base, '/') . '/' . ltrim($path, '/');
    return $query ? $u . '?' . http_build_query($query) : $u;
}

/** Versioned asset URL for cache busting. */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path): never
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $target, true, 303);
    exit;
}

function csrf_field(): string
{
    return Csrf::field();
}

function old(string $key, string $default = ''): string
{
    return Session::old($key, $default);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

/** Format a UTC DB datetime for display. */
function format_date(?string $datetime, string $format = 'j M Y, H:i'): string
{
    if (!$datetime) {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone((string) config('app.timezone', 'UTC')))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function format_duration(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return '—';
    }
    $secs = max(0, strtotime($end) - strtotime($start));
    if ($secs < 60) {
        return $secs . 's';
    }
    $m = intdiv($secs, 60);
    if ($m < 60) {
        return $m . ' min';
    }
    return intdiv($m, 60) . 'h ' . ($m % 60) . 'm';
}

/** Human labels for enumerations used across the UI. */
function label_for(string $group, ?string $value): string
{
    static $labels = [
        'interview_type' => [
            'general' => 'General', 'hr' => 'HR', 'technical' => 'Technical', 'behavioural' => 'Behavioural',
            'leadership' => 'Leadership', 'panel' => 'Panel', 'management' => 'Management',
            'graduate' => 'Graduate', 'executive' => 'Executive',
        ],
        'seniority' => [
            'intern' => 'Intern', 'entry' => 'Entry Level', 'mid' => 'Mid Level', 'senior' => 'Senior',
            'manager' => 'Manager', 'director' => 'Director', 'executive' => 'Executive',
        ],
        'answer_mode' => [
            'auto' => 'Auto', 'quick' => 'Quick', 'star' => 'STAR', 'technical' => 'Technical',
            'leadership' => 'Leadership', 'general' => 'General', 'written' => 'Written',
        ],
        'document_type' => [
            'interview_questions' => 'Interview questions', 'application_form' => 'Application form',
            'essay_exam' => 'Essay / exam paper', 'assignment' => 'Assignment',
            'job_description' => 'Job description', 'other' => 'Question sheet', 'unreadable' => 'Partly unreadable',
        ],
        'session_type' => ['live' => 'Interview', 'practice' => 'Practice', 'scan' => 'Scanned paper'],
        'question_type' => [
            'behavioural' => 'Behavioural', 'technical' => 'Technical', 'situational' => 'Situational',
            'leadership' => 'Leadership', 'competency' => 'Competency', 'motivation' => 'Motivation',
            'career_history' => 'Career history', 'salary_hr' => 'Salary / HR', 'problem_solving' => 'Problem solving',
            'management' => 'Management', 'communication' => 'Communication', 'general' => 'General', 'unknown' => 'Unknown',
        ],
    ];
    return $labels[$group][$value ?? ''] ?? ucfirst((string) $value);
}

/** @return array<string,string> */
function options_for(string $group): array
{
    return match ($group) {
        'interview_type' => array_combine(
            ['general', 'hr', 'technical', 'behavioural', 'leadership', 'panel', 'management', 'graduate', 'executive'],
            array_map(fn ($v) => label_for('interview_type', $v), ['general', 'hr', 'technical', 'behavioural', 'leadership', 'panel', 'management', 'graduate', 'executive'])
        ),
        'seniority' => array_combine(
            ['intern', 'entry', 'mid', 'senior', 'manager', 'director', 'executive'],
            array_map(fn ($v) => label_for('seniority', $v), ['intern', 'entry', 'mid', 'senior', 'manager', 'director', 'executive'])
        ),
        'answer_mode' => [
            'auto' => 'Auto', 'quick' => 'Quick', 'star' => 'STAR', 'technical' => 'Technical', 'leadership' => 'Leadership',
        ],
        // Scanned papers can also be answered in writing (essays, application forms).
        'scan_answer_mode' => [
            'auto' => 'Auto', 'written' => 'Written', 'quick' => 'Quick', 'star' => 'STAR', 'technical' => 'Technical',
        ],
        default => [],
    };
}

/** Inline SVG icon (Lucide-style, stroke-based). Decorative by default (aria-hidden). */
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'mic'       => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/>',
        'square'    => '<rect x="6" y="6" width="12" height="12" rx="2"/>',
        'home'      => '<path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'history'   => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'settings'  => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'target'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x'         => '<path d="M18 6 6 18M6 6l12 12"/>',
        'alert'     => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'trash'     => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'eye'       => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'refresh'   => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'keyboard'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M6 12h.01M10 12h.01M14 12h.01M18 12h.01M7 16h10"/>',
        'send'      => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'sparkles'  => '<path d="m12 3-1.9 5.8L4 10.7l6.1 1.9L12 18.4l1.9-5.8 6.1-1.9-6.1-1.9Z"/><path d="M5 3v4M3 5h4M19 17v4M17 19h4"/>',
        'chat'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'skip'      => '<path d="m5 4 10 8-10 8Z"/><path d="M19 5v14"/>',
        'stop'      => '<rect x="5" y="5" width="14" height="14" rx="2"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'zap'       => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8Z"/>',
        'arrow-right' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'scan'      => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M3 12h18"/>',
        'camera'    => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3Z"/><circle cx="12" cy="13" r="3.5"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
    ];
    $svg = $paths[$name] ?? $paths['info'];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $svg . '</svg>';
}
