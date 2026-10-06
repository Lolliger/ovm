<?php
$kicker = e(c('conference.edition'));
$heading = 'Forgot your password?';
$lead = 'Enter your personal code. We will send a one-time login link to the e-mail address you registered with; afterwards you can set a new password.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container narrow">
    <?php if ($sent): ?>
      <div class="notice notice-ok" role="status">
        <p><strong>Check your inbox.</strong> If this code is registered, we have sent a login link to its e-mail address. It is valid for 30 minutes.</p>
        <p class="muted">Nothing arrived? Check your spam folder or try again in a minute.</p>
      </div>
    <?php else: ?>
      <form class="form" method="post" action="<?= e(url('login/forgot')) ?>">
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="field"><label for="p-code">Personal code</label><input id="p-code" type="text" name="code" required autocomplete="username" autocapitalize="characters" spellcheck="false" placeholder="e.g. K7QF-M3XP" autofocus></div>
        <button class="btn" type="submit">Send login link</button>
      </form>
      <p class="muted"><a href="<?= e(url('login')) ?>">Back to login</a></p>
    <?php endif; ?>
  </div>
</section>
