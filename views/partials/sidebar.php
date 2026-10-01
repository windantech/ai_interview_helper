<?php
/** @var string $active @var array $user */
$nav = [
    'dashboard' => ['Dashboard', 'dashboard.php', 'home'],
    'interview' => ['Interview', 'interview.php', 'mic'],
    'practice'  => ['Practice', 'practice.php', 'target'],
    'cv'        => ['My CV', 'cv.php', 'file'],
    'jobs'      => ['Target jobs', 'jobs.php', 'briefcase'],
    'history'   => ['History', 'history.php', 'history'],
    'settings'  => ['Settings', 'settings.php', 'settings'],
];
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="<?= e(url('dashboard.php')) ?>">
        <span class="brand-mark" aria-hidden="true"><?= icon('mic') ?></span>
        <span class="brand-text"><?= e(config('app.name')) ?></span>
    </a>
    <nav>
        <ul class="side-nav">
            <?php foreach ($nav as $key => [$label, $href, $ic]): ?>
                <li>
                    <a href="<?= e(url($href)) ?>" class="<?= ($active ?? '') === $key ? 'active' : '' ?>" <?= ($active ?? '') === $key ? 'aria-current="page"' : '' ?>>
                        <?= icon($ic) ?><span><?= e($label) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <div class="user-chip">
            <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
            <span class="user-meta">
                <strong><?= e($user['name']) ?></strong>
                <small><?= e($user['email']) ?></small>
            </span>
        </div>
        <form method="post" action="<?= e(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-block btn-sm"><?= icon('logout') ?> Sign out</button>
        </form>
    </div>
</aside>
