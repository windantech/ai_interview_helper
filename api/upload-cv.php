<?php

declare(strict_types=1);

/*
 * POST /api/upload-cv.php  (multipart: cv=<file>)
 * Uploads/replaces the CV, extracts text and builds the compressed AI candidate profile.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\CV;
use App\Services\CVService;

Api::handle(function (): void {
    $user = Api::postGuard();
    RateLimiter::enforce('cv_upload', 'u' . $user['id']);
    @set_time_limit(180);

    if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        Logger::warning('CV upload exceeded post_max_size');
        throw new HttpException(413, 'The file is too large. Maximum size is ' . format_bytes((int) config('app.max_cv_size_bytes')) . '.');
    }

    try {
        $meta = (new CVService())->upload((int) $user['id'], $_FILES['cv'] ?? null);
    } catch (HttpException $e) {
        Logger::warning('CV upload failed', ['user_id' => $user['id'], 'status' => $e->status(), 'reason' => $e->getMessage()]);
        throw $e;
    }

    $cv = CV::forUser((int) $user['id']);
    Response::success([
        'cv'      => cv_public($meta),
        'profile' => CV::profile($cv),
    ], 201);
});

/** Public-safe CV metadata. */
function cv_public(array $m): array
{
    return [
        'original_filename' => $m['original_filename'] ?? '',
        'file_size'         => (int) ($m['file_size'] ?? 0),
        'file_size_label'   => format_bytes((int) ($m['file_size'] ?? 0)),
        'status'            => $m['status'] ?? 'processing',
        'uploaded_at'       => format_date($m['created_at'] ?? null),
    ];
}
