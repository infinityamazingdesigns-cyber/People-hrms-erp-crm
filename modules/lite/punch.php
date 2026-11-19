<?php
// Lite punch: one photo and a precise location, then Check in / Check out. The punch itself goes to the full punch screen's
// handler (att_record), so every rule is the same. Camera and location are allowed on this page only.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/lite.php';
require_once WN_ROOT . '/lib/attendance.php';
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self), payment=()');
$u = module_require_self('lite');
if (!module_allowed($u, 'attendance')) { lite_head('Punch', $u); echo '<p class="e">Attendance is not open for you.</p>'; lite_foot($u); exit; }
$db = pdo(); $set = att_settings(); $tz = new DateTimeZone(org('timezone') ?: 'UTC');
$emp = lite_emp($db, $u); $nowD = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$flex = !$emp || shift_for($db, $emp, $nowD)['flexible'];
[$day] = att_work_day($flex, new DateTimeImmutable('now', $tz), (int)$set['flex_day_change']);
$next = att_next(att_today($db, (int)$u['id'], $day), (int)$set['max_punches']);
lite_head('Punch', $u); ?>
<div class="c"><?php if ($next === ''): ?><p>All punches for today are used.</p><?php else: ?>
<video id="v" autoplay playsinline muted style="width:100%;max-height:50vh;background:#000;border-radius:6px"></video>
<p id="g" class="m">Finding your location…</p><input id="r" placeholder="Remark if you are away from the office (optional)" maxlength="200">
<button class="b" id="p" disabled><?= $next === 'IN' ? 'Check in' : 'Check out' ?></button><p id="s"></p><?php endif; ?></div>
<?php if ($next !== ''): ?><script>
(function(){var N=<?= (int)$set['accuracy_m'] ?>,T=<?= json_encode($next) ?>,K=<?= json_encode(csrf_token()) ?>,f=null,v=document.getElementById('v'),g=document.getElementById('g'),p=document.getElementById('p'),s=document.getElementById('s');
navigator.mediaDevices&&navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:480},audio:false}).then(function(m){v.srcObject=m}).catch(function(){s.textContent='Allow the camera for this site.'});
navigator.geolocation&&navigator.geolocation.watchPosition(function(x){f={lat:x.coords.latitude,lng:x.coords.longitude,acc:x.coords.accuracy,at:Date.now()};var ok=f.acc<=N;g.textContent='Location '+(ok?'ready':'not precise yet')+' ('+Math.round(f.acc)+' m, needs '+N+' m)';p.disabled=!ok},function(){g.textContent='Turn on location and allow it for this site.'},{enableHighAccuracy:true,maximumAge:0,timeout:20000});
p.onclick=function(){if(!f||!v.videoWidth)return;p.disabled=true;var c=document.createElement('canvas');c.width=v.videoWidth;c.height=v.videoHeight;c.getContext('2d').drawImage(v,0,0);
fetch('<?= h(wn_base()) ?>modules/attendance/punch.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':K},body:JSON.stringify({type:T,lat:f.lat,lng:f.lng,acc:f.acc,fixAge:Date.now()-f.at,photo:c.toDataURL('image/jpeg',.6),remarks:document.getElementById('r').value})})
.then(function(r){return r.json()}).then(function(j){s.textContent=j.msg;if(j.ok)setTimeout(function(){location.href='index.php'},1500);else p.disabled=false}).catch(function(){s.textContent='Not sent — check your connection and try again.';p.disabled=false})}})();
</script><?php endif;
lite_foot($u);
