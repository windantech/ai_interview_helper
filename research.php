<?php

declare(strict_types=1);

/*
 * Research instrumentation: how detectable is an AI-assisted interview?
 * Scores the signer-in user's own recorded sessions on signals an interviewer or hiring platform
 * could plausibly observe. See App\Services\DetectionService for what each signal means and why
 * none of them is proof.
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Models\InterviewSession;
use App\Services\DetectionService;

$user = Auth::requireUser();
$userId = (int) $user['id'];

$rows = [];
foreach (InterviewSession::recent($userId, 20) as $session) {
    $questions = InterviewSession::questions((int) $session['id']);
    $job = !empty($session['job_id'])
        ? Database::fetch('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [(int) $session['job_id'], $userId])
        : null;
    $rows[] = ['session' => $session, 'analysis' => DetectionService::analyse($session, $questions, $job)];
}

View::page('pages/research', [
    'title'  => 'Detection signals',
    'active' => 'research',
    'user'   => $user,
    'rows'   => $rows,
]);
