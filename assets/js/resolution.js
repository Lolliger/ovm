// Resolution editor: live updates (polling), confirmations and the amendment form.
(function () {
  var root = document.querySelector('[data-res-state]');
  if (!root) return;
  var stateUrl = root.getAttribute('data-res-state');
  var rev = parseInt(root.getAttribute('data-rev'), 10) || 0;
  var layout = root.getAttribute('data-layout') || '';
  var stale = document.querySelector('.res-stale');
  var busy = false;
  var reloadPending = false;

  // Someone is typing here (focused text field or a form with unsaved input).
  function typingIn(el) {
    var a = document.activeElement;
    var field = a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.type !== 'hidden' && a.type !== 'submit';
    return (field && el.contains(a)) || !!el.querySelector('form.dirty');
  }

  // Replace a region but keep <details> open that the user had opened.
  function replace(el, html) {
    var open = [];
    el.querySelectorAll('details[open]').forEach(function (d) { open.push(d.className); });
    el.innerHTML = html;
    el.querySelectorAll('details').forEach(function (d) { if (open.indexOf(d.className) !== -1) d.open = true; });
  }

  function reloadWhenIdle() {
    if (typingIn(document.body)) {
      if (stale) stale.hidden = false;
      return; // checked again on the next poll
    }
    var y = window.scrollY;
    try { sessionStorage.setItem('res-scroll', location.href + '|' + y); } catch (e) {}
    location.reload();
  }
  try {
    var saved = (sessionStorage.getItem('res-scroll') || '').split('|');
    if (saved[0] === location.href) window.scrollTo(0, parseInt(saved[1], 10) || 0);
    sessionStorage.removeItem('res-scroll');
  } catch (e) {}

  function poll() {
    if (reloadPending) { reloadWhenIdle(); return; }
    if (busy || document.hidden) return;
    busy = true;
    fetch(stateUrl + '&rev=' + rev, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.regions) return;
        var skipped = false;
        Object.keys(data.regions).forEach(function (key) {
          document.querySelectorAll('[data-region="' + key + '"]').forEach(function (el) {
            if (typingIn(el)) skipped = true; else replace(el, data.regions[key]);
          });
        });
        // Keep asking for the regions until the skipped one could be updated too.
        if (!skipped) rev = data.rev;
        // Status, clauses, settings … changed: forms and buttons outside the
        // live regions are out of date, so reload the page (not while typing).
        if (data.layout && layout && data.layout !== layout) {
          reloadPending = true;
          reloadWhenIdle();
        }
      })
      .catch(function () {})
      .then(function () { busy = false; });
  }
  setInterval(poll, document.body.classList.contains('screen') ? 2000 : 3000);
  document.addEventListener('visibilitychange', poll);

  // Confirmations for forms and buttons with data-confirm.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-confirm]');
    if (b && !confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !confirm(msg)) e.preventDefault();
  });
  document.addEventListener('input', function (e) {
    var f = e.target.closest('form');
    if (f) f.classList.add('dirty');
  });

  // Amendment form: show the fields for the chosen type, prefill the clause text.
  var form = document.querySelector('.amend-form');
  if (form) {
    var kind = form.querySelector('[name=kind]');
    var target = form.querySelector('[name=target]');
    var text = form.querySelector('[name=text]');
    var touched = false;
    text.addEventListener('input', function () { touched = true; });
    function update() {
      form.querySelectorAll('[data-for]').forEach(function (el) {
        el.hidden = el.getAttribute('data-for').split(' ').indexOf(kind.value) === -1;
      });
      text.required = kind.value !== 'strike';
      if (kind.value === 'modify' && !touched && target.selectedOptions[0]) {
        text.value = target.selectedOptions[0].getAttribute('data-text') || '';
      } else if (kind.value === 'add' && !touched) {
        text.value = '';
      }
    }
    kind.addEventListener('change', update);
    target.addEventListener('change', function () { touched = false; update(); });
    update();
  }
})();
