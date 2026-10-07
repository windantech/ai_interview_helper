<?php

declare(strict_types=1);

/*
 * POST /api/scan-question.php  JSON: {session_id, question_id?, text, number?}
 * Corrects a mis-read question on a scanned paper, or adds one the scan missed.
 * Editing a question clears any answer already stored for it (it no longer matches).
 * Returns the question id (null when the user has history saving switched off).
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Models\Settings;
use App\Services\ScanService;

Api::handle(function (): void {
    $user = Api::postGuard();
    $userId = (int) $user['id'];
    $in = Api::input();

    $session = InterviewSession::findForUser((int) ($in['session_id'] ?? 0), $userId);
    if (!$session || $session['session_type'] !== 'scan') {
        throw new HttpException(404, 'Scanned paper not found. Please scan it again.');
    }
    if ($session['status'] !== 'active') {
        throw new HttpException(409, 'This paper has been finished. Scan it again to keep working on it.');
    }

    $text = trim(preg_replace('/\s+/u', ' ', is_string($in['text'] ?? null) ? $in['text'] : '') ?? '');
    if (mb_strlen($text) < 8) {
        throw new HttpException(422, 'Please enter the full question (at least 8 characters).');
    }
    if (mb_strlen($text) > 1200) {
        throw new HttpException(422, 'That question is too long. Please shorten it.');
    }
    $number = mb_substr(trim((string) ($in['number'] ?? '')), 0, 20);

    if (!empty($in['question_id'])) {
        $q = InterviewSession::findQuestionForUser((int) $in['question_id'], $userId);
        if (!$q || (int) $q['session_id'] !== (int) $session['id']) {
            throw new HttpException(404, 'Question not found.');
        }
        InterviewSession::updateQuestion((int) $q['id'], [
            'question'        => $text,
            'question_number' => $number !== '' ? $number : null,
            'answer_json'     => null,
        ]);
        Response::success(['id' => (int) $q['id'], 'text' => $text, 'number' => $number]);
    }

    if (!Settings::forUser($userId)['save_history']) {
        Response::success(['id' => null, 'text' => $text, 'number' => $number], 201);
    }
    if (count(InterviewSession::questionTexts((int) $session['id'])) >= ScanService::MAX_QUESTIONS) {
        throw new HttpException(422, 'This paper already holds the maximum of ' . ScanService::MAX_QUESTIONS . ' questions.');
    }

    $id = InterviewSession::addQuestion((int) $session['id'], [
        'question'        => $text,
        'question_number' => $number !== '' ? $number : null,
        'question_type'   => 'unknown',
        'answer_mode'     => 'auto',
        'source'          => 'scan',
    ]);
    Response::success(['id' => $id, 'text' => $text, 'number' => $number], 201);
});
