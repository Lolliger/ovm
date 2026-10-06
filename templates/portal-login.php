<?php
$kicker = e(c('conference.edition'));
$heading = 'Delegate login';
$lead = 'Log in with your personal code and the password you chose when registering.';
require __DIR__ . '/_pagehead.php';
?>
<section class="section">
  <div class="container narrow">
    <form class="form" method="post" action="<?= e(url('login')) ?>">
      <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <div class="field"><label for="p-code">Personal code</label><input id="p-code" type="text" name="code" required autocomplete="username" autocapitalize="characters" spellcheck="false" placeholder="e.g. K7QF-M3XP" value="<?= e($address) ?>"<?= $address === '' ? ' autofocus' : '' ?>></div>
      <div class="field"><label for="p-password">Password</label><input id="p-password" type="password" name="password" required autocomplete="current-password"<?= $address !== '' ? ' autofocus' : '' ?>></div>
      <button class="btn" type="submit">Log in</button>
    </form>
    <p class="muted"><a href="<?= e(url('login/forgot')) ?>">Forgot your password?</a> · Not registered yet? <a href="<?= e(url('register')) ?>">Register here</a>.</p>
  </div>
</section>
