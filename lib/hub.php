<?php
declare(strict_types=1);

/**
 * Hub-API: read-only JSON for the external super-admin dashboard.
 *
 *   GET /api/hub/health | stats | audit | admins | errors
 *   Header: Authorization: Bearer <HUB_TOKEN>
 *
 * The token comes from the environment variable HUB_TOKEN or is created under
 * Admin → Update → Hub-API (then only its SHA-256 hash is stored in
 * data/deploy.json). Off as long as neither exists. Never outputs password
 * hashes, keys, tokens, names, e-mail addresses or personal codes.
 * See HUB_API.md.
 */

require_once __DIR__ . '/deploy.php';

const HUB_PROJECT = 'omun';
const HUB_ENDPOINTS = ['health', 'stats', 'audit', 'admins', 'errors'];
const HUB_RATE_MAX = 30;       // requests per IP …
const HUB_RATE_WINDOW = 60;    // … per minute
const HUB_ENV_MIN_LENGTH = 32; // shorter HUB_TOKEN values are ignored

/* ---------- Token ---------- */

/** HUB_TOKEN from the server environment ('' if not set or too short). */
function hub_env_token(): string
{
    foreach ([getenv('HUB_TOKEN'), $_SERVER['HUB_TOKEN'] ?? null, $_SERVER['REDIRECT_HUB_TOKEN'] ?? null] as $v) {
        if (is_string($v) && strlen(trim($v)) >= HUB_ENV_MIN_LENGTH) {
            return trim($v);
        }
    }
    return '';
}

function hub_settings(): array
{
    return deploy_settings() + ['hub_enabled' => false, 'hub_token_hash' => '', 'hub_token_created' => ''];
}

function hub_enabled(): bool
{
    $s = hub_settings();
    return hub_env_token() !== '' || (!empty($s['hub_enabled']) && $s['hub_token_hash'] !== '');
}

/** Creates a new token, stores only its hash and returns it (shown once in the admin). */
function new_hub_token(): string
{
    $token = 'omunhub_' . bin2hex(random_bytes(32));
    save_deploy_settings(['hub_token_hash' => hash('sha256', $token), 'hub_token_created' => date('c')]);
    return $token;
}

/** Constant-time check against the environment token or the stored hash. */
function check_hub_token(string $token): bool
{
    if ($token === '') {
        return false;
    }
    $given = hash('sha256', $token);
    $ok = false;
    $env = hub_env_token();
    if ($env !== '') {
        $ok = hash_equals(hash('sha256', $env), $given) || $ok;
    }
    $s = hub_settings();
    if (!empty($s['hub_enabled']) && $s['hub_token_hash'] !== '') {
        $ok = hash_equals((string) $s['hub_token_hash'], $given) || $ok;
    }
    return $ok;
}

/* ---------- Request handling ---------- */

function hub_handle(?string $endpoint): void
{
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');

    try {
        if (!hub_enabled()) {
            hub_send(404, ['error' => 'not_found']);
        }
        if (hub_rate_limited()) {
            header('Retry-After: ' . HUB_RATE_WINDOW);
            hub_send(429, ['error' => 'too_many_requests']);
        }
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
        if (!check_hub_token($token)) {
            audit_error('hub.auth_failed', '/api/hub/' . preg_replace('/[^a-z]/', '', (string) $endpoint));
            header('WWW-Authenticate: Bearer');
            hub_send(401, ['error' => 'unauthorized']);
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET');
            hub_send(405, ['error' => 'method_not_allowed']);
        }
        if (!in_array($endpoint, HUB_ENDPOINTS, true)) {
            hub_send(404, ['error' => 'not_found']);
        }
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        hub_send(200, match ($endpoint) {
            'health' => hub_health(),
            'stats' => hub_stats(),
            'audit' => hub_audit($limit),
            'admins' => hub_admins(),
            'errors' => hub_errors($limit),
        });
    } catch (Throwable $e) {
        hub_send(500, ['error' => 'internal_error']);
    }
}

