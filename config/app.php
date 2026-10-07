<?php

declare(strict_types=1);

return [
    'name'            => env('APP_NAME', 'AI Interview Copilot'),
    'env'             => env('APP_ENV', 'production'),
    'url'             => rtrim((string) env('APP_URL', ''), '/'),
    'timezone'        => env('APP_TIMEZONE', 'UTC'),

    // Session / auth
    'session_timeout_minutes' => (int) env('SESSION_TIMEOUT_MINUTES', 120),
    'session_name'            => 'icp_session',

    // Upload limits
    'max_cv_size_bytes'    => (int) round((float) env('MAX_CV_SIZE_MB', 10) * 1024 * 1024),
    'max_audio_size_bytes' => (int) round((float) env('MAX_AUDIO_SIZE_MB', 20) * 1024 * 1024),
    // Scanned question papers: per page/file limit and how many pages one scan may contain.
    'max_scan_page_bytes'  => (int) round((float) env('MAX_SCAN_PAGE_MB', 8) * 1024 * 1024),
    'max_scan_pages'       => max(1, min(12, (int) env('MAX_SCAN_PAGES', 8))),
    // Whole-scan ceiling: pages are base64-encoded into one request, so cap the total.
    'max_scan_total_bytes' => (int) round((float) env('MAX_SCAN_TOTAL_MB', 20) * 1024 * 1024),

    // Answer defaults
    'default_answer_length' => in_array(env('DEFAULT_ANSWER_LENGTH', 'short'), ['short', 'medium'], true)
        ? env('DEFAULT_ANSWER_LENGTH', 'short') : 'short',

    // Storage (outside web access; protected by .htaccess and router.php)
    // Override with STORAGE_PATH to keep files outside the web root (also used by the test suites).
    'storage_path' => rtrim((string) env('STORAGE_PATH', dirname(__DIR__) . '/storage'), '/'),

    // Mail
    'mail' => [
        'enabled'   => filter_var(env('MAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'from'      => env('MAIL_FROM', 'no-reply@example.com'),
        'from_name' => env('MAIL_FROM_NAME', 'AI Interview Copilot'),
    ],

    // Rate limits: [max attempts, window seconds]
    'rate_limits' => [
        'login'            => [6, 900],
        'register'         => [5, 3600],
        'forgot_password'  => [4, 3600],
        'reset_password'   => [10, 3600],
        // Answering a whole scanned paper is a legitimate burst of answer calls.
        'generate_answer'  => [60, 60],
        'transcribe'       => [40, 60],
        'realtime_session' => [30, 600],
        'cv_upload'        => [12, 3600],
        'practice'         => [40, 60],
        'scan_extract'     => [20, 600],
        // Live scanning sends a frame whenever the view settles on something new.
        'scan_live'        => [90, 600],
        'api_general'      => [120, 60],
    ],
];
