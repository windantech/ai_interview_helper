<?php

declare(strict_types=1);

/*
 * POST /api/start-session.php  JSON: {job_id?, type: live|practice, instructions?}
 * If "instructions" is omitted, the instructions from the user's last interview for the same job are carried over.
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

    if (array_key_exists('instructions', $in)) {
        $instructions = is_string($in['instructions']) ? $in['instructions'] : null;
        if ($instructions !== null && mb_strlen(trim($instructions)) > InterviewSession::MAX_INSTRUCTIONS) {
            throw new HttpException(422, 'Instructions are too long (max ' . InterviewSession::MAX_INSTRUCTIONS . ' characters).');
        }
    } else {
        $instructions = InterviewSession::lastInstructions($userId, $job ? (int) $job['id'] : null);
    }

    InterviewSession::endActive($userId, $type);
    $id = InterviewSession::create($userId, $job, $type, $instructions);
    $session = InterviewSession::findForUser($id, $userId);

    Response::success([
        'session' => [
            'id'         => $id,
            'title'      => $session['title'],
            'job_title'  => $session['job_title'],
            'company'    => $session['company'],
            'instructions' => $session['instructions'],
            'type'       => $type,
            'started_at' => $session['started_at'],
        ],
    ], 201);
});
