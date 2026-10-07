<?php
/** @var array $session @var array $questions */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('history.php')) ?>">← History</a></p>
        <h1><?= e($session['title']) ?></h1>
        <p class="muted">
            <?= e(format_date($session['started_at'])) ?>
            · <?= count($questions) ?> question<?= count($questions) === 1 ? '' : 's' ?>
            · <?= e($session['status'] === 'active' ? 'In progress' : format_duration($session['started_at'], $session['ended_at'])) ?>
            <?php if ($session['session_type'] !== 'live'): ?> · <?= e(label_for('session_type', $session['session_type'])) ?><?php endif; ?>
        </p>
    </div>
    <div class="btn-row">
        <?php if ($session['status'] === 'active' && $session['session_type'] === 'live'): ?>
            <a class="btn btn-primary" href="<?= e(url('interview.php', $session['job_id'] ? ['job' => $session['job_id']] : [])) ?>"><?= icon('mic') ?> Continue</a>
        <?php elseif ($session['status'] === 'active' && $session['session_type'] === 'scan'): ?>
            <a class="btn btn-primary" href="<?= e(url('scan.php')) ?>"><?= icon('scan') ?> Continue paper</a>
        <?php endif; ?>
        <button type="button" class="btn btn-danger-outline" data-delete-session="<?= (int) $session['id'] ?>" data-redirect="<?= e(url('history.php')) ?>"><?= icon('trash') ?> Delete session</button>
    </div>
</div>

<?php if (!empty($session['instructions'])): ?>
    <div class="card instructions-used">
        <p class="answer-label"><?= icon('edit') ?> Your instructions<?= $session['session_type'] === 'scan' ? ' for this paper' : ' for this interview' ?></p>
        <p class="pre-line"><?= e($session['instructions']) ?></p>
    </div>
<?php endif; ?>

<?php if (!$questions): ?>
    <div class="card empty-card"><p class="muted">No questions were recorded in this session.</p></div>
<?php endif; ?>

<div class="qa-list">
    <?php foreach ($questions as $i => $q): ?>
        <article class="card qa-item">
            <p class="answer-label">Question <?= e(($q['question_number'] ?? '') ?: (string) ($i + 1)) ?> · <?= e(label_for('question_type', $q['question_type'])) ?><?php if ($q['source'] !== 'typed'): ?> · <?= e(ucfirst($q['source'])) ?><?php endif; ?> · <?= e(format_date($q['created_at'], 'H:i')) ?></p>
            <h2 class="question-text"><?= e($q['question']) ?></h2>
            <?php if ($q['raw_transcript'] && $q['raw_transcript'] !== $q['question']): ?>
                <details class="small muted"><summary>Original transcript</summary><p><?= e($q['raw_transcript']) ?></p></details>
            <?php endif; ?>

            <?php if (!empty($q['answer'])): ?>
                <?php \App\Core\View::render('components/answer-card', ['answer' => $q['answer']]); ?>
            <?php endif; ?>

            <?php if ($q['user_answer']): ?>
                <div class="profile-block"><h3>Your answer</h3><p class="pre-line"><?= e($q['user_answer']) ?></p></div>
            <?php endif; ?>
            <?php if (!empty($q['feedback'])): ?>
                <?php \App\Core\View::render('components/feedback-card', ['feedback' => $q['feedback']]); ?>
            <?php endif; ?>
            <?php if (empty($q['answer']) && !$q['user_answer']): ?>
                <p class="muted small"><?= $session['session_type'] === 'scan' ? 'Not answered yet.' : 'Skipped.' ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
