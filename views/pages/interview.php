<?php
/**
 * @var array $jobs @var array|null $job @var array|null $cv @var array $settings
 * @var array|null $session @var array $questions @var array $config @var string $instructions
 */
$cfg = [
    'sessionId'        => $session ? (int) $session['id'] : null,
    'jobId'            => $job ? (int) $job['id'] : null,
    'mode'             => $settings['default_answer_mode'],
    'transcriptionMode'=> $settings['transcription_mode'],
    'showTranscript'   => (bool) $settings['show_transcript'],
    'autoDetect'       => (bool) $settings['auto_detect_question'],
    'saveHistory'      => (bool) $settings['save_history'],
    'consented'        => $settings['mic_consent_at'] !== null,
    'realtimeEnabled'  => $config['realtimeEnabled'],
    'maxAudioBytes'    => $config['maxAudioBytes'],
    'maxScanPageBytes' => $config['maxScanPageBytes'],
    'instructions'     => $instructions,
    'maxInstructions'  => \App\Models\InterviewSession::MAX_INSTRUCTIONS,
    'questions'        => array_map(fn ($q) => ['id' => (int) $q['id'], 'question' => $q['question'], 'answer' => $q['answer']], $questions),
];
$modes = options_for('answer_mode');
?>
<script type="application/json" id="interview-config"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<div class="interview" id="interview">
    <header class="iv-head">
        <div class="iv-title">
            <h1>Interview Copilot</h1>
            <div class="iv-target">
                <label for="iv-job" class="iv-target-label">Target</label>
                <select id="iv-job" class="select-inline" aria-label="Target job">
                    <option value="">No specific job</option>
                    <?php foreach ($jobs as $j): ?>
                        <option value="<?= (int) $j['id'] ?>" <?= $job && (int) $job['id'] === (int) $j['id'] ? 'selected' : '' ?>>
                            <?= e($j['title'] . ($j['company'] ? ' — ' . $j['company'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="iv-head-actions">
            <span class="pill" id="session-pill" <?= $session ? '' : 'hidden' ?>><span class="dot dot-success" aria-hidden="true"></span> Session active · <span id="q-count"><?= count($questions) ?></span> Q</span>
            <button type="button" class="btn btn-outline btn-sm" id="end-session" <?= $session ? '' : 'hidden' ?>><?= icon('stop') ?> End interview</button>
        </div>
    </header>

    <?php if (!$cv || $cv['status'] !== 'ready' || !$job): ?>
        <div class="alert alert-info iv-setup" role="note"><?= icon('info') ?>
            <span>
                <?php if (!$cv): ?>No CV uploaded — answers will be general approaches. <a href="<?= e(url('cv.php')) ?>">Upload CV</a>.<?php elseif ($cv['status'] !== 'ready'): ?>Your CV hasn't been analysed yet. <a href="<?= e(url('cv.php')) ?>">Check CV</a>.<?php endif; ?>
                <?php if (!$job): ?> No target job selected. <a href="<?= e(url('jobs.php', ['new' => 1])) ?>">Add a job description</a> for tailored answers.<?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <details class="card instructions-card" id="instructions-card">
        <summary>
            <span class="ins-title"><?= icon('edit') ?> Your instructions for this interview</span>
            <span class="badge badge-primary" id="ins-badge" <?= $instructions === '' ? 'hidden' : '' ?>>On</span>
            <span class="ins-preview muted" id="ins-preview"><?= e($instructions === '' ? 'Optional — tell the AI which examples to use' : mb_substr(preg_replace('/\s+/', ' ', $instructions), 0, 90) . (mb_strlen($instructions) > 90 ? '…' : '')) ?></span>
        </summary>
        <form id="ins-form" class="ins-form" novalidate>
            <label for="ins-text" class="visually-hidden">Instructions for this interview</label>
            <textarea id="ins-text" rows="4" maxlength="<?= \App\Models\InterviewSession::MAX_INSTRUCTIONS ?>"
                placeholder="e.g. When asked for a sample project, use finKAP — I built the loan module and integrated M-Pesa Paybill.&#10;Keep salary answers open: say I'm flexible based on the full package.&#10;Mention my PRINCE2 certification when relevant."><?= e($instructions) ?></textarea>
            <div class="ins-actions">
                <p class="hint" id="ins-hint">Used for every question in this interview. Facts you add here can be used in answers.</p>
                <span class="ins-count muted small" id="ins-count"></span>
                <button type="submit" class="btn btn-primary btn-sm" id="ins-save"><?= icon('check') ?> Save instructions</button>
            </div>
        </form>
    </details>

    <div class="iv-grid">
        <!-- ============ LEFT: microphone panel ============ -->
        <section class="card listen-card" id="listen-card" aria-labelledby="listen-status" data-state="ready">
            <div class="mode-row" role="radiogroup" aria-label="Answer style">
                <?php foreach ($modes as $k => $label): ?>
                    <label class="seg">
                        <input type="radio" name="answer-mode" value="<?= e($k) ?>" <?= $settings['default_answer_mode'] === $k ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="mic-area">
                <p class="listen-status" id="listen-status" aria-live="polite">Ready</p>
                <p class="listen-sub muted" id="listen-sub">Press Listen when the interviewer starts asking a question.</p>

                <div class="mic-wrap">
                    <span class="ring ring-1" aria-hidden="true"></span>
                    <span class="ring ring-2" aria-hidden="true"></span>
                    <button type="button" class="mic-btn" id="mic-btn" aria-describedby="listen-sub">
                        <span class="mic-icon mic-icon-idle"><?= icon('mic') ?></span>
                        <span class="mic-icon mic-icon-live"><?= icon('stop') ?></span>
                        <span class="spinner spinner-light mic-icon-busy" aria-hidden="true"></span>
                        <span class="mic-label" id="mic-label">Listen</span>
                    </button>
                </div>

                <div class="wave" id="wave" aria-hidden="true">
                    <?php for ($i = 0; $i < 7; $i++): ?><span></span><?php endfor; ?>
                </div>

                <p class="mic-indicator" id="mic-indicator" hidden>
                    <span class="rec-dot" aria-hidden="true"></span> Microphone on · <span id="engine-label">Live</span>
                </p>

                <div class="transcript-box" id="transcript-box" hidden>
                    <p class="answer-label">Live transcript</p>
                    <p class="transcript-text" id="transcript-text" aria-live="off"></p>
                </div>

                <div class="error-box" id="error-box" hidden role="alert">
                    <p class="strong" id="error-title">We couldn't hear the question clearly.</p>
                    <p class="muted small" id="error-detail"></p>
                    <div class="btn-row center">
                        <button type="button" class="btn btn-primary" id="retry-btn"><?= icon('refresh') ?> Try again</button>
                        <button type="button" class="btn btn-outline" data-open-typed><?= icon('keyboard') ?> Type question instead</button>
                        <button type="button" class="btn btn-outline" data-open-scan><?= icon('scan') ?> Scan it instead</button>
                    </div>
                </div>
            </div>

            <div class="listen-actions">
                <button type="button" class="btn btn-outline btn-sm" id="done-btn" hidden><?= icon('check') ?> Question finished</button>
                <button type="button" class="btn btn-ghost btn-sm" id="cancel-btn" hidden><?= icon('x') ?> Cancel</button>
                <button type="button" class="btn btn-ghost btn-sm" data-open-typed><?= icon('keyboard') ?> Type question</button>
                <button type="button" class="btn btn-ghost btn-sm" data-open-scan><?= icon('scan') ?> Scan question</button>
            </div>

            <form class="typed-form" id="typed-form" hidden novalidate>
                <label for="typed-question" class="answer-label">Type question</label>
                <textarea id="typed-question" rows="3" maxlength="2000" placeholder="Paste or type the interview question here..."></textarea>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary"><?= icon('send') ?> Get Answer</button>
                    <button type="button" class="btn btn-ghost" id="typed-close">Close</button>
                </div>
            </form>

            <p class="hint center small notice-line"><?= icon('shield') ?> Microphone and camera access are used to capture interview questions. Use only where permitted.</p>
        </section>

        <!-- ============ RIGHT: question + answer ============ -->
        <section class="result-col" id="result-col" aria-live="polite" aria-busy="false">
            <div class="card question-card" id="question-card" hidden>
                <p class="answer-label" id="question-label">Question</p>
                <p class="question-text" id="question-text"></p>
                <p class="question-meta" id="question-meta"></p>
            </div>

            <div class="card answer-card" id="answer-card" hidden></div>

            <div class="card placeholder-card" id="placeholder-card">
                <div class="placeholder-icon"><?= icon('chat') ?></div>
                <h2>Your answer approach appears here</h2>
                <p class="muted">Concise talking points based on your CV and the job — readable in seconds.</p>
            </div>

            <div class="result-actions" id="result-actions" hidden>
                <button type="button" class="btn btn-primary btn-lg" id="listen-again"><?= icon('mic') ?> Listen Again</button>
                <button type="button" class="btn btn-outline btn-lg" id="new-question"><?= icon('plus') ?> New Question</button>
                <button type="button" class="btn btn-ghost" id="copy-answer"><?= icon('copy') ?> Copy</button>
            </div>

            <details class="card session-list" id="session-list" <?= $questions ? '' : 'hidden' ?>>
                <summary>Questions in this session (<span id="session-count"><?= count($questions) ?></span>)</summary>
                <ol id="session-questions"></ol>
            </details>
        </section>
    </div>
</div>

<!-- Scan a question into this interview -->
<div class="modal" id="scan-modal" hidden>
    <div class="modal-panel iv-scan-panel" role="dialog" aria-modal="true" aria-labelledby="iv-scan-title">
        <h2 id="iv-scan-title"><?= icon('scan') ?> Scan a question</h2>
        <p class="muted small" id="iv-scan-sub">Point the camera at the question, or choose a photo. The whole question must be inside the frame.</p>

        <div class="sc-cam" id="iv-cam" hidden>
            <div class="sc-cam-frame">
                <video id="iv-video" playsinline muted autoplay></video>
                <span class="sc-guide" aria-hidden="true"></span>
            </div>
        </div>

        <ol class="sc-pages" id="iv-pages" hidden aria-label="Captured pages"></ol>
        <div class="alert alert-error" id="iv-scan-error" hidden role="alert"></div>

        <ul class="iv-scan-picks" id="iv-scan-picks" hidden aria-label="Questions found"></ul>

        <div class="progress-block" id="iv-scan-busy" hidden role="status">
            <div class="spinner" aria-hidden="true"></div>
            <p id="iv-scan-busy-text">Reading the question…</p>
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" id="iv-shoot"><?= icon('camera') ?> Capture</button>
            <button type="button" class="btn btn-outline" id="iv-pick"><?= icon('image') ?> Choose photo</button>
            <button type="button" class="btn btn-primary" id="iv-scan-read" hidden><?= icon('scan') ?> Read question</button>
            <button type="button" class="btn btn-ghost" id="iv-scan-retake" hidden>Retake</button>
            <button type="button" class="btn btn-ghost" id="iv-scan-close">Cancel</button>
        </div>
        <input type="file" id="iv-file" accept="image/jpeg,image/png,image/webp" hidden>

        <p class="hint small"><?= icon('shield') ?> The photo is sent to OpenAI to read the question and is not stored.
            Working through a whole paper? <a href="<?= e(url('scan.php')) ?>">Use the Scan page</a>.</p>
    </div>
</div>

<!-- Consent dialog -->
<div class="modal" id="consent-modal" hidden>
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="consent-title" aria-describedby="consent-desc">
        <h2 id="consent-title"><?= icon('mic') ?> Microphone access</h2>
        <div id="consent-desc">
            <p><strong>Microphone access is used to transcribe interview questions.</strong> Audio is only captured while the
                microphone indicator is showing, and is sent to OpenAI for transcription. Recordings are not stored.</p>
            <p class="alert alert-warning"><?= icon('alert') ?><span>Use this assistant only where external assistance or transcription is permitted by the interviewer, employer, platform rules, or applicable requirements.</span></p>
        </div>
        <label class="check"><input type="checkbox" id="consent-check"> <span>I understand and will only use this where permitted.</span></label>
        <div class="btn-row end">
            <button type="button" class="btn btn-ghost" id="consent-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="consent-ok" disabled>Allow &amp; listen</button>
        </div>
    </div>
</div>
