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
                responseWith(json_encode(['is_question' => false, 'question' => '', 'question_type' => 'unknown', 'answer_mode' => 'general', 'key_message' => '', 'points' => [], 'sections' => [], 'cv_evidence' => [], 'evidence_strength' => 'general', 'evidence_note' => '', 'closing_line' => '', 'keywords' => []]));
            }
            if (str_contains(strtolower($m[1] ?? ''), 'kubernetes')) {
                // Nothing on this CV touches Kubernetes: answer anyway, bridged from the closest project.
                responseWith(json_encode([
                    'is_question' => true,
                    'question' => ucfirst(trim($m[1] ?? '')),
                    'question_type' => 'technical',
                    'answer_mode' => 'technical',
                    'key_message' => 'Show the approach transfers, and be straight about the gap.',
                    'points' => ['I have not run Kubernetes in production, but the rollout discipline is the same as on the depot upgrade.'],
                    'sections' => [
                        ['label' => 'Approach', 'bullets' => ['I have not run Kubernetes in production, but on the depot upgrade I ran staged rollouts with a rollback plan, and the same discipline applies.']],
                        ['label' => 'Key points', 'bullets' => ['First, I would start with readiness and liveness probes so traffic only reaches healthy pods.']],
                    ],
                    'cv_evidence' => ['Delivered £4m depot upgrade on time'],
                    'evidence_strength' => 'adjacent',
                    'evidence_note' => 'Built from your depot upgrade work — swap in a closer example if you have one.',
                    'closing_line' => 'The tooling is new to me; the release discipline is not.',
                    'keywords' => ['Staged rollout', 'Rollback'],
                ]));
            }
            $quick = str_contains($text, 'Requested mode: QUICK');
            if (str_contains($text, 'Requested mode: WRITTEN')) {
                responseWith(json_encode([
                    'is_question' => true,
                    'question' => ucfirst(trim($m[1] ?? 'Discuss project recovery.')),
                    'question_type' => 'management',
                    'answer_mode' => 'written',
                    'key_message' => 'Show a structured recovery approach grounded in real delivery experience.',
                    'points' => ['A late project is recovered by re-baselining scope, resequencing the critical path and renegotiating expectations.', 'I did this on the depot upgrade at Acme Build.'],
                    'sections' => [
                        ['label' => 'Opening', 'bullets' => ['A project that has fallen behind schedule needs an honest reassessment before any recovery plan is credible, because every option depends on knowing how much of the slippage is scope, estimation or dependency failure. In my experience the first action is to re-baseline rather than to ask the team to work harder.']],
                        ['label' => 'My experience', 'bullets' => ['On the £4m depot upgrade at Acme Build I inherited a programme running four weeks late, and I rebuilt the critical path with the delivery leads before committing to a new date. That exercise taught me that transparency with the client early is cheaper than optimism later.']],
                        ['label' => 'Conclusion', 'bullets' => ['Recovery therefore rests on three things: an accurate re-baseline, a resequenced critical path, and expectations renegotiated in writing with the sponsor.']],
                    ],
                    'cv_evidence' => ['Delivered £4m depot upgrade on time', 'Managed 12-person team at Acme Build'],
                    'evidence_strength' => 'direct',
                    'evidence_note' => '',
                    'closing_line' => 'Handled that way, a late project becomes a managed one rather than a failing one.',
                    'keywords' => ['Re-baseline', 'Critical path', 'Stakeholder management'],
                ]));
            }
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
                'evidence_strength' => 'direct',
                'evidence_note' => '',
                'closing_line' => 'Addressing issues early keeps small problems small.',
                'keywords' => ['Accountability', 'Feedback', 'Communication'],
            ]));
        case 'scanned_questions':
            $raw = json_encode($json['input'], JSON_UNESCAPED_SLASHES);
            $pages = substr_count($raw, '"input_image"') + substr_count($raw, '"input_file"');
            if ($pages < 1) {
                apiError(400, 'A scan must include at least one page image or file.');
            }
            if (str_contains($raw, 'input_image') && !preg_match('#"image_url":"data:image/(jpeg|png|webp);base64,#', $raw)) {
                apiError(400, 'input_image must carry a base64 data URL of a supported image type.');
            }
            if (str_contains($text, 'blank page')) {
                responseWith(json_encode(['document_type' => 'unreadable', 'document_title' => '', 'instructions_text' => '',
                    'written_answer_expected' => false, 'notes' => 'The page looks blank or is too dark to read.', 'questions' => []]));
            }
            responseWith(json_encode([
                'document_type'  => 'essay_exam',
                'document_title' => 'Management Principles — Paper 2',
                'instructions_text' => 'Answer any three questions.',
                'written_answer_expected' => true,
                'notes' => $pages > 1 ? 'Read ' . $pages . ' pages.' : '',
                'questions' => [
                    ['number' => '1', 'text' => 'Discuss how you would manage a project that has fallen behind schedule.', 'marks' => '[20 marks]', 'question_type' => 'management'],
                    ['number' => '2(a)', 'text' => 'Explain the role of stakeholder communication in project delivery.', 'marks' => '[10 marks]', 'question_type' => 'communication'],
                    ['number' => '2(b)', 'text' => 'Describe a time you had to manage a difficult team member.', 'marks' => '[10 marks]', 'question_type' => 'behavioural'],
                ],
            ]));
        case 'practice_question':
            $n = substr_count($text, "\n- ");
            responseWith(json_encode(['question' => 'Practice question #' . ($n + 1) . ': Describe a project that went off track.', 'question_type' => 'behavioural', 'why_asked' => 'Tests recovery skills.', 'tip' => 'Use STAR.']));
        case 'practice_feedback':
            responseWith(json_encode(['score' => 7, 'summary' => 'Good structure.', 'strengths' => ['Clear situation'], 'missing_points' => ['No result'], 'better_structure' => ['Add the result'], 'delivery_tips' => ['Instead of "so I would", say "I would".'], 'filler_words' => ['So (×3)'], 'possible_mishears' => ['"Harvard 360" → probably "Eval360"'], 'improved_answer' => 'At Acme Build I ... [result].']));
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
