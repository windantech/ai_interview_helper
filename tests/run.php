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
use App\Services\ScanService;
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
    'cv_evidence' => ['Led team'], 'evidence_strength' => 'direct', 'evidence_note' => '', 'closing_line' => '"Close."', 'keywords' => ['Conflict', 'Leadership'],
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
$writtenPara = str_repeat('This paragraph is long enough to prove that written answers are not truncated at the spoken limit. ', 6);
$written = $valid;
$written['answer_mode'] = 'written';
$written['sections'] = [['label' => 'Opening', 'bullets' => [$writtenPara]]];
$nw = InterviewService::normaliseAnswer($written);
check('written mode kept', $nw['answer_mode'] === 'written' && InterviewService::isWritten('written'));
check('written paragraphs not cut to the spoken 220-char limit', mb_strlen($nw['sections'][0]['bullets'][0]) > 500);
check('spoken bullets still capped at 220', mb_strlen(InterviewService::normaliseAnswer(['is_question' => true, 'question' => 'Q?', 'answer_mode' => 'star',
    'sections' => [['label' => 'Situation', 'bullets' => [$writtenPara]]], 'points' => []])['sections'][0]['bullets'][0]) === 220);
check('evidence strength kept', $n['evidence_strength'] === 'direct');
$adj = InterviewService::normaliseAnswer(['is_question' => true, 'question' => 'Q?', 'points' => ['p'],
    'cv_evidence' => ['Ran a depot upgrade'], 'evidence_strength' => 'adjacent', 'evidence_note' => 'Bridged.']);
check('bridged answers are marked adjacent, not hidden', $adj['evidence_strength'] === 'adjacent' && $adj['cv_evidence'] && $adj['evidence_note'] === 'Bridged.');
$bad = InterviewService::normaliseAnswer(['is_question' => true, 'question' => 'Q?', 'points' => ['p'],
    'cv_evidence' => ['Something relevant'], 'evidence_strength' => 'nonsense']);
check('unknown strength falls back to what was actually produced', $bad['evidence_strength'] === 'adjacent');
check('no evidence at all falls back to general', InterviewService::normaliseAnswer(['is_question' => true,
    'question' => 'Q?', 'points' => ['p'], 'cv_evidence' => []])['evidence_strength'] === 'general');

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
check('scan schema strict-valid', $strictOk(ScanService::schema()));
check('all 13 question types present', count(InterviewService::QUESTION_TYPES) === 13);
check('scan question types reuse the answer enum', ScanService::schema()['properties']['questions']['items']['properties']['question_type']['enum'] === InterviewService::QUESTION_TYPES);

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
check('interview instructions included in prompt', str_contains($withIns, "CANDIDATE'S OWN INSTRUCTIONS AND EXTRA EXPERIENCE") && str_contains($withIns, 'use finKAP'));
check('experience the candidate adds is first-hand, not hedged', str_contains($withIns, 'AS AUTHORITATIVE AS THE CV')
    && str_contains($withIns, 'do NOT hedge, qualify or say they have not done it'));
check('and it counts as direct evidence', str_contains($withIns, 'set evidence_strength to \\"direct\\"')
    && str_contains($sys, 'OR stated by the candidate in their own instructions'));
check('specifics they did not give are still gaps, not inventions', str_contains($withIns, 'leave a short square-bracket gap for them to fill rather than inventing one'));
check('strict structured output requested', $payload['text']['format']['strict'] === true && $payload['text']['format']['type'] === 'json_schema');
check('no-CV context forbids invention', str_contains(InterviewService::candidateContext(null), 'never invent'));
check('prompt asks for speakable first-person sentences, no coaching instructions', str_contains($payload['instructions'], 'say exactly as written') && str_contains($payload['instructions'], 'NEVER be instructions'));
$writtenPayload = $svc->buildAnswerPayload('Discuss project recovery. [20 marks]', 'written', 'short', $job, $cvRow, [], false);
$wtext = json_encode($writtenPayload);
check('written mode overrides the spoken-delivery rules', str_contains($wtext, 'NOT SPOKEN') && str_contains($wtext, 'flowing first-person prose'));
check('written mode gets a larger output budget', $writtenPayload['max_output_tokens'] > $payload['max_output_tokens']);
check('written mode is told the text came off a paper', str_contains($wtext, 'read off a question paper'));
check('auto mode can still choose a written answer', str_contains($ptext, 'has to WRITE out'));
$sys = $payload['instructions'];
check('CV is framed as a starting point, not a limit', str_contains($sys, 'STARTING POINT, NOT A LIMIT')
    && str_contains($sys, 'NOT proof they have never done it'));
check('every question gets a full answer', str_contains($sys, 'ALWAYS answer the question in full')
    && str_contains($sys, 'never tell the candidate you have no evidence'));
check('uncovered questions bridge from the closest real project', str_contains($sys, 'anchored in the CLOSEST real project')
    && str_contains($sys, 'That is a good answer, not a disclaimer'));
