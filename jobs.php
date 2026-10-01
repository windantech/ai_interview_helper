<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Models\Job;
use App\Models\Settings;

$user = Auth::requireUser();
$userId = (int) $user['id'];
$settings = Settings::forUser($userId);
$jobs = Job::forUser($userId);

$editJob = null;
if (isset($_GET['edit'])) {
    $editJob = Job::findForUser((int) $_GET['edit'], $userId);
}
$showForm = $editJob !== null || isset($_GET['new']) || !$jobs;

View::page('pages/jobs', [
    'title'       => 'Target jobs',
    'active'      => 'jobs',
    'user'        => $user,
    'jobs'        => $jobs,
    'activeJobId' => $settings['active_job_id'],
    'editJob'     => $editJob,
    'showForm'    => $showForm,
    'defaultType' => $settings['default_interview_type'],
    'scripts'     => ['jobs.js'],
]);
