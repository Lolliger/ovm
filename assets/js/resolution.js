// Resolution editor: live updates (polling), confirmations and the amendment form.
(function () {
  var root = document.querySelector('[data-res-state]');
  if (!root) return;
  var stateUrl = root.getAttribute('data-res-state');
  var rev = parseInt(root.getAttribute('data-rev'), 10) || 0;
  var stale = document.querySelector('.res-stale');
  var busy = false;

  function busyIn(el) {
    var a = document.activeElement;
    return (a && a !== document.body && el.contains(a)) || el.querySelector('details[open] textarea, form.dirty');
  }

  function poll() {
    if (busy || document.hidden) return;
    busy = true;
    fetch(stateUrl + '&rev=' + rev, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.regions) return;
        rev = data.rev;
        Object.keys(data.regions).forEach(function (key) {
          document.querySelectorAll('[data-region="' + key + '"]').forEach(function (el) {
            if (!busyIn(el)) el.innerHTML = data.regions[key];
          });
        });
        if (stale && document.querySelector('.res-editor')) stale.hidden = false;
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
