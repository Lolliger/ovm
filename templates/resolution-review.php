<?php
/** Review of a handed-in resolution by the MUN Coordinator. @var array $cm @var array $res @var string $self @var ?array $flash */
$clauses = clauses_sorted($res['clauses']);
$nums = clause_numbers($clauses);
$released = ($res['review'] ?? '') === 'released';
$positionOptions = '<option value="pre_start">At the beginning of the preamble</option>';
foreach ($clauses as $cl) {
    $positionOptions .= '<option value="' . e($cl['id']) . '">After ' . e($nums[$cl['id']]['label']) . '</option>';
}
$positionOptions .= '<option value="pre_end">At the end of the preamble</option><option value="op_end" selected>At the end of the operative clauses</option>';
$hidden = fn (string $a, string $anchor = '') => csrf_field() . '<input type="hidden" name="a" value="' . $a . '">' . ($anchor ? '<input type="hidden" name="anchor" value="' . $anchor . '">' : '');
$view = res_public_link($cm, $res);
?>
<section class="page-head res-pagehead">
  <div class="container">
    <p class="kicker"><a href="<?= e(portal_link()) ?>">Delegate area</a> / <?= e($cm['name']) ?> / Review</p>
    <div class="res-title">
      <h1>Resolution <?= (int) ($res['number'] ?? 1) ?></h1>
      <span class="tag <?= $released ? 'status-confirmed' : 'status-waitlist' ?>"><?= $released ? 'Released' : 'Waiting for release' ?></span>
    </div>
    <p class="res-links">
      <span class="muted">Handed in by the chairs on <?= e(date('j F Y, H:i', strtotime($res['archived']))) ?> · Result: <strong><?= e($res['outcome'] ?? '') ?></strong></span>
      <a href="<?= e($view) ?>" target="_blank">Print / PDF</a>
      <a href="<?= e($view . '&format=docx') ?>">Word file</a>
    </p>
  </div>
</section>

<section class="section res-section">
  <div class="container">
    <?php if ($flash): ?><div class="notice notice-<?= $flash[0] === 'error' ? 'error' : 'ok' ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
    <div class="notice">
      <?php if ($released): ?>
        <p><strong>This resolution is released.</strong> Everyone who is logged in to the delegate area – from every committee – can read it and download it as PDF and Word file. You can still correct it; changes are visible immediately.</p>
        <form method="post" data-confirm="Take the release back? Delegates will no longer see this resolution until you release it again."><?= $hidden('unrelease') ?><button class="btn-ghost">Take release back</button></form>
      <?php else: ?>
        <p><strong>Not released yet.</strong> Only the chairs of this committee and you can see this resolution. Correct spelling and wording where needed, then release it: afterwards delegates and chairs of all committees can read it and download it as PDF and Word file.</p>
        <form method="post" data-confirm="Finish the review and release this resolution for everyone in the delegate area?"><?= $hidden('release') ?><button class="btn">Finish and release</button></form>
      <?php endif; ?>
    </div>

    <div class="res-doc-tab">
      <div class="res-paper"><?= res_document_html($res, $cm) ?></div>
      <div class="res-panel res-editor" id="editor">
        <h2>Revise</h2>
        <form method="post" class="form" id="meta">
          <?= $hidden('meta', 'meta') ?>
          <label class="field"><span>Topic</span><input name="topic" value="<?= e($res['topic']) ?>"></label>
          <?php if (res_has_submitters($cm)): ?>
            <label class="field"><span>Co-submitter(s)</span><input name="co_submitters" value="<?= e($res['co_submitters']) ?>"></label>
            <label class="field"><span>Signatories</span><input name="signatories" value="<?= e($res['signatories'] ?? '') ?>"></label>
          <?php else: ?>
            <input type="hidden" name="co_submitters" value="<?= e($res['co_submitters']) ?>"><input type="hidden" name="signatories" value="<?= e($res['signatories'] ?? '') ?>">
          <?php endif; ?>
          <button class="btn btn-small">Save details</button>
        </form>
        <?php foreach ($clauses as $cl): ?>
          <form method="post" class="clause-edit" id="c-<?= e($cl['id']) ?>">
            <?= $hidden('clause_edit', 'c-' . $cl['id']) ?><input type="hidden" name="id" value="<?= e($cl['id']) ?>">
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
          <textarea name="text" rows="2" required></textarea>
          <button class="btn btn-small">Add clause</button>
        </form>
      </div>
    </div>
  </div>
</section>
<script>
// Confirmations for forms and buttons with data-confirm.
document.addEventListener('click', function (e) {
  var b = e.target.closest('button[data-confirm]');
  if (b && !confirm(b.getAttribute('data-confirm'))) e.preventDefault();
});
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !confirm(msg)) e.preventDefault();
});
</script>
