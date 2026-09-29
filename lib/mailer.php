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
    $from = c('site.name', 'OMUN');
    $headers = [
        'From: =?UTF-8?B?' . base64_encode($from) . '?= <noreply@' . mail_domain() . '>',
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
    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}
