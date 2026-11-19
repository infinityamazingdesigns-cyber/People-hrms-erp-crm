/* Turn browser notifications on / off for this device (modules/notify/, lib/push.php, sw.js).
   [data-push-box] panel: state, Turn on / Send a test / Turn off. A browser with notifications on re-sends its
   subscription once a day and when another login signs in on it, so notices go to whoever is signed in there. */
(function () {
  "use strict";
  var box = document.querySelector("[data-push-box]");
  if (!box) return;
  var ROOT = box.getAttribute("data-base") || "/", UID = box.getAttribute("data-uid") || "", CSRF = box.getAttribute("data-csrf") || "";
  var API = ROOT + "modules/notify/api.php";
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  var standalone = (window.matchMedia && matchMedia("(display-mode: standalone)").matches) || navigator.standalone === true;
  var ok = "serviceWorker" in navigator && "PushManager" in window && "Notification" in window && window.isSecureContext;
  var st = { on: false, blocked: false, busy: false, msg: "", bad: false };
  function api(b) {
    return fetch(API, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF": CSRF }, body: JSON.stringify(b) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || "Could not save"); return j; }); });
  }
  function key64(s) { var b = atob((s + "=".repeat((4 - s.length % 4) % 4)).replace(/-/g, "+").replace(/_/g, "/")), a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a; }
  function current() { return navigator.serviceWorker.getRegistration(ROOT).then(function (r) { return r ? r.pushManager.getSubscription() : null; }).catch(function () { return null; }); }
  function mark() { try { localStorage.setItem("wn-push-sync", UID + ":" + new Date().toDateString()); } catch (e) {} }
  function marked() { try { return localStorage.getItem("wn-push-sync") === UID + ":" + new Date().toDateString(); } catch (e) { return false; } }
  function on() {
    if (st.busy) return; st.busy = true; st.msg = ""; draw();
    Notification.requestPermission().then(function (p) {
      if (p !== "granted") { st.blocked = p === "denied"; throw new Error(p === "denied" ? "Notifications are blocked for this site. Allow them in the browser's site settings, then try again." : "Notifications were not allowed."); }
      return navigator.serviceWorker.register(ROOT + "sw.js", { scope: ROOT });
    }).then(function () { return navigator.serviceWorker.ready; })
      .then(function (r) { return fetch(API + "?key=1", { credentials: "same-origin" }).then(function (x) { return x.json(); }).then(function (j) {
        return r.pushManager.getSubscription().then(function (old) { return old || r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key64(j.key) }); }); }); })
      .then(function (sub) { return api({ op: "subscribe", sub: sub.toJSON() }); })
      .then(function () { mark(); st.on = true; return api({ op: "test" }); })
      .then(function () { st.msg = "Notifications are on for this device. A test is on its way."; st.bad = false; })
      .catch(function (e) { st.msg = e.message || "Could not turn notifications on."; st.bad = true; })
      .then(function () { st.busy = false; draw(); });
  }
  function off() {
    st.busy = true; draw();
    current().then(function (s) { if (!s) return; var ep = s.endpoint; return s.unsubscribe().then(function () { return api({ op: "unsubscribe", endpoint: ep }); }); })
      .then(function () { st.on = false; st.msg = "Notifications are off for this device."; st.bad = false; })
      .catch(function (e) { st.msg = e.message; st.bad = true; }).then(function () { st.busy = false; draw(); });
  }
  function test() {
    st.busy = true; draw();
    api({ op: "test" }).then(function () { st.msg = "Test sent. It should appear in a few seconds."; st.bad = false; }).catch(function (e) { st.msg = e.message; st.bad = true; }).then(function () { st.busy = false; draw(); });
  }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
  function btn(label, act, ghost) { var b = el("button", "btn sm" + (ghost ? " ghost" : ""), label); b.type = "button"; b.disabled = st.busy; b.dataset.pb = act; return b; }
  function draw() {
    box.textContent = "";
    box.append(el("h2", "", "Notifications on this device: " + (!ok ? "not available" : st.on ? "on" : "off")));
    box.append(el("p", "muted", "You get a notification here when something needs you — a meeting call, a task, a step waiting for you. Every notice is also kept below."));
    if (!ok) box.append(el("p", "muted", ios && !standalone ? "On iPhone / iPad: Share → Add to Home Screen, open the site from the new icon, sign in, then turn notifications on here." : "This browser cannot show notifications from websites."));
    else { var r = el("div", "row"); if (st.on) r.append(btn("Send a test", "test"), btn("Turn off", "off", true)); else r.append(btn("Turn on notifications", "on")); box.append(r); }
    if (st.blocked && !st.on) box.append(el("p", "muted", "Notifications are blocked for this site. Allow them in the site settings, then tap Turn on."));
    if (st.msg) { var m = el("p", st.bad ? "flash err" : "flash ok", st.msg); m.setAttribute("role", "status"); box.append(m); }
  }
  box.addEventListener("click", function (e) { var t = e.target.closest("[data-pb]"); if (!t) return; ({ on: on, off: off, test: test })[t.dataset.pb](); });
  if (!ok) { draw(); return; }
  st.blocked = Notification.permission === "denied";
  current().then(function (s) {
    st.on = !!s && Notification.permission === "granted"; draw();
    if (s && !marked()) api({ op: "subscribe", sub: s.toJSON() }).then(mark).catch(function () {});
  });
})();
