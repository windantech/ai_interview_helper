<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Minimal mailer using PHP mail() (available on most cPanel hosts).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $textBody): bool
    {
        if (!config('app.mail.enabled')) {
            Logger::info('Mail disabled; message not sent', ['to_domain' => substr(strrchr($to, '@') ?: '', 1), 'subject' => $subject]);
            return false;
        }
        $fromName = preg_replace('/[\r\n"]+/', '', (string) config('app.mail.from_name'));
        $from = preg_replace('/[\r\n]+/', '', (string) config('app.mail.from'));
        $headers = [
            'From' => sprintf('"%s" <%s>', $fromName, $from),
            'Reply-To' => $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'AI-Interview-Copilot',
        ];
        $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail($to, $subject, $textBody, $headers, '-f' . $from);
        if (!$ok) {
            Logger::error('Mail sending failed', ['subject' => 'password reset']);
        }
        return $ok;
    }
}
