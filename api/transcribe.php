<?php

declare(strict_types=1);

/*
 * POST /api/transcribe.php  (multipart: audio=<file>, session_id)
 * Fallback recorded-question mode: uploads audio to OpenAI /v1/audio/transcriptions (gpt-transcribe).
 * Audio is stored only temporarily and deleted immediately after transcription.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Models\UsageLog;
use App\Services\FileUploadService;
use App\Services\InterviewService;
use App\Services\OpenAIClient;

Api::handle(function (): void {
    $user = Api::postGuard();
    RateLimiter::enforce('transcribe', 'u' . $user['id']);

    if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        Logger::warning('Audio upload exceeded post_max_size');
        throw new \App\Core\HttpException(413, 'The recording is too large. Please ask for shorter questions or use live mode.');
    }

    $valid = FileUploadService::validate($_FILES['audio'] ?? null, FileUploadService::AUDIO_TYPES, (int) config('app.max_audio_size_bytes'), 'recording');

    // Who is speaking + names/terms from the CV and job, so e.g. "Eval360" isn't heard as "Harvard 360".
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $s = $sessionId > 0 ? InterviewSession::findForUser($sessionId, (int) $user['id']) : null;
    $prompt = InterviewService::transcriptionPrompt((int) $user['id'], $s);

    $dir = rtrim((string) config('app.storage_path'), '/') . '/audio';
    $path = FileUploadService::store($valid['tmp'], $dir, FileUploadService::randomName($valid['ext']));
    try {
        $model = (string) config('openai.transcribe_model');
        $result = (new OpenAIClient())->transcribeAudio(
            $path,
            'question.' . $valid['ext'],
            $valid['mime'],
            $model,
            (string) config('openai.transcribe_language', '') ?: null,
            $prompt
        );
    } finally {
        FileUploadService::deleteFile($path);
    }

    $u = $result['usage'] ?? [];
    UsageLog::record((int) $user['id'], 'transcribe', $model, (int) ($u['input_tokens'] ?? 0), (int) ($u['output_tokens'] ?? 0));

    $text = trim($result['text']);
    Response::success([
        'transcript' => $text,
        'plausible'  => InterviewService::plausibleQuestion($text),
    ]);
});
