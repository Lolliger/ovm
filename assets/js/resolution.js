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

  // Chair view: tabs (remembered per browser tab; a #anchor after saving opens its tab).
  var tabs = document.querySelectorAll('.res-tabs [data-tab]');
  if (tabs.length) {
    var show = function (name, remember) {
      tabs.forEach(function (t) { var on = t.getAttribute('data-tab') === name; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      document.querySelectorAll('.res-tab').forEach(function (p) { p.hidden = p.id !== 'tab-' + name; });
      if (remember) { try { sessionStorage.setItem('res-tab', name); } catch (e) {} }
    };
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab'), true); history.replaceState(null, '', location.pathname + location.search); }); });
    var start = null;
    var target = location.hash && document.getElementById(location.hash.slice(1));
    var panel = target && target.closest('.res-tab');
    if (panel) start = panel.id.replace('tab-', '');
    if (!start) { try { start = sessionStorage.getItem('res-tab'); } catch (e) {} }
    show(start && document.getElementById('tab-' + start) ? start : 'live', true);
    if (target) target.scrollIntoView({ block: 'start' });
  }

  // Quick actions (roll call, timer, next speaker): send without reloading the page.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute('data-quick') || e.defaultPrevented || !window.fetch) return;
    e.preventDefault();
    var btn = f.querySelector('button');
    if (btn) btn.disabled = true;
    fetch(location.href, { method: 'POST', body: new FormData(f), credentials: 'same-origin' })
      .catch(function () {})
      .then(function () { busy = false; poll(); setTimeout(function () { if (btn) btn.disabled = false; }, 400); });
  });

  // Speaker timer: count down locally between updates (server time is in data-now).
  function tick() {
    document.querySelectorAll('.timer').forEach(function (t) {
      if (!t.hasAttribute('data-ends')) return;
      if (t._offset === undefined) t._offset = parseInt(t.getAttribute('data-now'), 10) - Date.now();
      var left = Math.max(0, Math.ceil((parseInt(t.getAttribute('data-ends'), 10) - (Date.now() + t._offset)) / 1000));
      var dur = parseInt(t.getAttribute('data-dur'), 10) || 1;
      t.querySelector('.timer-time').textContent = Math.floor(left / 60) + ':' + ('0' + left % 60).slice(-2);
      t.querySelector('.timer-bar span').style.width = (left / dur * 100) + '%';
      t.classList.toggle('low', left <= 10 && left > 0);
      t.classList.toggle('over', left === 0);
    });
  }
  setInterval(tick, 250);
  tick();

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
