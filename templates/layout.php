<?php
declare(strict_types=1);

function published_news(): array
{
    $news = array_filter(c('news', []), fn ($n) => !empty($n['published']));
    usort($news, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    return $news;
}

/** Main menu entries: [key, label, href]. Sections without content are hidden. */
function main_nav(): array
{
    $nav = [
        ['conference', 'Conference', url('conference')],
        ['committees', 'Committees', url('committees')],
        ['team', 'Team', url('team')],
    ];
    if (published_news()) {
        $nav[] = ['news', 'News', url('news')];
    }
    if (c('gallery', [])) {
        $nav[] = ['gallery', 'Gallery', url('gallery')];
    }
    if (c('faq', [])) {
        $nav[] = ['faq', 'FAQ', url('faq')];
    }
    foreach (c('pages', []) as $p) {
        if (!empty($p['in_nav'])) {
            $nav[] = ['page:' . $p['slug'], $p['title'], url($p['slug'])];
        }
    }
    return $nav;
}

function secondary_nav(): array
{
    $nav = [];
    if (c('downloads', [])) {
        $nav[] = ['downloads', 'Downloads', url('downloads')];
    }
    if (c('sponsors', [])) {
        $nav[] = ['sponsors', 'Sponsors', url('sponsors')];
    }
    if (c('archive', [])) {
        $nav[] = ['archive', 'Archive', url('archive')];
    }
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
    extract($vars);
    $nav = $vars['nav'] ?? '';
    $siteName = c('site.name', 'OMUN');
    $pageTitle = !empty($vars['title']) ? $vars['title'] . ' · ' . $siteName : $siteName . ' · ' . c('site.full_name');
    $description = c('site.description');
    $ogImage = c('site.og_image') ?: c('home.hero_image');
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
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($origin . media($ogImage)) ?>">
<?php endif; ?>
<meta name="theme-color" content="<?= e(safe_color('color_ink', '#1e293b')) ?>">
<link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
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
  </nav>
</aside>

<?php if (c('site.banner_show') && c('site.banner_text')): ?>
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

<header class="site-header">
  <div class="container header-row">
    <label for="menu-toggle" class="menu-btn" title="Menu">
      <span class="menu-btn-icon" aria-hidden="true"><i></i><i></i><i></i></span>
    </label>
    <a class="brand" href="<?= e(url()) ?>">
      <?php if (c('site.logo')): ?>
        <img src="<?= e(media(c('site.logo'))) ?>" alt="<?= e($siteName) ?>">
      <?php else: ?>
        <span class="brand-name"><?= e($siteName) ?></span>
        <span class="brand-sub"><?= e(c('site.full_name')) ?></span>
      <?php endif; ?>
    </a>
    <nav class="main-nav" aria-label="Main">
      <?php foreach (main_nav() as [$key, $label, $href]): ?>
        <a href="<?= e($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="btn btn-small header-cta" href="<?= e(url('register')) ?>"<?= $nav === 'register' ? ' aria-current="page"' : '' ?>>Register</a>
  </div>
</header>

<main id="main">
<?= $main ?>
</main>

<footer class="site-footer">
  <div class="container footer-grid">
    <div>
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
