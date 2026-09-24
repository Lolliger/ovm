<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/admin.php';
require dirname(__DIR__) . '/lib/registration.php';
require dirname(__DIR__) . '/lib/totp.php';

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$schema = schema();
$s = (string) ($_GET['s'] ?? '');
$action = (string) ($_POST['a'] ?? '');

/* ---------- Not logged in: setup or login ---------- */

if (!is_setup()) {
    $error = '';
    if ($action === 'setup') {
        $pw = (string) ($_POST['password'] ?? '');
        if (!csrf_check()) {
            $error = 'Sitzung abgelaufen, bitte erneut versuchen.';
        } elseif (mb_strlen($pw) < 10) {
            $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
        } elseif ($pw !== ($_POST['password2'] ?? '')) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            set_password($pw);
            content(); // creates data/content.json
            flash('Passwort gespeichert. Willkommen im Admin-Bereich!');
            redirect(admin_url());
        }
    }
    admin_login_page('setup', $error);
    exit;
}

if (!is_logged_in()) {
    $error = '';
    start_session();
    // Step 2 of the login: password was correct, now the authenticator code.
    $pending = $_SESSION['2fa'] ?? null;
    if ($pending && ($pending['v'] !== (auth_data()['v'] ?? null) || time() - $pending['t'] > 300)) {
        unset($_SESSION['2fa']);
        $pending = null;
        $error = $action === 'login2fa' ? 'Zeit abgelaufen, bitte erneut anmelden.' : '';
    }
    if ($action === 'cancel2fa') {
        unset($_SESSION['2fa']);
        redirect(admin_url());
    }
    if ($pending && $action === 'login2fa') {
        if (!csrf_check()) {
            $error = 'Sitzung abgelaufen, bitte erneut versuchen.';
        } elseif (rate_limited('login', 8, 900)) {
            $error = 'Zu viele Versuche. Bitte 15 Minuten warten.';
        } elseif (verify_second_factor((string) ($_POST['code'] ?? ''))) {
            unset($_SESSION['2fa']);
            login_session(auth_data()['v']);
            redirect(admin_url());
        } else {
            usleep(400000);
            $error = 'Der Code ist falsch oder abgelaufen.';
        }
    }
    if ($pending) {
        admin_login_page('2fa', $error);
        exit;
    }
    if ($action === 'login') {
        if (!csrf_check()) {
            $error = 'Sitzung abgelaufen, bitte erneut versuchen.';
        } elseif (rate_limited('login', 8, 900)) {
            $error = 'Zu viele Versuche. Bitte 15 Minuten warten.';
        } elseif (check_password((string) ($_POST['password'] ?? ''))) {
            if (totp_enabled()) {
                session_regenerate_id(true);
                $_SESSION['2fa'] = ['v' => auth_data()['v'], 't' => time()];
                redirect(admin_url());
            }
            login_session(auth_data()['v']);
            redirect(admin_url(array_filter(['s' => $s])));
        } else {
            usleep(400000);
            $error = 'Falsches Passwort.';
        }
    }
    admin_login_page('login', $error);
    exit;
}

purge_registrations();

