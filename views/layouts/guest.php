<?php
/** Guest layout (landing, auth, legal pages). @var string $pageTemplate */
$wide = $wide ?? false;
require __DIR__ . '/../partials/header.php';
$signedIn = \App\Core\Auth::check();
?>
<body class="guest-body">
<a class="skip-link" href="#main">Skip to content</a>
<header class="guest-header">
    <a class="brand" href="<?= e(url($signedIn ? 'dashboard.php' : 'index.php')) ?>">
        <span class="brand-mark" aria-hidden="true"><?= icon('mic') ?></span>
        <span class="brand-text"><?= e(config('app.name')) ?></span>
    </a>
    <nav class="guest-nav" aria-label="Account">
        <?php if ($signedIn): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
        <?php else: ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('login.php')) ?>">Sign in</a>
            <a class="btn btn-primary btn-sm" href="<?= e(url('register.php')) ?>">Get started</a>
        <?php endif; ?>
    </nav>
</header>
<main id="main" class="guest-main <?= $wide ? 'guest-wide' : '' ?>" tabindex="-1">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?php \App\Core\View::render($pageTemplate, get_defined_vars()); ?>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
