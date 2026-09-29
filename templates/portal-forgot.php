<?php
$kicker = e(c('conference.edition'));
$heading = 'Forgot your password?';
$lead = 'Enter the e-mail address you registered with. We will send you a one-time link to log in; afterwards you can set a new password.';
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
      <form class="form" method="post" action="<?= e(url('portal/forgot')) ?>">
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="field"><label for="p-email">E-mail</label><input id="p-email" type="email" name="email" required autocomplete="email" autofocus></div>
        <button class="btn" type="submit">Send login link</button>
      </form>
      <p class="muted"><a href="<?= e(url('portal')) ?>">Back to login</a></p>
    <?php endif; ?>
  </div>
</section>
