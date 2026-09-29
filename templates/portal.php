<?php
$kicker = e(c('conference.edition')) . ' · Delegate area';
$heading = 'Your registration';
require __DIR__ . '/_pagehead.php';
$statuses = registration_statuses();
?>
<section class="section">
  <div class="container narrow portal">
    <div class="portal-top">
      <p class="muted">Logged in as <strong><?= e($email) ?></strong></p>
      <form method="post" action="<?= e(url('portal/logout')) ?>"><?= csrf_field() ?><button class="btn-link" type="submit">Log out</button></form>
    </div>
    <?php if ($message): ?><div class="notice notice-ok" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (c('portal.intro')): ?><div class="prose"><?= md(c('portal.intro')) ?></div><?php endif; ?>

    <?php if (!$regs): ?>
      <div class="notice"><p>We could not find a registration for this address (it may have been deleted after the conference).</p></div>
    <?php endif; ?>

    <?php foreach ($regs as $r): ?>
      <?php
      $status = $r['status'] ?? 'received';
      $committee = committee_by_label((string) ($r['assigned_committee'] ?? ''));
      ?>
      <article class="portal-card">
        <header>
          <h2><?= e($r['first_name'] . ' ' . $r['last_name']) ?></h2>
          <span class="tag status-<?= e($status) ?>"><?= e($statuses[$status][0] ?? $status) ?></span>
        </header>

        <dl class="facts">
          <div><dt>Participation as</dt><dd><?= e($r['role']) ?></dd></div>
          <div><dt>Country</dt><dd><?= e(($r['assigned_country'] ?? '') ?: 'Not allocated yet') ?></dd></div>
          <div><dt>Committee</dt><dd>
            <?php if ($committee): ?>
              <a href="<?= e(url('committees/' . $committee['slug'])) ?>"><?= e($committee['name']) ?></a>
              <?php if (!empty($committee['study_guide'])): ?> · <a href="<?= e(media($committee['study_guide'])) ?>" download>Study guide ↓</a><?php endif; ?>
            <?php else: ?>
              <?= e(($r['assigned_committee'] ?? '') ?: 'Not allocated yet') ?>
            <?php endif; ?>
          </dd></div>
          <div><dt>School</dt><dd><?= e($r['school']) ?>, grade <?= e($r['grade']) ?></dd></div>
          <div><dt>Registered on</dt><dd><?= e(format_date($r['created'])) ?></dd></div>
        </dl>

        <div class="paper">
          <h3>Position paper</h3>
          <?php if (!empty($r['paper'])): ?>
            <p class="paper-current">
              <a href="<?= e(url('portal/paper') . '?id=' . rawurlencode($r['id'])) ?>"><?= e($r['paper']['name']) ?></a>
              <span class="muted">uploaded <?= e(date('j F Y, H:i', strtotime($r['paper']['uploaded']))) ?></span>
            </p>
          <?php endif; ?>
          <?php if ($status === 'cancelled'): ?>
            <p class="muted">This registration was cancelled.</p>
          <?php elseif (papers_open()): ?>
            <?php if (c('portal.paper_info')): ?><div class="prose muted"><?= md(c('portal.paper_info')) ?></div><?php endif; ?>
            <?php if (c('portal.paper_deadline')): ?><p><strong>Deadline: <?= e(format_date(c('portal.paper_deadline'))) ?></strong></p><?php endif; ?>
            <form class="paper-form" method="post" enctype="multipart/form-data" action="<?= e(url('portal')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="a" value="paper">
              <input type="hidden" name="id" value="<?= e($r['id']) ?>">
              <input type="file" name="paper" accept=".pdf,.doc,.docx,.odt" required>
              <button class="btn" type="submit"><?= empty($r['paper']) ? 'Upload' : 'Replace' ?></button>
            </form>
          <?php elseif (empty($r['paper'])): ?>
            <p class="muted"><?= c('portal.papers_open') ? 'The deadline for position papers has passed.' : 'Uploading position papers is not open yet.' ?></p>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>

    <details class="portal-card change-password"<?= $error && ($_POST['a'] ?? '') === 'password' ? ' open' : '' ?>>
      <summary><h2>Change password</h2></summary>
      <form class="form" method="post" action="<?= e(url('portal')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="a" value="password">
        <input type="hidden" name="username" value="<?= e($email) ?>" autocomplete="username">
        <div class="field"><label for="p-new">New password (at least 10 characters)</label><input id="p-new" type="password" name="new_password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label for="p-new2">Repeat new password</label><input id="p-new2" type="password" name="new_password2" required minlength="10" autocomplete="new-password"></div>
        <button class="btn" type="submit">Save new password</button>
      </form>
    </details>

    <p class="muted">Something wrong with your details? Write to <a href="mailto:<?= e(c('site.email')) ?>"><?= e(c('site.email')) ?></a>.</p>
  </div>
</section>
