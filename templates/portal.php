<?php
$kicker = e(c('conference.edition')) . ' · ' . e(portal_label($account, true));
$heading = $staff ? 'Conference area' : 'Your registration';
require __DIR__ . '/_pagehead.php';
$statuses = registration_statuses();
?>
<section class="section">
  <div class="container narrow portal">
    <div class="portal-top">
      <p class="muted">Logged in as <strong><?= e($staff ? $account : format_code($account)) ?></strong></p>
      <form method="post" action="<?= e(portal_link('logout')) ?>"><?= csrf_field() ?><button class="btn-link" type="submit">Log out</button></form>
    </div>
    <?php if ($message): ?><div class="notice notice-ok" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($resCtx = res_context(null)): ?>
      <a class="portal-res" href="<?= e(portal_link('resolution')) ?>">
        <span class="kicker">Resolution editor</span>
        <strong><?= e($resCtx['committee']['name']) ?></strong>
        <span><?= res_is_chair($resCtx) ? 'Chair view: document, amendments, speakers list and beamer view →' : ($resCtx['role'] === 'viewer' ? 'View all committees (read only) and open the beamer view →' : 'Read the draft resolution and submit amendments →') ?></span>
      </a>
    <?php endif; ?>
    <?php
    $schedReg = null;
    foreach ($regs as $r) {
        if (($r['status'] ?? '') !== 'cancelled' && empty($r['role_pending'])) {
            $schedReg = $schedReg && committee_by_label((string) ($schedReg['assigned_committee'] ?? '')) ? $schedReg : $r;
        }
    }
    $schedule = !$staff && c('conference.schedule') ? portal_schedule($schedReg) : [];
    ?>
    <?php if ($schedule): ?>
      <details class="portal-card schedule"<?= array_filter($schedule, fn ($d) => $d['date'] && $d['date'] >= date('Y-m-d', strtotime('-1 day'))) ? ' open' : '' ?>>
        <summary><h2>Your schedule</h2><span class="muted"><?= e(date_range(c('conference.date_start'), c('conference.date_end'))) ?></span></summary>
        <?php foreach ($schedule as $day): ?>
          <h3 class="sched-day"><?= e($day['name']) ?><?php if ($day['date']): ?> <span><?= e(date('j F', strtotime($day['date']))) ?></span><?php endif; ?></h3>
          <ol class="sched">
            <?php foreach ($day['items'] as $it): ?>
              <li class="<?= $it['mine'] ? 'mine ' : '' ?><?= e($it['state']) ?>">
                <span class="sched-time"><?= e($it['time']) ?></span>
                <span class="sched-what"><strong><?= e($it['title']) ?></strong><?php if ($it['where'] !== ''): ?><span><?= e($it['where']) ?></span><?php endif; ?></span>
                <?php if ($it['state'] === 'now'): ?><span class="tag">now</span><?php elseif ($it['state'] === 'next'): ?><span class="tag">next</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>
    <?php if (gallery_viewer($account)): ?>
      <?php $galCount = count(gallery_photos('community')); $galOfficial = gallery_official_released() ? count(gallery_photos('official')) : 0; ?>
      <a class="portal-res portal-gallery-link" href="<?= e(gallery_link()) ?>">
        <span class="kicker">Photo gallery</span>
        <strong><?= $galOfficial ? 'The official photos are online' : 'Share your conference photos' ?></strong>
        <span><?= $galCount ?> photo<?= $galCount === 1 ? '' : 's' ?> from participants<?= $galOfficial ? ' · ' . $galOfficial . ' official photo' . ($galOfficial === 1 ? '' : 's') : '' ?><?= !$staff && gallery_uploads_open() ? ' – add your own →' : ' →' ?></span>
      </a>
    <?php endif; ?>
    <?php if (is_site_admin_account($account)): ?>
      <form class="portal-card portal-admin" method="post" action="<?= e(portal_link('admin')) ?>">
        <?= csrf_field() ?>
        <div><h2>Website admin</h2><p class="muted">Edit the website, registrations and the allocation – without logging in again.</p></div>
        <button class="btn" type="submit">Open admin area →</button>
      </form>
    <?php endif; ?>
    <?php
    $reviewer = res_can_review($account);
    $resList = array_values(array_filter(res_reviewed_all(), fn ($x) => res_archive_visible($x['committee'], $x['res'], $account)));
    ?>
    <?php if ($resList): ?>
      <div class="portal-card" id="resolutions">
        <header><h2>Resolutions</h2></header>
        <ul class="portal-list">
          <?php foreach ($resList as $x): $link = res_public_link($x['committee'], $x['res']); $pending = ($x['res']['review'] ?? '') === 'pending'; ?>
            <li>
              <span><strong><?= e((($x['committee']['abbr'] ?? '') ?: $x['committee']['name']) . ' · Resolution ' . (int) ($x['res']['number'] ?? 1)) ?></strong>
                <span class="muted"><?= e($x['res']['topic'] ?: 'No topic') ?> · <?= e($x['res']['outcome'] ?? '') ?></span>
                <?php if ($pending): ?><span class="tag status-waitlist"><?= $reviewer ? 'Waiting for your review' : 'With the MUN Coordinator' ?></span><?php endif; ?></span>
              <span class="portal-list-links">
                <?php if ($reviewer): ?><a class="<?= $pending ? 'btn btn-small' : '' ?>" href="<?= e($link . '&view=review') ?>">Review</a><?php endif; ?>
                <a href="<?= e($link) ?>" target="_blank">View / PDF</a>
                <a href="<?= e($link . '&format=docx') ?>">Word</a>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($staff): ?>
      <p class="muted">This is a shared view-only account for the conference laptops. Its password is managed in the admin area.</p>
    <?php else: ?>
    <?php if (c('portal.intro')): ?><div class="prose"><?= md(c('portal.intro')) ?></div><?php endif; ?>

    <?php if (!$regs): ?>
      <div class="notice"><p>We could not find a registration for this code (it may have been deleted after the conference).</p></div>
    <?php endif; ?>

    <?php foreach ($regs as $r): ?>
      <?php
      $status = $r['status'] ?? 'received';
      $delegate = sits_as_delegate($r); // delegates and conference managers who also take part as delegates
      // Delegates see their allocation only once it has been released by the organisers.
      $allocated = alloc_visible($r);
      $committee = $allocated ? committee_by_label((string) ($r['assigned_committee'] ?? '')) : null;
      $profile = team_profile($r);
      ?>
      <article class="portal-card">
        <header>
          <h2><?= e(format_code((string) ($r['code'] ?? '')) ?: 'Your registration') ?></h2>
          <span class="tag status-<?= e($status) ?>"><?= e($statuses[$status][0] ?? $status) ?></span>
        </header>

        <?php if (certificate_available($r)): ?>
          <a class="portal-cert" href="<?= e(portal_link('certificate') . '?id=' . rawurlencode($r['id'])) ?>" target="_blank">
            <span><strong>Your certificate is ready</strong>Certificate of participation – download as PDF</span><span aria-hidden="true">↓</span></a>
        <?php endif; ?>
        <dl class="facts">
          <div><dt>Participation as</dt><dd><?= e($r['role']) ?><?= !empty($r['is_coordinator']) ? ' · <strong>MUN Coordinator</strong> (spectator in all committees, reviews resolutions)' : '' ?><?= !empty($r['role_pending']) ? ' <span class="muted">– waiting for confirmation by the organisers</span>' : '' ?><?= !empty($r['is_admin']) ? ' · <strong>Admin</strong> (chair rights in all committees)' : '' ?><?= is_cm_delegate($r) ? ' · <strong>also as a delegate</strong> (delegate in your own committee, view only in all others)' : '' ?></dd></div>
          <?php if ($delegate): ?><div><dt>Country</dt><dd><?= e(($allocated ? ($r['assigned_country'] ?? '') : '') ?: 'Not allocated yet') ?></dd></div><?php endif; ?>
          <?php if ($delegate || !in_array($r['kind'] ?? '', ['manager', 'staff'], true) || $committee): ?><div><dt>Committee</dt><dd>
            <?php if ($committee): ?>
              <a href="<?= e(main_origin() . url('committees/' . $committee['slug'])) ?>"><?= e($committee['name']) ?></a>
              <?php if (!empty($committee['study_guide'])): ?> · <a href="<?= e(media($committee['study_guide'])) ?>" download>Study guide ↓</a><?php endif; ?>
            <?php else: ?>
              <?= e(($allocated ? committee_display((string) ($r['assigned_committee'] ?? '')) : '') ?: 'Not allocated yet') ?>
            <?php endif; ?>
          </dd></div><?php endif; ?>
          <?php if (($r['grade'] ?? '') !== ''): ?><div><dt>Grade</dt><dd><?= e($r['grade']) ?></dd></div><?php endif; ?>
          <div><dt>Registered on</dt><dd><?= e(format_date($r['created'])) ?></dd></div>
        </dl>

        <?php if (is_cm_delegate($r)): $wishCms = c('committees', []); $wishNames = array_map('committee_wish_label', $wishCms); ?>
        <div class="paper" id="wishes">
          <h3>Your wishes as a delegate</h3>
          <?php if (cm_wishes_open($r)): ?>
            <p class="muted">Choose two committees and, for each, the two countries you would most like to represent. You will be allocated together with all other delegates – we cannot guarantee any wish.<?= !empty($r['wishes_at']) ? ' Last saved ' . e(date('j F Y, H:i', strtotime($r['wishes_at']))) . '.' : '' ?></p>
            <form class="form wish-form" method="post" action="<?= e(portal_link()) ?>#wishes">
              <?= csrf_field() ?>
              <input type="hidden" name="a" value="wishes">
              <input type="hidden" name="id" value="<?= e($r['id']) ?>">
              <?php foreach ([1 => '1st', 2 => '2nd'] as $n => $nth): $curCm = (string) ($r['committee_' . $n] ?? ''); $chosen = committee_by_label($curCm); ?>
              <div class="wish" data-wish>
                <div class="field"><label for="w-<?= e($r['id']) ?>-c<?= $n ?>">Committee, <?= $nth ?> choice</label>
                  <select id="w-<?= e($r['id']) ?>-c<?= $n ?>" name="committee_<?= $n ?>"<?= $n === 1 ? ' required' : '' ?>><option value="">– please choose –</option>
                    <?php foreach ($wishNames as $o): ?><option<?= $chosen && committee_wish_label($chosen) === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
                  </select></div>
                <div class="row">
                  <?php foreach (['a' => '1st', 'b' => '2nd'] as $x => $xth): $name = "country_{$n}{$x}"; $cur = (string) ($r[$name] ?? ''); ?>
                  <div class="field"><label for="w-<?= e($r['id']) ?>-<?= $name ?>">Country, <?= $xth ?> choice</label>
                    <select id="w-<?= e($r['id']) ?>-<?= $name ?>" name="<?= $name ?>" data-country><option value="">– please choose –</option>
                      <?php if ($chosen): foreach (committee_countries($chosen) as $country): ?><option<?= $cur === $country ? ' selected' : '' ?>><?= e($country) ?></option><?php endforeach; endif; ?>
                    </select></div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
              <div class="field"><label for="w-<?= e($r['id']) ?>-exp">MUN conferences attended so far *</label>
                <input id="w-<?= e($r['id']) ?>-exp" class="input-number" type="number" name="experience" min="0" max="99" step="1" inputmode="numeric" required value="<?= e(($n = mun_conferences_input((string) ($r['experience'] ?? ''))) === null ? '' : (string) $n) ?>">
                <p class="hint">Not counting this one – enter 0 if this is your first MUN conference. Counts only if two delegates would otherwise be equally well placed.</p></div>
              <script type="application/json" id="committee-countries"><?= json_encode(array_combine($wishNames, array_map('committee_countries', $wishCms)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
              <button class="btn" type="submit"><?= empty($r['wishes_at']) ? 'Save my wishes' : 'Update my wishes' ?></button>
            </form>
          <?php else: ?>
            <p class="muted">Your allocation has been released – see country and committee above.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($delegate): ?>
        <div class="paper">
          <h3>Position paper</h3>
          <?php if (!empty($r['paper'])): ?>
            <p class="paper-current">
              <a href="<?= e(portal_link('paper') . '?id=' . rawurlencode($r['id'])) ?>"><?= e($r['paper']['name']) ?></a>
              <span class="muted">uploaded <?= e(date('j F Y, H:i', strtotime($r['paper']['uploaded']))) ?></span>
            </p>
          <?php endif; ?>
          <?php if ($status === 'cancelled'): ?>
            <p class="muted">This registration was cancelled.</p>
          <?php elseif (!$committee): ?>
            <p class="muted">You can hand in your position paper here once you have received your country and committee.</p>
          <?php elseif (papers_open()): ?>
            <?php if (c('portal.paper_info')): ?><div class="prose muted"><?= md(c('portal.paper_info')) ?></div><?php endif; ?>
            <?php if (c('portal.paper_deadline')): ?><p><strong>Deadline: <?= e(format_date(c('portal.paper_deadline'))) ?></strong></p><?php endif; ?>
            <form class="paper-form" method="post" enctype="multipart/form-data" action="<?= e(portal_link()) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="a" value="paper">
              <input type="hidden" name="id" value="<?= e($r['id']) ?>">
              <input type="file" name="paper" accept=".pdf,.doc,.docx,.odt" required>
              <button class="btn" type="submit"><?= empty($r['paper']) ? 'Hand in position paper' : 'Replace position paper' ?></button>
            </form>
            <p class="muted small">Once handed in, your paper can be read by the delegates and chairs of your committee. Please do not put your name on it – use your country.</p>
          <?php elseif (empty($r['paper'])): ?>
            <p class="muted"><?= c('portal.papers_open') ? 'The deadline for position papers has passed.' : 'Uploading position papers is not open yet.' ?></p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($committee && ($delegate || !empty($r['is_chair'])) && ($papers = committee_papers($committee['slug']))): ?>
        <div class="paper">
          <h3>Position papers of <?= e($committee['name']) ?></h3>
          <ul class="portal-list">
            <?php foreach ($papers as $p): ?>
              <li><span><strong><?= e(trim((string) ($p['assigned_country'] ?? '')) ?: 'Delegation') ?></strong><?= $p['id'] === $r['id'] ? ' <span class="muted">(yours)</span>' : '' ?>
                <span class="muted">handed in <?= e(date('j F Y', strtotime($p['paper']['uploaded']))) ?></span></span>
                <a href="<?= e(portal_link('paper') . '?id=' . rawurlencode($p['id'])) ?>">Download ↓</a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <?php $isChair = $committee && !empty($r['is_chair']) && committee_chair_access($account, $committee['slug']); ?>
        <?php if ($committee && ($delegate || $isChair) && (($reports = research_reports($committee['slug'])) || $isChair)): ?>
        <div class="paper" id="reports-<?= e($committee['slug']) ?>">
          <h3>Research reports</h3>
          <?php if ($reports): ?>
          <ul class="portal-list">
            <?php foreach ($reports as $rep): ?>
              <li><span><strong><?= e($rep['title']) ?></strong><span class="muted"><?= e(strtoupper(pathinfo($rep['file'], PATHINFO_EXTENSION))) ?> · <?= e(date('j F Y', strtotime($rep['uploaded']))) ?></span></span>
                <span class="portal-list-links"><a href="<?= e(report_url($rep)) ?>" download>Download ↓</a>
                <?php if ($isChair): ?>
                  <form method="post" action="<?= e(portal_link()) ?>" data-confirm="Remove “<?= e($rep['title']) ?>”? It disappears from the portal and from Downloads."><?= csrf_field() ?><input type="hidden" name="a" value="report_delete"><input type="hidden" name="committee" value="<?= e($committee['slug']) ?>"><input type="hidden" name="report" value="<?= e($rep['id']) ?>"><button class="btn-link" type="submit">Remove</button></form>
                <?php endif; ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php elseif ($isChair): ?>
            <p class="muted">No research report uploaded yet.</p>
          <?php endif; ?>
          <?php if ($isChair): ?>
            <form class="form report-form" method="post" enctype="multipart/form-data" action="<?= e(portal_link()) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="a" value="report_upload">
              <input type="hidden" name="committee" value="<?= e($committee['slug']) ?>">
              <div class="field"><label for="rep-title-<?= e($r['id']) ?>">Title</label>
                <input id="rep-title-<?= e($r['id']) ?>" name="title" required maxlength="150" list="rep-topics-<?= e($r['id']) ?>" placeholder="e.g. the topic of the report">
                <datalist id="rep-topics-<?= e($r['id']) ?>"><?php foreach ($committee['topics'] ?? [] as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist></div>
              <div class="field"><label for="rep-file-<?= e($r['id']) ?>">File (PDF or Word, up to 20 MB)</label>
                <input id="rep-file-<?= e($r['id']) ?>" type="file" name="report" accept=".pdf,.doc,.docx,.odt" required></div>
              <button class="btn" type="submit">Upload research report</button>
            </form>
            <p class="muted small">Once uploaded, the report is shown to the delegates of <?= e($committee['name']) ?> here in the portal and is published under <a href="<?= e(main_origin() . url('downloads')) ?>" target="_blank">Downloads</a> and on the committee page.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($profile): ?>
        <div class="paper" id="profile">
          <h3>Your profile on the team page</h3>
          <form class="form profile-form" method="post" enctype="multipart/form-data" action="<?= e(portal_link()) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="profile">
            <input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <div class="profile-row">
              <?php if (!empty($profile['photo'])): ?><img src="<?= e(media($profile['photo'])) ?>" alt="Your current photo"><?php endif; ?>
              <div class="field"><label for="pf-photo-<?= e($r['id']) ?>">Photo</label>
                <input id="pf-photo-<?= e($r['id']) ?>" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
                <p class="hint"><strong>Black and white photos only</strong> (portrait format, JPG or PNG, up to 10 MB). Colour photos are converted to black and white automatically.</p></div>
            </div>
            <div class="field"><label for="pf-bio-<?= e($r['id']) ?>">Short introduction</label>
              <textarea id="pf-bio-<?= e($r['id']) ?>" name="bio" rows="6"><?= e($profile['bio'] ?? '') ?></textarea>
              <p class="hint">Shown publicly on the <a href="<?= e(main_origin() . url('team')) ?>" target="_blank">team page</a> next to “<?= e(plain_name((string) $profile['name'])) ?>”. An empty line starts a new paragraph; **bold**, *italic* and [links](https://…) work.</p></div>
            <button class="btn" type="submit">Save profile</button>
          </form>
        </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>

    <details class="portal-card change-password"<?= $error && ($_POST['a'] ?? '') === 'password' ? ' open' : '' ?>>
      <summary><h2>Change password</h2></summary>
      <form class="form" method="post" action="<?= e(portal_link()) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="a" value="password">
        <input type="hidden" name="username" value="<?= e(format_code($account)) ?>" autocomplete="username">
        <div class="field"><label for="p-new">New password (at least 10 characters)</label><input id="p-new" type="password" name="new_password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label for="p-new2">Repeat new password</label><input id="p-new2" type="password" name="new_password2" required minlength="10" autocomplete="new-password"></div>
        <button class="btn" type="submit">Save new password</button>
      </form>
    </details>

    <?php endif; ?>
    <p class="muted">Something wrong with your details? Write to <a href="mailto:<?= e(c('site.email')) ?>"><?= e(c('site.email')) ?></a>.</p>
  </div>
</section>
