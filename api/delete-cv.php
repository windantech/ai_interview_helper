<?php

declare(strict_types=1);

/*
 * POST /api/delete-cv.php — deletes the CV row, the private file and the OpenAI copy.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\CVService;

Api::handle(function (): void {
    $user = Api::postGuard();
    if (!(new CVService())->delete((int) $user['id'])) {
        throw new HttpException(404, 'No CV to delete.');
    }
    Response::success(['deleted' => true]);
});
