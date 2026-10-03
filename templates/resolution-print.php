<?php
/** Printable resolution (browser "Save as PDF"). @var array $ctx @var array $res */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title><?= e($ctx['committee']['name'] . ' – ' . ($res['topic'] ?: 'Resolution')) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/site.css')) ?>?v=<?= filemtime(ROOT . '/assets/css/site.css') ?>">
</head>
<body class="print-page">
  <p class="print-actions"><button class="btn" onclick="window.print()">Print / save as PDF</button></p>
  <article class="res-paper print-paper">
    <?= res_document_html($res, $ctx['committee']) ?>
    <p class="print-meta">Resolution <?= (int) ($res['number'] ?? 1) ?> · <?= !empty($res['outcome']) ? e($res['outcome']) . ' · ' . e(date('j F Y', strtotime($res['archived']))) : 'Status: ' . e(status_label($res['status'])) ?> · Printed <?= e(date('j F Y, H:i')) ?></p>
  </article>
</body>
</html>
