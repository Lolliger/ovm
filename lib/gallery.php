<?php
declare(strict_types=1);

/**
 * Photo gallery inside the portal (not the public gallery page). Two albums:
 *  - community: every logged-in participant (whatever their role or status) can
 *    upload and share photos – open from the start, also during the conference;
 *  - official: the photographer's pictures, uploaded in the admin and shown only
 *    once the organisers release the album (optionally with a farewell e-mail).
 *
 * The photos are not public: they lie in data/gallery/<album>/ and are only
 * delivered to logged-in accounts. Every photo is re-encoded on upload, which
 * removes the camera data (EXIF incl. GPS location) and keeps the files small.
 *
 * data/gallery.json:       [ { id, album, w, h, at, by (registration id | "admin") } ]
 * data/gallery-state.json: { released: date|null, mailed: { sha1(e-mail): date } }
 */

const GALLERY_FILE = DATA_DIR . '/gallery.json';
const GALLERY_STATE_FILE = DATA_DIR . '/gallery-state.json';
const GALLERY_DIR = DATA_DIR . '/gallery';
const GALLERY_ALBUMS = ['community', 'official'];
const GALLERY_MAX_BYTES = 30 * 1024 * 1024;
const GALLERY_MAX_SIDE = ['community' => 2560, 'official' => 3200];
const GALLERY_THUMB = 640;

function gallery_album_title(string $album): string
{
    return $album === 'official' ? 'Official photos' : 'Everyone’s photos';
}

function gallery_state(): array
{
    return read_json(GALLERY_STATE_FILE) + ['released' => null, 'mailed' => []];
}

function gallery_official_released(): bool
{
    return !empty(gallery_state()['released']);
}

/** Participants may upload to the shared album (Admin → Teilnehmer-Bereich). */
function gallery_uploads_open(): bool
{
    return (bool) c('portal.gallery_open', true);
}

/** Photos of an album, newest first. */
function gallery_photos(string $album): array
{
    $list = array_values(array_filter(read_json(GALLERY_FILE), fn ($p) => ($p['album'] ?? '') === $album));
    usort($list, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    return $list;
}

function gallery_photo(string $id): ?array
{
    foreach (read_json(GALLERY_FILE) as $p) {
        if (($p['id'] ?? '') === $id) {
            return $p;
        }
    }
    return null;
}

function gallery_file(array $p, bool $thumb = false): string
{
    return GALLERY_DIR . '/' . basename((string) $p['album']) . '/' . basename((string) $p['id']) . ($thumb ? '-t' : '') . '.jpg';
}

/**
 * Who may see the gallery: every account with a registration (any role, any
 * status) – they may also upload; the shared laptop account may only look.
 * Returns ['reg' => registration id|null] or null.
 */
function gallery_viewer(?string $account): ?array
{
    if (!$account) {
        return null;
    }
    if (is_staff_account($account)) {
        return ['reg' => null];
    }
    $regs = registrations_for($account);
    return $regs ? ['reg' => $regs[0]['id']] : null;
}

/** May this viewer see the album? */
function gallery_album_visible(string $album): bool
{
    return $album === 'community' || ($album === 'official' && gallery_official_released());
}

/**
 * Re-encodes an uploaded image as JPEG (upright, at most $max px, no camera data)
 * plus a thumbnail. Returns [width, height] or an error message (English).
 */
function gallery_process(string $tmp, string $dest, string $thumb, int $max)
{
    if (!function_exists('imagecreatetruecolor')) {
        return 'Images cannot be processed on this server.';
    }
    $info = @getimagesize($tmp);
    $type = $info[2] ?? 0;
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        return 'Only JPG, PNG and WebP photos can be uploaded. (iPhone: Settings → Camera → Formats → “Most Compatible”.)';
    }
    if ($info[0] * $info[1] > 60_000_000) {
        return 'This photo is too large (more than 60 megapixels).';
    }
    @ini_set('memory_limit', '512M');
    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($tmp),
    };
    if (!$img) {
        return 'This photo could not be read.';
    }
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $img = match ((int) (@exif_read_data($tmp)['Orientation'] ?? 1)) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
    $w = imagesx($img);
    $h = imagesy($img);
    // Transparent PNGs get a white background (JPEG has no transparency).
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
    $save = function (int $side, string $path, int $quality) use ($flat, $w, $h): array {
        $scale = min(1, $side / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = $flat;
        if ($scale < 1) {
            $out = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($out, $flat, 0, 0, 0, 0, $nw, $nh, $w, $h);
        }
        imageinterlace($out, true);
        imagejpeg($out, $path, $quality);
        return [$nw, $nh];
    };
    $size = $save($max, $dest, 86);
    $save(GALLERY_THUMB, $thumb, 78);
    @chmod($dest, 0644);
    @chmod($thumb, 0644);
    return $size;
}

