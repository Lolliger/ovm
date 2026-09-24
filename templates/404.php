<?php
$heading = 'Page not found';
$lead = 'This page does not exist, or it has moved.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container"><a class="btn" href="<?= e(url()) ?>">Back to the home page</a></div>
</section>
