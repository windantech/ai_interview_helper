<?php
/** @var array $user @var array|null $cv @var array|null $activeJob @var int $jobCount @var array $stats @var array $recent @var array $recentQuestions @var bool $apiConfigured */
$first = explode(' ', trim((string) $user['name']))[0];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Dashboard</p>
        <h1>Welcome, <?= e($first) ?></h1>
        <p class="muted">Get set up, then start an interview session when you're ready.</p>
    </div>
    <a class="btn btn-primary btn-lg hide-sm" href="<?= e(url('interview.php')) ?>"><?= icon('mic') ?> Start interview</a>
</div>

<?php if (!$apiConfigured): ?>
    <div class="alert alert-warning" role="alert"><?= icon('alert') ?>
        <span><strong>OpenAI is not configured.</strong> Add <code>OPENAI_API_KEY</code> to the server's <code>.env</code> file to enable transcription and answers.</span>
    </div>
<?php endif; ?>

<a class="start-cta card show-sm" href="<?= e(url('interview.php')) ?>">
    <span class="start-cta-icon"><?= icon('mic') ?></span>
    <span><strong>Start interview</strong><small><?= $activeJob ? e($activeJob['title']) : 'Live CV-aware answer guidance' ?></small></span>
    <?= icon('arrow-right') ?>
</a>

<div class="grid grid-2">
    <section class="card status-card" aria-labelledby="cv-status-h">
        <div class="card-head">
            <h2 id="cv-status-h"><?= icon('file') ?> CV status</h2>
            <?php if ($cv && $cv['status'] === 'ready'): ?>
                <span class="badge badge-success"><?= icon('check') ?> Ready</span>
            <?php elseif ($cv && $cv['status'] === 'failed'): ?>
                <span class="badge badge-danger">Needs attention</span>
            <?php elseif ($cv): ?>
                <span class="badge">Processing</span>
            <?php else: ?>
                <span class="badge badge-warning">Missing</span>
            <?php endif; ?>
        </div>
        <?php if ($cv): ?>
            <p class="file-line"><strong><?= e($cv['original_filename']) ?></strong><span class="muted"> · <?= e(format_bytes((int) $cv['file_size'])) ?> · <?= e(format_date($cv['created_at'], 'j M Y')) ?></span></p>
            <?php if ($cv['status'] === 'failed'): ?><p class="muted small"><?= e($cv['extraction_error']) ?></p><?php endif; ?>
            <a class="btn btn-outline btn-sm" href="<?= e(url('cv.php')) ?>">Manage CV</a>
        <?php else: ?>
            <p class="muted">Upload your CV so answers can reference your real experience.</p>
            <a class="btn btn-primary btn-sm" href="<?= e(url('cv.php')) ?>"><?= icon('upload') ?> Upload CV</a>
        <?php endif; ?>
    </section>

    <section class="card status-card" aria-labelledby="job-status-h">
        <div class="card-head">
            <h2 id="job-status-h"><?= icon('briefcase') ?> Current target job</h2>
            <?php if ($activeJob): ?><span class="badge badge-primary"><?= e(label_for('seniority', $activeJob['seniority'])) ?></span><?php endif; ?>
        </div>
        <?php if ($activeJob): ?>
            <p class="file-line"><strong><?= e($activeJob['title']) ?></strong><?php if ($activeJob['company']): ?><span class="muted"> · <?= e($activeJob['company']) ?></span><?php endif; ?></p>
            <p class="muted small"><?= e(label_for('interview_type', $activeJob['interview_type'])) ?> interview<?= $jobCount > 1 ? ' · ' . $jobCount . ' saved jobs' : '' ?></p>
            <a class="btn btn-outline btn-sm" href="<?= e(url('jobs.php')) ?>">Change job</a>
        <?php else: ?>
            <p class="muted">Add the job description so answers match what the employer is looking for.</p>
            <a class="btn btn-primary btn-sm" href="<?= e(url('jobs.php', ['new' => 1])) ?>"><?= icon('plus') ?> Add job</a>
        <?php endif; ?>
    </section>
</div>

<section class="stats" aria-label="Your activity">
    <div class="stat card"><span class="stat-value"><?= (int) $stats['sessions'] ?></span><span class="stat-label">Interview sessions</span></div>
    <div class="stat card"><span class="stat-value"><?= (int) $stats['questions'] ?></span><span class="stat-label">Questions answered</span></div>
    <div class="stat card"><span class="stat-value"><?= (int) $stats['types'] ?></span><span class="stat-label">Question types practised</span></div>
</section>

<section class="quick-actions" aria-label="Quick actions">
    <a class="qa card" href="<?= e(url('interview.php')) ?>"><?= icon('mic') ?><span>Start Interview</span></a>
    <a class="qa card" href="<?= e(url('cv.php')) ?>"><?= icon('upload') ?><span>Upload CV</span></a>
    <a class="qa card" href="<?= e(url('jobs.php', ['new' => 1])) ?>"><?= icon('plus') ?><span>Add Job</span></a>
    <a class="qa card" href="<?= e(url('history.php')) ?>"><?= icon('history') ?><span>View History</span></a>
    <a class="qa card" href="<?= e(url('practice.php')) ?>"><?= icon('target') ?><span>Practice</span></a>
</section>

<div class="grid grid-2">
    <section class="card" aria-labelledby="recent-h">
        <div class="card-head"><h2 id="recent-h">Recent interviews</h2><a class="small-link" href="<?= e(url('history.php')) ?>">View all</a></div>
        <?php if (!$recent): ?>
            <p class="empty">No interviews yet. Your sessions will appear here.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recent as $s): ?>
                    <li>
                        <a href="<?= e(url('history.php', ['id' => $s['id']])) ?>" class="list-link">
                            <span class="list-main">
                                <strong><?= e($s['title']) ?></strong>
                                <small class="muted"><?= e(format_date($s['started_at'])) ?> · <?= (int) $s['question_count'] ?> question<?= (int) $s['question_count'] === 1 ? '' : 's' ?></small>
                            </span>
                            <?php if ($s['session_type'] === 'practice'): ?><span class="badge">Practice</span><?php elseif ($s['status'] === 'active'): ?><span class="badge badge-success">Active</span><?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="activity-h">
        <div class="card-head"><h2 id="activity-h">Recent activity</h2></div>
        <?php if (!$recentQuestions): ?>
            <p class="empty">Questions you answer will show up here.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recentQuestions as $q): ?>
                    <li>
                        <a href="<?= e(url('history.php', ['id' => $q['session_id']])) ?>" class="list-link">
                            <span class="list-main">
                                <span class="clamp-2"><?= e($q['question']) ?></span>
                                <small class="muted"><?= e(format_date($q['created_at'])) ?></small>
                            </span>
                            <span class="badge"><?= e(label_for('question_type', $q['question_type'])) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($stats['type_breakdown'])): ?>
            <div class="chip-row mt">
                <?php foreach ($stats['type_breakdown'] as $t): ?>
                    <span class="chip"><?= e(label_for('question_type', $t['question_type'])) ?> · <?= (int) $t['c'] ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
