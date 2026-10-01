<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;

if (Auth::check()) {
    redirect('dashboard.php');
}

View::guest('landing', ['title' => 'CV-aware interview answer guidance', 'wide' => true]);
