<?php

declare(strict_types=1);

/*
 * POST /api/delete-job.php  JSON: {id}
 * Interview history is kept (sessions keep a snapshot of the job title/company).
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Job;

Api::handle(function (): void {
    $user = Api::postGuard();
    $in = Api::input();
    if (!Job::delete((int) ($in['id'] ?? 0), (int) $user['id'])) {
        throw new HttpException(404, 'Job not found.');
    }
    Response::success(['deleted' => true]);
});
