<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/admin.php';
require dirname(__DIR__) . '/lib/registration.php';
require dirname(__DIR__) . '/lib/totp.php';
require dirname(__DIR__) . '/lib/updater.php';
require dirname(__DIR__) . '/lib/resolution.php';
require dirname(__DIR__) . '/lib/stats.php';

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
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($id) {
                    foreach ($regs as $r) {
                        if (($r['id'] ?? '') === $id) {
                            delete_paper_file($r);
                        }
                    }
                    return array_values(array_filter($regs, fn ($r) => ($r['id'] ?? '') !== $id));
                });
                delete_orphan_accounts();
                flash('Anmeldung gelöscht.');
                redirect(admin_url(['s' => 'registrations']));

            case 'reg_update':
                $id = (string) ($_POST['id'] ?? '');
                $status = array_key_exists($_POST['status'] ?? '', registration_statuses()) ? $_POST['status'] : 'received';
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($id, $status) {
                    foreach ($regs as &$r) {
                        if (($r['id'] ?? '') === $id) {
                            $r['status'] = $status;
                            $r['assigned_country'] = trim(mb_substr((string) ($_POST['assigned_country'] ?? ''), 0, 100));
                            $r['assigned_committee'] = trim(mb_substr((string) ($_POST['assigned_committee'] ?? ''), 0, 150));
                            $r['admin_note'] = trim(mb_substr((string) ($_POST['admin_note'] ?? ''), 0, 2000));
                            $r['is_chair'] = !empty($_POST['is_chair']);
                            $r['is_manager'] = !empty($_POST['is_manager']);
                            if ($r['is_chair'] || $r['is_manager']) {
                                unset($r['role_pending']);
                            }
                        }
                    }
                    return $regs;
                });
                flash('Anmeldung aktualisiert.');
                redirect(admin_url(['s' => 'registrations', 'open' => $id]) . '#reg-' . rawurlencode($id));

            case 'alloc_save':
                $countries = (array) ($_POST['country'] ?? []);
                $committees = (array) ($_POST['committee'] ?? []);
                $chairs = (array) ($_POST['chair'] ?? []);
                $changed = 0;
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($countries, $committees, $chairs, &$changed) {
                    foreach ($regs as &$r) {
                        if (!array_key_exists($r['id'], $countries)) {
                            continue;
                        }
                        $before = [$r['assigned_country'] ?? '', $r['assigned_committee'] ?? '', !empty($r['is_chair'])];
                        $r['assigned_country'] = trim(mb_substr((string) $countries[$r['id']], 0, 100));
                        $r['assigned_committee'] = trim(mb_substr((string) ($committees[$r['id']] ?? ''), 0, 150));
                        $r['is_chair'] = !empty($chairs[$r['id']]);
                        if ($r['is_chair']) {
                            unset($r['role_pending']);
                        }
                        $changed += $before !== [$r['assigned_country'], $r['assigned_committee'], $r['is_chair']] ? 1 : 0;
                    }
                    return $regs;
                });
                flash($changed . ' Zuteilung' . ($changed === 1 ? '' : 'en') . ' geändert.');
                redirect(admin_url(['s' => 'allocation']));

            case 'reg_confirm_role':
                $id = (string) ($_POST['id'] ?? '');
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($id) {
                    foreach ($regs as &$r) {
                        if (($r['id'] ?? '') === $id && !empty($r['role_pending'])) {
                            unset($r['role_pending']);
                            $r['status'] = 'confirmed';
                            if (($r['kind'] ?? '') === 'manager') {
                                $r['is_manager'] = true;
                            } else {
                                $r['is_chair'] = true;
                            }
                        }
                    }
                    return $regs;
                });
                flash('Bestätigt. Die Rechte gelten ab dem nächsten Seitenaufruf der Person.');
                redirect(admin_url(['s' => 'registrations']));

            case 'staff_password':
                $pw = (string) ($_POST['password'] ?? '');
                if (!check_password((string) ($_POST['current'] ?? ''))) {
                    flash('Das Admin-Passwort ist falsch.', 'error');
                } elseif (mb_strlen($pw) < 10) {
                    flash('Das neue Passwort muss mindestens 10 Zeichen lang sein.', 'error');
                } else {
                    set_staff_password('laptops', $pw);
                    flash('Passwort des Laptop-Kontos geändert. Bereits angemeldete Laptops bleiben angemeldet, bis sie sich abmelden.');
                }
                redirect(admin_url(['s' => 'resolutions']));

            case 'reg_sendcreds':
                $email = '';
                foreach (read_json(REGISTRATIONS_FILE) as $r) {
                    if (($r['id'] ?? '') === ($_POST['id'] ?? '')) {
                        $email = $r['email'];
                    }
                }
                if ($email && send_new_credentials($email)) {
                    flash('Neue Zugangsdaten an ' . $email . ' gesendet. Das alte Passwort gilt nicht mehr.');
                } else {
                    flash('Die E-Mail konnte nicht gesendet werden. Ist der Mailversand im Hosting-Paket aktiv?', 'error');
                }
                redirect(admin_url(['s' => 'registrations']));

            case 'reg_delete_all':
                if (($_POST['confirm'] ?? '') === 'LÖSCHEN') {
                    update_json(REGISTRATIONS_FILE, function (array $regs) {
                        foreach ($regs as $r) {
                            delete_paper_file($r);
                        }
                        return [];
                    });
                    delete_orphan_accounts();
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

            case 'update':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Zum Einspielen eines Updates bitte das richtige Admin-Passwort eingeben.', 'error');
                    redirect(admin_url(['s' => 'update']));
                }
                $u = $_FILES['package'] ?? null;
                if (!$u || $u['error'] !== UPLOAD_ERR_OK) {
                    flash($u && $u['error'] !== UPLOAD_ERR_NO_FILE ? upload_error_text((int) $u['error']) : 'Bitte die Update-Zip auswählen.', 'error');
                    redirect(admin_url(['s' => 'update']));
                }
                [$count, $old, $new] = apply_update($u['tmp_name']);
                flash("Update eingespielt: Version $old → $new ($count Dateien). Inhalte, Bilder und Anmeldungen wurden nicht verändert.");
                redirect(admin_url(['s' => 'update']));

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

