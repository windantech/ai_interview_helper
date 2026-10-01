<?php
/** @var array $user @var array $settings @var bool $hasCv @var array $usage @var array $errors @var string $section */
$err = fn (string $f) => !empty($errors[$f]) ? '<p class="field-error" id="' . e($f) . '-error">' . e($errors[$f]) . '</p>' : '';
$inv = fn (string $f) => !empty($errors[$f]) ? 'aria-invalid="true" aria-describedby="' . e($f) . '-error"' : '';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Account</p>
        <h1>Settings</h1>
    </div>
</div>

<div class="grid grid-2 settings-grid">
    <section class="card" aria-labelledby="s-profile">
        <h2 id="s-profile">User settings</h2>
        <form method="post" class="form" novalidate>
            <?= csrf_field() ?><input type="hidden" name="action" value="profile">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" required maxlength="120" autocomplete="name"
                       value="<?= e($section === 'profile' ? ($_POST['name'] ?? '') : $user['name']) ?>" <?= $inv('name') ?>>
                <?= $err('name') ?>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required maxlength="190" autocomplete="email"
                       value="<?= e($section === 'profile' ? ($_POST['email'] ?? '') : $user['email']) ?>" <?= $inv('email') ?>>
                <?= $err('email') ?>
            </div>
            <button class="btn btn-primary" type="submit">Save profile</button>
        </form>
    </section>

    <section class="card" aria-labelledby="s-password">
        <h2 id="s-password">Change password</h2>
        <form method="post" class="form" novalidate>
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required <?= $inv('current_password') ?>>
                <?= $err('current_password') ?>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" <?= $inv('password') ?>>
                <?= $err('password') ?>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required <?= $inv('password_confirmation') ?>>
                <?= $err('password_confirmation') ?>
            </div>
            <button class="btn btn-primary" type="submit">Change password</button>
        </form>
    </section>

    <section class="card span-2" aria-labelledby="s-prefs">
        <h2 id="s-prefs">Interview preferences</h2>
        <form method="post" class="form" novalidate>
            <?= csrf_field() ?><input type="hidden" name="action" value="preferences">
            <div class="form-grid">
                <div class="field">
                    <label for="default_answer_mode">Default answer style</label>
                    <select id="default_answer_mode" name="default_answer_mode">
                        <?php foreach (options_for('answer_mode') as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= $settings['default_answer_mode'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="response_detail">Response detail</label>
                    <select id="response_detail" name="response_detail">
                        <option value="short" <?= $settings['response_detail'] === 'short' ? 'selected' : '' ?>>Short</option>
                        <option value="medium" <?= $settings['response_detail'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                    </select>
                </div>
                <div class="field">
                    <label for="default_interview_type">Default interview mode</label>
                    <select id="default_interview_type" name="default_interview_type">
                        <?php foreach (options_for('interview_type') as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= $settings['default_interview_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="transcription_mode">Transcription</label>
                    <select id="transcription_mode" name="transcription_mode">
                        <option value="auto" <?= $settings['transcription_mode'] === 'auto' ? 'selected' : '' ?>>Automatic (live, with fallback)</option>
                        <option value="live" <?= $settings['transcription_mode'] === 'live' ? 'selected' : '' ?>>Live only</option>
                        <option value="recorded" <?= $settings['transcription_mode'] === 'recorded' ? 'selected' : '' ?>>Recorded question</option>
                    </select>
                </div>
            </div>
            <div class="toggles">
                <label class="switch"><input type="checkbox" name="auto_detect_question" value="1" <?= $settings['auto_detect_question'] ? 'checked' : '' ?>><span class="switch-ui" aria-hidden="true"></span>
                    <span><strong>Auto detect question</strong><small>Ignore small talk and only answer real interview questions; stop recording automatically when the interviewer pauses.</small></span></label>
                <label class="switch"><input type="checkbox" name="show_transcript" value="1" <?= $settings['show_transcript'] ? 'checked' : '' ?>><span class="switch-ui" aria-hidden="true"></span>
                    <span><strong>Show transcript</strong><small>Display the live transcript while listening.</small></span></label>
                <label class="switch"><input type="checkbox" name="save_history" value="1" <?= $settings['save_history'] ? 'checked' : '' ?>><span class="switch-ui" aria-hidden="true"></span>
                    <span><strong>Save interview history</strong><small>Store questions and suggested answers in your history.</small></span></label>
            </div>
            <button class="btn btn-primary" type="submit">Save preferences</button>
        </form>
    </section>

    <section class="card" aria-labelledby="s-usage">
        <h2 id="s-usage">AI usage (last 30 days)</h2>
        <dl class="kv">
            <div><dt>AI requests</dt><dd><?= (int) $usage['calls'] ?></dd></div>
            <div><dt>Input tokens</dt><dd><?= number_format((int) $usage['input_tokens']) ?></dd></div>
            <div><dt>Output tokens</dt><dd><?= number_format((int) $usage['output_tokens']) ?></dd></div>
            <div><dt>Estimated cost</dt><dd>$<?= number_format((float) $usage['cost'], 4) ?></dd></div>
        </dl>
        <p class="hint">Cost estimates use the pricing configured on the server and may differ from your OpenAI bill.</p>
    </section>

    <section class="card danger-zone" aria-labelledby="s-data">
        <h2 id="s-data">Your data</h2>
        <div class="danger-row">
            <div><strong>Delete uploaded CV</strong><p class="muted small">Removes the file, extracted text and profile.</p></div>
            <form method="post" data-confirm="Delete your CV and its extracted profile?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_cv">
                <button class="btn btn-danger-outline btn-sm" type="submit" <?= $hasCv ? '' : 'disabled' ?>>Delete CV</button>
            </form>
        </div>
        <div class="danger-row">
            <div><strong>Delete all interview history</strong><p class="muted small">Removes every session, question and answer.</p></div>
            <form method="post" data-confirm="Delete ALL interview history? This cannot be undone.">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_history">
                <button class="btn btn-danger-outline btn-sm" type="submit">Delete history</button>
            </form>
        </div>
        <details class="danger-row-details" <?= $section === 'delete_account' ? 'open' : '' ?>>
            <summary><strong class="text-danger">Delete account</strong> <span class="muted small">— permanently remove your account and all data</span></summary>
            <form method="post" class="form" novalidate data-confirm="Permanently delete your account and all data?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_account">
                <div class="field">
                    <label for="confirm_password">Your password</label>
                    <input id="confirm_password" name="confirm_password" type="password" autocomplete="current-password" required <?= $inv('confirm_password') ?>>
                    <?= $err('confirm_password') ?>
                </div>
                <div class="field">
                    <label for="confirm_text">Type <strong>DELETE</strong> to confirm</label>
                    <input id="confirm_text" name="confirm_text" type="text" autocomplete="off" required <?= $inv('confirm_text') ?>>
                    <?= $err('confirm_text') ?>
                </div>
                <button class="btn btn-danger" type="submit"><?= icon('trash') ?> Delete my account</button>
            </form>
        </details>
    </section>
</div>
