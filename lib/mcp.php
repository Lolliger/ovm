<?php
declare(strict_types=1);

/**
 * MCP endpoint (Model Context Protocol, "Streamable HTTP", JSON responses only)
 * so Claude can check and install updates from GitHub.
 *
 *   https://omun.eu/mcp/<key>                      (Claude.ai: custom connector)
 *   https://omun.eu/mcp  + "Authorization: Bearer <key>"   (Claude Code)
 *
 * Off by default; switched on and the key created under Admin → Update.
 * The endpoint can only install the fixed GitHub repository/branch from
 * lib/deploy.php or one of the automatic backups – never arbitrary files.
 */

require_once __DIR__ . '/deploy.php';

const MCP_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_FAIL_MAX = 10;      // wrong keys per IP …
const MCP_FAIL_WINDOW = 900;  // … per 15 minutes

function mcp_handle(?string $urlToken): void
{
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    if (empty(deploy_settings()['mcp_enabled'])) {
        mcp_http(404, 'Not found');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        mcp_http(405, 'Method not allowed');
    }
    // Browsers must not talk to this endpoint (DNS rebinding / CSRF).
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && !in_array(parse_url($origin, PHP_URL_HOST), ['claude.ai', 'www.claude.ai'], true)) {
        mcp_http(403, 'Forbidden');
    }

    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) $urlToken;
    if (mcp_failures() >= MCP_FAIL_MAX) {
        mcp_http(429, 'Too many attempts');
    }
    if (!check_mcp_token($token)) {
        mcp_failures(true);
        header('WWW-Authenticate: Bearer');
        mcp_http(401, 'Unauthorized');
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        mcp_send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
    }
    $batch = array_is_list($body) && $body !== [];
    $out = [];
    foreach ($batch ? $body : [$body] as $msg) {
        $r = is_array($msg) ? mcp_message($msg) : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request']];
        if ($r !== null) {
            $out[] = $r;
        }
    }
    if (!$out) {
        http_response_code(202); // only notifications/responses
        exit;
    }
    mcp_send($batch ? $out : $out[0]);
}

function mcp_http(int $code, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($text);
}

function mcp_send(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Counts wrong keys per IP (and records one if $add). */
function mcp_failures(bool $add = false): int
{
    $file = DATA_DIR . '/ratelimit-mcp.json';
    $id = hash('sha256', client_ip());
    $n = 0;
    update_json($file, function (array $all) use ($id, $add, &$n) {
        $now = time();
        foreach ($all as $k => $hits) {
            $all[$k] = array_values(array_filter($hits, fn ($t) => $t > $now - MCP_FAIL_WINDOW));
            if (!$all[$k]) {
                unset($all[$k]);
            }
        }
        if ($add) {
            $all[$id][] = $now;
        }
        $n = count($all[$id] ?? []);
        return $all;
    });
    return $n;
}

/** Handles one JSON-RPC message; null for notifications. */
function mcp_message(array $msg): ?array
{
    $method = (string) ($msg['method'] ?? '');
    if (!array_key_exists('id', $msg)) {
        return null; // notification (e.g. notifications/initialized) or a response
    }
    $id = $msg['id'];
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $ok = fn (array $result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];

    switch ($method) {
        case 'initialize':
            $asked = (string) ($params['protocolVersion'] ?? '');
            return $ok([
                'protocolVersion' => in_array($asked, MCP_PROTOCOLS, true) ? $asked : MCP_PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'omun-website', 'title' => 'OMUN Website', 'version' => current_version()],
                'instructions' => 'Updates für die OMUN-Website (omun.eu). Der Code liegt auf GitHub (' . GITHUB_REPO . ', Branch ' . github_branch() . '). '
                    . 'Ablauf: Änderungen committen und pushen, dann check_github aufrufen und mit install_update einspielen '
                    . '(expected_version = die gerade gepushte Versionsnummer). Inhalte, Passwörter und Anmeldungen (data/, uploads/) werden nie verändert. '
                    . 'Vor jedem Update wird automatisch gesichert; bei Problemen list_backups und restore_backup.',
            ]);
        case 'ping':
            return $ok([]);
        case 'tools/list':
            return $ok(['tools' => mcp_tools()]);
        case 'tools/call':
            $name = (string) ($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            if (!in_array($name, array_column(mcp_tools(), 'name'), true)) {
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => "Unknown tool: $name"]];
            }
            try {
                $text = mcp_call($name, $args);
                return $ok(['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]);
            } catch (Throwable $ex) {
                return $ok(['content' => [['type' => 'text', 'text' => 'Fehler: ' . $ex->getMessage()]], 'isError' => true]);
            }
        default:
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: $method"]];
    }
}

function mcp_tools(): array
{
    $none = ['type' => 'object', 'properties' => new stdClass()];
    return [
        [
            'name' => 'status',
            'title' => 'Status',
            'description' => 'Installierte Version der Website, GitHub-Branch und die letzten Updates.',
            'inputSchema' => $none,
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name' => 'check_github',
            'title' => 'Auf GitHub nach Updates suchen',
            'description' => 'Lädt den aktuellen Stand des Branches von GitHub und vergleicht die Versionsnummer (Datei VERSION) mit der installierten. Installiert nichts.',
            'inputSchema' => $none,
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => true],
        ],
        [
            'name' => 'install_update',
            'title' => 'Update von GitHub einspielen',
            'description' => 'Spielt den aktuellen Stand des GitHub-Branches auf der Live-Website ein (vorher automatische Sicherung; data/ und uploads/ bleiben unverändert). '
                . 'expected_version angeben: Ist auf GitHub eine andere Version, wird abgebrochen (schützt davor, versehentlich einen fremden Stand einzuspielen). '
                . 'Eine ältere Version als die installierte wird nur mit allow_older=true eingespielt.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'expected_version' => ['type' => 'string', 'description' => 'Versionsnummer, die eingespielt werden soll, z. B. 2026.10.07.8'],
                    'allow_older' => ['type' => 'boolean', 'description' => 'Auch eine ältere Version als die installierte einspielen', 'default' => false],
                ],
                'required' => ['expected_version'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => true],
        ],
        [
            'name' => 'list_backups',
            'title' => 'Sicherungen auflisten',
            'description' => 'Die automatischen Sicherungen der Programmdateien (je eine vor jedem Update, die letzten 5).',
            'inputSchema' => $none,
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name' => 'restore_backup',
            'title' => 'Sicherung wiederherstellen',
            'description' => 'Spielt eine Sicherung aus list_backups wieder ein (Rückgängig machen eines Updates). Inhalte und Anmeldungen bleiben unverändert.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['file' => ['type' => 'string', 'description' => 'Dateiname aus list_backups, z. B. code-20261007-101500-v2026.10.07.7.zip']],
                'required' => ['file'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
        ],
    ];
}

