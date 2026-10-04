// Countdown to the conference start and small menu helpers.
(function () {
  var el = document.querySelector('[data-countdown]');
  if (el) {
    var target = new Date(el.getAttribute('data-countdown')).getTime();
    var units = {
      d: el.querySelector('[data-unit="d"]'),
      h: el.querySelector('[data-unit="h"]'),
      m: el.querySelector('[data-unit="m"]'),
      s: el.querySelector('[data-unit="s"]')
    };
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    var tick = function () {
      var diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
      if (diff === 0) {
        Array.prototype.forEach.call(el.children, function (c) { c.hidden = true; });
        el.querySelector('.countdown-done').hidden = false;
        return;
      }
      units.d.textContent = Math.floor(diff / 86400);
      units.h.textContent = pad(Math.floor(diff % 86400 / 3600));
      units.m.textContent = pad(Math.floor(diff % 3600 / 60));
      units.s.textContent = pad(diff % 60);
      setTimeout(tick, 1000 - Date.now() % 1000);
    };
    if (!isNaN(target)) tick();
  }

  var toggle = document.getElementById('menu-toggle');
  if (toggle) {
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') toggle.checked = false;
    });
    document.querySelectorAll('.side-menu a').forEach(function (a) {
      a.addEventListener('click', function () { toggle.checked = false; });
    });
  }
})();

// Team: open a person as a pop-up, the rest of the page is blurred behind it.
(function () {
  var dialog = document.querySelector('.person-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  var body = dialog.querySelector('.person-dialog-body');
  var lastFocus = null;
  document.querySelectorAll('.person').forEach(function (person) {
    var tpl = person.querySelector('.person-detail');
    var open = function () {
      lastFocus = document.activeElement;
      body.innerHTML = '';
      body.appendChild(tpl.content.cloneNode(true));
      dialog.showModal();
      document.documentElement.classList.add('dialog-open');
    };
    person.querySelector('.person-open').addEventListener('click', open);
    person.querySelector('figcaption').addEventListener('click', open);
  });
  var close = function () { dialog.close(); };
  dialog.querySelector('.person-close').addEventListener('click', close);
  // Click on the blurred background closes it.
  dialog.addEventListener('click', function (e) { if (e.target === dialog) close(); });
  dialog.addEventListener('close', function () {
    document.documentElement.classList.remove('dialog-open');
    if (lastFocus) lastFocus.focus();
  });
})();

/* Registration form: chairs and conference managers get a shorter form */
(function () {
  var select = document.querySelector('[data-role-select]');
  if (!select) return;
  var form = select.form;
  var kindOf = function () {
    var o = select.options[select.selectedIndex];
    return (o && o.getAttribute('data-kind')) || 'delegate';
  };
  var apply = function () {
    var kind = kindOf();
    form.querySelectorAll('[data-kinds]').forEach(function (el) {
      var show = el.getAttribute('data-kinds').split(' ').indexOf(kind) !== -1;
      el.hidden = !show;
      el.querySelectorAll('input, select, textarea').forEach(function (f) { f.disabled = !show; });
    });
    return kind;
  };
  apply();
  select.addEventListener('change', function () {
    apply();
    var o = select.options[select.selectedIndex];
    var popup = o && o.getAttribute('data-popup') && document.getElementById(o.getAttribute('data-popup'));
    if (popup && typeof popup.showModal === 'function') {
      popup.showModal();
      document.documentElement.classList.add('dialog-open');
    }
  });
  document.querySelectorAll('.notice-dialog').forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    d.addEventListener('close', function () { document.documentElement.classList.remove('dialog-open'); });
  });
})();
