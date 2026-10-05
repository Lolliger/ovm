<?php
/** Printable certificate (A4 landscape, "Save as PDF" in the browser). @var array $cert */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($cert['title'] . ' – ' . $cert['name']) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/site.css')) ?>?v=<?= filemtime(ROOT . '/assets/css/site.css') ?>">
<style>
  @page { size: A4 landscape; margin: 0; }
  html, body { background: #e9edf2; }
  .cert-actions { text-align: center; padding: 1.25rem 1rem; }
  .cert-actions p { font-size: 0.85rem; color: #5d6b80; margin: 0.5rem 0 0; }
  .cert {
    width: 297mm; height: 210mm; margin: 0 auto 2rem; background: #fff; color: #1b2433; position: relative;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12); font-family: "Times New Roman", Times, "Liberation Serif", serif;
    display: flex; flex-direction: column; align-items: center; text-align: center; padding: 16mm 24mm 14mm;
  }
  .cert::before { content: ""; position: absolute; inset: 8mm; border: 1.2mm solid #c9a24d; pointer-events: none; }
  .cert::after { content: ""; position: absolute; inset: 10.5mm; border: 0.3mm solid #c9a24d; pointer-events: none; }
  .cert-emblem { width: 34mm; height: auto; margin-bottom: 5mm; }
  .cert-conf { font-family: var(--sans, sans-serif); font-size: 10pt; letter-spacing: 0.25em; text-transform: uppercase; color: #8a6d2b; margin: 0 0 3mm; }
  .cert-title { font-size: 32pt; font-weight: 400; margin: 0 0 7mm; letter-spacing: 0.02em; }
  .cert-certify { font-size: 13pt; font-style: italic; margin: 0 0 3mm; }
  .cert-name { font-size: 34pt; margin: 0 0 5mm; padding: 0 12mm 2mm; border-bottom: 0.3mm solid #c9a24d; }
  .cert-text { font-size: 14pt; line-height: 1.5; max-width: 200mm; margin: 0; }
  .cert-signs { display: flex; gap: 30mm; margin-top: auto; }
  .cert-sign { width: 62mm; border-top: 0.3mm solid #1b2433; padding-top: 2mm; font-size: 11pt; }
  .cert-sign span { display: block; font-size: 9.5pt; color: #5d6b80; font-style: italic; }
  @media print {
    html, body { background: #fff; }
    .cert-actions { display: none; }
    .cert { margin: 0; box-shadow: none; }
  }
  @media screen and (max-width: 1150px) { .cert { zoom: 0.6; } }
  @media screen and (max-width: 700px) { .cert { zoom: 0.32; } }
</style>
</head>
<body>
  <div class="cert-actions">
    <button class="btn" onclick="window.print()">Download as PDF / print</button>
    <p>In the print dialog choose “Save as PDF”, landscape, without headers and footers.</p>
  </div>
  <article class="cert">
    <img class="cert-emblem" src="<?= e(url('assets/img/res-emblem.jpg')) ?>" alt="">
    <p class="cert-conf"><?= e($cert['conference']) ?></p>
    <h1 class="cert-title"><?= e($cert['title']) ?></h1>
    <p class="cert-certify">This is to certify that</p>
    <p class="cert-name"><?= e($cert['name']) ?></p>
    <p class="cert-text"><?= e($cert['text']) ?></p>
    <?php if ($cert['signers']): ?>
      <div class="cert-signs">
        <?php foreach ($cert['signers'] as $sg): ?><div class="cert-sign"><?= e($sg['name']) ?><span><?= e($sg['title']) ?></span></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
</body>
</html>