/* ---------- Logged in: actions (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash('Sitzung abgelaufen – bitte erneut versuchen.', 'error');
        redirect(admin_url(array_filter(['s' => $s])));
    }
    $content = content();
    try {
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                redirect(admin_url());

            case 'save':
                $def = $schema[$s] ?? null;
                if (!$def) {
                    redirect(admin_url());
                }
                $errors = [];
                $in = $_POST['f'] ?? [];
                if ($def['type'] === 'object') {
                    foreach ($def['fields'] as $f) {
                        $content[$s][$f['key']] = field_value($f, $in[$f['key']] ?? '', $f['key'], $errors);
                    }
                    save_content($content, $def['label']);
                    foreach ($errors as $er) {
                        flash($er, 'error');
                    }
                    flash('Gespeichert.');
                    redirect(admin_url(['s' => $s]));
                }
                // list item
                $id = (string) ($_POST['id'] ?? '');
                $list = $content[$s] ?? [];
                $item = ['id' => $id ?: bin2hex(random_bytes(5))];
                foreach ($def['fields'] as $f) {
                    $item[$f['key']] = field_value($f, $in[$f['key']] ?? '', $f['key'], $errors);
                }
                if (!empty($def['slug_from'])) {
                    $wanted = trim((string) ($_POST['slug'] ?? '')) ?: (string) $item[$def['slug_from']];
                    $item['slug'] = unique_slug($list, $wanted, $item['id'], $s === 'pages');
                }
                $found = false;
                foreach ($list as $k => $existing) {
                    if (($existing['id'] ?? '') === $id && $id !== '') {
                        $list[$k] = $item;
                        $found = true;
                    }
                }
                if (!$found) {
                    if ($s === 'news') {
                        array_unshift($list, $item);
                    } else {
                        $list[] = $item;
                    }
                }
                $content[$s] = array_values($list);
                save_content($content, $def['label'] . ': ' . ($item[$def['title_field']] ?? ''));
                foreach ($errors as $er) {
                    flash($er, 'error');
                }
                flash('Gespeichert.');
                redirect(admin_url(['s' => $s, 'i' => $item['id']]));

            case 'delete_item':
            case 'move_item':
                $list = $content[$s] ?? [];
                $id = (string) ($_POST['id'] ?? '');
                $idx = array_search($id, array_column($list, 'id'), true);
                if ($idx !== false) {
                    if ($action === 'delete_item') {
                        array_splice($list, $idx, 1);
                        flash('Eintrag gelöscht.');
                    } else {
                        $to = $idx + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
                        if ($to >= 0 && $to < count($list)) {
                            [$list[$idx], $list[$to]] = [$list[$to], $list[$idx]];
                        }
                    }
                    $label = ($schema[$s]['label'] ?? $s) . ': ' . ($action === 'delete_item' ? 'Eintrag gelöscht' : 'Reihenfolge geändert');
                    $content[$s] = array_values($list);
                    save_content($content, $label);
                }
                redirect(admin_url(['s' => $s]));

            case 'media_upload':
                $n = 0;
                $files = $_FILES['files'] ?? null;
                if ($files && is_array($files['name'])) {
                    foreach ($files['name'] as $k => $name) {
                        if ((int) $files['error'][$k] === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        try {
                            store_upload(['name' => $name, 'tmp_name' => $files['tmp_name'][$k], 'error' => (int) $files['error'][$k], 'size' => $files['size'][$k]]);
                            $n++;
                        } catch (RuntimeException $ex) {
                            flash($ex->getMessage(), 'error');
                        }
                    }
                }
                if ($n) {
                    flash($n === 1 ? '1 Datei hochgeladen.' : "$n Dateien hochgeladen.");
                }
                redirect(admin_url(['s' => 'media']));

            case 'media_delete':
                $file = basename((string) ($_POST['file'] ?? ''));
                $path = UPLOAD_DIR . '/' . $file;
                if ($file !== '' && $file[0] !== '.' && is_file($path)) {
                    unlink($path);
                    flash('„' . $file . '“ gelöscht.');
                }
                redirect(admin_url(['s' => 'media']));

            case 'reg_delete':
                $id = (string) ($_POST['id'] ?? '');
                $regs = array_values(array_filter(read_json(REGISTRATIONS_FILE), fn ($r) => ($r['id'] ?? '') !== $id));
                write_json(REGISTRATIONS_FILE, $regs);
                flash('Anmeldung gelöscht.');
                redirect(admin_url(['s' => 'registrations']));

            case 'reg_delete_all':
                if (($_POST['confirm'] ?? '') === 'LÖSCHEN') {
                    write_json(REGISTRATIONS_FILE, []);
                    flash('Alle Anmeldungen wurden gelöscht.');
                } else {
                    flash('Zum Bestätigen bitte LÖSCHEN eintippen.', 'error');
                }
                redirect(admin_url(['s' => 'registrations']));

            case 'password':
                if (!check_password((string) ($_POST['current'] ?? ''))) {
                    flash('Das aktuelle Passwort ist falsch.', 'error');
                } elseif (mb_strlen((string) ($_POST['password'] ?? '')) < 10) {
                    flash('Das neue Passwort muss mindestens 10 Zeichen lang sein.', 'error');
                } elseif ($_POST['password'] !== ($_POST['password2'] ?? '')) {
                    flash('Die neuen Passwörter stimmen nicht überein.', 'error');
                } else {
                    set_password((string) $_POST['password']);
                    flash('Passwort geändert. Alle anderen Geräte wurden abgemeldet.');
                }
                redirect(admin_url(['s' => 'settings']));

            case 'import':
                $u = $_FILES['backup'] ?? null;
                $data = $u && $u['error'] === UPLOAD_ERR_OK ? json_decode((string) file_get_contents($u['tmp_name']), true) : null;
                if (!is_array($data) || !isset($data['site'], $data['home'])) {
                    flash('Das ist keine gültige Backup-Datei.', 'error');
                } else {
                    save_content(array_intersect_key($data, $schema), 'Backup-Import');
                    flash('Backup wiederhergestellt. Der vorherige Stand ist unter „Versionen“ gesichert.');
                }
                redirect(admin_url(['s' => 'settings']));

            case 'history_restore':
                $path = history_path((string) ($_POST['file'] ?? ''));
                $version = $path ? read_json($path) : [];
                if (empty($version['content'])) {
                    flash('Version nicht gefunden.', 'error');
                } else {
                    save_content($version['content'], 'Wiederherstellung (Stand vom ' . date('d.m.Y H:i', strtotime($version['saved'])) . ')');
                    flash('Version vom ' . date('d.m.Y, H:i', strtotime($version['saved'])) . ' wiederhergestellt. Der Stand davor ist ebenfalls gesichert.');
                }
                redirect(admin_url(['s' => 'history']));

            case 'totp_add':
                $secret = $_SESSION['totp_new'] ?? '';
                $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 60)) ?: 'Gerät';
                $step = $secret ? totp_verify($secret, (string) ($_POST['code'] ?? '')) : null;
                if ($step === null) {
                    flash('Der Code stimmt nicht. Bitte den aktuellen Code aus der App eingeben (Uhrzeit am Handy prüfen).', 'error');
                    redirect(admin_url(['s' => 'settings', 'add2fa' => 1]));
                }
                $first = !totp_enabled();
                $auth = auth_data();
                $auth['totp'][] = ['id' => bin2hex(random_bytes(4)), 'name' => $name, 'secret' => $secret, 'created' => date('c'), 'last_step' => $step];
                write_json(AUTH_FILE, $auth);
                unset($_SESSION['totp_new']);
                flash('„' . $name . '“ hinzugefügt. Ab jetzt wird beim Login ein Code verlangt.');
                if ($first || empty($auth['recovery'])) {
                    $_SESSION['recovery_show'] = new_recovery_codes();
                }
                redirect(admin_url(['s' => 'settings']));

            case 'totp_remove':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Zum Entfernen eines Geräts bitte das richtige Passwort eingeben.', 'error');
                } else {
                    $auth = auth_data();
                    $id = (string) ($_POST['id'] ?? '');
                    $auth['totp'] = array_values(array_filter($auth['totp'] ?? [], fn ($d) => $d['id'] !== $id));
                    if (!$auth['totp']) {
                        unset($auth['recovery']);
                    }
                    write_json(AUTH_FILE, $auth);
                    flash($auth['totp'] ? 'Gerät entfernt.' : 'Gerät entfernt. Zwei-Faktor-Login ist jetzt AUS.', $auth['totp'] ? 'ok' : 'error');
                }
                redirect(admin_url(['s' => 'settings']));

            case 'recovery_new':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Bitte das richtige Passwort eingeben.', 'error');
                } else {
                    $_SESSION['recovery_show'] = new_recovery_codes();
                    flash('Neue Notfall-Codes erstellt. Die alten gelten nicht mehr.');
                }
                redirect(admin_url(['s' => 'settings']));
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        redirect(admin_url(array_filter(['s' => $s])));
    }
    redirect(admin_url());
}

/* ---------- Downloads (GET) ---------- */

