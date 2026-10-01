<?php
/** @var int $status @var string $message */
$titles = [400 => 'Bad request', 403 => 'Access denied', 404 => 'Page not found', 405 => 'Not allowed', 419 => 'Session expired', 429 => 'Slow down', 503 => 'Service unavailable'];
$heading = $titles[$status] ?? 'Something went wrong';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($heading) ?> · <?= e(config('app.name', 'AI Interview Copilot')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest-body">
<main class="guest-main" id="main">
    <div class="card auth-card error-card">
        <p class="eyebrow">Error <?= (int) $status ?></p>
        <h1><?= e($heading) ?></h1>
        <p class="muted"><?= e($message) ?></p>
        <div class="btn-row">
            <a class="btn btn-primary" href="<?= e(url('index.php')) ?>">Go to home</a>
            <a class="btn btn-outline" href="<?= e(url('login.php')) ?>">Sign in</a>
        </div>
    </div>
</main>
</body>
</html>
