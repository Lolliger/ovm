<?php
declare(strict_types=1);

/**
 * Small local helper for the Kennungen-Generator: lets the generator page read
 * and write the list of handed-out codes (code + name) in a file on this
 * computer's desktop. Started by "Kennungen-Generator.command" (Mac) or
 * "Kennungen-Generator.bat" (Windows) – it never runs on the web server.
 *
 * The list:  ~/Desktop/OMUN-Kennungen.csv  (Kennung;Name;Erzeugt am)
 * Another place: set the environment variable OMUN_KENNUNGEN_FILE.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('Nur lokal: bitte Kennungen-Generator.command (Mac) bzw. Kennungen-Generator.bat (Windows) starten.');
}
// Only this computer, and only this page: no other website may read or write the list.
$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if (!in_array($host, ['127.0.0.1', 'localhost'], true)
    || ($origin !== '' && !preg_match('~^http://(127\.0\.0\.1|localhost):\d+$~', $origin))) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Europe/Berlin');
$home = getenv('HOME') ?: getenv('USERPROFILE') ?: '.';
$file = getenv('OMUN_KENNUNGEN_FILE') ?: $home . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'OMUN-Kennungen.csv';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

function normalize_code(string $code): string
{
    return strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $code));
}

function code_ok(string $code): bool
{
    return (bool) preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $code) && preg_match('/\d/', $code) && preg_match('/[A-Z]/', $code);
}

/** All rows of the list: [['code' => 'K7QFM3XP', 'name' => '…', 'date' => '…'], …]. */
function read_list(string $file): array
{
    $rows = [];
    if (!is_file($file)) {
        return $rows;
    }
    $fh = fopen($file, 'r');
    while (($line = fgetcsv($fh, null, ';', '"', '\\')) !== false) {
        $code = normalize_code(str_replace("\xEF\xBB\xBF", '', (string) ($line[0] ?? '')));
        if (code_ok($code)) {
            $rows[] = ['code' => $code, 'name' => trim((string) ($line[1] ?? '')), 'date' => trim((string) ($line[2] ?? ''))];
        }
    }
    fclose($fh);
    return $rows;
}

function answer(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

if ($path === '/api/list') {
    answer(['file' => $file, 'rows' => read_list($file)]);
}

if ($path === '/api/add' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // JSON only: a form on another website cannot send this.
    if (!str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        answer(['error' => 'Bad request'], 400);
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    $have = array_flip(array_column(read_list($file), 'code'));
    $new = is_file($file) && filesize($file) > 0 ? false : true;
    $fh = @fopen($file, 'a');
    if (!$fh) {
        answer(['error' => 'Die Datei konnte nicht geschrieben werden: ' . $file . ' (ist sie gerade in Excel geöffnet?)'], 500);
    }
    flock($fh, LOCK_EX);
    if ($new) {
        fwrite($fh, "\xEF\xBB\xBF"); // so Excel shows umlauts correctly
        fputcsv($fh, ['Kennung', 'Name', 'Erzeugt am'], ';', '"', '\\');
    }
    $added = 0;
    foreach ((array) ($in['rows'] ?? []) as $row) {
        $code = normalize_code((string) ($row['code'] ?? ''));
        if (!code_ok($code) || isset($have[$code])) {
            continue;
        }
        $have[$code] = true;
        $name = trim(mb_substr((string) ($row['name'] ?? ''), 0, 300));
        // No formulas in Excel.
        $name = preg_match('/^[=+\-@]/', $name) ? "'" . $name : $name;
        fputcsv($fh, [substr($code, 0, 4) . '-' . substr($code, 4), $name, date('d.m.Y H:i')], ';', '"', '\\');
        $added++;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    answer(['file' => $file, 'added' => $added, 'rows' => read_list($file)]);
}

if ($path === '/' || $path === '/kennungen-generator.html') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(__DIR__ . '/kennungen-generator.html');
    exit;
}

http_response_code(404);
exit('Not found');
