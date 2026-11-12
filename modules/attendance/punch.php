<?php
// Punch screen: live selfie + precise GPS, then Check in / Check out. Camera and location are allowed on this page only.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/attendance.php';
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self), payment=()');
$u = module_require('attendance');
$db = pdo();
$emp = !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null;
$set = att_settings();
$tz = new DateTimeZone(org('timezone') ?: 'UTC');
// Flexible = the person's shift rule says so, or their branch has no shift start. Asked for the calendar day and the
// day before, so a flexible night still lands on the day it began.
$nowD = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$flexible = !$emp || shift_for($db, $emp, $nowD)['flexible'] || shift_for($db, $emp, date('Y-m-d', strtotime($nowD . ' -1 day')))['flexible'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    header('Content-Type: application/json');
    try {
        $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $in['flexible'] = $flexible;
        $r = att_record($db, $u, $emp, $in);
        echo json_encode(['ok' => true, 'msg' => ($r['type'] === 'IN' ? 'Checked in' : 'Checked out') . ' at ' . att_hm($r['time']) . ($r['geofence'] === 'inside' ? ' · in the office' : ($r['geofence'] === 'outside' ? ' · ' . $r['distance'] . ' m from the office' : ''))]);
    } catch (Throwable $e) { http_response_code(422); echo json_encode(['ok' => false, 'msg' => $e->getMessage()]); }
    exit;
}

[$day] = att_work_day($flexible, new DateTimeImmutable('now', $tz), (int)$set['flex_day_change']);
$today = att_today($db, (int)$u['id'], $day);
$next = att_next($today, (int)$set['max_punches']);
ui_head('Punch', $u, 'attendance');
?>
<style>
.punch{max-width:520px;margin:0 auto}.cam{position:relative;background:#000;border-radius:12px;overflow:hidden;aspect-ratio:3/4}
.cam video,.cam img{width:100%;height:100%;object-fit:cover;transform:scaleX(-1)}.gps{display:flex;gap:8px;align-items:center;margin:10px 0}
.dot{width:10px;height:10px;border-radius:50%;background:var(--bad)}.dot.ok{background:var(--ok)}.big{font-size:18px;padding:14px;width:100%}
</style>
<div class="punch">
<section class="card">
  <h2><?= h(date('l, j F', strtotime($day))) ?></h2>
  <p><?php foreach ($today as $p): ?><span class="pill <?= $p['type'] === 'IN' ? 'on' : '' ?>"><?= h($p['type'] . ' ' . att_hm($p['time'])) ?></span> <?php endforeach; ?><?= $today ? '' : '<span class="muted">No punches yet today.</span>' ?></p>
  <?php if ($next === ''): ?><p class="note">You have used all punches for today.</p><?php else: ?>
  <div class="cam"><video id="v" autoplay playsinline muted></video><img id="shot" hidden alt=""></div>
  <div class="gps"><span class="dot" id="dot"></span><span id="gps">Finding your location…</span></div>
  <?php if ($set['remarks_outside']): ?><label id="remBox" hidden>Not at the office? Add a short remark (optional) <input id="rem" maxlength="<?= ATT_REMARK_MAX ?>"></label><?php endif; ?>
  <button class="btn big" id="go" disabled><?= $next === 'IN' ? 'Check in' : 'Check out' ?></button>
  <p id="msg"></p>
  <?php endif; ?>
  <p><a href="index.php">My attendance</a></p>
</section>
</div>
<?php if ($next !== ''): ?>
<script>
(() => {
  const NEED = <?= (int)$set['accuracy_m'] ?>, TYPE = <?= json_encode($next) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
  const OFFICES = <?= json_encode(array_values(array_map(fn($b) => ['lat' => (float)$b['lat'], 'lng' => (float)$b['lng'], 'r' => (int)($b['radius_m'] ?: 200)], array_filter(branches(), fn($b) => $b['lat'] !== null && $b['lng'] !== null)))) ?>;
  const v = document.getElementById('v'), go = document.getElementById('go'), gps = document.getElementById('gps'), dot = document.getElementById('dot'), msg = document.getElementById('msg');
  let fix = null, stream = null;
  const dist = (a, b, c, d) => { const r = x => x * Math.PI / 180, h = Math.sin(r(c - a) / 2) ** 2 + Math.cos(r(a)) * Math.cos(r(c)) * Math.sin(r(d - b) / 2) ** 2; return 12742000 * Math.asin(Math.min(1, Math.sqrt(h))); };
  const ready = () => { go.disabled = !(fix && fix.acc <= NEED && stream); };
  navigator.mediaDevices?.getUserMedia({ video: { facingMode: 'user', width: 640 }, audio: false })
    .then(s => { stream = s; v.srcObject = s; ready(); })
    .catch(() => { msg.textContent = 'Allow the camera for this site to punch.'; });
  if (!navigator.geolocation) gps.textContent = 'This browser has no location.';
  else navigator.geolocation.watchPosition(p => {
      fix = { lat: p.coords.latitude, lng: p.coords.longitude, acc: p.coords.accuracy, at: Date.now() };
      const ok = fix.acc <= NEED; dot.classList.toggle('ok', ok);
      gps.textContent = ok ? 'Location locked (±' + Math.round(fix.acc) + ' m)' : 'Getting a precise fix… ±' + Math.round(fix.acc) + ' m (needs ' + NEED + ' m)';
      const box = document.getElementById('remBox');
      if (box && OFFICES.length) box.hidden = OFFICES.some(o => dist(fix.lat, fix.lng, o.lat, o.lng) <= o.r);
      ready();
    }, e => { gps.textContent = e.code === 1 ? 'Allow location for this site to punch.' : 'No GPS signal yet — move near a window.'; },
    { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
  go.addEventListener('click', async () => {
    if (!fix || !stream) return;
    go.disabled = true; msg.textContent = 'Saving…';
    const c = document.createElement('canvas'); c.width = v.videoWidth || 480; c.height = v.videoHeight || 640;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    const photo = c.toDataURL('image/jpeg', 0.8);
    document.getElementById('shot').src = photo; document.getElementById('shot').hidden = false; v.hidden = true;
    try {
      const r = await fetch('punch.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
        body: JSON.stringify({ type: TYPE, lat: fix.lat, lng: fix.lng, acc: fix.acc, fixAge: Date.now() - fix.at, photo, remarks: document.getElementById('rem')?.value || '' }) });
      const j = await r.json(); msg.textContent = j.msg;
      if (j.ok) { stream.getTracks().forEach(t => t.stop()); setTimeout(() => location.href = 'index.php', 1500); }
      else { go.disabled = false; v.hidden = false; document.getElementById('shot').hidden = true; }
    } catch (e) { msg.textContent = 'No connection — try again.'; go.disabled = false; }
  });
})();
</script>
<?php endif;
ui_foot();
