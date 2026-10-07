<?php

declare(strict_types=1);

/*
 * POST /api/scan-extract.php
 *   multipart: pages[]=<image|pdf>, job_id?, hint?, instructions?, extract_only?, session_id?, live?
 *
 * Reads a photographed/uploaded question paper and extracts every question on it. Three modes:
 *
 *   (default)       opens a "scan" session holding the questions — the Scan page capturing a paper.
 *   session_id=N    appends only the questions that session does not already hold, and returns
 *                   those. Live scanning sends a frame at a time this way.
 *   extract_only=1  returns the questions and stores nothing — the interview screen pulling one
 *                   question into the interview already in progress.
 *
 * live=1 tells the model it is seeing one frame of a document being scrolled past, so it skips
 * questions cut off at the frame edge and returns nothing rather than guessing at blurred text.
 *
 * The answers themselves come from /api/generate-answer.php, one question at a time
 * (pass question_id for stored questions, transcript otherwise).
 *
 * Page images are processed in memory and never written to disk; a scanned PDF is stored only
 * for the length of the request and deleted again.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\InterviewSession;
use App\Models\Job;
use App\Models\Settings;
use App\Services\FileUploadService;
use App\Services\InterviewService;
use App\Services\ScanService;

Api::handle(function (): void {
    $user = Api::postGuard();
    $userId = (int) $user['id'];

    // A live scan sends one frame at a time whenever the view settles on something new, so it needs
    // a looser budget than a deliberate multi-page capture.
    $live = filter_var($_POST['live'] ?? false, FILTER_VALIDATE_BOOLEAN);
    RateLimiter::enforce($live ? 'scan_live' : 'scan_extract', 'u' . $userId);
    @set_time_limit(240);

    if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        Logger::warning('Scan upload exceeded post_max_size');
        throw new HttpException(413, 'Those pages are too large to upload together. Please scan fewer pages at a time.');
    }

    $maxPages = (int) config('app.max_scan_pages');
    $files = normalise_uploads($_FILES['pages'] ?? null);
    if (!$files) {
        throw new HttpException(400, 'Please capture or choose at least one page to scan.');
    }
    if (count($files) > $maxPages) {
        throw new HttpException(422, 'You can scan up to ' . $maxPages . ' pages at a time.');
    }

    $pages = [];
    $total = 0;
    foreach ($files as $i => $file) {
        $page = FileUploadService::validate($file, FileUploadService::SCAN_TYPES, (int) config('app.max_scan_page_bytes'), 'page ' . ($i + 1));
        $total += $page['size'];
        // The pages are base64-encoded into a single request, so cap the batch as well as each page.
        if ($total > (int) config('app.max_scan_total_bytes')) {
            throw new HttpException(413, 'Those pages come to more than ' . format_bytes((int) config('app.max_scan_total_bytes')) . ' together. Please scan fewer pages at a time.');
        }
        $pages[] = $page;
    }

    $job = null;
    if (!empty($_POST['job_id'])) {
        $job = Job::findForUser((int) $_POST['job_id'], $userId);
        if (!$job) {
            throw new HttpException(404, 'Selected job not found.');
        }
        Settings::update($userId, ['active_job_id' => (int) $job['id']]);
    }

    // Appending to a scan already in progress: each live frame adds whatever is new on it.
    $session = null;
    if (!empty($_POST['session_id'])) {
        $session = InterviewSession::findForUser((int) $_POST['session_id'], $userId);
        if (!$session || $session['session_type'] !== 'scan') {
            throw new HttpException(404, 'Scan not found. Please start a new scan.');
        }
        if ($session['status'] !== 'active') {
            throw new HttpException(409, 'That scan has been finished. Start a new one to keep scanning.');
        }
    }

    $hint = is_string($_POST['hint'] ?? null) ? $_POST['hint'] : null;
    // Project names, employers and tools from the CV and job, so the model reads jargon correctly.
    $vocab = InterviewService::vocabularyHint($userId, $job, 300);
    $doc = (new ScanService())->extract($userId, $pages, $job, $hint, $vocab, $live);

    // A live frame that shows nothing readable is normal — the camera is still moving, or the page
    // is between questions. Answer "nothing new" rather than treating it as a failure.
    if ($live && !$doc['questions']) {
        Response::success(['session' => $session ? ['id' => (int) $session['id']] : null, 'questions' => [], 'added' => 0, 'nothing_new' => true]);
    }

    if (!$doc['questions']) {
        $what = count($pages) === 1 ? 'that page' : 'those pages';
        throw new HttpException(422, $doc['document_type'] === 'unreadable'
            ? "We couldn't read $what clearly. Try again in better light, holding the camera straight above the page."
            : "We couldn't find any questions on $what. Make sure the whole question sheet is in the frame.", [
                'error_kind' => 'no_questions',
                'notes'      => $doc['notes'],
            ]);
    }

    $document = [
        'type'              => $doc['document_type'],
        'type_label'        => label_for('document_type', $doc['document_type']),
        'title'             => $doc['document_title'],
        'instructions_text' => $doc['instructions_text'],
        'notes'             => $doc['notes'],
        'written'           => $doc['written_answer_expected'],
        'pages'             => count($pages),
    ];

    // Appending to a running scan: keep only the questions it does not already hold. Frames of a
    // live scan overlap heavily, so most of what each one reads is already on the list.
    if ($session !== null) {
        $known = [];
        // (A session only exists when history saving is on, so the rows are safe to write.)
        foreach (InterviewSession::questionTexts((int) $session['id']) as $text) {
            $known[ScanService::dedupeKey($text)] = true;
        }
        $room = ScanService::MAX_QUESTIONS - count($known);
        $added = [];
        foreach ($doc['questions'] as $q) {
            if ($room <= 0) {
                break;
            }
            $key = ScanService::dedupeKey($q['text']);
            if (isset($known[$key])) {
                continue;
            }
            $known[$key] = true;
            $room--;
            $added[] = $q + ['id' => InterviewSession::addQuestion((int) $session['id'], [
                'question'        => $q['text'],
                'question_number' => $q['number'] !== '' ? $q['number'] : null,
                'question_type'   => $q['question_type'],
                'answer_mode'     => 'auto',
                'source'          => 'scan',
            ])];
        }
        Response::success([
            'session'     => ['id' => (int) $session['id']],
            'document'    => $document,
            'questions'   => $added,
            'added'       => count($added),
            'total'       => count($known),
            'full'        => $room <= 0,
            'nothing_new' => $added === [],
            'saved'       => true,
        ]);
    }

    // The interview screen scans a question into the interview already in progress, so it asks for
    // the questions only: no session of its own, nothing stored until an answer is generated.
    if (filter_var($_POST['extract_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        Response::success([
            'session'   => null,
            'document'  => $document,
            'questions' => array_map(fn ($q) => $q + ['id' => null], $doc['questions']),
            'saved'     => false,
        ]);
    }

    // Otherwise the scan opens a session of its own so the paper and its answers stay grouped in history.
    $job = (new InterviewService())->ensureJobSummary($userId, $job);
    if (array_key_exists('instructions', $_POST)) {
        $instructions = is_string($_POST['instructions']) ? $_POST['instructions'] : null;
        if ($instructions !== null && mb_strlen(trim($instructions)) > InterviewSession::MAX_INSTRUCTIONS) {
            throw new HttpException(422, 'Instructions are too long (max ' . InterviewSession::MAX_INSTRUCTIONS . ' characters).');
        }
    } else {
        $instructions = InterviewSession::lastInstructions($userId, $job ? (int) $job['id'] : null);
    }

    InterviewSession::endActive($userId, 'scan');
    $sessionId = InterviewSession::create($userId, $job, 'scan', $instructions);
    InterviewSession::rename($sessionId, $userId, ScanService::sessionTitle($doc));

    // Store the questions up front (unanswered) so answering one is an update, and a part-finished
    // paper can be picked up again from history. With history off nothing is written.
    $save = (bool) Settings::forUser($userId)['save_history'];
    $questions = [];
    foreach ($doc['questions'] as $q) {
        $questions[] = $q + ['id' => $save ? InterviewSession::addQuestion($sessionId, [
            'question'        => $q['text'],
            'question_number' => $q['number'] !== '' ? $q['number'] : null,
            'question_type'   => $q['question_type'],
            'answer_mode'     => 'auto',
            'source'          => 'scan',
        ]) : null];
    }

    $session = InterviewSession::findForUser($sessionId, $userId);
    Response::success([
        'session' => [
            'id'           => $sessionId,
            'title'        => $session['title'],
            'instructions' => $session['instructions'],
            'started_at'   => $session['started_at'],
        ],
        'document'  => $document,
        'questions' => $questions,
        'saved'     => $save,
    ], 201);
});

/**
 * Turn one $_FILES entry into a list of single-file entries, whether the field was sent as
 * pages[] (arrays per key) or as a single file.
 * @return list<array<string,mixed>>
 */
function normalise_uploads(mixed $entry): array
{
    if (!is_array($entry) || !isset($entry['error'])) {
        return [];
    }
    if (!is_array($entry['error'])) {
        return [$entry];
    }
    $out = [];
    foreach (array_keys($entry['error']) as $i) {
        if ((int) $entry['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name'     => $entry['name'][$i] ?? 'page',
            'type'     => $entry['type'][$i] ?? '',
            'tmp_name' => $entry['tmp_name'][$i] ?? '',
            'error'    => $entry['error'][$i],
            'size'     => $entry['size'][$i] ?? 0,
        ];
    }
    return $out;
}