function hub_send(int $code, array $data): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/** HUB_RATE_MAX requests per IP and window, counted under a lock. */
function hub_rate_limited(): bool
{
    $id = hash('sha256', client_ip());
    $limited = false;
    update_json(DATA_DIR . '/ratelimit-hub.json', function (array $all) use ($id, &$limited) {
        $now = time();
        foreach ($all as $k => $hits) {
            $all[$k] = array_values(array_filter((array) $hits, fn ($t) => $t > $now - HUB_RATE_WINDOW));
            if (!$all[$k]) {
                unset($all[$k]);
            }
        }
        $limited = count($all[$id] ?? []) >= HUB_RATE_MAX;
        if (!$limited) {
            $all[$id][] = $now;
        }
        return $all;
    });
    return $limited;
}

/* ---------- Endpoints ---------- */

function hub_health(): array
{
    $checks = [
        'data_writable' => is_dir(DATA_DIR) && is_writable(DATA_DIR),
        'content_ok' => is_file(CONTENT_FILE) && is_array(json_decode((string) file_get_contents(CONTENT_FILE), true)),
        'admin_setup' => !empty(read_json(AUTH_FILE)['hash']), // false = anyone could set the admin password (R3)
    ];
    return [
        'project' => HUB_PROJECT,
        'version' => current_version(),
        'status' => in_array(false, $checks, true) ? 'degraded' : 'ok',
        'time' => date('c'),
        'checks' => $checks,
    ];
}

function hub_stats(): array
{
    $regs = read_json(REGISTRATIONS_FILE);
    $byStatus = array_fill_keys(array_keys(registration_statuses()), 0);
    $last = '';
    $papers = $pending = $chairs = $managers = $partAdmins = 0;
    foreach ($regs as $r) {
        $st = (string) ($r['status'] ?? 'received');
        $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        $papers += !empty($r['paper']['file']) ? 1 : 0;
        $pending += !empty($r['role_pending']) ? 1 : 0;
        $chairs += !empty($r['is_chair']) ? 1 : 0;
        $managers += !empty($r['is_manager']) ? 1 : 0;
        $partAdmins += !empty($r['is_admin']) ? 1 : 0;
        $last = max($last, (string) ($r['created'] ?? ''));
    }

    $codes = codes();
    $usedCodes = count(array_filter($codes, fn ($c) => !empty($c['reg'])));

    $res = ['draft' => 0, 'debate' => 0, 'closed' => 0];
    $finished = 0;
    foreach (glob(RES_DIR . '/*.json') ?: [] as $f) {
        if (str_contains(basename($f), '--')) {
            $finished++;
            continue;
        }
        $st = (string) (read_json($f)['status'] ?? 'draft');
        $res[$st] = ($res[$st] ?? 0) + 1;
    }

    $uploads = 0;
    $uploadBytes = 0;
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $f) {
        if (is_file($f) && !str_starts_with(basename($f), '.')) {
            $uploads++;
            $uploadBytes += (int) filesize($f);
        }
    }

    $week = stats_summary(7);
    $month = stats_summary(30);
    $news = c('news', []);

    return [
        'project' => HUB_PROJECT,
        'version' => current_version(),
        'time' => date('c'),
        'registrations' => [
            'total' => count($regs),
            'by_status' => $byStatus,
            'role_pending' => $pending,
            'chairs' => $chairs,
            'conference_managers' => $managers,
            'participant_admins' => $partAdmins,
            'papers_uploaded' => $papers,
            'last_registration' => $last !== '' ? $last : null,
            'open' => (bool) c('registration.open', false),
        ],
        'codes' => ['total' => count($codes), 'used' => $usedCodes, 'free' => count($codes) - $usedCodes],
        'portal_accounts' => count(read_json(ACCOUNTS_FILE)),
        'resolutions' => ['current_by_status' => $res, 'finished' => $finished],
        'content' => [
            'committees' => count(c('committees', [])),
            'news_total' => count($news),
            'news_published' => count(array_filter($news, fn ($n) => !empty($n['published']))),
            'team' => count(c('team', [])),
            'pages' => count(c('pages', [])),
            'content_versions' => count(glob(DATA_DIR . '/history/*.json') ?: []),
        ],
        'uploads' => ['files' => $uploads, 'bytes' => $uploadBytes],
        'visitors' => [
            'last_7_days' => ['views' => $week['views'], 'visitors' => $week['visitors']],
            'last_30_days' => ['views' => $month['views'], 'visitors' => $month['visitors']],
        ],
    ];
}

