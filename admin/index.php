<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/admin.php';
require dirname(__DIR__) . '/lib/registration.php';
require_once dirname(__DIR__) . '/lib/allocation.php';
require_once dirname(__DIR__) . '/lib/codes_api.php';
require dirname(__DIR__) . '/lib/totp.php';
require dirname(__DIR__) . '/lib/updater.php';
require dirname(__DIR__) . '/lib/deploy.php';
require dirname(__DIR__) . '/lib/hub.php';
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
            audit('admin.setup');
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
            audit('admin.login', '2fa');
            redirect(admin_url());
        } else {
            audit_error('admin.2fa_failed', 'admin');
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
            audit_error('admin.login_rate_limited', 'admin');
            $error = 'Zu viele Versuche. Bitte 15 Minuten warten.';
        } elseif (check_password((string) ($_POST['password'] ?? ''))) {
            if (totp_enabled()) {
                session_regenerate_id(true);
                $_SESSION['2fa'] = ['v' => auth_data()['v'], 't' => time()];
                redirect(admin_url());
            }
            login_session(auth_data()['v']);
            audit('admin.login', 'password');
            redirect(admin_url(array_filter(['s' => $s])));
        } else {
            audit_error('admin.login_failed', 'admin');
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
                audit('admin.logout');
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
                    audit('content.save', $def['label']);
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
                audit($found ? 'content.item_update' : 'content.item_create', $def['label'] . ': ' . ($item[$def['title_field']] ?? ''));
                foreach ($errors as $er) {
                    flash($er, 'error');
                }
                flash('Gespeichert.');
                redirect(admin_url(['s' => $s, 'i' => $item['id']]));

            case 'save_page':
                // Texts of a list page itself (e.g. the intro above the committees).
                $page = $schema[$s]['page'] ?? null;
                if (!$page) {
                    redirect(admin_url());
                }
                $errors = [];
                $in = $_POST['p'] ?? [];
                foreach ($page['fields'] as $f) {
                    $content[$page['key']][$f['key']] = field_value($f, $in[$f['key']] ?? '', 'p_' . $f['key'], $errors);
                }
                save_content($content, $schema[$s]['label'] . ': Seitentext');
                audit('content.save', $schema[$s]['label'] . ': Seitentext');
                foreach ($errors as $er) {
                    flash($er, 'error');
                }
                flash('Gespeichert.');
                redirect(admin_url(['s' => $s]));

            case 'report_upload':
                $err = store_report((string) ($_POST['committee'] ?? ''), (string) ($_POST['title'] ?? ''), $_FILES['report'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'admin');
                flash($err ? 'Research Report nicht gespeichert: ' . $err : 'Research Report hochgeladen. Er steht jetzt im Teilnehmer-Bereich des Gremiums, unter Downloads und auf der Gremien-Seite.', $err ? 'error' : 'ok');
                redirect(admin_url(['s' => 'committees']) . '#reports');

            case 'report_delete':
                flash(delete_report((string) ($_POST['report'] ?? '')) ? 'Research Report gelöscht.' : 'Nicht gefunden.');
                redirect(admin_url(['s' => 'committees']) . '#reports');

            case 'gallery_upload':
                @set_time_limit(300);
                [$ok, $errs] = gallery_store_uploads('official', 'admin');
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => !$errs, 'errors' => $errs]);
                    exit;
                }
                foreach ($errs as $er) {
                    flash($er, 'error');
                }
                if ($ok) {
                    flash($ok . ' Foto' . ($ok === 1 ? '' : 's') . ' hochgeladen.');
                }
                redirect(admin_url(['s' => 'photos']));

            case 'gallery_delete':
                gallery_delete((string) ($_POST['id'] ?? ''));
                flash('Foto gelöscht.');
                redirect(admin_url(['s' => 'photos', 'album' => (string) ($_POST['album'] ?? 'official')]) . '#photos');

            case 'gallery_delete_album':
                $album = in_array($_POST['album'] ?? '', GALLERY_ALBUMS, true) ? $_POST['album'] : '';
                $n = $album ? gallery_delete_album($album) : 0;
                audit('gallery.delete_album', $album . ': ' . $n);
                flash($n . ' Foto' . ($n === 1 ? '' : 's') . ' gelöscht.');
                redirect(admin_url(['s' => 'photos', 'album' => $album]));

            case 'gallery_hide':
                gallery_hide();
                audit('gallery.hide');
                flash('Das offizielle Album ist im Teilnehmer-Bereich wieder ausgeblendet.');
                redirect(admin_url(['s' => 'photos']));

            case 'gallery_mail_save':
            case 'gallery_release':
            case 'gallery_release_silent':
                $content['portal']['gallery_mail_subject'] = trim(mb_substr((string) ($_POST['gallery_mail_subject'] ?? ''), 0, 200));
                $content['portal']['gallery_mail_text'] = trim(mb_substr((string) ($_POST['gallery_mail_text'] ?? ''), 0, 8000));
                if ($content['portal']['gallery_mail_subject'] !== (string) c('portal.gallery_mail_subject') || $content['portal']['gallery_mail_text'] !== (string) c('portal.gallery_mail_text')) {
                    save_content($content, 'Fotogalerie: Abschieds-Mail');
                }
                if ($action === 'gallery_mail_save') {
                    flash('E-Mail-Text gespeichert.');
                    redirect(admin_url(['s' => 'photos']) . '#release');
                }
                if (!gallery_photos('official')) {
                    flash('Bitte zuerst die Fotos des Fotografen hochladen.', 'error');
                    redirect(admin_url(['s' => 'photos']));
                }
                $mail = $action === 'gallery_release';
                if ($mail && ($content['portal']['gallery_mail_subject'] === '' || $content['portal']['gallery_mail_text'] === '')) {
                    flash('Bitte Betreff und Text der E-Mail ausfüllen.', 'error');
                    redirect(admin_url(['s' => 'photos']) . '#release');
                }
                [$sent, $failed] = gallery_release($mail, $content['portal']['gallery_mail_subject'], $content['portal']['gallery_mail_text']);
                audit('gallery.release', $mail ? $sent . ' sent, ' . $failed . ' failed' : 'without mail');
                flash('Das offizielle Album ist jetzt im Teilnehmer-Bereich sichtbar.' . ($mail ? ' ' . $sent . ' Abschieds-Mail' . ($sent === 1 ? '' : 's') . ' verschickt.' : '')
                    . ($failed ? ' ' . $failed . ' E-Mail(s) konnten nicht gesendet werden – nochmal „Freigeben und E-Mail senden“ drücken schickt sie nur an diese Personen.' : ''), $failed ? 'error' : 'ok');
                redirect(admin_url(['s' => 'photos']));

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
                    audit($action === 'delete_item' ? 'content.item_delete' : 'content.item_move', $label);
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
                    audit('media.upload', $n . ' file(s)');
                    flash($n === 1 ? '1 Datei hochgeladen.' : "$n Dateien hochgeladen.");
                }
                redirect(admin_url(['s' => 'media']));

            case 'media_delete':
                $file = basename((string) ($_POST['file'] ?? ''));
                $path = UPLOAD_DIR . '/' . $file;
                if ($file !== '' && $file[0] !== '.' && is_file($path)) {
                    unlink($path);
                    audit('media.delete', $file);
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
                audit('registration.delete', 'id ' . $id);
                flash('Anmeldung gelöscht.');
                redirect(admin_url(['s' => 'registrations']));

            case 'reg_update':
                $id = (string) ($_POST['id'] ?? '');
                $status = array_key_exists($_POST['status'] ?? '', registration_statuses()) ? $_POST['status'] : 'received';
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($id, $status) {
                    foreach ($regs as &$r) {
                        if (($r['id'] ?? '') === $id) {
                            $r['status'] = $status;
                            // Country: delegates only; committee: delegates and chairs (the form leaves the others out).
                            if (isset($_POST['assigned_country'])) {
                                $r['assigned_country'] = trim(mb_substr((string) $_POST['assigned_country'], 0, 100));
                            }
                            if (isset($_POST['assigned_committee'])) {
                                $r['assigned_committee'] = trim(mb_substr((string) $_POST['assigned_committee'], 0, 150));
                            }
                            $r['admin_note'] = trim(mb_substr((string) ($_POST['admin_note'] ?? ''), 0, 2000));
                            $r['is_chair'] = !empty($_POST['is_chair']);
                            $r['is_manager'] = !empty($_POST['is_manager']);
                            $r['is_coordinator'] = !empty($_POST['is_coordinator']);
                            $r['is_admin'] = !empty($_POST['is_admin']);
                            if ($r['is_chair'] || $r['is_manager'] || $r['is_admin'] || $r['is_coordinator']) {
                                unset($r['role_pending']);
                            }
                        }
                    }
                    return $regs;
                });
                audit('registration.update', 'id ' . $id . ' → ' . $status);
                flash('Anmeldung aktualisiert.');
                redirect(admin_url(['s' => 'registrations', 'open' => $id]) . '#reg-' . rawurlencode($id));

            case 'alloc_save':
            case 'alloc_compute':
                // Save what is in the table first (manual changes, "fest" ticks) …
                $countries = (array) ($_POST['country'] ?? []);
                $committees = (array) ($_POST['committee'] ?? []);
                $fixed = (array) ($_POST['fixed'] ?? []);
                $changed = 0;
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($countries, $committees, $fixed, &$changed) {
                    foreach ($regs as &$r) {
                        if (!array_key_exists($r['id'], $countries) || !is_delegate_reg($r)) {
                            continue;
                        }
                        $before = [$r['assigned_country'] ?? '', $r['assigned_committee'] ?? ''];
                        $r['assigned_country'] = trim(mb_substr((string) $countries[$r['id']], 0, 100));
                        $r['assigned_committee'] = trim(mb_substr((string) ($committees[$r['id']] ?? ''), 0, 150));
                        $r['alloc_fixed'] = !empty($fixed[$r['id']]);
                        $changed += $before !== [$r['assigned_country'], $r['assigned_committee']] ? 1 : 0;
                    }
                    return $regs;
                });
                if ($action === 'alloc_save') {
                    audit('allocation.save', $changed . ' changed');
                    flash($changed . ' Zuteilung' . ($changed === 1 ? '' : 'en') . ' geändert.');
                    redirect(admin_url(['s' => 'allocation']));
                }
                // … then let the algorithm place everyone who is not fixed or released.
                $result = alloc_compute(alloc_delegates());
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($result) {
                    foreach ($regs as &$r) {
                        if (isset($result[$r['id']])) {
                            $r['assigned_committee'] = $result[$r['id']]['committee'];
                            $r['assigned_country'] = $result[$r['id']]['country'];
                        }
                    }
                    return $regs;
                });
                $none = count(array_filter($result, fn ($x) => $x['committee'] === ''));
                audit('allocation.compute', count($result) . ' delegates');
                flash('Einteilung für ' . count($result) . ' Delegierte berechnet' . ($none ? ' – ' . $none . ' ohne Platz (zu wenige Länder in den Gremien)' : '') . '. Noch nichts verschickt: bitte prüfen, bei Bedarf ändern und dann herausgeben.', $none ? 'error' : 'ok');
                redirect(admin_url(['s' => 'allocation']));

            case 'alloc_mail_save':
            case 'alloc_release':
                $content['portal']['alloc_subject'] = trim(mb_substr((string) ($_POST['alloc_subject'] ?? ''), 0, 200));
                $content['portal']['alloc_text'] = trim(mb_substr((string) ($_POST['alloc_text'] ?? ''), 0, 5000));
                if ($content['portal']['alloc_subject'] !== (string) c('portal.alloc_subject') || $content['portal']['alloc_text'] !== (string) c('portal.alloc_text')) {
                    save_content($content, 'Einteilung: E-Mail-Text');
                }
                if ($action === 'alloc_mail_save') {
                    flash('E-Mail-Text gespeichert.');
                    redirect(admin_url(['s' => 'allocation']) . '#release');
                }
                if ($content['portal']['alloc_subject'] === '' || $content['portal']['alloc_text'] === '') {
                    flash('Bitte Betreff und Text der E-Mail ausfüllen.', 'error');
                    redirect(admin_url(['s' => 'allocation']) . '#release');
                }
                set_time_limit(600);
                [$sent, $failed] = alloc_release($content['portal']['alloc_subject'], $content['portal']['alloc_text']);
                audit('allocation.release', $sent . ' sent, ' . $failed . ' failed');
                flash($sent . ' E-Mail' . ($sent === 1 ? '' : 's') . ' mit der Einteilung verschickt; die Einteilung ist jetzt im Teilnehmer-Bereich sichtbar.'
                    . ($failed ? ' ' . $failed . ' E-Mail(s) konnten nicht gesendet werden – diese Personen sehen ihre Einteilung trotzdem im Teilnehmer-Bereich; „herausgeben“ versucht es erneut.' : ''), $failed ? 'error' : 'ok');
                redirect(admin_url(['s' => 'allocation']));

            case 'team_save':
                $committees = (array) ($_POST['committee'] ?? []);
                $changed = 0;
                $teamIds = array_column((array) ($content['team'] ?? []), 'id');
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($committees, $teamIds, &$changed) {
                    foreach ($regs as &$r) {
                        if (!array_key_exists($r['id'], $committees)) {
                            continue;
                        }
                        $before = json_encode([$r['assigned_committee'] ?? '', !empty($r['is_chair']), !empty($r['is_coordinator']), !empty($r['is_admin']), !empty($r['is_site_admin']), $r['team_profile'] ?? '']);
                        $r['assigned_committee'] = trim(mb_substr((string) $committees[$r['id']], 0, 150));
                        $r['is_chair'] = !empty($_POST['chair'][$r['id']]);
                        $r['is_coordinator'] = !empty($_POST['coordinator'][$r['id']]);
                        $r['is_admin'] = !empty($_POST['admin'][$r['id']]);
                        $r['is_site_admin'] = !empty($_POST['site_admin'][$r['id']]);
                        $profile = (string) ($_POST['profile'][$r['id']] ?? '');
                        $r['team_profile'] = in_array($profile, $teamIds, true) ? $profile : '';
                        if ($r['is_chair'] || $r['is_admin'] || $r['is_coordinator']) {
                            unset($r['role_pending']);
                        }
                        $changed += $before !== json_encode([$r['assigned_committee'], $r['is_chair'], $r['is_coordinator'], $r['is_admin'], $r['is_site_admin'], $r['team_profile']]) ? 1 : 0;
                    }
                    return $regs;
                });
                audit('allocation.team_save', $changed . ' changed');
                flash($changed . ' Team-Zuordnung' . ($changed === 1 ? '' : 'en') . ' geändert.');
                redirect(admin_url(['s' => 'allocation']) . '#team');

            case 'reg_confirm':
            case 'reg_confirm_role':
                $id = (string) ($_POST['id'] ?? '');
                update_json(REGISTRATIONS_FILE, function (array $regs) use ($id) {
                    foreach ($regs as &$r) {
                        if (($r['id'] ?? '') !== $id) {
                            continue;
                        }
                        $r['status'] = 'confirmed';
                        if (!empty($r['role_pending'])) {
                            unset($r['role_pending']);
                            if (($r['kind'] ?? '') === 'manager') {
                                $r['is_manager'] = true;
                            } else {
                                $r['is_chair'] = true;
                            }
                        }
                    }
                    return $regs;
                });
                audit('registration.confirm_role', 'id ' . $id);
                flash('Bestätigt. Die Rechte gelten ab dem nächsten Seitenaufruf der Person.');
                redirect(admin_url(['s' => 'registrations']) . '#reg-' . rawurlencode($id));

            case 'staff_password':
                $pw = (string) ($_POST['password'] ?? '');
                if (!check_password((string) ($_POST['current'] ?? ''))) {
                    flash('Das Admin-Passwort ist falsch.', 'error');
                } elseif (mb_strlen($pw) < 10) {
                    flash('Das neue Passwort muss mindestens 10 Zeichen lang sein.', 'error');
                } else {
                    set_staff_password('laptops', $pw);
                    audit('staff.password_change', 'laptops');
                    flash('Passwort des Laptop-Kontos geändert. Bereits angemeldete Laptops bleiben angemeldet, bis sie sich abmelden.');
                }
                redirect(admin_url(['s' => 'resolutions']));

            case 'reg_sendcreds':
                $reg = null;
                foreach (read_json(REGISTRATIONS_FILE) as $r) {
                    if (($r['id'] ?? '') === ($_POST['id'] ?? '')) {
                        $reg = $r;
                    }
                }
                if ($reg && ($reg['code'] ?? '') === '') {
                    flash('Diese Anmeldung hat noch keine Kennung. Erst oben „Kennungen vergeben“ ausführen.', 'error');
                } elseif ($reg && send_new_credentials($reg['code'])) {
                    audit('registration.send_credentials', 'id ' . $reg['id']);
                    flash('Neue Zugangsdaten (Kennung + Passwort) an ' . $reg['email'] . ' gesendet. Das alte Passwort gilt nicht mehr.');
                } else {
                    flash('Die E-Mail konnte nicht gesendet werden. Ist der Mailversand im Hosting-Paket aktiv?', 'error');
                }
                redirect(admin_url(['s' => 'registrations']));

            case 'codes_add':
                $text = (string) ($_POST['codes'] ?? '');
                $file = $_FILES['codes_file'] ?? null;
                if ($file && $file['error'] === UPLOAD_ERR_OK && $file['size'] < 2 * 1024 * 1024) {
                    $text .= "\n" . file_get_contents($file['tmp_name']);
                }
                [$added, $known, $invalid] = add_codes($text);
                audit('codes.add', $added . ' added');
                flash($added . ' Kennung' . ($added === 1 ? '' : 'en') . ' hinzugefügt'
                    . ($known ? ', ' . $known . ' waren schon da' : '')
                    . ($invalid ? ', ' . $invalid . ' Zeile' . ($invalid === 1 ? '' : 'n') . ' übersprungen (keine gültige Kennung – Namen werden nie übernommen)' : '') . '.',
                    $added || !$invalid ? 'ok' : 'error');
                redirect(admin_url(['s' => 'codes']));

            case 'test_create':
                $pw = (string) ($_POST['password'] ?? '');
                if (mb_strlen($pw) < 6) {
                    flash('Bitte ein Passwort mit mindestens 6 Zeichen für die Test-Delegierten eingeben.', 'error');
                } else {
                    $n = create_test_delegates($pw);
                    audit('test_delegates.create', $n . ' new');
                    flash('Test-Delegierte sind bereit (' . $n . ' neu angelegt, Passwort für alle gesetzt): ' . implode(', ', array_map('format_code', TEST_CODES)) . '.');
                }
                redirect(admin_url(['s' => 'codes']) . '#test');

            case 'test_delete':
                $n = delete_test_delegates();
                audit('test_delegates.delete', (string) $n);
                flash($n . ' Test-Delegierte gelöscht.');
                redirect(admin_url(['s' => 'codes']) . '#test');

            case 'generator_key':
                $_SESSION['generator_key'] = generator_new_key();
                audit('generator.key_create');
                redirect(admin_url(['s' => 'codes']) . '#generator');

            case 'generator_key_remove':
                generator_remove_key();
                audit('generator.key_remove');
                flash('Verbindung getrennt. Der Generator kann keine Kennungen mehr an die Website senden.');
                redirect(admin_url(['s' => 'codes']) . '#generator');

            case 'smtp_save':
            case 'smtp_test':
                $old = is_file(SMTP_FILE) ? read_json(SMTP_FILE) : [];
                $pass = trim((string) ($_POST['smtp_pass'] ?? '')); // pasted passwords often carry a space or line break
                $new = ['host' => trim((string) ($_POST['smtp_host'] ?? '')), 'port' => (int) ($_POST['smtp_port'] ?? 465) ?: 465,
                    'user' => mb_strtolower(trim((string) ($_POST['smtp_user'] ?? ''))), 'pass' => $pass !== '' ? $pass : (string) ($old['pass'] ?? '')];
                if ($new['host'] === '' && $new['user'] === '') {
                    @unlink(SMTP_FILE);
                    audit('smtp.remove');
                    flash('Postfach entfernt. E-Mails gehen wieder über den einfachen Versand des Servers.');
                    redirect(admin_url(['s' => 'settings']) . '#smtp');
                }
                write_json(SMTP_FILE, $new);
                audit('smtp.save', $new['host']);
                if ($action === 'smtp_test') {
                    $to = trim((string) ($_POST['smtp_to'] ?? ''));
                    $err = !filter_var($to, FILTER_VALIDATE_EMAIL) ? 'Bitte unten bei „Test-Mail an“ eine Adresse eingeben, an die die Test-Mail gehen soll.'
                        : (!smtp_settings() ? 'Bitte Server, Benutzername und Passwort ausfüllen (das Passwort ist noch nicht gespeichert).'
                        : smtp_send(smtp_settings(), mail_from_address(), $to, ['To: ' . $to, 'Subject: OMUN Test-Mail', 'MIME-Version: 1.0', 'From: ' . mail_from_address(), 'Date: ' . date('r'),
                            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr(mail_from_address(), '@'), 1) . '>', 'Content-Type: text/plain; charset=UTF-8'],
                            "Diese Test-Mail kommt von der OMUN-Website über euer Postfach.\nWenn sie im Posteingang (nicht im Spam) angekommen ist, ist alles richtig eingerichtet."));
                    flash($err === null ? 'Test-Mail an ' . $to . ' verschickt. Bitte nachsehen, ob sie im Posteingang (nicht im Spam) ankommt.' : 'Test fehlgeschlagen: ' . $err, $err === null ? 'ok' : 'error');
                } else {
                    flash('Postfach gespeichert. Am besten gleich eine Test-Mail schicken.');
                }
                redirect(admin_url(['s' => 'settings']) . '#smtp');

            case 'codes_delete':
                $n = delete_unused_codes([(string) ($_POST['code'] ?? '')]);
                if ($n) {
                    audit('codes.delete', '1 code');
                }
                flash($n ? 'Kennung gelöscht.' : 'Die Kennung wird schon von einer Anmeldung verwendet und wurde nicht gelöscht.', $n ? 'ok' : 'error');
                redirect(admin_url(['s' => 'codes']));

            case 'codes_delete_unused':
                $n = delete_unused_codes();
                audit('codes.delete_unused', $n . ' codes');
                flash($n . ' unbenutzte Kennung' . ($n === 1 ? '' : 'en') . ' gelöscht.');
                redirect(admin_url(['s' => 'codes']));

            case 'legacy_assign_codes':
                $n = 0;
                update_json(REGISTRATIONS_FILE, function (array $regs) use (&$n) {
                    foreach ($regs as &$r) {
                        if (($r['code'] ?? '') === '') {
                            $r['code'] = new_code_for($r['id']);
                            $r['migrated'] = true;
                            $n++;
                        }
                    }
                    return $regs;
                });
                delete_orphan_accounts(); // old log-ins by e-mail address no longer work
                audit('legacy.assign_codes', $n . ' registrations');
                flash($n . ($n === 1 ? ' Anmeldung hat' : ' Anmeldungen haben') . ' eine Kennung bekommen. Jetzt die Liste herunterladen (Schritt 2).');
                redirect(admin_url(['s' => 'registrations']));

            case 'legacy_strip':
                if (($_POST['confirm'] ?? '') !== 'LÖSCHEN') {
                    flash('Zum Bestätigen bitte LÖSCHEN eintippen.', 'error');
                    redirect(admin_url(['s' => 'registrations']));
                }
                $n = 0;
                update_json(REGISTRATIONS_FILE, function (array $regs) use (&$n) {
                    foreach ($regs as &$r) {
                        if (isset($r['first_name']) || isset($r['last_name']) || isset($r['school'])) {
                            unset($r['first_name'], $r['last_name'], $r['school']);
                            $n++;
                        }
                    }
                    return $regs;
                });
                audit('legacy.strip_names', $n . ' registrations');
                flash('Namen und Schulen aus ' . $n . ' Anmeldung' . ($n === 1 ? '' : 'en') . ' gelöscht.');
                redirect(admin_url(['s' => 'registrations']));

            case 'legacy_sendcreds':
                set_time_limit(300);
                $sent = 0;
                $failed = 0;
                foreach (read_json(REGISTRATIONS_FILE) as $r) {
                    if (!empty($r['migrated']) && empty($r['creds_sent']) && ($r['code'] ?? '') !== '' && ($r['status'] ?? '') !== 'cancelled') {
                        if (send_new_credentials($r['code'])) {
                            $sent++;
                            update_json(REGISTRATIONS_FILE, function (array $regs) use ($r) {
                                foreach ($regs as &$x) {
                                    if ($x['id'] === $r['id']) {
                                        $x['creds_sent'] = date('c');
                                    }
                                }
                                return $regs;
                            });
                        } else {
                            $failed++;
                        }
                    }
                }
                audit('legacy.send_credentials', $sent . ' sent, ' . $failed . ' failed');
                flash($sent . ' E-Mail' . ($sent === 1 ? '' : 's') . ' mit Kennung und neuem Passwort gesendet.' . ($failed ? ' ' . $failed . ' konnten nicht gesendet werden.' : ''), $failed ? 'error' : 'ok');
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
                    audit('registration.delete_all');
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
                    audit('admin.password_change');
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
                    audit('content.import');
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
                try {
                    [$count, $old, $new] = with_update_lock(fn () => apply_update($u['tmp_name']));
                } catch (RuntimeException $ex) {
                    deploy_log('upload', current_version(), '?', false, $ex->getMessage());
                    throw $ex;
                }
                deploy_log('upload', $old, $new, true, "$count Dateien");
                flash("Update eingespielt: Version $old → $new ($count Dateien). Inhalte, Bilder und Anmeldungen wurden nicht verändert.");
                redirect(admin_url(['s' => 'update']));

            case 'gh_check':
                $meta = with_update_lock(fn () => github_fetch());
                $_SESSION['gh_checked'] = $meta['commit'] ?: (string) $meta['fetched'];
                redirect(admin_url(['s' => 'update']) . '#github');

            case 'gh_install':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Zum Einspielen eines Updates bitte das richtige Admin-Passwort eingeben.', 'error');
                    redirect(admin_url(['s' => 'update']) . '#github');
                }
                $commit = (string) ($_POST['commit'] ?? '');
                [$count, $old, $new, $meta] = github_install(preg_match('/^[0-9a-f]{40}$/', $commit) ? $commit : null, 'github');
                unset($_SESSION['gh_checked']);
                flash("Update von GitHub eingespielt: Version $old → $new ($count Dateien). Inhalte, Bilder und Anmeldungen wurden nicht verändert.");
                redirect(admin_url(['s' => 'update']));

            case 'backup_restore':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Zum Wiederherstellen bitte das richtige Admin-Passwort eingeben.', 'error');
                    redirect(admin_url(['s' => 'update']) . '#backups');
                }
                [$count, $old, $new] = restore_code_backup((string) ($_POST['file'] ?? ''), 'restore');
                flash("Sicherung eingespielt: Version $old → $new ($count Dateien).");
                redirect(admin_url(['s' => 'update']));

            case 'deploy_settings':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Bitte zur Bestätigung das richtige Admin-Passwort eingeben.', 'error');
                    redirect(admin_url(['s' => 'update']) . '#mcp');
                }
                $branch = trim((string) ($_POST['branch'] ?? ''));
                if (!valid_branch($branch)) {
                    flash('Ungültiger Branch-Name.', 'error');
                    redirect(admin_url(['s' => 'update']) . '#mcp');
                }
                $enable = !empty($_POST['mcp_enabled']);
                save_deploy_settings(['branch' => $branch, 'mcp_enabled' => $enable]);
                if (!empty($_POST['new_token']) || ($enable && deploy_settings()['mcp_token_hash'] === '')) {
                    $_SESSION['mcp_new_token'] = new_mcp_token();
                    audit('mcp.new_key');
                }
                audit('deploy.settings', 'branch ' . $branch . ', mcp ' . ($enable ? 'on' : 'off'));
                flash('Gespeichert. Claude-Zugang ist ' . ($enable ? 'eingeschaltet.' : 'ausgeschaltet.'));
                redirect(admin_url(['s' => 'update']) . '#mcp');

            case 'hub_settings':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Bitte zur Bestätigung das richtige Admin-Passwort eingeben.', 'error');
                    redirect(admin_url(['s' => 'update']) . '#hub');
                }
                $enable = !empty($_POST['hub_enabled']);
                save_deploy_settings(['hub_enabled' => $enable]);
                if (!empty($_POST['new_hub_token']) || ($enable && hub_settings()['hub_token_hash'] === '')) {
                    $_SESSION['hub_new_token'] = new_hub_token();
                    audit('hub.new_token');
                }
                audit('hub.settings', 'hub ' . ($enable ? 'on' : 'off'));
                flash('Gespeichert. Hub-API ist ' . (hub_enabled() ? 'eingeschaltet.' : 'ausgeschaltet.'));
                redirect(admin_url(['s' => 'update']) . '#hub');

            case 'history_restore':
                $path = history_path((string) ($_POST['file'] ?? ''));
                $version = $path ? read_json($path) : [];
                if (empty($version['content'])) {
                    flash('Version nicht gefunden.', 'error');
                } else {
                    save_content($version['content'], 'Wiederherstellung (Stand vom ' . date('d.m.Y H:i', strtotime($version['saved'])) . ')');
                    audit('content.history_restore', basename((string) $path));
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
                audit('admin.2fa_add', $name);
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
                    audit('admin.2fa_remove', $auth['totp'] ? '' : '2fa now off');
                    flash($auth['totp'] ? 'Gerät entfernt.' : 'Gerät entfernt. Zwei-Faktor-Login ist jetzt AUS.', $auth['totp'] ? 'ok' : 'error');
                }
                redirect(admin_url(['s' => 'settings']));

            case 'recovery_new':
                if (!check_password((string) ($_POST['password'] ?? ''))) {
                    flash('Bitte das richtige Passwort eingeben.', 'error');
                } else {
                    $_SESSION['recovery_show'] = new_recovery_codes();
                    audit('admin.recovery_codes_new');
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

if ($s === 'gallery_photo') {
    $p = gallery_photo((string) ($_GET['id'] ?? ''));
    $p ? gallery_send($p, (string) ($_GET['size'] ?? 'f'), isset($_GET['download'])) : not_found_admin();
}
if ($s === 'gallery_zip') {
    in_array($_GET['album'] ?? '', GALLERY_ALBUMS, true) ? gallery_zip($_GET['album']) : not_found_admin();
}

if ($s === 'certificate') {
    foreach (read_json(REGISTRATIONS_FILE) as $r) {
        if (($r['id'] ?? '') === ($_GET['id'] ?? '')) {
            send_certificate($r);
        }
    }
    http_response_code(404);
    exit('Not found');
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

if ($s === 'legacy_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kennungen-alte-anmeldungen-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kennung', 'Vorname', 'Nachname', 'Schule', 'E-Mail', 'Teilnahme als'], ';', '"', '\\');
    foreach (legacy_registrations() as $r) {
        $row = [format_code((string) ($r['code'] ?? '')), $r['first_name'] ?? '', $r['last_name'] ?? '', $r['school'] ?? '', $r['email'] ?? '', $r['role'] ?? ''];
        fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, $row), ';', '"', '\\');
    }
    exit;
}

