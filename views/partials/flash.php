<?php
use App\Core\Session;

$flashes = Session::pullFlashes();
$iconFor = ['success' => 'check-circle', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'];
?>
<?php if ($flashes): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>">
                <?= icon($iconFor[$f['type']] ?? 'info') ?>
                <span><?= e($f['message']) ?></span>
                <button type="button" class="alert-close" data-dismiss-alert aria-label="Dismiss"><?= icon('x') ?></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
