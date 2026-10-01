<?php

declare(strict_types=1);

/*
 * POST /api/select-job.php  JSON: {id}  — sets the current target job.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Job;
use App\Models\Settings;

Api::handle(function (): void {
    $user = Api::postGuard();
    $in = Api::input();
    $job = Job::findForUser((int) ($in['id'] ?? 0), (int) $user['id']);
    if (!$job) {
        throw new HttpException(404, 'Job not found.');
    }
    Settings::update((int) $user['id'], ['active_job_id' => (int) $job['id']]);
    Response::success(['active_job_id' => (int) $job['id'], 'title' => $job['title']]);
});
