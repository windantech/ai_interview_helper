<?php

declare(strict_types=1);

/*
 * OpenAI configuration. The API key is only ever read server-side.
 *
 * Endpoints used (verified against developers.openai.com docs):
 *   POST /v1/responses                 - answer generation + CV profiling (structured JSON output)
 *   POST /v1/audio/transcriptions      - fallback recorded-audio transcription (gpt-transcribe)
 *   POST /v1/realtime/client_secrets   - short-lived ephemeral key for browser WebRTC transcription
 *   POST /v1/realtime/calls            - (browser) WebRTC SDP exchange using the ephemeral key
 *   POST /v1/files, DELETE /v1/files/{id} - CV + scanned PDF file inputs (purpose=user_data)
 */

$pricing = [];
foreach (array_filter(array_map('trim', explode(',', (string) env('OPENAI_PRICING', '')))) as $entry) {
    $parts = explode(':', $entry);
    if (count($parts) === 3) {
        $pricing[$parts[0]] = ['input' => (float) $parts[1], 'output' => (float) $parts[2]];
    }
}

return [
    'api_key'         => env('OPENAI_API_KEY', ''),
    'base_url'        => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'organization'    => env('OPENAI_ORGANIZATION', ''),
    'project'         => env('OPENAI_PROJECT', ''),
    'timeout'         => (int) env('OPENAI_TIMEOUT', 45),
    'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 10),

    'answer_model'     => env('OPENAI_ANSWER_MODEL', 'gpt-6-luna'),
    'answer_reasoning' => env('OPENAI_ANSWER_REASONING', 'none'),
    'cv_model'         => env('OPENAI_CV_MODEL', 'gpt-6-luna'),
    'cv_reasoning'     => env('OPENAI_CV_REASONING', 'low'),
    // Reading a photographed/scanned question paper (vision input).
    'scan_model'       => env('OPENAI_SCAN_MODEL', 'gpt-6-luna'),
    'scan_reasoning'   => env('OPENAI_SCAN_REASONING', 'low'),

    'transcribe_model'          => env('OPENAI_TRANSCRIBE_MODEL', 'gpt-transcribe'),
    'realtime_transcribe_model' => env('OPENAI_REALTIME_TRANSCRIBE_MODEL', 'gpt-live-transcribe'),
    'realtime_enabled'          => filter_var(env('OPENAI_REALTIME_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'realtime_calls_url'        => 'https://api.openai.com/v1/realtime/calls',
    'realtime_secret_ttl'       => 600,
    'transcribe_language'       => env('OPENAI_TRANSCRIBE_LANGUAGE', 'en'),

    // Cost estimation (USD per 1M tokens)
    'pricing' => $pricing,

    // Answer generation token caps
    'max_output_tokens' => [
        'short'  => 900,
        'medium' => 1500,
        // Written (typed/essay) answers need room for full paragraphs.
        'written' => 2600,
    ],

    // Reading the questions off a scanned paper
    'scan_max_output_tokens' => (int) env('OPENAI_SCAN_MAX_OUTPUT_TOKENS', 4000),
];
