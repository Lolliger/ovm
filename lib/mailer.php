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
 * Sender address. With a mailbox set up (SMTP) it is always that mailbox –
 * mail servers refuse other senders. Otherwise the address from the admin,
 * or noreply@<domain>.
 */
function mail_from_address(): string
{
    $box = (string) (smtp_settings()['user'] ?? '');
    if (filter_var($box, FILTER_VALIDATE_EMAIL)) {
        return $box;
    }
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

    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($fromAddress, '@'), 1) . '>';
    // With a mailbox set up in the admin the mail goes through the provider's
    // mail server (signed, so it is far less likely to end up in spam).
    $smtp = smtp_settings();
    if ($smtp) {
        $error = smtp_send($smtp, $fromAddress, $to, array_merge(['To: ' . $to, 'Subject: ' . $encodedSubject, 'MIME-Version: 1.0'], $headers), $body);
        if ($error === null) {
            return true;
        }
        audit_error('mail.smtp_failed', $error, 'system');
    }
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

/* ---------- Sending through a mailbox (SMTP) ---------- */

const SMTP_FILE = DATA_DIR . '/smtp.json';

/** ['host', 'port', 'user', 'pass'] or [] if no mailbox is set up (admin → Sicherheit & Backup). */
function smtp_settings(): array
{
    $s = is_file(SMTP_FILE) ? read_json(SMTP_FILE) : [];
    return !empty($s['host']) && !empty($s['user']) && !empty($s['pass']) ? $s : [];
}

/** Sends one mail via SMTP (SSL on port 465, STARTTLS otherwise). Returns null or the error. */
function smtp_send(array $smtp, string $from, string $to, array $headers, string $body): ?string
{
    $port = (int) ($smtp['port'] ?? 465);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $smtp['host'] . ':' . $port, $errno, $errstr, 15);
    if (!$fp) {
        return 'Keine Verbindung zu ' . $smtp['host'] . ':' . $port . ' (' . ($errstr ?: 'keine Antwort') . '). Servername und Port prüfen (Strato: smtp.strato.de, Port 465 – sonst 587 versuchen).';
    }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (?string $line, string $expect) use ($fp, $read): ?string {
        if ($line !== null) {
            fwrite($fp, $line . "\r\n");
        }
        $answer = $read();
        return str_starts_with($answer, $expect) ? null : (trim($answer) ?: 'keine Antwort');
    };
    $helo = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', mail_domain()) ?: 'localhost');
    $steps = [[null, '220'], [$helo, '250']];
    if ($port !== 465) {
        $steps[] = ['STARTTLS', '220'];
    }
    foreach ($steps as [$line, $expect]) {
        if ($err = $cmd($line, $expect)) {
            fclose($fp);
            return 'Mailserver: ' . $err;
        }
    }
    if ($port !== 465) {
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return 'Verschlüsselung (STARTTLS) fehlgeschlagen';
        }
        if ($err = $cmd($helo, '250')) {
            fclose($fp);
            return 'Mailserver: ' . $err;
        }
    }
    $data = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/^\./m', '..', preg_replace('/\r\n|\r|\n/', "\r\n", $body)) . "\r\n.";
    foreach ([['AUTH LOGIN', '334'], [base64_encode((string) $smtp['user']), '334'], [base64_encode((string) $smtp['pass']), '235'],
        ['MAIL FROM:<' . $from . '>', '250'], ['RCPT TO:<' . $to . '>', '25'], ['DATA', '354'], [$data, '250']] as $i => [$line, $expect]) {
        if ($err = $cmd($line, $expect)) {
            fclose($fp);
            return ($i < 3 ? 'Der Mailserver hat Benutzername oder Passwort nicht angenommen (bei Strato ist der Benutzername die vollständige E-Mail-Adresse des Postfachs, das Passwort das des Postfachs – nicht das des Kundenlogins). Antwort: '
                : ($i === 3 ? 'Der Mailserver lässt ' . $from . ' nicht als Absender zu. Antwort: ' : ($i === 4 ? 'Der Mailserver nimmt die Empfänger-Adresse ' . $to . ' nicht an. Antwort: ' : 'Mailserver: '))) . $err;
        }
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return null;
}