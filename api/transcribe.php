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

    // Optional context hint to improve accuracy for role-specific vocabulary.
    $prompt = 'An interviewer asking a job interview question.';
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    if ($sessionId > 0 && ($s = InterviewSession::findForUser($sessionId, (int) $user['id'])) && $s['job_title']) {
        $prompt = 'A job interview for the role of ' . $s['job_title'] . ($s['company'] ? ' at ' . $s['company'] : '') . '. The interviewer asks a question.';
    }

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
