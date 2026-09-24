<?php
declare(strict_types=1);

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

    $entry = ['id' => bin2hex(random_bytes(6)), 'created' => date('c')] + $values;
    append_json(REGISTRATIONS_FILE, $entry);
    notify_registration($entry);

    return ['ok' => true, 'errors' => [], 'values' => []];
}

function notify_registration(array $entry): void
{
    $to = (string) c('registration.notify_email');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        return;
    }
    $host = preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
    $lines = [];
    foreach (registration_fields() as $key => [$label]) {
        $lines[] = str_pad($label . ':', 26) . ($entry[$key] ?? '');
    }
    $body = "New registration on {$host}\n\n" . implode("\n", $lines)
        . "\n\nAll registrations: https://{$host}" . url('admin/?s=registrations') . "\n";
    $subject = '=?UTF-8?B?' . base64_encode('OMUN registration: ' . $entry['first_name'] . ' ' . $entry['last_name']) . '?=';
    $headers = [
        'From: OMUN <noreply@' . $host . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    // Reply-To only if the address is clean (prevents header injection).
    if (filter_var($entry['email'], FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $entry['email'])) {
        $headers[] = 'Reply-To: ' . $entry['email'];
    }
    @mail($to, $subject, $body, implode("\r\n", $headers));
}
