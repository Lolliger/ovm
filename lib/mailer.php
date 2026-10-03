<?php
declare(strict_types=1);

/** "https://omun.eu" – used for absolute links in e-mails. */
function site_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host;
}

function mail_domain(): string
{
    $host = preg_replace('/:\d+$/', '', preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
    return preg_replace('/^www\./', '', $host);
}

/** Sender address from the admin, otherwise noreply@<domain>. */
function mail_from_address(): string
{
    $a = trim((string) c('portal.mail_from'));
    return filter_var($a, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n\s]/', $a) ? $a : 'noreply@' . mail_domain();
}

/**
 * Sends a plain-text e-mail. Returns false if sending failed.
 * With the local test server (php -S) nothing is sent: the mail is written
 * to data/mail-outbox/ instead, so login links can be tried out locally.
 */
function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        return false;
    }
    $fromName = trim((string) c('portal.mail_from_name')) ?: c('site.name', 'OMUN');
    $fromAddress = mail_from_address();
    $headers = [
        'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromAddress . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $replyTo)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (PHP_SAPI === 'cli-server') {
        $dir = DATA_DIR . '/mail-outbox';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.txt';
        return (bool) file_put_contents($file, "To: $to\nSubject: $subject\n" . implode("\n", $headers) . "\n\n$body");
    }
    if (!function_exists('mail')) {
        return false;
    }
    // "-f" sets the envelope sender, so the mail server does not replace it with a default address.
    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $fromAddress);
}
