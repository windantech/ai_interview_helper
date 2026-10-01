<?php

declare(strict_types=1);

/*
 * POST /api/delete-session.php  JSON: {session_id}  or  {all: true} to delete all interview history.
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
    if (!empty($in['all'])) {
        $n = InterviewSession::deleteAllForUser((int) $user['id']);
        unset($_SESSION['interview_ctx']);
        Response::success(['deleted' => $n]);
    }
    if (!InterviewSession::delete((int) ($in['session_id'] ?? 0), (int) $user['id'])) {
        throw new HttpException(404, 'Interview session not found.');
    }
    Response::success(['deleted' => 1]);
});
