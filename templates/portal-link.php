<?php
$kicker = e(c('conference.edition'));
$heading = 'Log in';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container narrow">
    <?php if ($error): ?>
      <div class="notice notice-error" role="alert"><p><?= e($error) ?></p></div>
      <p><a class="btn" href="<?= e(url('portal')) ?>">Request a new link</a></p>
    <?php elseif ($token === ''): ?>
      <p>This link is incomplete. <a href="<?= e(url('portal')) ?>">Request a new login link</a>.</p>
    <?php else: ?>
      <form class="form" method="post" action="<?= e(url('portal/login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <p>Click the button to open your delegate area.</p>
        <button class="btn" type="submit">Log in</button>
      </form>
    <?php endif; ?>
  </div>
</section>
