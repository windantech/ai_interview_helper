<?php

declare(strict_types=1);

/*
 * GET /api/research-export.php — one row per session, one column per detection signal, as CSV.
 * Covers only the signed-in user's own sessions. Intended for analysing a study's results offline.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\Database;
use App\Models\InterviewSession;
use App\Services\DetectionService;

Api::handle(function (): void {
    $user = Api::getGuard();
    $userId = (int) $user['id'];

    $sessions = InterviewSession::recent($userId, 200);
    $keys = ['latency', 'grounding', 'uniformity', 'vocabulary', 'register'];

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="detection-signals-' . gmdate('Y-m-d') . '.csv"');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(
        ['session_id', 'started_at', 'session_type', 'job_title', 'questions', 'answered', 'score', 'confidence'],
        array_map(fn ($k) => $k . '_level', $keys),
        array_map(fn ($k) => $k . '_flag', $keys)
    ));

    foreach ($sessions as $session) {
        $questions = InterviewSession::questions((int) $session['id']);
        $job = !empty($session['job_id'])
            ? Database::fetch('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [(int) $session['job_id'], $userId])
            : null;
        $a = DetectionService::analyse($session, $questions, $job);
        $by = [];
        foreach ($a['signals'] as $sig) {
            $by[$sig['key']] = $sig;
        }
        fputcsv($out, array_merge(
            [
                (int) $session['id'],
                (string) $session['started_at'],
                (string) $session['session_type'],
                (string) ($session['job_title'] ?? ''),
                (int) $a['summary']['questions'],
                (int) $a['summary']['answered'],
                (int) $a['score'],
                (string) $a['confidence'],
            ],
            // Blank rather than 0 where a signal could not be measured, so it is excluded from
            // analysis instead of being averaged in as a genuine zero.
            array_map(fn ($k) => isset($by[$k]) && $by[$k]['measured'] ? round($by[$k]['level'], 4) : '', $keys),
            array_map(fn ($k) => isset($by[$k]) && $by[$k]['measured'] ? (int) $by[$k]['flag'] : '', $keys)
        ));
    }
    fclose($out);
    exit;
});
