/* Video meetings page (modules/meet/index.php). Rules are on the server (lib/meet.php); this file draws the screen, runs the
   camera / microphone / screen sharing (WebRTC, browser to browser) and asks api.php what changed. */
(function () {
  "use strict";
  var B = window.__MEET || {}, ME = B.me, CSRF = B.csrf;
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
  function h(tag, props) {
    var el = document.createElement(tag), k, v;
    props = props || {};
    for (k in props) { v = props[k];
      if (k === "class") el.className = v; else if (k === "text") el.textContent = v;
      else if (k.slice(0, 2) === "on") el.addEventListener(k.slice(2), v);
      else if (v === true) el.setAttribute(k, ""); else if (v !== false && v != null) el.setAttribute(k, v); }
    for (var i = 2; i < arguments.length; i++) { var c = arguments[i]; if (c == null) continue;
      [].concat(c).forEach(function (x) { if (x != null) el.append(x.nodeType ? x : document.createTextNode(x)); }); }
    return el;
  }
  function svg(d) { return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' + d + "</svg>"; }
  var I = {
    mic: svg('<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0014 0M12 18v3"/>'),
    micOff: svg('<path d="M3 3l18 18M9 9v2a3 3 0 005 2.2M15 9.3V6a3 3 0 00-5.7-1.3M19 11a7 7 0 01-1.2 3.9M5 11a7 7 0 0010.6 6M12 18v3"/>'),
    cam: svg('<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3"/>'),
    camOff: svg('<path d="M3 3l18 18M16 16v1a1 1 0 01-1 1H5a2 2 0 01-2-2V8a2 2 0 012-2h1M10 6h5a1 1 0 011 1v3l5-3v10"/>'),
    share: svg('<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4M12 13V8M9.5 10.5L12 8l2.5 2.5"/>'),
    cc: svg('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 10.5a2 2 0 10 0 3M17 10.5a2 2 0 10 0 3"/>'),
    hand: svg('<path d="M8 13V5.5a1.5 1.5 0 013 0V12M11 11V4.5a1.5 1.5 0 013 0V11M14 11V6.5a1.5 1.5 0 013 0V14a7 7 0 01-7 7h-.5a6 6 0 01-5-2.7L3 15.5a1.6 1.6 0 012.6-1.8L8 16"/>'),
    react: svg('<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 007 0M9 9.5h.01M15 9.5h.01"/>'),
    chat: svg('<path d="M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12z"/>'),
    people: svg('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0113 0M16 4.5a3.5 3.5 0 010 7M18 14a6 6 0 013.5 6"/>'),
    _t: svg('<path d="M8 4h8v5a4 4 0 01-8 0zM8 6H4a3 3 0 003 4M16 6h4a3 3 0 01-3 4M12 13v4M8 21h8M9 17h6"/>'),
    doc: svg('<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>'),
    leave: svg('<path d="M10 17l-5-5 5-5M5 12h11M14 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4"/>')
  };

  /* ---------- small helpers ---------- */
  function store(k, v) { try { if (v === undefined) return JSON.parse(localStorage.getItem("wn-meet:" + k)); localStorage.setItem("wn-meet:" + k, JSON.stringify(v)); } catch (e) { return null; } }
  function toast(t, err) { var el = h("div", { class: "toast" + (err ? " err" : ""), text: t }); $("#toasts").append(el); setTimeout(function () { el.remove(); }, 4200); }
  function clock(ts) { return new Date(ts * 1000).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }); }
  function initials(n) { return String(n || "?").split(/\s+/).filter(Boolean).map(function (w) { return w[0]; }).slice(0, 2).join("").toUpperCase(); }
  function copy(text, label) {
    var done = function () { toast(label || "Copied"); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, fallback); else fallback();
    function fallback() { var t = h("textarea"); t.value = text; document.body.append(t); t.select(); try { document.execCommand("copy"); done(); } catch (e) { toast("Select the text and copy it", true); } t.remove(); }
  }
  function api(params) { return fetch("api.php?" + new URLSearchParams(params), { credentials: "same-origin", cache: "no-store" }).then(answer); }
  function post(body, keep) {
    return fetch("api.php", { method: "POST", credentials: "same-origin", keepalive: !!keep,
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF }, body: JSON.stringify(body) }).then(answer);
  }
  function answer(r) {
    if (r.status === 401) { location.href = B.base + "login.php"; return Promise.reject(new Error("Signed out")); }
    return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.error || j.message || "Something went wrong – try again"); return j; });
  }
  function oops(e) { toast(e.message || String(e), true); }
  function show(v) { ["vHome", "vPre", "vWait", "vRoom", "vEnd"].forEach(function (x) { $("#" + x).hidden = x !== v; }); document.body.classList.toggle("mt-in", v === "vRoom"); }
  function setAddr(code, hash) { try { history.replaceState(null, "", "index.php" + (code ? "?r=" + code : "") + (hash || "")); } catch (e) {} }
  var reduce = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- home ---------- */
  var people = [], picked = { s: [], r: [] };
  function loadPeople(code) { return api(code ? { op: "people", r: code } : { op: "people" }).then(function (j) { people = j.people || []; return people; }); }
  function picker(key, input, list, chips) {
    function draw() {
      var q = input.value.trim().toLowerCase();
      list.innerHTML = "";
      if (!q && document.activeElement !== input) return;
      people.filter(function (p) { return picked[key].indexOf(p.id) < 0 && (!q || (p.name + " " + p.dept + " " + p.title).toLowerCase().indexOf(q) >= 0); })
        .sort(function (a, b) { return (b.online - a.online) || a.name.localeCompare(b.name); }).slice(0, 30)
        .forEach(function (p) {
          var taken = p.state === "in";
          var li = h("li", { tabindex: taken ? null : "0", class: taken ? "taken" : "", title: p.online ? "Online now" : "" },
            h("span", { class: "dot" + (p.online ? " on" : "") }),
            h("span", null, p.name, h("small", { text: [p.title, p.dept].filter(Boolean).join(" · ") + (taken ? " · in the meeting" : p.state === "ringing" ? " · ringing" : "") })));
          if (!taken) { li.onclick = function () { picked[key].push(p.id); input.value = ""; draw(); drawChips(); input.focus(); };
            li.onkeydown = function (e) { if (e.key === "Enter") li.click(); }; }
          list.append(li);
        });
    }
    function drawChips() {
      chips.innerHTML = "";
      picked[key].forEach(function (id) {
        var p = people.filter(function (x) { return x.id === id; })[0]; if (!p) return;
        chips.append(h("span", { class: "chip" }, p.name, h("button", { type: "button", "aria-label": "Remove " + p.name, text: "×",
          onclick: function () { picked[key] = picked[key].filter(function (x) { return x !== id; }); drawChips(); } })));
      });
    }
    input.addEventListener("input", draw); input.addEventListener("focus", draw);
    input.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); var f = list.querySelector("li[tabindex]"); if (f) f.click(); } if (e.key === "Escape") { input.value = ""; list.innerHTML = ""; } });
    document.addEventListener("click", function (e) { if (!input.parentNode.contains(e.target)) list.innerHTML = ""; });
    return { draw: draw, chips: drawChips, clear: function () { picked[key] = []; drawChips(); } };
  }
  var sPick = picker("s", $("#sFind"), $("#sList"), $("#sChips"));
  function loadHome() {
    api({ op: "home" }).then(function (j) {
      var ol = $("#openList"), pl = $("#pastList"); ol.innerHTML = ""; pl.innerHTML = "";
      if (!j.open.length) ol.append(h("li", { class: "muted", text: "No meeting is open for you. Start one, or join with a code." }));
      j.open.forEach(function (m) {
        ol.append(h("li", null, h("div", null, h("b", { text: m.title }), h("small", { text: "Host " + m.host + (m.in ? " · " + m.in + " in now" : " · not started") + (m.state === "ringing" ? " · calling you" : "") })),
          h("a", { class: "btn sm primary", href: "index.php?r=" + m.code, text: "Join" })));
      });
      if (!j.past.length) pl.append(h("li", { class: "muted", text: "Meetings you attended will be listed here with their minutes." }));
      j.past.forEach(function (m) {
        pl.append(h("li", null, h("div", null, h("b", { text: m.title }), h("small", { text: new Date(m.ended * 1000).toLocaleDateString([], { day: "2-digit", month: "short" }) + " · host " + m.host })),
          m.minutes ? h("a", { class: "btn sm", href: "index.php?r=" + m.code + "#minutes", text: "Minutes" }) : h("span", { class: "muted small", text: "No minutes" })));
      });
    }).catch(oops);
  }
  $("#startForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var btn = e.target.querySelector("button[type=submit]"); btn.disabled = true;
    post({ op: "create", title: $("#sTitle").value, agenda: $("#sAgenda").value, invite: picked.s, ring: $("#sRing").checked,
           open_join: $("#sOpen").checked, auto_minutes: $("#sMinutes").checked })
      .then(function (j) { store("ringLater:" + j.code, null); openPre(j.code); }).catch(oops).finally(function () { btn.disabled = false; });
  });
  $("#joinForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var v = $("#jCode").value.trim().toLowerCase(), m = v.match(/[a-z]{3}-[a-z]{4}-[a-z]{3}/);
    if (!m) return toast("Enter the meeting code, e.g. abc-defg-hjk", true);
    openPre(m[0]);
  });

  /* ---------- pre-join ---------- */
  var code = "", local = null, micOn = true, camOn = true;
  function getMedia() {
    if (local) return Promise.resolve(local);
    var note = [];
    if (!window.isSecureContext) note.push("Camera and microphone need the https:// address.");
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { note.push("This browser cannot use the camera or microphone."); $("#preNote").textContent = note.join(" "); local = new MediaStream(); micOn = camOn = false; return Promise.resolve(local); }
    var tries = [{ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 24 } } },
                 { audio: true, video: false }, { audio: false, video: true }];
    function next(i) {
      if (i >= tries.length) return Promise.resolve(new MediaStream());
      return navigator.mediaDevices.getUserMedia(tries[i]).catch(function (err) {
        if (err && err.name === "NotAllowedError") { note.push("Camera and microphone are blocked for this site. Allow them from the address bar, then reload."); return new MediaStream(); }
        return next(i + 1);
      });
    }
    return next(0).then(function (s) {
      local = s;
      if (!s.getAudioTracks().length) { micOn = false; if (!note.length) note.push("No microphone found. You can still watch, chat and play."); }
      if (!s.getVideoTracks().length) { camOn = false; if (!note.length) note.push("No camera found. Others will see your initials."); }
      $("#preNote").textContent = note.join(" ");
      return s;
    });
  }
  function paintPre() {
    $("#pMic").innerHTML = micOn ? I.mic : I.micOff; $("#pMic").classList.toggle("off", !micOn); $("#pMic").setAttribute("aria-label", micOn ? "Turn microphone off" : "Turn microphone on");
    $("#pCam").innerHTML = camOn ? I.cam : I.camOff; $("#pCam").classList.toggle("off", !camOn); $("#pCam").setAttribute("aria-label", camOn ? "Turn camera off" : "Turn camera on");
    $("#preOff").hidden = camOn;
  }
  function openPre(c) {
    api({ op: "info", r: c }).then(function (j) {
      code = j.code; setAddr(code);
      if (j.ended || j.removed) return openMinutes(j);
      $("#preTitle").textContent = j.title; $("#preCode").textContent = j.code + " · host " + j.host;
      $("#preLang").value = store("lang") || B.lang;
      show("vPre");
      getMedia().then(function () { $("#preview").srcObject = local; local.getAudioTracks().forEach(function (t) { t.enabled = micOn; }); local.getVideoTracks().forEach(function (t) { t.enabled = camOn; }); paintPre(); });
    }).catch(function (e) { oops(e); show("vHome"); loadHome(); });
  }
  $("#pMic").onclick = function () { if (!local || !local.getAudioTracks().length) return; micOn = !micOn; local.getAudioTracks().forEach(function (t) { t.enabled = micOn; }); paintPre(); };
  $("#pCam").onclick = function () { if (!local || !local.getVideoTracks().length) return; camOn = !camOn; local.getVideoTracks().forEach(function (t) { t.enabled = camOn; }); paintPre(); };
  $("#preBack").onclick = function () { stopLocal(); setAddr(""); show("vHome"); loadHome(); };
  $("#preJoin").onclick = function () { store("lang", $("#preLang").value); join(); };
  function join() {
    $("#preJoin").disabled = true;
    post({ op: "join", r: code }).then(function (j) {
      if (j.state === "in") enterRoom();
      else { $("#waitTitle").textContent = $("#preTitle").textContent; show("vWait"); waitLoop(); }
    }).catch(oops).finally(function () { $("#preJoin").disabled = false; });
  }
  var waitT = 0;
  function waitLoop() {
    clearTimeout(waitT);
    api({ op: "poll", r: code }).then(function (j) {
      if (j.state === "in") { toast("The host let you in"); return enterRoom(j); }   // keep what this check brought (connection messages are handed over once)
      if (j.state === "waiting") { waitT = setTimeout(waitLoop, 2500); return; }
      if (j.state === "declined") { toast("The host did not let you in", true); stopLocal(); show("vHome"); loadHome(); setAddr(""); return; }
      if (j.state === "ended") return openMinutes({ ended: true, title: $("#preTitle").textContent });
      waitT = setTimeout(waitLoop, 2500);
    }).catch(function () { waitT = setTimeout(waitLoop, 4000); });
  }
  $("#waitLeave").onclick = function () { clearTimeout(waitT); post({ op: "leave", r: code }).catch(function () {}); stopLocal(); show("vHome"); setAddr(""); loadHome(); };

  /* ---------- meeting state ---------- */
  var inRoom = false, room = null, members = [], byId = {}, lastLine = 0, ver = 0, role = "member", pollT = 0, busyUntil = 0;
  var peers = {}, screen = null, hand = false, timerT = 0, skew = 0;
  function isMod() { return role === "host" || role === "cohost"; }
  function me() { return byId[ME] || { mic: micOn, cam: camOn }; }

  function enterRoom(first) {
    inRoom = true; lastLine = 0; ver = 0;
    show("vRoom"); buildControls();
    $("#grid").innerHTML = ""; $("#chatList").innerHTML = ""; $("#trList").innerHTML = "";
    addTile(ME, true); attachSelf();
    var link = location.origin + location.pathname + "?r=" + code;
    $("#waShare").href = "https://wa.me/?text=" + encodeURIComponent("Join my video meeting (sign in first): " + link);
    $("#codeInline").textContent = code; $("#rCode").textContent = code;
    sendState();
    if (SR && micOn && store("cc") !== false) startCC();
    paintCC();
    loadPeople(code).catch(function () {});
    if (first && first.state === "in") onPoll(first);
    poll();
  }
  function poll() {
    clearTimeout(pollT);
    if (!inRoom) return;
    api({ op: "poll", r: code, l: lastLine, v: ver }).then(onPoll).catch(function () {}).finally(function () {
      if (!inRoom) return;
      var fast = Date.now() < busyUntil || Object.keys(peers).some(function (k) { var s = peers[k].pc.connectionState; return s !== "connected"; });
      pollT = setTimeout(poll, document.hidden ? 2500 : fast ? 700 : 1600);
    });
  }
  function onPoll(j) {
    if (j.state !== "in") {
      if (j.state === "ended") { teardown(); return openMinutes({ ended: true, title: room ? room.title : "" }, "Meeting ended"); }
      if (j.state === "removed") { teardown(); return endScreen("You were removed", "The host removed you from the meeting.", false); }
      if (j.state === "left" || j.state === "invited") { post({ op: "join", r: code }).catch(oops); return; }   // the page slept too long: back in
      return;
    }
    if (j.now) skew = j.now * 1000 - Date.now();
    if (j.room) { room = j.room; $("#rTitle").textContent = room.title; $("#rLock").hidden = !room.locked;
      $("#setLock").checked = room.locked; $("#setOpen").checked = room.openJoin; $("#setMin").checked = room.autoMinutes; startTimer(); }
    if (j.role && j.role !== role) { var was = isMod(); role = j.role; document.body.classList.toggle("is-mod", isMod()); if (!was && isMod() && ver) toast("You are now a co-host"); }
    document.body.classList.toggle("is-mod", isMod());
    if (j.members) { ver = j.ver; members = j.members; byId = {}; members.forEach(function (m) { byId[m.id] = m; }); syncPeers(); drawPeople(); }
    else if (j.ver) ver = j.ver;
    if (j.muteReq && micOn) { setMic(false); toast("The host muted you"); }
    (j.lines || []).forEach(onLine);
    if (j.signals && j.signals.length) { busyUntil = Date.now() + 6000; j.signals.forEach(onSignal); }
  }

  /* ---------- tiles ---------- */
  function tileOf(id) { return document.getElementById("tile-" + id); }
  function addTile(id, self) {
    if (tileOf(id)) return;
    var t = h("div", { class: "tile" + (self ? " self" : ""), id: "tile-" + id },
      h("video", { autoplay: true, playsinline: true }), h("div", { class: "av" }, h("span")),
      h("div", { class: "np" }, h("span", { class: "mi" }), h("span", { class: "nm" })),
      h("div", { class: "hand", hidden: true, text: "Hand raised" }), h("div", { class: "ptag", hidden: true, text: "Presenting" }),
      self ? null : h("div", { class: "conn", text: "Connecting…" }));
    if (self) t.querySelector("video").muted = true;
    $("#grid").append(t); paintTile(id); layout();
  }
  function paintTile(id) {
    var t = tileOf(id); if (!t) return;
    var self = id === ME, m = byId[id] || (self ? { name: B.name, role: role, mic: micOn, cam: camOn, hand: hand, sharing: !!screen } : null); if (!m) return;
    if (self) m = Object.assign({}, m, { mic: micOn, cam: camOn && !!(local && local.getVideoTracks().length), hand: hand, sharing: !!screen });
    $(".nm", t).textContent = m.name + (self ? " (you)" : "") + (m.role === "host" ? " · Host" : m.role === "cohost" ? " · Co-host" : "");
    $(".mi", t).innerHTML = m.mic ? "" : I.micOff;
    $(".av span", t).textContent = initials(m.name);
    $(".av", t).hidden = !!(m.cam || m.sharing);
    $(".hand", t).hidden = !m.hand; $(".ptag", t).hidden = !m.sharing;
    t.classList.toggle("pres", !!m.sharing);
  }
  function layout() {
    var tiles = $$("#grid .tile"), n = tiles.length, g = $("#grid"), pres = tiles.some(function (t) { return t.classList.contains("pres"); });
    g.classList.toggle("spot", pres); if (pres) g.style.setProperty("--rows", Math.max(1, n - 1));
    var cols = n <= 1 ? 1 : n <= 4 ? 2 : n <= 9 ? 3 : 4; if (innerWidth < 700) cols = n <= 2 ? 1 : 2;
    g.style.setProperty("--cols", cols);
  }
  addEventListener("resize", layout);
  function attachSelf() { var v = $("#tile-" + ME + " video"); if (v) v.srcObject = screen ? new MediaStream([screen]) : local; watchTalk(ME, local); }

  var actx = null, meters = {};
  function watchTalk(id, stream) {
    if (!stream || !stream.getAudioTracks().length || meters[id]) return;
    try { actx = actx || new (window.AudioContext || window.webkitAudioContext)();
      var src = actx.createMediaStreamSource(new MediaStream(stream.getAudioTracks())), an = actx.createAnalyser(); an.fftSize = 512; src.connect(an);
      meters[id] = { an: an, buf: new Uint8Array(512) }; } catch (e) {}
  }
  setInterval(function () {
    Object.keys(meters).forEach(function (id) {
      var m = meters[id], sum = 0; m.an.getByteTimeDomainData(m.buf); for (var i = 0; i < m.buf.length; i++) sum += Math.pow(m.buf[i] - 128, 2);
      var t = tileOf(id), on = Math.sqrt(sum / m.buf.length) > 6 && (+id === ME ? micOn : (byId[id] || {}).mic !== false);
      if (t) t.classList.toggle("talking", on);
    });
  }, 250);

  /* ---------- WebRTC: browser to browser, messages through api.php ---------- */
  var ICE = B.ice || [];
  function sendSignal(to, data) { busyUntil = Date.now() + 6000; return post({ op: "signal", r: code, to: to, data: data }).catch(function () {}); }
  function videoOut() { return screen || (local && local.getVideoTracks()[0]) || null; }
  function bindTracks(pc) {
    pc.getTransceivers().forEach(function (t) {
      var kind = t.receiver.track.kind; t.direction = "sendrecv";
      t.sender.replaceTrack(kind === "audio" ? (local && local.getAudioTracks()[0]) || null : videoOut()).catch(function () {});
    });
  }
  function syncPeers() {
    var inIds = members.filter(function (m) { return m.state === "in" && m.id !== ME; }).map(function (m) { return m.id; });
    inIds.forEach(function (id) { addTile(id); if (!peers[id] && ME < id) makePeer(id, true); });   // the lower id starts the call
    Object.keys(peers).forEach(function (id) { if (inIds.indexOf(+id) < 0) dropPeer(+id); });
    $$("#grid .tile").forEach(function (t) { var id = +t.id.slice(5); if (id !== ME && inIds.indexOf(id) < 0) { t.remove(); } });
    Object.keys(byId).forEach(function (id) { paintTile(+id); }); paintTile(ME); layout();
  }
  function makePeer(id, starter) {
    var pc = new RTCPeerConnection({ iceServers: ICE }), p = { pc: pc, stream: new MediaStream(), queue: [], out: [], outT: 0, starter: starter };
    peers[id] = p;
    pc.onicecandidate = function (e) {
      if (!e.candidate) return;
      p.out.push(e.candidate.toJSON ? e.candidate.toJSON() : e.candidate);
      if (!p.outT) p.outT = setTimeout(function () { var c = p.out; p.out = []; p.outT = 0; sendSignal(id, { cands: c }); }, 250);   // a batch, not one request each
    };
    pc.ontrack = function (e) {
      p.stream.addTrack(e.track);
      var v = $("#tile-" + id + " video"); if (v && v.srcObject !== p.stream) { v.srcObject = p.stream; v.play().catch(function () {}); }
      if (e.track.kind === "audio") { delete meters[id]; watchTalk(id, p.stream); }
    };
    pc.onconnectionstatechange = function () {
      var c = $("#tile-" + id + " .conn"); if (c) c.hidden = pc.connectionState === "connected";
      if (c && pc.connectionState === "failed") c.textContent = "Can't connect – trying again";
      if (pc.connectionState === "failed" && p.starter) pc.createOffer({ iceRestart: true }).then(function (o) { return pc.setLocalDescription(o); }).then(function () { sendSignal(id, { sdp: pc.localDescription }); }).catch(function () {});
    };
    if (starter) {
      pc.addTransceiver("audio", { direction: "sendrecv" }); pc.addTransceiver("video", { direction: "sendrecv" });
      bindTracks(pc);
      pc.createOffer().then(function (o) { return pc.setLocalDescription(o); }).then(function () { sendSignal(id, { sdp: pc.localDescription }); }).catch(function (e) { console.warn("offer", e); });
    }
    return p;
  }
  function onSignal(s) {
    var id = s.from, d = s.data || {}, p = peers[id];
    var flush = function () { var q = p.queue; p.queue = []; q.forEach(function (c) { p.pc.addIceCandidate(c).catch(function () {}); }); };
    if (d.sdp && d.sdp.type === "offer") {
      if (p && p.starter) { if (ME < id) return; dropPeer(id); p = null; }   // both started: the lower id wins
      if (!p) { addTile(id); p = makePeer(id, false); }
      p.pc.setRemoteDescription(d.sdp).then(function () { bindTracks(p.pc); return p.pc.createAnswer(); })
        .then(function (a) { return p.pc.setLocalDescription(a); }).then(function () { sendSignal(id, { sdp: p.pc.localDescription }); flush(); }).catch(function (e) { console.warn("answer", e); });
    } else if (d.sdp && p) {
      p.pc.setRemoteDescription(d.sdp).then(flush).catch(function (e) { console.warn("remote", e); });
    } else if (d.cands && p) {
      d.cands.forEach(function (c) { if (p.pc.remoteDescription) p.pc.addIceCandidate(c).catch(function () {}); else p.queue.push(c); });
    }
  }
  function dropPeer(id) { var p = peers[id]; if (p) { clearTimeout(p.outT); try { p.pc.close(); } catch (e) {} delete peers[id]; } delete meters[id]; }
  function videoForAll(track) { Object.keys(peers).forEach(function (id) { peers[id].pc.getTransceivers().forEach(function (t) { if (t.receiver.track.kind === "video") t.sender.replaceTrack(track).catch(function () {}); }); }); }

  /* ---------- controls ---------- */
  function ctl(el, icon, label) { el.innerHTML = icon + '<span class="lbl"></span>'; $(".lbl", el).textContent = label; el.setAttribute("aria-label", label); }
  function buildControls() {
    ctl($("#bShare"), I.share, "Share"); ctl($("#bCC"), I.cc, "Transcript"); ctl($("#bHand"), I.hand, "Hand"); ctl($("#bReact"), I.react, "React");
    var o = { chat: [I.chat, "Chat"], people: [I.people, "People"], minutes: [I.doc, "Minutes"] };
    $$("[data-open]").forEach(function (b) { ctl(b, o[b.dataset.open][0], o[b.dataset.open][1]); });
    ctl($("#bLeave"), I.leave, "Leave");
    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) $("#bShare").hidden = true;
    var rm = $("#rMenu"); rm.innerHTML = ""; (B.reactions || []).forEach(function (e) { rm.append(h("button", { type: "button", text: e, "aria-label": "React " + e })); });
    paintCtl();
  }
  function paintCtl() {
    ctl($("#bMic"), micOn ? I.mic : I.micOff, micOn ? "Mute" : "Unmute"); $("#bMic").classList.toggle("off", !micOn);
    ctl($("#bCam"), camOn ? I.cam : I.camOff, camOn ? "Stop video" : "Start video"); $("#bCam").classList.toggle("off", !camOn);
    $("#bShare").classList.toggle("act", !!screen); $(".lbl", $("#bShare")).textContent = screen ? "Stop share" : "Share";
    $("#bHand").classList.toggle("act", hand); $(".lbl", $("#bHand")).textContent = hand ? "Lower" : "Hand";
    paintTile(ME);
  }
  function sendState() { if (!inRoom) return; post({ op: "state", r: code, mic: micOn, cam: camOn && !!(local && local.getVideoTracks().length), hand: hand, sharing: !!screen }).catch(function () {}); }
  function setMic(on) {
    if (!local || !local.getAudioTracks().length) return toast("No microphone available", true);
    micOn = on; local.getAudioTracks().forEach(function (t) { t.enabled = on; });
    if (on && ccWant) runRec(); else stopRec();
    paintCtl(); paintCC(); sendState();
  }
  $("#bMic").onclick = function () { setMic(!micOn); };
  $("#bCam").onclick = function () {
    if (!local || !local.getVideoTracks().length) return toast("No camera available", true);
    camOn = !camOn; local.getVideoTracks().forEach(function (t) { t.enabled = camOn; }); paintCtl(); sendState();
  };
  $("#bShare").onclick = function () {
    if (screen) return stopShare();
    navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 15 }, audio: false }).then(function (s) {
      screen = s.getVideoTracks()[0]; try { screen.contentHint = "detail"; } catch (e) {}
      screen.onended = stopShare; videoForAll(screen); attachSelf(); paintCtl(); layout(); sendState();
    }).catch(function (e) { if (e && e.name !== "NotAllowedError") toast("Screen sharing could not start", true); });
  };
  function stopShare() {
    if (!screen) return; screen.onended = null; screen.stop(); screen = null;
    videoForAll((local && local.getVideoTracks()[0]) || null); attachSelf(); paintCtl(); layout(); sendState();
  }
  $("#bHand").onclick = function () { hand = !hand; paintCtl(); sendState(); };
  $("#bReact").onclick = function () { $("#rMenu").hidden = !$("#rMenu").hidden; };
  $("#rMenu").onclick = function (e) { if (e.target.tagName === "BUTTON") { $("#rMenu").hidden = true; post({ op: "say", r: code, kind: "react", text: e.target.textContent }).catch(oops); } };

  /* ---------- side panel ---------- */
  var tab = "chat", unread = 0;
  function openTab(t) {
    tab = t; $("#mside").hidden = false;
    $$(".tab[data-tab]").forEach(function (b) { b.classList.toggle("on", b.dataset.tab === t); });
    $$(".pane").forEach(function (p) { p.hidden = p.dataset.pane !== t; }); $("#chatForm").hidden = t !== "chat";
    if (t === "chat") { unread = 0; $("#chatBadge").hidden = true; setTimeout(function () { $("#chatIn").focus(); }, 0); }
    if (t === "people") loadPeople(code).catch(function () {});
  }
  $$(".tab[data-tab]").forEach(function (b) { b.onclick = function () { openTab(b.dataset.tab); }; });
  $$("[data-open]").forEach(function (b) { b.onclick = function () { if (!$("#mside").hidden && tab === b.dataset.open) $("#mside").hidden = true; else openTab(b.dataset.open); }; });
  $("#sideX").onclick = function () { $("#mside").hidden = true; };

  /* ---------- lines: chat, transcript, notes, reactions ---------- */
  function onLine(l) {
    lastLine = Math.max(lastLine, l.id);
    if (l.kind === "react") {
      if (reduce) return;
      var r = h("div", { class: "react" }, l.text, h("small", { text: l.name })); r.style.left = (10 + Math.random() * 70) + "%";
      $("#reacts").append(r); setTimeout(function () { r.remove(); }, 2700); return;
    }
    if (l.kind === "cap") {
      caption({ uid: l.uid, name: l.uid === ME ? "You" : l.name, text: l.text, final: true });
      var tl = $("#trList"), sc = tl.parentNode, bottom = sc.scrollHeight - sc.scrollTop - sc.clientHeight < 60;
      tl.append(h("li", null, h("span", { class: "ts", text: clock(l.ts) }), h("b", { text: l.name + ": " }), l.text));
      if (bottom) sc.scrollTop = sc.scrollHeight; return;
    }
    var list = $("#chatList"), atB = list.scrollHeight - list.scrollTop - list.clientHeight < 40;
    if (l.kind === "sys") list.append(h("li", { class: "sys", text: l.text }));
    else {
      list.append(h("li", { class: l.uid === ME ? "mine" : "" }, h("div", null, h("span", { class: "who", text: l.name }), h("span", { class: "ts", text: clock(l.ts) })), h("div", { class: "txt", text: l.text })));
      if (l.uid !== ME && ($("#mside").hidden || tab !== "chat")) { unread++; $("#chatBadge").textContent = unread; $("#chatBadge").hidden = false; }
    }
    if (atB) list.scrollTop = list.scrollHeight;
  }
  $("#chatForm").addEventListener("submit", function (e) {
    e.preventDefault(); var t = $("#chatIn").value.trim(); if (!t) return;
    $("#chatIn").value = ""; busyUntil = Date.now() + 3000;
    post({ op: "say", r: code, kind: "chat", text: t }).then(function () { clearTimeout(pollT); poll(); }).catch(function (er) { $("#chatIn").value = t; oops(er); });
  });

  /* ---------- live transcript (each browser transcribes its own voice) ---------- */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition, rec = null, ccWant = false;
  $("#ccNote").textContent = SR ? "Each person’s browser writes down their own voice, so every line shows who said it. Your language is the one you picked before joining."
    : "This browser cannot write down your voice (use Chrome or Edge). You still see everyone else’s lines.";
  function startCC() { if (!SR) return toast("Live transcript needs Chrome or Edge", true); ccWant = true; store("cc", true); runRec(); paintCC(); }
  function stopCC() { ccWant = false; store("cc", false); stopRec(); paintCC(); }
  function stopRec() { if (rec) { var r = rec; rec = null; try { r.onend = null; r.stop(); } catch (e) {} } }
  function runRec() {
    if (!SR || !ccWant || !micOn || rec || !inRoom) return;
    var r = new SR(); rec = r;
    r.lang = store("lang") || B.lang; r.continuous = true; r.interimResults = true;
    r.onresult = function (e) {
      for (var i = e.resultIndex; i < e.results.length; i++) {
        var res = e.results[i], text = res[0].transcript.trim(); if (!text) continue;
        if (res.isFinal) post({ op: "say", r: code, kind: "cap", text: text, lang: r.lang }).catch(function () {});
        else caption({ uid: ME, name: "You", text: text, final: false });
      }
    };
    r.onerror = function (e) { if (e.error === "not-allowed" || e.error === "service-not-allowed") { ccWant = false; paintCC(); toast("Transcript is blocked: allow the microphone for this site", true); } };
    r.onend = function () { if (rec === r) { rec = null; setTimeout(runRec, 300); } };
    try { r.start(); } catch (e) { rec = null; }
  }
  function paintCC() { $("#bCC").classList.toggle("act", ccWant); $("#ccLive").hidden = !(ccWant && micOn); }
  $("#bCC").onclick = function () { if (ccWant) stopCC(); else startCC(); };
  var caps = {};
  function caption(c) {
    var e = caps[c.uid];
    if (!e) { e = caps[c.uid] = { el: h("div", { class: "cap" }, h("b"), h("span")) }; $("#caps").append(e.el); }
    $("b", e.el).textContent = c.name; $("span", e.el).textContent = c.text; e.el.classList.toggle("interim", !c.final);
    clearTimeout(e.t); e.t = setTimeout(function () { e.el.remove(); delete caps[c.uid]; }, c.final ? 5000 : 8000);
    while ($("#caps").children.length > 3) { var f = $("#caps").firstElementChild; Object.keys(caps).forEach(function (k) { if (caps[k].el === f) delete caps[k]; }); f.remove(); }
  }
  $("#dlTr").onclick = function () { save("Transcript-" + code + ".txt", $$("#trList li").map(function (li) { return li.textContent; }).join("\n") || "No transcript yet."); };
  function save(name, text) { var a = h("a", { href: URL.createObjectURL(new Blob([text], { type: "text/plain" })), download: name }); document.body.append(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500); }

  /* ---------- people ---------- */
  function confirmBtn(label, go) {
    var b = h("button", { class: "btn sm ghost", type: "button", text: label });
    b.onclick = function () { if (b.dataset.armed) return go(); b.dataset.armed = "1"; b.textContent = "Sure?"; setTimeout(function () { delete b.dataset.armed; b.textContent = label; }, 3000); };
    return b;
  }
  function act(body, okText) { return post(Object.assign({ r: code }, body)).then(function () { if (okText) toast(okText); clearTimeout(pollT); poll(); }).catch(oops); }
  function drawPeople() {
    var ins = members.filter(function (m) { return m.state === "in"; }), wait = members.filter(function (m) { return m.state === "waiting"; }),
        inv = members.filter(function (m) { return ["invited", "ringing", "declined"].indexOf(m.state) >= 0; });
    $("#peopleN").textContent = ins.length;
    var ul = $("#inList"); ul.innerHTML = "";
    ins.forEach(function (m) {
      var mine = m.id === ME, acts = h("span", { class: "acts" });
      if (isMod() && !mine) {
        if (m.mic) acts.append(h("button", { class: "btn sm ghost", type: "button", text: "Mute", onclick: function () { act({ op: "mute", user: m.id }); } }));
        if (m.hand) acts.append(h("button", { class: "btn sm ghost", type: "button", text: "Lower hand", onclick: function () { act({ op: "hand", user: m.id }); } }));
        if (m.role !== "host") acts.append(h("button", { class: "btn sm ghost", type: "button", text: m.role === "cohost" ? "Remove co-host" : "Make co-host", onclick: function () { act({ op: "role", user: m.id, role: m.role === "cohost" ? "member" : "cohost" }); } }));
        if (m.role !== "host") acts.append(confirmBtn("Remove", function () { act({ op: "remove", user: m.id }, m.name + " removed"); }));
      }
      var ic = h("span", { class: "ic" }); ic.innerHTML = (m.mic ? I.mic : I.micOff.replace('class="i"', 'class="i off"')) + (m.cam ? I.cam : I.camOff.replace('class="i"', 'class="i off"'));
      ul.append(h("li", null, h("span", { class: "pa", text: initials(m.name) }),
        h("span", { class: "pn" }, m.name + (mine ? " (you)" : ""), m.role !== "member" ? h("span", { class: "role", text: m.role === "host" ? "Host" : "Co-host" }) : null, m.hand ? h("small", { text: "Hand raised" }) : null), ic, acts));
    });
    $("#lobbyBox").hidden = !isMod() || !wait.length;
    var lb = $("#lobbyList"); lb.innerHTML = "";
    wait.forEach(function (m) { lb.append(h("li", null, h("span", { class: "pa", text: initials(m.name) }), h("span", { class: "pn", text: m.name }),
      h("span", { class: "acts" }, h("button", { class: "btn sm primary", type: "button", text: "Admit", onclick: function () { act({ op: "admit", user: m.id, yes: true }); } }),
        h("button", { class: "btn sm ghost", type: "button", text: "Deny", onclick: function () { act({ op: "admit", user: m.id, yes: false }); } })))); });
    if (isMod() && wait.length && ($("#mside").hidden || tab !== "people") && !drawPeople.told) { toast(wait[wait.length - 1].name + " is waiting to join"); drawPeople.told = true; setTimeout(function () { drawPeople.told = false; }, 8000); }
    $("#invBox").hidden = !inv.length;
    var il = $("#invList"); il.innerHTML = "";
    inv.forEach(function (m) {
      il.append(h("li", null, h("span", { class: "pa", text: initials(m.name) }), h("span", { class: "pn", text: m.name }),
        h("span", { class: "st " + m.state, text: m.state === "ringing" ? "Ringing" : m.state === "declined" ? "Declined" : "Invited" }),
        isMod() && m.state !== "ringing" ? h("span", { class: "acts" }, h("button", { class: "btn sm ghost", type: "button", text: "Ring", onclick: function () { act({ op: "invite", users: [m.id], ring: true }, "Ringing " + m.name); } })) : null));
    });
    Object.keys(byId).forEach(function (id) { paintTile(+id); });
  }
  var rPick = picker("r", $("#rFind"), $("#rList"), $("#rChips"));
  function inviteSel(ring) {
    if (!picked.r.length) return toast("Pick colleagues first", true);
    post({ op: "invite", r: code, users: picked.r, ring: ring }).then(function (j) {
      toast(ring ? "Ringing " + (j.rung.join(", ") || "nobody new") : "Invited " + (j.invited.join(", ") || "nobody new"));
      rPick.clear(); loadPeople(code); clearTimeout(pollT); poll();
    }).catch(oops);
  }
  $("#ringBtn").onclick = function () { inviteSel(true); };
  $("#invBtn").onclick = function () { inviteSel(false); };
  $("#muteAll").onclick = function () { act({ op: "mute", user: 0 }, "Asked everyone to mute"); };
  $("#setLock").onchange = function (e) { act({ op: "settings", locked: e.target.checked }); };
  $("#setOpen").onchange = function (e) { act({ op: "settings", open_join: e.target.checked }); };
  $("#setMin").onchange = function (e) { act({ op: "settings", auto_minutes: e.target.checked }); };
  $("#copyLink").onclick = $("#copyLink2").onclick = function () { copy(location.origin + location.pathname + "?r=" + code, "Meeting link copied"); };

  /* ---------- minutes ---------- */
  var minText = "";
  function drawMinutes(el, m) {
    el.innerHTML = "";
    var sec = function (title, node) { el.append(h("h5", { text: title }), node); };
    el.append(h("div", { class: "mh" }, h("b", { text: m.title }), h("div", { class: "small muted", text: new Date(m.start * 1000).toLocaleDateString([], { day: "2-digit", month: "short", year: "numeric" }) + " · " + clock(m.start) + "–" + clock(m.end) + " · " + m.minutesLong + " min · Host " + m.host }),
      h("div", { class: "small muted", text: "Automatic summary" + (m.final ? "" : " · meeting still on") })));
    sec("Summary", h("p", { text: m.summary }));
    if (m.decisions.length) sec("Decisions", h("ul", null, m.decisions.map(function (d) { return h("li", { text: d }); })));
    if (m.actions.length) sec("Action items", h("div", { class: "tw" }, h("table", null, m.actions.map(function (a) { return h("tr", null, h("td", null, h("b", { text: a.owner || "Unassigned" })), h("td", { text: a.task }), h("td", { text: a.due })); }))));
    if (m.questions.length) sec("Open questions", h("ul", null, m.questions.map(function (q) { return h("li", { text: q }); })));
    sec("Attendees", h("ul", null, m.attendees.map(function (a) { return h("li", { text: a.name + " (" + clock(a.joined) + (a.left ? "–" + clock(a.left) : "") + ")" }); })));
  }
  function minuteLinks(prefix, text) {
    minText = text;
    $("#copy" + prefix).hidden = false;
    var dl = $("#dl" + prefix); dl.hidden = false; dl.href = "api.php?op=minutes&txt=1&r=" + code;
    var wa = $("#wa" + prefix); wa.hidden = false; wa.href = "https://wa.me/?text=" + encodeURIComponent(text.slice(0, 3500));
  }
  $("#mkMin").onclick = function () {
    $("#mkMin").disabled = true; $("#minView").innerHTML = '<p class="muted">Writing the minutes…</p>';
    post({ op: "minutes", r: code }).then(function (j) { drawMinutes($("#minView"), j.minutes); minuteLinks("Min", j.text); $("#sendMin").disabled = false; }).catch(oops).finally(function () { $("#mkMin").disabled = false; });
  };
  $("#sendMin").onclick = function () { $("#sendMin").disabled = true; post({ op: "minutes", r: code, send: true }).then(function (j) { drawMinutes($("#minView"), j.minutes); minuteLinks("Min", j.text); toast("Minutes sent to " + j.sent + " " + (j.sent === 1 ? "person" : "people")); }).catch(oops).finally(function () { $("#sendMin").disabled = false; }); };
  $("#copyMin").onclick = $("#copyEnd").onclick = function () { copy(minText, "Minutes copied"); };
  function openMinutes(info, why) {
    show("vEnd"); setAddr(code, "#minutes");
    $("#endWhy").textContent = why || "Minutes of meeting"; $("#endTitle").textContent = info.title || "Meeting";
    if (info.removed) { $("#endMin").innerHTML = ""; $("#endMin").append(h("p", { class: "muted", text: "The host removed you from this meeting." })); return; }
    var tries = 0;
    (function load() {
      api({ op: "minutes", r: code }).then(function (j) {
        if (j.minutes) { $("#endTitle").textContent = j.minutes.title; drawMinutes($("#endMin"), j.minutes); minuteLinks("End", j.text); }
        else if (tries++ < 5) setTimeout(load, 2000);
        else { $("#endMin").innerHTML = ""; $("#endMin").append(h("p", { class: "muted", text: "No minutes were made for this meeting." })); }
      }).catch(function (e) { $("#endMin").innerHTML = ""; $("#endMin").append(h("p", { class: "muted", text: e.message })); });
    })();
  }
  function endScreen(why, title) { show("vEnd"); $("#endWhy").textContent = why; $("#endTitle").textContent = title; $("#endMin").innerHTML = ""; }

  /* ---------- leave / end ---------- */
  $("#bLeave").onclick = function () { $("#leaveModal").hidden = false; };
  $("#leaveNo").onclick = function () { $("#leaveModal").hidden = true; };
  $("#leaveOnly").onclick = function () { $("#leaveModal").hidden = true; var t = room ? room.title : ""; post({ op: "leave", r: code }).catch(function () {}); teardown();
    endScreen("You left", "You left " + (t ? "“" + t + "”" : "the meeting") + "."); $("#endMin").append(h("p", { class: "muted", text: "The minutes are emailed to attendees when the meeting ends (when Email is on)." })); };
  $("#endAll").onclick = function () {
    $("#leaveModal").hidden = true; $("#endAll").disabled = true;
    post({ op: "end", r: code }).then(function (j) { var t = room ? room.title : ""; teardown(); show("vEnd"); setAddr(code, "#minutes");
      $("#endWhy").textContent = "Meeting ended"; $("#endTitle").textContent = t; drawMinutes($("#endMin"), j.minutes); minuteLinks("End", j.text);
      toast(room && room.autoMinutes ? "Minutes ready (emailed when Email is on)" : "Minutes ready"); }).catch(oops).finally(function () { $("#endAll").disabled = false; });
  };
  function teardown() {
    inRoom = false; clearTimeout(pollT); stopRec(); ccWant = false; stopShare();
    Object.keys(peers).forEach(function (id) { dropPeer(+id); }); meters = {};
    clearInterval(timerT); stopLocal(); $("#grid").innerHTML = ""; $("#caps").innerHTML = ""; caps = {};
    document.body.classList.remove("is-mod");
  }
  function stopLocal() { if (local) local.getTracks().forEach(function (t) { t.stop(); }); local = null; micOn = true; camOn = true; }
  function startTimer() {
    clearInterval(timerT); if (!room || !room.started) return;
    var t0 = room.started * 1000;
    var f = function () { var s = Math.max(0, Math.floor((Date.now() + skew - t0) / 1000)), hh = Math.floor(s / 3600), mm = Math.floor(s % 3600 / 60), ss = s % 60;
      $("#rTimer").textContent = (hh ? hh + ":" : "") + String(mm).padStart(2, "0") + ":" + String(ss).padStart(2, "0"); };
    f(); timerT = setInterval(f, 1000);
  }
  addEventListener("pagehide", function () { if (inRoom) post({ op: "leave", r: code }, true).catch(function () {}); });
  addEventListener("beforeunload", function (e) { if (inRoom) { e.preventDefault(); e.returnValue = ""; } });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") { if (!$("#leaveModal").hidden) $("#leaveModal").hidden = true; else if (!$("#rMenu").hidden) $("#rMenu").hidden = true; else if (inRoom && !$("#mside").hidden) $("#mside").hidden = true; }
  });

  /* ---------- start ---------- */
  if (B.code) { code = B.code; if (location.hash === "#minutes") api({ op: "info", r: code }).then(function (j) { if (j.ended || j.minutes) openMinutes(j); else openPre(code); }).catch(function (e) { oops(e); show("vHome"); loadHome(); }); else openPre(code); }
  else { show("vHome"); loadHome(); }
  loadPeople().catch(function () {});
})();
