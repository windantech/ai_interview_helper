<?php
/** Practice feedback renderer. @var array $feedback */
$f = $feedback;
$list = function (string $title, array $items, string $cls = ''): void {
    if (!$items) {
        return;
    }
    echo '<div class="fb-block ' . e($cls) . '"><h3>' . e($title) . '</h3><ul>';
    foreach ($items as $i) {
        echo '<li>' . e($i) . '</li>';
    }
    echo '</ul></div>';
};
?>
<div class="feedback">
    <div class="fb-score"><span class="score-num"><?= (int) $f['score'] ?></span><span class="muted">/10</span>
        <p><?= e($f['summary'] ?? '') ?></p></div>
    <?php $list('Strengths', $f['strengths'] ?? [], 'fb-good'); ?>
    <?php $list('Missing points', $f['missing_points'] ?? [], 'fb-miss'); ?>
    <?php $list('Better structure', $f['better_structure'] ?? []); ?>
    <?php $list('Delivery', $f['delivery_tips'] ?? []); ?>
    <?php $list('Filler words', $f['filler_words'] ?? [], 'fb-miss'); ?>
    <?php $list('Possibly mis-heard (slow down slightly here)', $f['possible_mishears'] ?? []); ?>
    <?php if (!empty($f['improved_answer'])): ?>
        <div class="fb-block"><h3>Example improved answer</h3><p class="pre-line"><?= e($f['improved_answer']) ?></p></div>
    <?php endif; ?>
</div>
