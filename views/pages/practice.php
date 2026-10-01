<?php
/** @var array $jobs @var int|null $activeJobId @var array|null $cv @var array|null $session @var bool $consented @var int $maxAudioBytes */
$cfg = [
    'sessionId'     => $session ? (int) $session['id'] : null,
    'consented'     => $consented,
    'maxAudioBytes' => $maxAudioBytes,
];
?>
<script type="application/json" id="practice-config"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<div class="page-head">
    <div>
        <p class="eyebrow">Practice mode</p>
        <h1>Practice interview</h1>
        <p class="muted">Get realistic questions tailored to your CV and target job, answer out loud, and receive coaching feedback.</p>
    </div>
</div>

<section class="card" id="practice-setup" aria-labelledby="ps-h">
    <h2 id="ps-h">Set up</h2>
    <div class="form-grid">
        <div class="field">
            <label for="p-job">Target job</label>
            <select id="p-job">
                <option value="">No specific job</option>
                <?php foreach ($jobs as $j): ?>
                    <option value="<?= (int) $j['id'] ?>" <?= (int) $activeJobId === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['title'] . ($j['company'] ? ' — ' . $j['company'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="p-focus">Question focus</label>
            <select id="p-focus">
                <option value="">Mixed (recommended)</option>
                <?php foreach (['behavioural', 'technical', 'situational', 'leadership', 'motivation', 'career_history', 'problem_solving', 'management', 'communication', 'salary_hr'] as $t): ?>
                    <option value="<?= e($t) ?>"><?= e(label_for('question_type', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php if (!$cv): ?><p class="hint"><?= icon('info') ?> Tip: <a href="<?= e(url('cv.php')) ?>">upload your CV</a> for questions that probe your real experience.</p><?php endif; ?>
    <div class="btn-row">
        <button type="button" class="btn btn-primary btn-lg" id="p-start"><?= icon('target') ?> <?= $session ? 'Restart practice' : 'Practice Interview' ?></button>
        <?php if ($session): ?><button type="button" class="btn btn-outline btn-lg" id="p-resume"><?= icon('arrow-right') ?> Continue current session</button><?php endif; ?>
    </div>
</section>

<div id="practice-stage" hidden>
    <div class="practice-bar">
        <span class="pill"><span class="dot dot-success" aria-hidden="true"></span> Question <span id="p-num">1</span></span>
        <button type="button" class="btn btn-ghost btn-sm" id="p-end"><?= icon('stop') ?> End practice</button>
    </div>

    <section class="card question-card practice-q" aria-live="polite">
        <p class="answer-label">Interviewer asks</p>
        <p class="question-text" id="p-question"></p>
        <p class="question-meta" id="p-meta"></p>
        <p class="hint" id="p-tip"></p>
    </section>

    <section class="card" id="p-answer-card">
        <div class="practice-controls">
            <button type="button" class="btn btn-primary btn-lg" id="p-speak"><?= icon('mic') ?> Speak Answer</button>
            <button type="button" class="btn btn-outline btn-lg" id="p-type"><?= icon('keyboard') ?> Type answer</button>
            <button type="button" class="btn btn-ghost btn-lg" id="p-skip"><?= icon('skip') ?> Skip</button>
            <button type="button" class="btn btn-ghost btn-lg" id="p-hint"><?= icon('sparkles') ?> Show approach</button>
        </div>

        <div class="rec-panel" id="p-rec" hidden>
            <p class="mic-indicator"><span class="rec-dot" aria-hidden="true"></span> <span id="p-rec-status">Recording your answer…</span> <span id="p-timer">0:00</span></p>
            <div class="wave is-active" id="p-wave" aria-hidden="true"><?php for ($i = 0; $i < 7; $i++): ?><span></span><?php endfor; ?></div>
            <button type="button" class="btn btn-danger btn-lg" id="p-stop"><?= icon('stop') ?> Finish answer</button>
        </div>

        <div id="p-busy" class="progress-block" hidden role="status"><div class="spinner" aria-hidden="true"></div><p id="p-busy-text">Working…</p></div>

        <form id="p-answer-form" hidden novalidate>
            <label for="p-answer" class="answer-label">Your answer</label>
            <textarea id="p-answer" rows="6" maxlength="8000" placeholder="Type or edit your answer here…"></textarea>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary"><?= icon('sparkles') ?> Analyse my answer</button>
            </div>
        </form>

        <div id="p-error" class="alert alert-error" hidden role="alert"></div>
    </section>

    <section class="card answer-card" id="p-approach" hidden></section>
    <section class="card feedback-card" id="p-feedback" hidden></section>

    <div class="btn-row">
        <button type="button" class="btn btn-primary btn-lg" id="p-next" hidden><?= icon('arrow-right') ?> Next question</button>
    </div>
</div>

<div class="modal" id="consent-modal" hidden>
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="consent-title">
        <h2 id="consent-title"><?= icon('mic') ?> Microphone access</h2>
        <p><strong>Microphone access is used to transcribe your practice answers.</strong> Audio is captured only while the recording indicator is visible and is not stored.</p>
        <label class="check"><input type="checkbox" id="consent-check"> <span>I understand.</span></label>
        <div class="btn-row end">
            <button type="button" class="btn btn-ghost" id="consent-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="consent-ok" disabled>Allow &amp; record</button>
        </div>
    </div>
</div>
