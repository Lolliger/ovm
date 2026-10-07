<?php
declare(strict_types=1);

/**
 * Updates straight from GitHub (admin button "Update von GitHub holen" and the
 * MCP endpoint in lib/mcp.php). The server downloads the branch as a zip from
 * the fixed repository below and installs it with apply_update() – the same
 * path as an uploaded update zip, including the backup beforehand.
 *
 * data/deploy.json:      branch, MCP switch, hash of the MCP key (never the key itself)
 * data/github-update.*:  the last downloaded zip + its version/commit
 * data/update-log.json:  who installed what, when (last 50 entries)
 */

require_once __DIR__ . '/updater.php';

const GITHUB_REPO = 'lolliger/ovm';
const GITHUB_DEFAULT_BRANCH = 'claude/mun-website-oskar-gymnasium-3lww8x';
const DEPLOY_FILE = DATA_DIR . '/deploy.json';
const DEPLOY_LOG_FILE = DATA_DIR . '/update-log.json';
const GITHUB_ZIP = DATA_DIR . '/github-update.zip';
const GITHUB_META = DATA_DIR . '/github-update.json';
const GITHUB_MAX_BYTES = 40 * 1024 * 1024;

function deploy_settings(): array
{
    return read_json(DEPLOY_FILE) + ['branch' => GITHUB_DEFAULT_BRANCH, 'mcp_enabled' => false, 'mcp_token_hash' => '', 'mcp_token_created' => ''];
}

function save_deploy_settings(array $changes): void
{
    update_json(DEPLOY_FILE, fn (array $s) => array_merge($s, $changes));
}

function valid_branch(string $b): bool
{
    return (bool) preg_match('~^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$~', $b) && !str_contains($b, '..') && strlen($b) <= 120;
}

function github_branch(): string
{
    $b = (string) deploy_settings()['branch'];
    return valid_branch($b) ? $b : GITHUB_DEFAULT_BRANCH;
}

function github_branch_url(): string
{
    return 'https://github.com/' . GITHUB_REPO . '/tree/' . github_branch();
}

/** Downloads $url into $dest (cURL, otherwise PHP streams). */
function http_download(string $url, string $dest): void
{
    $ua = 'omun-updater/' . current_version();
    if (function_exists('curl_init')) {
        $fh = fopen($dest, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn ($c, $dlTotal, $dl) => $dl > GITHUB_MAX_BYTES ? 1 : 0,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 90, 'user_agent' => $ua, 'follow_location' => 1, 'ignore_errors' => true]]);
        $data = @file_get_contents($url, false, $ctx, 0, GITHUB_MAX_BYTES + 1);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) { // the last status line counts (after redirects)
            if (preg_match('~^HTTP/\S+ (\d+)~', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        $ok = $data !== false && strlen($data) <= GITHUB_MAX_BYTES && file_put_contents($dest, $data) !== false;
        $err = $ok ? '' : 'Download fehlgeschlagen';
    }
    if (!$ok || $status !== 200) {
        @unlink($dest);
        if ($status === 404) {
            throw new RuntimeException('Auf GitHub gibt es den Branch „' . github_branch() . '“ nicht (oder das Repository ist privat).');
        }
        throw new RuntimeException('GitHub ist gerade nicht erreichbar' . ($status ? " (HTTP $status)" : '') . ($err ? ": $err" : '') . '.');
    }
}

/**
 * Downloads the current state of the branch. Returns
 * ['version', 'commit' (40 hex or ''), 'branch', 'fetched' (unix time)].
 */
function github_fetch(): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Der Server kann keine Zip-Dateien entpacken (PHP-Erweiterung „zip“ fehlt).');
    }
    $branch = github_branch();
    $url = 'https://codeload.github.com/' . GITHUB_REPO . '/zip/refs/heads/' . implode('/', array_map('rawurlencode', explode('/', $branch)));
    $tmp = GITHUB_ZIP . '.' . bin2hex(random_bytes(4)) . '.part';
    http_download($url, $tmp);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('GitHub hat keine gültige Zip-Datei geliefert.');
    }
    $prefix = update_root_prefix($zip);
    $version = $prefix === null ? false : $zip->getFromName($prefix . 'VERSION');
    $comment = (string) $zip->getArchiveComment(); // GitHub puts the commit hash here
    $zip->close();
    if ($prefix === null) {
        @unlink($tmp);
        throw new RuntimeException('Der Branch enthält keine OMUN-Website (index.php bzw. lib/bootstrap.php fehlen).');
    }
    rename($tmp, GITHUB_ZIP);
    $meta = [
        'version' => trim((string) $version) ?: 'unbekannt',
        'commit' => preg_match('/^[0-9a-f]{40}$/', trim($comment)) ? trim($comment) : '',
        'branch' => $branch,
        'fetched' => time(),
    ];
    write_json(GITHUB_META, $meta);
    return $meta;
}

