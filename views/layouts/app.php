<?php
/**
 * Authenticated layout: desktop sidebar, mobile topbar + bottom nav.
 * @var string $pageTemplate @var array $user @var string|null $active @var list<string>|null $scripts
 */
$active = $active ?? '';
require __DIR__ . '/../partials/header.php';
?>
<body class="app-body page-<?= e($active) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="app-shell">
    <?php require __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="app-main">
        <?php require __DIR__ . '/../partials/navbar.php'; ?>
        <main id="main" class="content" tabindex="-1">
            <?php require __DIR__ . '/../partials/flash.php'; ?>
            <?php \App\Core\View::render($pageTemplate, get_defined_vars()); ?>
        </main>
        <?php require __DIR__ . '/../partials/footer.php'; ?>
    </div>
</div>
<div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="false"></div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(asset('js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
