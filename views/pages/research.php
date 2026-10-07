<?php
/** @var array $rows */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Research</p>
        <h1>Detection signals</h1>
        <p class="muted">How visible assistance is in your own recorded sessions, scored on signals an interviewer or hiring platform could plausibly observe.</p>
    </div>
    <?php if ($rows): ?>
        <a class="btn btn-outline" href="<?= e(url('api/research-export.php')) ?>"><?= icon('download') ?> Export CSV</a>
    <?php endif; ?>
</div>

<div class="alert alert-warning" role="note"><?= icon('alert') ?>
    <span><strong>These are heuristics, not proof.</strong> Every signal has an innocent explanation: a fast answer may be a rehearsed one,
    uniform structure may be a candidate taught to use STAR, and job-description vocabulary is what a well-prepared candidate uses on purpose.
    The score is an instrument for comparing populations in a study. It is not fit to judge an individual, and should never be used to accuse one.</span>
</div>

<?php if (!$rows): ?>
    <div class="card empty-card">
        <div class="placeholder-icon"><?= icon('search') ?></div>
        <h2>No sessions to analyse yet</h2>
        <p class="muted">Run an interview or a practice session and its signals will appear here.</p>
        <a class="btn btn-primary" href="<?= e(url('interview.php')) ?>"><?= icon('mic') ?> Start interview</a>
    </div>
<?php else: ?>
    <div class="rs-list">
        <?php foreach ($rows as $row): ?>
            <?php $s = $row['session']; $a = $row['analysis']; $band = $a['score'] >= 60 ? 'high' : ($a['score'] >= 30 ? 'mid' : 'low'); ?>
            <article class="card rs-item">
                <div class="rs-head">
                    <div class="rs-title">
                        <a class="strong" href="<?= e(url('history.php', ['id' => $s['id']])) ?>"><?= e($s['title']) ?></a>
                        <p class="muted small">
                            <?= e(format_date($s['started_at'])) ?>
                            · <?= (int) $a['summary']['answered'] ?> of <?= (int) $a['summary']['questions'] ?> answered
                            · <?= e(label_for('session_type', $s['session_type'])) ?>
                        </p>
                    </div>
                    <div class="rs-score" data-band="<?= e($band) ?>">
                        <span class="rs-score-num"><?= (int) $a['score'] ?></span>
                        <span class="rs-score-label"><?= e($a['confidence']) ?> confidence</span>
                    </div>
                </div>

                <?php if ($a['confidence'] === 'insufficient'): ?>
                    <p class="hint"><?= icon('info') ?> Too few answered questions to measure anything meaningful. Treat the score as noise.</p>
                <?php endif; ?>

                <ul class="rs-signals">
                    <?php foreach ($a['signals'] as $sig): ?>
                        <li class="rs-signal<?= $sig['measured'] ? '' : ' is-unmeasured' ?><?= $sig['flag'] ? ' is-flagged' : '' ?>">
                            <span class="rs-signal-label"><?= e($sig['label']) ?></span>
                            <span class="rs-bar" aria-hidden="true"><span style="width: <?= $sig['measured'] ? round($sig['level'] * 100) : 0 ?>%"></span></span>
                            <span class="rs-signal-detail muted small"><?= e($sig['detail']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
