<?php

declare(strict_types=1);

/*
 * GET /cv-file.php — streams the signed-in user's own CV file through an authenticated route.
 * CV files live in /storage (never directly web-accessible).
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\HttpException;
use App\Models\CV;
use App\Services\CVService;

$user = Auth::requireUser();
$cv = CV::forUser((int) $user['id']);
if (!$cv) {
    throw new HttpException(404, 'No CV uploaded.');
}
$path = CVService::filePath($cv);
if (!is_file($path)) {
    throw new HttpException(404, 'The CV file could not be found.');
}

$name = preg_replace('/[^\w.\- ()]+/u', '_', (string) $cv['original_filename']) ?: 'cv';
$inline = ($_GET['view'] ?? '') === '1' && $cv['mime_type'] === 'application/pdf';

header('Content-Type: ' . $cv['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode((string) $cv['original_filename']));
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
