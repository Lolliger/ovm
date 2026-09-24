<?php
$heading = 'Frequently asked questions';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container narrow">
    <div class="faq">
      <?php foreach (c('faq', []) as $f): ?>
        <details>
          <summary><?= e($f['question']) ?></summary>
          <div class="prose"><?= md($f['answer'] ?? '') ?></div>
        </details>
      <?php endforeach; ?>
    </div>
    <p class="muted">Still unsure? Write to <a href="mailto:<?= e(c('site.email')) ?>"><?= e(c('site.email')) ?></a>.</p>
  </div>
</section>
