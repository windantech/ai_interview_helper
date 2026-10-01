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

View::page('pages/practice', [
    'title'    => 'Practice interview',
    'active'   => 'practice',
    'user'     => $user,
    'jobs'     => Job::forUser($userId),
    'activeJobId' => $settings['active_job_id'],
    'cv'       => CV::metaForUser($userId),
    'session'  => InterviewSession::active($userId, 'practice'),
    'consented'=> $settings['mic_consent_at'] !== null,
    'maxAudioBytes' => (int) config('app.max_audio_size_bytes'),
    'scripts'  => ['recorder.js', 'answer-render.js', 'practice.js'],
]);
