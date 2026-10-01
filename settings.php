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
use App\Models\CV;
use App\Models\InterviewSession;
use App\Models\Settings;
use App\Models\UsageLog;
use App\Models\User;
use App\Services\CVService;
use App\Services\FileUploadService;

$user = Auth::requireUser();
$userId = (int) $user['id'];
$errors = [];
$section = '';

if (is_post()) {
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    $section = $action;

    switch ($action) {
        case 'profile':
            $v = Validator::make($_POST, ['name' => 'required|min:2|max:120', 'email' => 'required|email|max:190']);
            $errors = $v->errors();
            $d = $v->validated();
            if (!$errors && User::emailExists($d['email'], $userId)) {
                $errors['email'] = 'That email is already used by another account.';
            }
            if (!$errors) {
                User::updateProfile($userId, $d['name'], $d['email']);
                Session::flash('success', 'Profile updated.');
                redirect('settings.php');
            }
            break;

        case 'password':
            if (!RateLimiter::attempt('reset_password', 'u' . $userId)) {
                $errors['current_password'] = 'Too many attempts. Please try again later.';
                break;
            }
            if (!User::verifyPassword($userId, (string) ($_POST['current_password'] ?? ''))) {
                $errors['current_password'] = 'Your current password is incorrect.';
                Logger::security('Password change: wrong current password', ['user_id' => $userId]);
                break;
            }
            $v = Validator::make($_POST, ['password' => 'required|max:200', 'password_confirmation' => 'required|same:password'], ['password_confirmation' => 'Password confirmation']);
            $errors = $v->errors();
            if (!isset($errors['password']) && ($p = Validator::passwordProblem((string) $_POST['password']))) {
                $errors['password'] = $p;
            }
            if (!$errors) {
                User::updatePassword($userId, (string) $_POST['password']);
                Session::regenerate();
                Session::flash('success', 'Password changed.');
                redirect('settings.php');
            }
            break;

        case 'preferences':
            $v = Validator::make($_POST, [
                'default_answer_mode'    => 'required|in:auto,quick,star,technical,leadership',
                'response_detail'        => 'required|in:short,medium',
                'default_interview_type' => 'required|in:' . implode(',', array_keys(options_for('interview_type'))),
                'transcription_mode'     => 'required|in:auto,live,recorded',
            ]);
            $errors = $v->errors();
            if (!$errors) {
                Settings::update($userId, $v->validated() + [
                    'auto_detect_question' => !empty($_POST['auto_detect_question']),
                    'show_transcript'      => !empty($_POST['show_transcript']),
                    'save_history'         => !empty($_POST['save_history']),
                ]);
                Session::flash('success', 'Interview preferences saved.');
                redirect('settings.php');
            }
            break;

        case 'delete_history':
            $n = InterviewSession::deleteAllForUser($userId);
            unset($_SESSION['interview_ctx']);
            Session::flash('success', "Deleted $n interview session" . ($n === 1 ? '' : 's') . '.');
            redirect('settings.php');

        case 'delete_cv':
            (new CVService())->delete($userId);
            Session::flash('success', 'Your CV and extracted profile were deleted.');
            redirect('settings.php');

        case 'delete_account':
            if (!User::verifyPassword($userId, (string) ($_POST['confirm_password'] ?? ''))) {
                $errors['confirm_password'] = 'Password is incorrect.';
                break;
            }
            if (trim((string) ($_POST['confirm_text'] ?? '')) !== 'DELETE') {
                $errors['confirm_text'] = 'Type DELETE to confirm.';
                break;
            }
            // Remove CV (incl. OpenAI copy), all private files, then the user (FK cascades remove all rows).
            (new CVService())->delete($userId);
            FileUploadService::deleteDir(rtrim((string) config('app.storage_path'), '/') . '/users/' . $userId);
            User::delete($userId);
            Logger::info('Account deleted', ['user_id' => $userId]);
            Auth::logout();
            Session::start();
            Session::flash('success', 'Your account and all associated data have been permanently deleted.');
            redirect('login.php');

        default:
            Session::flash('error', 'Unknown action.');
            redirect('settings.php');
    }
}

View::page('pages/settings', [
    'title'    => 'Settings',
    'active'   => 'settings',
    'user'     => User::find($userId) ?? $user,
    'settings' => Settings::forUser($userId),
    'hasCv'    => CV::metaForUser($userId) !== null,
    'usage'    => UsageLog::summary($userId),
    'errors'   => $errors,
    'section'  => $section,
]);