if ($s === 'code_backup') {
    $file = basename((string) ($_GET['file'] ?? ''));
    $path = CODE_BACKUP_DIR . '/' . $file;
    if (!preg_match('/^code-[\w.-]+\.zip$/', $file) || !is_file($path)) {
        http_response_code(404);
        exit('Nicht gefunden');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
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

if ($s === 'paper') {
    foreach (read_json(REGISTRATIONS_FILE) as $r) {
        if (($r['id'] ?? '') === ($_GET['id'] ?? '')) {
            send_paper($r);
        }
    }
    http_response_code(404);
    exit('Nicht gefunden');
}

if ($s === 'registrations_csv') {
    $regs = read_json(REGISTRATIONS_FILE);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anmeldungen-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel shows umlauts correctly
    $fields = registration_fields();
    $statuses = registration_statuses();
    fputcsv($out, array_merge(['Datum', 'Status', 'Rechte', 'Land (zugeteilt)', 'Gremium (zugeteilt)', 'Position Paper'], array_column($fields, 0), ['Notiz']), ';');
    foreach ($regs as $r) {
        $row = [
            date('d.m.Y H:i', strtotime($r['created'])),
            $statuses[$r['status'] ?? 'received'][1] ?? '',
            !empty($r['role_pending']) ? 'Bestätigung offen' : implode(', ', array_filter([!empty($r['is_chair']) ? 'Chair' : '', !empty($r['is_manager']) ? 'Conference Manager' : ''])),
            $r['assigned_country'] ?? '',
            committee_display((string) ($r['assigned_committee'] ?? '')),
            !empty($r['paper']) ? 'ja (' . date('d.m.Y', strtotime($r['paper']['uploaded'])) . ')' : 'nein',
        ];
        foreach ($fields as $k => $_) {
            $v = (string) ($r[$k] ?? '');
            $row[] = preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; // no formula injection
        }
        $row[] = $r['admin_note'] ?? '';
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
    $titles = ['' => 'Übersicht', 'registrations' => 'Anmeldungen', 'media' => 'Dateien & Bilder', 'history' => 'Versionen', 'settings' => 'Sicherheit & Backup', 'update' => 'Update', 'resolutions' => 'Resolutionen', 'allocation' => 'Zuteilung', 'stats' => 'Besucher'];
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
    <a href="<?= e(admin_url(['s' => 'stats'])) ?>"<?= $s === 'stats' ? ' class="active"' : '' ?>>Besucher</a>
    <a href="<?= e(admin_url(['s' => 'allocation'])) ?>"<?= $s === 'allocation' ? ' class="active"' : '' ?>>Zuteilung</a>
    <a href="<?= e(admin_url(['s' => 'resolutions'])) ?>"<?= $s === 'resolutions' ? ' class="active"' : '' ?>>Resolutionen</a>
    <p class="nav-label">Inhalte</p>
    <?php foreach ($schema as $key => $def): ?>
      <a href="<?= e(admin_url(['s' => $key])) ?>"<?= $s === $key ? ' class="active"' : '' ?>><?= e($def['label']) ?></a>
    <?php endforeach; ?>
    <p class="nav-label">Verwaltung</p>
    <a href="<?= e(admin_url(['s' => 'media'])) ?>"<?= $s === 'media' ? ' class="active"' : '' ?>>Dateien &amp; Bilder</a>
    <a href="<?= e(admin_url(['s' => 'update'])) ?>"<?= $s === 'update' ? ' class="active"' : '' ?>>Update <span class="count"><?= e(current_version()) ?></span></a>
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
    } elseif ($s === 'stats') {
        view_stats();
    } elseif ($s === 'allocation') {
        view_allocation();
    } elseif ($s === 'media') {
        view_media();
    } elseif ($s === 'settings') {
        view_settings();
    } elseif ($s === 'history') {
        view_history();
    } elseif ($s === 'update') {
        view_update();
    } elseif ($s === 'resolutions') {
        view_resolutions();
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
        'news' => 'news', 'gallery' => 'gallery', 'faq' => 'faq', 'downloads' => 'downloads', 'sponsors' => 'sponsors', 'archive' => 'archive', 'legal' => 'imprint', 'portal' => 'login'];
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
      <?php $week = stats_summary(7); ?>
      <a class="tile" href="<?= e(admin_url(['s' => 'stats'])) ?>"><strong><?= number_format($week['visitors'], 0, ',', '.') ?></strong><span>Besucher (7 Tage)</span></a>
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

/** Dropdown of all committees (value = slug), keeping an assignment whose committee no longer exists. */
function committee_select(string $name, string $current): string
{
    $sel = committee_by_label($current);
    $h = '<select name="' . e($name) . '"><option value="">– noch keins –</option>';
    foreach (c('committees', []) as $cm) {
        $h .= '<option value="' . e($cm['slug']) . '"' . ($sel && $sel['slug'] === $cm['slug'] ? ' selected' : '') . '>' . e(committee_display($cm['slug'])) . '</option>';
    }
    if ($current !== '' && !$sel) {
        $h .= '<option value="' . e($current) . '" selected>' . e($current) . ' (gibt es nicht mehr)</option>';
    }
    return $h . '</select>';
}

function view_stats(): void
{
    $ranges = [7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '12 Monate'];
    $days = array_key_exists((int) ($_GET['days'] ?? 30), $ranges) ? (int) ($_GET['days'] ?? 30) : 30;
    $st = stats_summary($days);
    $n = fn (int $x) => number_format($x, 0, ',', '.');
    $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    // Bars: one per day, for a year one per week.
    $buckets = [];
    foreach ($st['days'] as $date => $d) {
        $ts = strtotime($date);
        $key = $days > 90 ? date('o-W', $ts) : $date;
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['label' => $days > 90 ? 'Woche ab ' . date('d.m.', $ts) : $wd[(int) date('w', $ts)] . ' ' . date('d.m.', $ts), 'short' => date('d.m.', $ts), 'v' => 0, 'u' => 0];
        }
        $buckets[$key]['v'] += $d['v'];
        $buckets[$key]['u'] += $d['u'];
    }
    $buckets = array_values($buckets);
    $max = max(1, ...array_column($buckets, 'v'));
    $step = $max <= 5 ? 1 : (int) (10 ** floor(log10($max)) * ($max / 10 ** floor(log10($max)) > 5 ? 2 : 1));
    $top = (int) (ceil($max / $step) * $step);
    $labelEvery = max(1, (int) ceil(count($buckets) / 8));
    $pageNames = ['/' => 'Startseite'];
    ?>
    <div class="page-title"><h1>Besucher</h1>
      <nav class="range-tabs" aria-label="Zeitraum"><?php foreach ($ranges as $k => $label): ?><a href="<?= e(admin_url(['s' => 'stats', 'days' => $k])) ?>"<?= $k === $days ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
    </div>
    <p class="help">Gezählt werden Aufrufe der öffentlichen Seiten, ohne Cookies und ohne gespeicherte IP-Adressen. Admins, Suchmaschinen und Bots zählen nicht mit.
      „Besucher“ = verschiedene Personen pro Tag (über mehrere Tage zusammengezählt, wer an zwei Tagen kommt, zählt zweimal).</p>

    <div class="tiles">
      <div class="tile"><strong><?= $n($st['visitors']) ?></strong><span>Besucher</span></div>
      <div class="tile"><strong><?= $n($st['views']) ?></strong><span>Seitenaufrufe</span></div>
      <div class="tile"><strong><?= $st['visitors'] ? number_format($st['views'] / $st['visitors'], 1, ',', '.') : '–' ?></strong><span>Seiten pro Besuch</span></div>
      <div class="tile"><strong><?= $st['views'] ? round($st['mobile'] / $st['views'] * 100) . ' %' : '–' ?></strong><span>vom Handy</span></div>
    </div>

    <section class="panel stats-chart">
      <h2>Seitenaufrufe <?= $days > 90 ? 'pro Woche' : 'pro Tag' ?></h2>
      <?php if (!$st['views']): ?>
        <p class="empty">In diesem Zeitraum noch keine Aufrufe gezählt.</p>
      <?php else: ?>
      <div class="chart" role="img" aria-label="Seitenaufrufe <?= $days > 90 ? 'pro Woche' : 'pro Tag' ?>, höchster Wert <?= $max ?>">
        <div class="chart-grid" aria-hidden="true">
          <?php foreach ([1, 0.5, 0] as $f): ?><span style="bottom: <?= $f * 100 ?>%"><em><?= $n((int) round($top * $f)) ?></em></span><?php endforeach; ?>
        </div>
        <div class="chart-bars">
          <?php foreach ($buckets as $i => $b): ?>
            <div class="chart-col" tabindex="0" data-tip="<?= e($b['label'] . ': ' . $n($b['v']) . ' Aufrufe · ' . $n($b['u']) . ' Besucher') ?>">
              <span class="chart-bar" style="height: <?= round($b['v'] / $top * 100, 2) ?>%"></span>
              <?php if ($i % $labelEvery === 0): ?><span class="chart-x"><?= e($b['short']) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <details class="chart-table"><summary>Als Tabelle anzeigen</summary>
        <table><thead><tr><th><?= $days > 90 ? 'Woche' : 'Tag' ?></th><th>Aufrufe</th><th>Besucher</th></tr></thead><tbody>
          <?php foreach (array_reverse($buckets) as $b): ?><tr><td><?= e($b['label']) ?></td><td><?= $n($b['v']) ?></td><td><?= $n($b['u']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      </details>
      <?php endif; ?>
    </section>

    <div class="stats-lists">
      <section class="panel">
        <h2>Meistbesuchte Seiten</h2>
        <?php if (!$st['pages']): ?><p class="empty">–</p><?php else: ?>
        <ol class="rank"><?php $pmax = max($st['pages']); foreach (array_slice($st['pages'], 0, 12, true) as $path => $cnt): ?>
          <li><span class="rank-bar" style="width: <?= round($cnt / $pmax * 100) ?>%"></span><a href="<?= e(url(ltrim((string) $path, '/'))) ?>" target="_blank"><?= e($pageNames[$path] ?? $path) ?></a><strong><?= $n($cnt) ?></strong></li>
        <?php endforeach; ?></ol><?php endif; ?>
      </section>
      <section class="panel">
        <h2>Woher die Besucher kommen</h2>
        <?php if (!$st['refs']): ?><p class="empty">Bisher nur direkte Aufrufe (Adresse eingetippt, Lesezeichen, Apps).</p><?php else: ?>
        <ol class="rank"><?php $rmax = max($st['refs']); foreach (array_slice($st['refs'], 0, 12, true) as $host => $cnt): ?>
          <li><span class="rank-bar" style="width: <?= round($cnt / $rmax * 100) ?>%"></span><span><?= e((string) $host) ?></span><strong><?= $n($cnt) ?></strong></li>
        <?php endforeach; ?></ol>
        <p class="help">Aufrufe ohne Herkunft (Adresse eingetippt, Lesezeichen, manche Apps) sind hier nicht aufgeführt.</p><?php endif; ?>
      </section>
    </div>
    <?php
}

function view_allocation(): void
{
    $regs = read_json(REGISTRATIONS_FILE);
    usort($regs, fn ($a, $b) => [committee_display((string) ($a['assigned_committee'] ?? '')) ?: 'zzz', $a['last_name']] <=> [committee_display((string) ($b['assigned_committee'] ?? '')) ?: 'zzz', $b['last_name']]);
    ?>
    <div class="page-title"><h1>Zuteilung</h1></div>
    <p class="help">Land und Gremium für alle auf einmal ändern – auch nach der Bestätigung. Die Änderung gilt sofort (Teilnehmer-Bereich und Resolution Editor).
      Die Liste der Gremien selbst (Namen, Abkürzungen, neue Gremien) bearbeitet ihr unter <a href="<?= e(admin_url(['s' => 'committees'])) ?>">Gremien</a>; Zuteilungen bleiben beim Umbenennen erhalten.</p>
    <?php if (!$regs): ?><p class="empty">Noch keine Anmeldungen.</p><?php return; endif; ?>
    <input type="search" class="filter" placeholder="Suchen (Name, Land, Gremium …)" data-filter=".alloc-table tbody tr">
    <form method="post" class="alloc-form">
      <?= csrf_field() ?><input type="hidden" name="a" value="alloc_save">
      <div class="table-wrap"><table class="alloc-table">
        <thead><tr><th>Name</th><th>Teilnahme</th><th>Wünsche</th><th>Land</th><th>Gremium</th><th>Chair</th></tr></thead>
        <tbody>
        <?php foreach ($regs as $r): $id = e($r['id']); ?>
          <tr<?= ($r['status'] ?? '') === 'cancelled' ? ' class="cancelled"' : '' ?>>
            <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><?= ($r['status'] ?? '') === 'cancelled' ? ' <small>(abgesagt)</small>' : '' ?></td>
            <td><?= e($r['role'] ?? '') ?><?= !empty($r['role_pending']) ? ' <small>(Bestätigung offen)</small>' : '' ?></td>
            <td><small><?= e(implode(' / ', array_filter([$r['committee_1'] ?? '', $r['committee_2'] ?? '', $r['country_wishes'] ?? '']))) ?></small></td>
            <td><input name="country[<?= $id ?>]" value="<?= e($r['assigned_country'] ?? '') ?>" aria-label="Land"></td>
            <td><?= committee_select('committee[' . $r['id'] . ']', (string) ($r['assigned_committee'] ?? '')) ?></td>
            <td><input type="checkbox" name="chair[<?= $id ?>]" value="1"<?= !empty($r['is_chair']) ? ' checked' : '' ?> aria-label="Chair"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="save-bar"><button class="btn">Alle Zuteilungen speichern</button></div>
    </form>
    <?php
}

function view_registrations(): void
{
    $regs = array_reverse(read_json(REGISTRATIONS_FILE));
    $fields = registration_fields();
    $byRole = array_count_values(array_map(fn ($r) => $r['role'] ?: '–', $regs));
    $byCommittee = array_count_values(array_map(fn ($r) => ($r['committee_1'] ?? '') ?: '–', $regs));
    arsort($byCommittee);
    $statuses = registration_statuses();
    $byStatus = array_count_values(array_map(fn ($r) => $statuses[$r['status'] ?? 'received'][1] ?? '–', $regs));
    $papers = count(array_filter($regs, fn ($r) => !empty($r['paper'])));
    $committeeOptions = array_map(fn ($cm) => trim(($cm['abbr'] ?? '') . ' – ' . $cm['name'], ' –'), c('committees', []));
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
    <?php $pending = array_filter($regs, fn ($r) => !empty($r['role_pending'])); ?>
    <?php if ($pending): ?>
      <div class="pending-box">
        <h2>Warten auf Bestätigung (<?= count($pending) ?>)</h2>
        <p class="help">Chairs bekommen nach der Bestätigung Chair-Rechte für ihr gewähltes Gremium, Conference Manager können alle Resolutionen ansehen (nicht bearbeiten). Zugangsdaten haben sie schon per Mail bekommen. Nicht bestätigen = Anmeldung löschen oder auf „Abgesagt“ setzen.</p>
        <ul>
          <?php foreach ($pending as $r): ?>
            <li>
              <span><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong> · <?= e(($r['kind'] ?? '') === 'manager' ? 'Conference Manager' : 'Chair' . (($r['assigned_committee'] ?? '') !== '' ? ' – ' . committee_display($r['assigned_committee']) : '')) ?> · <?= e($r['email']) ?></span>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="reg_confirm_role"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn">Bestätigen</button></form>
              <a class="btn-ghost" href="<?= e(admin_url(['s' => 'registrations', 'open' => $r['id']])) ?>#reg-<?= e($r['id']) ?>">Details</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <div class="summary">
      <div><h3>Nach Teilnahmeart</h3><ul><?php foreach ($byRole as $k => $n): ?><li><span><?= e((string) $k) ?></span><strong><?= $n ?></strong></li><?php endforeach; ?></ul></div>
      <div><h3>Status</h3><ul><?php foreach ($byStatus as $k => $n): ?><li><span><?= e((string) $k) ?></span><strong><?= $n ?></strong></li><?php endforeach; ?>
        <li><span>Position Papers abgegeben</span><strong><?= $papers ?></strong></li></ul></div>
      <div><h3>Erstwunsch Gremium</h3><ul><?php foreach ($byCommittee as $k => $n): ?><li><span><?= e((string) $k) ?></span><strong><?= $n ?></strong></li><?php endforeach; ?></ul></div>
    </div>
    <input type="search" class="filter" placeholder="Suchen (Name, Schule, E-Mail …)" data-filter=".reg">
    <div class="regs">
      <?php foreach ($regs as $r): ?>
        <?php $st = $r['status'] ?? 'received'; ?>
        <details class="reg" id="reg-<?= e($r['id']) ?>"<?= ($_GET['open'] ?? '') === $r['id'] ? ' open' : '' ?>>
          <summary>
            <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?><?= !empty($r['is_chair']) ? ' <span class="st st-paper">Chair</span>' : '' ?><?= !empty($r['is_manager']) ? ' <span class="st st-paper">Conf. Manager</span>' : '' ?><?= !empty($r['role_pending']) ? ' <span class="st st-waitlist">Bestätigung offen</span>' : '' ?> <span class="st st-<?= e($st) ?>"><?= e($statuses[$st][1] ?? $st) ?></span><?= !empty($r['paper']) ? ' <span class="st st-paper">Paper</span>' : '' ?></strong>
            <span><?= e(implode(' · ', array_filter([$r['school'] ?? '', $r['role'] ?? '']))) ?><?= !empty($r['assigned_country']) ? ' · ' . e($r['assigned_country']) : '' ?><?= !empty($r['assigned_committee']) ? ' (' . e(committee_display($r['assigned_committee'])) . ')' : '' ?></span>
            <small><?= e(date('d.m.Y H:i', strtotime($r['created']))) ?></small>
          </summary>
          <dl>
            <?php foreach ($fields as $k => [$label]): ?>
              <?php if (($r[$k] ?? '') !== ''): ?><dt><?= e($label) ?></dt><dd><?= nl2br(e($r[$k])) ?></dd><?php endif; ?>
            <?php endforeach; ?>
          </dl>
          <form method="post" class="reg-edit">
            <?= csrf_field() ?><input type="hidden" name="a" value="reg_update"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <label>Status<select name="status"><?php foreach ($statuses as $key => [, $label]): ?><option value="<?= e($key) ?>"<?= $st === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label>Land (zugeteilt)<input name="assigned_country" value="<?= e($r['assigned_country'] ?? '') ?>" placeholder="z. B. Brazil"></label>
            <label>Gremium (zugeteilt)<?= committee_select('assigned_committee', (string) ($r['assigned_committee'] ?? '')) ?></label>
            <label class="check-inline"><input type="checkbox" name="is_chair" value="1"<?= !empty($r['is_chair']) ? ' checked' : '' ?>> Chair dieses Gremiums (darf Resolution bearbeiten, Amendments entscheiden, Beamer-Ansicht)</label>
            <label class="check-inline"><input type="checkbox" name="is_manager" value="1"<?= !empty($r['is_manager']) ? ' checked' : '' ?>> Conference Manager (sieht alle Resolutionen und die Beamer-Ansicht, darf nichts ändern)</label>
            <label class="wide">Interne Notiz (nur im Admin sichtbar)<textarea name="admin_note" rows="2"><?= e($r['admin_note'] ?? '') ?></textarea></label>
            <button class="btn">Speichern</button>
          </form>
          <div class="reg-actions">
            <?php if (!empty($r['paper'])): ?>
              <a class="btn-ghost" href="<?= e(admin_url(['s' => 'paper', 'id' => $r['id']])) ?>">Position Paper herunterladen (<?= e(human_size((int) $r['paper']['size'])) ?>, <?= e(date('d.m.Y', strtotime($r['paper']['uploaded']))) ?>)</a>
            <?php endif; ?>
            <form method="post" data-confirm="Neues Passwort erzeugen und an <?= e($r['email']) ?> schicken? Das bisherige Passwort gilt dann nicht mehr."><?= csrf_field() ?><input type="hidden" name="a" value="reg_sendcreds"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn-ghost">Neue Zugangsdaten senden</button></form>
            <form method="post" data-confirm="Anmeldung wirklich löschen? Ein hochgeladenes Position Paper wird mitgelöscht."><?= csrf_field() ?><input type="hidden" name="a" value="reg_delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn-ghost danger">Anmeldung löschen</button></form>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
    <datalist id="committee-options"><?php foreach ($committeeOptions as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist>
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

function view_update(): void
{
    $backups = code_backups();
    ?>
    <div class="page-title"><h1>Update</h1><span class="muted">Installierte Version: <strong><?= e(current_version()) ?></strong></span></div>
    <form class="edit-form" method="post" enctype="multipart/form-data" data-confirm="Update jetzt einspielen?">
      <?= csrf_field() ?><input type="hidden" name="a" value="update">
      <h2>Neue Version einspielen</h2>
      <p>Die Update-Zip hier hochladen – der Server ersetzt die Programmdateien selbst.
        <strong>Texte, Einstellungen, Passwort, Bilder und Anmeldungen</strong> (Ordner <code>data/</code> und <code>uploads/</code>)
        werden dabei <strong>nie</strong> verändert, auch wenn sie in der Zip enthalten sind.
        Vorher wird die aktuelle Version automatisch gesichert.</p>
      <div class="field"><label>Update-Zip<input type="file" name="package" accept=".zip,application/zip" required></label></div>
      <div class="field"><label>Admin-Passwort zur Bestätigung<input type="password" name="password" required autocomplete="current-password"></label></div>
      <div class="save-bar"><button class="btn">Update einspielen</button></div>
    </form>

    <div class="edit-form">
      <h2>Sicherungen der vorherigen Versionen</h2>
      <?php if (!$backups): ?>
        <p class="muted">Noch keine – eine Sicherung entsteht automatisch bei jedem Update.</p>
      <?php else: ?>
        <p>Falls nach einem Update etwas nicht funktioniert: Sicherung herunterladen und oben wieder als Update einspielen.</p>
        <ul class="item-list">
          <?php foreach ($backups as $b): ?>
            <li><span class="item-title"><?= e($b['file']) ?><small><?= e(date('d.m.Y, H:i', $b['time'])) ?> Uhr · <?= e(human_size($b['size'])) ?></small></span>
              <a class="btn-ghost" href="<?= e(admin_url(['s' => 'code_backup', 'file' => $b['file']])) ?>">Download</a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
}

function view_resolutions(): void
{
    $base = split_portal() ? portal_home() : site_origin() . url('portal/');
    ?>
    <div class="page-title"><h1>Resolutionen</h1></div>
    <p class="help">Pro Gremium wird immer an einer Resolution gearbeitet; ist sie fertig, speichern die Chairs sie mit „Save &amp; start new resolution“ ab und beginnen eine neue. Chairs legt ihr unter <a href="<?= e(admin_url(['s' => 'registrations'])) ?>">Anmeldungen</a> fest (Häkchen „Chair“ + zugeteiltes Gremium).
      Als Admin habt ihr in jedem Gremium Chair-Rechte und könnt die Beamer-Ansicht öffnen.
      Conference Manager und das Laptop-Konto sehen alle Gremien inkl. Beamer-Ansicht, können aber nichts ändern.</p>
    <?php if (!c('committees', [])): ?><p class="empty">Noch keine Gremien angelegt.</p><?php endif; ?>
    <ul class="item-list">
      <?php foreach (c('committees', []) as $cm): ?>
        <?php
        $res = res_load($cm);
        $members = committee_members($cm);
        $chairs = array_filter($members, fn ($m) => !empty($m['is_chair']));
        $open = count(array_filter($res['amendments'], fn ($a) => in_array($a['status'], ['pending', 'floor'], true)));
        $link = $base . 'resolution?c=' . rawurlencode($cm['slug']);
        $done = res_archive_list($cm['slug']);
        ?>
        <li>
          <span class="item-title"><?= e(($cm['abbr'] ?? '') ? $cm['abbr'] . ' – ' : '') ?><?= e($cm['name']) ?>
            <small><?= e(status_label($res['status'])) ?> · <?= count($res['clauses']) ?> Klauseln · <?= $open ?> offene Amendments · <?= count($members) - count($chairs) ?> Delegierte · <?= count($chairs) ?> Chairs<?= $done ? ' · ' . count($done) . ' fertige Resolution' . (count($done) === 1 ? '' : 'en') : '' ?></small>
            <?php foreach ($done as $old): ?><small><a href="<?= e($link) ?>&amp;view=print&amp;res=<?= e($old['id']) ?>" target="_blank">Resolution <?= (int) ($old['number'] ?? 1) ?>: <?= e($old['topic'] ?: 'ohne Thema') ?> (<?= e($old['outcome'] ?? '') ?>)</a></small><?php endforeach; ?></span>
          <a class="btn-ghost" href="<?= e($link) ?>" target="_blank">Öffnen</a>
          <a class="btn-ghost" href="<?= e($link) ?>&amp;view=screen" target="_blank">Beamer</a>
          <a class="btn-ghost" href="<?= e($link) ?>&amp;view=print" target="_blank">PDF</a>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" class="panel staff-box" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="a" value="staff_password">
      <h2>Laptop-Konto (Beamer)</h2>
      <p class="help">Festes Konto für die Konferenz-Laptops: Login unter <strong><?= e(site_origin() . url('login')) ?></strong> mit Benutzername <strong>laptops</strong>.
        Es sieht alle Gremien und die Beamer-Ansicht, kann aber nichts ändern. <?= isset(read_json(STAFF_FILE)['laptops']) ? 'Passwort zuletzt geändert am ' . e(date('d.m.Y', strtotime(read_json(STAFF_FILE)['laptops']['changed'] ?? 'now'))) . '.' : 'Es gilt noch das Start-Passwort.' ?></p>
      <div class="row-fields">
        <label>Neues Passwort für „laptops“ (mind. 10 Zeichen)<input type="text" name="password" minlength="10" required autocomplete="off"></label>
        <label>Dein Admin-Passwort zur Bestätigung<input type="password" name="current" required autocomplete="current-password"></label>
      </div>
      <button class="btn">Passwort ändern</button>
    </form>
    <?php
}
