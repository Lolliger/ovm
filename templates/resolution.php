<?php
/** @var array $ctx @var array $res @var string $self @var ?array $flash */
$cm = $ctx['committee'];
$chair = res_is_chair($ctx);
$viewer = res_is_viewer($ctx);
$canEdit = res_can_edit($ctx, $res);
$clauses = clauses_sorted($res['clauses']);
$nums = clause_numbers($clauses);
$regions = res_regions($ctx, $res, 'page');
$members = committee_members($cm);
$cur = amendment_by_id($res, $res['current']);
$myId = $ctx['reg']['id'] ?? '';
$archive = res_archive_list($cm['slug']);
$excerpt = fn (string $t) => mb_strimwidth($t, 0, 60, '…');
$clauseOptions = '';
foreach ($clauses as $cl) {
    $clauseOptions .= '<option value="' . e($cl['id']) . '" data-text="' . e($cl['text']) . '">' . e($nums[$cl['id']]['label'] . ' – ' . $excerpt($cl['text'])) . '</option>';
}
$positionOptions = '<option value="pre_start">At the beginning of the preamble</option>';
foreach ($clauses as $cl) {
    $positionOptions .= '<option value="' . e($cl['id']) . '">After ' . e($nums[$cl['id']]['label']) . '</option>';
}
$positionOptions .= '<option value="pre_end">At the end of the preamble</option><option value="op_end" selected>At the end of the operative clauses</option>';
$hidden = fn (string $a, string $anchor = '') => csrf_field() . '<input type="hidden" name="a" value="' . $a . '">' . ($anchor ? '<input type="hidden" name="anchor" value="' . $anchor . '">' : '');
?>
<section class="page-head res-pagehead">
  <div class="container">
    <p class="kicker"><a href="<?= e(portal_link()) ?>">Delegate area</a> / <?= e($cm['name']) ?></p>
    <div class="res-title">
      <h1>Resolution<?= $archive ? ' ' . (int) ($res['number'] ?? count($archive) + 1) : '' ?></h1>
      <span class="tag res-status" data-region="status"><?= $regions['status'] ?></span>
    </div>
    <p class="res-links">
      <?php if (count($ctx['committees']) > 1): ?>
        <select onchange="location.href='<?= e(portal_link('resolution')) ?>?c='+this.value" aria-label="Committee">
          <?php foreach ($ctx['committees'] as $slug => $name): ?><option value="<?= e($slug) ?>"<?= $slug === $cm['slug'] ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <span class="muted">You are: <strong><?= e($chair ? ($ctx['role'] === 'admin' ? 'Admin (chair rights)' : 'Chair') : $ctx['label']) ?></strong></span>
      <a href="<?= e($self) ?>&amp;view=print" target="_blank">Print / PDF</a>
      <?php if ($chair || $viewer): ?><a class="btn btn-small" href="<?= e($self) ?>&amp;view=screen" target="_blank">Open beamer view ↗</a><?php endif; ?>
    </p>
  </div>
</section>

<section class="section res-section" data-res-state="<?= e($self) ?>&amp;view=state" data-rev="<?= (int) $res['rev'] ?>" data-layout="<?= e(res_layout_key($ctx, $res)) ?>">
  <div class="container">
    <?php if ($flash): ?><div class="notice notice-<?= $flash[0] === 'error' ? 'error' : 'ok' ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
    <div class="notice res-stale" hidden>The page was changed by someone else. It reloads automatically when you have finished typing – or <a href="<?= e($self) ?>">reload now</a>.</div>

