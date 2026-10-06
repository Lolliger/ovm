<?php
declare(strict_types=1);

require_once __DIR__ . '/portal.php';

/**
 * Fields of the public registration form: key => [label, required].
 * No name and no school: participants register with their personal code (lib/codes.php).
 * The password is set in the same form but only stored as a hash in the account.
 */
function registration_fields(): array
{
    return [
        'code' => ['Personal code', true],
        'role' => ['Participation as', true],
        'email' => ['E-mail', true],
        'grade' => ['Grade', true],
        'experience' => ['MUN experience', false],
        'committee_1' => ['Committee (1st choice)', false],
        'committee_2' => ['Committee (2nd choice)', false],
        'country_wishes' => ['Country wishes', false],
        'diet' => ['Dietary requirements', false],
        'message' => ['Message', false],
    ];
}

/** Options of "Participation as": [['name', 'kind' => delegate|chair|manager|staff, 'popup'], …] (set in the admin). */
function registration_role_defs(): array
{
    $defs = [];
    foreach ((array) c('registration.role_options', []) as $o) {
        $name = trim((string) ($o['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $kind = array_search((string) ($o['kind'] ?? ''), ROLE_KINDS, true);
        $defs[] = ['name' => $name, 'kind' => $kind === false ? 'delegate' : $kind, 'popup' => trim((string) ($o['popup'] ?? '')),
            'open' => !array_key_exists('open', $o) || !empty($o['open'])];
    }
    return $defs;
}

function registration_roles(): array
{
    return array_column(registration_role_defs(), 'name');
}

/** Options people can currently register as (switched on in the admin). */
function registration_open_role_defs(): array
{
    return array_values(array_filter(registration_role_defs(), fn ($d) => $d['open']));
}

/** Registration is possible: switched on overall and at least one option open. */
function registration_is_open(): bool
{
    return c('registration.open') && registration_open_role_defs();
}

/** Delegates can register (not only chairs, conference managers or staff). */
function registration_delegates_open(): bool
{
    return c('registration.open') && in_array('delegate', array_column(registration_open_role_defs(), 'kind'), true);
}

/** delegate | chair | manager | staff – for an option name (older registrations: guessed from the name). */
function registration_kind(string $role): string
{
    foreach (registration_role_defs() as $d) {
        if (strcasecmp($d['name'], trim($role)) === 0) {
            return $d['kind'];
        }
    }
    if (preg_match('/chair/i', $role)) {
        return 'chair';
    }
    return preg_match('/manag/i', $role) ? 'manager' : 'delegate';
}

/** Chairs and conference managers get their rights only after confirmation in the admin. */
function kind_needs_confirmation(string $kind): bool
{
    return in_array($kind, ['chair', 'manager'], true);
}

/** Fields only delegates fill in (hidden for chairs and conference managers). */
const DELEGATE_ONLY_FIELDS = ['grade', 'experience', 'committee_1', 'committee_2', 'country_wishes', 'diet', 'message'];

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
    $values['code'] = normalize_code($values['code']);
    $password = (string) ($_POST['password'] ?? '');
    $errors = [];
    $kind = registration_kind($values['role']);
    $chairCommittee = trim((string) ($_POST['chair_committee'] ?? ''));
    if ($kind !== 'delegate') {
        foreach (DELEGATE_ONLY_FIELDS as $key) {
            $values[$key] = '';
        }
        if ($kind === 'chair') {
            $values['committee_1'] = committee_by_label($chairCommittee) ? $chairCommittee : '';
        }
    }

    if (!registration_is_open()) {
        $errors[] = 'Registration is closed.';
    } elseif ($values['role'] !== '' && !in_array($values['role'], array_column(registration_open_role_defs(), 'name'), true)
        && in_array($values['role'], registration_roles(), true)) {
        $errors[] = 'Registration as “' . $values['role'] . '” is currently closed.';
    }
    // Honeypot (hidden field bots like to fill in) and a minimum fill-in time.
    $started = (int) ($_POST['t'] ?? 0);
    if (!empty($_POST['website']) || ($started && time() - $started < 3)) {
        return ['ok' => true, 'errors' => [], 'values' => []];
    }
    foreach (registration_fields() as $key => [$label, $required]) {
        if ($required && $values[$key] === '' && !($kind !== 'delegate' && in_array($key, DELEGATE_ONLY_FIELDS, true))) {
            $errors[] = "Please fill in “{$label}”.";
        }
    }
    if ($values['role'] !== '' && !in_array($values['role'], registration_roles(), true)) {
        $errors[] = 'Please choose how you want to participate.';
    }
    if ($kind === 'chair' && $values['committee_1'] === '' && c('committees')) {
        $errors[] = 'Please choose your committee.';
    }
    if ($values['code'] !== '') {
        // Every attempt counts (not only wrong ones), so the limit cannot be used to tell valid codes apart.
        $known = codes()[$values['code']] ?? null;
        if (rate_limited('register-code', 30, 3600)) {
            $errors[] = 'Too many attempts. Please try again later.';
        } elseif (!$known) {
            $errors[] = 'This personal code is not valid. Please check it (8 characters, e.g. K7QF-M3XP).';
        } elseif (!empty($known['reg'])) {
            $errors[] = 'This personal code has already been used to register. Please log in instead – or contact us if that was not you.';
        }
    }
    if (mb_strlen($password) < 10) {
        $errors[] = 'Please choose a password with at least 10 characters.';
    } elseif ($password !== ($_POST['password2'] ?? '')) {
        $errors[] = 'The two passwords do not match.';
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
    if (!claim_code($values['code'], $entry['id'])) {
        // Someone registered with the same code a moment ago.
        return ['ok' => false, 'errors' => ['This personal code has already been used to register. Please log in instead – or contact us if that was not you.'], 'values' => $values];
    }
    if ($kind !== 'delegate') {
        $entry['kind'] = $kind;
    }
    if (kind_needs_confirmation($kind)) {
        // Rights (chair of the chosen committee / view all committees) only
        // apply once the registration is confirmed in the admin.
        $entry['role_pending'] = true;
        if ($kind === 'chair') {
            $entry['assigned_committee'] = committee_by_label($values['committee_1'])['slug'] ?? $values['committee_1'];
        }
    }
    append_json(REGISTRATIONS_FILE, $entry);
    set_account_password($values['code'], $password);
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
        if (($entry[$key] ?? '') !== '') {
            $lines[] = str_pad($label . ':', 26) . ($key === 'code' ? format_code($entry[$key]) : $entry[$key]);
        }
    }
    $pending = !empty($entry['role_pending']) ? "\n\nPlease confirm this " . ($entry['kind'] === 'chair' ? 'chair' : 'conference manager') . ' registration in the admin area.' : '';
    $body = 'New registration on ' . mail_domain() . "\n\n" . implode("\n", $lines) . $pending
        . "\n\nAll registrations: " . site_origin() . url('admin/?s=registrations') . "\n";
    send_mail($to, c('site.name') . ' registration: ' . format_code($entry['code']), $body, $entry['email']);
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
