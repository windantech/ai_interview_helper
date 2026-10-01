<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Models\CV;

$user = Auth::requireUser();
$cv = CV::forUser((int) $user['id']);

View::page('pages/cv', [
    'title'   => 'My CV',
    'active'  => 'cv',
    'user'    => $user,
    'cv'      => $cv,
    'profile' => CV::profile($cv),
    'maxSize' => (int) config('app.max_cv_size_bytes'),
    'scripts' => ['cv.js'],
]);
