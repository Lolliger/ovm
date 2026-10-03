<?php
$kicker = e(c('conference.edition'));
$heading = 'Delegate login';
$lead = 'Log in with the e-mail address you registered with and the password from your confirmation e-mail.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container narrow">
    <form class="form" method="post" action="<?= e(url('login')) ?>">
      <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <div class="field"><label for="p-email">E-mail or username</label><input id="p-email" type="text" name="email" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($address) ?>"<?= $address === '' ? ' autofocus' : '' ?>></div>
      <div class="field"><label for="p-password">Password</label><input id="p-password" type="password" name="password" required autocomplete="current-password"<?= $address !== '' ? ' autofocus' : '' ?>></div>
      <button class="btn" type="submit">Log in</button>
    </form>
    <p class="muted"><a href="<?= e(url('login/forgot')) ?>">Forgot your password?</a> · Not registered yet? <a href="<?= e(url('register')) ?>">Register here</a>.</p>
  </div>
</section>
