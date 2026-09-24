<?php
$kicker = '<a href="' . e(url('committees')) . '">Committees</a> / ' . e($item['abbr'] ?? '');
$heading = $item['name'];
$lead = $item['summary'] ?? '';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container split">
    <div class="prose">
      <?php if (!empty($item['image'])): ?><img class="section-photo" src="<?= e(media($item['image'])) ?>" alt=""><?php endif; ?>
      <?php if (!empty($item['topics'])): ?>
        <h2>Topics</h2>
        <ol class="topics big"><?php foreach ($item['topics'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ol>
      <?php endif; ?>
      <?= md($item['description'] ?? '') ?>
    </div>
    <dl class="facts">
      <?php if (!empty($item['level'])): ?><div><dt>Level</dt><dd><?= e($item['level']) ?></dd></div><?php endif; ?>
      <?php if (!empty($item['chairs'])): ?><div><dt>Chairs</dt><dd><?= e($item['chairs']) ?></dd></div><?php endif; ?>
      <div><dt>Study guide</dt><dd>
        <?php if (!empty($item['study_guide'])): ?>
          <a href="<?= e(media($item['study_guide'])) ?>" download>Download (<?= e(file_meta($item['study_guide'])) ?>)</a>
        <?php else: ?>Coming soon<?php endif; ?>
      </dd></div>
      <div><dd><a class="btn" href="<?= e(url('register')) ?>">Register for this committee</a></dd></div>
    </dl>
  </div>
</section>
