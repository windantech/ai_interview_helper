<?php

declare(strict_types=1);

/*
 * Mock OpenAI API for automated tests:  php -S 127.0.0.1:9100 tests/mock-openai.php
 *
 * Base path selects behaviour:  /v1 (normal), /err401, /err403, /err429, /quota, /err500, /badjson, /slow, /refusal, /malformed
 * Every request is appended to $MOCK_LOG (JSON lines) so tests can assert on what the app sent.
 * Request shapes are validated against the documented API so payload bugs fail loudly (HTTP 400).
 */

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$parts = explode('/', trim($uri, '/'));
$mode = array_shift($parts) ?? 'v1';
$route = '/' . implode('/', $parts);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input') ?: '';
$json = json_decode($raw, true);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

$log = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/mock-openai.log';
file_put_contents($log, json_encode([
    'mode' => $mode, 'route' => $route, 'method' => $method, 'auth' => $auth,
    'json' => $json, 'post' => $_POST, 'files' => array_map(fn ($f) => ['name' => $f['name'], 'size' => $f['size'], 'type' => $f['type']], $_FILES),
]) . "\n", FILE_APPEND);

function out(int $status, $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo is_string($body) ? $body : json_encode($body);
    exit;
}
function apiError(int $status, string $message, string $code = ''): void
{
    out($status, ['error' => ['message' => $message, 'type' => 'invalid_request_error', 'code' => $code ?: null]]);
}

// ---------------------------------------------------------------- error modes
switch ($mode) {
    case 'err401': apiError(401, 'Incorrect API key provided.', 'invalid_api_key');
    case 'err403': apiError(403, 'Model access denied.');
    case 'err429': apiError(429, 'Rate limit reached.', 'rate_limit_exceeded');
    case 'quota':  apiError(429, 'You exceeded your current quota.', 'insufficient_quota');
    case 'err500': apiError(500, 'The server had an error.');
    case 'badjson': http_response_code(200); header('Content-Type: application/json'); echo '{"id": "resp_1", "output": [ broken'; exit;
    case 'slow': sleep(4); out(200, ['ok' => true]);
}

if ($auth !== 'Bearer sk-test-key') {
    apiError(401, 'Incorrect API key provided.', 'invalid_api_key');
}

