/* Ctrl + K palette: jump to a screen, an account item or a colleague's chat.
 * It searches only what this page already has — the menu links drawn for this login (each still checks access on its
 * own page) — plus Team chat's own people list when Team chat is open to the login. Nothing typed is sent anywhere. */
(function () {
  var me = document.currentScript, base = me.dataset.base || '', csrf = me.dataset.csrf || '', talk = me.dataset.talk === '1';
  var people = null, items = [], shown = [], sel = 0, back, inp, list;

  function pageItems() {
    var out = [], seen = {};
    document.querySelectorAll('aside.side nav a.item').forEach(function (a) {
      var g = a.previousElementSibling; while (g && !g.classList.contains('grp')) g = g.previousElementSibling;
      var t = (a.childNodes[0] && a.childNodes[0].textContent || a.textContent).trim();
      if (!t || seen[a.href]) return; seen[a.href] = 1;
      out.push({ g: 'Screens', t: t, s: g ? g.textContent.trim() : '', go: a.href });
    });
    document.querySelectorAll('header.top .who a').forEach(function (a) { out.push({ g: 'Account', t: a.textContent.trim(), s: '', go: a.href }); });
    return out;
  }
  function loadPeople() {
    if (!talk || people) return;
    people = [];
    fetch(base + 'modules/talk/index.php?people=1', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : { people: [] }; })
      .then(function (d) { people = (d.people || []).map(function (p) { return { g: 'People', t: p.name, s: [p.title, p.dept].filter(Boolean).join(' · '), dm: p.id }; }); if (back) draw(); })
      .catch(function () {});
  }
  function score(it, q) {
    if (!q) return it.g === 'People' ? -1 : 1;
    var t = (it.t + ' ' + it.s).toLowerCase(), i = t.indexOf(q);
    if (i === 0) return 3; if (i > 0 && t.charAt(i - 1) === ' ') return 2; if (i > 0) return 1;
    var j = 0; for (var k = 0; k < t.length && j < q.length; k++) if (t[k] === q[j]) j++;
    return j === q.length ? 0.5 : -1;
  }
  function draw() {
    var q = inp.value.trim().toLowerCase(), all = items.concat(people || []);
    shown = all.map(function (it) { return [score(it, q), it]; }).filter(function (x) { return x[0] >= 0; })
      .sort(function (a, b) { return b[0] - a[0]; }).slice(0, 40).map(function (x) { return x[1]; });
    var order = []; shown.forEach(function (it) { if (order.indexOf(it.g) < 0) order.push(it.g); });   // best-matching group first
    shown = shown.map(function (it, i) { return [order.indexOf(it.g), i, it]; }).sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; }).map(function (x) { return x[2]; });
    list.textContent = ''; sel = Math.min(sel, Math.max(0, shown.length - 1));
    if (!shown.length) { var n = document.createElement('li'); n.className = 'ck-none'; n.textContent = 'Nothing found'; list.appendChild(n); return; }
    var last = '';
    shown.forEach(function (it, i) {
      if (it.g !== last) { last = it.g; var h = document.createElement('li'); h.className = 'ck-g'; h.textContent = it.g; h.setAttribute('role', 'presentation'); list.appendChild(h); }
      var li = document.createElement('li'); li.className = 'ck-i'; li.id = 'ck-' + i; li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', i === sel ? 'true' : 'false');
      var b = document.createElement('span'); b.textContent = it.t; li.appendChild(b);
      if (it.s) { var s = document.createElement('small'); s.textContent = it.s; li.appendChild(s); }
      li.addEventListener('mousemove', function () { if (sel !== i) { sel = i; mark(); } });
      li.addEventListener('click', function () { pick(it); });
      list.appendChild(li);
    });
    inp.setAttribute('aria-activedescendant', 'ck-' + sel);
  }
  function mark() {
    list.querySelectorAll('.ck-i').forEach(function (li) { li.setAttribute('aria-selected', li.id === 'ck-' + sel ? 'true' : 'false'); });
    var c = document.getElementById('ck-' + sel); if (c) c.scrollIntoView({ block: 'nearest' });
    inp.setAttribute('aria-activedescendant', 'ck-' + sel);
  }
  function pick(it) {
    if (!it) return;
    if (it.go) { location.href = it.go; return; }
    if (it.dm) {   // opens (or starts) the chat through Team chat's own rules on the server
      var f = document.createElement('form'); f.method = 'post'; f.action = base + 'modules/talk/index.php';
      [['csrf', csrf], ['a', 'dm'], ['user', String(it.dm)]].forEach(function (p) { var x = document.createElement('input'); x.type = 'hidden'; x.name = p[0]; x.value = p[1]; f.appendChild(x); });
      document.body.appendChild(f); f.submit();
    }
  }
  function open() {
    if (back) return;
    items = pageItems(); loadPeople();
    back = document.createElement('div'); back.className = 'ck-back';
    back.innerHTML = '<div class="ck-box" role="dialog" aria-modal="true" aria-label="Search"><input type="text" role="combobox" aria-expanded="true" aria-controls="ck-list" placeholder="Search screens and people" autocomplete="off"><ul class="ck-list" id="ck-list" role="listbox"></ul></div>';
    inp = back.querySelector('input'); list = back.querySelector('ul'); sel = 0;
    back.addEventListener('mousedown', function (e) { if (e.target === back) close(); });
    inp.addEventListener('input', function () { sel = 0; draw(); });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (shown.length) { sel = (sel + 1) % shown.length; mark(); } }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (shown.length) { sel = (sel - 1 + shown.length) % shown.length; mark(); } }
      else if (e.key === 'Enter') { e.preventDefault(); pick(shown[sel]); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
    });
    document.body.appendChild(back); draw(); inp.focus();
  }
  function close() { if (back) { back.remove(); back = null; } }
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); back ? close() : open(); }
  });
  document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-ck-open]')) open(); });
  window.WNCmdK = { open: open, close: close };
})();
