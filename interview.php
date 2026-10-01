<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Models\CV;
use App\Models\InterviewSession;
use App\Models\Job;
use App\Models\Settings;

$user = Auth::requireUser();
$userId = (int) $user['id'];
$settings = Settings::forUser($userId);
$jobs = Job::forUser($userId);

// ?job=ID selects a job explicitly; otherwise use the current target job.
$jobId = isset($_GET['job']) ? (int) $_GET['job'] : $settings['active_job_id'];
$job = $jobId ? Job::findForUser($jobId, $userId) : null;
if ($job && (int) $job['id'] !== (int) $settings['active_job_id']) {
    Settings::update($userId, ['active_job_id' => (int) $job['id']]);
}

// Resume an active live session for the same job (if any) so questions stay grouped.
$session = InterviewSession::active($userId, 'live');
if ($session && (int) ($session['job_id'] ?? 0) !== (int) ($job['id'] ?? 0)) {
    $session = null;
}
$questions = $session ? InterviewSession::questions((int) $session['id']) : [];

View::page('pages/interview', [
    'title'     => 'Interview',
    'active'    => 'interview',
    'user'      => $user,
    'jobs'      => $jobs,
    'job'       => $job,
    'cv'        => CV::metaForUser($userId),
    'settings'  => $settings,
    'session'   => $session,
    'questions' => $questions,
    'config'    => [
        'realtimeEnabled' => (bool) config('openai.realtime_enabled'),
        'maxAudioBytes'   => (int) config('app.max_audio_size_bytes'),
    ],
    'scripts'   => ['recorder.js', 'realtime.js', 'answer-render.js', 'interview.js'],
]);