function responseWith(string $text, array $usage = ['input_tokens' => 1200, 'output_tokens' => 180]): void
{
    global $json;
    if (!empty($json['stream'])) {
        // Server-Sent Events, mirroring the Responses API streaming format.
        http_response_code(200);
        header('Content-Type: text/event-stream');
        $ev = function (array $e): void { echo 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n"; @flush(); };
        $ev(['type' => 'response.created', 'response' => ['id' => 'resp_s', 'status' => 'in_progress']]);
        foreach (mb_str_split($text, 24) as $chunk) {
            $ev(['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'delta' => $chunk]);
        }
        $ev(['type' => 'response.completed', 'response' => [
            'id' => 'resp_s', 'object' => 'response', 'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
            'usage' => $usage + ['total_tokens' => array_sum($usage)],
        ]]);
        exit;
    }
    out(200, [
        'id' => 'resp_' . bin2hex(random_bytes(4)), 'object' => 'response', 'status' => 'completed',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        'usage' => $usage + ['total_tokens' => array_sum($usage)],
    ]);
}

function allText(array $input): string
{
    $t = '';
    foreach ($input as $msg) {
        foreach ((array) ($msg['content'] ?? []) as $c) {
            $t .= is_array($c) ? ($c['text'] ?? '') . "\n" : (string) $c;
        }
    }
    return $t;
}

// ---------------------------------------------------------------- routes
if ($method === 'POST' && $route === '/responses') {
    if (!is_array($json) || empty($json['model']) || !isset($json['input'])) {
        apiError(400, 'Missing model or input.');
    }
    $format = $json['text']['format'] ?? null;
    if (($format['type'] ?? '') !== 'json_schema' || ($format['strict'] ?? false) !== true || !isset($format['schema']['properties'])) {
        apiError(400, 'Invalid text.format.');
    }
    // Strict mode: every property must be required and additionalProperties false.
    $check = function (array $schema) use (&$check): bool {
        if (($schema['type'] ?? '') === 'object') {
            if (($schema['additionalProperties'] ?? true) !== false) { return false; }
            $props = array_keys($schema['properties'] ?? []);
            sort($props);
            $req = $schema['required'] ?? [];
            sort($req);
            if ($props !== $req) { return false; }
            foreach ($schema['properties'] as $p) { if (!$check($p)) { return false; } }
        }
        if (($schema['type'] ?? '') === 'array' && isset($schema['items'])) { return $check($schema['items']); }
        return true;
    };
    if (!$check($format['schema'])) {
        apiError(400, 'Invalid schema for strict mode.');
    }
    if ($mode === 'refusal') {
        out(200, ['id' => 'r', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]]]);
    }
    if ($mode === 'malformed') {
        responseWith('{"is_question": true, "question": ');
    }

    $text = allText($json['input']);
    switch ($format['name']) {
        case 'candidate_profile':
            $hasFile = str_contains(json_encode($json['input']), 'input_file');
            responseWith(json_encode([
                'full_name' => 'Alex Morgan', 'headline' => 'Project Manager', 'summary' => 'Project manager with 6 years delivering infrastructure projects.',
                'years_experience' => '6 years', 'skills' => ['Stakeholder management', 'Risk management', 'Budgeting'], 'tools' => ['MS Project', 'Jira'],
                'industries' => ['Construction'], 'roles' => [['title' => 'Project Manager', 'employer' => 'Acme Build', 'dates' => '2019–2025', 'highlights' => ['Delivered £4m depot upgrade on time', 'Managed 12-person team']]],
                'education' => [['qualification' => 'BSc Civil Engineering', 'institution' => 'Leeds', 'year' => '2017']], 'certifications' => ['PRINCE2 Practitioner'],
                'projects' => [['name' => 'Depot upgrade', 'description' => '£4m upgrade']], 'achievements' => ['Cut rework costs 15%'], 'leadership' => ['Led a 12-person delivery team'],
                'plain_text' => $hasFile ? "Alex Morgan\nProject Manager\nAcme Build 2019-2025\nDelivered £4m depot upgrade on time." : '',
            ]));
        case 'job_requirements':
            responseWith(json_encode(['summary' => 'Senior PM for infrastructure.', 'required_skills' => ['Stakeholder management'], 'competencies' => ['Leadership'],
                'tools' => ['MS Project'], 'leadership_requirements' => ['Lead teams'], 'technical_requirements' => [], 'key_responsibilities' => ['Deliver projects']]));
        case 'interview_answer':
            preg_match('/## TRANSCRIPT\n"""\n(.*?)\n"""/s', $text, $m);
            $transcript = strtolower($m[1] ?? '');
            if ($transcript === '' || str_contains($transcript, 'background about our company') || str_contains($transcript, 'thank you')) {
                responseWith(json_encode(['is_question' => false, 'question' => '', 'question_type' => 'unknown', 'answer_mode' => 'general', 'key_message' => '', 'points' => [], 'sections' => [], 'cv_evidence' => [], 'evidence_note' => '', 'closing_line' => '', 'keywords' => []]));
            }
            $quick = str_contains($text, 'Requested mode: QUICK');
            responseWith(json_encode([
                'is_question' => true,
                'question' => ucfirst(trim($m[1] ?? 'Tell me about yourself.')),
                'question_type' => 'behavioural',
                'answer_mode' => $quick ? 'quick' : 'star',
                'key_message' => 'Show you address performance issues early while supporting improvement.',
                'points' => ['I dealt with it early and privately.', 'I agreed clear milestones with them.', 'The project was delivered on time.'],
                'sections' => $quick ? [] : [
                    ['label' => 'Situation', 'bullets' => ['On the depot upgrade, one team member kept missing agreed deadlines.']],
                    ['label' => 'Task', 'bullets' => ['I needed to fix delivery without hurting team morale.']],
                    ['label' => 'Action', 'bullets' => ['I held a private one-to-one to understand what was blocking them.', 'We agreed clear milestones and weekly check-ins, and one defect I fixed was [the defect].']],
                    ['label' => 'Result', 'bullets' => ['Their delivery improved and the project finished on time.']],
                ],
                'cv_evidence' => ['Managed 12-person team at Acme Build'],
                'evidence_note' => '',
                'closing_line' => 'Addressing issues early keeps small problems small.',
                'keywords' => ['Accountability', 'Feedback', 'Communication'],
            ]));
        case 'practice_question':
            $n = substr_count($text, "\n- ");
            responseWith(json_encode(['question' => 'Practice question #' . ($n + 1) . ': Describe a project that went off track.', 'question_type' => 'behavioural', 'why_asked' => 'Tests recovery skills.', 'tip' => 'Use STAR.']));
        case 'practice_feedback':
            responseWith(json_encode(['score' => 7, 'summary' => 'Good structure.', 'strengths' => ['Clear situation'], 'missing_points' => ['No result'], 'better_structure' => ['Add the result'], 'improved_answer' => 'At Acme Build I ... [result].']));
    }
    apiError(400, 'Unknown schema ' . ($format['name'] ?? ''));
}

if ($method === 'POST' && $route === '/audio/transcriptions') {
    if (empty($_FILES['file']) || empty($_POST['model'])) {
        apiError(400, 'file and model are required');
    }
    if ($_FILES['file']['size'] < 100) {
        apiError(400, 'Audio file is too short.');
    }
    out(200, ['text' => 'Tell me about a time you managed a difficult team member?', 'usage' => ['type' => 'tokens', 'input_tokens' => 64, 'output_tokens' => 14]]);
}

if ($method === 'POST' && $route === '/files') {
    if (empty($_FILES['file']) || ($_POST['purpose'] ?? '') !== 'user_data') {
        apiError(400, 'file and purpose=user_data required');
    }
    out(200, ['id' => 'file-mock' . bin2hex(random_bytes(3)), 'object' => 'file', 'purpose' => 'user_data']);
}

if ($method === 'DELETE' && str_starts_with($route, '/files/')) {
    out(200, ['id' => substr($route, 7), 'object' => 'file', 'deleted' => true]);
}

if ($method === 'POST' && $route === '/realtime/client_secrets') {
    $s = $json['session'] ?? [];
    if (($s['type'] ?? '') !== 'transcription' || empty($s['audio']['input']['transcription']['model'])) {
        apiError(400, 'Invalid transcription session');
    }
    if (array_key_exists('turn_detection', $s['audio']['input']) && $s['audio']['input']['turn_detection'] !== null) {
        apiError(400, 'turn_detection must be null for gpt-live-transcribe');
    }
    out(200, ['value' => 'ek_mock_' . bin2hex(random_bytes(8)), 'expires_at' => time() + 600, 'session' => ['type' => 'transcription', 'id' => 'sess_mock']]);
}

apiError(404, "Unknown route $method $route");
