<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

// Logout must be a POST with a valid CSRF token (prevents forced logout via links/images).
if (!is_post()) {
    redirect(Auth::check() ? 'dashboard.php' : 'login.php');
}
Csrf::verify();
Auth::logout();
Session::start();
Session::flash('success', 'You have been signed out.');
redirect('login.php');
