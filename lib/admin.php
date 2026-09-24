<?php
declare(strict_types=1);

const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const FILE_EXT = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'odt', 'ods', 'odp', 'txt', 'zip', 'mp4'];
const SESSION_IDLE = 3 * 3600;

/* ---------- Auth ---------- */

function auth_data(): array
{
    return read_json(AUTH_FILE);
}

function is_setup(): bool
{
    return !empty(auth_data()['hash']);
}

function is_logged_in(): bool
{
    start_session();
    if (empty($_SESSION['admin']) || ($_SESSION['pwv'] ?? null) !== (auth_data()['v'] ?? null)) {
        return false;
    }
    if (time() - ($_SESSION['last'] ?? 0) > SESSION_IDLE) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['last'] = time();
    return true;
}

function set_password(string $password): void
{
    $auth = auth_data();
    $auth['hash'] = password_hash($password, PASSWORD_DEFAULT);
    $auth['v'] = bin2hex(random_bytes(8)); // invalidates all other sessions
    $auth['changed'] = date('c');
    write_json(AUTH_FILE, $auth);
    login_session($auth['v']);
}

function login_session(string $version): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['pwv'] = $version;
    $_SESSION['last'] = time();
}

function check_password(string $password): bool
{
    $auth = auth_data();
    return !empty($auth['hash']) && password_verify($password, $auth['hash']);
}

/* ---------- Flash messages & redirects ---------- */

function flash(string $msg, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flash(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function admin_url(array $params = []): string
{
    return url('admin/') . ($params ? '?' . http_build_query($params) : '');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/* ---------- Media ---------- */

function media_files(?string $kind = null, bool $fresh = false): array
{
    static $cache = [];
    if (!$fresh && isset($cache[$kind ?? ''])) {
        return $cache[$kind ?? ''];
    }
    $files = [];
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $f) {
        if (!is_file($f) || str_starts_with(basename($f), '.')) {
            continue;
        }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($kind === 'image' && !in_array($ext, IMAGE_EXT, true)) {
            continue;
        }
        $files[] = 'uploads/' . basename($f);
    }
    usort($files, fn ($a, $b) => filemtime(ROOT . '/' . $b) <=> filemtime(ROOT . '/' . $a));
    return $cache[$kind ?? ''] = $files;
}

function is_image_path(string $p): bool
{
    return in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), IMAGE_EXT, true);
}

function upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß (Server-Limit: ' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise hochgeladen.',
        default => 'Upload fehlgeschlagen (Fehlercode ' . $code . ').',
    };
}

/**
 * Stores an uploaded file in /uploads and returns "uploads/name.ext".
 * @throws RuntimeException with a German message for the admin.
 */
function store_upload(array $file, string $kind = 'file'): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_text((int) $file['error']));
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = $kind === 'image' ? IMAGE_EXT : array_merge(IMAGE_EXT, FILE_EXT);
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('„' . $file['name'] . '“: Dateityp .' . $ext . ' ist nicht erlaubt. Erlaubt: ' . implode(', ', $allowed) . '.');
    }
    $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) : '';
    if (in_array($ext, IMAGE_EXT, true) && $mime !== '' && !str_starts_with($mime, 'image/')) {
        throw new RuntimeException('„' . $file['name'] . '“ ist kein gültiges Bild.');
    }
    if ($ext === 'pdf' && $mime !== '' && $mime !== 'application/pdf') {
        throw new RuntimeException('„' . $file['name'] . '“ ist keine gültige PDF-Datei.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $base = substr(slugify(pathinfo($file['name'], PATHINFO_FILENAME)), 0, 60);
    $ext = $ext === 'jpeg' ? 'jpg' : $ext;
    $name = "$base.$ext";
    for ($i = 2; file_exists(UPLOAD_DIR . '/' . $name); $i++) {
        $name = "$base-$i.$ext";
    }
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Datei konnte nicht gespeichert werden – Schreibrechte von /uploads prüfen.');
    }
    @chmod(UPLOAD_DIR . '/' . $name, 0644);
    optimize_image(UPLOAD_DIR . '/' . $name);
    return 'uploads/' . $name;
}

