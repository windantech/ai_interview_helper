<?php /** @var array $errors @var bool $sent @var string|null $devLink */ ?>
<div class="card auth-card">
    <h1>Reset your password</h1>
    <?php if ($sent): ?>
        <div class="alert alert-success" role="status"><?= icon('check-circle') ?>
            <span>If an account exists for that email, we've sent a password reset link. It expires in 60 minutes.</span>
        </div>
        <?php if ($devLink): ?>
            <div class="alert alert-warning" role="note"><?= icon('info') ?>
                <span><strong>Development mode:</strong> email is not configured, so here is the reset link:
                    <a href="<?= e($devLink) ?>">Reset password</a></span>
            </div>
        <?php endif; ?>
        <p class="auth-switch"><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
    <?php else: ?>
        <p class="muted">Enter your account email and we'll send you a reset link.</p>
        <?php if (!empty($errors['form'])): ?>
            <div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('forgot-password.php')) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" autocomplete="email" required maxlength="190" value="<?= e(old('email')) ?>"
                    <?= !empty($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
                <?php $field = 'email'; require __DIR__ . '/../components/field-error.php'; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Send reset link</button>
        </form>
        <p class="auth-switch"><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
    <?php endif; ?>
</div>
