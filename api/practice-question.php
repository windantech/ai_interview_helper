<?php

declare(strict_types=1);

/*
 * POST /api/practice-question.php  JSON: {session_id, focus?}
 * Generates the next tailored practice question (one at a time) and stores it in the session.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Services\InterviewService;

Api::handle(function (): void {
    $user = Api::postGuard();
    RateLimiter::enforce('practice', 'u' . $user['id']);
    $in = Api::input();
    $session = InterviewSession::findForUser((int) ($in['session_id'] ?? 0), (int) $user['id']);
    if (!$session || $session['session_type'] !== 'practice') {
        throw new HttpException(404, 'Practice session not found. Start a new practice interview.');
    }
    if ($session['status'] !== 'active') {
        throw new HttpException(409, 'This practice session has ended.');
    }
    $job = $session['job_id'] ? Database::fetch('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [(int) $session['job_id'], (int) $user['id']]) : null;
    $previous = InterviewSession::questionTexts((int) $session['id']);
    if (count($previous) >= 40) {
        throw new HttpException(422, 'This practice session is full. Start a new one to continue.');
    }

    $q = (new InterviewService())->practiceQuestion($user, $session, $job, $previous, is_string($in['focus'] ?? null) ? $in['focus'] : null);
    $id = InterviewSession::addQuestion((int) $session['id'], [
        'question'      => $q['question'],
        'question_type' => $q['question_type'],
        'answer_mode'   => 'auto',
        'source'        => 'practice',
    ]);
    Response::success(['question_id' => $id, 'number' => count($previous) + 1] + $q);
});