if ($s === 'registrations_csv') {
    $regs = read_json(REGISTRATIONS_FILE);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anmeldungen-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel shows umlauts correctly
    $fields = registration_fields();
    $statuses = registration_statuses();
    fputcsv($out, array_merge(['Datum', 'Status', 'Rechte', 'Land (zugeteilt)', 'Gremium (zugeteilt)', 'Position Paper'], array_column($fields, 0), ['Notiz']), ';', '"', '\\');
    foreach ($regs as $r) {
        $row = [
            date('d.m.Y H:i', strtotime($r['created'])),
            $statuses[$r['status'] ?? 'received'][1] ?? '',
            !empty($r['role_pending']) ? 'Bestätigung offen' : implode(', ', array_filter([!empty($r['is_chair']) ? 'Chair' : '', !empty($r['is_manager']) ? 'Conference Manager' : '', !empty($r['is_admin']) ? 'Admin' : ''])),
            $r['assigned_country'] ?? '',
            committee_display((string) ($r['assigned_committee'] ?? '')),
            !empty($r['paper']) ? 'ja (' . date('d.m.Y', strtotime($r['paper']['uploaded'])) . ')' : 'nein',
        ];
        foreach ($fields as $k => $_) {
            $v = $k === 'code' ? format_code((string) ($r['code'] ?? '')) : (string) ($r[$k] ?? '');
            $row[] = preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; // no formula injection
        }
        $row[] = $r['admin_note'] ?? '';
        fputcsv($out, $row, ';', '"', '\\');
    }
    exit;
}

