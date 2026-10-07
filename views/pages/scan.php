<?php
/**
 * @var array $jobs @var array|null $job @var array|null $cv @var array $settings
 * @var array|null $session @var array $questions @var array $config @var string $instructions
 */
$cfg = [
    'sessionId'    => $session ? (int) $session['id'] : null,
    'sessionTitle' => $session ? (string) $session['title'] : '',
    'jobId'        => $job ? (int) $job['id'] : null,
    'questions'    => $questions,
    'saveHistory'  => (bool) $settings['save_history'],
    'maxPages'     => $config['maxPages'],
    'maxPageBytes' => $config['maxPageBytes'],
    'maxQuestions' => $config['maxQuestions'],
    'maxInstructions' => \App\Models\InterviewSession::MAX_INSTRUCTIONS,
    'instructions' => $instructions,
];
$modes = options_for('scan_answer_mode');
?>
<script type="application/json" id="scan-config"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<div class="page-head">
    <div>
        <p class="eyebrow">Scan</p>
        <h1>Scan a question paper</h1>
        <p class="muted">Photograph a sheet of questions — an interview question list, an application form, an essay or exam paper — and get an answer for every question on it.</p>
    </div>
    <div class="btn-row">
        <button type="button" class="btn btn-outline" id="sc-end" <?= $session ? '' : 'hidden' ?>><?= icon('stop') ?> Finish paper</button>
    </div>
</div>

<?php if (!$cv || $cv['status'] !== 'ready'): ?>
    <div class="alert alert-info" role="note"><?= icon('info') ?>
        <span><?php if (!$cv): ?>No CV uploaded — answers will be general approaches. <a href="<?= e(url('cv.php')) ?>">Upload your CV</a> for answers grounded in your real experience.<?php else: ?>Your CV hasn't been analysed yet. <a href="<?= e(url('cv.php')) ?>">Check CV</a>.<?php endif; ?></span>
    </div>
<?php endif; ?>

