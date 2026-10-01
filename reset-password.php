<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\PasswordReset;
use App\Models\User;

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$reset = PasswordReset::findValid($token);
$errors = [];

if (is_post()) {
    Csrf::verify();
    if (!RateLimiter::attempt('reset_password', client_ip())) {
        $errors['form'] = 'Too many attempts. Please try again later.';
    } elseif ($reset === null) {
        Logger::security('Invalid or expired password reset token used');
        $errors['form'] = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $v = Validator::make($_POST, ['password' => 'required|max:200', 'password_confirmation' => 'required|same:password'], ['password_confirmation' => 'Password confirmation']);
        $errors = $v->errors();
        if (!isset($errors['password']) && ($p = Validator::passwordProblem((string) $_POST['password']))) {
            $errors['password'] = $p;
        }
        if (!$errors) {
            User::updatePassword((int) $reset['user_id'], (string) $_POST['password']);
            PasswordReset::markUsed((int) $reset['id']);
            Logger::info('Password reset completed', ['user_id' => $reset['user_id']]);
            Session::flash('success', 'Your password has been reset. Please sign in.');
            redirect('login.php');
        }
    }
}

View::guest('auth/reset-password', [
    'title'   => 'Reset password',
    'errors'  => $errors,
    'token'   => $token,
    'invalid' => $reset === null,
]);