<?php if ($chair): ?>
    <nav class="res-tabs" role="tablist" aria-label="Chair view">
      <button type="button" role="tab" data-tab="live">Live <span class="tab-count" data-region="queue-count"><?= $regions['queue-count'] ?></span></button>
      <button type="button" role="tab" data-tab="doc">Document</button>
      <button type="button" role="tab" data-tab="roll">Roll call</button>
      <button type="button" role="tab" data-tab="settings">Settings</button>
    </nav>

    <div class="res-tab" id="tab-live" role="tabpanel">
      <div class="res-live">
        <div class="res-live-main">
          <div class="res-panel res-floor" id="live">
            <h2>On the floor</h2>
            <div data-region="floor"><?= $regions['floor'] ?></div>
          </div>
        <div class="res-panel" id="queue">
          <div class="panel-head"><h2>Amendments</h2><?php if ($cur): ?><form method="post"><?= $hidden('clear_floor', 'live') ?><button class="btn-ghost">Take off the floor</button></form><?php endif; ?></div>
          <p class="muted small">All submitted amendments. Only chairs see this list. Second-degree amendments are shown under their original.</p>
          <div data-region="queue"><?= $regions['queue'] ?></div>
        </div>

        </div>
        <aside class="res-side">
          <div class="res-panel"><div data-region="quorum"><?= $regions['quorum'] ?: '<p class="muted small">No roll call yet – see the “Roll call” tab.</p>' ?></div></div>
          <div class="res-panel" data-region="speakers"><?= $regions['speakers'] ?></div>
          <div class="res-panel">
            <form method="post" class="speaker-form">
              <?= $hidden('speaker_add', 'live') ?>
              <input name="label" list="delegations" placeholder="Add speaker (delegation)" required>
              <button class="btn btn-small">Add</button>
            </form>
            <datalist id="delegations"><?php foreach ($members as $m): ?><option value="<?= e(reg_label($m)) ?>"><?php endforeach; ?></datalist>
            <div class="speaker-actions">
              <form method="post" data-quick><?= $hidden('speaker_next', 'live') ?><button class="btn btn-small">Next speaker</button></form>
              <form method="post" data-confirm="Clear the speakers list?"><?= $hidden('speaker_clear', 'live') ?><button class="btn-ghost">Clear list</button></form>
            </div>
          </div>
          <details class="res-panel timer-panel" id="timer"<?= !empty($res['timer']['on']) ? ' open' : '' ?>>
            <summary><h2>Speaker timer</h2><span class="muted small">optional</span></summary>
            <div data-region="timer"><?= $regions['timer'] ?></div>
            <?php if (!empty($res['timer']['on'])): ?>
              <div class="timer-actions">
                <form method="post" data-quick><?= $hidden('timer_start', 'timer') ?><button class="btn btn-small">Start</button></form>
                <form method="post" data-quick><?= $hidden('timer_pause', 'timer') ?><button class="btn-ghost">Pause</button></form>
                <form method="post" data-quick><?= $hidden('timer_reset', 'timer') ?><button class="btn-ghost">Reset</button></form>
                <form method="post"><?= $hidden('timer_off', 'timer') ?><button class="btn-link">Hide timer</button></form>
              </div>
            <?php endif; ?>
            <form method="post" class="timer-set">
              <?= $hidden('timer_set', 'timer') ?>
              <span>Speaking time</span>
              <?php $dur = (int) ($res['timer']['dur'] ?? 60); ?>
              <input type="number" name="min" min="0" max="30" value="<?= intdiv($dur, 60) ?>" aria-label="Minutes"> min
              <input type="number" name="sec" min="0" max="59" step="5" value="<?= $dur % 60 ?>" aria-label="Seconds"> s
              <button class="btn-ghost"><?= !empty($res['timer']['on']) ? 'Set' : 'Show timer' ?></button>
            </form>
            <p class="muted small">Shown on the beamer next to the speakers list. “Next speaker” resets it.</p>
          </details>
        </aside>
      </div>
    </div>

    <div class="res-tab" id="tab-doc" role="tabpanel">
      <div class="res-doc-tab">
        <div class="res-paper" data-region="doc"><?= $regions['doc'] ?></div>
        <?php if ($canEdit): ?>
          <details class="res-panel res-editor" id="editor"<?= $chair || isset($_GET['edit']) || $res['status'] === 'draft' ? ' open' : '' ?>>
            <summary><h2>Edit document</h2><span class="muted"><?= $chair ? 'Changes are visible to everyone immediately.' : 'You are the main submitter – you can edit until the chairs open the debate.' ?></span></summary>
            <?php foreach ($clauses as $cl): ?>
              <form method="post" class="clause-edit" id="c-<?= e($cl['id']) ?>">
                <?= $hidden('clause_edit', 'editor') ?><input type="hidden" name="id" value="<?= e($cl['id']) ?>">
                <div class="clause-edit-head">
                  <strong><?= e($nums[$cl['id']]['label']) ?></strong>
                  <select name="ctype"><option value="pre"<?= $cl['type'] === 'pre' ? ' selected' : '' ?>>Preambular</option><option value="op"<?= $cl['type'] === 'op' ? ' selected' : '' ?>>Operative</option></select>
                  <select name="level"><option value="0">Main clause</option><option value="1"<?= (int) $cl['level'] === 1 ? ' selected' : '' ?>>Sub-clause (a)</option><option value="2"<?= (int) $cl['level'] === 2 ? ' selected' : '' ?>>Sub-sub-clause (i)</option></select>
                  <span class="spacer"></span>
                  <button class="icon" name="a" value="clause_move" formnovalidate onclick="this.form.dir.value='up'" title="Move up">↑</button>
                  <button class="icon" name="a" value="clause_move" formnovalidate onclick="this.form.dir.value='down'" title="Move down">↓</button>
                  <button class="icon danger" name="a" value="clause_delete" formnovalidate data-confirm="Delete this clause?" title="Delete">✕</button>
                  <input type="hidden" name="dir" value="">
                </div>
                <textarea name="text" rows="2" required><?= e($cl['text']) ?></textarea>
                <button class="btn btn-small">Save</button>
              </form>
            <?php endforeach; ?>
            <form method="post" class="clause-edit clause-new">
              <?= $hidden('clause_add', 'editor') ?>
              <div class="clause-edit-head">
                <strong>New clause</strong>
                <select name="ctype"><option value="pre">Preambular</option><option value="op" selected>Operative</option></select>
                <select name="level"><option value="0">Main clause</option><option value="1">Sub-clause (a)</option><option value="2">Sub-sub-clause (i)</option></select>
                <select name="after"><?= $positionOptions ?></select>
              </div>
              <textarea name="text" rows="2" required placeholder="e.g. Calls upon all Member States to …"></textarea>
              <button class="btn btn-small">Add clause</button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    </div>

    <div class="res-tab" id="tab-roll" role="tabpanel">
      <div class="res-panel" id="roll">
        <div class="panel-head"><h2>Roll call</h2>
          <div class="speaker-actions">
            <form method="post" data-quick><?= $hidden('roll_all', 'roll') ?><button class="btn-ghost">Everyone present</button></form>
            <form method="post" data-confirm="Clear the roll call?"><?= $hidden('roll_reset', 'roll') ?><button class="btn-ghost">Reset</button></form>
          </div>
        </div>
        <p class="muted small">Click the status of each delegation. Majorities are calculated from the delegations present and shown in the “Live” tab and on the beamer.</p>
        <div data-region="quorum"><?= $regions['quorum'] ?></div>
        <div data-region="roll"><?= $regions['roll'] ?></div>
      </div>
    </div>

    <div class="res-tab" id="tab-settings" role="tabpanel">
        <div class="res-panel" id="settings">
          <h2>Settings</h2>
          <form method="post" class="form">
            <?= $hidden('meta', 'settings') ?>
            <label class="field"><span>Topic</span><input name="topic" value="<?= e($res['topic']) ?>" list="topics"></label>
            <datalist id="topics"><?php foreach ($cm['topics'] ?? [] as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
            <?php if (res_has_submitters($cm)): ?>
            <label class="field"><span>Main submitter</span>
              <select name="main_submitter"><option value="">– none –</option>
                <?php foreach ($members as $m): ?><option value="<?= e($m['id']) ?>"<?= $res['main_submitter'] === $m['id'] ? ' selected' : '' ?>><?= e(reg_label($m)) ?></option><?php endforeach; ?>
              </select></label>
            <label class="field"><span>Co-submitter(s)</span><input name="co_submitters" value="<?= e($res['co_submitters']) ?>" placeholder="e.g. France, Japan, Kenya"></label>
            <label class="field"><span>Signatories</span><input name="signatories" value="<?= e($res['signatories'] ?? '') ?>" placeholder="e.g. Brazil, Germany, India"></label>
            <?php endif; ?>
            <button class="btn btn-small">Save details</button>
          </form>
          <p class="status-row">Status:
            <?php foreach (['draft' => res_has_submitters($cm) ? 'Draft (main submitter writes)' : 'Draft', 'debate' => 'Open debate (amendments)', 'closed' => 'Close'] as $k => $label): ?>
              <form method="post"><?= $hidden('status', 'settings') ?><input type="hidden" name="status" value="<?= $k ?>"><button class="<?= $res['status'] === $k ? 'btn btn-small' : 'btn-ghost' ?>"<?= $res['status'] === $k ? ' disabled' : '' ?>><?= e($label) ?></button></form>
            <?php endforeach; ?>
          </p>
          <form method="post" class="res-new" data-confirm="Save this resolution as finished and start a new, empty one? The finished resolution stays available under “Previous resolutions”.">
            <?= $hidden('res_new') ?>
            <strong>Resolution finished?</strong>
            <label>Result <select name="outcome"><?php foreach (RES_OUTCOMES as $o): ?><option><?= e($o) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-small">Save &amp; start new resolution</button>
          </form>
        </div>
      
    <?php if ($archive): ?>
      <div class="res-panel res-archive">
        <h2>Previous resolutions</h2>
        <ul>
          <?php foreach (array_reverse($archive) as $old): ?>
            <li><span class="tag"><?= e($old['outcome'] ?? 'Closed') ?></span>
              <strong>Resolution <?= (int) ($old['number'] ?? 1) ?></strong> · <?= e($old['topic'] ?: 'No topic') ?>
              <span class="muted"><?= e(date('j M Y, H:i', strtotime($old['archived']))) ?></span>
              <a href="<?= e($self) ?>&amp;view=print&amp;res=<?= e($old['id']) ?>" target="_blank">View / PDF</a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    </div>
<?php else: ?>
    <div class="res-layout">
      <div class="res-main">
        <div class="res-paper" data-region="doc"><?= $regions['doc'] ?></div>
        <?php if (!$viewer && $res['status'] === 'debate'): ?><p class="muted small">Highlighted boxes are your own amendments – only you and the chairs can see them.</p><?php endif; ?>
        <?php if ($canEdit): ?>
          <details class="res-panel res-editor" id="editor"<?= $chair || isset($_GET['edit']) || $res['status'] === 'draft' ? ' open' : '' ?>>
            <summary><h2>Edit document</h2><span class="muted"><?= $chair ? 'Changes are visible to everyone immediately.' : 'You are the main submitter – you can edit until the chairs open the debate.' ?></span></summary>
            <?php foreach ($clauses as $cl): ?>
              <form method="post" class="clause-edit" id="c-<?= e($cl['id']) ?>">
                <?= $hidden('clause_edit', 'editor') ?><input type="hidden" name="id" value="<?= e($cl['id']) ?>">
                <div class="clause-edit-head">
                  <strong><?= e($nums[$cl['id']]['label']) ?></strong>
                  <select name="ctype"><option value="pre"<?= $cl['type'] === 'pre' ? ' selected' : '' ?>>Preambular</option><option value="op"<?= $cl['type'] === 'op' ? ' selected' : '' ?>>Operative</option></select>
                  <select name="level"><option value="0">Main clause</option><option value="1"<?= (int) $cl['level'] === 1 ? ' selected' : '' ?>>Sub-clause (a)</option><option value="2"<?= (int) $cl['level'] === 2 ? ' selected' : '' ?>>Sub-sub-clause (i)</option></select>
                  <span class="spacer"></span>
                  <button class="icon" name="a" value="clause_move" formnovalidate onclick="this.form.dir.value='up'" title="Move up">↑</button>
                  <button class="icon" name="a" value="clause_move" formnovalidate onclick="this.form.dir.value='down'" title="Move down">↓</button>
                  <button class="icon danger" name="a" value="clause_delete" formnovalidate data-confirm="Delete this clause?" title="Delete">✕</button>
                  <input type="hidden" name="dir" value="">
                </div>
                <textarea name="text" rows="2" required><?= e($cl['text']) ?></textarea>
                <button class="btn btn-small">Save</button>
              </form>
            <?php endforeach; ?>
            <form method="post" class="clause-edit clause-new">
              <?= $hidden('clause_add', 'editor') ?>
              <div class="clause-edit-head">
                <strong>New clause</strong>
                <select name="ctype"><option value="pre">Preambular</option><option value="op" selected>Operative</option></select>
                <select name="level"><option value="0">Main clause</option><option value="1">Sub-clause (a)</option><option value="2">Sub-sub-clause (i)</option></select>
                <select name="after"><?= $positionOptions ?></select>
              </div>
              <textarea name="text" rows="2" required placeholder="e.g. Calls upon all Member States to …"></textarea>
              <button class="btn btn-small">Add clause</button>
            </form>
          </details>
        <?php endif; ?>
      </div>

      <aside class="res-side">
        <div class="res-panel res-floor">
          <h2>On the floor</h2>
          <div data-region="floor"><?= $regions['floor'] ?></div>
        </div>
        <div data-region="timer"><?= $regions['timer'] ?></div>
        <div class="res-panel" data-region="speakers"><?= $regions['speakers'] ?></div>
        <div data-region="quorum"><?= $regions['quorum'] ?></div>
      </aside>
    </div>

    <?php if ($viewer): ?>
      <p class="muted small">View only: this account can follow every committee and open the beamer view, but cannot change anything.</p>
    <?php else: ?>
      <div class="res-delegate">
        <?php if ($res['status'] === 'debate'): ?>
          <div class="res-panel" id="amend">
            <h2>Submit an amendment</h2>
            <form method="post" class="form amend-form">
              <?= $hidden('amend', 'amend') ?>
              <div class="row">
                <label class="field"><span>Type</span>
                  <select name="kind"><option value="modify">Change a clause</option><option value="add">Add a clause</option><option value="strike">Strike a clause</option></select></label>
                <label class="field" data-for="modify strike"><span>Clause</span><select name="target"><?= $clauseOptions ?></select></label>
              </div>
              <div class="row" data-for="add">
                <label class="field"><span>Position</span><select name="after"><?= $positionOptions ?></select></label>
                <label class="field"><span>Kind of clause</span>
                  <select name="ctype"><option value="op">Operative</option><option value="pre">Preambular</option></select></label>
                <label class="field"><span>Level</span>
                  <select name="level"><option value="0">Main clause</option><option value="1">Sub-clause (a)</option><option value="2">Sub-sub-clause (i)</option></select></label>
              </div>
              <label class="field" data-for="modify add"><span>Text</span><textarea name="text" rows="4" placeholder="Your wording"></textarea></label>
              <button class="btn">Submit to the chairs</button>
              <p class="muted small">Your amendment is visible only to you and the chairs until they put it on the floor.</p>
            </form>
          </div>
        <?php elseif ($res['status'] === 'draft'): ?>
          <div class="notice">The resolution is still being drafted<?= $canEdit ? '' : (res_has_submitters($cm) ? ' by the main submitter' : ' by the chairs') ?>. Amendments can be submitted once the chairs open the debate.</div>
        <?php endif; ?>
        <div class="res-panel">
          <h2>Your amendments</h2>
          <div data-region="mine"><?= $regions['mine'] ?></div>
        </div>
      </div>
    <?php endif; ?>
    <?php if ($archive): ?>
      <div class="res-panel res-archive">
        <h2>Previous resolutions</h2>
        <ul>
          <?php foreach (array_reverse($archive) as $old): ?>
            <li><span class="tag"><?= e($old['outcome'] ?? 'Closed') ?></span>
              <strong>Resolution <?= (int) ($old['number'] ?? 1) ?></strong> · <?= e($old['topic'] ?: 'No topic') ?>
              <span class="muted"><?= e(date('j M Y, H:i', strtotime($old['archived']))) ?></span>
              <a href="<?= e($self) ?>&amp;view=print&amp;res=<?= e($old['id']) ?>" target="_blank">View / PDF</a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
<?php endif; ?>
  </div>
</section>
<script src="<?= e(url('assets/js/resolution.js')) ?>?v=<?= filemtime(ROOT . '/assets/js/resolution.js') ?>" defer></script>
