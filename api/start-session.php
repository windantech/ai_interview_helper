<?php

declare(strict_types=1);

/*
 * POST /api/start-session.php  JSON: {job_id?, type: live|practice}
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Models\Job;
use App\Models\Settings;
use App\Services\InterviewService;

Api::handle(function (): void {
    $user = Api::postGuard();
    $in = Api::input();
    $userId = (int) $user['id'];
    $type = ($in['type'] ?? 'live') === 'practice' ? 'practice' : 'live';

    $job = null;
    if (!empty($in['job_id'])) {
        $job = Job::findForUser((int) $in['job_id'], $userId);
        if (!$job) {
            throw new HttpException(404, 'Selected job not found.');
        }
        Settings::update($userId, ['active_job_id' => (int) $job['id']]);
    }

    // Analyse the job description once (cached) so each question sends a compact summary.
    $job = (new InterviewService())->ensureJobSummary($userId, $job);

    InterviewSession::endActive($userId, $type);
    $id = InterviewSession::create($userId, $job, $type);
    $session = InterviewSession::findForUser($id, $userId);

    Response::success([
        'session' => [
            'id'         => $id,
            'title'      => $session['title'],
            'job_title'  => $session['job_title'],
            'company'    => $session['company'],
            'type'       => $type,
            'started_at' => $session['started_at'],
        ],
    ], 201);
});
