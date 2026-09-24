<?php
$kicker = e(c('conference.edition'));
$lead = 'Every delegate represents one country in one committee. Levels help you pick: Beginner committees are perfect for your first conference.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container">
    <div class="committee-list">
      <?php foreach (c('committees', []) as $cm): ?>
        <article class="committee-row">
          <a class="committee-abbr" href="<?= e(url('committees/' . $cm['slug'])) ?>"><?= e($cm['abbr'] ?? '') ?></a>
          <div>
            <h2><a href="<?= e(url('committees/' . $cm['slug'])) ?>"><?= e($cm['name']) ?></a></h2>
            <?php if (!empty($cm['summary'])): ?><p><?= e($cm['summary']) ?></p><?php endif; ?>
            <?php if (!empty($cm['topics'])): ?>
              <ol class="topics"><?php foreach ($cm['topics'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ol>
            <?php endif; ?>
          </div>
          <div class="committee-side">
            <?php if (!empty($cm['level'])): ?><span class="tag tag-<?= e(slugify($cm['level'])) ?>"><?= e($cm['level']) ?></span><?php endif; ?>
            <?php if (!empty($cm['study_guide'])): ?><a class="more" href="<?= e(media($cm['study_guide'])) ?>" download>Study guide ↓</a><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
