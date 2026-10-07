<?php

declare(strict_types=1);

/*
 * End-to-end HTTP tests against a running app + MySQL + mock OpenAI.
 * See tests/run-all.sh for the environment this expects.
 */

$BASE = rtrim(getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8000', '/');
$MOCK_LOG = getenv('MOCK_LOG') ?: '/tmp/mock.log';
$STORAGE = rtrim(getenv('STORAGE_PATH') ?: dirname(__DIR__) . '/storage', '/');

$pass = 0;
$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✓\033[0m $name\n"; }
    else { $fail++; echo "  \033[31m✗ $name\033[0m" . ($detail !== '' ? " — " . mb_substr($detail, 0, 400) : '') . "\n"; }
}
function section(string $s): void { echo "\n\033[1m$s\033[0m\n"; }

final class Client
{
    public string $jar;
    public string $csrf = '';
    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }
    /** @return array{status:int,body:string,json:?array,location:string} */
    public function req(string $method, string $path, array $opt = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        $headers = $opt['headers'] ?? [];
        if (($opt['csrf'] ?? true) && $method !== 'GET') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $o = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false];
        if (isset($opt['json'])) {
            $headers[] = 'Content-Type: application/json';
            $o[CURLOPT_POSTFIELDS] = json_encode($opt['json']);
        } elseif (isset($opt['form'])) {
            $o[CURLOPT_POSTFIELDS] = $opt['form'];
        }
        $o[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $o);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $hsize);
        $body = substr($raw, $hsize);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $body, $c)) {
            $this->csrf = $c[1];
        }
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'location' => $m[1] ?? '', 'head' => $head];
    }
    public function get(string $p): array { return $this->req('GET', $p); }
    public function postForm(string $p, array $f, bool $csrf = true): array
    {
        if ($csrf) { $f['_csrf'] = $this->csrf; }
        return $this->req('POST', $p, ['form' => http_build_query($f), 'csrf' => false, 'headers' => ['Content-Type: application/x-www-form-urlencoded']]);
    }
    public function api(string $p, array $json = []): array { return $this->req('POST', $p, ['json' => $json]); }
    public function upload(string $p, string $field, string $path, string $name, array $extra = []): array
    {
        return $this->req('POST', $p, ['form' => [$field => new CURLFile($path, 'application/octet-stream', $name)] + $extra]);
    }
    /** Multi-file upload, e.g. pages[0], pages[1] → $_FILES['pages'] with array keys. */
    public function uploadMany(string $p, string $field, array $files, array $extra = []): array
    {
        $form = $extra;
        foreach (array_values($files) as $i => [$path, $name, $mime]) {
            $form[$field . '[' . $i . ']'] = new CURLFile($path, $mime, $name);
        }
        return $this->req('POST', $p, ['form' => $form]);
    }
    public function sessionCookie(): string
    {
        $c = (string) @file_get_contents($this->jar);
        return preg_match('/icp_session\s+(\S+)/', $c, $m) ? $m[1] : '';
    }
}

