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
$activeJob = $settings['active_job_id'] ? Job::findForUser($settings['active_job_id'], $userId) : null;

View::page('pages/dashboard', [
    'title'           => 'Dashboard',
    'active'          => 'dashboard',
    'user'            => $user,
    'cv'              => CV::metaForUser($userId),
    'activeJob'       => $activeJob,
    'jobCount'        => Job::count($userId),
    'stats'           => InterviewSession::stats($userId),
    'recent'          => InterviewSession::recent($userId, 5),
    'recentQuestions' => InterviewSession::recentQuestions($userId, 5),
    'apiConfigured'   => (string) config('openai.api_key') !== '',
]);
