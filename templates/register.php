<?php
$kicker = e(c('conference.edition')) . ' · ' . e(date_range(c('conference.date_start'), c('conference.date_end')));
$heading = 'Register';
require __DIR__ . '/_pagehead.php';
$v = $result['values'] ?? [];
$val = fn ($k) => e($v[$k] ?? '');
$committees = c('committees', []);
$kind = registration_kind($v['role'] ?? '');
$select = function (string $name, array $options, bool $required = false) use ($v) {
    $html = '<select id="f-' . $name . '" name="' . $name . '"' . ($required ? ' required' : '') . '><option value="">– please choose –</option>';
    foreach ($options as $o) {
        $html .= '<option' . (($v[$name] ?? '') === $o ? ' selected' : '') . '>' . e($o) . '</option>';
    }
    return $html . '</select>';
};
$committeeNames = array_map(fn ($cm) => trim(($cm['abbr'] ?? '') . ' – ' . $cm['name'], ' –'), $committees);
?>
<section class="section">
  <div class="container split split-form">
    <div class="prose">
      <?php if ($result && $result['ok']): ?>
        <div class="notice notice-ok" role="status"><?= md(c('registration.success_message')) ?></div>
      <?php elseif (!registration_is_open()): ?>
        <div class="notice"><?= md(c('registration.closed_message')) ?></div>
      <?php else: ?>
        <?= md(c('registration.intro')) ?>
        <?php if (c('registration.deadline')): ?>
          <p><strong>Deadline: <?= e(format_date(c('registration.deadline'))) ?></strong></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (registration_is_open() && !($result && $result['ok'])): ?>
    <form class="form" method="post" action="<?= e(url('register')) ?>" novalidate>
      <?php if (!empty($result['errors'])): ?>
        <div class="notice notice-error" role="alert"><ul><?php foreach ($result['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <input type="hidden" name="t" value="<?= time() ?>">
      <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <fieldset>
        <legend>Your login</legend>
        <div class="field"><label for="f-code">Personal code *</label><input id="f-code" name="code" required autocomplete="username" autocapitalize="characters" spellcheck="false" placeholder="e.g. K7QF-M3XP" value="<?= e(format_code($v['code'] ?? '')) ?>">
          <p class="hint">You received this code from us. You will log in with it – we do not ask for your name.</p></div>
        <div class="row">
          <div class="field"><label for="f-password">Choose a password *</label><input id="f-password" type="password" name="password" required minlength="10" autocomplete="new-password"></div>
          <div class="field"><label for="f-password2">Repeat password *</label><input id="f-password2" type="password" name="password2" required minlength="10" autocomplete="new-password"></div>
        </div>
        <p class="hint">At least 10 characters.</p>
      </fieldset>

      <fieldset>
        <legend>About you</legend>
        <div class="field"><label for="f-role">Participation as *</label>
          <select id="f-role" name="role" required data-role-select>
            <option value="">– please choose –</option>
            <?php foreach (registration_role_defs() as $i => $d): if (!$d['open']) continue; ?><option data-kind="<?= e($d['kind']) ?>"<?= $d['popup'] !== '' ? ' data-popup="role-popup-' . $i . '"' : '' ?><?= ($v['role'] ?? '') === $d['name'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="f-email">E-mail *</label><input id="f-email" type="email" name="email" required autocomplete="email" value="<?= $val('email') ?>">
          <p class="hint">For your confirmation, your allocation and if you forget your password.</p></div>
        <?php if ($committeeNames): ?>
        <div class="field" data-kinds="chair"<?= $kind === 'chair' ? '' : ' hidden' ?>><label for="f-chair_committee">Committee *</label>
          <select id="f-chair_committee" name="chair_committee"><option value="">– please choose –</option>
            <?php foreach ($committeeNames as $o): ?><option<?= $kind === 'chair' && ($v['committee_1'] ?? '') === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <div class="field field-small" data-kinds="delegate"<?= $kind === 'delegate' ? '' : ' hidden' ?>><label for="f-grade">Grade *</label><input id="f-grade" name="grade" required inputmode="numeric" value="<?= $val('grade') ?>"></div>
        <div class="field" data-kinds="delegate"<?= $kind === 'delegate' ? '' : ' hidden' ?>><label for="f-experience">MUN experience</label><?= $select('experience', ['This is my first conference', '1–2 conferences', '3 or more conferences']) ?></div>
      </fieldset>

      <fieldset data-kinds="delegate"<?= $kind === 'delegate' ? '' : ' hidden' ?>>
        <legend>Preferences</legend>
        <?php if ($committeeNames): ?>
        <div class="row">
          <div class="field"><label for="f-committee_1">Committee, 1st choice</label><?= $select('committee_1', $committeeNames) ?></div>
          <div class="field"><label for="f-committee_2">Committee, 2nd choice</label><?= $select('committee_2', $committeeNames) ?></div>
        </div>
        <?php endif; ?>
        <div class="field"><label for="f-country_wishes">Country wishes</label><input id="f-country_wishes" name="country_wishes" placeholder="e.g. Brazil, Japan" value="<?= $val('country_wishes') ?>"></div>
        <div class="field"><label for="f-diet">Dietary requirements</label><input id="f-diet" name="diet" placeholder="e.g. vegetarian" value="<?= $val('diet') ?>"></div>
        <div class="field"><label for="f-message">Anything else?</label><textarea id="f-message" name="message" rows="4"><?= $val('message') ?></textarea></div>
      </fieldset>

      <label class="check"><input type="checkbox" name="consent" value="1" required>
        <span>I agree that my data is stored and used to organise the conference, as described in the <a href="<?= e(url('privacy')) ?>" target="_blank">privacy policy</a>. If I am under 16, my parents agree as well. *</span></label>

      <button class="btn" type="submit">Send registration</button>
    </form>
    <?php foreach (registration_role_defs() as $i => $d): if ($d['popup'] === '') continue; ?>
    <dialog class="notice-dialog" id="role-popup-<?= $i ?>" aria-labelledby="role-popup-<?= $i ?>-title">
      <h2 id="role-popup-<?= $i ?>-title">Registering as <?= e($d['name']) ?></h2>
      <div class="prose"><?= md($d['popup']) ?></div>
      <form method="dialog"><button class="btn">OK, got it</button></form>
    </dialog>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
