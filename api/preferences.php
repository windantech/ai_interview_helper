<?php

declare(strict_types=1);

/*
 * POST /api/preferences.php  JSON: any of {default_answer_mode, transcription_mode, show_transcript, mic_consent}
 * Lightweight preference updates from the interview screen.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\Response;
use App\Models\Settings;

Api::handle(function (): void {
    $user = Api::postGuard();
    $in = Api::input();
    $update = [];
    if (in_array($in['default_answer_mode'] ?? null, ['auto', 'quick', 'star', 'technical', 'leadership'], true)) {
        $update['default_answer_mode'] = $in['default_answer_mode'];
    }
    if (in_array($in['transcription_mode'] ?? null, ['auto', 'live', 'recorded'], true)) {
        $update['transcription_mode'] = $in['transcription_mode'];
    }
    if (isset($in['show_transcript'])) {
        $update['show_transcript'] = (bool) $in['show_transcript'];
    }
    if (!empty($in['mic_consent'])) {
        $update['mic_consent_at'] = gmdate('Y-m-d H:i:s');
    }
    Settings::update((int) $user['id'], $update);
    Response::success(['updated' => array_keys($update)]);
});