/** Rotates phone photos upright and scales very large images down (needs GD). */
function optimize_image(string $path, int $max = 2400): void
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!function_exists('imagecreatetruecolor') || !in_array($ext, ['jpg', 'png', 'webp'], true)) {
        return;
    }
    $info = @getimagesize($path);
    if (!$info || $info[0] * $info[1] > 40_000_000) {
        return;
    }
    [$w, $h] = $info;
    $orientation = 1;
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
    }
    if ($w <= $max && $h <= $max && $orientation === 1) {
        return;
    }
    $img = match ($ext) {
        'jpg' => @imagecreatefromjpeg($path),
        'png' => @imagecreatefrompng($path),
        'webp' => @imagecreatefromwebp($path),
    };
    if (!$img) {
        return;
    }
    $img = match ($orientation) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => $img,
    };
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $max / max($w, $h));
    if ($scale < 1) {
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $img = $dst;
    }
    match ($ext) {
        'jpg' => imagejpeg($img, $path, 84),
        'png' => imagepng($img, $path, 7),
        'webp' => imagewebp($img, $path, 84),
    };
}

/** Where is a file used? Returns a list of section labels. */
function media_usage(string $path): array
{
    $used = [];
    $needle = json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach (content() as $key => $value) {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (str_contains($json, $needle) || str_contains($json, '(' . $path . ')')) {
            $used[] = schema()[$key]['label'] ?? $key;
        }
    }
    return $used;
}

