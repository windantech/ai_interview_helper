<?php

declare(strict_types=1);

/*
 * Unit tests (no framework). Run:  php tests/run.php
 * OpenAI error-handling tests need the mock server:  php -S 127.0.0.1:9100 tests/mock-openai.php
 * (set MOCK_OPENAI=http://127.0.0.1:9100 to enable them).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Validator;
use App\Services\CVService;
use App\Services\FileUploadService;
use App\Services\InterviewService;
use App\Services\OpenAIClient;
use App\Services\OpenAIException;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32m✓\033[0m $name\n";
    } else {
        $fail++;
        echo "  \033[31m✗ $name\033[0m" . ($detail ? " — $detail" : '') . "\n";
    }
}
function throws(callable $fn, string $class = \Throwable::class, ?callable $inspect = null): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class && ($inspect === null || $inspect($e));
    }
    return false;
}
function section(string $s): void
{
    echo "\n\033[1m$s\033[0m\n";
}

$tmp = sys_get_temp_dir() . '/icp-tests-' . bin2hex(random_bytes(3));
mkdir($tmp);
Config::set('app.storage_path', $tmp . '/storage');

// ---------------------------------------------------------------- Env
section('Env parsing');
check('quoted value', Env::parseValue('"AI Interview Copilot"') === 'AI Interview Copilot');
check('single quoted', Env::parseValue("'a b'") === 'a b');
check('inline comment stripped', Env::parseValue('value # comment') === 'value');
check('empty', Env::parseValue('') === '');

// ---------------------------------------------------------------- Validator
section('Validator');
$v = Validator::make(['email' => 'BAD', 'name' => ''], ['email' => 'required|email', 'name' => 'required']);
check('invalid email + missing name fail', $v->fails() && isset($v->errors()['email'], $v->errors()['name']));
$v = Validator::make(['email' => ' Test@Example.COM ', 'n' => '5'], ['email' => 'required|email', 'n' => 'int']);
check('email normalised to lowercase', !$v->fails() && $v->validated()['email'] === 'test@example.com');
check('int cast', $v->validated()['n'] === 5);
check('in: rule', Validator::make(['m' => 'x'], ['m' => 'in:a,b'])->fails());
check('same: rule', Validator::make(['a' => '1', 'b' => '2'], ['b' => 'same:a'])->fails());
check('array input rejected', Validator::make(['a' => ['x']], ['a' => 'required'])->fails());
check('weak password rejected', Validator::passwordProblem('short') !== null && Validator::passwordProblem('password') !== null);
check('ok password accepted', Validator::passwordProblem('Secur3pass') === null);

// ---------------------------------------------------------------- Question detection heuristics
section('Question detection (pre-filter)');
check('"What attracted you to this role?" → yes', InterviewService::plausibleQuestion('What attracted you to this role?'));
check('"Okay, thank you very much." → no', !InterviewService::plausibleQuestion('Okay, thank you very much.'));
check('"Thanks" → no', !InterviewService::plausibleQuestion('Thanks!'));
check('empty → no', !InterviewService::plausibleQuestion('   '));
check('background talk left to model (ambiguous → yes)', InterviewService::plausibleQuestion('So before we proceed, I want to give you some background about our company.'));
check('"Tell me about yourself" → yes', InterviewService::plausibleQuestion('Tell me about yourself'));

// ---------------------------------------------------------------- Answer normalisation
section('Answer JSON validation');
$valid = [
    'is_question' => true, 'question' => '  Tell us about a time you managed conflict. ', 'question_type' => 'behavioural', 'answer_mode' => 'star',
    'key_message' => 'Show calm, fair conflict resolution.', 'points' => ['a', 'b'],
    'sections' => [['label' => 'Situation', 'bullets' => ['S1']], ['label' => 'Task', 'bullets' => ['T1']], ['label' => 'Action', 'bullets' => ['A1', 'A2']], ['label' => 'Result', 'bullets' => ['R1']]],
    'cv_evidence' => ['Led team'], 'evidence_note' => '', 'closing_line' => '"Close."', 'keywords' => ['Conflict', 'Leadership'],
];
$n = InterviewService::normaliseAnswer($valid);
check('question trimmed', $n['question'] === 'Tell us about a time you managed conflict.');
check('STAR object derived', isset($n['star']) && $n['star']['situation'] === 'S1' && $n['star']['action'] === ['A1', 'A2']);
check('closing quotes stripped', $n['closing_line'] === 'Close.');
check('non-question normalised', InterviewService::normaliseAnswer(['is_question' => false, 'question' => 'x'])['is_question'] === false);
check('missing fields throws', throws(fn () => InterviewService::normaliseAnswer(['foo' => 1]), \UnexpectedValueException::class));
check('empty content throws', throws(fn () => InterviewService::normaliseAnswer(['is_question' => true, 'question' => 'Q?', 'points' => [], 'sections' => []]), \UnexpectedValueException::class));
$bad = $valid;
$bad['question_type'] = 'nonsense';
$bad['answer_mode'] = 'weird';
$nb = InterviewService::normaliseAnswer($bad, 'quick');
check('invalid enums coerced', $nb['question_type'] === 'unknown' && $nb['answer_mode'] === 'quick');
check('quick mode drops sections', $nb['sections'] === []);
$long = $valid;
$long['points'] = array_fill(0, 20, 'p');
$long['keywords'] = array_fill(0, 20, 'k');
$nl = InterviewService::normaliseAnswer($long);
check('lists capped', count($nl['points']) === 6 && count($nl['keywords']) === 6);
check('stripJson removes code fences', InterviewService::stripJson("```json\n{\"a\":1}\n```") === '{"a":1}');
check('stripJson trims preamble', InterviewService::stripJson("Here you go: {\"a\":1} thanks") === '{"a":1}');

// ---------------------------------------------------------------- Schemas
section('Strict JSON schemas');
$strictOk = function (array $schema) use (&$strictOk): bool {
    if (($schema['type'] ?? '') === 'object') {
        $props = array_keys($schema['properties']);
        $req = $schema['required'];
        sort($props);
        sort($req);
        if (($schema['additionalProperties'] ?? true) !== false || $props !== $req) {
            return false;
        }
        foreach ($schema['properties'] as $p) {
            if (!$strictOk($p)) { return false; }
        }
    }
    if (($schema['type'] ?? '') === 'array') {
        return $strictOk($schema['items']);
    }
    return true;
};
check('answer schema strict-valid', $strictOk(InterviewService::answerSchema()));
check('CV profile schema strict-valid', $strictOk(CVService::profileSchema()));
check('JD schema strict-valid', $strictOk(InterviewService::jdSchema()));
check('all 13 question types present', count(InterviewService::QUESTION_TYPES) === 13);

// ---------------------------------------------------------------- Prompt building
section('Prompt building (token optimisation)');
$svc = new InterviewService(new OpenAIClient('sk-x', 'http://127.0.0.1:1'));
$cvRow = ['cv_profile_json' => json_encode(['full_name' => 'A', 'skills' => ['PM'], 'tools' => [], 'plain_text' => 'SHOULD NOT APPEAR']), 'cv_text' => str_repeat('FULLCV ', 3000)];
$job = ['id' => 1, 'title' => 'PM', 'company' => 'ABC', 'industry' => null, 'seniority' => 'senior', 'interview_type' => 'behavioural', 'main_skills' => 'Risk', 'description' => str_repeat('desc ', 2000), 'jd_summary_json' => null];
$payload = $svc->buildAnswerPayload('Tell me about yourself', 'auto', 'short', $job, $cvRow, [['question' => 'Earlier Q', 'key_message' => 'km']], true);
$ptext = json_encode($payload);
check('uses compact profile, not full CV text', !str_contains($ptext, 'FULLCV') && str_contains($ptext, '"skills'));
check('empty profile fields dropped', !str_contains(InterviewService::candidateContext($cvRow), '"tools"') && !str_contains(InterviewService::candidateContext($cvRow), 'SHOULD NOT APPEAR'));
check('JD truncated', mb_strlen(InterviewService::jobContext($job)) < 5600);
check('session history included', str_contains($ptext, 'Earlier Q'));
check('store disabled', $payload['store'] === false);
check('coaching style: approach first, signposted points, no filler, one CV example', str_contains($payload['instructions'], 'Open with the approach') && str_contains($payload['instructions'], 'whereby') && str_contains($payload['instructions'], 'exactly ONE concrete example'));
check('technical answers: Approach → Key points → From my experience → Validation + frameworks', str_contains($ptext, 'From my experience') && str_contains($ptext, 'reproduce, measure, isolate, fix, validate'));
check('no instructions section when none given', !str_contains($ptext, "OWN INSTRUCTIONS"));
$withIns = json_encode($svc->buildAnswerPayload('Tell me about a project', 'auto', 'short', $job, $cvRow, [], true, 'When asked for a sample project, use finKAP.'));
check('interview instructions included in prompt', str_contains($withIns, "CANDIDATE'S OWN INSTRUCTIONS FOR THIS INTERVIEW") && str_contains($withIns, 'use finKAP'));
check('strict structured output requested', $payload['text']['format']['strict'] === true && $payload['text']['format']['type'] === 'json_schema');
check('no-CV context forbids invention', str_contains(InterviewService::candidateContext(null), 'never invent'));
check('prompt asks for speakable first-person sentences, no coaching instructions', str_contains($payload['instructions'], 'say exactly as written') && str_contains($payload['instructions'], 'NEVER be instructions'));
check('system prompt contains anti-fabrication rule', str_contains(json_encode($payload['instructions']), 'Never invent employment history'));

// ---------------------------------------------------------------- Upload validation
section('Upload validation (finfo, extensions, signatures)');
$mk = function (string $name, string $content) use ($tmp): array {
    $p = $tmp . '/' . bin2hex(random_bytes(4));
    file_put_contents($p, $content);
    return ['name' => $name, 'tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'type' => 'application/octet-stream'];
};
$max = 10 * 1024 * 1024;
$cvText = "Alex Morgan\nProject Manager\n" . str_repeat("Delivered projects on time and on budget. ", 20);
check('TXT CV accepted', FileUploadService::validate($mk('cv.txt', $cvText), FileUploadService::CV_TYPES, $max, 'CV')['ext'] === 'txt');
check('PHP disguised as TXT rejected', throws(fn () => FileUploadService::validate($mk('cv.txt', "<?php system('id');"), FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class));
check('double extension .php.txt rejected', throws(fn () => FileUploadService::validate($mk('cv.php.txt', $cvText), FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class, fn ($e) => $e->status() === 415));
check('EXE renamed to PDF rejected', throws(fn () => FileUploadService::validate($mk('cv.pdf', "MZ\x90\x00" . str_repeat("\0", 200)), FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class));
check('unsupported extension rejected', throws(fn () => FileUploadService::validate($mk('cv.rtf', '{\\rtf1 hello}'), FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class, fn ($e) => $e->status() === 415));
check('text file named .pdf rejected', throws(fn () => FileUploadService::validate($mk('cv.pdf', $cvText), FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class));
check('oversized rejected (413)', throws(fn () => FileUploadService::validate($mk('cv.txt', str_repeat('a', 2048)), FileUploadService::CV_TYPES, 1024, 'CV'), HttpException::class, fn ($e) => $e->status() === 413));
check('upload error code handled', throws(fn () => FileUploadService::validate(['name' => 'x', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0], FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class, fn ($e) => $e->status() === 413));
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
check('PDF accepted', FileUploadService::validate($mk('My CV.pdf', $pdf), FileUploadService::CV_TYPES, $max, 'CV')['mime'] === 'application/pdf');
check('filename sanitised', FileUploadService::sanitizeFilename('../../etc/pass<wd>.pdf') === 'pass_wd_.pdf');
check('random stored name', (bool) preg_match('/^[a-f0-9]{40}\.pdf$/', FileUploadService::randomName('pdf')));

if (class_exists(ZipArchive::class)) {
    $docx = $tmp . '/t.docx';
    $z = new ZipArchive();
    $z->open($docx, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $z->addFromString('word/document.xml', '<w:document xmlns:w="x"><w:body><w:p><w:r><w:t>Alex Morgan</w:t></w:r></w:p><w:p><w:r><w:t>Project Manager &amp; Lead</w:t></w:r></w:p></w:body></w:document>');
    $z->close();
    $res = FileUploadService::validate(['name' => 'cv.docx', 'tmp_name' => $docx, 'error' => 0, 'size' => filesize($docx)], FileUploadService::CV_TYPES, $max, 'CV');
    check('DOCX accepted', $res['ext'] === 'docx');
    $text = CVService::extractLocalText($docx, 'docx');
    check('DOCX text extracted locally', is_string($text) && str_contains($text, "Alex Morgan\n") && str_contains($text, 'Project Manager & Lead'));
    $fakeZip = $tmp . '/f.docx';
    $z = new ZipArchive();
    $z->open($fakeZip, ZipArchive::CREATE);
    $z->addFromString('evil.txt', 'not a word doc');
    $z->close();
    check('ZIP without word/ rejected as DOCX', throws(fn () => FileUploadService::validate(['name' => 'cv.docx', 'tmp_name' => $fakeZip, 'error' => 0, 'size' => filesize($fakeZip)], FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class));
} else {
    echo "  (zip extension missing — DOCX local extraction tests skipped)\n";
}
check('TXT normalisation', CVService::normaliseText("a\r\n\r\n\r\n\r\nb\t\tc") === "a\n\nb c");

// WAV audio
$wav = 'RIFF' . pack('V', 36 + 16000) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', 16000) . str_repeat("\0\0", 8000);
check('WAV audio accepted', FileUploadService::validate($mk('q.wav', $wav), FileUploadService::AUDIO_TYPES, $max, 'recording')['ext'] === 'wav');
check('audio wrong type rejected', throws(fn () => FileUploadService::validate($mk('q.webm', $cvText), FileUploadService::AUDIO_TYPES, $max, 'recording'), HttpException::class));

// ---------------------------------------------------------------- Logger redaction
section('Logging hygiene');
$r = Logger::redact(['password' => 'hunter2', 'msg' => 'Bearer sk-abcdefghijklmnop failed', 'nested' => ['api_key' => 'x', 'token' => 'y'], 'cv_text' => 'secret cv']);
check('password redacted', $r['password'] === '[redacted]');
check('bearer/sk keys redacted', !str_contains($r['msg'], 'sk-abcdefghijklmnop'));
check('nested secrets redacted', $r['nested']['api_key'] === '[redacted]' && $r['nested']['token'] === '[redacted]');
check('CV text not logged', $r['cv_text'] === '[redacted]');

// ---------------------------------------------------------------- OpenAI client (mock server)
$mock = getenv('MOCK_OPENAI');
if ($mock) {
    section('OpenAI client error handling (mock server)');
    $resp = ['model' => 'm', 'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'x']]]],
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'job_requirements', 'strict' => true, 'schema' => InterviewService::jdSchema()]]];
    $kind = function (string $base, ?int $timeout = null) use ($mock, $resp): string {
        try {
            (new OpenAIClient('sk-test-key', "$mock/$base", $timeout))->createResponse($resp);
            return 'ok';
        } catch (OpenAIException $e) {
            return $e->kind() . ':' . $e->status();
        }
    };
    check('success path', $kind('v1') === 'ok');
    check('401 → auth (503 to client)', $kind('err401') === 'auth:503');
    check('403 → forbidden', $kind('err403') === 'forbidden:503');
    check('429 rate limit → rate_limit (retried, then 429)', $kind('err429') === 'rate_limit:429');
    check('429 insufficient_quota → quota', $kind('quota') === 'quota:503');
    check('500 → server (502)', $kind('err500') === 'server:502');
    check('invalid JSON → invalid_json', $kind('badjson') === 'invalid_json:502');
    check('timeout → timeout (504)', $kind('slow', 1) === 'timeout:504');
    check('network failure → network', (function () use ($resp) {
        try { (new OpenAIClient('sk-test-key', 'http://127.0.0.1:1/v1', 2))->createResponse($resp); } catch (OpenAIException $e) { return $e->kind() === 'network'; }
        return false;
    })());
    check('missing key → not_configured', (function () use ($resp) {
        try { (new OpenAIClient('', 'http://127.0.0.1:1'))->createResponse($resp); } catch (OpenAIException $e) { return $e->kind() === 'not_configured'; }
        return false;
    })());
    check('refusal detected', (function () use ($mock, $resp) {
        $r = (new OpenAIClient('sk-test-key', "$mock/refusal"))->createResponse($resp);
        try { OpenAIClient::outputText($r); } catch (OpenAIException $e) { return $e->kind() === 'refusal'; }
        return false;
    })());
    check('malformed answer JSON → graceful error after retry', (function () use ($mock) {
        $svc = new InterviewService(new OpenAIClient('sk-test-key', "$mock/malformed"));
        try {
            $payload = $svc->buildAnswerPayload('Why this role?', 'auto', 'short', null, null, [], true);
            $m = new ReflectionMethod($svc, 'callJson');
            $m->invoke($svc, 0, 'answer', $payload, fn (array $d) => InterviewService::normaliseAnswer($d));
        } catch (OpenAIException $e) {
            return $e->kind() === 'invalid_json';
        }
        return false;
    })());
    $deltas = [];
    $final = (new OpenAIClient('sk-test-key', "$mock/v1"))->streamResponse($resp, function (string $d) use (&$deltas) { $deltas[] = $d; });
    check('streaming: many deltas forwarded as they arrive', count($deltas) > 3);
    check('streaming: deltas join into the final JSON', json_decode(implode('', $deltas), true) !== null && implode('', $deltas) === OpenAIClient::outputText($final));
    check('streaming: usage available from response.completed', OpenAIClient::usage($final)['input'] === 1200);
    $skind = function (string $base) use ($mock, $resp): string {
        try { (new OpenAIClient('sk-test-key', "$mock/$base"))->streamResponse($resp, fn () => null); return 'ok'; }
        catch (OpenAIException $e) { return $e->kind(); }
    };
    check('streaming errors mapped (401/quota/500)', $skind('err401') === 'auth' && $skind('quota') === 'quota' && $skind('err500') === 'server');
    check('realtime client secret minted', str_starts_with((new OpenAIClient('sk-test-key', "$mock/v1"))->createRealtimeSession(['type' => 'transcription', 'audio' => ['input' => ['transcription' => ['model' => 'gpt-live-transcribe'], 'turn_detection' => null]]])['value'], 'ek_'));
}

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);

echo "\n" . ($fail === 0 ? "\033[32m" : "\033[31m") . "Unit tests: $pass passed, $fail failed\033[0m\n";
exit($fail === 0 ? 0 : 1);
