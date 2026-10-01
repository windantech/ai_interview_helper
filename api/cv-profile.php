<?php

declare(strict_types=1);

/*
 * GET /api/cv-profile.php — returns CV metadata + extracted profile (never the file itself).
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\Response;
use App\Models\CV;

Api::handle(function (): void {
    $user = Api::getGuard();
    $cv = CV::forUser((int) $user['id']);
    if (!$cv) {
        Response::success(['cv' => null, 'profile' => null]);
    }
    Response::success([
        'cv' => [
            'original_filename' => $cv['original_filename'],
            'file_size'         => (int) $cv['file_size'],
            'file_size_label'   => format_bytes((int) $cv['file_size']),
            'status'            => $cv['status'],
            'error'             => $cv['extraction_error'],
            'uploaded_at'       => format_date($cv['created_at']),
            'text_chars'        => $cv['cv_text'] ? mb_strlen((string) $cv['cv_text']) : 0,
        ],
        'profile' => CV::profile($cv),
    ]);
});
