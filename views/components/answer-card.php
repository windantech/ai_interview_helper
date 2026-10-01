<?php
/** Server-side renderer for stored answer JSON (mirrors assets/js/answer-render.js). @var array $answer */
$a = $answer;
// Escape text, then bold job keywords and highlight [fill-in] gaps (mirrors answer-render.js).
$kw = array_values(array_filter($a['keywords'] ?? [], fn ($k) => is_string($k) && mb_strlen($k) > 2));
$pattern = '/(\[[^\]]{1,60}\]' . ($kw ? '|\b(?:' . implode('|', array_map(fn ($k) => preg_quote($k, '/'), $kw)) . ')\b' : '') . ')/iu';
$rich = function (string $text) use ($pattern): string {
    $out = '';
    foreach (preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text] as $i => $part) {
        $out .= $i % 2 === 0 ? e($part)
            : (str_starts_with($part, '[') ? '<mark class="fill-in">' . e($part) . '</mark>' : '<strong>' . e($part) . '</strong>');
    }
    return $out;
};
?>
<div class="answer">
    <?php if (!empty($a['key_message'])): ?>
        <div class="key-message"><p class="answer-label">Main point</p><p><?= e($a['key_message']) ?></p></div>
    <?php endif; ?>

    <?php if (!empty($a['sections'])): ?>
        <div class="answer-sections">
            <?php foreach ($a['sections'] as $sec): ?>
                <div class="answer-section">
                    <h3><?= e($sec['label']) ?></h3>
                    <ul><?php foreach ($sec['bullets'] as $b): ?><li><?= $rich((string) $b) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (!empty($a['points'])): ?>
        <ul class="answer-points"><?php foreach ($a['points'] as $p): ?><li><?= $rich((string) $p) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if (!empty($a['cv_evidence'])): ?>
        <div class="evidence"><p class="answer-label"><?= icon('file') ?> From your CV</p>
            <ul><?php foreach ($a['cv_evidence'] as $ev): ?><li><?= e($ev) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if (!empty($a['evidence_note'])): ?>
        <p class="evidence-note"><?= icon('info') ?> <?= e($a['evidence_note']) ?></p>
    <?php endif; ?>

    <?php if (!empty($a['closing_line'])): ?>
        <div class="closing"><p class="answer-label">Close with</p><p>“<?= $rich((string) $a['closing_line']) ?>”</p></div>
    <?php endif; ?>

    <?php if (!empty($a['keywords'])): ?>
        <div class="keywords"><p class="answer-label">Keywords</p>
            <div class="chip-row"><?php foreach ($a['keywords'] as $k): ?><span class="chip chip-key"><?= e($k) ?></span><?php endforeach; ?></div>
        </div>
    <?php endif; ?>
</div>
