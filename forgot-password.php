<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\Mailer;

Auth::redirectIfAuthenticated();

$errors = [];
$sent = false;
$devLink = null;

if (is_post()) {
    Csrf::verify();
    Session::flashInput($_POST);
    $v = Validator::make($_POST, ['email' => 'required|email|max:190']);
    if ($v->fails()) {
        $errors = $v->errors();
    } elseif (!RateLimiter::attempt('forgot_password', client_ip())) {
        $errors['form'] = 'Too many reset requests. Please try again later.';
    } else {
        $email = $v->validated()['email'];
        $user = User::findByEmailWithHash($email);
        if ($user && $user['status'] === 'active') {
            PasswordReset::purgeExpired();
            $token = PasswordReset::create((int) $user['id']);
            $link = absolute_url('reset-password.php', ['token' => $token]);
            $body = "Hi {$user['name']},\n\nWe received a request to reset your " . config('app.name') . " password.\n\n"
                . "Reset your password using this link (valid for " . PasswordReset::TTL_MINUTES . " minutes):\n$link\n\n"
                . "If you didn't request this, you can ignore this email.\n";
            $mailed = Mailer::send($user['email'], 'Reset your password', $body);
            if (!$mailed && is_debug()) {
                // Development convenience only: show the link on screen when mail is not configured.
                $devLink = $link;
            }
            Logger::info('Password reset requested', ['user_id' => $user['id'], 'mailed' => $mailed]);
        }
        // Same response whether or not the account exists (prevents account enumeration).
        $sent = true;
        Session::clearOld();
    }
}

View::guest('auth/forgot-password', ['title' => 'Forgot password', 'errors' => $errors, 'sent' => $sent, 'devLink' => $devLink]);
Session::clearOld();
