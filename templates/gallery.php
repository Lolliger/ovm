<?php
require __DIR__ . '/_pagehead.php';
$albums = [];
foreach (c('gallery', []) as $g) {
    if (!empty($g['image'])) {
        $albums[$g['year'] ?? ''][] = $g;
    }
}
krsort($albums);
?>
<?php foreach ($albums as $year => $photos): ?>
<section class="section">
  <div class="container">
    <?php if ($year !== ''): ?><h2><?= e($year) ?></h2><?php endif; ?>
    <div class="gallery">
      <?php foreach ($photos as $p): ?>
        <figure>
          <a href="<?= e(media($p['image'])) ?>" target="_blank"><img src="<?= e(media($p['image'])) ?>" alt="<?= e($p['caption'] ?? '') ?>" loading="lazy"></a>
          <?php if (!empty($p['caption'])): ?><figcaption><?= e($p['caption']) ?></figcaption><?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endforeach; ?>
