<?php

declare(strict_types=1);

/*
 * POST /api/generate-answer.php  JSON: {session_id, transcript, mode, source, question_id?, stream?}
 * Detects/cleans the interview question and returns structured, CV-aware answer guidance.
 * source: live | recorded | typed | practice | scan
 *
 * With "stream": true the response is Server-Sent Events so the answer appears while it is written:
 *   event: delta  data: {"t": "<raw JSON text chunk>"}
 *   event: done   data: <same object the non-streaming call returns in "data">
 *   event: error  data: {"message": "...", "status": 502, "error_kind": "..."}
 * Validation errors before streaming starts are returned as normal JSON errors.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Models\Settings;
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
    $source = in_array($in['source'] ?? '', ['live', 'recorded', 'typed', 'practice', 'scan'], true) ? $in['source'] : 'typed';

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

    $service = new InterviewService();

    if (empty($in['stream'])) {
        Response::success($service->generateAnswer($user, $session, $transcript, $mode, $source, $existingId));
    }

    // ------------------------------------------------------------ streaming (SSE)
    // Release the session lock so other requests from this user aren't blocked while the answer streams.
    // (When history is off the session stores interview context, so keep it open in that case.)
    if (Settings::forUser((int) $user['id'])['save_history']) {
        session_write_close();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, no-transform');
    header('X-Accel-Buffering: no');                 // nginx
    header('X-LiteSpeed-Cache-Control: no-cache');   // LiteSpeed (common on cPanel)
    header('Content-Encoding: identity');

    $send = function (string $event, array $data): void {
        echo "event: $event\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        @flush();
    };
    // Padding comment: some proxies only start forwarding after the first couple of KB.
    echo ':' . str_repeat(' ', 2048) . "\n\n";
    $send('start', ['ok' => true]);

    try {
        $result = $service->generateAnswer($user, $session, $transcript, $mode, $source, $existingId,
            fn (string $delta) => $send('delta', ['t' => $delta]));
        $send('done', $result);
    } catch (HttpException $e) {
        $send('error', ['message' => $e->getMessage(), 'status' => $e->status()] + $e->extra());
    } catch (\Throwable $e) {
        Logger::error('Streaming answer failed', ['error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
        $send('error', ['message' => 'Something went wrong. Please try again.', 'status' => 500]);
    }
    exit;
});