/** Audit log merged with the update log (data/update-log.json), newest first. */
function hub_audit(int $limit): array
{
    $events = audit_entries(AUDIT_FILE, $limit);
    foreach (deploy_log_entries($limit) as $l) {
        $events[] = [
            'time' => (string) $l['time'],
            'actor' => str_starts_with((string) $l['source'], 'mcp') ? 'mcp' : 'admin',
            'action' => $l['ok'] ? 'update.install' : 'update.failed',
            'target' => $l['old'] . ' → ' . $l['new'] . ' (' . (DEPLOY_SOURCES[$l['source']] ?? $l['source']) . ')',
        ];
    }
    return ['events' => hub_sort_limit($events, $limit)];
}

function hub_admins(): array
{
    $auth = read_json(AUTH_FILE);
    $lastLogin = null;
    $lastActivity = null;
    foreach (read_json(AUDIT_FILE) as $e) {
        if (($e['actor'] ?? '') !== 'admin') {
            continue;
        }
        $lastActivity ??= $e['time'];
        if (($e['action'] ?? '') === 'admin.login') {
            $lastLogin = $e['time'];
            break;
        }
    }
    $staff = [];
    $stored = read_json(STAFF_FILE);
    foreach (staff_accounts() as $name => $a) {
        $staff[] = [
            'name' => (string) $name,
            'label' => (string) ($a['label'] ?? $name),
            'role' => 'viewer',
            'password_changed' => $stored[$name]['changed'] ?? null,
            'default_password' => !isset($stored[$name]),
        ];
    }
    $deploy = hub_settings();
    return [
        'admins' => [[
            'name' => 'admin',
            'role' => 'admin',
            'note' => 'Single shared admin password (no individual admin users).',
            'password_changed' => $auth['changed'] ?? null,
            'two_factor' => [
                'enabled' => !empty($auth['totp']),
                'devices' => array_map(fn ($d) => ['name' => (string) ($d['name'] ?? ''), 'created' => $d['created'] ?? null], $auth['totp'] ?? []),
                'recovery_codes_left' => count($auth['recovery'] ?? []),
            ],
            'last_login' => $lastLogin,
            'last_activity' => $lastActivity,
        ]],
        'staff_accounts' => $staff,
        'integrations' => [
            'mcp_enabled' => !empty($deploy['mcp_enabled']),
            'mcp_key_created' => $deploy['mcp_token_created'] ?: null,
            'hub_token_created' => $deploy['hub_token_created'] ?: null,
            'hub_token_from_env' => hub_env_token() !== '',
        ],
    ];
}

/** Failed logins, rejected keys and failed updates, newest first. */
function hub_errors(int $limit): array
{
    $events = audit_entries(ERROR_LOG_FILE, $limit);
    foreach (deploy_log_entries(50) as $l) {
        if (!$l['ok']) {
            $events[] = [
                'time' => (string) $l['time'],
                'actor' => str_starts_with((string) $l['source'], 'mcp') ? 'mcp' : 'admin',
                'action' => 'update.failed',
                'target' => mb_substr($l['old'] . ' → ' . $l['new'] . ': ' . $l['msg'], 0, 160),
            ];
        }
    }
    return ['errors' => hub_sort_limit($events, $limit)];
}

function hub_sort_limit(array $events, int $limit): array
{
    usort($events, fn ($a, $b) => strtotime((string) $b['time']) <=> strtotime((string) $a['time']));
    return array_slice($events, 0, $limit);
}