/** Stores one uploaded photo. Returns an error message (English) or null. */
function gallery_store(string $album, array $file, string $by): ?string
{
    if (!in_array($album, GALLERY_ALBUMS, true)) {
        return 'Album not found.';
    }
    $name = (string) ($file['name'] ?? 'photo');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Please choose at least one photo.';
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > GALLERY_MAX_BYTES) {
        return '„' . $name . '“ is too large (photos can be up to ' . (GALLERY_MAX_BYTES >> 20) . ' MB).';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return '„' . $name . '“ could not be uploaded. Please try again.';
    }
    $dir = GALLERY_DIR . '/' . $album;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $id = bin2hex(random_bytes(8));
    $photo = ['id' => $id, 'album' => $album];
    $result = gallery_process($file['tmp_name'], gallery_file($photo), gallery_file($photo, true), GALLERY_MAX_SIDE[$album]);
    @unlink($file['tmp_name']);
    if (is_string($result)) {
        @unlink(gallery_file($photo));
        @unlink(gallery_file($photo, true));
        return '„' . $name . '“: ' . $result;
    }
    append_json(GALLERY_FILE, $photo + ['w' => $result[0], 'h' => $result[1], 'at' => date('c'), 'by' => $by]);
    return null;
}

/** Deletes a photo. $by = only if uploaded by this registration (null = any, for the admin). */
function gallery_delete(string $id, ?string $by = null): bool
{
    $removed = null;
    update_json(GALLERY_FILE, function (array $list) use ($id, $by, &$removed) {
        foreach ($list as $k => $p) {
            if (($p['id'] ?? '') === $id && ($by === null || ($p['by'] ?? '') === $by)) {
                $removed = $p;
                unset($list[$k]);
            }
        }
        return array_values($list);
    });
    if ($removed) {
        @unlink(gallery_file($removed));
        @unlink(gallery_file($removed, true));
    }
    return $removed !== null;
}

/** Deletes all photos of an album. Returns how many. */
function gallery_delete_album(string $album): int
{
    $n = 0;
    foreach (gallery_photos($album) as $p) {
        $n += gallery_delete($p['id']) ? 1 : 0;
    }
    return $n;
}