<!-- ============ STEP 1: capture ============ -->
<section class="card sc-capture" id="sc-capture" aria-labelledby="sc-capture-h">
    <h2 id="sc-capture-h"><span class="step-dot">1</span> Capture the pages</h2>

    <div class="form-grid">
        <div class="field">
            <label for="sc-job">Target job</label>
            <select id="sc-job">
                <option value="">No specific job</option>
                <?php foreach ($jobs as $j): ?>
                    <option value="<?= (int) $j['id'] ?>" <?= $job && (int) $job['id'] === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['title'] . ($j['company'] ? ' — ' . $j['company'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="sc-mode">Answer style</label>
            <select id="sc-mode">
                <?php foreach ($modes as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $k === 'auto' ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint" id="sc-mode-hint">Auto picks spoken talking points for interview questions and full written paragraphs for essays and forms.</p>
        </div>
    </div>

    <div class="sc-cam" id="sc-cam" hidden>
        <div class="sc-cam-frame" id="sc-cam-frame">
            <video id="sc-video" playsinline muted autoplay></video>
            <span class="sc-guide" aria-hidden="true"></span>
            <p class="sc-live-status" id="sc-live-status" hidden aria-live="polite">
                <span class="sc-live-dot" aria-hidden="true"></span><span id="sc-live-text">Starting…</span>
            </p>
        </div>
        <div class="sc-zoom" id="sc-zoom-wrap" hidden>
            <label for="sc-zoom">Zoom</label>
            <button type="button" class="sc-zoom-btn" id="sc-zoom-out" aria-label="Zoom out — fit more of the page">−</button>
            <input type="range" id="sc-zoom" min="1" max="2" step="0.1" value="1" aria-label="Camera zoom">
            <button type="button" class="sc-zoom-btn" id="sc-zoom-in" aria-label="Zoom in">+</button>
        </div>

        <p class="hint center" id="sc-cam-hint">Hold the camera straight above the page so all four corners are inside the frame.</p>

        <div class="sc-live-mode" id="sc-live-mode" role="radiogroup" aria-label="Scanning mode" hidden>
            <label class="seg">
                <input type="radio" name="sc-mode-live" value="auto" checked>
                <span>Keep scanning</span>
            </label>
            <label class="seg">
                <input type="radio" name="sc-mode-live" value="manual">
                <span>Part by part</span>
            </label>
        </div>

        <div class="btn-row center">
            <button type="button" class="btn btn-primary btn-lg" id="sc-shoot"><?= icon('camera') ?> Capture page</button>
            <button type="button" class="btn btn-primary btn-lg" id="sc-next" hidden><?= icon('scan') ?> Scan next part</button>
            <button type="button" class="btn btn-danger btn-lg" id="sc-live-stop" hidden><?= icon('stop') ?> Stop scanning</button>
            <button type="button" class="btn btn-ghost" id="sc-cam-close">Close camera</button>
        </div>
    </div>

    <div class="sc-sources" id="sc-sources">
        <button type="button" class="sc-source sc-source-main" id="sc-live-start">
            <?= icon('scan') ?>
            <span><strong>Live scan</strong><small>Hold the camera over the page and scroll — questions are read as they pass</small></span>
        </button>
        <button type="button" class="sc-source" id="sc-cam-open">
            <?= icon('camera') ?>
            <span><strong>Single photo</strong><small>Capture a page at a time</small></span>
        </button>
        <button type="button" class="sc-source" id="sc-pick">
            <?= icon('image') ?>
            <span><strong>Choose files</strong><small>Photos or a PDF of the paper</small></span>
        </button>
        <input type="file" id="sc-file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple hidden>
    </div>

    <ol class="sc-pages" id="sc-pages" hidden aria-label="Pages to scan"></ol>
    <p class="hint" id="sc-pages-hint" hidden></p>
    <div class="alert alert-error" id="sc-error" hidden role="alert"></div>

    <details class="sc-extra">
        <summary>Add context (optional)</summary>
        <div class="field">
            <label for="sc-hint">Anything the AI should know about this paper</label>
            <input type="text" id="sc-hint" maxlength="500" placeholder="e.g. This is section B — I only need to answer three questions.">
        </div>
        <div class="field">
            <label for="sc-ins">Your experience &amp; instructions for these answers</label>
            <textarea id="sc-ins" rows="3" maxlength="<?= \App\Models\InterviewSession::MAX_INSTRUCTIONS ?>"
                placeholder="e.g. I have built ML models — a churn classifier in Python and scikit-learn, trained on 2 years of billing data.&#10;When asked for a sample project, use finKAP — I built the loan module and integrated M-Pesa Paybill.&#10;Mention my PRINCE2 certification when relevant."><?= e($instructions) ?></textarea>
            <p class="hint">Anything you have actually done that your CV does not mention — tools, projects, domains — add it here and answers will use it with the same confidence as your CV. Used for every answer from this paper.</p>
        </div>
    </details>

    <div class="btn-row">
        <button type="button" class="btn btn-primary btn-lg" id="sc-scan" disabled><?= icon('scan') ?> Scan questions</button>
        <button type="button" class="btn btn-ghost" id="sc-clear" hidden>Clear pages</button>
    </div>

    <div class="progress-block" id="sc-busy" hidden role="status">
        <div class="spinner" aria-hidden="true"></div>
        <p id="sc-busy-text">Reading the page…</p>
    </div>

    <p class="hint small notice-line"><?= icon('shield') ?> Camera frames are sent to OpenAI to read the questions and are not stored. Use only where permitted.</p>
</section>

<!-- ============ STEP 2: questions + answers ============ -->
<section id="sc-result" <?= $questions ? '' : 'hidden' ?> aria-live="polite">
    <div class="card sc-doc" id="sc-doc" hidden>
        <p class="answer-label"><?= icon('list') ?> Scanned paper</p>
        <h2 id="sc-doc-title"></h2>
        <p class="muted" id="sc-doc-meta"></p>
        <p class="sc-doc-note" id="sc-doc-note" hidden></p>
    </div>

    <div class="sc-bar">
        <p class="sc-count" id="sc-count"></p>
        <div class="btn-row">
            <button type="button" class="btn btn-primary" id="sc-answer-all"><?= icon('sparkles') ?> Answer all</button>
            <button type="button" class="btn btn-outline" id="sc-stop-all" hidden><?= icon('x') ?> Stop</button>
            <button type="button" class="btn btn-ghost" id="sc-copy-all"><?= icon('copy') ?> Copy all</button>
            <button type="button" class="btn btn-ghost" id="sc-rescan"><?= icon('refresh') ?> Scan another paper</button>
        </div>
    </div>

    <ol class="sc-list" id="sc-list"></ol>
</section>
