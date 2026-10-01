<?php

declare(strict_types=1);

/*
 * POST /api/practice-feedback.php  JSON: {question_id, answer_text}
 * Analyses the candidate's practice answer: strengths, missing points, better structure, improved answer.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Services\InterviewService;

Api::handle(function (): void {
    $user = Api::postGuard();
    RateLimiter::enforce('practice', 'u' . $user['id']);
    $in = Api::input();

    $q = InterviewSession::findQuestionForUser((int) ($in['question_id'] ?? 0), (int) $user['id']);
    if (!$q) {
        throw new HttpException(404, 'Practice question not found.');
    }
    $answer = trim((string) ($in['answer_text'] ?? ''));
    if (mb_strlen($answer) < 15) {
        throw new HttpException(422, 'Your answer is too short to analyse. Try speaking for a little longer.');
    }
    if (mb_strlen($answer) > 8000) {
        throw new HttpException(422, 'Your answer is too long to analyse.');
    }
    $session = InterviewSession::findForUser((int) $q['session_id'], (int) $user['id']);
    $feedback = (new InterviewService())->practiceFeedback($user, $session, (string) $q['question'], $answer);
    InterviewSession::updateQuestion((int) $q['id'], ['user_answer' => $answer, 'feedback_json' => $feedback]);
    Response::success(['feedback' => $feedback]);
});