check('nothing adjacent → general answer with a gap to fill', str_contains($sys, 'leave a short square-bracket gap'));
check('a bridged answer owns the gap in its first spoken sentence', str_contains($sys, 'VERY FIRST sentence the candidate says')
    && str_contains($sys, 'is a lie the interviewer may well catch'));
check('STAR bridges open honestly in Situation', str_contains($ptext, 'Situation opens by saying so'));
check('adjacent evidence counts as evidence', str_contains($sys, 'shares the skill, the problem shape'));
check('answers report how well the CV backs them', str_contains($sys, 'evidence_strength') && count(InterviewService::EVIDENCE_STRENGTHS) === 3);
check('no-CV still answers rather than refusing', str_contains(InterviewService::candidateContext(null), 'Still answer every question in full'));
check('checkable facts are still off limits', str_contains($sys, 'NEVER invent are the checkable facts')
    && str_contains($sys, 'employers, job titles, dates, qualifications, certifications, and numbers or metrics'));
check('and that restriction is scoped, not a licence to withhold', str_contains($sys, 'it is not a reason to withhold the answer'));
check('metrics are never fabricated', str_contains($sys, 'Never make up a metric'));

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

// ---------------------------------------------------------------- Scanned papers
section('Scanned question papers');
$jpg = "\xFF\xD8\xFF\xE0" . pack('n', 16) . 'JFIF' . "\0" . str_repeat("\x20", 400) . "\xFF\xD9";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAAMBAQAY3Y2wAAAAAElFTkSuQmCC');   // real 1x1 PNG
$webp = base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');  // real 1x1 lossless WebP
check('JPEG page accepted', FileUploadService::validate($mk('page-1.jpg', $jpg), FileUploadService::SCAN_TYPES, $max, 'page 1')['mime'] === 'image/jpeg');
check('PNG page accepted', FileUploadService::validate($mk('page-1.png', $png), FileUploadService::SCAN_TYPES, $max, 'page 1')['ext'] === 'png');
check('WebP page accepted', FileUploadService::validate($mk('page-1.webp', $webp), FileUploadService::SCAN_TYPES, $max, 'page 1')['mime'] === 'image/webp');
check('PDF paper accepted', FileUploadService::validate($mk('paper.pdf', $pdf), FileUploadService::SCAN_TYPES, $max, 'page 1')['ext'] === 'pdf');
check('corrupt PNG rejected', throws(fn () => FileUploadService::validate($mk('page.png', "\x89PNG\r\n\x1A\n" . str_repeat("\0", 200)), FileUploadService::SCAN_TYPES, $max, 'page 1'), HttpException::class));
check('image not accepted as a CV', throws(fn () => FileUploadService::validate($mk('cv.jpg', $jpg), FileUploadService::CV_TYPES, $max, 'CV'), HttpException::class, fn ($e) => $e->status() === 415));
check('SVG page rejected', throws(fn () => FileUploadService::validate($mk('page.svg', '<svg onload="alert(1)"/>'), FileUploadService::SCAN_TYPES, $max, 'page 1'), HttpException::class, fn ($e) => $e->status() === 415));
check('text renamed .jpg rejected', throws(fn () => FileUploadService::validate($mk('page.jpg', $cvText), FileUploadService::SCAN_TYPES, $max, 'page 1'), HttpException::class));
check('DOCX not a scannable page', throws(fn () => FileUploadService::validate($mk('page.docx', $cvText), FileUploadService::SCAN_TYPES, $max, 'page 1'), HttpException::class, fn ($e) => $e->status() === 415));

$scan = ScanService::normalise([
    'document_type' => 'essay_exam',
    'document_title' => '  Management  Paper 2 ',
    'instructions_text' => 'Answer any three questions.',
    'written_answer_expected' => true,
    'notes' => '',
    'questions' => [
        ['number' => '1', 'text' => "Discuss  how you would\nmanage a late project.", 'marks' => '[20 marks]', 'question_type' => 'management'],
        ['number' => '1', 'text' => 'Discuss how you would manage a late project.', 'marks' => '', 'question_type' => 'management'],
        ['number' => '2', 'text' => 'Why?', 'marks' => '', 'question_type' => 'general'],
        ['number' => '3', 'text' => 'Explain the role of stakeholder communication.', 'marks' => '', 'question_type' => 'nonsense'],
    ],
]);
check('whitespace collapsed in questions', $scan['questions'][0]['text'] === 'Discuss how you would manage a late project.');
check('duplicate questions dropped', count($scan['questions']) === 2);
check('too-short lines dropped', !str_contains(json_encode($scan['questions']), 'Why?'));
check('invalid question type coerced', $scan['questions'][1]['question_type'] === 'unknown');
check('title trimmed', $scan['document_title'] === 'Management Paper 2');
check('written expectation kept', $scan['written_answer_expected'] === true);
check('marks preserved', $scan['questions'][0]['marks'] === '[20 marks]');
$many = ScanService::normalise(['document_type' => 'other', 'questions' => array_map(
    fn ($i) => ['number' => (string) $i, 'text' => 'Question number ' . $i . ' asks something specific.', 'marks' => '', 'question_type' => 'general'],
    range(1, ScanService::MAX_QUESTIONS + 15)
)]);
check('question list capped', count($many['questions']) === ScanService::MAX_QUESTIONS);
check('unknown document type coerced', $many['document_type'] === 'other');
check('missing fields default safely', ScanService::normalise([])['questions'] === [] && ScanService::normalise([])['notes'] === '');
check('session title names the paper', ScanService::sessionTitle($scan) === 'Essay / exam paper — Management Paper 2');
check('session title without a printed title', ScanService::sessionTitle(['document_type' => 'application_form', 'document_title' => '']) === 'Application form');

