<?php

declare(strict_types=1);

/*
 * POST /api/realtime-session.php
 * Mints a short-lived OpenAI ephemeral client secret for browser WebRTC live transcription.
 * The permanent OPENAI_API_KEY never leaves the server; the browser only receives the ek_ value.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Services\InterviewService;
use App\Services\OpenAIClient;
use App\Services\OpenAIException;

Api::handle(function (): void {
    $user = Api::postGuard();
    RateLimiter::enforce('realtime_session', 'u' . $user['id']);

    if (!config('openai.realtime_enabled')) {
        throw new HttpException(503, 'Live transcription is disabled. Using recorded-question mode.', ['error_kind' => 'disabled']);
    }

    $model = (string) config('openai.realtime_transcribe_model');
    $language = (string) config('openai.transcribe_language', '');
    $ttl = (int) config('openai.realtime_secret_ttl', 600);

    // gpt-live-transcribe: turn detection must be omitted/null; the browser commits each turn
    // (input_audio_buffer.commit) when it detects the interviewer has stopped speaking.
    $transcription = ['model' => $model];
    if ($language !== '') {
        $transcription['language'] = $language;
    }
    // Expected vocabulary (CV projects/tools/employers + job terms) to reduce mis-heard names.
    $in = Api::input();
    $interview = !empty($in['session_id']) ? InterviewSession::findForUser((int) $in['session_id'], (int) $user['id']) : null;
    $transcription['prompt'] = InterviewService::transcriptionPrompt((int) $user['id'], $interview);
    $session = [
        'type'  => 'transcription',
        'audio' => ['input' => [
            'noise_reduction' => ['type' => 'far_field'],
            'transcription'   => $transcription,
            'turn_detection'  => null,
        ]],
    ];

    $client = new OpenAIClient();
    try {
        $secret = $client->createRealtimeSession($session, $ttl);
    } catch (OpenAIException $e) {
        if ($e->kind() !== 'bad_request') {
            throw $e;
        }
        // Retry with the minimal documented configuration in case optional fields are rejected.
        $secret = $client->createRealtimeSession([
            'type'  => 'transcription',
            'audio' => ['input' => ['transcription' => ['model' => $model], 'turn_detection' => null]],
        ], $ttl);
    }

    Response::success([
        'client_secret' => $secret['value'],
        'expires_at'    => $secret['expires_at'],
        'model'         => $model,
        'calls_url'     => (string) config('openai.realtime_calls_url'),
    ]);
});
