<?php /** @var array $errors */ ?>
<div class="card auth-card">
    <h1>Welcome back</h1>
    <p class="muted">Sign in to continue preparing for your interview.</p>

    <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login.php')) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="email" required maxlength="190"
                   value="<?= e(old('email')) ?>" <?= !empty($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
            <?php $field = 'email'; require __DIR__ . '/../components/field-error.php'; ?>
        </div>
        <div class="field">
            <div class="label-row">
                <label for="password">Password</label>
                <a class="small-link" href="<?= e(url('forgot-password.php')) ?>">Forgot password?</a>
            </div>
            <input type="password" id="password" name="password" autocomplete="current-password" required maxlength="200"
                   <?= !empty($errors['password']) ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>>
            <?php $field = 'password'; require __DIR__ . '/../components/field-error.php'; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
    </form>
    <p class="auth-switch">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
</div>