/** The last downloaded state, if the zip is still there and younger than $maxAge seconds. */
function github_cached(int $maxAge = 3600): ?array
{
    $m = read_json(GITHUB_META);
    return $m && is_file(GITHUB_ZIP) && ($m['fetched'] ?? 0) > time() - $maxAge ? $m : null;
}

/** -1 = GitHub is older than installed, 0 = same, 1 = newer. */
function version_cmp(string $github, string $installed): int
{
    $ok = fn ($v) => (bool) preg_match('/^\d+(\.\d+)*$/', $v);
    return $ok($github) && $ok($installed) ? version_compare($github, $installed) : ($github === $installed ? 0 : 1);
}

/** Runs $fn while holding the update lock, so two updates can never run at the same time. */
function with_update_lock(callable $fn)
{
    $lock = fopen(DATA_DIR . '/update.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Es läuft gerade schon ein Update. Bitte kurz warten.');
    }
    try {
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Installs the downloaded GitHub state. With $commit the zip must be exactly
 * that commit (so the admin installs what was shown to them).
 * Returns [file count, old version, new version, meta].
 */
function github_install(?string $commit, string $source): array
{
    return with_update_lock(function () use ($commit, $source) {
        $meta = github_cached();
        if (!$meta || ($commit !== null && $meta['commit'] !== $commit)) {
            $meta = github_fetch();
            if ($commit !== null && $meta['commit'] !== $commit) {
                throw new RuntimeException('Auf GitHub gibt es inzwischen einen neueren Stand (Version ' . $meta['version'] . '). Bitte erneut prüfen und dann einspielen.');
            }
        }
        try {
            [$count, $old, $new] = apply_update(GITHUB_ZIP);
        } catch (RuntimeException $ex) {
            deploy_log($source, current_version(), $meta['version'], false, $ex->getMessage(), $meta['commit']);
            throw $ex;
        }
        deploy_log($source, $old, $new, true, "$count Dateien", $meta['commit']);
        return [$count, $old, $new, $meta];
    });
}

/** Installs one of the automatic backups in data/code-backups/. */
function restore_code_backup(string $file, string $source): array
{
    $file = basename($file);
    if (!preg_match('/^code-[\w.-]+\.zip$/', $file) || !is_file(CODE_BACKUP_DIR . '/' . $file)) {
        throw new RuntimeException('Diese Sicherung gibt es nicht.');
    }
    return with_update_lock(function () use ($file, $source) {
        // apply_update() makes a new backup and may rotate old ones, so work on a copy.
        $copy = DATA_DIR . '/restore-' . bin2hex(random_bytes(4)) . '.zip';
        copy(CODE_BACKUP_DIR . '/' . $file, $copy);
        try {
            [$count, $old, $new] = apply_update($copy);
        } catch (RuntimeException $ex) {
            deploy_log($source, current_version(), $file, false, $ex->getMessage());
            throw $ex;
        } finally {
            @unlink($copy);
        }
        deploy_log($source, $old, $new, true, "Sicherung $file wiederhergestellt");
        return [$count, $old, $new];
    });
}

const DEPLOY_SOURCES = ['upload' => 'Zip hochgeladen', 'github' => 'Admin: von GitHub', 'restore' => 'Admin: Sicherung', 'mcp' => 'Claude (MCP)', 'mcp-restore' => 'Claude (MCP): Sicherung'];

function deploy_log(string $source, string $old, string $new, bool $ok, string $msg = '', string $commit = ''): void
{
    try {
        update_json(DEPLOY_LOG_FILE, function (array $log) use ($source, $old, $new, $ok, $msg, $commit) {
            array_unshift($log, ['time' => date('c'), 'source' => $source, 'old' => $old, 'new' => $new, 'ok' => $ok, 'msg' => $msg, 'commit' => substr($commit, 0, 7)]);
            return array_slice($log, 0, 50);
        });
    } catch (Throwable $e) {
        // The log must never make an update fail.
    }
}

function deploy_log_entries(int $limit = 50): array
{
    return array_slice(read_json(DEPLOY_LOG_FILE), 0, $limit);
}

/* ---------- MCP key ---------- */

/** Creates a new MCP key, stores only its hash and returns it (shown once). */
function new_mcp_token(): string
{
    $token = 'omun_' . bin2hex(random_bytes(24));
    save_deploy_settings(['mcp_token_hash' => hash('sha256', $token), 'mcp_token_created' => date('c')]);
    return $token;
}

function check_mcp_token(string $token): bool
{
    $hash = (string) deploy_settings()['mcp_token_hash'];
    return $hash !== '' && $token !== '' && hash_equals($hash, hash('sha256', $token));
}
