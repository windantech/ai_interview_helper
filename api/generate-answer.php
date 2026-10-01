<?php

declare(strict_types=1);

/*
 * POST /api/generate-answer.php  JSON: {session_id, transcript, mode, source, question_id?}
 * Detects/cleans the interview question and returns structured, CV-aware answer guidance.
 * source: live | recorded | typed | practice
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
    RateLimiter::enforce('generate_answer', 'u' . $user['id']);
    $in = Api::input();

    $sessionId = (int) ($in['session_id'] ?? 0);
    $session = $sessionId > 0 ? InterviewSession::findForUser($sessionId, (int) $user['id']) : null;
    if (!$session) {
        throw new HttpException(404, 'Interview session not found. Please start a new interview.');
    }
    if ($session['status'] !== 'active') {
        throw new HttpException(409, 'This interview session has ended. Start a new interview to continue.');
    }

    $transcript = is_string($in['transcript'] ?? null) ? $in['transcript'] : '';
    if (mb_strlen($transcript) > 4000) {
        throw new HttpException(422, 'The question is too long. Please shorten it.');
    }
    $mode = is_string($in['mode'] ?? null) ? $in['mode'] : 'auto';
    $source = in_array($in['source'] ?? '', ['live', 'recorded', 'typed', 'practice'], true) ? $in['source'] : 'typed';

    $existingId = null;
    if (!empty($in['question_id'])) {
        $q = InterviewSession::findQuestionForUser((int) $in['question_id'], (int) $user['id']);
        if (!$q || (int) $q['session_id'] !== (int) $session['id']) {
            throw new HttpException(404, 'Question not found.');
        }
        $existingId = (int) $q['id'];
        $transcript = (string) $q['question'];
        $source = 'typed';
    }

    $result = (new InterviewService())->generateAnswer($user, $session, $transcript, $mode, $source, $existingId);
    Response::success($result);
});
