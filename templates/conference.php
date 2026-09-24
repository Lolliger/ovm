<?php
$kicker = e(c('conference.edition'));
$heading = date_range(c('conference.date_start'), c('conference.date_end')) ?: 'Conference';
$lead = c('conference.motto') ? '“' . c('conference.motto') . '”' : '';
require __DIR__ . '/_pagehead.php';

$days = [];
foreach (c('conference.schedule', []) as $row) {
    $days[$row['day'] ?? ''][] = $row;
}
?>
<section class="section">
  <div class="container split">
    <div class="prose"><?= md(c('conference.intro')) ?></div>
    <dl class="facts">
      <div><dt>Dates</dt><dd><?= e(date_range(c('conference.date_start'), c('conference.date_end'))) ?></dd></div>
      <div><dt>Venue</dt><dd><?= e(c('conference.venue')) ?><br><?= nl2br(e(c('conference.venue_address'))) ?>
        <?php if (c('conference.map_url')): ?><br><a href="<?= e(c('conference.map_url')) ?>" target="_blank" rel="noopener">Show on map</a><?php endif; ?></dd></div>
      <?php if (c('conference.fee')): ?><div><dt>Fee</dt><dd><?= e(c('conference.fee')) ?></dd></div><?php endif; ?>
      <?php if (c('conference.language')): ?><div><dt>Language</dt><dd><?= e(c('conference.language')) ?></dd></div><?php endif; ?>
      <?php if (c('registration.deadline')): ?><div><dt>Registration deadline</dt><dd><?= e(format_date(c('registration.deadline'))) ?></dd></div><?php endif; ?>
    </dl>
  </div>
</section>

<?php if ($days): ?>
<section class="section">
  <div class="container">
    <h2>Programme</h2>
    <?php foreach ($days as $day => $rows): ?>
      <table class="schedule">
        <caption><?= e($day) ?></caption>
        <thead><tr><th scope="col">When?</th><th scope="col">What?</th><th scope="col">Where?</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr><td class="time"><?= e($r['time'] ?? '') ?></td><td><?= e($r['title'] ?? '') ?></td><td class="where"><?= e($r['location'] ?? '') ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (c('conference.body')): ?>
<section class="section">
  <div class="container prose narrow"><?= md(c('conference.body')) ?></div>
</section>
<?php endif; ?>

<?php if ($docs = array_filter(c('downloads', []), fn ($d) => !empty($d['file']) && ($d['category'] ?? '') === 'Conference')): ?>
<section class="section">
  <div class="container">
    <h2>Documents</h2>
    <?php $files = $docs; require __DIR__ . '/_files.php'; ?>
  </div>
</section>
<?php endif; ?>
