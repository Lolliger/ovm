<?php
/** Beamer view: full screen, no navigation, updates live. @var array $ctx @var array $res @var string $self */
$regions = res_regions($ctx, $res, 'screen');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($ctx['committee']['name']) ?> · Beamer</title>
<link rel="stylesheet" href="<?= e(url('assets/css/site.css')) ?>?v=<?= filemtime(ROOT . '/assets/css/site.css') ?>">
<style>:root { --un: <?= safe_color('color_un', '#009edb') ?>; --accent: <?= safe_color('color_accent', '#046bd2') ?>; --ink: <?= safe_color('color_ink', '#1e293b') ?>; --paper: <?= safe_color('color_paper', '#f0f5fa') ?>; }</style>
</head>
<body class="screen" data-res-state="<?= e($self) ?>&amp;view=state&amp;screen=1" data-rev="<?= (int) $res['rev'] ?>" data-layout="<?= e(res_layout_key($ctx, $res)) ?>">
  <header class="screen-head">
    <div>
      <p class="screen-committee"><?= e(c('site.name')) ?> · <?= e($ctx['committee']['name']) ?></p>
      <p class="screen-topic"><?= e($res['topic']) ?></p>
    </div>
    <span class="tag" data-region="status"><?= $regions['status'] ?></span>
  </header>
  <main class="screen-grid">
    <section class="screen-main" data-region="screen-main"><?= $regions['screen-main'] ?></section>
    <aside class="screen-side" data-region="speakers"><?= $regions['speakers'] ?></aside>
  </main>
  <button class="screen-fs" type="button" onclick="document.documentElement.requestFullscreen && document.documentElement.requestFullscreen()">Full screen</button>
  <script src="<?= e(url('assets/js/resolution.js')) ?>?v=<?= filemtime(ROOT . '/assets/js/resolution.js') ?>" defer></script>
</body>
</html>
