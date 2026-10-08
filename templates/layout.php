<?php
declare(strict_types=1);

function published_news(): array
{
    $news = array_filter(c('news', []), fn ($n) => !empty($n['published']));
    usort($news, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    return $news;
}

/** Main menu entries: [key, label, href]. Sections without content are hidden. */
/** The fixed links in the header (Downloads also while still empty). */
function main_nav(): array
{
    return [
        ['conference', 'Conference', url('conference')],
        ['committees', 'Committees', url('committees')],
        ['team', 'Team', url('team')],
        ['downloads', 'Downloads', url('downloads')],
    ];
}

/** Everything else, in the header's "More" menu – only pages that have content. */
function more_nav(): array
{
    $nav = [];
    if (published_news()) {
        $nav[] = ['news', 'News', url('news')];
    }
    if (c('gallery', [])) {
        $nav[] = ['gallery', 'Gallery', url('gallery')];
    }
    if (c('faq', [])) {
        $nav[] = ['faq', 'FAQ', url('faq')];
    }
    if (c('sponsors', [])) {
        $nav[] = ['sponsors', 'Sponsors', url('sponsors')];
    }
    if (c('archive', [])) {
        $nav[] = ['archive', 'Archive', url('archive')];
    }
    foreach (c('pages', []) as $p) {
        if (!empty($p['in_nav'])) {
            $nav[] = ['page:' . $p['slug'], $p['title'], url($p['slug'])];
        }
    }
    return $nav;
}

/** The "More" pages plus pages shown only in the footer (footer and mobile menu). */
function secondary_nav(): array
{
    $nav = more_nav();
    foreach (c('pages', []) as $p) {
        if (!empty($p['in_footer']) && empty($p['in_nav'])) {
            $nav[] = ['page:' . $p['slug'], $p['title'], url($p['slug'])];
        }
    }
    return $nav;
}

function safe_color(string $key, string $fallback): string
{
    $v = (string) c('site.' . $key, $fallback);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $fallback;
}

/** Link targets from the admin may be "/register" (site-relative) or full URLs. */
function link_href(string $link): string
{
    if (preg_match('~^(https?:|mailto:)~i', $link)) {
        return $link;
    }
    return url(ltrim($link, '/'));
}

/**
 * Team names: a marked name (*…* or **…**, any part) is highlighted as a whole –
 * blue in light mode, gold in dark mode (escaped).
 */
function fancy_name(string $s): string
{
    $plain = e(plain_name($s));
    return preg_match('/\*.+?\*/', $s) ? '<span class="fx-name">' . $plain . '</span>' : $plain;
}

/** Name without the * / ** markers (for alt texts, initials, labels). */
function plain_name(string $s): string
{
    return trim(str_replace('*', '', $s));
}

/** Title with *emphasis* → <em>. */
function emph(string $s): string
{
    return preg_replace('/\*(.+?)\*/', '<em>$1</em>', nl2br(e($s), false));
}

function file_meta(string $path): string
{
    $file = ROOT . '/' . $path;
    $ext = strtoupper(pathinfo($path, PATHINFO_EXTENSION));
    if (!is_file($file)) {
        return $ext;
    }
    $size = filesize($file);
    $h = $size > 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, round($size / 1024)) . ' KB';
    return $ext . ' · ' . $h;
}

function conference_start_iso(): string
{
    $d = c('conference.date_start');
    if (!$d) {
        return '';
    }
    $t = preg_match('/^\d{1,2}:\d{2}$/', (string) c('conference.start_time')) ? c('conference.start_time') : '09:00';
    $ts = strtotime($d . ' ' . $t);
    return $ts ? date('c', $ts) : '';
}

