<?php
$kicker = '<a href="' . e(url('news')) . '">News</a> / ' . e(format_date($item['date'] ?? ''));
$heading = $item['title'];
$lead = $item['excerpt'] ?? '';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container prose narrow">
    <?php if (!empty($item['image'])): ?><img class="section-photo" src="<?= e(media($item['image'])) ?>" alt=""><?php endif; ?>
    <?= md($item['body'] ?? '') ?>
  </div>
</section>