/* ---------- Pages ---------- */

admin_page($s, $schema);

/* ================================================================== */

function not_found_admin(): never
{
    http_response_code(404);
    exit('Nicht gefunden');
}

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
<?= icon_links() ?>
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
    <?= emblem('login-emblem', 'purple') ?>
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
    $titles = ['' => 'Übersicht', 'registrations' => 'Anmeldungen', 'media' => 'Dateien & Bilder', 'history' => 'Versionen', 'settings' => 'Sicherheit & Backup', 'update' => 'Update', 'resolutions' => 'Resolutionen', 'allocation' => 'Zuteilung', 'stats' => 'Besucher', 'codes' => 'Kennungen', 'photos' => 'Fotogalerie (Portal)'];
    $title = $schema[$s]['label'] ?? $titles[$s] ?? 'Übersicht';
    admin_head($title);
    ?>
<body>
<input type="checkbox" id="nav-toggle" class="nav-toggle">
<header class="topbar">
  <label for="nav-toggle" class="nav-btn" aria-label="Menü">☰</label>
  <a class="topbar-brand" href="<?= e(admin_url()) ?>"><?= emblem('topbar-emblem', 'purple') ?><?= e(c('site.name')) ?> <span>Admin</span></a>
  <a class="topbar-link" href="<?= e(url()) ?>" target="_blank">Website ansehen ↗</a>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="logout"><button class="topbar-link" type="submit">Abmelden</button></form>
