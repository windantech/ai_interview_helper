<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use CURLFile;

/**
 * Lightweight OpenAI REST client built on PHP cURL (no unofficial SDK).
 *
 * The API key is read from server config only and is never returned to callers.
 */
final class OpenAIClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, ?int $timeout = null)
    {
        $this->apiKey = (string) ($apiKey ?? config('openai.api_key', ''));
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('openai.base_url', 'https://api.openai.com/v1')), '/');
        $this->timeout = (int) ($timeout ?? config('openai.timeout', 45));
        $this->connectTimeout = (int) config('openai.connect_timeout', 10);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    // ================================================================ public API

    /**
     * POST /responses — returns the decoded response object.
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createResponse(array $payload, ?int $timeout = null): array
    {
        return $this->request('POST', '/responses', json: $payload, timeout: $timeout, retries: 1);
    }

    /**
     * POST /audio/transcriptions (multipart).
     * @return array{text:string,usage:array<string,mixed>|null}
     */
    public function transcribeAudio(string $filePath, string $filename, string $mime, ?string $model = null, ?string $language = null, ?string $prompt = null): array
    {
        $fields = [
            'file'            => new CURLFile($filePath, $mime, $filename),
            'model'           => $model ?? (string) config('openai.transcribe_model', 'gpt-transcribe'),
            'response_format' => 'json',
        ];
        if ($language) {
            $fields['language'] = $language;
        }
        if ($prompt) {
            $fields['prompt'] = mb_substr($prompt, 0, 800);
        }
        $res = $this->request('POST', '/audio/transcriptions', multipart: $fields, timeout: 60, retries: 1);
        return [
            'text'  => trim((string) ($res['text'] ?? '')),
            'usage' => isset($res['usage']) && is_array($res['usage']) ? $res['usage'] : null,
        ];
    }

    /** POST /files (purpose=user_data) — returns the file id. */
    public function uploadFile(string $filePath, string $filename, string $mime, string $purpose = 'user_data'): string
    {
        $res = $this->request('POST', '/files', multipart: [
            'file'    => new CURLFile($filePath, $mime, $filename),
            'purpose' => $purpose,
        ], timeout: 90);
        $id = (string) ($res['id'] ?? '');
        if ($id === '') {
            throw new OpenAIException(502, 'The AI service did not accept the file.', 'invalid_response');
        }
        return $id;
    }

    /** DELETE /files/{id}. Failures are logged and ignored (file may already be gone). */
    public function deleteFile(string $fileId): bool
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $fileId)) {
            return false;
        }
        try {
            $res = $this->request('DELETE', '/files/' . $fileId, timeout: 20);
            return (bool) ($res['deleted'] ?? false);
        } catch (OpenAIException $e) {
            Logger::warning('OpenAI file delete failed', ['file_id' => $fileId, 'kind' => $e->kind()]);
            return false;
        }
    }

    /**
     * POST /realtime/client_secrets — mints a short-lived ephemeral key for browser WebRTC transcription.
     * @param array<string,mixed> $session
     * @return array{value:string,expires_at:int,session:array<string,mixed>}
     */
    public function createRealtimeSession(array $session, int $ttlSeconds = 600): array
    {
        $res = $this->request('POST', '/realtime/client_secrets', json: [
            'expires_after' => ['anchor' => 'created_at', 'seconds' => max(10, min(7200, $ttlSeconds))],
            'session'       => $session,
        ], timeout: 15);
        $value = (string) ($res['value'] ?? ($res['client_secret']['value'] ?? ''));
        if ($value === '') {
            throw new OpenAIException(502, 'Live transcription could not be started.', 'invalid_response');
        }
        return [
            'value'      => $value,
            'expires_at' => (int) ($res['expires_at'] ?? ($res['client_secret']['expires_at'] ?? time() + $ttlSeconds)),
            'session'    => is_array($res['session'] ?? null) ? $res['session'] : [],
        ];
    }

    // ================================================================ helpers

    /** Extract concatenated output_text from a Responses API result; throws on refusal / incomplete. */
    public static function outputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text']) && $response['output_text'] !== '') {
            return $response['output_text'];
        }
        $text = '';
        foreach ((array) ($response['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $c) {
                $type = $c['type'] ?? '';
                if ($type === 'refusal') {
                    throw new OpenAIException(422, 'The AI declined to answer this request. Try rephrasing the question.', 'refusal');
                }
                if ($type === 'output_text') {
                    $text .= (string) ($c['text'] ?? '');
                }
            }
        }
        if ($text === '' && ($response['status'] ?? '') === 'incomplete') {
            throw new OpenAIException(502, 'The AI response was cut short. Please try again.', 'incomplete');
        }
        return $text;
    }

    /** @return array{input:int,output:int} */
    public static function usage(array $response): array
    {
        $u = (array) ($response['usage'] ?? []);
        return [
            'input'  => (int) ($u['input_tokens'] ?? $u['prompt_tokens'] ?? 0),
            'output' => (int) ($u['output_tokens'] ?? $u['completion_tokens'] ?? 0),
        ];
    }

    // ================================================================ transport

    /**
     * @param array<string,mixed>|null $json
     * @param array<string,mixed>|null $multipart
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $json = null, ?array $multipart = null, ?int $timeout = null, int $retries = 0): array
    {
        if (!$this->isConfigured()) {
            Logger::error('OpenAI API key missing');
            throw new OpenAIException(503, 'The AI service is not configured yet. Please add an OpenAI API key to the server .env file.', 'not_configured');
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->send($method, $path, $json, $multipart, $timeout ?? $this->timeout);
            } catch (OpenAIException $e) {
                $retryable = in_array($e->kind(), ['server', 'rate_limit', 'network'], true);
                if (!$retryable || $attempt > $retries) {
                    throw $e;
                }
                usleep($e->kind() === 'rate_limit' ? 1_200_000 : 400_000);
            }
        }
    }

    /** @return array<string,mixed> */
    private function send(string $method, string $path, ?array $json, ?array $multipart, int $timeout): array
    {
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'];
        if (config('openai.organization')) {
            $headers[] = 'OpenAI-Organization: ' . config('openai.organization');
        }
        if (config('openai.project')) {
            $headers[] = 'OpenAI-Project: ' . config('openai.project');
        }

        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } elseif ($multipart !== null) {
            $opts[CURLOPT_POSTFIELDS] = $multipart; // cURL sets multipart/form-data boundary
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $started = microtime(true);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch); // handle is freed automatically (curl_close is a deprecated no-op on PHP 8.5+)
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($errno !== 0 || $body === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                Logger::error('OpenAI timeout', ['path' => $path, 'ms' => $ms]);
                throw new OpenAIException(504, 'The AI service took too long to respond. Please try again.', 'timeout');
            }
            Logger::error('OpenAI network error', ['path' => $path, 'errno' => $errno, 'error' => $error]);
            throw new OpenAIException(502, 'Could not reach the AI service. Please check your connection and try again.', 'network');
        }

        $data = json_decode((string) $body, true);

        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                Logger::error('OpenAI invalid JSON', ['path' => $path, 'status' => $status, 'snippet' => mb_substr((string) $body, 0, 200)]);
                throw new OpenAIException(502, 'The AI service returned an unreadable response. Please try again.', 'invalid_json', $status);
            }
            return $data;
        }

        $apiMessage = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        $apiCode = is_array($data) ? (string) ($data['error']['code'] ?? $data['error']['type'] ?? '') : '';
        Logger::error('OpenAI HTTP error', ['path' => $path, 'status' => $status, 'code' => $apiCode, 'message' => mb_substr($apiMessage, 0, 300), 'ms' => $ms]);

        throw match (true) {
            $status === 401 => new OpenAIException(503, 'The AI service rejected the server API key. Please check OPENAI_API_KEY.', 'auth', $status),
            $status === 403 => new OpenAIException(503, 'The server API key is not permitted to use this AI feature or model.', 'forbidden', $status),
            $status === 429 && in_array($apiCode, ['insufficient_quota', 'billing_hard_limit_reached'], true)
                => new OpenAIException(503, 'The AI usage quota has been reached. Please check the OpenAI account billing.', 'quota', $status),
            $status === 429 => new OpenAIException(429, 'The AI service is busy right now. Please try again in a few seconds.', 'rate_limit', $status),
            $status === 413 => new OpenAIException(413, 'The file is too large for the AI service.', 'too_large', $status),
            $status === 404 => new OpenAIException(502, 'The configured AI model or endpoint was not found. Please check the model settings.', 'not_found', $status),
            $status === 400 || $status === 422 => new OpenAIException(422, $this->friendlyBadRequest($apiMessage), 'bad_request', $status),
            $status >= 500 => new OpenAIException(502, 'The AI service is temporarily unavailable. Please try again.', 'server', $status),
            default => new OpenAIException(502, 'The AI service returned an unexpected error.', 'error', $status),
        };
    }

    private function friendlyBadRequest(string $apiMessage): string
    {
        $m = strtolower($apiMessage);
        if (str_contains($m, 'audio') && (str_contains($m, 'short') || str_contains($m, 'empty') || str_contains($m, 'too small'))) {
            return "We couldn't hear the question clearly. The recording was too short.";
        }
        if (str_contains($m, 'format') || str_contains($m, 'unsupported') || str_contains($m, 'invalid file')) {
            return 'The AI service could not read this file format.';
        }
        return 'The AI service could not process this request.';
    }
}