if ($s === 'export') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="omun-inhalte-' . date('Y-m-d') . '.json"');
    readfile(CONTENT_FILE);
    exit;
}

if ($s === 'history_download') {
    $path = history_path((string) ($_GET['file'] ?? ''));
    if (!$path) {
        http_response_code(404);
        exit('Nicht gefunden');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="omun-version-' . basename($path) . '"');
    echo json_encode(read_json($path)['content'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($s === 'registrations_csv') {
    $regs = read_json(REGISTRATIONS_FILE);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anmeldungen-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel shows umlauts correctly
    $fields = registration_fields();
    fputcsv($out, array_merge(['Datum'], array_column($fields, 0)), ';');
    foreach ($regs as $r) {
        $row = [date('d.m.Y H:i', strtotime($r['created']))];
        foreach ($fields as $k => $_) {
            $v = (string) ($r[$k] ?? '');
            $row[] = preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; // no formula injection
        }
        fputcsv($out, $row, ';');
    }
    exit;
}

/* ---------- Pages ---------- */

admin_page($s, $schema);

/* ================================================================== */

function admin_head(string $title): void
{
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Admin</title>
<link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(url('admin/admin.css')) ?>?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
<script src="<?= e(url('admin/qrcode.js')) ?>" defer></script>
<script src="<?= e(url('admin/admin.js')) ?>?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
</head>
    <?php
}

function admin_login_page(string $mode, string $error): void
{
    admin_head(['setup' => 'Einrichtung', '2fa' => 'Bestätigungscode'][$mode] ?? 'Login');
    ?>
<body class="login">
  <form class="login-box" method="post">
    <p class="login-brand"><?= e(c('site.name', 'OMUN')) ?> <span>Admin</span></p>
    <?= csrf_field() ?>
    <?php if ($mode === 'setup'): ?>
      <input type="hidden" name="a" value="setup">
      <p>Willkommen! Lege jetzt das Admin-Passwort fest. Wer es kennt, kann alle Inhalte der Seite bearbeiten.</p>
      <?php if ($error): ?><p class="flash flash-error"><?= e($error) ?></p><?php endif; ?>
      <label>Neues Passwort (mind. 10 Zeichen)<input type="password" name="password" autocomplete="new-password" required autofocus minlength="10"></label>
      <label>Passwort wiederholen<input type="password" name="password2" autocomplete="new-password" required minlength="10"></label>
      <button class="btn" type="submit">Passwort speichern</button>
    <?php elseif ($mode === '2fa'): ?>
      <input type="hidden" name="a" value="login2fa">
      <p>Gib den 6-stelligen Code aus deiner Authenticator-App ein.</p>
      <?php if ($error): ?><p class="flash flash-error"><?= e($error) ?></p><?php endif; ?>
      <label>Code<input name="code" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="11" required autofocus class="code-input"></label>
      <button class="btn" type="submit">Bestätigen</button>
      <p class="help">Handy nicht da? Du kannst stattdessen einen deiner Notfall-Codes eingeben (Format <code>xxxxx-xxxxx</code>).</p>
      <button class="link-btn" type="submit" name="a" value="cancel2fa" formnovalidate>Abbrechen</button>
    <?php else: ?>
      <input type="hidden" name="a" value="login">
      <?php if ($error): ?><p class="flash flash-error"><?= e($error) ?></p><?php endif; ?>
      <label>Passwort<input type="password" name="password" autocomplete="current-password" required autofocus></label>
      <button class="btn" type="submit">Anmelden</button>
    <?php endif; ?>
  </form>
</body>
</html>
    <?php
}

function admin_page(string $s, array $schema): void
{
    $regCount = count(read_json(REGISTRATIONS_FILE));
    $titles = ['' => 'Übersicht', 'registrations' => 'Anmeldungen', 'media' => 'Dateien & Bilder', 'history' => 'Versionen', 'settings' => 'Sicherheit & Backup'];
    $title = $schema[$s]['label'] ?? $titles[$s] ?? 'Übersicht';
    admin_head($title);
    ?>
<body>
<input type="checkbox" id="nav-toggle" class="nav-toggle">
<header class="topbar">
  <label for="nav-toggle" class="nav-btn" aria-label="Menü">☰</label>
  <a class="topbar-brand" href="<?= e(admin_url()) ?>"><?= e(c('site.name')) ?> <span>Admin</span></a>
  <a class="topbar-link" href="<?= e(url()) ?>" target="_blank">Website ansehen ↗</a>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="logout"><button class="topbar-link" type="submit">Abmelden</button></form>
</header>
<div class="layout">
  <nav class="sidebar">
    <a href="<?= e(admin_url()) ?>"<?= $s === '' ? ' class="active"' : '' ?>>Übersicht</a>
    <a href="<?= e(admin_url(['s' => 'registrations'])) ?>"<?= $s === 'registrations' ? ' class="active"' : '' ?>>Anmeldungen <span class="count"><?= $regCount ?></span></a>
    <p class="nav-label">Inhalte</p>
    <?php foreach ($schema as $key => $def): ?>
      <a href="<?= e(admin_url(['s' => $key])) ?>"<?= $s === $key ? ' class="active"' : '' ?>><?= e($def['label']) ?></a>
    <?php endforeach; ?>
    <p class="nav-label">Verwaltung</p>
    <a href="<?= e(admin_url(['s' => 'media'])) ?>"<?= $s === 'media' ? ' class="active"' : '' ?>>Dateien &amp; Bilder</a>
    <a href="<?= e(admin_url(['s' => 'history'])) ?>"<?= $s === 'history' ? ' class="active"' : '' ?>>Versionen</a>
    <a href="<?= e(admin_url(['s' => 'settings'])) ?>"<?= $s === 'settings' ? ' class="active"' : '' ?>>Sicherheit &amp; Backup<?= totp_enabled() ? '' : ' <span class="count warn">!</span>' ?></a>
  </nav>
  <main class="main">
    <?php foreach (take_flash() as [$type, $msg]): ?>
      <p class="flash flash-<?= e($type) ?>"><?= e($msg) ?></p>
    <?php endforeach; ?>
    <?php
    if (isset($schema[$s])) {
        $def = $schema[$s];
        if ($def['type'] === 'object') {
            view_object($s, $def);
        } elseif (isset($_GET['i'])) {
            view_item($s, $def, (string) $_GET['i']);
        } else {
            view_list($s, $def);
        }
    } elseif ($s === 'registrations') {
        view_registrations();
    } elseif ($s === 'media') {
        view_media();
    } elseif ($s === 'settings') {
        view_settings();
    } elseif ($s === 'history') {
        view_history();
    } else {
        view_dashboard($schema, $regCount);
    }
    ?>
  </main>
</div>
</body>
</html>
    <?php
}

function public_link(string $s): string
{
    $map = ['home' => '', 'site' => '', 'conference' => 'conference', 'registration' => 'register', 'committees' => 'committees', 'team' => 'team',
        'news' => 'news', 'gallery' => 'gallery', 'faq' => 'faq', 'downloads' => 'downloads', 'sponsors' => 'sponsors', 'archive' => 'archive', 'legal' => 'imprint'];
    return isset($map[$s]) ? url($map[$s]) : '';
}

function view_dashboard(array $schema, int $regCount): void
{
    ?>
    <h1>Übersicht</h1>
    <?php if (!totp_enabled()): ?>
      <p class="flash flash-error">Zwei-Faktor-Login ist noch nicht aktiv. <a href="<?= e(admin_url(['s' => 'settings'])) ?>">Jetzt einrichten →</a></p>
    <?php endif; ?>
    <?php $purgeAt = registrations_purge_at(); ?>
    <?php if ($regCount && $purgeAt && $purgeAt > time() && $purgeAt - time() < 14 * 86400): ?>
      <p class="flash flash-error">Die Anmeldungen werden am <?= e(date('d.m.Y', $purgeAt)) ?> automatisch gelöscht. Bei Bedarf vorher <a href="<?= e(admin_url(['s' => 'registrations'])) ?>">als CSV sichern</a>.</p>
    <?php endif; ?>
    <div class="tiles">
      <a class="tile" href="<?= e(admin_url(['s' => 'registrations'])) ?>"><strong><?= $regCount ?></strong><span>Anmeldungen</span></a>
      <a class="tile" href="<?= e(admin_url(['s' => 'registration'])) ?>"><strong><?= c('registration.open') ? 'offen' : 'zu' ?></strong><span>Anmeldung</span></a>
      <a class="tile" href="<?= e(admin_url(['s' => 'conference'])) ?>"><strong><?= e(format_date(c('conference.date_start'), 'd.m.Y')) ?: '–' ?></strong><span>Konferenzbeginn</span></a>
      <a class="tile" href="<?= e(admin_url(['s' => 'media'])) ?>"><strong><?= count(media_files()) ?></strong><span>Dateien</span></a>
    </div>
    <h2>Inhalte bearbeiten</h2>
    <div class="section-links">
      <?php foreach ($schema as $key => $def): ?>
        <a href="<?= e(admin_url(['s' => $key])) ?>">
          <strong><?= e($def['label']) ?></strong>
          <span><?= $def['type'] === 'list' ? count(c($key, [])) . ' Einträge' : 'Texte & Einstellungen' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
}

function view_object(string $s, array $def): void
{
    $data = c($s, []);
    ?>
    <div class="page-title">
      <h1><?= e($def['label']) ?></h1>
      <?php if ($link = public_link($s)): ?><a href="<?= e($link) ?>" target="_blank">Auf der Website ansehen ↗</a><?php endif; ?>
    </div>
    <form class="edit-form" method="post" enctype="multipart/form-data" action="<?= e(admin_url(['s' => $s])) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="a" value="save">
      <?php foreach ($def['fields'] as $f): ?>
        <?= field_html($f, $data[$f['key']] ?? '', 'f[' . $f['key'] . ']', $f['key'], 'f-' . $f['key']) ?>
      <?php endforeach; ?>
      <div class="save-bar"><button class="btn" type="submit">Speichern</button></div>
    </form>
    <?php
}

function view_list(string $s, array $def): void
{
    $list = c($s, []);
    $tf = $def['title_field'];
    ?>
    <div class="page-title">
      <h1><?= e($def['label']) ?></h1>
      <?php if ($link = public_link($s)): ?><a href="<?= e($link) ?>" target="_blank">Auf der Website ansehen ↗</a><?php endif; ?>
    </div>
    <p><a class="btn" href="<?= e(admin_url(['s' => $s, 'i' => 'new'])) ?>">+ Neuer Eintrag</a></p>
    <?php if (!$list): ?>
      <p class="empty">Noch keine Einträge. Dieser Bereich wird auf der Website erst angezeigt, wenn es Einträge gibt.</p>
    <?php else: ?>
    <ul class="item-list">
      <?php foreach ($list as $n => $item): ?>
        <?php $thumb = '';
        foreach ($def['fields'] as $f) {
            if ($f['type'] === 'image' && !empty($item[$f['key']])) {
                $thumb = $item[$f['key']];
                break;
            }
        } ?>
        <li>
          <?php if ($thumb): ?><img src="<?= e(media($thumb)) ?>" alt=""><?php endif; ?>
          <a class="item-title" href="<?= e(admin_url(['s' => $s, 'i' => $item['id']])) ?>">
            <?= e(($item[$tf] ?? '') !== '' ? $item[$tf] : '(ohne Titel)') ?>
            <?php if (isset($item['published']) && !$item['published']): ?><span class="badge">Entwurf</span><?php endif; ?>
            <?php if (!empty($item['date'])): ?><small><?= e(format_date($item['date'], 'd.m.Y')) ?></small><?php endif; ?>
            <?php if (!empty($item['role'])): ?><small><?= e($item['role']) ?></small><?php endif; ?>
          </a>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="a" value="move_item"><input type="hidden" name="id" value="<?= e($item['id']) ?>">
            <button class="icon" name="dir" value="up" title="Nach oben"<?= $n === 0 ? ' disabled' : '' ?>>↑</button><button class="icon" name="dir" value="down" title="Nach unten"<?= $n === count($list) - 1 ? ' disabled' : '' ?>>↓</button></form>
          <form method="post" class="inline" data-confirm="„<?= e($item[$tf] ?? '') ?>“ wirklich löschen?"><?= csrf_field() ?><input type="hidden" name="a" value="delete_item"><input type="hidden" name="id" value="<?= e($item['id']) ?>">
            <button class="icon danger" title="Löschen">✕</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php
}

function view_item(string $s, array $def, string $id): void
{
    $item = null;
    foreach (c($s, []) as $x) {
        if (($x['id'] ?? '') === $id) {
            $item = $x;
        }
    }
    $isNew = $item === null;
    if ($isNew) {
        $item = [];
        foreach ($def['fields'] as $f) {
            if (isset($f['default'])) {
                $item[$f['key']] = $f['default'];
            }
        }
    }
    ?>
    <p class="back"><a href="<?= e(admin_url(['s' => $s])) ?>">← <?= e($def['label']) ?></a></p>
    <div class="page-title">
      <h1><?= $isNew ? 'Neuer Eintrag' : e($item[$def['title_field']] ?? 'Bearbeiten') ?></h1>
      <?php if (!$isNew && !empty($item['slug'])): ?>
        <a href="<?= e(url(($s === 'pages' ? '' : $s . '/') . $item['slug'])) ?>" target="_blank">Ansehen ↗</a>
      <?php endif; ?>
    </div>
    <form class="edit-form" method="post" enctype="multipart/form-data" action="<?= e(admin_url(['s' => $s])) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="a" value="save">
      <input type="hidden" name="id" value="<?= e($isNew ? '' : $id) ?>">
      <?php foreach ($def['fields'] as $f): ?>
        <?= field_html($f, $item[$f['key']] ?? '', 'f[' . $f['key'] . ']', $f['key'], 'f-' . $f['key']) ?>
      <?php endforeach; ?>
      <?php if (!empty($def['slug_from'])): ?>
        <div class="field"><label for="f-slug">Adresse (URL)</label>
          <div class="slug-row"><span><?= e(url($s === 'pages' ? '' : $s . '/')) ?></span><input id="f-slug" name="slug" value="<?= e($item['slug'] ?? '') ?>" placeholder="wird automatisch aus dem Titel erzeugt"></div></div>
      <?php endif; ?>
      <div class="save-bar"><button class="btn" type="submit">Speichern</button><a href="<?= e(admin_url(['s' => $s])) ?>">Abbrechen</a></div>
    </form>
    <?php
}

function view_registrations(): void
{
    $regs = array_reverse(read_json(REGISTRATIONS_FILE));
    $fields = registration_fields();
    $byRole = array_count_values(array_map(fn ($r) => $r['role'] ?: '–', $regs));
    $byCommittee = array_count_values(array_map(fn ($r) => $r['committee_1'] ?: '–', $regs));
    arsort($byCommittee);
    ?>
    <div class="page-title">
      <h1>Anmeldungen</h1>
      <?php if ($regs): ?><a class="btn" href="<?= e(admin_url(['s' => 'registrations_csv'])) ?>">Als Excel/CSV herunterladen</a><?php endif; ?>
    </div>
    <?php $purgeAt = registrations_purge_at(); ?>
    <p class="help">
      <?php if ($purgeAt): ?>
        Automatische Löschung: am <strong><?= e(date('d.m.Y', $purgeAt)) ?></strong> (<?= (int) c('registration.retention_days') ?> Tage nach Konferenzende) werden alle Anmeldungen zu dieser Konferenz gelöscht.
      <?php else: ?>
        Automatische Löschung ist aus.
      <?php endif; ?>
      Einstellbar unter <a href="<?= e(admin_url(['s' => 'registration'])) ?>">Anmeldung</a>.
    </p>
    <?php if (!$regs): ?>
      <p class="empty">Noch keine Anmeldungen.</p>
      <?php return; ?>
    <?php endif; ?>
    <div class="summary">
      <div><h3>Nach Teilnahmeart</h3><ul><?php foreach ($byRole as $k => $n): ?><li><span><?= e((string) $k) ?></span><strong><?= $n ?></strong></li><?php endforeach; ?></ul></div>
      <div><h3>Erstwunsch Gremium</h3><ul><?php foreach ($byCommittee as $k => $n): ?><li><span><?= e((string) $k) ?></span><strong><?= $n ?></strong></li><?php endforeach; ?></ul></div>
    </div>
    <input type="search" class="filter" placeholder="Suchen (Name, Schule, E-Mail …)" data-filter=".reg">
    <div class="regs">
      <?php foreach ($regs as $r): ?>
        <details class="reg">
          <summary>
            <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong>
            <span><?= e($r['school']) ?> · <?= e($r['role']) ?></span>
            <small><?= e(date('d.m.Y H:i', strtotime($r['created']))) ?></small>
          </summary>
          <dl>
            <?php foreach ($fields as $k => [$label]): ?>
              <?php if (($r[$k] ?? '') !== ''): ?><dt><?= e($label) ?></dt><dd><?= nl2br(e($r[$k])) ?></dd><?php endif; ?>
            <?php endforeach; ?>
          </dl>
          <form method="post" data-confirm="Anmeldung wirklich löschen?"><?= csrf_field() ?><input type="hidden" name="a" value="reg_delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn-ghost danger">Anmeldung löschen</button></form>
        </details>
      <?php endforeach; ?>
    </div>
    <form class="danger-zone" method="post" data-confirm="Wirklich ALLE Anmeldungen löschen? Das kann nicht rückgängig gemacht werden.">
      <?= csrf_field() ?><input type="hidden" name="a" value="reg_delete_all">
      <h3>Alle Anmeldungen löschen</h3>
      <p>Laut Datenschutzerklärung müssen die Daten nach der Konferenz gelöscht werden. Vorher ggf. als CSV sichern.</p>
      <label>Zum Bestätigen <code>LÖSCHEN</code> eintippen: <input name="confirm" autocomplete="off"></label>
      <button class="btn danger">Alle löschen</button>
    </form>
    <?php
}

function view_media(): void
{
    $files = media_files(null, true);
    ?>
    <div class="page-title"><h1>Dateien &amp; Bilder</h1></div>
    <form class="upload-box" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="a" value="media_upload">
      <label>Dateien hochladen (Bilder, PDF, Office-Dateien – mehrere gleichzeitig möglich)
        <input type="file" name="files[]" multiple></label>
      <button class="btn">Hochladen</button>
      <p class="help">Große Fotos werden automatisch verkleinert. Max. Dateigröße laut Server: <?= e(ini_get('upload_max_filesize')) ?>.
        In Texten kannst du Bilder so einbinden: <code>![Beschreibung](uploads/dateiname.jpg)</code> und Dateien so verlinken: <code>[Linktext](uploads/datei.pdf)</code></p>
    </form>
    <?php if (!$files): ?><p class="empty">Noch keine Dateien hochgeladen.</p><?php endif; ?>
    <input type="search" class="filter" placeholder="Dateien suchen …" data-filter=".media-item">
    <div class="media-grid">
      <?php foreach ($files as $f): ?>
        <?php $used = media_usage($f); ?>
        <div class="media-item">
          <a href="<?= e(media($f)) ?>" target="_blank" class="media-thumb">
            <?php if (is_image_path($f)): ?><img src="<?= e(media($f)) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(strtoupper(pathinfo($f, PATHINFO_EXTENSION))) ?></span><?php endif; ?>
          </a>
          <p class="media-name" title="<?= e(basename($f)) ?>"><?= e(basename($f)) ?></p>
          <p class="media-meta"><?= e(human_size((int) filesize(ROOT . '/' . $f))) ?><?= $used ? ' · verwendet in: ' . e(implode(', ', $used)) : ' · nicht verwendet' ?></p>
          <div class="media-actions">
            <button type="button" class="btn-ghost copy" data-copy="<?= e($f) ?>">Pfad kopieren</button>
            <form method="post" data-confirm="<?= e($used ? 'Die Datei wird noch verwendet (' . implode(', ', $used) . '). Trotzdem löschen?' : 'Datei löschen?') ?>"><?= csrf_field() ?><input type="hidden" name="a" value="media_delete"><input type="hidden" name="file" value="<?= e(basename($f)) ?>"><button class="btn-ghost danger">Löschen</button></form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

function view_settings(): void
{
    ?>
    <div class="page-title"><h1>Sicherheit &amp; Backup</h1></div>
    <?php view_2fa(); ?>
    <form class="edit-form" method="post">
      <?= csrf_field() ?><input type="hidden" name="a" value="password">
      <h2>Passwort ändern</h2>
      <div class="field"><label>Aktuelles Passwort<input type="password" name="current" autocomplete="current-password" required></label></div>
      <div class="field"><label>Neues Passwort (mind. 10 Zeichen)<input type="password" name="password" autocomplete="new-password" required minlength="10"></label></div>
      <div class="field"><label>Neues Passwort wiederholen<input type="password" name="password2" autocomplete="new-password" required minlength="10"></label></div>
      <div class="save-bar"><button class="btn">Passwort ändern</button></div>
    </form>

    <div class="edit-form">
      <h2>Backup</h2>
      <p>Lädt alle Texte und Einstellungen als Datei herunter. Bilder und Dateien sind nicht enthalten, die liegen im Ordner <code>/uploads</code> auf dem Server.</p>
      <p><a class="btn" href="<?= e(admin_url(['s' => 'export'])) ?>">Backup herunterladen</a></p>
    </div>

    <form class="edit-form" method="post" enctype="multipart/form-data" data-confirm="Alle aktuellen Inhalte werden durch das Backup ersetzt. Fortfahren?">
      <?= csrf_field() ?><input type="hidden" name="a" value="import">
      <h2>Backup wiederherstellen</h2>
      <div class="field"><label>Backup-Datei (.json)<input type="file" name="backup" accept=".json,application/json" required></label></div>
      <div class="save-bar"><button class="btn-ghost">Wiederherstellen</button></div>
    </form>
    <?php
}

function view_2fa(): void
{
    $devices = totp_devices();
    $recovery = $_SESSION['recovery_show'] ?? null;
    unset($_SESSION['recovery_show']);
    ?>
    <div class="edit-form" id="twofa">
      <h2>Zwei-Faktor-Login <?= $devices ? '<span class="pill on">aktiv</span>' : '<span class="pill off">aus</span>' ?></h2>
      <p>Zusätzlich zum Passwort wird beim Login ein 6-stelliger Code aus einer Authenticator-App verlangt
        (z. B. Google Authenticator, Microsoft Authenticator, Authy, 2FAS, Aegis). Jedes Handy wird einzeln hinzugefügt,
        der Code von <em>jedem</em> eingetragenen Gerät funktioniert. Geht ein Handy verloren, einfach nur dieses Gerät entfernen.</p>

      <?php if ($recovery): ?>
        <div class="recovery">
          <h3>Deine Notfall-Codes – jetzt sichern!</h3>
          <p>Jeder Code funktioniert <strong>einmal</strong> statt eines App-Codes, falls kein Handy verfügbar ist.
            Ausdrucken oder im Passwort-Manager speichern. Sie werden <strong>nur jetzt</strong> angezeigt.</p>
          <ul><?php foreach ($recovery as $rc): ?><li><code><?= e($rc) ?></code></li><?php endforeach; ?></ul>
          <button type="button" class="btn-ghost copy" data-copy="<?= e(implode("\n", $recovery)) ?>">Alle kopieren</button>
        </div>
      <?php endif; ?>

      <?php if ($devices): ?>
        <ul class="item-list">
          <?php foreach ($devices as $d): ?>
            <li>
              <span class="item-title"><?= e($d['name']) ?>
                <small>hinzugefügt <?= e(date('d.m.Y', strtotime($d['created']))) ?><?= !empty($d['last_used']) ? ' · zuletzt benutzt ' . e(date('d.m.Y H:i', strtotime($d['last_used']))) : '' ?></small></span>
              <form method="post" class="inline remove-device" data-confirm="„<?= e($d['name']) ?>“ entfernen?">
                <?= csrf_field() ?><input type="hidden" name="a" value="totp_remove"><input type="hidden" name="id" value="<?= e($d['id']) ?>">
                <input type="password" name="password" placeholder="Passwort" required autocomplete="current-password">
                <button class="btn-ghost danger">Entfernen</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="help">Übrige Notfall-Codes: <?= count(auth_data()['recovery'] ?? []) ?> von 8.</p>
      <?php endif; ?>

      <?php if (isset($_GET['add2fa'])): ?>
        <?php
        if (empty($_SESSION['totp_new'])) {
            $_SESSION['totp_new'] = totp_new_secret();
        }
        $secret = $_SESSION['totp_new'];
        $host = preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'omun'));
        $uri = totp_uri($secret, 'Admin (' . $host . ')', c('site.name', 'OMUN'));
        ?>
        <div class="add-device">
          <div class="qr" data-qr="<?= e($uri) ?>" aria-label="QR-Code"></div>
          <form method="post" class="add-device-form">
            <?= csrf_field() ?><input type="hidden" name="a" value="totp_add">
            <ol>
              <li>Authenticator-App öffnen und <strong>QR-Code scannen</strong>. Oder den Schlüssel von Hand eingeben:<br>
                <code class="secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></li>
              <li>Gerät benennen und den <strong>angezeigten 6-stelligen Code</strong> eingeben.</li>
            </ol>
            <div class="field"><label>Name des Geräts<input name="name" placeholder="z. B. Handy Philip" required maxlength="60"></label></div>
            <div class="field"><label>Code aus der App<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="7" class="code-input"></label></div>
            <div class="row-actions"><button class="btn">Gerät hinzufügen</button><a href="<?= e(admin_url(['s' => 'settings'])) ?>">Abbrechen</a></div>
          </form>
        </div>
        <p class="help">Mehrere Handys: Jedes Gerät einzeln hinzufügen (danach erneut auf „Weiteres Gerät hinzufügen“). Denselben QR-Code auf mehreren Handys zu scannen geht auch, dann lassen sie sich aber nicht einzeln entfernen.</p>
      <?php else: ?>
        <p><a class="btn" href="<?= e(admin_url(['s' => 'settings', 'add2fa' => 1])) ?>#twofa"><?= $devices ? '+ Weiteres Gerät hinzufügen' : 'Zwei-Faktor-Login einrichten' ?></a></p>
      <?php endif; ?>

      <?php if ($devices): ?>
        <form method="post" class="inline-form" data-confirm="Neue Notfall-Codes erstellen? Die alten werden ungültig.">
          <?= csrf_field() ?><input type="hidden" name="a" value="recovery_new">
          <input type="password" name="password" placeholder="Passwort" required autocomplete="current-password">
          <button class="btn-ghost">Neue Notfall-Codes erstellen</button>
        </form>
      <?php endif; ?>
      <p class="help">Alle Geräte und Notfall-Codes verloren? Per SFTP die Datei <code>data/auth.json</code> löschen und unter /admin ein neues Passwort setzen (2FA ist danach aus).</p>
    </div>
    <?php
}

function view_history(): void
{
    $versions = history_list();
    ?>
    <div class="page-title"><h1>Versionen</h1></div>
    <p class="help">Vor jeder Änderung an den Inhalten wird automatisch der vorherige Stand gesichert (die letzten <?= HISTORY_KEEP ?>).
      Mit „Wiederherstellen“ springen <strong>alle</strong> Texte und Einstellungen auf diesen Stand zurück. Der aktuelle Stand wird dabei vorher auch gesichert, du kannst es also rückgängig machen.
      Hochgeladene Dateien und Anmeldungen sind davon nicht betroffen.</p>
    <?php if (!$versions): ?>
      <p class="empty">Noch keine Versionen. Sie entstehen automatisch, sobald du etwas speicherst.</p>
      <?php return; ?>
    <?php endif; ?>
    <ul class="item-list">
      <?php foreach ($versions as $v): ?>
        <li>
          <span class="item-title">Stand vor: <?= e($v['label']) ?>
            <small><?= e(date('d.m.Y, H:i:s', strtotime($v['saved']))) ?> Uhr · <?= e(human_size($v['size'])) ?></small></span>
          <a class="btn-ghost" href="<?= e(admin_url(['s' => 'history_download', 'file' => $v['file']])) ?>">Download</a>
          <form method="post" class="inline" data-confirm="Alle Inhalte auf den Stand vom <?= e(date('d.m.Y, H:i', strtotime($v['saved']))) ?> zurücksetzen?">
            <?= csrf_field() ?><input type="hidden" name="a" value="history_restore"><input type="hidden" name="file" value="<?= e($v['file']) ?>">
            <button class="btn-ghost">Wiederherstellen</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php
}
