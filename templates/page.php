<?php
$heading = $item['title'];
$lead = $item['lead'] ?? '';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container prose narrow"><?= md($item['body'] ?? '') ?></div>
</section>
