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
use App\Models\User;

Auth::redirectIfAuthenticated();

$errors = [];

if (is_post()) {
    Csrf::verify();
    Session::flashInput($_POST);

    $v = Validator::make($_POST, ['email' => 'required|email|max:190', 'password' => 'required|max:200']);
    if ($v->fails()) {
        $errors = $v->errors();
    } else {
        $email = $v->validated()['email'];
        $ipAllowed = RateLimiter::attempt('login', 'ip:' . client_ip(), 30, 900);
        $acctAllowed = RateLimiter::attempt('login', 'acct:' . $email);

        if (!$ipAllowed || !$acctAllowed) {
            Logger::security('Login rate limit hit', ['email_hash' => substr(hash('sha256', $email), 0, 12)]);
            $errors['form'] = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
        } else {
            $user = User::findByEmailWithHash($email);
            // Always run password_verify to reduce user-enumeration timing differences.
            $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
            $valid = password_verify((string) $_POST['password'], $hash);

            if ($user && $valid && $user['status'] === 'active') {
                User::rehashIfNeeded((int) $user['id'], (string) $_POST['password'], $user['password_hash']);
                unset($user['password_hash']);
                Auth::login($user);
                User::touchLogin((int) $user['id']);
                RateLimiter::clear('login', 'acct:' . $email);
                Session::clearOld();
                $intended = $_SESSION['intended'] ?? null;
                unset($_SESSION['intended']);
                // Only follow same-app relative paths.
                if (is_string($intended) && str_starts_with($intended, base_path() . '/') && !str_contains($intended, '//') && !str_contains($intended, '/api/')) {
                    header('Location: ' . $intended, true, 303);
                    exit;
                }
                redirect('dashboard.php');
            }
            if ($user && $valid && $user['status'] !== 'active') {
                $errors['form'] = 'This account is not active. Please contact support.';
            } else {
                Logger::security('Failed login', ['email_hash' => substr(hash('sha256', $email), 0, 12)]);
                $errors['form'] = 'Incorrect email or password.';
            }
        }
    }
}

View::guest('auth/login', ['title' => 'Sign in', 'errors' => $errors]);
Session::clearOld();
