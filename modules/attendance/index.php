<?php
// Attendance — My attendance (month by month), the register for people this login may see, CSV, selfies, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/attendance.php';
$u = module_require('attendance');
$db = pdo();
$set = att_settings();
$canSet = has_role($u, 'SUPERADMIN', 'HRMANAGER');
$scope = att_scope($db, $u);
$wide = $scope === null || count($scope) > 1;
$brName = array_column(branches(false), 'name', 'id');

if (isset($_GET['selfie'])) att_selfie_send($db, $u, preg_replace('/[^a-f0-9]/', '', (string)$_GET['selfie']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSet) {
    check_csrf();
    [$new, $err] = att_settings_check($_POST, array_keys(role_labels()));
    if ($err) flash(implode(' ', $err), 'err');
    else {
        $st = module_states()['attendance'] ?? ['enabled' => true, 'roles' => module_roles('attendance')];
        $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
           ->execute(['attendance', 1, implode(',', $st['roles'] ?: module_roles('attendance')), json_encode($new), (int)$u['id']]);
        audit((int)$u['id'], 'module_settings', 'attendance');
        flash('Attendance settings saved.');
    }
    redirect('index.php?view=settings');
}

$view = in_array($_GET['view'] ?? '', ['me', 'register', 'settings'], true) ? $_GET['view'] : 'me';
if ($view === 'register' && !$wide) $view = 'me';
if ($view === 'settings' && !$canSet) $view = 'me';
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : $month . '-01';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-t', strtotime($month . '-01'));
if ($to < $from || (strtotime($to) - strtotime($from)) > 92 * 86400) $to = date('Y-m-t', strtotime($from));
$one = trim((string)($_GET['code'] ?? ''));
$fb = (int)($_GET['b'] ?? 0);

/* CSV: one row per person per day */
if (isset($_GET['csv']) && $wide) {
    $rows = att_register($db, $u, $from, $to, $one ?: null, $fb);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-' . $from . '-to-' . $to . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Code', 'Name', 'Branch', 'Date', 'Status', 'In', 'Out', 'Hours', 'Away (min)', 'Late (min)', 'Early (min)', 'Corrected', 'Punches', 'Remarks'], ',', '"', '\\');
    foreach ($rows as $r) foreach ($r['days'] as $d)
        fputcsv($o, array_map('emp_csv_cell', [$r['emp']['code'], $r['emp']['name'], $brName[$r['emp']['branch_id']] ?? '', $d['day'], $d['status'],
            att_hm($d['in']), att_hm($d['out']), (string)($d['hours'] ?? ''), (string)$d['away_min'], (string)($d['late'] ?? 0), (string)($d['early'] ?? 0), !empty($d['corrected']) ? 'yes' : '',
            implode(' ', array_map(fn($p) => $p['type'] . ' ' . att_hm($p['time']) . ' ' . $p['geofence'], $d['punches'])),
            implode(' | ', array_filter(array_column($d['punches'], 'remarks')))]), ',', '"', '\\');
    audit((int)$u['id'], 'att_export', count($rows) . ' people');
    exit;
}

ui_head('Attendance', $u, 'attendance');
$tabs = ['me' => 'My attendance'] + ($wide ? ['register' => 'Register'] : []) + ($canSet ? ['settings' => 'Settings'] : []);
$pill = fn(string $s) => '<span class="pill ' . (str_starts_with($s, 'Present') ? 'on' : (in_array($s, ['Absent', 'Missed punch'], true) ? 'off' : (in_array($s, ['Half day', 'Short'], true) ? 'port' : ''))) . '">' . h($s) . '</span>';
$lateTag = fn(array $d) => (!empty($d['corrected']) ? ' <span class="pill planned">corrected</span>' : '') . (!empty($d['late']) ? ' <span class="pill port">late ' . (int)$d['late'] . 'm</span>' : '') . (!empty($d['early']) ? ' <span class="pill port">early ' . (int)$d['early'] . 'm</span>' : '');
?>
<nav class="row"><?php foreach ($tabs as $k => $l): ?><a class="btn sm<?= $view === $k ? '' : ' ghost' ?>" href="?view=<?= $k ?>&m=<?= h($month) ?>"><?= h($l) ?></a><?php endforeach; ?>
  <a class="btn sm ghost" href="requests.php">Requests<?= ($w = atr_waiting($db, $u)) && ($n = count($w['mine_mgr']) + count($w['hr'])) ? ' · ' . $n : '' ?></a>
  <a class="btn sm" href="punch.php">Punch</a></nav>

