<?php
/** Renders an extracted CV profile. @var array $profile */
$chips = function (string $title, array $items): void {
    if (!$items) {
        return;
    }
    echo '<div class="profile-block"><h3>' . e($title) . '</h3><div class="chip-row">';
    foreach ($items as $i) {
        echo '<span class="chip">' . e($i) . '</span>';
    }
    echo '</div></div>';
};
$bullets = function (string $title, array $items): void {
    if (!$items) {
        return;
    }
    echo '<div class="profile-block"><h3>' . e($title) . '</h3><ul class="bullets">';
    foreach ($items as $i) {
        echo '<li>' . e($i) . '</li>';
    }
    echo '</ul></div>';
};
?>
<div class="profile">
    <?php if (!empty($profile['full_name']) || !empty($profile['headline'])): ?>
        <div class="profile-block">
            <p class="profile-name"><?= e($profile['full_name'] ?? '') ?></p>
            <p class="muted"><?= e($profile['headline'] ?? '') ?><?= !empty($profile['years_experience']) ? ' · ' . e($profile['years_experience']) . ' experience' : '' ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($profile['summary'])): ?>
        <div class="profile-block"><h3>Professional summary</h3><p><?= e($profile['summary']) ?></p></div>
    <?php endif; ?>
    <?php $chips('Skills', $profile['skills'] ?? []); ?>
    <?php $chips('Tools & software', $profile['tools'] ?? []); ?>
    <?php $chips('Industries', $profile['industries'] ?? []); ?>
    <?php if (!empty($profile['roles'])): ?>
        <div class="profile-block">
            <h3>Employment history</h3>
            <ol class="timeline">
                <?php foreach ($profile['roles'] as $r): ?>
                    <li>
                        <p class="strong"><?= e($r['title']) ?><?= $r['employer'] ? ' · ' . e($r['employer']) : '' ?></p>
                        <?php if ($r['dates']): ?><p class="muted small"><?= e($r['dates']) ?></p><?php endif; ?>
                        <?php if ($r['highlights']): ?>
                            <ul class="bullets"><?php foreach ($r['highlights'] as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>
    <?php $bullets('Achievements', $profile['achievements'] ?? []); ?>
    <?php $bullets('Leadership experience', $profile['leadership'] ?? []); ?>
    <?php if (!empty($profile['projects'])): ?>
        <div class="profile-block"><h3>Major projects</h3><ul class="bullets">
            <?php foreach ($profile['projects'] as $p): ?><li><strong><?= e($p['name']) ?></strong><?= $p['description'] ? ' — ' . e($p['description']) : '' ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <?php if (!empty($profile['education'])): ?>
        <div class="profile-block"><h3>Education</h3><ul class="bullets">
            <?php foreach ($profile['education'] as $ed): ?><li><?= e($ed['qualification']) ?><?= $ed['institution'] ? ', ' . e($ed['institution']) : '' ?><?= $ed['year'] ? ' (' . e($ed['year']) . ')' : '' ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <?php $bullets('Certifications', $profile['certifications'] ?? []); ?>
</div>
