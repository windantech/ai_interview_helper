<?php

declare(strict_types=1);

/*
 * POST /api/end-session.php  JSON: {session_id}
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\InterviewSession;

Api::handle(function (): void {
    $user = Api::postGuard();
    $in = Api::input();
    $id = (int) ($in['session_id'] ?? 0);
    if (!InterviewSession::findForUser($id, (int) $user['id'])) {
        throw new HttpException(404, 'Interview session not found.');
    }
    InterviewSession::end($id, (int) $user['id']);
    unset($_SESSION['interview_ctx'][$id]);
    Response::success(['session_id' => $id, 'questions' => count(InterviewSession::questions($id))]);
});