$page1 = ['tmp' => $tmp . '/sp1.png', 'ext' => 'png', 'mime' => 'image/png'];
$page2 = ['tmp' => $tmp . '/sp2.jpg', 'ext' => 'jpg', 'mime' => 'image/jpeg'];
file_put_contents($page1['tmp'], $png);
file_put_contents($page2['tmp'], $jpg);
$scanJob = ['id' => 1, 'title' => 'Senior Project Manager', 'company' => 'ABC Ltd', 'industry' => null, 'seniority' => 'senior',
    'interview_type' => 'behavioural', 'main_skills' => 'Risk', 'description' => 'x', 'jd_summary_json' => null];
$sp = ScanService::buildPayload(
    [ScanService::imageInput($page1), ScanService::imageInput($page2)],
    $scanJob, 'Section B only.', 'Acme Build, Eval360, PRINCE2'
);
$spText = json_encode($sp, JSON_UNESCAPED_SLASHES);
check('scan payload: strict scanned_questions schema', ($sp['text']['format']['name'] ?? '') === 'scanned_questions' && $sp['text']['format']['strict'] === true);
check('scan payload: store disabled', $sp['store'] === false);
check('scan payload: both pages in one call', substr_count($spText, '"type":"input_image"') === 2);
check('scan payload: pages inlined as data URLs', str_contains($spText, '"image_url":"data:image/png;base64,') && str_contains($spText, '"image_url":"data:image/jpeg;base64,'));
check('scan payload: full-resolution pass for text', substr_count($spText, '"detail":"high"') === 2);
check('scan payload: page order and de-duplication stated', str_contains($spText, 'consecutive pages of the same question paper, in order') && str_contains($spText, 'never list the same question twice'));
check('scan payload: job + CV vocabulary + candidate hint included', str_contains($spText, 'Senior Project Manager') && str_contains($spText, 'Eval360') && str_contains($spText, 'Section B only.'));
check('scan payload: no local file paths leaked', !str_contains($spText, $tmp));
check('scan payload: output budget set', $sp['max_output_tokens'] === 4000);
check('scan payload: rubric/timing text excluded from questions', str_contains($sp['instructions'], 'Time allowed')
    && str_contains($sp['instructions'], 'mark schemes') && str_contains($sp['instructions'], 'instructions_text, not in questions'));
check('scan payload: never invent a question', str_contains($sp['instructions'], 'never invent one that is not on the page'));
check('scan payload: unreadable pages reported, not guessed', str_contains($sp['instructions'], 'unreadable'));
$one = ScanService::buildPayload([ScanService::imageInput($page1)]);
$livePayload = ScanService::buildPayload([ScanService::imageInput($page1)], $scanJob, null, '', true);
$liveText = json_encode($livePayload);
check('live frames are told they are mid-scroll', str_contains($liveText, 'being scrolled past'));
check('live frames skip questions cut off at the edge', str_contains($liveText, 'runs off the top or bottom edge'));
check('live frames return nothing rather than guess at blur', str_contains($liveText, 'Ignore blurred text rather than guessing')
    && str_contains($liveText, 'Returning nothing is correct'));
check('a deliberate capture is not told any of that', !str_contains($spText, 'being scrolled past'));
check('live and deliberate captures share one schema', $livePayload['text']['format'] === $sp['text']['format']);

check('dedupe key ignores case, spacing and punctuation',
    ScanService::dedupeKey('Discuss  how you would manage a LATE project.') === ScanService::dedupeKey('discuss how you would manage a late project'));
check('dedupe key still separates different questions',
    ScanService::dedupeKey('Describe a conflict you resolved.') !== ScanService::dedupeKey('Describe a project you delivered.'));
check('normalise dedupes with that same key', count(ScanService::normalise(['questions' => [
    ['number' => '1', 'text' => 'Discuss how you would manage a late project.', 'marks' => '', 'question_type' => 'general'],
    ['number' => '1', 'text' => '  discuss   how you would manage a LATE project!  ', 'marks' => '', 'question_type' => 'general'],
]])['questions']) === 1);

check('single page prompt differs from multi-page', str_contains(json_encode($one), 'is one page of a question paper') && !str_contains(json_encode($one), 'consecutive pages'));
check('scan payload without a job still valid', ($one['text']['format']['strict'] ?? false) === true && count($one['input'][0]['content']) === 2);
check('PDF pages go through the Files API, not a data URL', str_contains(file_get_contents(APP_ROOT . '/app/Services/ScanService.php'), "'type' => 'input_file', 'file_id' => $fileId"));

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
