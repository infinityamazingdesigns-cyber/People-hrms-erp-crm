<?php
// Shifts & weekly offs — HR sets a person's shift (fixed / flexible / the branch's) and weekly offs from a date.
// Rules win over the branch's values from that day; earlier months keep the rule they had. Nothing is deleted.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/attendance.php';
$u = module_require('shifts');
$db = pdo();
$canEdit = shift_can_edit($u);
$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'save') {
            $codes = $code !== '' ? [$code] : array_values(array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), (array)($_POST['codes'] ?? []))));
            if (!$codes) throw new RuntimeException('Pick at least one person.');
            $n = 0;
            foreach ($codes as $c) {
                $e = emp_by_code($db, $c);
                if (!$e || !emp_in_scope($u, $e)) throw new RuntimeException('Not on your list: ' . $c);
                shift_save($db, $u, $e, $_POST); $n++;
            }
            flash($n === 1 ? 'Saved.' : "Saved for $n people.");
        } elseif ($a === 'end') { shift_end($db, $u, (int)$_POST['id'], (string)($_POST['reason'] ?? '')); flash('Rule ended.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php' . ($code !== '' ? '?code=' . urlencode($code) : ''));
}

$people = emp_on_roll($db, $u);
$rules = shift_rules($db);
$brById = array_column(branches(false), null, 'id');
$today = date('Y-m-d');
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
$only = (string)($_GET['only'] ?? '');
ui_head('Shifts & weekly offs', $u, 'shifts');

$form = function (string $title, bool $many) use ($people, $today, $code) { ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><?php if (!$many): ?><input type="hidden" name="code" value="<?= h($code) ?>"><?php endif; ?>
  <h2><?= h($title) ?></h2>
  <?php if ($many): ?><label>People <select name="codes[]" multiple size="6" required><?php foreach ($people as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <div class="grid">
    <label>From <input type="date" name="from_day" value="<?= h($today) ?>" required></label>
    <label>Hours <select name="mode"><option value="fixed">Fixed shift</option><option value="flexible">Flexible hours</option><option value="branch">The branch's shift</option></select></label>
    <label>Start <input type="time" name="start"></label><label>End <input type="time" name="end"></label>
  </div>
  <p>Weekly off: <?php foreach (SHIFT_DAYS as $d): ?><label style="display:inline"><input type="checkbox" name="weekoffs[]" value="<?= $d ?>"<?= $d === 'Sun' ? ' checked' : '' ?>> <?= $d ?></label> <?php endforeach; ?>
    <label style="display:inline"><input type="checkbox" name="wo_branch" value="1"> the branch's</label></p>
  <label>Note <input name="note" maxlength="<?= SHIFT_NOTE_MAX ?>"></label>
  <p class="muted">Applies from that day on. Earlier days keep the shift they had. A shift ending before it starts runs past midnight.</p>
  <button class="btn sm">Save</button></form>
<?php };

if ($code !== ''):
    $e = emp_by_code($db, $code);
    if (!$e || !emp_in_scope($u, $e)) { echo '<section class="card"><p>Not on your list.</p></section>'; ui_foot(); exit; }
    $now = shift_for($db, $e, $today); $hist = shift_history($db, $code); ?>
<p><a href="index.php">‹ Everyone</a></p>
<section class="card"><h2><?= h($e['name'] . ' · ' . $e['code']) ?></h2>
  <p>Today: <b><?= h(shift_label($now)) ?></b> <span class="pill <?= $now['source'] === 'rule' ? 'on' : '' ?>"><?= $now['source'] === 'rule' ? 'Own rule' : 'Branch' ?></span></p>
  <table><thead><tr><th>From</th><th>Hours</th><th>Weekly off</th><th>Note</th><th>Set by</th><th>State</th><th></th></tr></thead><tbody>
  <?php foreach ($hist as $r): ?><tr><td><?= h($r['from_day']) ?></td>
    <td><?= h($r['use_branch_shift'] ? "Branch's" : ($r['flexible'] ? 'Flexible' : $r['start_time'] . '–' . $r['end_time'])) ?></td>
    <td><?= h($r['weekoffs'] === null ? "Branch's" : ($r['weekoffs'] ?: 'none')) ?></td><td><?= h($r['note']) ?></td><td><?= h(($r['by_name'] ?? '') . ' · ' . substr((string)$r['set_at'], 0, 10)) ?></td>
    <td><?= $r['removed_at'] ? '<span class="pill off">Ended</span> <small class="muted">' . h($r['removed_reason'] . ' · ' . ($r['removed_name'] ?? '')) . '</small>' : '<span class="pill on">Live</span>' ?></td>
    <td><?php if (!$r['removed_at'] && $canEdit): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="end"><input type="hidden" name="code" value="<?= h($code) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="reason" placeholder="Reason" required><button class="btn sm ghost">End</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?><?= $hist ? '' : '<tr><td colspan="7" class="muted">No own rule — the branch\'s shift and weekly off apply.</td></tr>' ?></tbody></table></section>
<?php if ($canEdit): $form("New rule", false); endif;
else: ?>
<form class="card row"><input name="q" placeholder="Find a person" value="<?= h($q) ?>"><select name="only"><option value="">Everyone</option><option value="rule"<?= $only === 'rule' ? ' selected' : '' ?>>With an own rule</option><option value="flex"<?= $only === 'flex' ? ' selected' : '' ?>>Flexible hours</option></select><button class="btn sm">Show</button></form>
<section class="card"><table><thead><tr><th>Code</th><th>Name</th><th>Branch</th><th>Today</th><th></th></tr></thead><tbody>
<?php foreach ($people as $p):
    $eff = shift_effective($rules[strtoupper($p['code'])] ?? [], $brById[(int)$p['branch_id']] ?? shift_branch(null), $today);
    if ($q !== '' && !str_contains(mb_strtolower($p['name'] . ' ' . $p['code']), $q)) continue;
    if ($only === 'rule' && $eff['source'] !== 'rule') continue;
    if ($only === 'flex' && !$eff['flexible']) continue; ?>
  <tr><td><?= h($p['code']) ?></td><td><?= h($p['name']) ?></td><td><?= h(branch_label((string)($brById[(int)$p['branch_id']]['name'] ?? ''))) ?></td>
  <td><?= h(shift_label($eff)) ?> <?= $eff['source'] === 'rule' ? '<span class="pill on">Own rule</span>' : '' ?></td><td><a href="?code=<?= urlencode($p['code']) ?>">Open</a></td></tr>
<?php endforeach; ?></tbody></table>
<p class="muted">Branch shifts and weekly offs are set on Admin → Organisation. A person's own rule wins from its start date.</p></section>
<?php if ($canEdit): $form("Set for several people", true); endif;
endif;
ui_foot();
