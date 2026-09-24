<?php
$lead = 'A look back at previous conferences.';
require __DIR__ . '/_pagehead.php';
?>
<?php foreach (c('archive', []) as $a): ?>
<section class="section">
  <div class="container split">
    <div class="prose">
      <p class="kicker"><?= e($a['dates'] ?? '') ?></p>
      <h2><?= e($a['title']) ?></h2>
      <?php if (!empty($a['motto'])): ?><p class="lead">“<?= e($a['motto']) ?>”</p><?php endif; ?>
      <?= md($a['text'] ?? '') ?>
      <?php if (!empty($a['file'])): ?><p><a href="<?= e(media($a['file'])) ?>" download>Download documents (<?= e(file_meta($a['file'])) ?>)</a></p><?php endif; ?>
    </div>
    <?php if (!empty($a['image'])): ?><img class="section-photo" src="<?= e(media($a['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
  </div>
</section>
<?php endforeach; ?>