function render(string $template, array $vars = []): void
{
    // Visitor statistics: public pages only (not the delegate area, login or error pages).
    if (in_array($template, ['home', 'conference', 'committees', 'committee', 'team', 'news', 'article', 'gallery', 'faq',
        'downloads', 'sponsors', 'archive', 'register', 'legal', 'page'], true) && http_response_code() === 200) {
        stats_track();
    }
    extract($vars);
    $nav = $vars['nav'] ?? '';
    $siteName = c('site.name', 'OMUN');
    $pageTitle = !empty($vars['title']) ? $vars['title'] . ' · ' . $siteName : $siteName . ' · ' . c('site.full_name');
    $description = c('site.description');
    $ogImage = c('site.og_image') ?: c('home.hero_image') ?: 'assets/img/og-default.png';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    ob_start();
    require __DIR__ . '/' . $template . '.php';
    $main = ob_get_clean();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:image" content="<?= e($origin . media($ogImage)) ?>">
<meta name="theme-color" content="<?= e(safe_color('color_ink', '#1e293b')) ?>">
<?= icon_links() ?>
<link rel="preload" href="<?= e(url('assets/fonts/newsreader-latin-opsz-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(url('assets/fonts/geist-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(url('assets/css/site.css')) ?>?v=<?= filemtime(ROOT . '/assets/css/site.css') ?>">
<style>
:root {
  --un: <?= safe_color('color_un', '#009edb') ?>;
  --accent: <?= safe_color('color_accent', '#046bd2') ?>;
  --ink: <?= safe_color('color_ink', '#1e293b') ?>;
  --paper: <?= safe_color('color_paper', '#f0f5fa') ?>;
}
</style>
<script src="<?= e(url('assets/js/site.js')) ?>?v=<?= filemtime(ROOT . '/assets/js/site.js') ?>" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<input type="checkbox" id="menu-toggle" class="menu-toggle" aria-label="Open menu">
<label for="menu-toggle" class="menu-overlay" aria-hidden="true"></label>

<aside class="side-menu" aria-label="Menu">
  <nav>
    <a href="<?= e(url()) ?>"<?= $nav === 'home' ? ' aria-current="page"' : '' ?>>Home</a>
    <?php foreach (main_nav() as [$key, $label, $href]): ?>
      <a href="<?= e($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($sec = secondary_nav()): ?>
      <span class="side-menu-rule"></span>
      <?php foreach ($sec as [$key, $label, $href]): ?>
        <a class="minor" href="<?= e($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('register')) ?>">Register</a>
    <a class="minor" href="<?= e(url('login')) ?>">Delegate login</a>
  </nav>
</aside>

<?php if (c('site.banner_show') && c('site.banner_text') && (!c('site.banner_delegates_only') || registration_delegates_open())): ?>
  <div class="banner">
    <div class="container">
      <?php if (c('site.banner_link')): ?>
        <a href="<?= e(link_href(c('site.banner_link'))) ?>"><?= e(c('site.banner_text')) ?> <span aria-hidden="true">→</span></a>
      <?php else: ?>
        <?= e(c('site.banner_text')) ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
// The menu is split around the centred OMUN: the first links left, the rest + "More" right.
$navMain = main_nav();
$navLeft = array_slice($navMain, 0, intdiv(count($navMain) + 2, 2));
$navRight = array_slice($navMain, count($navLeft));
?>
<header class="site-header">
  <div class="container header-row">
    <div class="header-start">
      <label for="menu-toggle" class="menu-btn" title="Menu">
        <span class="menu-btn-icon" aria-hidden="true"><i></i><i></i><i></i></span>
      </label>
      <nav class="main-nav" aria-label="Main">
        <?php foreach ($navLeft as [$key, $label, $href]): ?>
          <a href="<?= e($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <a class="brand" href="<?= e(url()) ?>" aria-label="<?= e($siteName . ' – ' . c('site.full_name')) ?>">
      <?php if (c('site.logo')): ?>
        <img src="<?= e(media(c('site.logo'))) ?>" alt="<?= e($siteName) ?>">
      <?php else: ?>
        <?= emblem('brand-emblem') ?>
        <span class="brand-name"><?= e($siteName) ?></span>
      <?php endif; ?>
    </a>
    <div class="header-end">
      <nav class="main-nav main-nav-end" aria-label="More pages">
        <?php foreach ($navRight as [$key, $label, $href]): ?>
          <a href="<?= e($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if ($more = more_nav()): ?>
          <details class="nav-more">
            <summary<?= in_array($nav, array_column($more, 0), true) ? ' class="current"' : '' ?>>More <svg aria-hidden="true" viewBox="0 0 12 12" width="10" height="10"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>
            <div class="nav-more-menu">
              <?php foreach ($more as [$key, $label, $href]): ?>
                <a href="<?= e($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
      </nav>
      <a class="header-login" href="<?= e(url('login')) ?>"<?= $nav === 'login' ? ' aria-current="page"' : '' ?>>Login</a>
      <a class="btn btn-small header-cta" href="<?= e(url('register')) ?>"<?= $nav === 'register' ? ' aria-current="page"' : '' ?>>Register</a>
    </div>
  </div>
</header>

<main id="main">
<?= $main ?>
</main>

<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <?= emblem('footer-emblem', 'gold') ?>
      <p class="footer-brand"><?= e($siteName) ?></p>
      <p class="footer-muted"><?= e(c('site.full_name')) ?><br><?= nl2br(e(c('site.address'))) ?></p>
    </div>
    <div>
      <p class="footer-label">Conference</p>
      <ul>
        <?php foreach (main_nav() as [$key, $label, $href]): ?>
          <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <li><a href="<?= e(url('register')) ?>">Register</a></li>
      </ul>
    </div>
    <div>
      <p class="footer-label">More</p>
      <ul>
        <?php foreach (secondary_nav() as [$key, $label, $href]): ?>
          <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <li><a href="<?= e(url('login')) ?>">Delegate login</a></li>
        <?php if (c('site.email')): ?><li><a href="mailto:<?= e(c('site.email')) ?>"><?= e(c('site.email')) ?></a></li><?php endif; ?>
        <?php if (c('site.instagram')): ?><li><a href="<?= e(c('site.instagram')) ?>" rel="noopener" target="_blank">Instagram</a></li><?php endif; ?>
        <?php if (c('site.school_url')): ?><li><a href="<?= e(c('site.school_url')) ?>" rel="noopener" target="_blank"><?= e(c('site.school')) ?></a></li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="container footer-bottom">
    <span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
    <span><a href="<?= e(url('imprint')) ?>">Impressum</a> · <a href="<?= e(url('privacy')) ?>">Datenschutz</a></span>
  </div>
</footer>
</body>
</html>
<?php
}