function human_size(int $bytes): string
{
    return $bytes > 1048576 ? number_format($bytes / 1048576, 1, ',', '') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

/* ---------- Forms generated from the schema ---------- */

/** Renders one field. $name is the POST name, $up the key used for uploads. */
function field_html(array $f, $value, string $name, string $up, string $id): string
{
    $label = '<label for="' . e($id) . '">' . e($f['label']) . '</label>';
    $help = !empty($f['help']) ? '<p class="help">' . e($f['help']) . '</p>' : '';
    $v = is_string($value) ? $value : '';
    switch ($f['type']) {
        case 'bool':
            return '<div class="field field-bool"><label><input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($value ? ' checked' : '') . '> ' . e($f['label']) . '</label>' . $help . '</div>';
        case 'textarea':
            return '<div class="field">' . $label . '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="3">' . e($v) . '</textarea>' . $help . '</div>';
        case 'markdown':
            return '<div class="field">' . $label . '<textarea class="md" id="' . e($id) . '" name="' . e($name) . '" rows="10">' . e($v) . '</textarea>' . $help . '</div>';
        case 'lines':
            $v = is_array($value) ? implode("\n", $value) : $v;
            return '<div class="field">' . $label . '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="4">' . e($v) . '</textarea>' . $help . '</div>';
        case 'select':
            $html = '<select id="' . e($id) . '" name="' . e($name) . '"><option value="">–</option>';
            foreach ($f['options'] as $o) {
                $html .= '<option' . ($v === $o ? ' selected' : '') . '>' . e($o) . '</option>';
            }
            return '<div class="field">' . $label . $html . '</select>' . $help . '</div>';
        case 'color':
            return '<div class="field field-color">' . $label . '<div class="color-row"><input type="color" id="' . e($id) . '" name="' . e($name) . '" value="' . e($v ?: '#000000') . '"><code>' . e($v) . '</code></div>' . $help . '</div>';
        case 'date':
            return '<div class="field field-short">' . $label . '<input type="date" id="' . e($id) . '" name="' . e($name) . '" value="' . e($v) . '">' . $help . '</div>';
        case 'image':
        case 'file':
            $kind = $f['type'];
            $opts = '<option value="">– keine Datei –</option>';
            foreach (media_files($kind === 'image' ? 'image' : null) as $m) {
                $opts .= '<option value="' . e($m) . '"' . ($v === $m ? ' selected' : '') . '>' . e(basename($m)) . '</option>';
            }
            if ($v && !in_array($v, media_files(), true)) {
                $opts .= '<option value="' . e($v) . '" selected>' . e($v) . ' (fehlt)</option>';
            }
            $preview = '';
            if ($v && $kind === 'image') {
                $preview = '<img class="preview" src="' . e(media($v)) . '" alt="">';
            } elseif ($v) {
                $preview = '<a class="preview-file" href="' . e(media($v)) . '" target="_blank">' . e(basename($v)) . '</a>';
            }
            $accept = $kind === 'image' ? 'image/*' : '';
            return '<div class="field field-media">' . $label . '<div class="media-row">' . $preview
                . '<div class="media-inputs"><select id="' . e($id) . '" name="' . e($name) . '">' . $opts . '</select>'
                . '<span class="or">oder neu hochladen:</span><input type="file" name="upload[' . e($up) . ']"' . ($accept ? ' accept="' . $accept . '"' : '') . '></div></div>' . $help . '</div>';
        case 'repeater':
            $rows = is_array($value) ? array_values($value) : [];
            $html = '<div class="field field-repeater"><span class="label">' . e($f['label']) . '</span><div class="repeater" data-next="' . count($rows) . '">';
            foreach ($rows as $i => $row) {
                $html .= repeater_row($f, $row, $name, $up, (string) $i);
            }
            $html .= '</div><template>' . repeater_row($f, [], $name, $up, '__i__') . '</template>';
            $html .= '<button type="button" class="btn-ghost add-row">+ Zeile hinzufügen</button>' . $help . '</div>';
            return $html;
        default:
            $type = in_array($f['type'], ['email', 'url'], true) ? $f['type'] : 'text';
            return '<div class="field">' . $label . '<input type="' . $type . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($v) . '">' . $help . '</div>';
    }
}

function repeater_row(array $f, array $row, string $name, string $up, string $i): string
{
    $html = '<div class="rep-row">';
    foreach ($f['fields'] as $sub) {
        $html .= field_html($sub, $row[$sub['key']] ?? '', "{$name}[$i][{$sub['key']}]", "$up.$i.{$sub['key']}", "f-{$up}-$i-{$sub['key']}");
    }
    return $html . '<div class="rep-tools"><button type="button" class="icon up" title="Nach oben">↑</button><button type="button" class="icon down" title="Nach unten">↓</button><button type="button" class="icon remove" title="Entfernen">✕</button></div></div>';
}

/** Reads a field value from POST, including uploads. Collects upload errors in $errors. */
function field_value(array $f, $raw, string $up, array &$errors)
{
    switch ($f['type']) {
        case 'bool':
            return (string) $raw === '1';
        case 'lines':
            return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $raw)), 'strlen'));
        case 'textarea':
        case 'markdown':
            return str_replace("\r\n", "\n", trim((string) $raw));
        case 'color':
            return preg_match('/^#[0-9a-f]{6}$/i', (string) $raw) ? strtolower((string) $raw) : '';
        case 'select':
            return in_array($raw, $f['options'], true) ? $raw : '';
        case 'image':
        case 'file':
            $value = (string) $raw;
            if ($value !== '' && !preg_match('~^uploads/[^/]+$~', $value)) {
                $value = '';
            }
            $u = upload_for($up);
            if ($u && $u['error'] !== UPLOAD_ERR_NO_FILE) {
                try {
                    $value = store_upload($u, $f['type']);
                } catch (RuntimeException $ex) {
                    $errors[] = $f['label'] . ': ' . $ex->getMessage();
                }
            }
            return $value;
        case 'repeater':
            $rows = [];
            foreach ((is_array($raw) ? $raw : []) as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($f['fields'] as $sub) {
                    $clean[$sub['key']] = field_value($sub, $row[$sub['key']] ?? '', "$up.$i.{$sub['key']}", $errors);
                }
                if (array_filter($clean, fn ($x) => $x !== '' && $x !== false && $x !== [])) {
                    $rows[] = $clean;
                }
            }
            return $rows;
        default:
            return trim((string) $raw);
    }
}

