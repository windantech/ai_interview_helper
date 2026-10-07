<?php

declare(strict_types=1);

/*
 * POST /api/scan-extract.php  (multipart: pages[]=<image|pdf>, job_id?, hint?, instructions?)
 * Reads a photographed/uploaded question paper, extracts every question on it and opens a
 * "scan" session holding them. The answers themselves come from /api/generate-answer.php,
 * one question at a time (pass question_id for saved questions, transcript otherwise).
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
    RateLimiter::enforce('scan_extract', 'u' . $userId);
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

    $hint = is_string($_POST['hint'] ?? null) ? $_POST['hint'] : null;
    // Project names, employers and tools from the CV and job, so the model reads jargon correctly.
    $vocab = InterviewService::vocabularyHint($userId, $job, 300);
    $doc = (new ScanService())->extract($userId, $pages, $job, $hint, $vocab);

    if (!$doc['questions']) {
        $what = count($pages) === 1 ? 'that page' : 'those pages';
        throw new HttpException(422, $doc['document_type'] === 'unreadable'
            ? "We couldn't read $what clearly. Try again in better light, holding the camera straight above the page."
            : "We couldn't find any questions on $what. Make sure the whole question sheet is in the frame.", [
                'error_kind' => 'no_questions',
                'notes'      => $doc['notes'],
            ]);
    }

    // The scan opens a session of its own so the paper and its answers stay grouped in history.
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
        'document' => [
            'type'              => $doc['document_type'],
            'type_label'        => label_for('document_type', $doc['document_type']),
            'title'             => $doc['document_title'],
            'instructions_text' => $doc['instructions_text'],
            'notes'             => $doc['notes'],
            'written'           => $doc['written_answer_expected'],
            'pages'             => count($pages),
        ],
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
