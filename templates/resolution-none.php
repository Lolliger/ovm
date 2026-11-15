<?php
$heading = 'Resolution';
$lead = 'The resolution editor opens once you have been allocated to a committee. You will see your allocation in your ' . portal_label(portal_account()) . '.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container narrow"><a class="btn" href="<?= e(portal_link()) ?>">Back to your <?= e(portal_label(portal_account())) ?></a></div>
</section>
