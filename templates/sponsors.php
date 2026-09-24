<?php
$lead = 'OMUN would not be possible without the people and organisations who support us.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container">
    <div class="sponsor-grid">
      <?php foreach (c('sponsors', []) as $s): ?>
        <?php $tag = !empty($s['url']) ? 'a' : 'div'; ?>
        <<?= $tag ?> class="sponsor"<?= $tag === 'a' ? ' href="' . e($s['url']) . '" target="_blank" rel="noopener"' : '' ?>>
          <?php if (!empty($s['logo'])): ?><img src="<?= e(media($s['logo'])) ?>" alt="<?= e($s['name']) ?>" loading="lazy"><?php else: ?><strong><?= e($s['name']) ?></strong><?php endif; ?>
          <?php if (!empty($s['text'])): ?><p><?= e($s['text']) ?></p><?php endif; ?>
        </<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  </div>
</section>
