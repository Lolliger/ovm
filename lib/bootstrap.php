<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');
define('UPLOAD_DIR', ROOT . '/uploads');
define('CONTENT_FILE', DATA_DIR . '/content.json');
define('AUTH_FILE', DATA_DIR . '/auth.json');
define('REGISTRATIONS_FILE', DATA_DIR . '/registrations.json');
define('PAPERS_DIR', DATA_DIR . '/papers');
define('TOKENS_FILE', DATA_DIR . '/login-tokens.json');

// Base path, so the site also works in a subfolder (e.g. for a test install).
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if (str_ends_with($scriptDir, '/admin')) {
    $scriptDir = substr($scriptDir, 0, -6);
}
define('BASE', $scriptDir);

require __DIR__ . '/markdown.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/schema.php';

date_default_timezone_set('Europe/Berlin');

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE . '/' . ltrim($path, '/');
}

/** Public URL of an uploaded file (stored as "uploads/xyz.jpg"). */
function media(?string $path): string
{
    if (!$path) {
        return '';
    }
    if (preg_match('~^https?://~', $path)) {
        return $path;
    }
    return url($path);
}

/** Browser-tab and home-screen icons (the OMUN emblem) for every page's <head>. */
function icon_links(): string
{
    return '<link rel="icon" href="' . e(url('assets/img/favicon.png')) . '" type="image/png" sizes="64x64">' . "\n"
        . '<link rel="apple-touch-icon" href="' . e(url('assets/img/apple-touch-icon.png')) . '">' . "\n";
}

/**
 * The OMUN emblem as an <img>. $tone: 'auto' (purple, blue in dark mode – for the site's own surfaces),
 * 'purple', 'gold' (dark backgrounds) or 'blue'.
 */
function emblem(string $class, string $tone = 'auto', string $alt = ''): string
{
    $file = $tone === 'auto' ? 'purple' : $tone;
    $img = '<img class="' . e($class) . '" src="' . e(url('assets/img/emblem-' . $file . '.png')) . '" alt="' . e($alt) . '" width="400" height="340">';
    if ($tone !== 'auto') {
        return $img;
    }
    return '<picture><source srcset="' . e(url('assets/img/emblem-blue.png')) . '" media="(prefers-color-scheme: dark)">' . $img . '</picture>';
}

function slugify(string $s): string
{
    $s = strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    return trim($s, '-') ?: 'item';
}

function read_json(string $file, $default = [])
{
    if (!is_file($file)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

/** Atomic write: write to a temp file, then rename. */
function write_json(string $file, $data): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write ' . basename($file) . ' – check folder permissions of /data.');
    }
}

/**
 * Read-modify-write of a JSON file under an exclusive lock, so parallel
 * requests (e.g. a new registration while the admin deletes one) cannot
 * overwrite each other. $fn receives the current data and returns the new data.
 */
function update_json(string $file, callable $fn)
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    $lock = fopen($file . '.lock', 'c');
    if (!$lock) {
        throw new RuntimeException('Could not lock ' . basename($file) . ' – check folder permissions of /data.');
    }
    flock($lock, LOCK_EX);
    try {
        $data = $fn(read_json($file));
        write_json($file, $data);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Appends an entry to a JSON list (used for registrations). */
function append_json(string $file, array $entry): void
{
    update_json($file, function (array $list) use ($entry) {
        $list[] = $entry;
        return $list;
    });
}

/** Loads the site content; on first run it is seeded from lib/defaults.json. */
/** What a "Participation as" option means; value = label shown in the admin. */
const ROLE_KINDS = [
    'delegate' => 'Delegierte/r',
    'chair' => 'Chair (nach Bestätigung)',
    'manager' => 'Conference Manager (nach Bestätigung)',
    'staff' => 'Ohne Rechte (nur Kontaktdaten)',
];

/** Older sites stored the options as plain lines ("roles"); turn them into the new list once. */
function migrate_role_options(array $c): array
{
    $reg = $c['registration'] ?? null;
    if (!is_array($reg) || isset($reg['role_options']) || !isset($reg['roles'])) {
        return $c;
    }
    $opts = [];
    $have = [];
    foreach ((array) $reg['roles'] as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $kind = preg_match('/chair/i', $name) ? 'chair' : (preg_match('/manag/i', $name) ? 'manager' : 'delegate');
        $have[$kind] = true;
        $opts[] = ['name' => $name, 'kind' => ROLE_KINDS[$kind], 'popup' => $kind === 'chair'
            ? (string) ($reg['chair_notice'] ?? 'All chairs have already been selected. This registration is only used to record your details for the conference.') : ''];
    }
    if (empty($have['manager'])) {
        $opts[] = ['name' => 'Conference Manager', 'kind' => ROLE_KINDS['manager'],
            'popup' => 'All conference managers have already been selected. This registration is only used to record your details for the conference.'];
    }
    $c['registration']['role_options'] = $opts;
    return $c;
}

function content(): array
{
    static $c = null;
    if ($c === null) {
        if (!is_file(CONTENT_FILE)) {
            $defaults = read_json(__DIR__ . '/defaults.json');
            write_json(CONTENT_FILE, $defaults);
        }
        $c = migrate_role_options(read_json(CONTENT_FILE));
        // Fill in keys that were added to the schema later.
        $defaults ??= read_json(__DIR__ . '/defaults.json');
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $c)) {
                $c[$k] = $v;
            } elseif (is_array($v) && !array_is_list($v) && is_array($c[$k])) {
                $c[$k] += $v;
            }
        }
    }
    return $c;
}

function c(string $path, $default = '')
{
    $node = content();
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node ?? $default;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('omun_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE . '/',
        'domain' => function_exists('session_cookie_domain') ? session_cookie_domain() : '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    start_session();
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

function format_date(?string $date, string $fmt = 'j F Y'): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($fmt, $ts) : $date;
}

/** "14–16 March 2027" style range. */
function date_range(?string $start, ?string $end): string
{
    if (!$start) {
        return '';
    }
    $s = strtotime($start);
    $e = $end ? strtotime($end) : $s;
    if (!$s) {
        return (string) $start;
    }
    if (!$e || date('Y-m-d', $s) === date('Y-m-d', $e)) {
        return date('j F Y', $s);
    }
    if (date('Y-m', $s) === date('Y-m', $e)) {
        return date('j', $s) . '–' . date('j F Y', $e);
    }
    if (date('Y', $s) === date('Y', $e)) {
        return date('j F', $s) . ' – ' . date('j F Y', $e);
    }
    return date('j F Y', $s) . ' – ' . date('j F Y', $e);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Simple file-based rate limit: max $max hits per $window seconds per key. */
function rate_limited(string $key, int $max, int $window): bool
{
    $file = DATA_DIR . '/ratelimit-' . slugify($key) . '.json';
    $all = read_json($file);
    $now = time();
    $id = hash('sha256', client_ip());
    foreach ($all as $k => $hits) {
        $all[$k] = array_values(array_filter($hits, fn ($t) => $t > $now - $window));
        if (!$all[$k]) {
            unset($all[$k]);
        }
    }
    $limited = count($all[$id] ?? []) >= $max;
    if (!$limited) {
        $all[$id][] = $now;
    }
    write_json($file, $all);
    return $limited;
}
