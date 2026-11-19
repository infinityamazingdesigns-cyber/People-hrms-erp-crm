<?php
// Video meetings — home (start · join · my meetings), pre-join, waiting room, the meeting, minutes; settings for the
// Super Admin. Rules in lib/meet.php, requests through api.php, screen in assets/meet.js / meet.css.
// Camera, microphone and screen are allowed on this page only (header below + .htaccess).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/meet.php';
$u = module_require('meet');
$db = pdo(); $uid = (int)$u['id']; $set = meet_settings(); $isAdmin = has_role($u, 'SUPERADMIN');
if (!headers_sent()) header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=()');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
        [$new, $err] = meet_settings_check($_POST);
        if ($err) throw new RuntimeException(implode(' ', $err));
        $st = module_states()['meet'] ?? ['roles' => module_roles('meet')];
        $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
           ->execute(['meet', 1, implode(',', $st['roles'] ?: module_roles('meet')), json_encode($new), $uid]);
        audit($uid, 'module_settings', 'meet'); flash('Settings saved.');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php?view=settings');
}

if (($_GET['view'] ?? '') === 'settings' && $isAdmin) {
    ui_head('Video meetings · settings', $u, 'meet'); ?>
<form method="post" class="card"><?= csrf_field() ?><h2>Settings</h2>
<label>Most people in one meeting (browser-to-browser video works well up to about 8) <input type="number" name="max_in" min="2" max="16" value="<?= (int)$set['max_in'] ?>"></label>
<label>A ring stops after (seconds) <input type="number" name="ring_secs" min="15" max="300" value="<?= (int)$set['ring_secs'] ?>"></label>
<label>Connection helpers (STUN addresses, one per line; blank = only people on the same network connect) <textarea name="stun" rows="3"><?= h($set['stun']) ?></textarea></label>
<label>Transcript languages (one per line: code and name, e.g. en-US English) <textarea name="langs" rows="8"><?= h($set['langs']) ?></textarea></label>
<p class="muted">Nobody reads a meeting's chat, transcript or minutes unless they were in it. Minutes and invitations are emailed only when Email is on.</p>
<button class="btn">Save</button> <a class="btn ghost" href="index.php">Back</a></form>
<?php ui_foot(); exit; }

$langs = meet_langs($set);
$boot = ['csrf' => csrf_token(), 'me' => $uid, 'name' => (string)$u['name'], 'code' => meet_code_parse((string)($_GET['r'] ?? '')), 'base' => wn_base(),
         'reactions' => MEET_REACTIONS, 'ice' => array_map(fn($x) => ['urls' => $x], meet_ice_list((string)$set['stun'])), 'lang' => (string)array_key_first($langs), 'max' => (int)$set['max_in']];
$J = fn($v) => str_replace('</', '<\/', (string)json_encode($v, JSON_UNESCAPED_UNICODE));
$b = wn_base(); $ver = h(WN_VERSION . '.' . (string)@filemtime(WN_ROOT . '/assets/meet.js'));
ui_head('Video meetings', $u, 'meet'); ?>
<link rel="stylesheet" href="<?= $b ?>assets/meet.css?v=<?= $ver ?>">
<script>window.__MEET=<?= $J($boot) ?>;</script>
<div class="mt">
<?php if ($isAdmin): ?><p><a class="btn sm ghost" href="?view=settings">Settings</a></p><?php endif; ?>
<!-- home -->
<div id="vHome" hidden><div class="two">
  <section class="card"><h2>Start a meeting</h2><p class="muted">You are the host. Invite or ring colleagues; the minutes go to everyone when you end.</p>
    <form id="startForm" class="stack" autocomplete="off">
      <label>Meeting title <input id="sTitle" maxlength="120"></label>
      <label>Agenda (optional) <textarea id="sAgenda" rows="3" maxlength="2000"></textarea></label>
      <label for="sFind">Invite colleagues</label>
      <div class="picker"><input id="sFind" placeholder="Search by name or department" style="width:100%"><ul class="pick-list" id="sList"></ul><div class="chips" id="sChips"></div></div>
      <label class="check"><input type="checkbox" id="sRing" checked> Ring them when I start</label>
      <label class="check"><input type="checkbox" id="sOpen"> Anyone with the code joins directly (no waiting room)</label>
      <label class="check"><input type="checkbox" id="sMinutes" checked> Send the minutes to everyone when the meeting ends</label>
      <button class="btn" type="submit">Start meeting</button>
    </form></section>
  <section class="card"><h2>Join with a code</h2>
    <form id="joinForm" class="row"><input id="jCode" class="mono" placeholder="abc-defg-hjk" aria-label="Meeting code or link" required><button class="btn" type="submit">Join</button></form>
    <h3>Your meetings</h3><ul class="mlist" id="openList"><li class="muted">Loading…</li></ul>
    <h3>Past meetings &amp; minutes</h3><ul class="mlist" id="pastList"></ul></section>
