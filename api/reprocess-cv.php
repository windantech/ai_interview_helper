<?php

declare(strict_types=1);

/*
 * POST /api/reprocess-cv.php — re-runs AI extraction for an already uploaded CV (e.g. after a failure).
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\CV;
use App\Services\CVService;

Api::handle(function (): void {
    $user = Api::postGuard();
    RateLimiter::enforce('cv_upload', 'u' . $user['id']);
    @set_time_limit(180);
    $cv = CV::forUser((int) $user['id']);
    if (!$cv) {
        throw new HttpException(404, 'Upload a CV first.');
    }
    if (!is_file(CVService::filePath($cv))) {
        throw new HttpException(410, 'The original CV file is missing. Please upload it again.');
    }
    (new CVService())->process($cv);
    Response::success(['status' => 'ready', 'profile' => CV::profile(CV::forUser((int) $user['id']))]);
});
