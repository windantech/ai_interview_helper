<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Models\CV;
use App\Models\InterviewSession;
use App\Models\Job;
use App\Models\Settings;
use App\Services\ScanService;

$user = Auth::requireUser();
$userId = (int) $user['id'];
$settings = Settings::forUser($userId);
$jobs = Job::forUser($userId);

$jobId = isset($_GET['job']) ? (int) $_GET['job'] : $settings['active_job_id'];
$job = $jobId ? Job::findForUser($jobId, $userId) : null;

// Resume the last scan if it is still open, so a part-answered paper can be finished.
$session = InterviewSession::active($userId, 'scan');
$questions = $session ? array_map(fn ($q) => [
    'id'            => (int) $q['id'],
    'number'        => (string) ($q['question_number'] ?? ''),
    'text'          => (string) $q['question'],
    'marks'         => '',
    'question_type' => (string) $q['question_type'],
    'answer'        => $q['answer'],
], InterviewSession::questions((int) $session['id'])) : [];

// Instructions: the open scan's, otherwise carried over from the last session for this job.
$instructions = $session
    ? (string) ($session['instructions'] ?? '')
    : (string) InterviewSession::lastInstructions($userId, $job ? (int) $job['id'] : null);

View::page('pages/scan', [
    'title'     => 'Scan question paper',
    'active'    => 'scan',
    'user'      => $user,
    'jobs'      => $jobs,
    'job'       => $job,
    'cv'        => CV::metaForUser($userId),
    'settings'  => $settings,
    'session'   => $session,
    'questions' => $questions,
    'instructions' => $instructions,
    'config'    => [
        'maxPages'     => (int) config('app.max_scan_pages'),
        'maxPageBytes' => (int) config('app.max_scan_page_bytes'),
        'maxQuestions' => ScanService::MAX_QUESTIONS,
    ],
    'scripts'   => ['answer-render.js', 'page-capture.js', 'scan.js'],
]);