<?php if ($view === 'me'):
    $emp = !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null;
    if (!$emp): ?><section class="card"><p>Your login is not linked to an employee record yet, so there is no register for you.</p></section><?php ui_foot(); exit; endif;
    $mFrom = $month . '-01'; $mTo = min(date('Y-m-t', strtotime($mFrom)), date('Y-m-d', strtotime('+31 days')));
    $days = att_days_for($db, $emp, $mFrom, date('Y-m-t', strtotime($mFrom)));
    $openFrom = atr_open_from_db($db);
    $sum = att_summary(array_filter($days, fn($d) => $d['day'] <= date('Y-m-d')));
    $prevM = date('Y-m', strtotime($mFrom . ' -1 month')); $nextM = date('Y-m', strtotime($mFrom . ' +1 month')); ?>
<section class="card">
  <div class="row"><a class="btn sm ghost" href="?view=me&m=<?= $prevM ?>">‹</a><h2 style="margin:0"><?= h(date('F Y', strtotime($mFrom))) ?></h2><?php if ($nextM <= date('Y-m')): ?><a class="btn sm ghost" href="?view=me&m=<?= $nextM ?>">›</a><?php endif; ?></div>
  <p><?php foreach ($sum as $k => $v): if ($k === 'hours' || !$v) continue; ?><span class="pill"><?= h($k) ?>: <?= $v ?></span> <?php endforeach; ?><span class="pill">Hours: <?= $sum['hours'] ?></span></p>
  <table><thead><tr><th>Date</th><th>Status</th><th>In</th><th>Out</th><th>Hours</th><th>Punches</th></tr></thead><tbody>
  <?php foreach ($days as $d): ?><tr><td><?= h(date('D j', strtotime($d['day']))) ?></td><td><?= $d['status'] !== '' ? $pill($d['status']) : '' ?><?= $d['holiday'] ? ' <small class="muted">' . h($d['holiday']) . '</small>' : '' ?><?= $lateTag($d) ?>
    <?php if ($d['day'] >= $openFrom && $d['day'] <= date('Y-m-d') && in_array(explode(' · ', $d['status'])[0], ['Missed punch', 'Absent', 'Short', 'Half day', 'Not in yet'], true) || (!empty($d['late']) && $d['day'] >= $openFrom)): ?> <a class="muted" href="requests.php?day=<?= h($d['day']) ?>">Ask to correct</a><?php endif; ?></td>
    <td><?= h(att_hm($d['in'])) ?></td><td><?= h(att_hm($d['out'])) ?></td><td><?= $d['hours'] !== null ? h((string)$d['hours']) : '' ?></td>
    <td><?php foreach ($d['punches'] as $p): ?><a href="?selfie=<?= h($p['selfie_id']) ?>" target="_blank" title="<?= h($p['geofence'] . ($p['remarks'] ? ' · ' . $p['remarks'] : '')) ?>"><?= h($p['type'] . ' ' . att_hm($p['time'])) ?></a> <?php endforeach; ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>

<?php elseif ($view === 'register'):
    $rows = att_register($db, $u, $from, $to, $one ?: null, $fb); ?>
