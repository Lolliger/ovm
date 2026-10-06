<?php
declare(strict_types=1);

/**
 * Personal codes ("Kennungen"): every participant registers and logs in with a
 * code instead of their name. The organisers make the codes with
 * tools/kennungen-generator.html, hand them out and keep the list of who got
 * which code outside this website – names and schools are never stored here.
 *
 * data/codes.json: { "K7QFM3XP": { "added": "2026-10-06T…", "reg": "<registration id>" | null } }
 */

const CODES_FILE = DATA_DIR . '/codes.json';
/** Same alphabet as the generator: no 0/O, 1/I – easy to read and type. */
const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** " k7qf-m3xp" → "K7QFM3XP" (how codes are stored and compared). */
function normalize_code(string $code): string
{
    return strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $code));
}

/** "K7QFM3XP" → "K7QF-M3XP" (how codes are shown). */
function format_code(string $code): string
{
    $code = normalize_code($code);
    return $code === '' ? '' : implode('-', str_split($code, 4));
}

/**
 * 8 characters from CODE_CHARS with at least one letter and one digit,
 * so a name in a pasted list ("Schubert") is never mistaken for a code.
 */
function code_format_ok(string $code): bool
{
    return (bool) preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $code) && preg_match('/\d/', $code) && preg_match('/[A-Z]/', $code);
}

function codes(): array
{
    return read_json(CODES_FILE);
}

function code_exists(string $code): bool
{
    return isset(codes()[normalize_code($code)]);
}

/**
 * Adds codes from a pasted list or an uploaded file: one per line; only the
 * first column of a CSV line counts (a name in the second column is ignored).
 * Returns [added, already there, invalid].
 */
function add_codes(string $text): array
{
    $found = [];
    $invalid = 0;
    foreach (preg_split('/\R/', str_replace("\xEF\xBB\xBF", '', $text)) as $line) {
        $first = trim((string) preg_split('/[;,\t]/', $line)[0], " \t\"'");
        if ($first === '' || preg_match('/^kennung$/i', $first)) {
            continue;
        }
        $code = normalize_code($first);
        if (code_format_ok($code)) {
            $found[$code] = true;
        } else {
            $invalid++;
        }
    }
    $added = 0;
    update_json(CODES_FILE, function (array $codes) use ($found, &$added) {
        foreach (array_keys($found) as $code) {
            if (!isset($codes[$code])) {
                $codes[$code] = ['added' => date('c'), 'reg' => null];
                $added++;
            }
        }
        return $codes;
    });
    return [$added, count($found) - $added, $invalid];
}

/**
 * Marks a code as used by a registration. False if the code is unknown or
 * already used – checked and set in one locked step, so two people can never
 * register with the same code at the same moment.
 */
function claim_code(string $code, string $regId): bool
{
    $code = normalize_code($code);
    $ok = false;
    update_json(CODES_FILE, function (array $codes) use ($code, $regId, &$ok) {
        if (isset($codes[$code]) && empty($codes[$code]['reg'])) {
            $codes[$code]['reg'] = $regId;
            $ok = true;
        }
        return $codes;
    });
    return $ok;
}

/** A code for a registration made before codes existed (added as used). */
function new_code_for(string $regId): string
{
    $code = '';
    update_json(CODES_FILE, function (array $codes) use ($regId, &$code) {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= CODE_CHARS[random_int(0, strlen(CODE_CHARS) - 1)];
            }
        } while (isset($codes[$code]) || !code_format_ok($code));
        $codes[$code] = ['added' => date('c'), 'reg' => $regId];
        return $codes;
    });
    return $code;
}

/** Frees the codes of deleted registrations, so the person can register again. */
function release_unused_codes(): void
{
    if (!is_file(CODES_FILE)) {
        return;
    }
    $ids = array_flip(array_column(read_json(REGISTRATIONS_FILE), 'id'));
    update_json(CODES_FILE, function (array $codes) use ($ids) {
        foreach ($codes as &$c) {
            if (!empty($c['reg']) && !isset($ids[$c['reg']])) {
                $c['reg'] = null;
            }
        }
        return $codes;
    });
}

/** Removes codes that nobody has registered with yet (all, or the given ones). */
function delete_unused_codes(?array $only = null): int
{
    $only = $only === null ? null : array_flip(array_map('normalize_code', $only));
    $removed = 0;
    update_json(CODES_FILE, function (array $codes) use ($only, &$removed) {
        foreach ($codes as $code => $c) {
            if (empty($c['reg']) && ($only === null || isset($only[(string) $code]))) {
                unset($codes[$code]);
                $removed++;
            }
        }
        return $codes;
    });
    return $removed;
}

/** How a registration is shown: its code, or the name for old registrations without one. */
function reg_display(array $r): string
{
    if (($r['code'] ?? '') !== '') {
        return format_code($r['code']);
    }
    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    return $name !== '' ? $name . ' (ohne Kennung)' : '(ohne Kennung)';
}

/** Registrations made before the switch to codes that still contain a name or school. */
function legacy_registrations(): array
{
    return array_values(array_filter(read_json(REGISTRATIONS_FILE),
        fn ($r) => ($r['first_name'] ?? '') !== '' || ($r['last_name'] ?? '') !== '' || ($r['school'] ?? '') !== ''));
}
