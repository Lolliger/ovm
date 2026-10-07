<?php
require __DIR__ . '/_pagehead.php';
$cats = [];
foreach (c('downloads', []) as $d) {
    if (!empty($d['file'])) {
        $cats[$d['category'] ?? 'Other'][] = $d;
    }
}
?>
<?php if (!$cats): ?>
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
