<?php
declare(strict_types=1);

/**
 * Code updates from the admin: upload the update zip, the server replaces
 * the program files. data/ and uploads/ (content, password, registrations,
 * images) are never touched. Before every update the current program files
 * are saved as a zip in data/code-backups/.
 */

const CODE_BACKUP_DIR = DATA_DIR . '/code-backups';
const CODE_BACKUP_KEEP = 5;
/** Top-level folders/files that belong to the live installation, never to an update. */
const PROTECTED_PATHS = ['data', 'uploads'];

function current_version(): string
{
    $v = is_file(ROOT . '/VERSION') ? trim((string) file_get_contents(ROOT . '/VERSION')) : '';
    return $v !== '' ? $v : 'unbekannt';
}

function is_protected_path(string $rel): bool
{
    $first = explode('/', $rel, 2)[0];
    return in_array($first, PROTECTED_PATHS, true);
}

/**
 * Finds the folder inside the zip that contains the website (the zip may have
 * the files at the top level or inside one folder, e.g. "ovm-main/").
 */
function update_root_prefix(ZipArchive $zip): ?string
{
    $best = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('~^(.*/)?lib/bootstrap\.php$~', $name, $m)) {
            $prefix = $m[1] ?? '';
            if ($zip->locateName($prefix . 'index.php') !== false && ($best === null || strlen($prefix) < strlen($best))) {
                $best = $prefix;
            }
        }
    }
    return $best;
}

/** Zips all program files (everything except data/ and uploads/) as a backup. */
function backup_code(): string
{
    if (!is_dir(CODE_BACKUP_DIR)) {
        mkdir(CODE_BACKUP_DIR, 0755, true);
    }
    $file = CODE_BACKUP_DIR . '/code-' . date('Ymd-His') . '-v' . preg_replace('/[^\w.-]/', '_', current_version()) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Sicherung konnte nicht angelegt werden.');
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(ROOT) + 1));
        if ($f->isFile() && !is_protected_path($rel) && !str_starts_with($rel, '.git/')) {
            $zip->addFile($f->getPathname(), $rel);
        }
    }
    $zip->close();

    $all = glob(CODE_BACKUP_DIR . '/code-*.zip') ?: [];
    rsort($all);
    foreach (array_slice($all, CODE_BACKUP_KEEP) as $old) {
        @unlink($old);
    }
    return basename($file);
}

function code_backups(): array
{
    $all = glob(CODE_BACKUP_DIR . '/code-*.zip') ?: [];
    rsort($all);
    return array_map(fn ($f) => ['file' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $all);
}

/**
 * Applies an update zip. Returns [installed file count, old version, new version].
 * @throws RuntimeException with a German message for the admin.
 */
function apply_update(string $zipFile): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Der Server kann keine Zip-Dateien entpacken (PHP-Erweiterung „zip“ fehlt). Bitte über den Strato-Dateimanager hochladen.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('Die Datei ist keine gültige Zip-Datei.');
    }
    $prefix = update_root_prefix($zip);
    if ($prefix === null) {
        $zip->close();
        throw new RuntimeException('Das ist kein Update für diese Website (index.php bzw. lib/bootstrap.php fehlen in der Zip).');
    }

    // 1. Check every entry and extract into a temporary folder first.
    $tmp = DATA_DIR . '/update-tmp-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $files = [];
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }
            $rel = substr($name, strlen($prefix));
            if ($rel === '' || str_ends_with($rel, '/')) {
                continue; // folder entry
            }
            if (str_contains($rel, '\\') || str_contains($rel, "\0") || str_starts_with($rel, '/')
                || in_array('..', explode('/', $rel), true) || preg_match('~(^|/)\.git/~', $rel)) {
                throw new RuntimeException('Die Zip enthält einen ungültigen Pfad: ' . $rel);
            }
            if (is_protected_path($rel) || str_starts_with($rel, '__MACOSX/') || basename($rel) === '.DS_Store') {
                continue; // live data and junk are never replaced
            }
            $target = $tmp . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            $in = $zip->getStream($name);
            if (!$in || file_put_contents($target, $in) === false) {
                throw new RuntimeException('Datei konnte nicht entpackt werden: ' . $rel);
            }
            fclose($in);
            $files[] = $rel;
        }
        $zip->close();
        if (!in_array('index.php', $files, true)) {
            throw new RuntimeException('Das Update ist unvollständig.');
        }

        // 2. Save the current program files, then 3. copy the new files over.
        $old = current_version();
        backup_code();
        foreach ($files as $rel) {
            $dest = ROOT . '/' . $rel;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            if (!copy($tmp . '/' . $rel, $dest . '.new') || !rename($dest . '.new', $dest)) {
                @unlink($dest . '.new');
                throw new RuntimeException('Datei konnte nicht geschrieben werden: ' . $rel . '. Die vorherige Version liegt unter „Sicherungen“.');
            }
        }
    } finally {
        remove_dir($tmp);
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    return [count($files), $old, current_version()];
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