<section class="card">
  <form class="row"><input type="hidden" name="view" value="register">
    <label class="tick">From <input type="date" name="from" value="<?= h($from) ?>"></label><label class="tick">To <input type="date" name="to" value="<?= h($to) ?>"></label>
    <?php if ($scope === null): ?><select name="b"><option value="0">All branches</option><?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $fb === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <input name="code" value="<?= h($one) ?>" placeholder="Employee code" size="10"><button class="btn sm">Show</button>
    <a class="btn sm ghost" href="?view=register&csv=1&from=<?= h($from) ?>&to=<?= h($to) ?>&b=<?= $fb ?>&code=<?= h(urlencode($one)) ?>">Download CSV</a></form>
  <?php if ($one !== '' && count($rows) === 1): $r = $rows[0]; ?>
    <h2><?= h($r['emp']['name'] . ' · ' . $r['emp']['code']) ?></h2>
    <table><thead><tr><th>Date</th><th>Status</th><th>In</th><th>Out</th><th>Hours</th><th>Punches</th></tr></thead><tbody>
    <?php foreach ($r['days'] as $d): ?><tr><td><?= h(date('D j M', strtotime($d['day']))) ?></td><td><?= $d['status'] !== '' ? $pill($d['status']) : '' ?><?= $lateTag($d) ?></td><td><?= h(att_hm($d['in'])) ?></td><td><?= h(att_hm($d['out'])) ?></td><td><?= h((string)($d['hours'] ?? '')) ?></td>
      <td><?php foreach ($d['punches'] as $p): ?><a href="?selfie=<?= h($p['selfie_id']) ?>" target="_blank" title="<?= h($p['geofence'] . ($p['distance_m'] !== null ? ' · ' . $p['distance_m'] . ' m' : '') . ($p['remarks'] ? ' · ' . $p['remarks'] : '')) ?>"><?= h($p['type'] . ' ' . att_hm($p['time'])) ?></a> <?php endforeach; ?></td></tr><?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
  <table><thead><tr><th>Code</th><th>Name</th><th>Branch</th><th>Present</th><th>Half / short</th><th>Absent</th><th>Missed punch</th><th>Late / early</th><th>Off / holiday</th><th>Hours</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $s = att_summary(array_filter($r['days'], fn($d) => $d['day'] <= date('Y-m-d'))); ?>
    <tr><td><a href="?view=register&code=<?= h(urlencode($r['emp']['code'])) ?>&from=<?= h($from) ?>&to=<?= h($to) ?>"><?= h($r['emp']['code']) ?></a></td><td><?= h($r['emp']['name']) ?></td><td><?= h($brName[$r['emp']['branch_id']] ?? '') ?></td>
    <td><?= $s['Present'] ?></td><td><?= $s['Half day'] + $s['Short'] ?></td><td><?= $s['Absent'] ?></td><td><?= $s['Missed punch'] ?></td><td><?= $s['Late'] ?> / <?= $s['Early'] ?></td><td><?= $s['Weekly off'] + $s['Holiday'] ?></td><td><?= $s['hours'] ?></td></tr>
  <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="10"><em>Nobody in your view for these dates.</em></td></tr><?php endif; ?>
  </tbody></table><?php endif; ?>
</section>

<?php else: ?>
<form method="post" class="card"><?= csrf_field() ?><h2>Attendance settings</h2>
  <div class="grid">
    <label>GPS precision needed (metres) <input type="number" name="accuracy_m" min="5" max="<?= ATT_MAX_ACCURACY_CEILING ?>" value="<?= (int)$set['accuracy_m'] ?>"></label>
    <label>Punches a day (even) <input type="number" name="max_punches" min="2" max="12" step="2" value="<?= (int)$set['max_punches'] ?>"></label>
    <label>Hours for a full day <input type="number" name="full_day_hours" step="0.5" value="<?= h((string)$set['full_day_hours']) ?>"></label>
    <label>Hours for a half day <input type="number" name="half_day_hours" step="0.5" value="<?= h((string)$set['half_day_hours']) ?>"></label>
    <label>Flexible hours: day changes at (hour) <input type="number" name="flex_day_change" min="0" max="8" value="<?= (int)$set['flex_day_change'] ?>"></label>
    <label>Grace minutes for late / early <input type="number" name="grace_min" min="0" max="60" value="<?= (int)$set['grace_min'] ?>"></label>
  </div>
  <label class="tick"><input type="checkbox" name="remarks_outside" value="1"<?= $set['remarks_outside'] ? ' checked' : '' ?>> Ask for a remark when someone punches away from every office</label><br>
  <label class="tick"><input type="checkbox" name="managers_see_team" value="1"<?= $set['managers_see_team'] ? ' checked' : '' ?>> Managers see their own team's attendance</label>
  <p>Roles that see everyone's attendance (within their branches):<br><?php foreach (role_labels() as $rc => $rl): ?><label class="tick"><input type="checkbox" name="see_all_roles[]" value="<?= h($rc) ?>"<?= in_array($rc, $set['see_all_roles'], true) ? ' checked' : '' ?><?= $rc === 'SUPERADMIN' ? ' disabled checked' : '' ?>><?= h($rl) ?></label><?php endforeach; ?></p>
  <p class="muted">Office locations and radius come from each branch (Admin → Organisation). A branch without a shift start counts as flexible hours.</p>
  <button class="btn">Save</button></form>
<?php endif;
ui_foot();
