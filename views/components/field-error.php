<?php
/** @var array $errors @var string $field */
if (!empty($errors[$field])): ?>
    <p class="field-error" id="<?= e($field) ?>-error"><?= e($errors[$field]) ?></p>
<?php endif;
