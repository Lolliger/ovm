<?php require __DIR__ . '/_pagehead.php'; ?>
<section class="section">
  <div class="container">
    <div class="news-list">
      <?php foreach (published_news() as $n): ?>
        <a class="news-row" href="<?= e(url('news/' . $n['slug'])) ?>">
          <time datetime="<?= e($n['date']) ?>"><?= e(format_date($n['date'])) ?></time>
          <div>
            <h2><?= e($n['title']) ?></h2>
            <p><?= e($n['excerpt'] ?? '') ?></p>
          </div>
          <?php if (!empty($n['image'])): ?><img src="<?= e(media($n['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
