<?php
/** Mobile top bar + bottom navigation. @var string $active @var array $user */
$bottom = [
    'dashboard' => ['Home', 'dashboard.php', 'home'],
    'cv'        => ['CV', 'cv.php', 'file'],
    'interview' => ['Interview', 'interview.php', 'mic'],
    'scan'      => ['Scan', 'scan.php', 'scan'],
    'jobs'      => ['Jobs', 'jobs.php', 'briefcase'],
    'history'   => ['History', 'history.php', 'history'],
];
?>
<header class="topbar">
    <a class="brand brand-sm" href="<?= e(url('dashboard.php')) ?>">
        <span class="brand-mark" aria-hidden="true"><?= icon('mic') ?></span>
        <span class="brand-text">Interview Copilot</span>
    </a>
    <button class="icon-btn" type="button" data-toggle-menu aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu">
        <?= icon('menu') ?>
    </button>
</header>

<div class="mobile-menu" id="mobile-menu" hidden>
    <div class="mobile-menu-panel" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="mobile-menu-head">
            <div class="user-chip">
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
                <span class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e($user['email']) ?></small></span>
            </div>
            <button class="icon-btn" type="button" data-close-menu aria-label="Close menu"><?= icon('x') ?></button>
        </div>
        <ul class="side-nav">
            <li><a href="<?= e(url('practice.php')) ?>" class="<?= $active === 'practice' ? 'active' : '' ?>"><?= icon('target') ?><span>Practice mode</span></a></li>
            <li><a href="<?= e(url('settings.php')) ?>" class="<?= $active === 'settings' ? 'active' : '' ?>"><?= icon('settings') ?><span>Settings</span></a></li>
            <li><a href="<?= e(url('privacy.php')) ?>"><?= icon('shield') ?><span>Privacy</span></a></li>
            <li><a href="<?= e(url('terms.php')) ?>"><?= icon('file') ?><span>Terms</span></a></li>
        </ul>
        <form method="post" action="<?= e(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline btn-block"><?= icon('logout') ?> Sign out</button>
        </form>
    </div>
</div>

<nav class="bottom-nav" aria-label="Primary">
    <?php foreach ($bottom as $key => [$label, $href, $ic]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= $key === 'interview' ? 'bn-primary ' : '' ?><?= $active === $key ? 'active' : '' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
            <?= icon($ic) ?><span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
