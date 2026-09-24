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
