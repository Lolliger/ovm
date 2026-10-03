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
      <h1>Resolution</h1>
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

<section class="section res-section" data-res-state="<?= e($self) ?>&amp;view=state" data-rev="<?= (int) $res['rev'] ?>">
  <div class="container">
    <?php if ($flash): ?><div class="notice notice-<?= $flash[0] === 'error' ? 'error' : 'ok' ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
    <div class="notice res-stale" hidden>The document was changed by someone else. <a href="<?= e($self) ?>">Reload</a> before editing.</div>

    <div class="res-layout">
      <div class="res-main">
        <div class="res-paper" data-region="doc"><?= $regions['doc'] ?></div>
        <?php if (!$chair && !$viewer && $res['status'] === 'debate'): ?><p class="muted small">Highlighted boxes are your own amendments – only you and the chairs can see them.</p><?php endif; ?>

        <?php if ($canEdit): ?>
          <details class="res-panel res-editor" id="editor"<?= isset($_GET['edit']) || $res['status'] === 'draft' ? ' open' : '' ?>>
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

        <div class="res-panel" data-region="speakers"><?= $regions['speakers'] ?></div>

        <?php if ($chair): ?>
          <div class="res-panel">
            <form method="post" class="speaker-form">
              <?= $hidden('speaker_add') ?>
              <input name="label" list="delegations" placeholder="Add speaker (delegation)" required>
              <button class="btn btn-small">Add</button>
            </form>
            <datalist id="delegations"><?php foreach ($members as $m): ?><option value="<?= e(reg_label($m)) ?>"><?php endforeach; ?></datalist>
            <div class="speaker-actions">
              <form method="post"><?= $hidden('speaker_next') ?><button class="btn-ghost">Next speaker</button></form>
              <form method="post" data-confirm="Clear the speakers list?"><?= $hidden('speaker_clear') ?><button class="btn-ghost">Clear list</button></form>
            </div>
          </div>
        <?php endif; ?>
      </aside>
    </div>

    <?php if ($chair): ?>
      <div class="res-chair">
        <div class="res-panel" id="queue">
          <div class="panel-head"><h2>Amendments</h2><?php if ($cur): ?><form method="post"><?= $hidden('clear_floor', 'queue') ?><button class="btn-ghost">Take off the floor</button></form><?php endif; ?></div>
          <p class="muted small">All submitted amendments. Only chairs see this list. Second-degree amendments are shown under their original.</p>
          <div data-region="queue"><?= $regions['queue'] ?></div>
        </div>

        <div class="res-panel" id="settings">
          <h2>Settings</h2>
          <form method="post" class="form">
            <?= $hidden('meta', 'settings') ?>
            <label class="field"><span>Topic</span><input name="topic" value="<?= e($res['topic']) ?>" list="topics"></label>
            <datalist id="topics"><?php foreach ($cm['topics'] ?? [] as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
            <label class="field"><span>Main submitter</span>
              <select name="main_submitter"><option value="">– none –</option>
                <?php foreach ($members as $m): ?><option value="<?= e($m['id']) ?>"<?= $res['main_submitter'] === $m['id'] ? ' selected' : '' ?>><?= e(reg_label($m)) ?></option><?php endforeach; ?>
              </select></label>
            <label class="field"><span>Co-submitter(s)</span><input name="co_submitters" value="<?= e($res['co_submitters']) ?>" placeholder="e.g. France, Japan, Kenya"></label>
            <label class="field"><span>Signatories</span><input name="signatories" value="<?= e($res['signatories'] ?? '') ?>" placeholder="e.g. Brazil, Germany, India"></label>
            <button class="btn btn-small">Save details</button>
          </form>
          <p class="status-row">Status:
            <?php foreach (['draft' => 'Draft (main submitter writes)', 'debate' => 'Open debate (amendments)', 'closed' => 'Close'] as $k => $label): ?>
              <form method="post"><?= $hidden('status', 'settings') ?><input type="hidden" name="status" value="<?= $k ?>"><button class="<?= $res['status'] === $k ? 'btn btn-small' : 'btn-ghost' ?>"<?= $res['status'] === $k ? ' disabled' : '' ?>><?= e($label) ?></button></form>
            <?php endforeach; ?>
          </p>
        </div>
      </div>
    <?php elseif ($viewer): ?>
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
          <div class="notice">The resolution is still being drafted<?= $canEdit ? '' : ' by the main submitter' ?>. Amendments can be submitted once the chairs open the debate.</div>
        <?php endif; ?>
        <div class="res-panel">
          <h2>Your amendments</h2>
          <div data-region="mine"><?= $regions['mine'] ?></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
<script src="<?= e(url('assets/js/resolution.js')) ?>?v=<?= filemtime(ROOT . '/assets/js/resolution.js') ?>" defer></script>