function mockCalls(): array
{
    global $MOCK_LOG;
    return array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents($MOCK_LOG))));
}
function lastMock(string $route): ?array
{
    $calls = array_values(array_filter(mockCalls(), fn ($c) => $c['route'] === $route));
    return $calls ? end($calls) : null;
}
function noPhpErrors(string $html): bool
{
    return !preg_match('/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>|Uncaught |Stack trace/', $html);
}
function pdo(): PDO
{
    return new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$fx = sys_get_temp_dir() . '/icp-fixtures';
@mkdir($fx);
$cvTxt = "$fx/cv.txt";
file_put_contents($cvTxt, "Alex Morgan\nProject Manager\nAcme Build 2019-2025\n" . str_repeat("Delivered a £4m depot upgrade on time and managed a 12-person team. ", 8));
$cvPdf = "$fx/cv.pdf";
file_put_contents($cvPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" . str_repeat('%', 500));
$cvDocx = "$fx/cv.docx";
$z = new ZipArchive();
$z->open($cvDocx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$z->addFromString('word/document.xml', '<w:document xmlns:w="x"><w:body><w:p><w:r><w:t>Alex Morgan</w:t></w:r></w:p><w:p><w:r><w:t>' . str_repeat('Project Manager delivering infrastructure programmes for public sector clients. ', 6) . '</w:t></w:r></w:p></w:body></w:document>');
$z->close();
$evil = "$fx/evil.pdf";
file_put_contents($evil, "MZ\x90\x00" . str_repeat("\0", 300));
$big = "$fx/big.txt";
file_put_contents($big, str_repeat("Lorem ipsum dolor sit amet.\n", 400000)); // ~11 MB
$pagePng = "$fx/page-1.png";
file_put_contents($pagePng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAAMBAQAY3Y2wAAAAAElFTkSuQmCC'));
$pageJpg = "$fx/page-2.jpg";
file_put_contents($pageJpg, "\xFF\xD8\xFF\xE0" . pack('n', 16) . 'JFIF' . "\0" . str_repeat("\x20", 600) . "\xFF\xD9");
$paperPdf = "$fx/paper.pdf";
file_put_contents($paperPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" . str_repeat('%', 400));
$wav = "$fx/q.wav";
file_put_contents($wav, 'RIFF' . pack('V', 36 + 32000) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', 32000) . random_bytes(32000));

$email = 'alex+' . bin2hex(random_bytes(3)) . '@example.com';
$pw = 'Secur3pass!';
$c = new Client($BASE);

// =================================================================== AUTH
section('Authentication');
$r = $c->get('/');
check('landing page 200', $r['status'] === 200 && str_contains($r['body'], 'Glanceable'));
$r = $c->get('dashboard.php');
check('protected page redirects guests to login', $r['status'] === 303 && str_contains($r['location'], 'login.php'));
$c->get('register.php');
$r = $c->postForm('register.php', ['name' => 'Alex', 'email' => $email, 'password' => $pw, 'password_confirmation' => $pw, 'agree' => 1], false);
check('register without CSRF token → 419', $r['status'] === 419);
$c->get('register.php');
$r = $c->postForm('register.php', ['name' => 'Alex', 'email' => $email, 'password' => 'weak', 'password_confirmation' => 'weak', 'agree' => 1]);
check('weak password rejected', $r['status'] === 200 && str_contains($r['body'], 'at least 8 characters'));
$r = $c->postForm('register.php', ['name' => 'Alex Morgan', 'email' => $email, 'password' => $pw, 'password_confirmation' => $pw, 'agree' => 1]);
check('register → redirect to dashboard', $r['status'] === 303 && str_contains($r['location'], 'dashboard.php'), $r['body']);
$hash = pdo()->query("SELECT password_hash FROM users WHERE email = " . pdo()->quote($email))->fetchColumn();
check('password stored hashed (not plain text)', is_string($hash) && $hash !== $pw && password_verify($pw, $hash));
$r = $c->get('dashboard.php');
check('dashboard renders', $r['status'] === 200 && str_contains($r['body'], 'Welcome, Alex') && noPhpErrors($r['body']), substr($r['body'], 0, 300));
$r = $c->postForm('logout.php', []);
check('logout (POST + CSRF)', $r['status'] === 303 && str_contains($r['location'], 'login.php'));
check('logged out cannot reach dashboard', $c->get('dashboard.php')['status'] === 303);

$c->get('register.php');
$r = $c->postForm('register.php', ['name' => 'Dup', 'email' => strtoupper($email), 'password' => $pw, 'password_confirmation' => $pw, 'agree' => 1]);
check('duplicate email rejected (case-insensitive)', $r['status'] === 200 && str_contains($r['body'], 'already exists'));

$c->get('login.php');
$r = $c->postForm('login.php', ['email' => $email, 'password' => 'WrongPass1']);
check('incorrect password message', $r['status'] === 200 && str_contains($r['body'], 'Incorrect email or password'));
$before = $c->sessionCookie();
$r = $c->postForm('login.php', ['email' => $email, 'password' => $pw]);
check('login → dashboard', $r['status'] === 303 && str_contains($r['location'], 'dashboard.php'));
check('session ID regenerated on login', $before !== '' && $c->sessionCookie() !== $before);
$c->get('dashboard.php');

$guest = new Client($BASE);
$r = $guest->get('api/history.php');
check('API returns JSON 401 for guests', $r['status'] === 401 && ($r['json']['success'] ?? null) === false);
$r = $c->req('POST', 'api/save-job.php', ['json' => ['title' => 'x'], 'csrf' => false]);
check('API POST without CSRF → 419', $r['status'] === 419 && ($r['json']['success'] ?? null) === false);
$r = $c->req('GET', 'api/save-job.php');
check('wrong HTTP method → 405', $r['status'] === 405);

// =================================================================== PRIVATE FILES
section('Private paths are not web-accessible');
foreach (['.env.example', 'app/bootstrap.php', 'config/openai.php', 'storage/', 'storage/logs/', 'database/schema.sql', 'views/landing.php', 'composer.json', 'tests/run.php'] as $p) {
    $r = $guest->get($p);
    check("GET /$p → 403", $r['status'] === 403, (string) $r['status']);
}

// =================================================================== CV
section('CV upload & extraction');
$uid = (int) pdo()->query("SELECT id FROM users WHERE email = " . pdo()->quote($email))->fetchColumn();
$r = $c->upload('api/upload-cv.php', 'cv', $cvTxt, 'Alex CV.txt');
check('TXT CV upload → 201', $r['status'] === 201 && ($r['json']['success'] ?? false), $r['body']);
check('profile extracted (name, skills)', ($r['json']['data']['profile']['full_name'] ?? '') === 'Alex Morgan' && in_array('Budgeting', $r['json']['data']['profile']['skills'] ?? [], true));
$m = lastMock('/responses');
check('TXT sent as text (no file upload)', $m && !str_contains(json_encode($m['json']), 'input_file') && str_contains(json_encode($m['json']), 'Acme Build'));
$row = pdo()->query("SELECT * FROM user_cvs WHERE user_id = $uid")->fetch(PDO::FETCH_ASSOC);
check('cv_text and cv_profile_json stored separately', $row && str_contains($row['cv_text'], 'Alex Morgan') && json_decode($row['cv_profile_json'], true)['headline'] === 'Project Manager');
check('stored under storage/users/{id}/cv with random name', is_file("$STORAGE/users/$uid/cv/" . $row['stored_filename']) && preg_match('/^[a-f0-9]{40}\.txt$/', $row['stored_filename']));
$r = $guest->get("storage/users/$uid/cv/" . $row['stored_filename']);
check('direct URL to CV file blocked', $r['status'] === 403);
$r = $c->get('cv-file.php');
check('CV downloadable via authenticated route', $r['status'] === 200 && str_contains($r['body'], 'Alex Morgan'));
check('CV route blocked for guests', $guest->get('cv-file.php')['status'] === 303);
$r = $c->get('cv.php');
check('CV page shows success + extracted info', str_contains($r['body'], 'CV uploaded successfully') && str_contains($r['body'], 'Employment history') && noPhpErrors($r['body']));

$r = $c->upload('api/upload-cv.php', 'cv', $cvPdf, 'cv.pdf');
check('PDF upload (replace) → 201', $r['status'] === 201, $r['body']);
check('PDF sent via Files API (purpose=user_data) + input_file', lastMock('/files') !== null && str_contains(json_encode(lastMock('/responses')['json']), 'input_file'));
$row2 = pdo()->query("SELECT * FROM user_cvs WHERE user_id = $uid")->fetch(PDO::FETCH_ASSOC);
check('replaced CV: old file removed, one file remains', count(glob("$STORAGE/users/$uid/cv/*")) === 1 && !is_file("$STORAGE/users/$uid/cv/" . $row['stored_filename']));
check('openai_file_id stored + model plain_text saved as cv_text', str_starts_with((string) $row2['openai_file_id'], 'file-mock') && str_contains($row2['cv_text'], 'depot upgrade'));

$r = $c->upload('api/upload-cv.php', 'cv', $cvDocx, 'cv.docx');
check('DOCX upload → 201 (local extraction)', $r['status'] === 201 && !str_contains(json_encode(lastMock('/responses')['json']), 'input_file'), $r['body']);
check('old OpenAI file deleted on replace', (function () use ($row2) { foreach (mockCalls() as $m) { if ($m['method'] === 'DELETE' && $m['route'] === '/files/' . $row2['openai_file_id']) { return true; } } return false; })());
$r = $c->upload('api/upload-cv.php', 'cv', $evil, 'cv.pdf');
check('executable disguised as PDF → 415', $r['status'] === 415 && ($r['json']['success'] ?? true) === false);
$r = $c->upload('api/upload-cv.php', 'cv', $fx . '/cv.txt', 'cv.exe');
check('unsupported format → 415', $r['status'] === 415);
$r = $c->upload('api/upload-cv.php', 'cv', $big, 'big.txt');
check('oversized CV → 413', $r['status'] === 413, $r['status'] . ' ' . $r['body']);
$r = $c->get('api/cv-profile.php');
check('CV still intact after rejected uploads', ($r['json']['data']['cv']['status'] ?? '') === 'ready' && ($r['json']['data']['cv']['original_filename'] ?? '') === 'cv.docx');

// =================================================================== JOBS
section('Job setup');
$r = $c->api('api/save-job.php', ['title' => 'PM', 'description' => 'short', 'interview_type' => 'general', 'seniority' => 'mid']);
check('validation error (short description) → 422 with field errors', $r['status'] === 422 && isset($r['json']['errors']['description']));
$r = $c->api('api/save-job.php', ['title' => 'Senior Project Manager', 'company' => 'ABC Company', 'description' => str_repeat('Lead infrastructure projects, manage stakeholders, budgets and risk. ', 10), 'interview_type' => 'behavioural', 'seniority' => 'senior', 'main_skills' => 'Stakeholders, Risk']);
$jobId = (int) ($r['json']['data']['job']['id'] ?? 0);
check('create job → 201', $r['status'] === 201 && $jobId > 0, $r['body']);
$r = $c->api('api/save-job.php', ['id' => $jobId, 'title' => 'Senior Project Manager II', 'company' => 'ABC Company', 'description' => str_repeat('Lead infrastructure projects, manage stakeholders, budgets and risk. ', 10), 'interview_type' => 'behavioural', 'seniority' => 'senior']);
check('edit job → 200', $r['status'] === 200 && $r['json']['data']['job']['title'] === 'Senior Project Manager II');
$r = $c->api('api/save-job.php', ['sample' => 1]);
$sampleId = (int) ($r['json']['data']['job']['id'] ?? 0);
check('add sample job (Project Manager)', $r['status'] === 201 && $r['json']['data']['job']['title'] === 'Project Manager');
$r = $c->api('api/select-job.php', ['id' => $jobId]);
check('select job', $r['status'] === 200 && $r['json']['data']['active_job_id'] === $jobId);
$r = $c->api('api/delete-job.php', ['id' => $sampleId]);
check('delete job', $r['status'] === 200);
$r = $c->get('jobs.php');
check('jobs page lists job and marks current', str_contains($r['body'], 'Senior Project Manager II') && str_contains($r['body'], 'Current') && noPhpErrors($r['body']));

// =================================================================== INTERVIEW
section('Interview session');
$r = $c->get('interview.php');
check('interview page renders', $r['status'] === 200 && str_contains($r['body'], 'mic-btn') && noPhpErrors($r['body']));
check('API key never present in HTML', !str_contains($r['body'], 'sk-test-key'));
$r = $c->api('api/start-session.php', ['job_id' => $jobId, 'type' => 'live', 'instructions' => "When asked for a sample project, use the depot upgrade.\nKeep salary answers open."]);
$sid = (int) ($r['json']['data']['session']['id'] ?? 0);
check('session starts with interview instructions', str_contains($r['json']['data']['session']['instructions'] ?? '', 'depot upgrade'));
check('start session → 201', $r['status'] === 201 && $sid > 0, $r['body']);
check('job description analysed once (JD summary cached)', pdo()->query("SELECT jd_summary_json IS NOT NULL FROM jobs WHERE id = $jobId")->fetchColumn() == 1);

$r = $c->api('api/realtime-session.php', []);
check('realtime: ephemeral key returned', $r['status'] === 200 && str_starts_with($r['json']['data']['client_secret'] ?? '', 'ek_'), $r['body']);
check('realtime: permanent key NOT returned', !str_contains($r['body'], 'sk-test-key'));
check('realtime: vocabulary hint sent with live transcription', str_contains(lastMock('/realtime/client_secrets')['json']['session']['audio']['input']['transcription']['prompt'] ?? '', 'PRINCE2'));
$rt = lastMock('/realtime/client_secrets');
check('realtime: transcription session w/ gpt-live-transcribe, turn_detection null', ($rt['json']['session']['audio']['input']['transcription']['model'] ?? '') === 'gpt-live-transcribe' && array_key_exists('turn_detection', $rt['json']['session']['audio']['input']));

$r = $c->upload('api/transcribe.php', 'audio', $wav, 'question.wav', ['session_id' => (string) $sid]);
check('fallback transcription (WAV) → transcript', $r['status'] === 200 && str_contains($r['json']['data']['transcript'] ?? '', 'difficult team member'), $r['body']);
$tm = lastMock('/audio/transcriptions');
check('transcription prompt carries CV/job vocabulary', str_contains($tm['post']['prompt'] ?? '', 'Acme Build') && str_contains($tm['post']['prompt'] ?? '', 'Senior Project Manager II'), $tm['post']['prompt'] ?? '');
check('transcription uses gpt-transcribe + file', ($tm['post']['model'] ?? '') === 'gpt-transcribe' && isset($tm['files']['file']));
check('temporary audio deleted after transcription', count(glob("$STORAGE/audio/*.wav")) === 0);
$r = $c->upload('api/transcribe.php', 'audio', $cvTxt, 'question.webm');
check('invalid audio rejected → 415', $r['status'] === 415);

$t0 = microtime(true);
$r = $c->api('api/generate-answer.php', ['session_id' => $sid, 'transcript' => 'Tell me about a time you managed a difficult team member?', 'mode' => 'auto', 'source' => 'recorded']);
$a = $r['json']['data'] ?? [];
check('Q1: answer generated', $r['status'] === 200 && ($a['is_question'] ?? false) === true && !empty($a['answer']['sections']), $r['body']);
check('Q1: STAR structure + star object', ($a['answer']['answer_mode'] ?? '') === 'star' && isset($a['answer']['star']['action']));
check('Q1: CV evidence separated from approach', !empty($a['answer']['cv_evidence']) && !empty($a['answer']['key_message']));
check('Q1: saved to history', (int) ($a['question_id'] ?? 0) > 0);
$req = lastMock('/responses')['json'];
$reqText = json_encode($req);
check('prompt includes compact CV profile + JD summary', str_contains($reqText, 'Alex Morgan') && str_contains($reqText, 'Job description analysis'));
check('model configured from .env (answer model)', $req['model'] === 'gpt-6-luna');
$q1 = (int) $a['question_id'];
check('instructions sent with the question', str_contains($reqText, "OWN INSTRUCTIONS FOR THIS INTERVIEW") && str_contains($reqText, 'use the depot upgrade'));
$r = $c->api('api/session-instructions.php', ['session_id' => $sid, 'instructions' => 'Use the finKAP platform as my sample project.']);
check('instructions updated mid-interview', $r['status'] === 200);
$c->api('api/generate-answer.php', ['session_id' => $sid, 'transcript' => 'Tell me about a project you are proud of?', 'source' => 'typed']);
check('next question uses the updated instructions', str_contains(json_encode(lastMock('/responses')['json']), 'finKAP') && !str_contains(json_encode(lastMock('/responses')['json']), 'use the depot upgrade'));
pdo()->exec("DELETE FROM interview_questions WHERE session_id = $sid AND question LIKE '%proud of%'");
$r = $c->api('api/session-instructions.php', ['session_id' => $sid, 'instructions' => str_repeat('x', 2001)]);
check('over-long instructions rejected (422)', $r['status'] === 422);
$r = $c->get('interview.php');
check('interview page shows saved instructions', str_contains($r['body'], 'Use the finKAP platform as my sample project.'));

$r = $c->api('api/generate-answer.php', ['session_id' => $sid, 'transcript' => 'How do you deal with difficult stakeholders?', 'mode' => 'auto', 'source' => 'live']);
check('Q2: second question answered', ($r['json']['data']['is_question'] ?? false) === true);
check('Q2: previous question sent as context', str_contains(json_encode(lastMock('/responses')['json']), 'EARLIER QUESTIONS') && str_contains(json_encode(lastMock('/responses')['json']), 'difficult team member'));

$t0 = microtime(true);
$r = $c->req('POST', 'api/generate-answer.php', ['json' => ['session_id' => $sid, 'transcript' => 'Why do you want to work here?', 'mode' => 'auto', 'source' => 'live', 'stream' => true]]);
preg_match_all('/^event: (\w+)\ndata: (.*)$/m', $r['body'], $ev, PREG_SET_ORDER);
$names = array_column($ev, 1);
$doneEv = null;
foreach ($ev as $e) { if ($e[1] === 'done') { $doneEv = json_decode($e[2], true); } }
$deltaText = implode('', array_map(fn ($e) => json_decode($e[2], true)['t'] ?? '', array_filter($ev, fn ($e) => $e[1] === 'delta')));
check('streaming: SSE content type', str_contains($r['head'], 'text/event-stream'));
check('streaming: start → many deltas → done', ($names[0] ?? '') === 'start' && count(array_keys($names, 'delta')) > 3 && end($names) === 'done', implode(',', array_unique($names)));
check('streaming: deltas rebuild the model JSON', is_array(json_decode($deltaText, true)));
check('streaming: done carries validated + saved answer', ($doneEv['is_question'] ?? false) === true && (int) ($doneEv['question_id'] ?? 0) > 0 && !empty($doneEv['answer']['sections']));
check('streaming: OpenAI called with stream=true', (lastMock('/responses')['json']['stream'] ?? false) === true);
pdo()->exec('DELETE FROM interview_questions WHERE id = ' . (int) ($doneEv['question_id'] ?? 0));

$n = count(mockCalls());
$r = $c->api('api/generate-answer.php', ['session_id' => $sid, 'transcript' => 'Okay, thank you very much.', 'source' => 'live']);
check('small talk ignored without calling OpenAI', ($r['json']['data']['is_question'] ?? null) === false && count(mockCalls()) === $n);
$r = $c->api('api/generate-answer.php', ['session_id' => $sid, 'transcript' => 'So before we proceed, I want to give you some background about our company.', 'source' => 'live']);
check('company background classified as not a question by model', ($r['json']['data']['is_question'] ?? null) === false);
$r = $c->api('api/generate-answer.php', ['session_id' => $sid, 'transcript' => '', 'source' => 'live']);
check('empty transcript → 422 friendly message', $r['status'] === 422 && str_contains($r['json']['message'] ?? '', "couldn't hear"));

$r = $c->api('api/generate-answer.php', ['session_id' => $sid, 'question_id' => $q1, 'mode' => 'quick', 'source' => 'typed']);
check('switch answer mode (Quick) regenerates same question', ($r['json']['data']['answer']['answer_mode'] ?? '') === 'quick' && (int) $r['json']['data']['question_id'] === $q1);
check('regenerate does not duplicate history rows', (int) pdo()->query("SELECT COUNT(*) FROM interview_questions WHERE session_id = $sid")->fetchColumn() === 2);

$r = $c->api('api/end-session.php', ['session_id' => $sid]);
check('end session', $r['status'] === 200 && $r['json']['data']['questions'] === 2);
$r = $c->api('api/generate-answer.php', ['session_id' => $sid, 'transcript' => 'Why this role?', 'source' => 'typed']);
check('cannot add questions to ended session → 409', $r['status'] === 409);
$r = $c->req('POST', 'api/generate-answer.php', ['json' => ['session_id' => $sid, 'transcript' => 'Why this role?', 'source' => 'typed', 'stream' => true]]);
check('streaming request on ended session → plain JSON 409', $r['status'] === 409 && ($r['json']['success'] ?? true) === false);

// =================================================================== HISTORY
section('History');
$r = $c->get('api/history.php');
check('history list (JSON)', ($r['json']['data']['total'] ?? 0) >= 1 && $r['json']['data']['items'][0]['questions'] === 2, $r['body']);
$r = $c->get("api/history.php?id=$sid");
check('history detail with Q&A', count($r['json']['data']['questions'] ?? []) === 2 && isset($r['json']['data']['questions'][0]['answer']['key_message']));
$r = $c->get('history.php?q=' . urlencode('stakeholders'));
check('search finds session by question text', str_contains($r['body'], 'Senior Project Manager II') && noPhpErrors($r['body']));
$r = $c->get('history.php?q=' . urlencode('zzzz-nothing'));
check('search with no results shows empty state', str_contains($r['body'], 'No sessions match'));
$r = $c->get("history.php?job_id=$jobId&from=" . gmdate('Y-m-d') . '&to=' . gmdate('Y-m-d'));
check('filter by job + date', str_contains($r['body'], 'Senior Project Manager II'));
$r = $c->get("history.php?id=$sid");
check('history shows the instructions used', str_contains($r['body'], 'Use the finKAP platform as my sample project.'));
check('session page shows questions + answers', str_contains($r['body'], 'Question 1') && str_contains($r['body'], 'Close with') && noPhpErrors($r['body']));

// =================================================================== PRACTICE
section('Practice mode');
$r = $c->api('api/start-session.php', ['job_id' => $jobId, 'type' => 'practice']);
$psid = (int) ($r['json']['data']['session']['id'] ?? 0);
$r1 = $c->api('api/practice-question.php', ['session_id' => $psid]);
$r2 = $c->api('api/practice-question.php', ['session_id' => $psid, 'focus' => 'leadership']);
check('practice questions generated one at a time', ($r1['json']['data']['number'] ?? 0) === 1 && ($r2['json']['data']['number'] ?? 0) === 2, $r1['body']);
check('previous practice questions excluded from next prompt', str_contains(json_encode(lastMock('/responses')['json']), 'Already asked'));
$pq = (int) $r1['json']['data']['question_id'];
$r = $c->api('api/practice-feedback.php', ['question_id' => $pq, 'answer_text' => 'At Acme Build I led the depot upgrade when it slipped two weeks behind schedule.']);
check('practice feedback includes delivery, filler words and likely mis-hears', !empty($r['json']['data']['feedback']['filler_words']) && !empty($r['json']['data']['feedback']['possible_mishears']) && !empty($r['json']['data']['feedback']['delivery_tips']));
check('practice feedback (strengths, missing points, improved answer)', ($r['json']['data']['feedback']['score'] ?? 0) === 7 && !empty($r['json']['data']['feedback']['improved_answer']));
$r = $c->api('api/generate-answer.php', ['session_id' => $psid, 'question_id' => $pq, 'mode' => 'auto', 'source' => 'typed']);
check('practice "show approach" uses the same pipeline', ($r['json']['data']['is_question'] ?? false) === true);
check('practice pages render', noPhpErrors($c->get('practice.php')['body']));


// =================================================================== SCAN
section('Scan a question paper');
$r = $c->get('scan.php');
check('scan page renders', $r['status'] === 200 && str_contains($r['body'], 'sc-capture') && noPhpErrors($r['body']));
check('scan page does not leak the API key', !str_contains($r['body'], 'sk-test-key'));
check('Permissions-Policy allows the camera for this origin', (bool) preg_match('/Permissions-Policy:[^\r\n]*camera=\(self\)/i', $r['head']), $r['head']);
check('CSP allows blob: page previews', (bool) preg_match('/img-src[^;]*blob:/i', $r['head']) && (bool) preg_match('/media-src[^;]*blob:/i', $r['head']));

$r = $c->uploadMany('api/scan-extract.php', 'pages', [[$pagePng, 'page-1.png', 'image/png'], [$pageJpg, 'page-2.jpg', 'image/jpeg']], ['job_id' => (string) $jobId, 'hint' => 'Section B only.', 'instructions' => 'Use the depot upgrade as my example.']);
$sc = $r['json']['data'] ?? [];
check('two pages scanned → 201', $r['status'] === 201 && count($sc['questions'] ?? []) === 3, $r['body']);
$scanSid = (int) ($sc['session']['id'] ?? 0);
check('scan opens its own session', $scanSid > 0 && pdo()->query("SELECT session_type FROM interview_sessions WHERE id = $scanSid")->fetchColumn() === 'scan');
check('session named after the paper', str_contains($sc['session']['title'] ?? '', 'Management Principles'), $sc['session']['title'] ?? '');
check('printed numbering kept', array_column($sc['questions'], 'number') === ['1', '2(a)', '2(b)'], json_encode(array_column($sc['questions'], 'number')));
check('marks captured', ($sc['questions'][0]['marks'] ?? '') === '[20 marks]');
check('paper-wide directions kept separate from the questions', ($sc['document']['instructions_text'] ?? '') === 'Answer any three questions.');
check('paper recognised as one to write on', ($sc['document']['written'] ?? false) === true && ($sc['document']['type'] ?? '') === 'essay_exam');
check('questions stored unanswered, ready to answer', (int) pdo()->query("SELECT COUNT(*) FROM interview_questions WHERE session_id = $scanSid AND source = 'scan' AND answer_json IS NULL")->fetchColumn() === 3);
check('scan instructions saved on the session', str_contains($sc['session']['instructions'] ?? '', 'depot upgrade'));
$scanReq = lastMock('/responses')['json'];
$scanText = json_encode($scanReq, JSON_UNESCAPED_SLASHES);
check('both pages sent in one vision call', substr_count($scanText, '"input_image"') === 2);
check('pages sent as base64 data URLs, not stored paths', str_contains($scanText, 'data:image/png;base64,') && str_contains($scanText, 'data:image/jpeg;base64,'));
check('scan uses the configured model + strict schema', $scanReq['model'] === 'gpt-6-luna' && ($scanReq['text']['format']['name'] ?? '') === 'scanned_questions');
check('scan prompt carries CV/job vocabulary and the candidate hint', str_contains($scanText, 'Acme Build') && str_contains($scanText, 'Section B only.'));
check('no page images written to storage', count(glob("$STORAGE/users/$uid/*.png")) === 0 && count(glob("$STORAGE/*.png")) === 0);

$qids = array_column($sc['questions'], 'id');
$r = $c->api('api/generate-answer.php', ['session_id' => $scanSid, 'question_id' => $qids[0], 'mode' => 'written', 'source' => 'scan']);
$ans = $r['json']['data']['answer'] ?? [];
check('scanned question answered in written mode', $r['status'] === 200 && ($ans['answer_mode'] ?? '') === 'written', $r['body']);
check('written answer is prose, not clipped talking points', mb_strlen($ans['sections'][0]['bullets'][0] ?? '') > 220);
check('written answer grounded in the CV', !empty($ans['cv_evidence']));
check('answer stored against the scanned question row', (int) pdo()->query("SELECT COUNT(*) FROM interview_questions WHERE id = {$qids[0]} AND answer_json IS NOT NULL")->fetchColumn() === 1);
check('answering does not add a duplicate row', (int) pdo()->query("SELECT COUNT(*) FROM interview_questions WHERE session_id = $scanSid")->fetchColumn() === 3);
$wreq = json_encode(lastMock('/responses')['json']);
check('written prompt overrides the spoken rules', str_contains($wreq, 'NOT SPOKEN'));
check('scan instructions sent with the answer', str_contains($wreq, 'depot upgrade'));

$r = $c->api('api/generate-answer.php', ['session_id' => $scanSid, 'question_id' => $qids[1], 'mode' => 'auto', 'source' => 'scan']);
check('second scanned question answered', ($r['json']['data']['is_question'] ?? false) === true);
$ctx = json_encode(lastMock('/responses')['json']);
check('only answered questions used as context', str_contains($ctx, 'EARLIER QUESTIONS') && str_contains($ctx, 'manage a late project')
    && !str_contains($ctx, 'Describe a time you had to manage a difficult team member'), $ctx);

$r = $c->api('api/scan-question.php', ['session_id' => $scanSid, 'question_id' => $qids[0], 'text' => 'Discuss how you would recover a project that has fallen four weeks behind.', 'number' => '1']);
check('mis-read question can be corrected', $r['status'] === 200 && (int) $r['json']['data']['id'] === $qids[0], $r['body']);
check('correcting a question clears its stale answer', pdo()->query("SELECT answer_json FROM interview_questions WHERE id = {$qids[0]}")->fetchColumn() === null);
$r = $c->api('api/scan-question.php', ['session_id' => $scanSid, 'text' => 'Evaluate the use of earned value analysis on a capital project.']);
$addedId = (int) ($r['json']['data']['id'] ?? 0);
check('a missed question can be added', $r['status'] === 201 && $addedId > 0);
check('added question answers through the same pipeline', ($c->api('api/generate-answer.php', ['session_id' => $scanSid, 'question_id' => $addedId, 'source' => 'scan'])['json']['data']['is_question'] ?? false) === true);
$r = $c->api('api/scan-question.php', ['session_id' => $scanSid, 'text' => 'short']);
check('too-short question rejected (422)', $r['status'] === 422);
$r = $c->api('api/scan-question.php', ['session_id' => $sid, 'text' => 'Is this allowed on a non-scan session?']);
check('only scan sessions accept scanned questions (404)', $r['status'] === 404);

$r = $c->api('api/generate-answer.php', ['session_id' => $scanSid, 'transcript' => 'Outline the stages of a project lifecycle.', 'source' => 'scan']);
check('scanned text is never dropped by the speech prefilter', ($r['json']['data']['is_question'] ?? false) === true, $r['body']);
pdo()->exec("DELETE FROM interview_questions WHERE session_id = $scanSid AND question LIKE '%project lifecycle%'");

// The interview screen scans a question into the interview already running.
$sessionsBefore = (int) pdo()->query("SELECT COUNT(*) FROM interview_sessions WHERE user_id = $uid")->fetchColumn();
$r = $c->uploadMany('api/scan-extract.php', 'pages', [[$pagePng, 'page-1.png', 'image/png']], ['extract_only' => '1', 'job_id' => (string) $jobId]);
$eo = $r['json']['data'] ?? [];
check('extract_only returns the questions', $r['status'] === 200 && count($eo['questions'] ?? []) === 3, $r['body']);
check('extract_only opens no session', ($eo['session'] ?? 'x') === null
    && (int) pdo()->query("SELECT COUNT(*) FROM interview_sessions WHERE user_id = $uid")->fetchColumn() === $sessionsBefore);
check('extract_only stores no questions', array_column($eo['questions'], 'id') === [null, null, null]);
check('extract_only still reports the document', ($eo['document']['type'] ?? '') === 'essay_exam');

$r = $c->api('api/start-session.php', ['job_id' => $jobId, 'type' => 'live']);
$ivSid = (int) $r['json']['data']['session']['id'];
$r = $c->api('api/generate-answer.php', ['session_id' => $ivSid, 'transcript' => $eo['questions'][2]['text'], 'source' => 'scan']);
check('a scanned question answers inside a live interview', ($r['json']['data']['is_question'] ?? false) === true, $r['body']);
check('it is saved to that interview, tagged scan', (int) pdo()->query("SELECT COUNT(*) FROM interview_questions WHERE session_id = $ivSid AND source = 'scan'")->fetchColumn() === 1);
$c->api('api/end-session.php', ['session_id' => $ivSid]);

$r = $c->uploadMany('api/scan-extract.php', 'pages', [[$cvTxt, 'page.txt', 'text/plain']]);
check('non-image page rejected (415)', $r['status'] === 415, $r['body']);
$r = $c->uploadMany('api/scan-extract.php', 'pages', [[$evil, 'page.png', 'image/png']]);
check('executable disguised as a page rejected', $r['status'] === 415);
$r = $c->api('api/scan-extract.php', []);
check('scan with no pages rejected (400)', $r['status'] === 400);
$r = $c->uploadMany('api/scan-extract.php', 'pages', array_fill(0, 9, [$pagePng, 'page.png', 'image/png']));
check('too many pages rejected (422)', $r['status'] === 422 && str_contains($r['json']['message'] ?? '', 'up to 8 pages'), $r['body']);
$r = $c->uploadMany('api/scan-extract.php', 'pages', [[$pagePng, 'page-1.png', 'image/png']], ['hint' => 'This is a blank page.']);
check('unreadable page → friendly 422, no session created', $r['status'] === 422 && ($r['json']['error_kind'] ?? '') === 'no_questions' && str_contains($r['json']['message'] ?? '', 'read that page'), $r['body']);
check('failed scan did not open a session', (int) pdo()->query("SELECT COUNT(*) FROM interview_sessions WHERE user_id = $uid AND session_type = 'scan'")->fetchColumn() === 1);

$filesDeleted = fn () => count(array_filter(mockCalls(), fn ($m) => $m['method'] === 'DELETE' && str_starts_with($m['route'], '/files/')));
$deletesBefore = $filesDeleted();
$r = $c->uploadMany('api/scan-extract.php', 'pages', [[$paperPdf, 'paper.pdf', 'application/pdf']]);
check('a PDF paper scans too', $r['status'] === 201 && count($r['json']['data']['questions'] ?? []) === 3, $r['body']);
$pdfScanSid = (int) $r['json']['data']['session']['id'];
check('PDF sent as an OpenAI file input', str_contains(json_encode(lastMock('/responses')['json']), 'input_file'));
check('temporary OpenAI file deleted after the scan', $filesDeleted() === $deletesBefore + 1, $filesDeleted() . ' vs ' . $deletesBefore);
check('starting a new scan closes the previous one', pdo()->query("SELECT status FROM interview_sessions WHERE id = $scanSid")->fetchColumn() === 'ended');

$r = $c->get("history.php?id=$pdfScanSid");
check('scanned paper appears in history, labelled', $r['status'] === 200 && str_contains($r['body'], 'Scanned paper') && noPhpErrors($r['body']));
check('history shows the printed question numbers', str_contains($r['body'], 'Question 2(a)'));
check('unanswered scanned questions marked as such', str_contains($r['body'], 'Not answered yet.'));
$r = $c->get('history.php?type=scan');
check('history can be filtered to scanned papers', $r['status'] === 200 && str_contains($r['body'], 'Scanned paper') && noPhpErrors($r['body']));
$r = $c->api('api/end-session.php', ['session_id' => $pdfScanSid]);
check('finishing a paper ends the session', $r['status'] === 200);

// =================================================================== SETTINGS
section('Settings & privacy controls');
$r = $c->get('settings.php');
check('settings page renders', $r['status'] === 200 && noPhpErrors($r['body']));
$r = $c->postForm('settings.php', ['action' => 'preferences', 'default_answer_mode' => 'star', 'response_detail' => 'medium', 'default_interview_type' => 'technical', 'transcription_mode' => 'recorded', 'auto_detect_question' => '1', 'show_transcript' => '1']);
check('save preferences (history off)', $r['status'] === 303);
$s = pdo()->query("SELECT * FROM user_settings WHERE user_id = $uid")->fetch(PDO::FETCH_ASSOC);
check('preferences persisted', $s['default_answer_mode'] === 'star' && $s['response_detail'] === 'medium' && (int) $s['save_history'] === 0);
$r = $c->api('api/start-session.php', ['job_id' => $jobId]);
$sid2 = (int) $r['json']['data']['session']['id'];
check('next interview for same job carries instructions over', ($r['json']['data']['session']['instructions'] ?? '') === 'Use the finKAP platform as my sample project.');
$r = $c->api('api/generate-answer.php', ['session_id' => $sid2, 'transcript' => 'Why do you want this job?', 'source' => 'typed']);
check('history disabled → answer not stored', ($r['json']['data']['saved'] ?? true) === false && (int) pdo()->query("SELECT COUNT(*) FROM interview_questions WHERE session_id = $sid2")->fetchColumn() === 0);
check('medium detail → larger token budget', lastMock('/responses')['json']['max_output_tokens'] === 1500);

// =================================================================== OWNERSHIP
section('Access control between users');
$other = new Client($BASE);
$other->get('register.php');
$other->postForm('register.php', ['name' => 'Eve', 'email' => 'eve+' . bin2hex(random_bytes(3)) . '@example.com', 'password' => $pw, 'password_confirmation' => $pw, 'agree' => 1]);
$other->get('dashboard.php');
check("other user can't view session", $other->get("history.php?id=$sid")['status'] === 404);
check("other user can't read session via API", $other->get("api/history.php?id=$sid")['status'] === 404);
check("other user can't delete job", $other->api('api/delete-job.php', ['id' => $jobId])['status'] === 404);
check("other user can't use session for answers", $other->api('api/generate-answer.php', ['session_id' => $sid2, 'transcript' => 'Why?'])['status'] === 404);
$od = $other->get('api/cv-profile.php')['json']['data'] ?? [];
check("other user gets no CV", array_key_exists('cv', $od) && $od['cv'] === null);
check("other user can't change instructions", $other->api('api/session-instructions.php', ['session_id' => $sid2, 'instructions' => 'hack'])['status'] === 404);
check("other user can't edit a scanned question", $other->api('api/scan-question.php', ['session_id' => $scanSid, 'text' => 'Inject a question into someone else\'s paper.'])['status'] === 404);
check("other user can't answer a scanned question", $other->api('api/generate-answer.php', ['session_id' => $scanSid, 'question_id' => $qids[1], 'source' => 'scan'])['status'] === 404);

// =================================================================== DELETE DATA
section('Deleting data');
$r = $c->api('api/delete-session.php', ['session_id' => $sid]);
check('delete one session (questions cascade)', $r['status'] === 200 && (int) pdo()->query("SELECT COUNT(*) FROM interview_questions WHERE session_id = $sid")->fetchColumn() === 0);
$r = $c->api('api/delete-session.php', ['all' => true]);
check('delete all history', $r['status'] === 200 && (int) pdo()->query("SELECT COUNT(*) FROM interview_sessions WHERE user_id = $uid")->fetchColumn() === 0);
$r = $c->api('api/delete-cv.php', []);
check('delete CV removes row + file', $r['status'] === 200 && count(glob("$STORAGE/users/$uid/cv/*")) === 0 && (int) pdo()->query("SELECT COUNT(*) FROM user_cvs WHERE user_id = $uid")->fetchColumn() === 0);
$c->upload('api/upload-cv.php', 'cv', $cvTxt, 'cv.txt');

// =================================================================== PASSWORD RESET
section('Password reset');
$p = new Client($BASE);
$p->get('forgot-password.php');
$r = $p->postForm('forgot-password.php', ['email' => $email]);
check('forgot password: generic confirmation', str_contains($r['body'], 'If an account exists'));
preg_match('/href="([^"]*reset-password\.php\?token=[a-f0-9]{64})"/', $r['body'], $m);
check('dev mode shows reset link (mail disabled)', !empty($m[1]));
$tokenUrl = html_entity_decode($m[1] ?? '');
$path = substr($tokenUrl, strpos($tokenUrl, 'reset-password.php'));
$p->get($path);
parse_str((string) parse_url($tokenUrl, PHP_URL_QUERY), $qs);
$newPw = 'N3wPassword!';
$r = $p->postForm('reset-password.php', ['token' => $qs['token'] ?? '', 'password' => $newPw, 'password_confirmation' => $newPw]);
check('reset password → redirect to login', $r['status'] === 303 && str_contains($r['location'], 'login.php'), $r['body']);
check('token hashed in DB (plain token not stored)', (int) pdo()->query("SELECT COUNT(*) FROM password_resets WHERE token_hash = " . pdo()->quote($qs['token'] ?? ''))->fetchColumn() === 0);
$p->get($path);
$r = $p->postForm('reset-password.php', ['token' => $qs['token'] ?? '', 'password' => $newPw, 'password_confirmation' => $newPw]);
check('reset token single-use', str_contains($r['body'], 'invalid or has expired'));
$l = new Client($BASE);
$l->get('login.php');
$r = $l->postForm('login.php', ['email' => $email, 'password' => $newPw]);
check('login with new password', $r['status'] === 303 && str_contains($r['location'], 'dashboard.php'));

// =================================================================== RATE LIMIT
section('Rate limiting');
$rl = new Client($BASE);
$rl->get('login.php');
$last = '';
for ($i = 0; $i < 7; $i++) {
    $last = $rl->postForm('login.php', ['email' => 'ratelimit@example.com', 'password' => 'Wrong12345'])['body'];
}
check('login attempts rate limited', str_contains($last, 'Too many sign-in attempts'));

// =================================================================== PAGES
section('All pages render without PHP errors');
foreach (['dashboard.php', 'cv.php', 'jobs.php', 'jobs.php?new=1', "jobs.php?edit=$jobId", 'interview.php', "interview.php?job=$jobId", 'scan.php', "scan.php?job=$jobId", 'practice.php', 'history.php', 'history.php?type=scan', 'settings.php', 'privacy.php', 'terms.php', 'nope.php'] as $pg) {
    $r = $l->get($pg);
    $ok = ($pg === 'nope.php' ? $r['status'] === 404 : $r['status'] === 200) && noPhpErrors($r['body']);
    check("GET /$pg", $ok, $r['status'] . ' ' . substr(strip_tags($r['body']), 0, 300));
}

// =================================================================== ACCOUNT DELETION
section('Account deletion');
$l->get('settings.php');
$r = $l->postForm('settings.php', ['action' => 'delete_account', 'confirm_password' => 'wrong', 'confirm_text' => 'DELETE']);
check('wrong password blocks deletion', $r['status'] === 200 && str_contains($r['body'], 'Password is incorrect'));
$r = $l->postForm('settings.php', ['action' => 'delete_account', 'confirm_password' => $newPw, 'confirm_text' => 'DELETE']);
check('account deleted → login', $r['status'] === 303 && str_contains($r['location'], 'login.php'));
$left = 0;
foreach (['users WHERE id', 'user_cvs WHERE user_id', 'jobs WHERE user_id', 'interview_sessions WHERE user_id', 'usage_logs WHERE user_id', 'user_settings WHERE user_id', 'password_resets WHERE user_id'] as $t) {
    $left += (int) pdo()->query("SELECT COUNT(*) FROM $t = $uid")->fetchColumn();
}
check('all DB rows removed', $left === 0, "$left rows left");
check('private files removed', !is_dir("$STORAGE/users/$uid"));
$l->get('login.php');
$r = $l->postForm('login.php', ['email' => $email, 'password' => $newPw]);
check('deleted account cannot sign in', str_contains($r['body'], 'Incorrect email or password'));

// =================================================================== LOGS
section('Logs');
$logText = implode("\n", array_map('file_get_contents', glob("$STORAGE/logs/*.log") ?: []));
check('logs contain no passwords / API key', !str_contains($logText, $pw) && !str_contains($logText, $newPw) && !str_contains($logText, 'sk-test-key'));
check('security events logged (failed login)', str_contains($logText, 'Failed login'));

echo "\n" . ($fail === 0 ? "\033[32m" : "\033[31m") . "Integration tests: $pass passed, $fail failed\033[0m\n";
exit($fail === 0 ? 0 : 1);
