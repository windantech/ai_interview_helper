<?php

declare(strict_types=1);

/*
 * GET /api/history.php?q=&job_id=&from=&to=&page=   — paginated session list
 * GET /api/history.php?id=123                       — one session with all questions + answers
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\InterviewSession;

Api::handle(function (): void {
    $user = Api::getGuard();
    $userId = (int) $user['id'];

    if (isset($_GET['id'])) {
        $session = InterviewSession::findForUser((int) $_GET['id'], $userId);
        if (!$session) {
            throw new HttpException(404, 'Interview session not found.');
        }
        Response::success(['session' => $session, 'questions' => InterviewSession::questions((int) $session['id'])]);
    }

    $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    $filters = [
        'q'      => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
        'job_id' => (int) ($_GET['job_id'] ?? 0) ?: null,
        'from'   => $date($_GET['from'] ?? null),
        'to'     => $date($_GET['to'] ?? null),
        'type'   => $_GET['type'] ?? null,
    ];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $result = InterviewSession::history($userId, $filters, $page, 20);
    $items = array_map(fn ($s) => [
        'id'        => (int) $s['id'],
        'title'     => $s['title'],
        'job_title' => $s['job_title'],
        'company'   => $s['company'],
        'type'      => $s['session_type'],
        'status'    => $s['status'],
        'date'      => format_date($s['started_at']),
        'duration'  => format_duration($s['started_at'], $s['ended_at']),
        'questions' => (int) $s['question_count'],
    ], $result['items']);
    Response::success(['items' => $items, 'total' => $result['total'], 'page' => $page, 'pages' => (int) ceil($result['total'] / 20)]);
});
