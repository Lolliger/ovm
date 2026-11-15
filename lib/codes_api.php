<?php
declare(strict_types=1);

/**
 * Connection for the Kennungen-Generator (tools/): codes created in the
 * generator are added to data/codes.json right away, so nobody has to upload
 * a file in the admin.
 *
 *   POST /api/codes        {"codes": ["K7QF-M3XP", …]}  → {"added": n, "known": n, "invalid": n, "total": n, "free": n}
 *   GET  /api/codes        → {"ok": true, "total": n, "free": n}   (to test the key)
 *   Header: Authorization: Bearer <key>
 *
 * The key is created in the admin under Kennungen and only its hash is stored
 * (data/generator.json). Only codes go over the line – never names.
 */

const GENERATOR_FILE = DATA_DIR . '/generator.json';

function generator_key_set(): bool
{
    return !empty(read_json(GENERATOR_FILE)['key_hash']);
}

/** Creates a new key (the old one stops working) and returns it – shown once. */
function generator_new_key(): string
{
    $key = 'omun_' . rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
    write_json(GENERATOR_FILE, ['key_hash' => hash('sha256', $key), 'created' => date('c')]);
    return $key;
}

function generator_remove_key(): void
{
    @unlink(GENERATOR_FILE);
}

function codes_api_handle(): never
{
    // The generator's page calls this from another origin (the local program).
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $send = function (int $status, array $data): never {
        http_response_code($status);
        exit(json_encode($data, JSON_UNESCAPED_UNICODE));
    };
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        $send(204, []);
    }
    if (rate_limited('codes-api', 60, 600)) {
        $send(429, ['error' => 'Zu viele Anfragen. Bitte später noch einmal versuchen.']);
    }
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('apache_request_headers')) {
        $auth = (string) (array_change_key_case(apache_request_headers())['authorization'] ?? '');
    }
    $hash = (string) (read_json(GENERATOR_FILE)['key_hash'] ?? '');
    if ($hash === '' || !preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) || !hash_equals($hash, hash('sha256', $m[1]))) {
        audit_error('codes_api.denied', 'generator');
        $send(401, ['error' => 'Der Schlüssel stimmt nicht. Bitte im Admin unter Kennungen einen neuen erzeugen und im Generator eintragen.']);
    }
    $counts = function (): array {
        $codes = codes();
        return ['total' => count($codes), 'free' => count(array_filter($codes, fn ($c) => empty($c['reg'])))];
    };
    if ($method === 'GET') {
        $send(200, ['ok' => true, 'site' => (string) c('site.name')] + $counts());
    }
    if ($method !== 'POST') {
        $send(405, ['error' => 'Method not allowed']);
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    $list = is_array($in['codes'] ?? null) ? array_slice($in['codes'], 0, 5000) : [];
    [$added, $known, $invalid] = add_codes(implode("\n", array_map(fn ($c) => is_string($c) ? $c : '', $list)));
    audit('codes.add', $added . ' via generator', 'generator');
    $send(200, ['added' => $added, 'known' => $known, 'invalid' => $invalid] + $counts());
}
