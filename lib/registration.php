<?php
declare(strict_types=1);

require_once __DIR__ . '/portal.php';

/** Fields of the public registration form: key => [label, required]. */
function registration_fields(): array
{
    return [
        'role' => ['Participation as', true],
        'first_name' => ['First name', true],
        'last_name' => ['Last name', true],
        'email' => ['E-mail', true],
        'school' => ['School', true],
        'grade' => ['Grade', true],
        'experience' => ['MUN experience', false],
        'committee_1' => ['Committee (1st choice)', false],
        'committee_2' => ['Committee (2nd choice)', false],
        'country_wishes' => ['Country wishes', false],
        'diet' => ['Dietary requirements', false],
        'message' => ['Message', false],
    ];
}

/**
 * Handles a POST to /register.
 * Returns ['ok' => bool, 'errors' => [...], 'values' => [...]] or null for GET.
 */
function handle_registration(): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }
    $values = [];
    foreach (registration_fields() as $key => $_) {
        $values[$key] = trim(mb_substr((string) ($_POST[$key] ?? ''), 0, 2000));
    }
    $errors = [];

    if (!c('registration.open')) {
        $errors[] = 'Registration is closed.';
    }
    // Honeypot (hidden field bots like to fill in) and a minimum fill-in time.
    $started = (int) ($_POST['t'] ?? 0);
    if (!empty($_POST['website']) || ($started && time() - $started < 3)) {
        return ['ok' => true, 'errors' => [], 'values' => []];
    }
    foreach (registration_fields() as $key => [$label, $required]) {
        if ($required && $values[$key] === '') {
            $errors[] = "Please fill in “{$label}”.";
        }
    }
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid e-mail address.';
    }
    if (empty($_POST['consent'])) {
        $errors[] = 'Please agree to the processing of your data.';
    }
    if (!$errors && rate_limited('register', 10, 3600)) {
        $errors[] = 'Too many registrations from this connection. Please try again later.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'values' => $values];
    }

    $values['email'] = normalize_email($values['email']);
    $entry = ['id' => bin2hex(random_bytes(6)), 'created' => date('c'), 'status' => 'received'] + $values;
    append_json(REGISTRATIONS_FILE, $entry);
    notify_registration($entry);
    send_confirmation($entry);

    return ['ok' => true, 'errors' => [], 'values' => []];
}

function notify_registration(array $entry): void
{
    $to = (string) c('registration.notify_email');
    if (!$to) {
        return;
    }
    $lines = [];
    foreach (registration_fields() as $key => [$label]) {
        $lines[] = str_pad($label . ':', 26) . ($entry[$key] ?? '');
    }
    $body = 'New registration on ' . mail_domain() . "\n\n" . implode("\n", $lines)
        . "\n\nAll registrations: " . site_origin() . url('admin/?s=registrations') . "\n";
    send_mail($to, c('site.name') . ' registration: ' . $entry['first_name'] . ' ' . $entry['last_name'], $body, $entry['email']);
}

/**
 * Date after which registrations for the current conference are deleted
 * (conference end + retention days), or null if auto-deletion is off.
 */
function registrations_purge_at(): ?int
{
    $days = (int) c('registration.retention_days');
    $end = c('conference.date_end') ?: c('conference.date_start');
    $ts = $end ? strtotime($end . ' 23:59:59') : false;
    return $days > 0 && $ts ? $ts + $days * 86400 : null;
}

/**
 * Deletes registrations that are past their retention period.
 * As a safety net nothing is kept longer than a year, even if the conference
 * date was moved to the next year before the old data was deleted.
 * Returns the number of deleted registrations.
 */
function purge_registrations(): int
{
    $purgeAt = registrations_purge_at();
    if ($purgeAt === null || !is_file(REGISTRATIONS_FILE)) {
        return 0;
    }
    $end = $purgeAt - (int) c('registration.retention_days') * 86400;
    $now = time();
    $removed = [];
    update_json(REGISTRATIONS_FILE, function (array $regs) use ($now, $end, $purgeAt, &$removed) {
        $keep = [];
        foreach ($regs as $r) {
            $created = strtotime($r['created'] ?? '') ?: $now;
            $expired = ($now > $purgeAt && $created <= $end) || $now - $created >= 365 * 86400;
            if ($expired) {
                $removed[] = $r;
            } else {
                $keep[] = $r;
            }
        }
        return $keep;
    });
    foreach ($removed as $r) {
        delete_paper_file($r);
    }
    if ($removed) {
        delete_orphan_accounts();
    }
    return count($removed);
}

/** Runs the purge at most once a day from public page views. */
function purge_registrations_daily(): void
{
    $marker = DATA_DIR . '/purge-check';
    if (is_file($marker) && filemtime($marker) > time() - 86400) {
        return;
    }
    @touch($marker);
    purge_registrations();
}
