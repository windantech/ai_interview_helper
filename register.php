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

    if (!RateLimiter::attempt('register', client_ip())) {
        Logger::security('Registration rate limit hit');
        $errors['form'] = 'Too many sign-up attempts. Please try again later.';
    } else {
        $v = Validator::make($_POST, [
            'name'                  => 'required|min:2|max:120',
            'email'                 => 'required|email|max:190',
            'password'              => 'required|max:200',
            'password_confirmation' => 'required|same:password',
            'agree'                 => 'required',
        ], ['password_confirmation' => 'Password confirmation', 'agree' => 'Agreement to the terms']);

        $errors = $v->errors();
        $data = $v->validated();
        if (!isset($errors['password']) && ($p = Validator::passwordProblem((string) $_POST['password']))) {
            $errors['password'] = $p;
        }
        if (!$errors && User::emailExists($data['email'])) {
            $errors['email'] = 'An account with this email already exists. Try signing in instead.';
        }

        if (!$errors) {
            $id = User::create($data['name'], $data['email'], (string) $_POST['password']);
            $user = User::find($id);
            Auth::login($user);
            User::touchLogin($id);
            Session::clearOld();
            Session::flash('success', 'Welcome, ' . $data['name'] . '! Start by uploading your CV.');
            redirect('dashboard.php');
        }
    }
}

View::guest('auth/register', ['title' => 'Create account', 'errors' => $errors]);
Session::clearOld();
