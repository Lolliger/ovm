// Admin helpers: repeater rows, confirmations, filters, copy buttons.
(function () {
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !confirm(msg)) e.preventDefault();
  });

  // Repeater rows (schedule, numbers, …)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) return;
    var field = btn.closest('.field-repeater');
    if (btn.classList.contains('add-row') && field) {
      var list = field.querySelector('.repeater');
      var i = parseInt(list.getAttribute('data-next'), 10) || 0;
      var html = field.querySelector('template').innerHTML.replace(/__i__/g, 'n' + i);
      list.setAttribute('data-next', i + 1);
      list.insertAdjacentHTML('beforeend', html);
      var first = list.lastElementChild.querySelector('input, textarea, select');
      if (first) first.focus();
      return;
    }
    var row = btn.closest('.rep-row');
    if (!row) return;
    if (btn.classList.contains('remove')) row.remove();
    if (btn.classList.contains('up') && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
    if (btn.classList.contains('down') && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
    if (btn.classList.contains('copy')) return;
  });

  // Repeater rows are submitted in DOM order, so reordering just works
  // (PHP keeps insertion order of f[key][index]).

  document.querySelectorAll('.copy').forEach(function (b) {
    b.addEventListener('click', function () {
      navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
        var t = b.textContent; b.textContent = 'Kopiert ✓';
        setTimeout(function () { b.textContent = t; }, 1500);
      });
    });
  });

  document.querySelectorAll('input[type=color]').forEach(function (c) {
    c.addEventListener('input', function () { c.nextElementSibling.textContent = c.value; });
  });

  document.querySelectorAll('.filter').forEach(function (f) {
    f.addEventListener('input', function () {
      var q = f.value.toLowerCase();
      document.querySelectorAll(f.getAttribute('data-filter')).forEach(function (el) {
        el.hidden = q && el.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  });

  // Warn before leaving a form with unsaved changes.
  var dirty = false;
  document.querySelectorAll('.edit-form').forEach(function (form) {
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  // Ctrl/Cmd+S saves the current form.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      var form = document.querySelector('form.edit-form[method=post]');
      if (form) { e.preventDefault(); dirty = false; form.requestSubmit(); }
    }
  });
})();