</div></div>
<!-- pre-join -->
<div id="vPre" hidden><div class="card pre">
  <div class="preview"><video id="preview" autoplay playsinline muted></video><div class="pre-off" id="preOff" hidden>Camera is off</div>
    <div class="pre-ctrls"><button id="pMic" class="round" type="button"></button><button id="pCam" class="round" type="button"></button></div></div>
  <div><div class="eyebrow">Ready to join</div><h2 id="preTitle">Meeting</h2><div class="mono muted" id="preCode"></div>
    <label>Transcript language for your voice <select id="preLang"><?php foreach ($langs as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
    <p class="muted small" id="preNote"></p>
    <div class="row"><button class="btn" id="preJoin" type="button">Join now</button><button class="btn ghost" id="preBack" type="button">Back</button></div></div>
</div></div>
<!-- waiting -->
<div id="vWait" hidden><div class="card narrow" style="text-align:center"><div class="pulse" aria-hidden="true"></div><h2>Waiting for the host to let you in</h2><p class="muted" id="waitTitle"></p>
  <button class="btn ghost" id="waitLeave" type="button">Leave</button></div></div>
<!-- the meeting -->
<div class="mt-room" id="vRoom" hidden>
  <div class="bar"><div><b id="rTitle">Meeting</b><span class="mono" id="rCode"></span></div>
    <div><span class="live" id="ccLive" hidden>Transcribing</span><span class="mono" id="rTimer">00:00</span><span class="lockchip" id="rLock" hidden>Locked</span></div></div>
  <div class="body">
    <div class="stage"><div class="mgrid" id="grid"></div><div class="caps" id="caps" aria-live="polite"></div><div class="reacts" id="reacts" aria-hidden="true"></div></div>
    <aside class="mside" id="mside" hidden>
      <nav class="tabs" role="tablist">
        <button class="tab on" data-tab="chat" type="button">Chat <span class="badge" id="chatBadge" hidden></span></button>
        <button class="tab" data-tab="people" type="button">People <span class="count" id="peopleN">1</span></button>
        <button class="tab" data-tab="transcript" type="button">Transcript</button>
        <button class="tab" data-tab="minutes" type="button">Minutes</button>
        <button class="tab x" id="sideX" type="button" aria-label="Close panel">×</button></nav>
      <section class="pane" data-pane="chat"><ul class="chat" id="chatList"></ul></section>
      <form class="chatf" id="chatForm" data-pane-foot="chat"><input id="chatIn" maxlength="2000" placeholder="Message everyone" aria-label="Message"><button class="btn sm" type="submit">Send</button></form>
      <section class="pane" data-pane="people" hidden>
        <div id="lobbyBox" hidden><h4>Waiting to join</h4><ul class="people" id="lobbyList"></ul></div>
        <h4>In the meeting</h4><ul class="people" id="inList"></ul>
        <div id="invBox" hidden><h4>Invited</h4><ul class="people" id="invList"></ul></div>
        <div class="mod"><h4>Invite or ring</h4>
          <div class="picker"><input id="rFind" placeholder="Search colleagues" style="width:100%"><ul class="pick-list" id="rList"></ul><div class="chips" id="rChips"></div></div>
          <div class="row" style="margin-top:6px"><button class="btn sm" id="ringBtn" type="button">Ring</button><button class="btn sm ghost" id="invBtn" type="button">Invite</button><button class="btn sm ghost" id="muteAll" type="button">Mute everyone</button></div>
          <h4>Meeting</h4>
          <label class="check"><input type="checkbox" id="setLock"> Lock (nobody new)</label>
          <label class="check"><input type="checkbox" id="setOpen"> Anyone with the code joins directly</label>
          <label class="check"><input type="checkbox" id="setMin"> Send the minutes when it ends</label></div>
        <h4>Share</h4><div class="row"><span class="mono" id="codeInline"></span><button class="link" id="copyLink" type="button">Copy link</button><button class="link" id="copyLink2" type="button" hidden></button><a class="link" id="waShare" target="_blank" rel="noopener">WhatsApp</a></div>
      </section>
      <section class="pane" data-pane="transcript" hidden><div class="sh"><h4>Live transcript</h4><button class="link" id="dlTr" type="button">Download</button></div>
        <p class="muted small" id="ccNote"></p><ol class="tr" id="trList"></ol></section>
      <section class="pane" data-pane="minutes" hidden>
        <div class="mod"><p class="muted small">Minutes are made from the transcript, chat and attendance.</p>
          <div class="row"><button class="btn sm" id="mkMin" type="button">Make minutes now</button><button class="btn sm ghost" id="sendMin" type="button" disabled>Email to everyone</button></div></div>
        <p class="member muted small">The minutes are emailed to attendees when the meeting ends (when Email is on).</p>
        <div class="row"><button class="btn sm ghost" id="copyMin" type="button" hidden>Copy</button><a class="btn sm ghost" id="dlMin" hidden>Download</a><a class="btn sm ghost" id="waMin" target="_blank" rel="noopener" hidden>WhatsApp</a></div>
        <div class="minutes" id="minView"></div></section>
    </aside>
  </div>
  <footer class="ctrls">
    <div class="cg"><button class="ctl" id="bMic" type="button"></button><button class="ctl" id="bCam" type="button"></button><button class="ctl" id="bShare" type="button"></button>
      <button class="ctl" id="bCC" type="button"></button><button class="ctl" id="bHand" type="button"></button>
      <span class="rpop"><button class="ctl" id="bReact" type="button"></button><span class="rmenu" id="rMenu" hidden></span></span></div>
    <div class="cg"><button class="ctl" data-open="chat" type="button"></button><button class="ctl" data-open="people" type="button"></button><button class="ctl" data-open="minutes" type="button"></button></div>
    <div class="cg"><button class="ctl end" id="bLeave" type="button"></button></div>
  </footer>
</div>
<!-- ended / minutes -->
<div id="vEnd" hidden><div class="card" id="minutes"><div class="eyebrow" id="endWhy">Meeting ended</div><h2 id="endTitle">Thanks for joining</h2>
  <div class="row"><button class="btn sm ghost" id="copyEnd" type="button" hidden>Copy</button><a class="btn sm ghost" id="dlEnd" hidden>Download</a><a class="btn sm ghost" id="waEnd" target="_blank" rel="noopener" hidden>WhatsApp</a></div>
  <div class="minutes" id="endMin"><p class="muted">Loading the minutes…</p></div><p><a class="btn" href="index.php">Back to meetings</a></p></div></div>
<div class="modal" id="leaveModal" hidden><div class="mcard" role="dialog" aria-modal="true" aria-labelledby="leaveH"><h3 id="leaveH">Leave the meeting?</h3>
  <p class="muted small mod">Ending for everyone makes the minutes and emails them if that is on.</p>
  <div class="row"><button class="btn mod" id="endAll" type="button" style="background:var(--bad)">End for everyone</button><button class="btn" id="leaveOnly" type="button">Leave</button><button class="btn ghost" id="leaveNo" type="button">Cancel</button></div></div></div>
<div class="toasts" id="toasts" aria-live="polite"></div>
</div>
<script src="<?= $b ?>assets/meet.js?v=<?= $ver ?>" defer></script>
<?php ui_foot();