function mcp_call(string $name, array $args): string
{
    switch ($name) {
        case 'status':
            $lines = ['Installierte Version: ' . current_version(), 'GitHub: ' . GITHUB_REPO . ', Branch ' . github_branch()];
            $log = deploy_log_entries(5);
            if ($log) {
                $lines[] = '';
                $lines[] = 'Letzte Updates:';
                foreach ($log as $l) {
                    $lines[] = mcp_log_line($l);
                }
            }
            return implode("\n", $lines);

        case 'check_github':
            $meta = github_fetch();
            $cmp = version_cmp($meta['version'], current_version());
            return "Installiert: " . current_version() . "\nGitHub (" . $meta['branch'] . '): ' . $meta['version']
                . ($meta['commit'] ? ' (Commit ' . substr($meta['commit'], 0, 7) . ')' : '') . "\n"
                . ($cmp > 0 ? 'Auf GitHub gibt es eine neuere Version – mit install_update einspielen.'
                    : ($cmp === 0 ? 'Gleiche Versionsnummer wie installiert. (Wurde VERSION beim letzten Commit erhöht?)'
                        : 'Achtung: GitHub hat eine ÄLTERE Version als installiert.'));

        case 'install_update':
            $expected = trim((string) ($args['expected_version'] ?? ''));
            if ($expected === '') {
                throw new RuntimeException('expected_version fehlt.');
            }
            $meta = with_update_lock(fn () => github_fetch());
            if ($meta['version'] !== $expected) {
                throw new RuntimeException("Auf GitHub ist Version {$meta['version']}, erwartet war $expected. Nichts eingespielt. (Ist der Push schon durch und VERSION erhöht?)");
            }
            if (version_cmp($meta['version'], current_version()) < 0 && empty($args['allow_older'])) {
                throw new RuntimeException('Die Version auf GitHub (' . $meta['version'] . ') ist älter als die installierte (' . current_version() . '). Nichts eingespielt. Nur mit allow_older=true.');
            }
            [$count, $old, $new] = github_install($meta['commit'] ?: null, 'mcp');
            return "Update eingespielt: $old → $new ($count Dateien" . ($meta['commit'] ? ', Commit ' . substr($meta['commit'], 0, 7) : '') . ').'
                . "\nInhalte, Bilder und Anmeldungen wurden nicht verändert. Die vorherige Version liegt unter list_backups.";

        case 'list_backups':
            $b = code_backups();
            if (!$b) {
                return 'Noch keine Sicherungen (eine entsteht automatisch bei jedem Update).';
            }
            return "Sicherungen (neueste zuerst):\n" . implode("\n", array_map(fn ($x) => '- ' . $x['file'] . ' (' . date('d.m.Y H:i', $x['time']) . ', ' . round($x['size'] / 1024) . ' KB)', $b));

        case 'restore_backup':
            [$count, $old, $new] = restore_code_backup((string) ($args['file'] ?? ''), 'mcp-restore');
            return "Sicherung eingespielt: $old → $new ($count Dateien).";
    }
    throw new RuntimeException('Unbekanntes Werkzeug.');
}

function mcp_log_line(array $l): string
{
    return '- ' . date('d.m.Y H:i', strtotime($l['time'])) . ' · ' . (DEPLOY_SOURCES[$l['source']] ?? $l['source']) . ' · '
        . $l['old'] . ' → ' . $l['new'] . ($l['ok'] ? '' : ' · FEHLGESCHLAGEN: ' . $l['msg']);
}