/** Delivers a photo: thumbnail, full size, or full size as a download. */
function gallery_send(array $p, string $size, bool $download): never
{
    $path = gallery_file($p, $size === 't');
    if (!is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    if ($download) {
        $name = c('site.name', 'OMUN') . ' ' . ($p['album'] === 'official' ? 'official ' : '') . date('Y-m-d', strtotime((string) $p['at'])) . ' ' . substr($p['id'], 0, 6) . '.jpg';
        header('Content-Disposition: attachment; filename="' . $name . '"');
    }
    readfile($path);
    exit;
}

/** All photos of an album as one ZIP file. */
function gallery_zip(string $album): never
{
    $photos = gallery_photos($album);
    if (!$photos || !class_exists('ZipArchive')) {
        http_response_code(404);
        exit('No photos');
    }
    @set_time_limit(600);
    $tmp = tempnam(sys_get_temp_dir(), 'gal');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach (array_reverse($photos) as $i => $p) {
        if (is_file(gallery_file($p))) {
            $entry = sprintf('%03d', $i + 1) . ' ' . date('Y-m-d H-i', strtotime((string) $p['at'])) . '.jpg';
            $zip->addFile(gallery_file($p), $entry);
            $zip->setCompressionName($entry, ZipArchive::CM_STORE); // JPEGs do not get smaller
        }
    }
    $zip->close();
    $name = c('conference.edition', c('site.name', 'OMUN')) . ' – ' . gallery_album_title($album) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w .-]/u', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** Address of the gallery in the portal. */
function gallery_link(string $album = 'community'): string
{
    return portal_link('gallery') . ($album === 'official' ? '?album=official' : '');
}

/** Full address of the official album for e-mails (works on the main domain and on a portal subdomain). */
function gallery_mail_link(): string
{
    return (split_portal() ? rtrim(portal_home(), '/') . '/gallery' : main_origin() . url('portal/gallery')) . '?album=official';
}

/** Everyone who gets the farewell e-mail: all registrations with an address, except cancelled ones and test delegates (one mail per address). */
function gallery_recipients(): array
{
    $out = [];
    foreach (read_json(REGISTRATIONS_FILE) as $r) {
        $email = normalize_email((string) ($r['email'] ?? ''));
        if ($email !== '' && empty($r['test']) && ($r['status'] ?? '') !== 'cancelled' && !isset($out[$email])) {
            $out[$email] = $r;
        }
    }
    return $out;
}

/**
 * Shows the official album in the portal and, if $mail, sends the farewell
 * e-mail to everyone who has not received it yet (so pressing the button again
 * after an error never sends a mail twice). Returns [sent, failed].
 */
function gallery_release(bool $mail, string $subject, string $text): array
{
    update_json(GALLERY_STATE_FILE, function (array $s) {
        $s['released'] = !empty($s['released']) ? $s['released'] : date('c');
        return $s;
    });
    if (!$mail) {
        return [0, 0];
    }
    @set_time_limit(900);
    ignore_user_abort(true);
    $sent = 0;
    $failed = 0;
    $mailed = gallery_state()['mailed'];
    foreach (gallery_recipients() as $email => $r) {
        $key = sha1($email);
        if (isset($mailed[$key])) {
            continue;
        }
        $vars = ['code' => format_code((string) ($r['code'] ?? '')), 'first_name' => format_code((string) ($r['code'] ?? '')), 'last_name' => '',
            'conference' => c('conference.edition', c('site.name')), 'portal' => login_url(), 'gallery' => gallery_mail_link(), 'email' => c('site.email')];
        if (send_mail($r['email'], fill_placeholders($subject, $vars), fill_placeholders($text, $vars), c('site.email') ?: null)) {
            $sent++;
            update_json(GALLERY_STATE_FILE, function (array $s) use ($key) {
                $s['mailed'][$key] = date('c');
                return $s;
            });
        } else {
            $failed++;
        }
    }
    return [$sent, $failed];
}

function gallery_hide(): void
{
    update_json(GALLERY_STATE_FILE, function (array $s) {
        $s['released'] = null;
        return $s;
    });
}

/** Normalizes $_FILES['photos'] (multiple) into a list of single files. */
function gallery_uploaded_files(string $field = 'photos'): array
{
    $u = $_FILES[$field] ?? null;
    if (!$u) {
        return [];
    }
    if (!is_array($u['name'])) {
        return [$u];
    }
    $out = [];
    foreach ($u['name'] as $k => $name) {
        $out[] = ['name' => $name, 'tmp_name' => $u['tmp_name'][$k], 'error' => (int) $u['error'][$k], 'size' => (int) $u['size'][$k]];
    }
    return $out;
}

/** Stores all uploaded photos. Returns [stored count, list of error messages]. */
function gallery_store_uploads(string $album, string $by): array
{
    $files = array_filter(gallery_uploaded_files(), fn ($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if (!$files) {
        return [0, ['Please choose at least one photo.']];
    }
    $ok = 0;
    $errors = [];
    foreach ($files as $f) {
        if ($err = gallery_store($album, $f, $by)) {
            $errors[] = $err;
        } else {
            $ok++;
        }
    }
    return [$ok, $errors];
}

/** /portal/gallery – the gallery page, photos, ZIP, upload and delete. */
function gallery_route(?string $account): void
{
    $viewer = gallery_viewer($account);
    if (!$viewer) {
        if (!$account) {
            start_session();
            $_SESSION['after_login'] = ($_GET['album'] ?? '') === 'official' ? 'gallery?album=official' : 'gallery';
            redirect_to(login_url());
        }
        not_found();
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $ajax = !empty($_POST['ajax']) || !empty($_GET['ajax']);

    if (!$post && isset($_GET['photo'])) {
        $p = gallery_photo((string) $_GET['photo']);
        if (!$p || !gallery_album_visible($p['album'])) {
            http_response_code(404);
            exit('Not found');
        }
        gallery_send($p, (string) ($_GET['size'] ?? 'f'), isset($_GET['download']));
    }
    if (!$post && isset($_GET['zip'])) {
        $album = (string) $_GET['zip'];
        if (!in_array($album, GALLERY_ALBUMS, true) || !gallery_album_visible($album) || rate_limited('galzip:' . $account, 10, 3600)) {
            not_found();
        }
        gallery_zip($album);
    }

    $message = '';
    $errors = [];
    if ($post) {
        if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $errors[] = 'The upload is too large. Please upload fewer photos at once.';
        } elseif (!csrf_check()) {
            $errors[] = 'Your session expired. Please reload the page and try again.';
        } elseif (($_POST['a'] ?? '') === 'upload') {
            if (!$viewer['reg']) {
                $errors[] = 'This shared account cannot upload photos.';
            } elseif (!gallery_uploads_open()) {
                $errors[] = 'Uploading photos is closed.';
            } elseif (rate_limited('galup:' . $account, 400, 3600)) {
                $errors[] = 'You have uploaded a lot of photos in a short time. Please wait a little.';
            } else {
                [$ok, $errors] = gallery_store_uploads('community', $viewer['reg']);
                if ($ok) {
                    $message = $ok === 1 ? 'Thank you! Your photo has been added.' : "Thank you! {$ok} photos have been added.";
                }
            }
        } elseif (($_POST['a'] ?? '') === 'delete') {
            if ($viewer['reg'] && gallery_delete((string) ($_POST['id'] ?? ''), $viewer['reg'])) {
                $message = 'Your photo has been deleted.';
            } else {
                $errors[] = 'You can only delete your own photos.';
            }
        }
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => !$errors, 'errors' => $errors, 'message' => $message]);
            exit;
        }
        start_session();
        $_SESSION['gallery_flash'] = [$message, $errors];
        redirect_to(portal_link('gallery'));
    }
    start_session();
    if (isset($_SESSION['gallery_flash'])) {
        [$message, $errors] = $_SESSION['gallery_flash'];
        unset($_SESSION['gallery_flash']);
    }
    $album = ($_GET['album'] ?? '') === 'official' && gallery_official_released() ? 'official' : 'community';
    render('portal-gallery', ['title' => 'Photo gallery', 'account' => $account, 'viewer' => $viewer, 'album' => $album,
        'photos' => gallery_photos($album), 'message' => $message, 'errors' => $errors]);
}
