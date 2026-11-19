// Photo gallery: upload many photos one by one (so no single request gets too
// large for the server, with progress) and a simple full-screen viewer.
(function () {
  document.querySelectorAll('form[data-batch-upload]').forEach(function (form) {
    if (!window.fetch || !window.FormData) return; // the normal form upload still works
    var input = form.querySelector('input[type=file]');
    var progress = form.querySelector('[data-progress]');
    var button = form.querySelector('button[type=submit]');
    form.addEventListener('submit', function (e) {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) return;
      e.preventDefault();
      button.disabled = true;
      input.disabled = true;
      var done = 0, ok = 0, errors = [];
      var show = function () {
        progress.textContent = done < files.length
          ? 'Uploading ' + (done + 1) + ' of ' + files.length + ' … please keep this page open.'
          : ok + ' of ' + files.length + ' uploaded.';
      };
      var next = function () {
        if (done >= files.length) {
          show();
          if (errors.length) {
            progress.innerHTML = '';
            var p = document.createElement('span');
            p.textContent = ok + ' of ' + files.length + ' uploaded. Not uploaded: ' + errors.join(' · ');
            progress.appendChild(p);
            var a = document.createElement('a');
            a.href = location.pathname + location.search;
            a.textContent = ' Show photos';
            progress.appendChild(a);
            button.disabled = false;
            input.disabled = false;
          } else {
            location.href = location.pathname + location.search;
          }
          return;
        }
        show();
        var data = new FormData();
        Array.prototype.forEach.call(form.elements, function (el) {
          if (el.name && el.type !== 'file' && el.type !== 'submit' && !(el.type === 'checkbox' && !el.checked)) data.append(el.name, el.value);
        });
        data.append('ajax', '1');
        data.append(input.name, files[done], files[done].name);
        fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (j.ok) ok++; else errors = errors.concat(j.errors || ['„' + files[done].name + '“']); })
          .catch(function () { errors.push('„' + files[done].name + '“ (connection problem or file too large)'); })
          .then(function () { done++; next(); });
      };
      next();
    });
  });

  var box = document.getElementById('lightbox');
  var grid = document.querySelector('[data-lightbox]');
  if (!box || !grid || typeof box.showModal !== 'function') return;
  var links = Array.prototype.slice.call(grid.querySelectorAll('a[data-full]'));
  var img = box.querySelector('img');
  var count = box.querySelector('[data-lb=count]');
  var dl = box.querySelector('[data-lb=download]');
  var current = 0;
  var open = function (i) {
    current = (i + links.length) % links.length;
    img.src = links[current].getAttribute('data-full');
    dl.href = links[current].getAttribute('data-download');
    count.textContent = (current + 1) + ' / ' + links.length;
    if (!box.open) box.showModal();
  };
  links.forEach(function (a, i) {
    a.addEventListener('click', function (e) { e.preventDefault(); open(i); });
  });
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-lb]');
    var what = b && b.getAttribute('data-lb');
    if (what === 'prev') open(current - 1);
    else if (what === 'next') open(current + 1);
    else if (what === 'close' || e.target === box) box.close();
  });
  box.addEventListener('close', function () { img.removeAttribute('src'); });
  document.addEventListener('keydown', function (e) {
    if (!box.open) return;
    if (e.key === 'ArrowLeft') open(current - 1);
    if (e.key === 'ArrowRight') open(current + 1);
  });
  // Swipe on phones.
  var x0 = null;
  box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  box.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 50) open(current + (dx < 0 ? 1 : -1));
    x0 = null;
  });
})();