function upload_for(string $key): ?array
{
    $u = $_FILES['upload'] ?? null;
    if (!$u || !isset($u['name'][$key])) {
        return null;
    }
    return [
        'name' => $u['name'][$key],
        'tmp_name' => $u['tmp_name'][$key],
        'error' => (int) $u['error'][$key],
        'size' => $u['size'][$key],
    ];
}

/** Saves the content; the previous state is kept in the version history first. */
function save_content(array $content, string $label): void
{
    snapshot_content($label);
    write_json(CONTENT_FILE, $content);
}

/* ---------- Version history ---------- */

const HISTORY_DIR = DATA_DIR . '/history';
const HISTORY_KEEP = 50;

function snapshot_content(string $label): void
{
    if (!is_file(CONTENT_FILE)) {
        return;
    }
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
    write_json(HISTORY_DIR . '/' . $name, [
        'saved' => date('c'),
        'label' => $label,
        'content' => read_json(CONTENT_FILE),
    ]);
    $files = glob(HISTORY_DIR . '/*.json') ?: [];
    rsort($files);
    foreach (array_slice($files, HISTORY_KEEP) as $old) {
        @unlink($old);
    }
}

/** Newest first: [file, saved, label, size]. */
function history_list(): array
{
    $files = glob(HISTORY_DIR . '/*.json') ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $f) {
        $v = read_json($f);
        $out[] = ['file' => basename($f), 'saved' => $v['saved'] ?? '', 'label' => $v['label'] ?? '', 'size' => filesize($f)];
    }
    return $out;
}

function history_path(string $file): ?string
{
    $file = basename($file);
    return preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}\.json$/', $file) && is_file(HISTORY_DIR . '/' . $file) ? HISTORY_DIR . '/' . $file : null;
}

/* ---------- Two-factor authentication ---------- */

function totp_devices(): array
{
    return auth_data()['totp'] ?? [];
}

function totp_enabled(): bool
{
    return (bool) totp_devices();
}

/** Checks an app code (any device) or a one-time recovery code. */
function verify_second_factor(string $code): bool
{
    $auth = auth_data();
    foreach ($auth['totp'] ?? [] as $k => $dev) {
        $step = totp_verify($dev['secret'], $code, (int) ($dev['last_step'] ?? 0));
        if ($step !== null) {
            $auth['totp'][$k]['last_step'] = $step;
            $auth['totp'][$k]['last_used'] = date('c');
            write_json(AUTH_FILE, $auth);
            return true;
        }
    }
    $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
    if (strlen($normalized) === 10) {
        foreach ($auth['recovery'] ?? [] as $k => $hash) {
            if (password_verify($normalized, $hash)) {
                array_splice($auth['recovery'], $k, 1);
                write_json(AUTH_FILE, $auth);
                flash('Notfall-Code verwendet. Es sind noch ' . count($auth['recovery']) . ' übrig.', count($auth['recovery']) < 3 ? 'error' : 'ok');
                return true;
            }
        }
    }
    return false;
}

/** Creates 8 new recovery codes, stores their hashes and returns them for one-time display. */
function new_recovery_codes(): array
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    $auth = auth_data();
    $auth['recovery'] = array_map(fn ($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $codes);
    write_json(AUTH_FILE, $auth);
    return $codes;
}

function unique_slug(array $list, string $slug, string $ownId, bool $topLevel = false): string
{
    $slug = slugify($slug);
    $reserved = !$topLevel ? [] : ['admin', 'assets', 'uploads', 'data', 'lib', 'templates', 'conference', 'committees', 'team', 'news', 'gallery', 'faq', 'downloads', 'sponsors', 'archive', 'register', 'imprint', 'impressum', 'privacy', 'datenschutz'];
    $taken = array_column(array_filter($list, fn ($x) => ($x['id'] ?? '') !== $ownId), 'slug');
    $base = $slug;
    for ($i = 2; in_array($slug, $taken, true) || in_array($slug, $reserved, true); $i++) {
        $slug = "$base-$i";
    }
    return $slug;
}
