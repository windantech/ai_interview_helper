<?php
/** @var array $filters @var array $jobs @var array $items @var int $total @var int $page @var int $pages */
$hasFilters = $filters['q'] !== '' || $filters['job_id'] || $filters['from'] || $filters['to'] || $filters['type'];
$qs = fn (array $extra) => url('history.php', array_filter(array_merge([
    'q' => $filters['q'], 'job_id' => $filters['job_id'], 'from' => $filters['from'], 'to' => $filters['to'], 'type' => $filters['type'],
], $extra), fn ($v) => $v !== null && $v !== ''));
?>
<div class="page-head">
    <div>
        <p class="eyebrow">History</p>
        <h1>Interview history</h1>
        <p class="muted"><?= (int) $total ?> session<?= $total === 1 ? '' : 's' ?><?= $hasFilters ? ' matching your filters' : '' ?>.</p>
    </div>
    <?php if ($total > 0 || $hasFilters): ?>
        <button type="button" class="btn btn-danger-outline" id="delete-all-history"><?= icon('trash') ?> Delete all history</button>
    <?php endif; ?>
</div>

<form class="card filters" method="get" action="<?= e(url('history.php')) ?>" role="search">
    <div class="field field-search">
        <label for="f-q">Search</label>
        <input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search questions, jobs or companies" maxlength="100">
    </div>
    <div class="field">
        <label for="f-job">Job</label>
        <select id="f-job" name="job_id">
            <option value="">All jobs</option>
            <?php foreach ($jobs as $j): ?>
                <option value="<?= (int) $j['id'] ?>" <?= (int) $filters['job_id'] === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['title'] . ($j['company'] ? ' — ' . $j['company'] : '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="f-type">Type</label>
        <select id="f-type" name="type">
            <option value="">All</option>
            <option value="live" <?= $filters['type'] === 'live' ? 'selected' : '' ?>>Interviews</option>
            <option value="practice" <?= $filters['type'] === 'practice' ? 'selected' : '' ?>>Practice</option>
            <option value="scan" <?= $filters['type'] === 'scan' ? 'selected' : '' ?>>Scanned papers</option>
        </select>
    </div>
    <div class="field">
        <label for="f-from">From</label>
        <input id="f-from" type="date" name="from" value="<?= e($filters['from']) ?>">
    </div>
    <div class="field">
        <label for="f-to">To</label>
        <input id="f-to" type="date" name="to" value="<?= e($filters['to']) ?>">
    </div>
    <div class="filters-actions">
        <button class="btn btn-primary" type="submit"><?= icon('search') ?> Filter</button>
        <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('history.php')) ?>">Clear</a><?php endif; ?>
    </div>
</form>

<?php if (!$items): ?>
    <div class="card empty-card">
        <div class="placeholder-icon"><?= icon('history') ?></div>
        <h2><?= $hasFilters ? 'No sessions match your filters' : 'No interviews yet' ?></h2>
        <p class="muted"><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Start an interview and your questions and answers will be saved here.' ?></p>
        <?php if (!$hasFilters): ?><a class="btn btn-primary" href="<?= e(url('interview.php')) ?>"><?= icon('mic') ?> Start interview</a><?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap card">
        <table class="table history-table">
            <thead>
                <tr><th scope="col">Date</th><th scope="col">Job</th><th scope="col">Company</th><th scope="col">Questions</th><th scope="col">Duration</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $s): ?>
                <tr data-session-id="<?= (int) $s['id'] ?>">
                    <td data-label="Date"><a class="strong" href="<?= e(url('history.php', ['id' => $s['id']])) ?>"><?= e(format_date($s['started_at'])) ?></a>
                        <?php if ($s['session_type'] !== 'live'): ?> <span class="badge"><?= e(label_for('session_type', $s['session_type'])) ?></span><?php endif; ?>
                        <?php if ($s['status'] === 'active'): ?> <span class="badge badge-success">Active</span><?php endif; ?>
                    </td>
                    <td data-label="Job"><?= e($s['job_title'] ?: '—') ?></td>
                    <td data-label="Company"><?= e($s['company'] ?: '—') ?></td>
                    <td data-label="Questions"><?= (int) $s['question_count'] ?></td>
                    <td data-label="Duration"><?= e($s['status'] === 'active' ? 'In progress' : format_duration($s['started_at'], $s['ended_at'])) ?></td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('history.php', ['id' => $s['id']])) ?>"><?= icon('eye') ?> Open</a>
                        <button type="button" class="btn btn-ghost btn-sm text-danger" data-delete-session="<?= (int) $s['id'] ?>" aria-label="Delete session from <?= e(format_date($s['started_at'])) ?>"><?= icon('trash') ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?><a class="btn btn-outline btn-sm" href="<?= e($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-outline btn-sm" href="<?= e($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
