<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');
define('UPLOAD_DIR', ROOT . '/uploads');
define('CONTENT_FILE', DATA_DIR . '/content.json');
define('AUTH_FILE', DATA_DIR . '/auth.json');
define('REGISTRATIONS_FILE', DATA_DIR . '/registrations.json');

// Base path, so the site also works in a subfolder (e.g. for a test install).
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if (str_ends_with($scriptDir, '/admin')) {
    $scriptDir = substr($scriptDir, 0, -6);
}
define('BASE', $scriptDir);

require __DIR__ . '/markdown.php';
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

/** Append to a JSON list under an exclusive lock (used for registrations). */
function append_json(string $file, array $entry): void
{
    $fh = fopen($file, 'c+');
    if (!$fh) {
        throw new RuntimeException('Could not open ' . basename($file));
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $list = json_decode($raw ?: '[]', true) ?: [];
    $list[] = $entry;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Loads the site content; on first run it is seeded from lib/defaults.json. */
function content(): array
{
    static $c = null;
    if ($c === null) {
        if (!is_file(CONTENT_FILE)) {
            $defaults = read_json(__DIR__ . '/defaults.json');
            write_json(CONTENT_FILE, $defaults);
        }
        $c = read_json(CONTENT_FILE);
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
