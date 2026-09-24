<?php
require __DIR__ . '/_pagehead.php';
$cats = [];
foreach (c('downloads', []) as $d) {
    if (!empty($d['file'])) {
        $cats[$d['category'] ?? 'Other'][] = $d;
    }
}
?>
<?php foreach ($cats as $cat => $files): ?>
<section class="section">
  <div class="container">
    <h2><?= e($cat) ?></h2>
    <?php require __DIR__ . '/_files.php'; ?>
  </div>
</section>
<?php endforeach; ?>
