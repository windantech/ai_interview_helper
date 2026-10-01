<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\View;
use App\Models\InterviewSession;
use App\Models\Job;

$user = Auth::requireUser();
$userId = (int) $user['id'];

if (isset($_GET['id'])) {
    $session = InterviewSession::findForUser((int) $_GET['id'], $userId);
    if (!$session) {
        throw new HttpException(404, 'That interview session could not be found.');
    }
    View::page('pages/history-detail', [
        'title'     => 'Interview details',
        'active'    => 'history',
        'user'      => $user,
        'session'   => $session,
        'questions' => InterviewSession::questions((int) $session['id']),
        'scripts'   => ['history.js'],
    ]);
    return;
}

$date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
$filters = [
    'q'      => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
    'job_id' => (int) ($_GET['job_id'] ?? 0) ?: null,
    'from'   => $date($_GET['from'] ?? null),
    'to'     => $date($_GET['to'] ?? null),
    'type'   => in_array($_GET['type'] ?? '', ['live', 'practice'], true) ? $_GET['type'] : null,
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$result = InterviewSession::history($userId, $filters, $page, $perPage);

View::page('pages/history', [
    'title'   => 'Interview history',
    'active'  => 'history',
    'user'    => $user,
    'filters' => $filters,
    'jobs'    => Job::forUser($userId),
    'items'   => $result['items'],
    'total'   => $result['total'],
    'page'    => $page,
    'pages'   => max(1, (int) ceil($result['total'] / $perPage)),
    'scripts' => ['history.js'],
]);
