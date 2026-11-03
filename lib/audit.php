<?php
declare(strict_types=1);

/**
 * Audit log for the Hub-API (lib/hub.php): who changed what, and what failed.
 *
 *   data/audit-log.json  changes and events  (newest first, max. AUDIT_KEEP)
 *   data/error-log.json  failed logins etc.  (newest first, max. AUDIT_KEEP)
 *
 * An entry is { time, actor, action, target }. Never put passwords, codes,
 * tokens, names, e-mail addresses or IP addresses into it.
 * Logging must never break the website, so every error is swallowed.
 */

const AUDIT_FILE = DATA_DIR . '/audit-log.json';
const ERROR_LOG_FILE = DATA_DIR . '/error-log.json';
const AUDIT_KEEP = 500;

/** Records a change/event. $actor: 'admin', 'portal', 'mcp', 'system' … */
function audit(string $action, string $target = '', string $actor = 'admin'): void
{
    audit_write(AUDIT_FILE, $action, $target, $actor);
}

/** Records a failure (failed login, rejected key …). */
function audit_error(string $action, string $target = '', string $actor = 'anonymous'): void
{
    audit_write(ERROR_LOG_FILE, $action, $target, $actor);
}

function audit_write(string $file, string $action, string $target, string $actor): void
{
    $entry = [
        'time' => date('c'),
        'actor' => mb_substr($actor, 0, 40),
        'action' => mb_substr($action, 0, 60),
        'target' => mb_substr($target, 0, 160),
    ];
    try {
        update_json($file, function (array $log) use ($entry) {
            array_unshift($log, $entry);
            return array_slice($log, 0, AUDIT_KEEP);
        });
    } catch (Throwable $e) {
        // never break the request because of the log
    }
}

function audit_entries(string $file, int $limit): array
{
    return array_slice(read_json($file), 0, $limit);
}