</header>
<div class="layout">
  <nav class="sidebar">
    <a href="<?= e(admin_url()) ?>"<?= $s === '' ? ' class="active"' : '' ?>>Übersicht</a>
    <a href="<?= e(admin_url(['s' => 'registrations'])) ?>"<?= $s === 'registrations' ? ' class="active"' : '' ?>>Anmeldungen <span class="count"><?= $regCount ?></span></a>
    <a href="<?= e(admin_url(['s' => 'codes'])) ?>"<?= $s === 'codes' ? ' class="active"' : '' ?>>Kennungen <span class="count"><?= count(array_filter(codes(), fn ($c) => empty($c['reg']))) ?> frei</span></a>
    <a href="<?= e(admin_url(['s' => 'stats'])) ?>"<?= $s === 'stats' ? ' class="active"' : '' ?>>Besucher</a>
    <a href="<?= e(admin_url(['s' => 'allocation'])) ?>"<?= $s === 'allocation' ? ' class="active"' : '' ?>>Zuteilung</a>
    <a href="<?= e(admin_url(['s' => 'resolutions'])) ?>"<?= $s === 'resolutions' ? ' class="active"' : '' ?>>Resolutionen</a>
    <a href="<?= e(admin_url(['s' => 'photos'])) ?>"<?= $s === 'photos' ? ' class="active"' : '' ?>>Fotogalerie <span class="count"><?= count(gallery_photos('community')) + count(gallery_photos('official')) ?></span></a>
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
    } elseif ($s === 'codes') {
        view_codes();
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
    } elseif ($s === 'photos') {
        view_gallery();
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
    <?php
    $off = array_keys(array_filter(['Delegierte' => 'login_delegates', 'Chairs' => 'login_chairs', 'Conference Manager' => 'login_managers',
        'Admins' => 'login_admins', '„Ohne Rechte“' => 'login_staff', 'Laptop-Konto' => 'login_laptop'], fn ($k) => !c('portal.' . $k, true)));
    ?>
    <?php if ($off): ?>
      <p class="flash flash-warn">Login gerade <strong>aus</strong> für: <?= e(implode(', ', $off)) ?>. <a href="<?= e(admin_url(['s' => 'portal'])) ?>">Ändern →</a></p>
    <?php endif; ?>
    <?php if ($legacy = count(legacy_registrations())): ?>
      <p class="flash flash-error"><?= $legacy ?> Anmeldung<?= $legacy === 1 ? '' : 'en' ?> enthalten noch Namen oder Schulen. <a href="<?= e(admin_url(['s' => 'registrations'])) ?>">Jetzt umstellen →</a></p>
    <?php endif; ?>
    <?php if (registration_is_open() && !array_filter(codes(), fn ($c) => empty($c['reg']))): ?>
      <p class="flash flash-warn">Die Anmeldung ist offen, aber es gibt keine freien Kennungen – niemand kann sich anmelden. <a href="<?= e(admin_url(['s' => 'codes'])) ?>">Kennungen hinzufügen →</a></p>
    <?php endif; ?>
    <?php $purgeAt = registrations_purge_at(); ?>
    <?php if ($regCount && $purgeAt && $purgeAt > time() && $purgeAt - time() < 14 * 86400): ?>
      <p class="flash flash-error">Die Anmeldungen werden am <?= e(date('d.m.Y', $purgeAt)) ?> automatisch gelöscht. Bei Bedarf vorher <a href="<?= e(admin_url(['s' => 'registrations'])) ?>">als CSV sichern</a>.</p>
    <?php endif; ?>
    <div class="tiles">
      <a class="tile" href="<?= e(admin_url(['s' => 'registrations'])) ?>"><strong><?= $regCount ?></strong><span>Anmeldungen</span></a>
      <?php $week = stats_summary(7); ?>
      <a class="tile" href="<?= e(admin_url(['s' => 'stats'])) ?>"><strong><?= number_format($week['visitors'], 0, ',', '.') ?></strong><span>Besucher (7 Tage)</span></a>
      <a class="tile" href="<?= e(admin_url(['s' => 'registration'])) ?>"><strong><?= !registration_is_open() ? 'zu' : (count(registration_open_role_defs()) < count(registration_role_defs()) ? 'teilweise' : 'offen') ?></strong><span>Anmeldung</span></a>
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
    <?php if (!empty($def['page'])): $pageData = c($def['page']['key'], []); ?>
      <form class="edit-form panel" method="post" action="<?= e(admin_url(['s' => $s])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="a" value="save_page">
        <?php foreach ($def['page']['fields'] as $f): ?>
          <?= field_html($f, $pageData[$f['key']] ?? '', 'p[' . $f['key'] . ']', 'p_' . $f['key'], 'p-' . $f['key']) ?>
        <?php endforeach; ?>
        <button class="btn" type="submit">Text speichern</button>
      </form>
    <?php endif; ?>
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
    <?php if ($s === 'committees') {
        view_reports();
    } ?>
    <?php
}

