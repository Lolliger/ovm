<?php
$lead = 'OMUN is planned and run by students. These are the people behind it.';
require __DIR__ . '/_pagehead.php';
$groups = [];
foreach (c('team', []) as $m) {
    $groups[$m['group'] ?? ''][] = $m;
}
$initials = fn (string $name) => mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', plain_name($name)), 0, 2))));
?>
<?php foreach ($groups as $group => $members): ?>
<section class="section">
  <div class="container">
    <?php if ($group): ?><h2><?= e($group) ?></h2><?php endif; ?>
    <div class="team-grid">
      <?php foreach ($members as $m): ?>
        <figure class="person">
          <button type="button" class="person-open" aria-haspopup="dialog" aria-label="<?= e(plain_name($m['name'])) ?>: more">
            <?php if (!empty($m['photo'])): ?>
              <img src="<?= e(media($m['photo'])) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="person-initials" aria-hidden="true"><?= e($initials($m['name'])) ?></span>
            <?php endif; ?>
          </button>
          <figcaption>
            <strong><?= fancy_name($m['name']) ?></strong>
            <span><?= e($m['role'] ?? '') ?></span>
            <?php if (!empty($m['bio'])): ?><span class="person-more">Read bio <span aria-hidden="true">→</span></span><?php endif; ?>
          </figcaption>
          <template class="person-detail">
            <div class="person-card">
              <?php if (!empty($m['photo'])): ?>
                <img src="<?= e(media($m['photo'])) ?>" alt="<?= e(plain_name($m['name'])) ?>">
              <?php else: ?>
                <span class="person-initials" aria-hidden="true"><?= e($initials($m['name'])) ?></span>
              <?php endif; ?>
              <div class="person-card-text">
                <?php if ($group): ?><p class="kicker"><?= e($group) ?></p><?php endif; ?>
                <h3><?= fancy_name($m['name']) ?></h3>
                <p class="person-role"><?= e($m['role'] ?? '') ?></p>
                <?php if (!empty($m['bio'])): ?><div class="person-bio-text"><?= md($m['bio']) ?></div><?php endif; ?>
              </div>
            </div>
          </template>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endforeach; ?>
<dialog class="person-dialog" aria-label="Team member">
  <button type="button" class="person-close" aria-label="Close">✕</button>
  <div class="person-dialog-body"></div>
</dialog>
