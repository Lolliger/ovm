<?php
$kicker = e(c('conference.edition'));
$heading = 'Delegate login';
$lead = 'Enter the e-mail address you registered with. We will send you a link to log in – no password needed.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container narrow">
    <?php if ($sent): ?>
      <div class="notice notice-ok" role="status">
        <p><strong>Check your inbox.</strong> If this address is registered, we have sent you a login link. It is valid for 30 minutes.</p>
        <p class="muted">Nothing arrived? Check your spam folder or try again in a minute.</p>
      </div>
    <?php else: ?>
      <form class="form" method="post" action="<?= e(url('portal')) ?>">
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="field"><label for="p-email">E-mail</label><input id="p-email" type="email" name="email" required autocomplete="email" autofocus></div>
        <button class="btn" type="submit">Send login link</button>
      </form>
      <p class="muted">Not registered yet? <a href="<?= e(url('register')) ?>">Register here</a>.</p>
    <?php endif; ?>
  </div>
</section>
