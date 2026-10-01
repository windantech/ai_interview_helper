<?php /** @var array $errors @var string $token @var bool $invalid */ ?>
<div class="card auth-card">
    <h1>Choose a new password</h1>
    <?php if ($invalid): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert') ?>
            <span>This reset link is invalid or has expired.</span>
        </div>
        <a class="btn btn-primary btn-block" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a>
    <?php else: ?>
        <?php if (!empty($errors['form'])): ?>
            <div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('reset-password.php')) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="field">
                <label for="password">New password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" required minlength="8" maxlength="200"
                       aria-describedby="password-hint">
                <p class="hint" id="password-hint">At least 8 characters, with a letter and a number.</p>
                <?php $field = 'password'; require __DIR__ . '/../components/field-error.php'; ?>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required maxlength="200">
                <?php $field = 'password_confirmation'; require __DIR__ . '/../components/field-error.php'; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Reset password</button>
        </form>
    <?php endif; ?>
</div>
