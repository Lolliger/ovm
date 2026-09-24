<?php
$lead = 'OMUN is planned and run by students. These are the people behind it.';
require __DIR__ . '/_pagehead.php';
$groups = [];
foreach (c('team', []) as $m) {
    $groups[$m['group'] ?? ''][] = $m;
}
?>
<?php foreach ($groups as $group => $members): ?>
<section class="section">
  <div class="container">
    <?php if ($group): ?><h2><?= e($group) ?></h2><?php endif; ?>
    <div class="team-grid">
      <?php foreach ($members as $m): ?>
        <figure class="person">
          <?php if (!empty($m['photo'])): ?>
            <img src="<?= e(media($m['photo'])) ?>" alt="<?= e($m['name']) ?>" loading="lazy">
          <?php else: ?>
            <div class="person-initials" aria-hidden="true"><?= e(mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($m['name'])), 0, 2))))) ?></div>
          <?php endif; ?>
          <figcaption>
            <strong><?= e($m['name']) ?></strong>
            <span><?= e($m['role'] ?? '') ?></span>
            <?php if (!empty($m['bio'])): ?><p><?= e($m['bio']) ?></p><?php endif; ?>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endforeach; ?>
