<ul class="file-list">
  <?php foreach ($files as $d): ?>
    <li><a href="<?= e(media($d['file'])) ?>" download>
      <span class="file-title"><?= e($d['title'] ?: basename($d['file'])) ?></span>
      <?php if (!empty($d['description'])): ?><span class="file-desc"><?= e($d['description']) ?></span><?php endif; ?>
      <span class="file-meta"><?= e(file_meta($d['file'])) ?></span>
    </a></li>
  <?php endforeach; ?>
</ul>
