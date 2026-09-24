<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/admin.php';
require dirname(__DIR__) . '/lib/registration.php';

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
    if ($action === 'login') {
        if (!csrf_check()) {
            $error = 'Sitzung abgelaufen, bitte erneut versuchen.';
        } elseif (rate_limited('login', 8, 900)) {
            $error = 'Zu viele Versuche. Bitte 15 Minuten warten.';
        } elseif (check_password((string) ($_POST['password'] ?? ''))) {
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
                    save_content($content);
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
                save_content($content);
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
                    $content[$s] = array_values($list);
                    save_content($content);
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
                    write_json(DATA_DIR . '/content-before-import-' . date('Ymd-His') . '.json', content());
                    save_content(array_intersect_key($data, $schema));
                    flash('Backup wiederhergestellt. Der vorherige Stand wurde im Ordner /data gesichert.');
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
<script src="<?= e(url('admin/admin.js')) ?>?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
</head>
    <?php
}

function admin_login_page(string $mode, string $error): void
{
    admin_head($mode === 'setup' ? 'Einrichtung' : 'Login');
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
    $titles = ['' => 'Übersicht', 'registrations' => 'Anmeldungen', 'media' => 'Dateien & Bilder', 'settings' => 'Passwort & Backup'];
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
    <a href="<?= e(admin_url(['s' => 'settings'])) ?>"<?= $s === 'settings' ? ' class="active"' : '' ?>>Passwort &amp; Backup</a>
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
    <div class="page-title"><h1>Passwort &amp; Backup</h1></div>
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
