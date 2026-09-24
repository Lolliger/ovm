<?php
declare(strict_types=1);

// Local development with `php -S localhost:8000 index.php`: serve real files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !preg_match('~/(data|lib|templates)/~', $file)) {
        return false;
    }
    if (is_dir($file) && realpath($file) !== __DIR__ && is_file(rtrim($file, '/') . '/index.php')) {
        $_SERVER['SCRIPT_NAME'] = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') . '/index.php';
        chdir($file);
        require rtrim($file, '/') . '/index.php';
        return true;
    }
}

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/templates/layout.php';
require __DIR__ . '/lib/registration.php';

purge_registrations_daily();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (BASE !== '' && str_starts_with($path, BASE)) {
    $path = substr($path, strlen(BASE));
}
$path = trim(rawurldecode($path), '/');
if ($path === 'index.php') {
    $path = '';
}
$parts = $path === '' ? [] : explode('/', $path);

function find_by_slug(string $list, string $slug): ?array
{
    foreach (c($list, []) as $item) {
        if (($item['slug'] ?? '') === $slug) {
            return $item;
        }
    }
    return null;
}

function not_found(): void
{
    http_response_code(404);
    render('404', ['title' => 'Page not found']);
    exit;
}

$route = $parts[0] ?? '';
$sub = $parts[1] ?? null;
if (count($parts) > 2) {
    not_found();
}

switch ($route) {
    case '':
        render('home', ['title' => null, 'nav' => 'home']);
        break;
    case 'conference':
        render('conference', ['title' => 'Conference', 'nav' => 'conference']);
        break;
    case 'committees':
        if ($sub === null) {
            render('committees', ['title' => 'Committees', 'nav' => 'committees']);
        } elseif ($item = find_by_slug('committees', $sub)) {
            render('committee', ['title' => $item['name'], 'nav' => 'committees', 'item' => $item]);
        } else {
            not_found();
        }
        break;
    case 'team':
        render('team', ['title' => 'Team', 'nav' => 'team']);
        break;
    case 'news':
        if ($sub === null) {
            render('news', ['title' => 'News', 'nav' => 'news']);
        } elseif (($item = find_by_slug('news', $sub)) && !empty($item['published'])) {
            render('article', ['title' => $item['title'], 'nav' => 'news', 'item' => $item]);
        } else {
            not_found();
        }
        break;
    case 'gallery':
        render('gallery', ['title' => 'Gallery', 'nav' => 'gallery']);
        break;
    case 'faq':
        render('faq', ['title' => 'FAQ', 'nav' => 'faq']);
        break;
    case 'downloads':
        render('downloads', ['title' => 'Downloads', 'nav' => 'downloads']);
        break;
    case 'sponsors':
        render('sponsors', ['title' => 'Sponsors & Partners', 'nav' => 'sponsors']);
        break;
    case 'archive':
        render('archive', ['title' => 'Archive', 'nav' => 'archive']);
        break;
    case 'register':
        $result = handle_registration();
        if ($result && $result['ok']) {
            header('Location: ' . url('register') . '?done=1', true, 303);
            exit;
        }
        if (isset($_GET['done'])) {
            $result = ['ok' => true, 'errors' => [], 'values' => []];
        }
        render('register', ['title' => 'Register', 'nav' => 'register', 'result' => $result]);
        break;
    case 'imprint':
    case 'impressum':
        render('legal', ['title' => 'Impressum', 'body' => c('legal.imprint')]);
        break;
    case 'privacy':
    case 'datenschutz':
        render('legal', ['title' => 'Datenschutzerklärung', 'body' => c('legal.privacy')]);
        break;
    default:
        if ($sub === null && ($item = find_by_slug('pages', $route))) {
            render('page', ['title' => $item['title'], 'nav' => 'page:' . $item['slug'], 'item' => $item]);
        } else {
            not_found();
        }
}
