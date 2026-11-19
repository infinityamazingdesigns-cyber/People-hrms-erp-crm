/* Ringing card for video meetings on any page (not the meeting page itself). Asks modules/meet/api.php?op=ring while the
   page is on screen; the check never keeps an idle sign-in alive. Join answers the ring and opens the meeting. */
(function () {
  "use strict";
  var me = document.currentScript, base = me.getAttribute("data-base") || "", csrf = me.getAttribute("data-csrf") || "", shown = {}, box = null, t = 0;
  function api(u) { return fetch(base + "modules/meet/api.php?" + u, { credentials: "same-origin", cache: "no-store" }).then(function (r) { return r.ok ? r.json() : { rings: [] }; }); }
  function answer(code, yes) {
    return fetch(base + "modules/meet/api.php", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF": csrf }, body: JSON.stringify({ op: "ringanswer", r: code, accept: yes }) }).catch(function () {});
  }
  function draw(rings) {
    var r = rings.filter(function (x) { return !shown[x.code]; })[0];
    if (!r) { if (box && !rings.length) { box.remove(); box = null; } return; }
    if (box) box.remove();
    box = document.createElement("div"); box.className = "wn-ring"; box.setAttribute("role", "alertdialog");
    var b = document.createElement("b"); b.textContent = r.from + " is calling you"; box.append(b);
    var s = document.createElement("span"); s.textContent = r.title; box.append(s);
    var row = document.createElement("div"); row.className = "row";
    var j = document.createElement("button"); j.className = "btn sm"; j.textContent = "Join";
    j.onclick = function () { answer(r.code, true).then(function () { location.href = base + "modules/meet/index.php?r=" + encodeURIComponent(r.code); }); };
    var d = document.createElement("button"); d.className = "btn sm ghost"; d.textContent = "Decline";
    d.onclick = function () { shown[r.code] = 1; answer(r.code, false); box.remove(); box = null; };
    row.append(j, d); box.append(row); document.body.append(box);
  }
  function tick() { clearTimeout(t); if (document.hidden) { t = setTimeout(tick, 15000); return; } api("op=ring").then(function (x) { draw(x.rings || []); }).catch(function () {}).finally(function () { t = setTimeout(tick, 10000); }); }
  document.addEventListener("visibilitychange", function () { if (!document.hidden) tick(); });
  tick();
})();
