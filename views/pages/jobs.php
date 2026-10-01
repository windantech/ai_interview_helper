<?php
/** @var array $jobs @var int|null $activeJobId @var array|null $editJob @var bool $showForm @var string $defaultType */
$j = $editJob ?? [];
$val = fn (string $k, string $d = '') => e($j[$k] ?? $d);
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Job setup</p>
        <h1>Target jobs</h1>
        <p class="muted">Save the roles you're interviewing for. The selected job is used to tailor every answer.</p>
    </div>
    <div class="btn-row">
        <button type="button" class="btn btn-outline" id="add-sample"><?= icon('sparkles') ?> Add sample job</button>
        <a class="btn btn-primary" href="<?= e(url('jobs.php', ['new' => 1])) ?>"><?= icon('plus') ?> Add job</a>
    </div>
</div>

<div id="jobs-alert" class="alert is-hidden" role="alert"></div>

<?php if ($showForm): ?>
<section class="card" aria-labelledby="job-form-h" id="job-form-card">
    <div class="card-head">
        <h2 id="job-form-h"><?= $editJob ? 'Edit job' : 'Add a target job' ?></h2>
        <?php if ($jobs): ?><a class="small-link" href="<?= e(url('jobs.php')) ?>">Cancel</a><?php endif; ?>
    </div>
    <form id="job-form" class="form" novalidate>
        <input type="hidden" name="id" value="<?= (int) ($j['id'] ?? 0) ?>">
        <div class="form-grid">
            <div class="field">
                <label for="title">Job title <span class="req" aria-hidden="true">*</span></label>
                <input id="title" name="title" type="text" required maxlength="160" value="<?= $val('title') ?>" placeholder="e.g. Senior Project Manager">
            </div>
            <div class="field">
                <label for="company">Company</label>
                <input id="company" name="company" type="text" maxlength="160" value="<?= $val('company') ?>" placeholder="e.g. ABC Company">
            </div>
            <div class="field">
                <label for="industry">Industry</label>
                <input id="industry" name="industry" type="text" maxlength="120" value="<?= $val('industry') ?>" placeholder="e.g. Financial services">
            </div>
            <div class="field">
                <label for="location">Location</label>
                <input id="location" name="location" type="text" maxlength="120" value="<?= $val('location') ?>" placeholder="e.g. Remote / London">
            </div>
            <div class="field">
                <label for="interview_type">Interview type</label>
                <select id="interview_type" name="interview_type">
                    <?php foreach (options_for('interview_type') as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= ($j['interview_type'] ?? $defaultType) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="seniority">Seniority level</label>
                <select id="seniority" name="seniority">
                    <?php foreach (options_for('seniority') as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= ($j['seniority'] ?? 'mid') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field-full">
                <label for="main_skills">Main skills required</label>
                <input id="main_skills" name="main_skills" type="text" maxlength="500" value="<?= $val('main_skills') ?>" placeholder="e.g. Stakeholder management, budgeting, Agile, SQL">
            </div>
            <div class="field field-full">
                <label for="description">Job description <span class="req" aria-hidden="true">*</span></label>
                <textarea id="description" name="description" rows="10" required maxlength="30000" placeholder="Paste the full job description here…"><?= $val('description') ?></textarea>
                <p class="hint">Include responsibilities and requirements — they're used to prioritise the most relevant experience from your CV.</p>
            </div>
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary btn-lg" id="job-save"><?= icon('check') ?> <?= $editJob ? 'Save changes' : 'Save job' ?></button>
            <?php if ($editJob): ?>
                <label class="check"><input type="checkbox" name="make_active" value="1" <?= (int) $activeJobId === (int) $editJob['id'] ? 'checked' : '' ?>> <span>Set as current target job</span></label>
            <?php endif; ?>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($jobs): ?>
<section aria-labelledby="saved-h">
    <h2 id="saved-h" class="section-title">Saved jobs (<?= count($jobs) ?>)</h2>
    <div class="job-list">
        <?php foreach ($jobs as $job): $isActive = (int) $activeJobId === (int) $job['id']; ?>
            <article class="card job-card <?= $isActive ? 'is-active' : '' ?>" data-job-id="<?= (int) $job['id'] ?>">
                <div class="job-card-main">
                    <div class="job-card-title">
                        <h3><?= e($job['title']) ?></h3>
                        <?php if ($isActive): ?><span class="badge badge-primary"><?= icon('check') ?> Current</span><?php endif; ?>
                    </div>
                    <p class="muted small">
                        <?= e(implode(' · ', array_filter([$job['company'], $job['location'], label_for('seniority', $job['seniority']), label_for('interview_type', $job['interview_type']) . ' interview']))) ?>
                    </p>
                    <p class="clamp-2 small"><?= e(mb_substr((string) $job['description'], 0, 300)) ?></p>
                    <p class="muted small"><?= (int) $job['session_count'] ?> session<?= (int) $job['session_count'] === 1 ? '' : 's' ?> · updated <?= e(format_date($job['updated_at'], 'j M Y')) ?></p>
                </div>
                <div class="job-card-actions">
                    <?php if (!$isActive): ?>
                        <button type="button" class="btn btn-outline btn-sm" data-action="select"><?= icon('target') ?> Select</button>
                    <?php endif; ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('interview.php', ['job' => $job['id']])) ?>"><?= icon('mic') ?> Interview</a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('jobs.php', ['edit' => $job['id']])) ?>" aria-label="Edit <?= e($job['title']) ?>"><?= icon('edit') ?> Edit</a>
                    <button type="button" class="btn btn-ghost btn-sm text-danger" data-action="delete" aria-label="Delete <?= e($job['title']) ?>"><?= icon('trash') ?> Delete</button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
