<?php

declare(strict_types=1);

/*
 * POST /api/session-instructions.php  JSON: {session_id, instructions}
 * Saves the candidate's own instructions for an interview (e.g. "When asked for a sample project, use finKAP").
 * They are sent with every following question in that session.
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
    $text = is_string($in['instructions'] ?? null) ? $in['instructions'] : '';
    if (mb_strlen(trim($text)) > InterviewSession::MAX_INSTRUCTIONS) {
        throw new HttpException(422, 'Instructions are too long (max ' . InterviewSession::MAX_INSTRUCTIONS . ' characters).');
    }
    if (!InterviewSession::findForUser($id, (int) $user['id'])) {
        throw new HttpException(404, 'Interview session not found.');
    }
    InterviewSession::updateInstructions($id, (int) $user['id'], $text);
    Response::success(['session_id' => $id, 'instructions' => InterviewSession::cleanInstructions($text)]);
});
