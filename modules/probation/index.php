<?php
// Probation — people on probation with their check-ins, the manager's review, HR's decision, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/attendance.php';
require_once WN_ROOT . '/lib/probation.php';
$u = module_require('probation');
$db = pdo();
$isHr = pr_is_hr($u);
$set = pr_settings();
$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $to = $code !== '' ? 'index.php?code=' . urlencode($code) : 'index.php';
    try {
        $emp = $code !== '' ? emp_by_code($db, $code) : null;
        switch ($a) {
            case 'checkin': if (!$emp) throw new RuntimeException('Not found.'); pr_checkin_save($db, $u, $emp, (int)$_POST['month'], $_POST); flash('Check-in saved.'); break;
            case 'open': if (!$emp) throw new RuntimeException('Not found.'); pr_open($db, $u, $emp); flash('Review opened.'); break;
            case 'submit': pr_submit($db, $u, (int)$_POST['id'], $_POST); flash('Review sent to HR.'); break;
            case 'return': pr_return($db, $u, (int)$_POST['id'], (string)($_POST['note'] ?? '')); flash('Sent back to the manager.'); break;
            case 'decide': flash('Decided: ' . strtolower(PR_RECO[pr_decide($db, $u, (int)$_POST['id'], $_POST)]) . '.'); break;
            case 'confirmed': flash('Marked ' . pr_mark_confirmed($db, $u, (array)($_POST['codes'] ?? [])) . ' as already confirmed.'); $to = 'index.php'; break;
            case 'settings':
                if (!has_role($u, 'SUPERADMIN', 'HRMANAGER')) throw new RuntimeException('Only the Super Admin or HR Manager changes these.');
                [$new, $err] = pr_settings_check($_POST);
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['probation'] ?? ['roles' => module_roles('probation')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['probation', 1, implode(',', $st['roles'] ?: module_roles('probation')), json_encode($new), (int)$u['id']]);
                audit((int)$u['id'], 'module_settings', 'probation');
                flash('Probation settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

pr_autostart($db, $u);
$L = pr_lists($db, $u);
$view = (string)($_GET['view'] ?? '');
ui_head('Probation', $u, 'probation');
$fmt = fn(?string $d) => $d ? date('j M Y', strtotime($d)) : '';
$reviewForm = function (array $r) use ($set) { ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="submit"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="code" value="<?= h($r['emp_code']) ?>">
  <h2>Final review · round <?= (int)$r['round'] ?></h2><?= $r['hr_note'] !== '' ? '<p class="note">HR: ' . h($r['hr_note']) . '</p>' : '' ?>
  <table><tbody><?php foreach ($set['ratings'] as $i => $label): ?><tr><td><?= h($label) ?></td><td><?php foreach (PR_SCALE as $v => $l): ?><label class="tick" title="<?= h($l) ?>"><input type="radio" name="rate[<?= $i ?>]" value="<?= $v ?>" required> <?= $v ?></label> <?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table>
  <p class="muted">1 <?= h(PR_SCALE[1]) ?> · 3 <?= h(PR_SCALE[3]) ?> · 5 <?= h(PR_SCALE[5]) ?></p>
  <div class="grid"><label>Strengths <input name="strengths" maxlength="1000"></label><label>To improve <input name="improve" maxlength="1000"></label></div>
  <?php if ($set['training']): ?><p>Training needed: <?php foreach ($set['training'] as $t): ?><label class="tick"><input type="checkbox" name="training[]" value="<?= h($t) ?>"> <?= h($t) ?></label> <?php endforeach; ?></p><?php endif; ?>
  <label>Training note <input name="training_note" maxlength="1000"></label>
  <p><label class="tick"><input type="checkbox" name="salary_rev" value="1"> Recommend a salary revision</label> <input name="salary_pct" placeholder="Suggested %" size="8"> <input type="date" name="salary_from" title="From"></p>
  <p><label class="tick"><input type="checkbox" name="role_change" value="1"> Recommend a role change</label> <input name="role_note" placeholder="New designation / department / manager" maxlength="500"></p>
  <div class="grid"><label>Recommendation <select name="reco" required><option value="">—</option><?php foreach (PR_RECO as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>Comment (needed unless confirm) <input name="comment" maxlength="1500"></label></div>
  <p class="muted">A recommendation only — no pay figures are kept here.</p><button class="btn sm">Send to HR</button></form>
<?php };
$reviewShow = function (array $r) use ($fmt) {
    $rat = json_decode((string)$r['ratings'], true) ?: []; $tr = json_decode((string)$r['training'], true) ?: [];
    echo '<section class="card"><h2>Review · round ' . (int)$r['round'] . ' <span class="pill">' . h(PR_STATUS[$r['status']]) . '</span></h2>';
    if ($rat) { echo '<p>'; foreach ($rat as $k => $v) echo '<span class="pill">' . h($k) . ': ' . (int)$v . '</span> '; echo '<b>Average ' . pr_avg($r['ratings']) . '</b></p>'; }
    foreach (['strengths' => 'Strengths', 'improve' => 'To improve', 'training_note' => 'Training note', 'manager_comment' => 'Comment', 'role_note' => 'Role change'] as $k => $l) if ($r[$k] !== '') echo '<p><b>' . $l . ':</b> ' . h($r[$k]) . '</p>';
    if ($tr) echo '<p><b>Training:</b> ' . h(implode(', ', $tr)) . '</p>';
    if ($r['salary_rev']) echo '<p><b>Salary revision:</b> recommended' . ($r['salary_pct'] !== null ? ' · ' . h((string)$r['salary_pct']) . '%' : '') . ($r['salary_from'] ? ' from ' . h($fmt($r['salary_from'])) : '') . '</p>';
    if ($r['reco']) echo '<p><b>Recommendation:</b> ' . h(PR_RECO[$r['reco']]) . '</p>';
    if ($r['decision']) echo '<p><b>Decision:</b> ' . h(PR_RECO[$r['decision']]) . ($r['decision_date'] ? ' · ' . h($fmt($r['decision_date'])) : '') . ($r['hr_note'] !== '' ? ' · ' . h($r['hr_note']) : '') . '</p>';
    echo '</section>';
};
?>
<nav class="row"><a class="btn sm<?= $code === '' && $view === '' ? '' : ' ghost' ?>" href="index.php">On probation</a><?php if (has_role($u, 'SUPERADMIN', 'HRMANAGER')): ?><a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($code !== '' && ($emp = emp_by_code($db, $code)) && pr_can($db, $u, $emp)):
    $end = pr_end($emp, (int)$set['months']); $rows = pr_checkins($db, $code); $open = pr_open_of($db, $code); $dec = pr_decided($db, $code); ?>
<p><a href="index.php">‹ Everyone on probation</a></p>
<section class="card"><h2><?= h($emp['name'] . ' · ' . $emp['code']) ?></h2>
  <p>Joined <?= h($fmt($emp['doj'])) ?> · probation ends <b><?= h($fmt($end)) ?></b><?= $dec ? ' · <span class="pill on">' . h(PR_RECO[$dec['decision']]) . '</span>' : '' ?></p>
  <?php if (!$open && !$dec && $isHr): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="open"><input type="hidden" name="code" value="<?= h($code) ?>"><button class="btn sm ghost">Open the final review now</button> <small class="muted">It opens by itself <?= (int)$set['open_days'] ?> days before the end.</small></form><?php endif; ?></section>
<?php foreach ($set['checkins'] as $m): $due = pr_checkin_due($emp, $m); $row = $rows[$m] ?? null; ?>
<section class="card"><h2><?= $m ?>-month check-in <small class="muted">due <?= h($fmt($due)) ?></small></h2>
  <?php if ($row): ?><p><span class="pill <?= $row['status'] === 'on_track' ? 'on' : 'port' ?>"><?= h(PR_CHECK_STATUS[$row['status']]) ?></span> <?= h($row['note']) ?><?= $row['actions'] !== '' ? ' · <i>' . h($row['actions']) . '</i>' : '' ?> <small class="muted">— <?= h($row['by_name'] . ', ' . $fmt($row['done_at'])) ?></small></p><?php endif; ?>
  <?php if (!$dec && pr_open_by($due, (int)$set['check_early'], date('Y-m-d'))): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="checkin"><input type="hidden" name="code" value="<?= h($code) ?>"><input type="hidden" name="month" value="<?= $m ?>">
    <select name="status" required><option value="">—</option><?php foreach (PR_CHECK_STATUS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select>
    <input name="note" placeholder="How it is going" required maxlength="1500"><input name="actions" placeholder="Agreed actions" maxlength="1000"><button class="btn sm"><?= $row ? 'Record again' : 'Save' ?></button></form>
  <?php elseif (!$row): ?><p class="muted">Opens <?= h($fmt(date('Y-m-d', strtotime($due . ' -' . $set['check_early'] . ' days')))) ?>.</p><?php endif; ?></section>
<?php endforeach; ?>
<?php if ($open && !$open['manager_at'] && ((int)$open['manager_id'] === (int)$u['id'] || $isHr)) $reviewForm($open); ?>
<?php if ($open && $open['manager_at']): $reviewShow($open); if ($isHr): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="decide"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>"><input type="hidden" name="code" value="<?= h($code) ?>"><h2>HR decision</h2>
  <div class="grid"><label>Decision <select name="decision" required><?php foreach (PR_RECO as $k => $l): ?><option value="<?= $k ?>"<?= $open['reco'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>Date (confirmation / new end) <input type="date" name="date" value="<?= h($open['reco'] === 'extend' ? date('Y-m-d', strtotime($end . ' +1 month')) : $end) ?>"></label>
  <label>Note <input name="note" maxlength="1000"></label></div><p class="muted">The date goes to the employee master's confirmation date.</p><button class="btn sm">Save decision</button></form>
<?php if ($open['manager_id']): ?><form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="return"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>"><input type="hidden" name="code" value="<?= h($code) ?>"><input name="note" placeholder="What the manager should add" required><button class="btn sm ghost">Send back to the manager</button></form><?php endif; endif; endif; ?>
<?php foreach (pr_history($db, $code) as $r) if ($r['status'] === 'done') $reviewShow($r); ?>

<?php elseif ($view === 'settings' && has_role($u, 'SUPERADMIN', 'HRMANAGER')): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Probation settings</h2>
  <div class="grid"><label>Probation months <input type="number" name="months" value="<?= (int)$set['months'] ?>"></label><label>Review opens (days before end) <input type="number" name="open_days" value="<?= (int)$set['open_days'] ?>"></label>
  <label>Check-ins at months <input name="checkins" value="<?= h(implode(', ', $set['checkins'])) ?>"></label><label>Check-ins open (days early) <input type="number" name="check_early" value="<?= (int)$set['check_early'] ?>"></label>
  <label>Track people who joined from <input type="date" name="track_from" value="<?= h($set['track_from']) ?>"></label></div>
  <div class="grid"><label>Rating areas (one per line) <textarea name="ratings" rows="7"><?= h(implode("\n", $set['ratings'])) ?></textarea></label>
  <label>Training needs (one per line) <textarea name="training" rows="7"><?= h(implode("\n", $set['training'])) ?></textarea></label></div>
  <p class="muted">An employee's own confirmation date (employee master) wins over joining date + months.</p><button class="btn sm">Save</button></form>

<?php else:
    $people = pr_people($db, $u); $today = date('Y-m-d'); ?>
<?php foreach (['mine' => 'Reviews waiting for you', 'hr_fill' => 'Reviews for HR to fill in (no manager login)', 'hr' => 'Waiting for HR decision', 'with_manager' => 'With reporting managers'] as $k => $t): if (!$L[$k]) continue; ?>
<section class="card"><h2><?= h($t) ?> · <?= count($L[$k]) ?></h2><table><tbody><?php foreach ($L[$k] as $r): ?><tr><td><?= h($r['name'] . ' · ' . $r['emp_code']) ?></td><td>ends <?= h($fmt($r['due_date'])) ?></td><td><?= $r['reco'] ? h(PR_RECO[$r['reco']]) : '' ?></td><td><a href="?code=<?= urlencode($r['emp_code']) ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></section>
<?php endforeach; ?>
<section class="card"><h2>On probation · <?= count($people) ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="confirmed">
  <table><thead><tr><?= $isHr ? '<th></th>' : '' ?><th>Name</th><th>Joined</th><th>Ends</th><th>Check-ins</th><th>Review</th><th></th></tr></thead><tbody>
  <?php foreach ($people as $p): $e = $p['emp']; ?><tr><?= $isHr ? '<td><input type="checkbox" name="codes[]" value="' . h($e['code']) . '" aria-label="Pick"></td>' : '' ?><td><?= h($e['name'] . ' · ' . $e['code']) ?></td><td><?= h($fmt($e['doj'])) ?></td>
    <td><?= h($fmt($p['end'])) ?><?= $p['end'] < $today ? ' <span class="pill port">overdue</span>' : '' ?></td>
    <td><?php foreach ($p['checks'] as $m => $c): ?><span class="pill <?= $c['state'] === 'done' ? 'on' : ($c['state'] === 'due' ? 'port' : '') ?>"><?= $m ?>m<?= $c['state'] === 'done' ? ' ✓' : ($c['state'] === 'due' ? ' due' : '') ?></span> <?php endforeach; ?></td>
    <td><?= $p['review'] ? h(PR_STATUS[$p['review']['status']]) : '' ?></td><td><a href="?code=<?= urlencode($e['code']) ?>">Open</a></td></tr>
  <?php endforeach; ?><?= $people ? '' : '<tr><td colspan="7" class="muted">Nobody on probation in your reach.</td></tr>' ?></tbody></table>
  <?php if ($isHr && $people): ?><p><button class="btn sm ghost">Mark ticked as already confirmed</button> <small class="muted">For people confirmed before tracking began — sets their confirmation date on the employee master.</small></p><?php endif; ?></form></section>
<?php if ($L['done']): ?><section class="card"><details><summary><h2 style="display:inline">Decided in the last 120 days · <?= count($L['done']) ?></h2></summary><table><tbody><?php foreach ($L['done'] as $r): ?><tr><td><?= h($r['name']) ?></td><td><?= h(PR_RECO[$r['decision']] ?? '') ?></td><td><?= h($fmt($r['decision_date'])) ?></td><td><a href="?code=<?= urlencode($r['emp_code']) ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></details></section><?php endif; ?>
<?php endif;
ui_foot();
