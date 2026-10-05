<?php
declare(strict_types=1);

/**
 * Visitor statistics without cookies and without storing IP addresses.
 *
 * Every public page view is counted in data/stats/YYYY-MM.json, per day:
 *   v = page views, u = visitors, m = views from phones, p = views per page, r = views per referring site.
 * A visitor is recognised for one day only, by a hash of IP + browser + a random
 * salt that is replaced every day (and never stored with the data). Afterwards
 * the hashes are thrown away, so nobody can be followed across days.
 */

const STATS_DIR = DATA_DIR . '/stats';
const STATS_KEEP_MONTHS = 13;

function stats_is_bot(string $ua): bool
{
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|preview|fetch|curl|wget|python|java\/|go-http|headless|lighthouse|monitor|uptime|scan|facebookexternalhit|whatsapp|telegram|discord|semrush|ahrefs/i', $ua);
}

/** Counts a view of a public page. Admins and bots are not counted. */
function stats_track(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || PHP_SAPI === 'cli') {
        return;
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $purpose = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
    if (stats_is_bot($ua) || str_contains($purpose, 'prefetch')) {
        return;
    }
    // Only look at the session if there is one (a visitor never gets a cookie from this).
    if (!empty($_COOKIE['omun_sid']) && function_exists('is_logged_in') && is_logged_in()) {
        return;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (BASE !== '' && str_starts_with($path, BASE)) {
        $path = substr($path, strlen(BASE));
    }
    $path = '/' . trim(mb_substr(rawurldecode($path), 0, 120), '/');

    $ref = '';
    $host = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        $host = preg_replace('/^www\./', '', strtolower($host));
        $own = preg_replace('/^www\./', '', strip_port(request_host()));
        if ($host !== $own && !str_ends_with($host, '.' . $own)) {
            $ref = mb_substr($host, 0, 80);
        }
    }
    $mobile = (bool) preg_match('/Mobi|Android|iPhone|iPad/i', $ua);
    $visitor = substr(hash('sha256', stats_salt() . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua), 0, 16);

    if (!is_dir(STATS_DIR)) {
        @mkdir(STATS_DIR, 0755, true);
    }
    $day = date('d');
    $today = date('Y-m-d');
    try {
        update_json(STATS_DIR . '/' . date('Y-m') . '.json', function (array $s) use ($day, $today, $path, $ref, $mobile, $visitor) {
            $d = $s['days'][$day] ?? ['v' => 0, 'u' => 0, 'm' => 0, 'p' => [], 'r' => []];
            $d['v']++;
            $d['m'] += $mobile ? 1 : 0;
            $d['p'][$path] = ($d['p'][$path] ?? 0) + 1;
            if ($ref !== '') {
                $d['r'][$ref] = ($d['r'][$ref] ?? 0) + 1;
            }
            if (($s['seen']['date'] ?? '') !== $today) {
                $s['seen'] = ['date' => $today, 'h' => []];
            }
            if (!isset($s['seen']['h'][$visitor])) {
                $s['seen']['h'][$visitor] = 1;
                $d['u']++;
            }
            $s['days'][$day] = $d;
            return $s;
        });
    } catch (Throwable $e) {
        // Statistics must never break the website.
    }
    stats_cleanup_daily();
}

/** Random value that changes every day; yesterday's is discarded. */
function stats_salt(): string
{
    $file = STATS_DIR . '/salt.json';
    $s = is_file($file) ? read_json($file) : [];
    if (($s['date'] ?? '') !== date('Y-m-d') || empty($s['salt'])) {
        $s = ['date' => date('Y-m-d'), 'salt' => bin2hex(random_bytes(16))];
        if (!is_dir(STATS_DIR)) {
            @mkdir(STATS_DIR, 0755, true);
        }
        @write_json($file, $s);
    }
    return $s['salt'];
}

/** Deletes months older than STATS_KEEP_MONTHS and yesterday's visitor hashes (once a day). */
function stats_cleanup_daily(): void
{
    $marker = STATS_DIR . '/cleanup';
    if (is_file($marker) && date('Y-m-d', (int) filemtime($marker)) === date('Y-m-d')) {
        return;
    }
    @touch($marker);
    $oldest = date('Y-m', strtotime('first day of -' . (STATS_KEEP_MONTHS - 1) . ' months'));
    foreach (glob(STATS_DIR . '/[0-9][0-9][0-9][0-9]-[0-9][0-9].json') ?: [] as $f) {
        $month = basename($f, '.json');
        if ($month < $oldest) {
            @unlink($f);
            @unlink($f . '.lock');
        } elseif ($month < date('Y-m')) {
            $s = read_json($f);
            if (isset($s['seen'])) {
                update_json($f, function (array $s) { unset($s['seen']); return $s; });
            }
        }
    }
}

/**
 * Totals for the last $days days (including today):
 * ['days' => ['Y-m-d' => ['v','u','m']], 'views', 'visitors', 'mobile', 'pages' => [path => n], 'refs' => [host => n]]
 */
function stats_summary(int $days): array
{
    $out = ['days' => [], 'views' => 0, 'visitors' => 0, 'mobile' => 0, 'pages' => [], 'refs' => []];
    $months = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts = strtotime("-$i days");
        $ym = date('Y-m', $ts);
        $months[$ym] ??= read_json(STATS_DIR . '/' . $ym . '.json');
        $d = $months[$ym]['days'][date('d', $ts)] ?? null;
        $out['days'][date('Y-m-d', $ts)] = ['v' => (int) ($d['v'] ?? 0), 'u' => (int) ($d['u'] ?? 0)];
        if (!$d) {
            continue;
        }
        $out['views'] += (int) $d['v'];
        $out['visitors'] += (int) $d['u'];
        $out['mobile'] += (int) ($d['m'] ?? 0);
        foreach ($d['p'] ?? [] as $k => $n) {
            $out['pages'][$k] = ($out['pages'][$k] ?? 0) + $n;
        }
        foreach ($d['r'] ?? [] as $k => $n) {
            $out['refs'][$k] = ($out['refs'][$k] ?? 0) + $n;
        }
    }
    arsort($out['pages']);
    arsort($out['refs']);
    return $out;
}
