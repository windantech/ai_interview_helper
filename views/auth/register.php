<?php /** @var array $errors */ ?>
<div class="card auth-card">
    <h1>Create your account</h1>
    <p class="muted">Upload your CV, add a job description and get concise, CV-aware answer guidance.</p>

    <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('register.php')) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="name">Full name</label>
            <input type="text" id="name" name="name" autocomplete="name" required maxlength="120" value="<?= e(old('name')) ?>"
                <?= !empty($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>>
            <?php $field = 'name'; require __DIR__ . '/../components/field-error.php'; ?>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="email" required maxlength="190" value="<?= e(old('email')) ?>"
                <?= !empty($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
            <?php $field = 'email'; require __DIR__ . '/../components/field-error.php'; ?>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required minlength="8" maxlength="200"
                   aria-describedby="password-hint<?= !empty($errors['password']) ? ' password-error' : '' ?>"
                <?= !empty($errors['password']) ? 'aria-invalid="true"' : '' ?>>
            <p class="hint" id="password-hint">At least 8 characters, with a letter and a number.</p>
            <?php $field = 'password'; require __DIR__ . '/../components/field-error.php'; ?>
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required maxlength="200"
                <?= !empty($errors['password_confirmation']) ? 'aria-invalid="true" aria-describedby="password_confirmation-error"' : '' ?>>
            <?php $field = 'password_confirmation'; require __DIR__ . '/../components/field-error.php'; ?>
        </div>
        <div class="field">
            <label class="check">
                <input type="checkbox" name="agree" value="1" <?= old('agree') ? 'checked' : '' ?> required>
                <span>I agree to the <a href="<?= e(url('terms.php')) ?>" target="_blank" rel="noopener">Terms</a> and
                    <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">Privacy Policy</a>, and I will only use
                    this assistant where external assistance is permitted.</span>
            </label>
            <?php $field = 'agree'; require __DIR__ . '/../components/field-error.php'; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Create account</button>
    </form>
    <p class="auth-switch">Already have an account? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
</div>
