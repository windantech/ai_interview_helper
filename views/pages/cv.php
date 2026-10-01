<?php
/** @var array|null $cv @var array|null $profile @var int $maxSize */
$hasCv = $cv !== null;
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Candidate profile</p>
        <h1>My CV</h1>
        <p class="muted">Your CV is stored privately and summarised into a compact profile used to personalise answers.</p>
    </div>
</div>

<div class="grid grid-cv">
    <section class="card" aria-labelledby="upload-h">
        <h2 id="upload-h"><?= $hasCv ? 'Replace CV' : 'Upload CV' ?></h2>

        <div id="cv-current" class="cv-current <?= $hasCv ? '' : 'is-hidden' ?>">
            <div class="cv-file">
                <span class="cv-file-icon"><?= icon('file') ?></span>
                <div class="cv-file-meta">
                    <p class="cv-ok" id="cv-status-line">
                        <?php if ($hasCv && $cv['status'] === 'ready'): ?>
                            <span class="text-success"><?= icon('check-circle') ?> CV uploaded successfully</span>
                        <?php elseif ($hasCv && $cv['status'] === 'failed'): ?>
                            <span class="text-danger"><?= icon('alert') ?> Uploaded, but analysis failed</span>
                        <?php else: ?>
                            <span><?= icon('clock') ?> Processing…</span>
                        <?php endif; ?>
                    </p>
                    <p class="cv-name" id="cv-name"><?= $hasCv ? e($cv['original_filename']) : '' ?></p>
                    <p class="muted small" id="cv-meta"><?= $hasCv ? e(format_bytes((int) $cv['file_size'])) . ' · Uploaded ' . e(format_date($cv['created_at'])) : '' ?></p>
                    <?php if ($hasCv && $cv['status'] === 'failed' && $cv['extraction_error']): ?>
                        <p class="field-error" id="cv-error-line"><?= e($cv['extraction_error']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="btn-row">
                <a class="btn btn-outline btn-sm" href="<?= e(url('cv-file.php')) ?>"><?= icon('download') ?> Download</a>
                <?php if ($hasCv && $cv['status'] === 'failed'): ?>
                    <button type="button" class="btn btn-outline btn-sm" id="cv-retry"><?= icon('refresh') ?> Retry analysis</button>
                <?php endif; ?>
                <button type="button" class="btn btn-danger-outline btn-sm" id="cv-delete"><?= icon('trash') ?> Delete CV</button>
            </div>
        </div>

        <form id="cv-form" class="dropzone-form" enctype="multipart/form-data" novalidate>
            <label class="dropzone" id="dropzone" for="cv-input">
                <span class="dz-icon"><?= icon('upload') ?></span>
                <span class="dz-title">Drag &amp; drop your CV here</span>
                <span class="dz-or">or</span>
                <span class="btn btn-primary btn-sm dz-btn">Choose file</span>
                <span class="dz-hint">PDF, DOC, DOCX or TXT · max <?= e(format_bytes($maxSize)) ?></span>
                <input type="file" id="cv-input" name="cv" class="visually-hidden"
                       accept=".pdf,.doc,.docx,.txt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain"
                       data-max-size="<?= (int) $maxSize ?>">
            </label>
        </form>

        <div id="cv-progress" class="progress-block is-hidden" role="status" aria-live="polite">
            <div class="spinner" aria-hidden="true"></div>
            <div>
                <p class="strong" id="cv-progress-title">Uploading…</p>
                <p class="muted small" id="cv-progress-sub">Reading your CV and building your candidate profile. This can take up to a minute.</p>
            </div>
        </div>
        <div id="cv-alert" class="alert alert-error is-hidden" role="alert"></div>

        <p class="hint mt"><?= icon('shield') ?> Files are stored outside the public web folder and are only accessible to you.
            CV content is sent to OpenAI for analysis. <a href="<?= e(url('privacy.php')) ?>">Privacy</a></p>
    </section>

    <section class="card" aria-labelledby="profile-h" id="profile-card">
        <div class="card-head">
            <h2 id="profile-h"><?= icon('eye') ?> Extracted information</h2>
        </div>
        <div id="profile-body">
            <?php if ($profile): ?>
                <?php \App\Core\View::render('components/cv-profile', ['profile' => $profile]); ?>
            <?php else: ?>
                <p class="empty"><?= $hasCv ? 'No extracted profile yet.' : 'Upload a CV to see the information we extract: skills, roles, achievements and more.' ?></p>
            <?php endif; ?>
        </div>
    </section>
</div>
