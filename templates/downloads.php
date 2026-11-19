<?php
require __DIR__ . '/_pagehead.php';
$cats = [];
foreach (c('downloads', []) as $d) {
    if (!empty($d['file'])) {
        $cats[$d['category'] ?? 'Other'][] = $d;
    }
}
$reportsBy = [];
foreach (research_reports() as $rep) {
    $reportsBy[$rep['committee']][] = $rep;
}
?>
<?php if (!$cats && !$reportsBy): ?>
<section class="section">
  <div class="container"><p class="muted">No documents yet. Study guides, rules of procedure and other material will be published here before the conference.</p></div>
</section>
<?php endif; ?>
<?php foreach ($cats as $cat => $files): ?>
<section class="section">
  <div class="container">
    <h2><?= e($cat) ?></h2>
    <?php require __DIR__ . '/_files.php'; ?>
  </div>
</section>
<?php endforeach; ?>
<?php if ($reportsBy): ?>
<section class="section">
  <div class="container">
    <h2>Research reports</h2>
    <?php foreach ($reportsBy as $slug => $reps): $cm = committee_by_slug($slug); ?>
      <h3 class="downloads-sub"><a href="<?= e(url('committees/' . $slug)) ?>"><?= e($cm['name']) ?></a></h3>
      <ul class="file-list">
        <?php foreach ($reps as $rep): ?>
          <li><a href="<?= e(report_url($rep)) ?>" download>
            <span class="file-title"><?= e($rep['title']) ?></span>
            <span class="file-desc">Research report · <?= e(($cm['abbr'] ?? '') ?: $cm['name']) ?></span>
            <span class="file-meta"><?= e(file_meta('uploads/research-reports/' . $rep['file'])) ?></span>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