/** Research reports of all committees (uploaded by the chairs in the portal). */
function view_reports(): void
{
    $reports = research_reports();
    ?>
    <div class="panel" id="reports">
      <h2>Research Reports</h2>
      <p class="help">Die Chairs laden die Research Reports ihres Gremiums im Teilnehmer-Bereich hoch. Sie erscheinen dann bei den Delegierten des Gremiums im Teilnehmer-Bereich, öffentlich unter <a href="<?= e(url('downloads')) ?>" target="_blank">Downloads</a> und auf der Seite des Gremiums. Hier könnt ihr sie auch selbst hochladen oder löschen.</p>
      <?php if ($reports): ?>
        <ul class="item-list">
          <?php foreach ($reports as $rep): $cm = committee_by_slug($rep['committee']); ?>
            <li><span class="item-title"><?= e((($cm['abbr'] ?? '') ?: $cm['name']) . ': ' . $rep['title']) ?>
              <small><?= e(date('d.m.Y H:i', strtotime($rep['uploaded']))) ?> · <?= e(human_size((int) $rep['size'])) ?> · <?= e(str_starts_with((string) $rep['by'], 'portal:') ? 'von ' . substr($rep['by'], 7) : 'im Admin') ?></small></span>
              <a class="btn-ghost" href="<?= e(report_url($rep)) ?>" target="_blank">Öffnen</a>
              <form method="post" class="inline" data-confirm="„<?= e($rep['title']) ?>“ löschen? Er verschwindet auch aus Downloads."><?= csrf_field() ?><input type="hidden" name="a" value="report_delete"><input type="hidden" name="report" value="<?= e($rep['id']) ?>"><button class="icon danger" title="Löschen">✕</button></form></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="empty">Noch keine Research Reports.</p>
      <?php endif; ?>
      <?php if (c('committees', [])): ?>
      <form method="post" enctype="multipart/form-data" class="row-fields">
        <?= csrf_field() ?><input type="hidden" name="a" value="report_upload">
        <label>Gremium<select name="committee"><?php foreach (c('committees', []) as $cm): ?><option value="<?= e($cm['slug']) ?>"><?= e(trim(($cm['abbr'] ?? '') . ' – ' . $cm['name'], ' –')) ?></option><?php endforeach; ?></select></label>
        <label>Titel (z. B. Thema)<input name="title" required maxlength="150"></label>
        <label>Datei (PDF/Word, max. 20 MB)<input type="file" name="report" accept=".pdf,.doc,.docx,.odt" required></label>
        <button class="btn">Hochladen</button>
      </form>
      <?php endif; ?>
    </div>
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
/** Country of a delegate: only the countries of the allocated committee can be chosen. */
function country_select(string $name, string $slug, string $current): string
{
    $cm = $slug !== '' ? committee_by_slug($slug) : null;
    $list = $cm ? committee_countries($cm) : [];
    $h = '<select name="' . e($name) . '" data-country aria-label="Land"><option value="">' . ($cm ? '– noch keins –' : '– erst Gremium wählen –') . '</option>';
    foreach ($list as $cn) {
        $h .= '<option' . (mb_strtolower($cn) === mb_strtolower(trim($current)) ? ' selected' : '') . '>' . e($cn) . '</option>';
    }
    if (trim($current) !== '' && !in_array(mb_strtolower(trim($current)), array_map('mb_strtolower', $list), true)) {
        $h .= '<option value="' . e($current) . '" selected>' . e($current) . ' (nicht in der Länderliste)</option>';
    }
    return $h . '</select>';
}

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
    $all = read_json(REGISTRATIONS_FILE);
    $delegates = alloc_delegates($all);
    $tests = alloc_delegates($all, true); // listed for allocation by hand, but never counted
    $team = array_values(array_filter($all, fn ($r) => !is_delegate_reg($r)));
    usort($team, fn ($a, $b) => [committee_display((string) ($a['assigned_committee'] ?? '')) ?: 'zzz', reg_display($a)] <=> [committee_display((string) ($b['assigned_committee'] ?? '')) ?: 'zzz', reg_display($b)]);
    $analysis = alloc_analysis($delegates);
    $committees = c('committees', []);
    $freeCodes = count(array_filter(codes(), fn ($c) => empty($c['reg'])));
    $noCountries = array_filter($committees, fn ($cm) => !committee_countries($cm));
    $seats = array_sum(array_map(fn ($cm) => count(committee_countries($cm)), $committees));

    // Who sits where (to spot double bookings) and how well the wishes were met.
    $bySeat = [];
    $matches = array_fill_keys(array_keys(ALLOC_COSTS), 0);
    $unplaced = 0;
    foreach ($delegates as $r) {
        if (alloc_complete($r)) {
            $bySeat[alloc_key($r)][] = $r['id'];
            $matches[alloc_match($r, reg_committee_slug($r), trim((string) $r['assigned_country']))]++;
        } else {
            $unplaced++;
        }
    }
    $toSend = count(array_filter(array_merge($delegates, $tests), fn ($r) => alloc_complete($r) && !alloc_is_sent($r)));
    $wish = function (array $r): string {
        $parts = [];
        foreach (alloc_wishes($r) as $i => [$slug, $countries]) {
            if ($slug !== '') {
                $cm = committee_by_slug($slug);
                $parts[] = ($i + 1) . '. ' . e(($cm['abbr'] ?? '') ?: ($cm['name'] ?? $slug)) . ($countries ? ': ' . e(implode(' / ', $countries)) : '');
            }
        }
        if (($r['country_wishes'] ?? '') !== '') {
            $parts[] = e($r['country_wishes']);
        }
        return $parts ? implode('<br>', $parts) : '–';
    };
    ?>
    <div class="page-title"><h1>Zuteilung</h1></div>

    <h2>Delegierte: Auswertung und Einteilung</h2>
    <?php if (!$delegates && !$tests): ?>
      <p class="empty">Noch keine Anmeldungen von Delegierten.</p>
    <?php else: ?>
    <?php if ($freeCodes): ?>
      <p class="flash flash-warn"><strong>Vorläufig:</strong> <?= $freeCodes ?> Kennung<?= $freeCodes === 1 ? ' ist' : 'en sind' ?> noch nicht eingelöst. Ihr könnt die Einteilung schon berechnen und ansehen – final wird sie, wenn alle Kennungen eingelöst sind. Für Nachzügler später einfach neue Kennungen ausgeben und noch einmal berechnen: bereits herausgegebene Einteilungen bleiben dabei unverändert.</p>
    <?php else: ?>
      <p class="flash flash-ok"><strong>Alle Kennungen sind eingelöst</strong> – die Einteilung kann final berechnet und herausgegeben werden.</p>
    <?php endif; ?>
    <?php if ($noCountries): ?>
      <p class="flash flash-error">Ohne Länderliste (dort kann niemand eingeteilt werden): <?= e(implode(', ', array_column($noCountries, 'name'))) ?>. <a href="<?= e(admin_url(['s' => 'committees'])) ?>">Länder unter Gremien eintragen →</a></p>
    <?php endif; ?>
    <?php if ($seats < count($delegates)): ?>
      <p class="flash flash-error">Es gibt <?= $seats ?> Plätze (Länder in allen Gremien) für <?= count($delegates) ?> Delegierte – <?= count($delegates) - $seats ?> zu wenig.</p>
    <?php endif; ?>
    <div class="summary">
      <div><h3>Erstwünsche (1. Gremium + 1. Land)</h3><ul>
        <li><span>Klar – niemand sonst will diesen Platz</span><strong><?= count(array_filter($analysis, fn ($a) => $a['state'] === 'clear')) ?></strong></li>
        <li><span>Umkämpft – mehrere wollen denselben Platz</span><strong><?= count(array_filter($analysis, fn ($a) => $a['state'] === 'contested')) ?></strong></li>
        <li><span>Ohne Länderwunsch</span><strong><?= count(array_filter($analysis, fn ($a) => $a['state'] === 'none')) ?></strong></li>
      </ul></div>
      <div><h3>Aktuelle Einteilung</h3><ul>
        <?php foreach (ALLOC_COSTS as $k => [, $label]): if ($matches[$k]): ?><li><span><?= e($label) ?></span><strong><?= $matches[$k] ?></strong></li><?php endif; endforeach; ?>
        <li><span>Noch nicht eingeteilt</span><strong><?= $unplaced ?></strong></li>
      </ul></div>
      <div><h3>Stand</h3><ul>
        <li><span>Delegierte</span><strong><?= count($delegates) ?></strong></li>
        <li><span>Plätze (Länder in allen Gremien)</span><strong><?= $seats ?></strong></li>
        <li><span>Herausgegeben</span><strong><?= count(array_filter($delegates, 'alloc_is_sent')) ?></strong></li>
        <li><span>Noch nicht mitgeteilt / geändert</span><strong><?= $toSend ?></strong></li>
      </ul></div>
    </div>
    <p class="help"><strong>So geht’s:</strong> 1. „Einteilung berechnen“ verteilt alle Delegierten bestmöglich nach ihren Wünschen (bei Gleichstand gewinnt die frühere Anmeldung). 2. In der Tabelle prüfen und bei Bedarf Gremium oder Land von Hand ändern (zur Auswahl stehen die Länder des gewählten Gremiums); ein Häkchen bei „fest“ schützt die Zeile vor dem nächsten Berechnen. 3. „Einteilung herausgeben“ verschickt die E-Mails und schaltet die Einteilung im Teilnehmer-Bereich frei – vorher sehen die Delegierten nichts davon.</p>
    <input type="search" class="filter" placeholder="Suchen (Kennung, Land, Gremium …)" data-filter=".alloc-delegates tbody tr">
    <form method="post" class="alloc-form">
      <?= csrf_field() ?>
      <div class="table-wrap"><table class="alloc-table alloc-delegates">
        <thead><tr><th>Kennung</th><th>Wünsche</th><th>Erstwunsch</th><th>Gremium</th><th>Land</th><th>Ergebnis</th><th>fest</th><th>Mitgeteilt</th></tr></thead>
        <tbody>
        <?php foreach (array_merge($delegates, $tests) as $r): $id = e($r['id']); $isTest = !empty($r['test']); $a = $analysis[$r['id']] ?? ['state' => 'none', 'rivals' => 0]; $complete = alloc_complete($r); $double = !$isTest && $complete && count($bySeat[alloc_key($r)]) > 1; ?>
          <tr<?= $isTest ? ' class="cancelled"' : '' ?>>
            <td><strong><?= e(reg_display($r)) ?></strong></td>
            <td><small><?= $wish($r) ?></small></td>
            <td><?= $a['state'] === 'clear' ? '<span class="st st-confirmed">klar</span>' : ($a['state'] === 'contested' ? '<span class="st st-waitlist">umkämpft (+' . $a['rivals'] . ')</span>' : '<small>–</small>') ?></td>
            <td><?= committee_select('committee[' . $r['id'] . ']', (string) ($r['assigned_committee'] ?? '')) ?></td>
            <td><?= country_select('country[' . $r['id'] . ']', reg_committee_slug($r), (string) ($r['assigned_country'] ?? '')) ?><?= $double ? '<br><small class="danger">doppelt vergeben!</small>' : '' ?></td>
            <td><small><?= $isTest ? 'Test – zählt nicht mit, von Hand zuteilen' : ($complete ? e(ALLOC_COSTS[alloc_match($r, reg_committee_slug($r), trim((string) $r['assigned_country']))][1]) : '–') ?></small></td>
            <td><?php if (!$isTest): ?><input type="checkbox" name="fixed[<?= $id ?>]" value="1"<?= !empty($r['alloc_fixed']) ? ' checked' : '' ?> aria-label="fest"><?php endif; ?></td>
            <td><small><?= alloc_is_sent($r) ? e(date('d.m.Y', strtotime($r['released']['at']))) : (!empty($r['released']) ? '<span class="danger">geändert – noch nicht mitgeteilt</span>' : 'nein') ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <script type="application/json" id="alloc-countries"><?= json_encode(array_combine(array_column($committees, 'slug'), array_map('committee_countries', $committees)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <div class="save-bar">
        <button class="btn" name="a" value="alloc_save">Änderungen speichern</button>
        <button class="btn-ghost" name="a" value="alloc_compute" data-confirm="Einteilung jetzt berechnen? Alle Zeilen ohne „fest“ und ohne bereits herausgegebene Einteilung werden neu verteilt. Es wird noch nichts verschickt.">Einteilung berechnen</button>
      </div>
    </form>

    <details class="edit-form">
      <summary><strong>Plätze pro Gremium</strong> (welche Länder sind vergeben, welche frei)</summary>
      <?php foreach ($committees as $cm): $list = committee_countries($cm); ?>
        <h3><?= e($cm['name']) ?> <small>(<?= count(array_filter($list, fn ($cn) => isset($bySeat[$cm['slug'] . '|' . mb_strtolower($cn)]))) ?> von <?= count($list) ?> vergeben)</small></h3>
        <p><?php foreach ($list as $cn): $taken = isset($bySeat[$cm['slug'] . '|' . mb_strtolower($cn)]); ?><span class="st <?= $taken ? 'st-confirmed' : 'st-cancelled' ?>"><?= e($cn) ?></span> <?php endforeach; ?><?= $list ? '' : '<span class="muted">Keine Länder eingetragen.</span>' ?></p>
      <?php endforeach; ?>
    </details>

    <form method="post" class="edit-form" id="release">
      <?= csrf_field() ?>
      <h2>Einteilung herausgeben</h2>
      <p class="help">Verschickt an alle Delegierten mit vollständiger Einteilung (Gremium und Land), die sie noch nicht bekommen haben oder deren Einteilung sich seitdem geändert hat, eine E-Mail und schaltet Land, Gremium, Study Guide und Position-Paper-Abgabe im Teilnehmer-Bereich frei. Platzhalter: <code>{country}</code>, <code>{committee}</code>, <code>{topics}</code>, <code>{code}</code>, <code>{conference}</code>, <code>{portal}</code>, <code>{email}</code>.</p>
      <div class="field"><label>Betreff<input name="alloc_subject" value="<?= e(c('portal.alloc_subject')) ?>"></label></div>
      <div class="field"><label>Text der E-Mail<textarea name="alloc_text" rows="14"><?= e(c('portal.alloc_text')) ?></textarea></label></div>
      <div class="save-bar">
        <button class="btn-ghost" name="a" value="alloc_mail_save">Nur Text speichern</button>
        <button class="btn" name="a" value="alloc_release"<?= $toSend ? '' : ' disabled' ?> data-confirm="Einteilung jetzt herausgeben? <?= $toSend ?> Delegierte bekommen eine E-Mail mit Land und Gremium.<?= $freeCodes ? ' Achtung: ' . $freeCodes . ' Kennung(en) sind noch nicht eingelöst.' : '' ?>">Einteilung herausgeben (<?= $toSend ?> E-Mail<?= $toSend === 1 ? '' : 's' ?>)</button>
      </div>
    </form>
    <?php endif; ?>

    <h2 id="team">Team: Chairs, Coordinators, Organisation</h2>
    <p class="help">Gilt sofort. <strong>Chair</strong> = Chair-Rechte im gewählten Gremium. <strong>MUN Coordinator</strong> = sieht alle Gremien als Zuschauer und überarbeitet abgegebene Resolutionen, bevor sie freigegeben werden. <strong>Admin</strong> = Chair-Rechte in allen Gremien. <strong>Website-Admin</strong> = darf aus dem Teilnehmer-Bereich direkt in diesen Admin-Bereich wechseln (ohne Admin-Passwort; der Bestätigungscode der Zwei-Faktor-Anmeldung wird weiterhin abgefragt). <strong>Team-Profil</strong> = die Person kann Foto und Kurzvorstellung ihres Eintrags auf der Team-Seite im Teilnehmer-Bereich selbst bearbeiten.</p>
    <?php if (!$team): ?><p class="empty">Noch keine Anmeldungen aus dem Team.</p><?php else: ?>
    <form method="post" class="alloc-form">
      <?= csrf_field() ?><input type="hidden" name="a" value="team_save">
      <div class="table-wrap"><table class="alloc-table">
        <thead><tr><th>Kennung</th><th>Teilnahme</th><th>Gremium</th><th>Chair</th><th>MUN Coordinator</th><th>Admin</th><th>Website-Admin</th><th>Team-Profil</th></tr></thead>
        <tbody>
        <?php foreach ($team as $r): $id = e($r['id']); ?>
          <tr<?= ($r['status'] ?? '') === 'cancelled' ? ' class="cancelled"' : '' ?>>
            <td><strong><?= e(reg_display($r)) ?></strong><br><small><?= e($r['email'] ?? '') ?></small></td>
            <td><?= e($r['role'] ?? '') ?><?= !empty($r['role_pending']) ? ' <small>(Bestätigung offen)</small>' : '' ?><?= ($r['status'] ?? '') === 'cancelled' ? ' <small>(abgesagt)</small>' : '' ?></td>
            <td><?= committee_select('committee[' . $r['id'] . ']', (string) ($r['assigned_committee'] ?? '')) ?></td>
            <td><input type="checkbox" name="chair[<?= $id ?>]" value="1"<?= !empty($r['is_chair']) ? ' checked' : '' ?> aria-label="Chair"></td>
            <td><input type="checkbox" name="coordinator[<?= $id ?>]" value="1"<?= !empty($r['is_coordinator']) ? ' checked' : '' ?> aria-label="MUN Coordinator"></td>
            <td><input type="checkbox" name="admin[<?= $id ?>]" value="1"<?= !empty($r['is_admin']) ? ' checked' : '' ?> aria-label="Admin"></td>
            <td><input type="checkbox" name="site_admin[<?= $id ?>]" value="1"<?= !empty($r['is_site_admin']) ? ' checked' : '' ?> aria-label="Website-Admin"></td>
            <td><select name="profile[<?= $id ?>]" aria-label="Team-Profil"><option value="">– keins –</option>
              <?php foreach (c('team', []) as $m): ?><option value="<?= e($m['id']) ?>"<?= ($r['team_profile'] ?? '') === $m['id'] ? ' selected' : '' ?>><?= e(trim(str_replace('*', '', (string) $m['name']))) ?> (<?= e($m['group'] ?? '') ?>)</option><?php endforeach; ?>
            </select></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="save-bar"><button class="btn">Team-Zuordnung speichern</button></div>
    </form>
    <?php endif; ?>
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
    <?php legacy_box($regs); ?>
    <?php $pending = array_filter($regs, fn ($r) => !empty($r['role_pending'])); ?>
    <?php if ($pending): ?>
      <div class="pending-box">
        <h2>Warten auf Bestätigung (<?= count($pending) ?>)</h2>
        <p class="help">Team-Anmeldungen bestätigt ihr mit dem Knopf direkt an der Anmeldung; Delegierte gelten als bestätigt, sobald ihre Einteilung herausgegeben ist. Chairs bekommen nach der Bestätigung Chair-Rechte für ihr gewähltes Gremium, Conference Manager können alle Resolutionen ansehen (nicht bearbeiten). Zugangsdaten haben sie schon per Mail bekommen. Nicht bestätigen = Anmeldung löschen oder auf „Abgesagt“ setzen.</p>
        <ul>
          <?php foreach ($pending as $r): ?>
            <li>
              <span><strong><?= e(reg_display($r)) ?></strong> · <?= e(($r['kind'] ?? '') === 'manager' ? 'Conference Manager' : 'Chair' . (($r['assigned_committee'] ?? '') !== '' ? ' – ' . committee_display($r['assigned_committee']) : '')) ?> · <?= e($r['email']) ?></span>
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
    <input type="search" class="filter" placeholder="Suchen (Kennung, E-Mail, Land …)" data-filter=".reg">
    <div class="regs">
      <?php foreach ($regs as $r): ?>
        <?php $st = $r['status'] ?? 'received'; ?>
        <details class="reg" id="reg-<?= e($r['id']) ?>"<?= ($_GET['open'] ?? '') === $r['id'] ? ' open' : '' ?>>
          <summary>
            <strong><?= e(reg_display($r)) ?><?= !empty($r['is_chair']) ? ' <span class="st st-paper">Chair</span>' : '' ?><?= !empty($r['is_manager']) ? ' <span class="st st-paper">Conf. Manager</span>' : '' ?><?= !empty($r['is_admin']) ? ' <span class="st st-paper">Admin</span>' : '' ?><?= !empty($r['role_pending']) ? ' <span class="st st-waitlist">Bestätigung offen</span>' : '' ?> <span class="st st-<?= e($st) ?>"><?= e($statuses[$st][1] ?? $st) ?></span><?= !empty($r['paper']) ? ' <span class="st st-paper">Paper</span>' : '' ?></strong>
            <span><?= e(implode(' · ', array_filter([$r['role'] ?? '', $r['email'] ?? '']))) ?><?= !empty($r['assigned_country']) && is_delegate_reg($r) ? ' · ' . e($r['assigned_country']) : '' ?><?= !empty($r['assigned_committee']) ? ' (' . e(committee_display($r['assigned_committee'])) . ')' : '' ?></span>
            <small><?= e(date('d.m.Y H:i', strtotime($r['created']))) ?></small>
            <?php if (!is_delegate_reg($r) && ($st === 'received' || !empty($r['role_pending']))): ?>
              <form method="post" class="reg-confirm"><?= csrf_field() ?><input type="hidden" name="a" value="reg_confirm"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn btn-small">Bestätigen</button></form>
            <?php endif; ?>
          </summary>
          <dl>
            <?php foreach ($fields as $k => [$label]): ?>
              <?php if (($r[$k] ?? '') !== ''): ?><dt><?= e($label) ?></dt><dd><?= nl2br(e($k === 'code' ? format_code($r[$k]) : $r[$k])) ?></dd><?php endif; ?>
            <?php endforeach; ?>
          </dl>
          <form method="post" class="reg-edit">
            <?= csrf_field() ?><input type="hidden" name="a" value="reg_update"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <label>Status<select name="status"><?php foreach ($statuses as $key => [, $label]): ?><option value="<?= e($key) ?>"<?= $st === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <?php if (is_delegate_reg($r)): ?><label>Land (zugeteilt)<input name="assigned_country" value="<?= e($r['assigned_country'] ?? '') ?>" placeholder="z. B. Brazil"></label><?php endif; ?>
            <?php if (is_delegate_reg($r) || !empty($r['is_chair']) || ($r['kind'] ?? '') === 'chair'): ?><label>Gremium (zugeteilt)<?= committee_select('assigned_committee', (string) ($r['assigned_committee'] ?? '')) ?></label><?php endif; ?>
            <label class="check-inline"><input type="checkbox" name="is_chair" value="1"<?= !empty($r['is_chair']) ? ' checked' : '' ?>> Chair dieses Gremiums (darf Resolution bearbeiten, Amendments entscheiden, Beamer-Ansicht)</label>
            <label class="check-inline"><input type="checkbox" name="is_manager" value="1"<?= !empty($r['is_manager']) ? ' checked' : '' ?>> Conference Manager (sieht alle Resolutionen und die Beamer-Ansicht, darf nichts ändern)</label>
            <label class="check-inline"><input type="checkbox" name="is_coordinator" value="1"<?= !empty($r['is_coordinator']) ? ' checked' : '' ?>> MUN Coordinator (sieht alle Gremien als Zuschauer, überarbeitet abgegebene Resolutionen und gibt sie frei)</label>
            <label class="check-inline"><input type="checkbox" name="is_admin" value="1"<?= !empty($r['is_admin']) ? ' checked' : '' ?>> Admin (Chair-Rechte in allen Gremien, ohne Land/Gremium – kein Zugang zu diesem Admin-Bereich)</label>
            <label class="wide">Interne Notiz (nur im Admin sichtbar)<textarea name="admin_note" rows="2"><?= e($r['admin_note'] ?? '') ?></textarea></label>
            <button class="btn">Speichern</button>
          </form>
          <div class="reg-actions">
            <?php if (!empty($r['paper'])): ?>
              <a class="btn-ghost" href="<?= e(admin_url(['s' => 'paper', 'id' => $r['id']])) ?>">Position Paper herunterladen (<?= e(human_size((int) $r['paper']['size'])) ?>, <?= e(date('d.m.Y', strtotime($r['paper']['uploaded']))) ?>)</a>
            <?php endif; ?>
            <a class="btn-ghost" href="<?= e(admin_url(['s' => 'certificate', 'id' => $r['id']])) ?>" target="_blank">Zertifikat (Vorschau)</a>
            <form method="post" data-confirm="Neues Passwort erzeugen und zusammen mit der Kennung an <?= e($r['email']) ?> schicken? Das bisherige Passwort gilt dann nicht mehr."><?= csrf_field() ?><input type="hidden" name="a" value="reg_sendcreds"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn-ghost">Neue Zugangsdaten senden</button></form>
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

/**
 * Switching old registrations (with name and school) to personal codes:
 * 1. give each a code, 2. download the list for your own records, 3. delete
 * names and schools here, 4. send everyone their code + a new password.
 */
function legacy_box(array $regs): void
{
    $legacy = legacy_registrations();
    $noCode = count(array_filter($regs, fn ($r) => ($r['code'] ?? '') === ''));
    $unsent = count(array_filter($regs, fn ($r) => !empty($r['migrated']) && empty($r['creds_sent']) && ($r['code'] ?? '') !== '' && ($r['status'] ?? '') !== 'cancelled'));
    if (!$legacy && !$noCode && !$unsent) {
        return;
    }
    ?>
    <div class="pending-box">
      <h2>Alte Anmeldungen auf Kennungen umstellen</h2>
      <p class="help">Namen und Schulen dürfen auf der Website nicht mehr gespeichert werden. <?= count($legacy) ?> Anmeldung<?= count($legacy) === 1 ? '' : 'en' ?> enthalten sie noch<?= $noCode ? ', ' . $noCode . ' haben noch keine Kennung' : '' ?>. In dieser Reihenfolge:</p>
      <ol class="steps">
        <li><strong>Kennungen vergeben</strong> – jede alte Anmeldung bekommt eine neue Kennung.
          <?php if ($noCode): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="legacy_assign_codes"><button class="btn">Kennungen für <?= $noCode ?> Anmeldung<?= $noCode === 1 ? '' : 'en' ?> vergeben</button></form>
          <?php else: ?> <span class="st st-confirmed">erledigt</span><?php endif; ?></li>
        <li><strong>Liste herunterladen</strong> – Kennung, Name, Schule, E-Mail. Für eure eigene Namensliste außerhalb der Website.
          <?php if ($legacy && !$noCode): ?><a class="btn-ghost" href="<?= e(admin_url(['s' => 'legacy_csv'])) ?>">Liste herunterladen (CSV)</a>
          <?php elseif (!$legacy): ?> <span class="st st-confirmed">Namen schon gelöscht</span><?php endif; ?></li>
        <li><strong>Namen und Schulen löschen</strong> – endgültig, aus allen Anmeldungen. Erst nach Schritt 2!
          <?php if ($legacy && !$noCode): ?>
            <form method="post" class="inline-confirm" data-confirm="Namen und Schulen aus allen Anmeldungen endgültig löschen? Habt ihr die Liste aus Schritt 2 gespeichert?"><?= csrf_field() ?><input type="hidden" name="a" value="legacy_strip">
              <label>Zum Bestätigen <code>LÖSCHEN</code> eintippen: <input name="confirm" autocomplete="off"></label><button class="btn danger">Namen und Schulen löschen</button></form>
          <?php elseif (!$legacy): ?> <span class="st st-confirmed">erledigt</span><?php endif; ?></li>
        <li><strong>Zugangsdaten senden</strong> – jede Person bekommt per E-Mail ihre Kennung und ein neues Passwort (das alte Login per E-Mail-Adresse funktioniert nicht mehr).
          <?php if ($unsent): ?>
            <form method="post" data-confirm="<?= $unsent ?> E-Mails mit Kennung und neuem Passwort senden?"><?= csrf_field() ?><input type="hidden" name="a" value="legacy_sendcreds"><button class="btn">An <?= $unsent ?> Person<?= $unsent === 1 ? '' : 'en' ?> senden</button></form>
          <?php elseif (!$noCode): ?> <span class="st st-confirmed">erledigt</span><?php endif; ?></li>
      </ol>
    </div>
    <?php
}

function view_codes(): void
{
    $codes = codes();
    $regs = array_column(read_json(REGISTRATIONS_FILE), null, 'id');
    $free = array_filter($codes, fn ($c) => empty($c['reg']));
    ksort($codes);
    ?>
    <div class="page-title"><h1>Kennungen</h1></div>
    <p class="help">Jede Person meldet sich mit einer persönlichen Kennung an, nicht mit ihrem Namen. Kennungen erzeugt ihr mit dem Generator
      (Programm „OMUN Kennungen“ für Mac und Windows, im Projektordner unter <code>tools/kennungen-generator</code>; es führt die Liste Kennung + Name in <code>OMUN-Kennungen.csv</code> auf eurem Schreibtisch). Ist der Generator mit der Website verbunden (siehe unten), erscheinen neue Kennungen hier automatisch.
      <strong>Die Liste, wer welche Kennung bekommen hat, bleibt bei euch und kommt nie auf die Website.</strong></p>
    <div class="summary">
      <div><h3>Kennungen</h3><ul>
        <li><span>Insgesamt</span><strong><?= count($codes) ?></strong></li>
        <li><span>Frei</span><strong><?= count($free) ?></strong></li>
        <li><span>Verwendet (angemeldet)</span><strong><?= count($codes) - count($free) ?></strong></li>
      </ul></div>
    </div>
    <?php $newKey = $_SESSION['generator_key'] ?? null; unset($_SESSION['generator_key']); ?>
    <div class="edit-form" id="generator">
      <h2>Verbindung zum Kennungen-Generator</h2>
      <p class="help">Mit einem Schlüssel schickt der Generator jede neue Kennung sofort an die Website – das Hochladen einer Datei entfällt. Übertragen werden nur die Kennungen, nie Namen. Im Generator unter „Verbindung zur Website“ die Adresse <code><?= e(site_origin() . url('')) ?></code> und den Schlüssel eintragen.</p>
      <?php if ($newKey): ?>
        <p class="flash flash-warn"><strong>Neuer Schlüssel (wird nur jetzt angezeigt):</strong><br><code style="font-size: 1rem; user-select: all"><?= e($newKey) ?></code><br>Bitte jetzt in den Generator kopieren. Ein früherer Schlüssel gilt nicht mehr.</p>
      <?php endif; ?>
      <p><?= generator_key_set() ? '<span class="st st-confirmed">Verbunden</span> Ein Schlüssel ist eingerichtet (seit ' . e(date('d.m.Y', strtotime((string) (read_json(GENERATOR_FILE)['created'] ?? 'now')))) . ').' : '<span class="st st-cancelled">Nicht verbunden</span> Noch kein Schlüssel.' ?></p>
      <div class="save-bar">
        <form method="post"<?= generator_key_set() ? ' data-confirm="Neuen Schlüssel erzeugen? Der bisherige gilt dann nicht mehr und muss im Generator ersetzt werden."' : '' ?>><?= csrf_field() ?><input type="hidden" name="a" value="generator_key"><button class="btn"><?= generator_key_set() ? 'Neuen Schlüssel erzeugen' : 'Schlüssel erzeugen' ?></button></form>
        <?php if (generator_key_set()): ?><form method="post" data-confirm="Verbindung trennen?"><?= csrf_field() ?><input type="hidden" name="a" value="generator_key_remove"><button class="btn-ghost danger">Verbindung trennen</button></form><?php endif; ?>
      </div>
    </div>

    <?php $tests = array_values(array_filter(read_json(REGISTRATIONS_FILE), fn ($r) => !empty($r['test']))); ?>
    <div class="edit-form" id="test">
      <h2>Test-Delegierte</h2>
      <p class="help">Vier Delegierte zum Ausprobieren (Einteilung, Teilnehmer-Bereich, Position Papers, Resolution Editor): Kennungen <strong><?= e(implode(', ', array_map('format_code', TEST_CODES))) ?></strong> – beim Login geht auch z. B. <code>1234test</code>. Ohne E-Mail-Adresse, mit einem gemeinsamen, einfachen Passwort, das ihr hier festlegt. Sie sind in allen Listen mit „TEST“ markiert. Bei der Einteilung zählen sie <strong>nicht</strong> mit: Sie belegen keinen Platz und werden vom Algorithmus nicht verteilt. Unter Zuteilung stehen sie am Ende der Tabelle – dort Land und Gremium von Hand eintragen und mit „Einteilung herausgeben“ freischalten (ohne E-Mail). Vor der Konferenz wieder löschen, sonst erscheinen sie im Gremium (Roll Call, Position Papers).</p>
      <p><?= $tests ? '<span class="st st-waitlist">' . count($tests) . ' Test-Delegierte vorhanden</span>' : 'Zurzeit gibt es keine Test-Delegierten.' ?></p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="a" value="test_create">
        <div class="field"><label>Passwort für alle Test-Delegierten (mind. 6 Zeichen)<input name="password" required minlength="6" autocomplete="off"></label></div>
        <div class="save-bar"><button class="btn"><?= $tests ? 'Passwort neu setzen' : 'Test-Delegierte anlegen' ?></button></div>
      </form>
      <?php if ($tests): ?><form method="post" data-confirm="Alle Test-Delegierten mit ihren Position Papers löschen?"><?= csrf_field() ?><input type="hidden" name="a" value="test_delete"><button class="btn-ghost danger">Test-Delegierte löschen</button></form><?php endif; ?>
    </div>

    <form class="edit-form" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="a" value="codes_add">
      <h2>Kennungen hinzufügen</h2>
      <div class="field"><label>Datei „kennungen-upload-….txt“ aus dem Generator<input type="file" name="codes_file" accept=".txt,.csv,text/plain,text/csv"></label></div>
      <div class="field"><label>… oder hier einfügen (eine pro Zeile)<textarea name="codes" rows="5" placeholder="K7QF-M3XP&#10;R2WD-9HTA"></textarea></label>
        <p class="help">Nur die Kennung wird übernommen. Steht in einer Zeile noch ein Name dahinter (z. B. aus der CSV mit Namen), wird er ignoriert und nicht gespeichert.</p></div>
      <div class="save-bar"><button class="btn">Hinzufügen</button></div>
    </form>
    <?php if ($codes): ?>
      <input type="search" class="filter" placeholder="Kennung suchen …" data-filter=".code-list li">
      <ul class="item-list code-list">
        <?php foreach ($codes as $code => $c): $reg = !empty($c['reg']) ? ($regs[$c['reg']] ?? null) : null; ?>
          <li><span class="item-title"><code><?= e(format_code((string) $code)) ?></code>
            <small><?= $reg ? 'angemeldet als ' . e($reg['role'] ?? '–') . ' · ' . e(date('d.m.Y', strtotime($reg['created']))) : 'frei' ?></small></span>
            <?php if ($reg): ?>
              <a class="btn-ghost" href="<?= e(admin_url(['s' => 'registrations', 'open' => $reg['id']])) ?>#reg-<?= e($reg['id']) ?>">Anmeldung</a>
            <?php else: ?>
              <form method="post" data-confirm="Kennung <?= e(format_code((string) $code)) ?> löschen? Damit kann sich niemand mehr anmelden."><?= csrf_field() ?><input type="hidden" name="a" value="codes_delete"><input type="hidden" name="code" value="<?= e((string) $code) ?>"><button class="btn-ghost danger">Löschen</button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($free): ?>
        <form class="danger-zone" method="post" data-confirm="Alle <?= count($free) ?> unbenutzten Kennungen löschen? Verwendete Kennungen bleiben.">
          <?= csrf_field() ?><input type="hidden" name="a" value="codes_delete_unused">
          <h3>Unbenutzte Kennungen löschen</h3>
          <p>Z. B. nach der Anmeldephase oder nach der Konferenz. Kennungen, mit denen sich jemand angemeldet hat, bleiben erhalten.</p>
          <button class="btn danger">Alle <?= count($free) ?> unbenutzten löschen</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
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
    <?php $smtp = is_file(SMTP_FILE) ? read_json(SMTP_FILE) : []; ?>
    <form class="edit-form" method="post" id="smtp">
      <?= csrf_field() ?>
      <h2>E-Mail-Versand über ein Postfach</h2>
      <p class="help"><strong>Damit E-Mails nicht im Spam landen:</strong> Ohne Postfach verschickt der Webserver die Mails selbst – unsigniert, und viele Anbieter (Gmail, Outlook) sortieren sie deshalb als Spam ein oder lehnen sie ab. Tragt hier ein echtes Postfach eurer Domain ein (bei Strato: Server <code>smtp.strato.de</code>, Port <code>465</code>, Benutzername = die E-Mail-Adresse des Postfachs, dazu dessen Passwort). Alle E-Mails der Website kommen dann von diesem Postfach (z. B. <code>noreply@omun.eu</code>); Antworten gehen an die Kontakt-Adresse der Website (<code><?= e((string) c('site.email')) ?></code>). Aktueller Absender: <code><?= e(mail_from_address()) ?></code>. Das Passwort wird nur auf dem Server gespeichert und ist nicht im Inhalts-Backup enthalten.</p>
      <p><?= smtp_settings() ? '<span class="st st-confirmed">Aktiv</span> E-Mails gehen über ' . e($smtp['host']) . '.' : '<span class="st st-cancelled">Nicht eingerichtet</span> E-Mails gehen über den einfachen Versand des Servers.' ?></p>
      <div class="field"><label>Mailserver (SMTP)<input name="smtp_host" value="<?= e($smtp['host'] ?? '') ?>" placeholder="smtp.strato.de"></label></div>
      <div class="field"><label>Port<input name="smtp_port" value="<?= e((string) ($smtp['port'] ?? 465)) ?>" inputmode="numeric"></label></div>
      <div class="field"><label>Benutzername (E-Mail-Adresse des Postfachs)<input name="smtp_user" value="<?= e($smtp['user'] ?? '') ?>" autocomplete="off"></label></div>
      <div class="field"><label>Passwort des Postfachs<input type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= !empty($smtp['pass']) ? 'gespeichert – leer lassen, um es zu behalten' : '' ?>"></label></div>
      <div class="field"><label>Test-Mail an<input type="email" name="smtp_to" placeholder="eure eigene Adresse, z. B. bei Gmail"></label></div>
      <div class="save-bar">
        <button class="btn" name="a" value="smtp_save">Speichern</button>
        <button class="btn-ghost" name="a" value="smtp_test">Speichern und Test-Mail senden</button>
      </div>
      <p class="help">Server und Benutzername leeren und speichern = Postfach wieder entfernen.</p>
    </form>    <form class="edit-form" method="post">
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
    $deploy = deploy_settings();
    $checked = $_SESSION['gh_checked'] ?? null;
    $meta = $checked ? github_cached() : null;
    $newToken = $_SESSION['mcp_new_token'] ?? null;
    unset($_SESSION['mcp_new_token']);
    $hub = hub_settings();
    $hubToken = $_SESSION['hub_new_token'] ?? null;
    unset($_SESSION['hub_new_token']);
    $hubUrl = site_origin() . url('api/hub');
    $mcpUrl = site_origin() . url('mcp');
    $pw = fn () => '<div class="field"><label>Admin-Passwort zur Bestätigung<input type="password" name="password" required autocomplete="current-password"></label></div>';
    ?>
    <div class="page-title"><h1>Update</h1><span class="muted">Installierte Version: <strong><?= e(current_version()) ?></strong></span></div>

    <div class="edit-form" id="github">
      <h2>Update von GitHub holen</h2>
      <p>Der Server lädt den aktuellen Stand direkt von GitHub
        (<a href="<?= e(github_branch_url()) ?>" target="_blank" rel="noopener"><?= e(GITHUB_REPO) ?>, Branch <code><?= e(github_branch()) ?></code></a>)
        und spielt ihn ein – wie eine hochgeladene Update-Zip, mit Sicherung vorher.
        <strong>Texte, Einstellungen, Passwort, Bilder und Anmeldungen</strong> bleiben unverändert.</p>
      <?php if ($meta):
          $cmp = version_cmp($meta['version'], current_version()); ?>
        <div class="flash <?= $cmp > 0 ? 'flash-ok' : 'flash-warn' ?>">
          GitHub: <strong>Version <?= e($meta['version']) ?></strong><?= $meta['commit'] ? ' · Commit <code>' . e(substr($meta['commit'], 0, 7)) . '</code>' : '' ?>
          · installiert: <?= e(current_version()) ?> –
          <?= $cmp > 0 ? 'neuere Version verfügbar.' : ($cmp === 0 ? 'gleiche Versionsnummer, also vermutlich schon aktuell.' : '<strong>Achtung: Das ist eine ÄLTERE Version als die installierte.</strong>') ?>
        </div>
        <form method="post" data-confirm="Version <?= e($meta['version']) ?> von GitHub jetzt einspielen?">
          <?= csrf_field() ?><input type="hidden" name="a" value="gh_install"><input type="hidden" name="commit" value="<?= e($meta['commit']) ?>">
          <?= $pw() ?>
          <div class="save-bar"><button class="btn">Version <?= e($meta['version']) ?> einspielen</button></div>
        </form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="gh_check"><button class="btn-ghost">Erneut prüfen</button></form>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="gh_check">
          <div class="save-bar"><button class="btn">Auf GitHub nach Updates suchen</button></div></form>
      <?php endif; ?>
    </div>

    <details class="edit-form">
      <summary><strong>Update-Zip manuell hochladen</strong></summary>
      <form method="post" enctype="multipart/form-data" data-confirm="Update jetzt einspielen?">
        <?= csrf_field() ?><input type="hidden" name="a" value="update">
        <p>Alternativ eine Update-Zip hochladen (z. B. eine Sicherung von unten). <code>data/</code> und <code>uploads/</code> in der Zip werden ignoriert.</p>
        <div class="field"><label>Update-Zip<input type="file" name="package" accept=".zip,application/zip" required></label></div>
        <?= $pw() ?>
        <div class="save-bar"><button class="btn">Update einspielen</button></div>
      </form>
    </details>

    <div class="edit-form" id="backups">
      <h2>Sicherungen der vorherigen Versionen</h2>
      <?php if (!$backups): ?>
        <p class="muted">Noch keine – eine Sicherung entsteht automatisch bei jedem Update.</p>
      <?php else: ?>
        <p>Falls nach einem Update etwas nicht funktioniert: die Version von vorher wieder einspielen.</p>
        <ul class="item-list">
          <?php foreach ($backups as $b): ?>
            <li><span class="item-title"><?= e($b['file']) ?><small><?= e(date('d.m.Y, H:i', $b['time'])) ?> Uhr · <?= e(human_size($b['size'])) ?></small></span>
              <a class="btn-ghost" href="<?= e(admin_url(['s' => 'code_backup', 'file' => $b['file']])) ?>">Download</a></li>
          <?php endforeach; ?>
        </ul>
        <form method="post" data-confirm="Diese Sicherung jetzt wieder einspielen?">
          <?= csrf_field() ?><input type="hidden" name="a" value="backup_restore">
          <div class="field"><label>Sicherung wiederherstellen<select name="file">
            <?php foreach ($backups as $b): ?><option value="<?= e($b['file']) ?>"><?= e($b['file']) ?></option><?php endforeach; ?>
          </select></label></div>
          <?= $pw() ?>
          <div class="save-bar"><button class="btn-ghost">Sicherung einspielen</button></div>
        </form>
      <?php endif; ?>
    </div>

    <div class="edit-form" id="mcp">
      <h2>Claude-Zugang (MCP)</h2>
      <p>Damit kann Claude Updates selbst von GitHub einspielen und bei Problemen eine Sicherung zurückholen – nicht mehr.
        Claude kann darüber <strong>keine</strong> Inhalte, Anmeldungen oder Passwörter lesen oder ändern und nur den Branch oben installieren.
        Status: <strong><?= !empty($deploy['mcp_enabled']) ? 'eingeschaltet' : 'ausgeschaltet' ?></strong><?= $deploy['mcp_token_created'] ? ' · Schlüssel erstellt am ' . e(date('d.m.Y, H:i', strtotime($deploy['mcp_token_created']))) : '' ?>.</p>
      <?php if ($newToken): ?>
        <div class="flash flash-warn">
          <p><strong>Neuer Schlüssel – wird nur jetzt angezeigt.</strong> Wer diesen Link hat, kann Updates einspielen. Nicht weitergeben, nicht in Git oder Chats posten.</p>
          <p><strong>Claude.ai / Claude-App:</strong> Einstellungen → Connectors → Benutzerdefinierten Connector hinzufügen → als URL eintragen:</p>
          <p><input type="text" readonly value="<?= e($mcpUrl . '/' . $newToken) ?>" onclick="this.select()" style="width:100%;font-family:monospace"></p>
          <p><strong>Claude Code (Terminal):</strong></p>
          <p><input type="text" readonly value="<?= e('claude mcp add --transport http omun ' . $mcpUrl . ' --header "Authorization: Bearer ' . $newToken . '"') ?>" onclick="this.select()" style="width:100%;font-family:monospace"></p>
        </div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="a" value="deploy_settings">
        <div class="field"><label><input type="checkbox" name="mcp_enabled" value="1"<?= !empty($deploy['mcp_enabled']) ? ' checked' : '' ?>> Claude-Zugang eingeschaltet</label></div>
        <div class="field"><label><input type="checkbox" name="new_token" value="1"> Neuen Schlüssel erstellen (der alte gilt dann nicht mehr)</label></div>
        <div class="field"><label>GitHub-Branch<input type="text" name="branch" value="<?= e(github_branch()) ?>" required></label>
          <small class="muted">Aus diesem Branch von <?= e(GITHUB_REPO) ?> werden Updates geholt (Button oben und Claude).</small></div>
        <?= $pw() ?>
        <div class="save-bar"><button class="btn">Speichern</button></div>
      </form>
    </div>

    <div class="edit-form" id="hub">
      <h2>Hub-API (Admin-Dashboard)</h2>
      <p>Schnittstelle nur zum <strong>Lesen</strong> für das externe Dashboard: Version, Kennzahlen, Protokoll, Admin-Zugänge und fehlgeschlagene Logins
        (<code><?= e($hubUrl) ?>/health</code>, <code>/stats</code>, <code>/audit</code>, <code>/admins</code>, <code>/errors</code>).
        Sie gibt <strong>keine</strong> Namen, E-Mail-Adressen, Kennungen, Passwörter oder Schlüssel heraus und kann nichts ändern. Details: <code>HUB_API.md</code>.
        Status: <strong><?= hub_enabled() ? 'eingeschaltet' : 'ausgeschaltet' ?></strong><?= $hub['hub_token_created'] ? ' · Token erstellt am ' . e(date('d.m.Y, H:i', strtotime($hub['hub_token_created']))) : '' ?><?= hub_env_token() !== '' ? ' · zusätzlich Token aus der Umgebungsvariable <code>HUB_TOKEN</code> aktiv' : '' ?>.</p>
      <?php if ($hubToken): ?>
        <div class="flash flash-warn">
          <p><strong>Neuer Token – wird nur jetzt angezeigt.</strong> Im Dashboard als <code>HUB_TOKEN</code> (serverseitig) eintragen. Nicht weitergeben, nicht in Git oder Chats posten.</p>
          <p><input type="text" readonly value="<?= e($hubToken) ?>" onclick="this.select()" style="width:100%;font-family:monospace"></p>
          <p><strong>Test:</strong></p>
          <p><input type="text" readonly value="<?= e('curl -H "Authorization: Bearer ' . $hubToken . '" ' . $hubUrl . '/health') ?>" onclick="this.select()" style="width:100%;font-family:monospace"></p>
        </div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="a" value="hub_settings">
        <div class="field"><label><input type="checkbox" name="hub_enabled" value="1"<?= !empty($hub['hub_enabled']) ? ' checked' : '' ?>> Hub-API eingeschaltet</label></div>
        <div class="field"><label><input type="checkbox" name="new_hub_token" value="1"> Neuen Token erstellen (der alte gilt dann nicht mehr)</label></div>
        <?= $pw() ?>
        <div class="save-bar"><button class="btn">Speichern</button></div>
      </form>
    </div>

    <?php $log = deploy_log_entries(15); if ($log): ?>
    <div class="edit-form">
      <h2>Protokoll</h2>
      <ul class="item-list">
        <?php foreach ($log as $l): ?>
          <li><span class="item-title"><?= e($l['old'] . ' → ' . $l['new']) ?><?= $l['ok'] ? '' : ' · <strong>fehlgeschlagen</strong>' ?>
            <small><?= e(date('d.m.Y, H:i', strtotime($l['time']))) ?> Uhr · <?= e(DEPLOY_SOURCES[$l['source']] ?? $l['source']) ?><?= $l['commit'] ? ' · Commit ' . e($l['commit']) : '' ?><?= $l['msg'] !== '' ? ' · ' . e($l['msg']) : '' ?></small></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif;
}

function view_resolutions(): void
{
    $base = split_portal() ? portal_home() : site_origin() . url('portal/');
    ?>
    <div class="page-title"><h1>Resolutionen</h1></div>
    <p class="help">Pro Gremium wird immer an einer Resolution gearbeitet; ist sie fertig, speichern die Chairs sie mit „Save &amp; start new resolution“ ab und beginnen eine neue. Chairs legt ihr unter <a href="<?= e(admin_url(['s' => 'registrations'])) ?>">Anmeldungen</a> fest (Häkchen „Chair“ + zugeteiltes Gremium).
      Als Admin habt ihr in jedem Gremium Chair-Rechte und könnt die Beamer-Ansicht öffnen.
      Teilnehmende mit dem Häkchen „Admin“ (unter Anmeldungen bzw. Zuteilung) haben Chair-Rechte in allen Gremien. Conference Manager und das Laptop-Konto sehen alle Gremien inkl. Beamer-Ansicht, können aber nichts ändern.</p>
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

/** Photo gallery in the portal: the photographer's album (release + farewell e-mail) and moderation of everyone's photos. */
function view_gallery(): void
{
    $state = gallery_state();
    $released = !empty($state['released']);
    $official = gallery_photos('official');
    $community = gallery_photos('community');
    $recipients = gallery_recipients();
    $mailed = count(array_filter(array_keys($recipients), fn ($e) => isset($state['mailed'][sha1($e)])));
    $album = ($_GET['album'] ?? '') === 'community' ? 'community' : 'official';
    $photos = $album === 'official' ? $official : $community;
    ?>
    <div class="page-title"><h1>Fotogalerie im Teilnehmer-Bereich</h1><a href="<?= e(gallery_link()) ?>" target="_blank">Im Teilnehmer-Bereich ansehen ↗</a></div>
    <p class="help">Die Galerie liegt im Teilnehmer-Bereich und ist nur für Eingeloggte sichtbar (alle Teilnahmearten und jeder Status) – nicht öffentlich wie die Galerie der Website.
      <strong>Everyone’s photos</strong>: Hier kann jede/r eigene Fotos hochladen, das Album ist sofort online (<?= c('portal.gallery_open', true) ? 'Hochladen gerade erlaubt' : 'Hochladen gerade <strong>aus</strong>' ?>; Schalter unter <a href="<?= e(admin_url(['s' => 'portal'])) ?>">Teilnehmer-Bereich</a>).
      <strong>Official photos</strong>: die Bilder des Fotografen – erst sichtbar, wenn ihr sie freigebt. Beim Hochladen werden alle Fotos verkleinert und Kameradaten (z. B. GPS-Ort) entfernt.</p>

    <div class="panel">
      <h2>Offizielles Album <span class="badge"><?= $released ? 'sichtbar seit ' . e(date('d.m.Y H:i', strtotime($state['released']))) : 'noch verborgen' ?></span></h2>
      <form method="post" enctype="multipart/form-data" action="<?= e(admin_url(['s' => 'photos'])) ?>" data-batch-upload>
        <?= csrf_field() ?><input type="hidden" name="a" value="gallery_upload">
        <label>Fotos des Fotografen hochladen (JPG/PNG, beliebig viele auf einmal)<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
        <p><button class="btn">Hochladen</button> <span class="help" data-progress><?= count($official) ?> Foto<?= count($official) === 1 ? '' : 's' ?> im offiziellen Album<?= $released ? '' : ' – für Teilnehmende noch unsichtbar' ?>.</span></p>
      </form>
    </div>

    <form method="post" class="panel" id="release">
      <?= csrf_field() ?>
      <h2>Freigeben und Abschieds-Mail</h2>
      <p class="help">„Freigeben und E-Mail senden“ macht das offizielle Album sichtbar und schickt die Mail an alle Anmeldungen mit E-Mail-Adresse (außer Abgesagten und Test-Delegierten), jede Adresse nur einmal: <strong><?= count($recipients) ?></strong> Empfänger<?= $mailed ? ', davon <strong>' . $mailed . '</strong> schon verschickt – nochmal drücken schickt nur an die übrigen' : '' ?>.
        Platzhalter: {gallery} (Link zu den Fotos), {portal}, {code}, {conference}, {email}.</p>
      <label>Betreff<input name="gallery_mail_subject" value="<?= e(c('portal.gallery_mail_subject')) ?>" maxlength="200"></label>
      <label>Text<textarea name="gallery_mail_text" rows="14"><?= e(c('portal.gallery_mail_text')) ?></textarea></label>
      <p class="actions-row">
        <button class="btn-ghost" name="a" value="gallery_mail_save">Text speichern</button>
        <?php if ($official && $released && $recipients && $mailed >= count($recipients)): ?>
          <span class="help">Die Abschieds-Mail ist an alle verschickt.</span>
        <?php elseif ($official): ?>
          <button class="btn" name="a" value="gallery_release" data-confirm="Offizielles Album freigeben und die Abschieds-Mail an <?= count($recipients) - $mailed ?> Empfänger senden?"><?= $mailed ? 'Abschieds-Mail an die übrigen senden' : 'Freigeben und E-Mail senden' ?></button>
          <?php if (!$released): ?><button class="btn-ghost" name="a" value="gallery_release_silent" data-confirm="Offizielles Album ohne E-Mail freigeben?">Nur freigeben (ohne E-Mail)</button><?php endif; ?>
        <?php else: ?>
          <span class="help">Freigeben geht, sobald Fotos im offiziellen Album sind.</span>
        <?php endif; ?>
        <?php if ($released): ?><button class="btn-ghost" name="a" value="gallery_hide" formnovalidate data-confirm="Offizielles Album im Teilnehmer-Bereich wieder ausblenden?">Wieder ausblenden</button><?php endif; ?>
      </p>
    </form>

    <div class="panel" id="photos">
      <nav class="range-tabs gallery-tabs">
        <a href="<?= e(admin_url(['s' => 'photos', 'album' => 'official'])) ?>#photos"<?= $album === 'official' ? ' class="active"' : '' ?>>Official photos (<?= count($official) ?>)</a>
        <a href="<?= e(admin_url(['s' => 'photos', 'album' => 'community'])) ?>#photos"<?= $album === 'community' ? ' class="active"' : '' ?>>Everyone’s photos (<?= count($community) ?>)</a>
      </nav>
      <?php if (!$photos): ?>
        <p class="empty">Noch keine Fotos.</p>
      <?php else: ?>
        <div class="actions-row">
          <a class="btn-ghost" href="<?= e(admin_url(['s' => 'gallery_zip', 'album' => $album])) ?>">Alle als ZIP herunterladen</a>
          <form method="post" class="inline" data-confirm="Wirklich ALLE <?= count($photos) ?> Fotos dieses Albums löschen? Das lässt sich nicht rückgängig machen."><?= csrf_field() ?><input type="hidden" name="a" value="gallery_delete_album"><input type="hidden" name="album" value="<?= e($album) ?>"><button class="btn-ghost danger">Ganzes Album löschen</button></form>
        </div>
        <ul class="admin-gallery">
          <?php foreach ($photos as $p): ?>
            <li>
              <a href="<?= e(admin_url(['s' => 'gallery_photo', 'id' => $p['id']])) ?>" target="_blank"><img src="<?= e(admin_url(['s' => 'gallery_photo', 'id' => $p['id'], 'size' => 't'])) ?>" alt="" loading="lazy"></a>
              <small><?= e(date('d.m. H:i', strtotime($p['at']))) ?></small>
              <form method="post" data-confirm="Dieses Foto löschen?"><?= csrf_field() ?><input type="hidden" name="a" value="gallery_delete"><input type="hidden" name="album" value="<?= e($album) ?>"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="icon danger" title="Löschen">✕</button></form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <script src="<?= e(url('assets/js/upload.js')) ?>?v=<?= filemtime(ROOT . '/assets/js/upload.js') ?>" defer></script>
    <?php
}
