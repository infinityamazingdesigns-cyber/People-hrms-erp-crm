<?php
// Aptitude test — HR side: results (with each paper's answer sheet), people (job family, level, invite, re-test),
// question bank with coverage, settings. The person's own page is mine.php and never shows a result.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/aptitude.php';
$u = module_require('aptitude');
$db = pdo(); $S = apt_settings(); $can = apt_can($u); $uid = (int)$u['id']; $tracks = apt_tracks($S); $levels = apt_levels($S);
if (!apt_can_view($u) && !is_superadmin($u)) redirect('mine.php');
$view = (string)($_GET['view'] ?? 'results'); $id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = '?view=' . $view;
    try {
        switch ($a) {
            case 'person': apt_person_set($db, $u, (string)$_POST['code'], array_intersect_key($_POST, ['track' => 1, 'level' => 1, 'invited' => 1, 'retest' => 1, 'retest_reason' => 1]) + (isset($_POST['inv_box']) ? ['invited' => $_POST['invited'] ?? ''] : [])); flash('Saved.'); $to = '?view=people'; break;
            case 'q': apt_q_add($db, $u, $_POST); flash('Question added.'); $to = '?view=bank'; break;
            case 'qoff': apt_q_active($db, $u, (int)$_POST['qid'], ($_POST['on'] ?? '') === '1', (string)($_POST['why'] ?? '')); flash('Saved.'); $to = '?view=bank'; break;
            case 'note': apt_note($db, $u, (int)$_POST['id'], (string)($_POST['note'] ?? '')); flash('Note saved.'); $to = '?view=paper&id=' . (int)$_POST['id']; break;
            case 'void': apt_void($db, $u, (int)$_POST['id'], (string)($_POST['why'] ?? '')); flash('Paper voided.'); $to = '?view=paper&id=' . (int)$_POST['id']; break;
            case 'settings':
                if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = apt_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['aptitude'] ?? ['roles' => module_roles('aptitude')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['aptitude', 1, implode(',', $st['roles'] ?: module_roles('aptitude')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'aptitude'); flash('Settings saved.'); $to = '?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'q') $_SESSION['apt_q'] = $_POST; }
    redirect('index.php' . $to);
}
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$tab = fn(string $v, string $l) => ' <a class="btn sm' . ($view === $v ? '' : ' ghost') . '" href="?view=' . $v . '">' . h($l) . '</a>';
ui_head('Aptitude test', $u, 'aptitude'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('results', 'Results') ?><?php if ($can): ?><?= $tab('people', 'People') . $tab('bank', 'Question bank') ?><?php endif; ?> <a class="btn sm ghost" href="mine.php">My test</a><?php if (is_superadmin($u)): ?><?= $tab('settings', 'Settings') ?><?php endif; ?></nav>

<?php if ($view === 'results'): $rs = apt_results($db, $u, !empty($_GET['void'])); ?>
<div class="card"><table><tr><th>Person</th><th>Job family · level</th><th>Taken</th><th>Score</th><th>Band</th><th>Ended</th><th>Left the screen</th></tr>
<?php foreach ($rs as $r): ?><tr><td><a href="?view=paper&id=<?= (int)$r['id'] ?>"><?= h((string)($r['name'] ?? $r['emp_code'])) ?></a> <span class="muted"><?= h($r['emp_code']) ?></span></td><td><?= h(($tracks[$r['track']] ?? $r['track']) . ' · ' . ($levels[(int)$r['level']] ?? '')) ?></td><td><?= h((string)$r['started_at']) ?></td>
<td><?= $r['status'] === 'open' ? '<span class="muted">in progress</span>' : (int)$r['score'] . ' / ' . (int)$r['total'] . ' (' . (float)$r['pct'] . '%)' ?></td><td><b><?= h($r['band']) ?></b></td><td><?= h($r['ended'] === 'time' ? 'Time ran out' : ($r['ended'] ?: '')) ?></td><td><?= (int)$r['leaves'] ?: '' ?></td></tr><?php endforeach; ?>
<?php if (!$rs): ?><tr><td colspan="7" class="muted">No papers yet.</td></tr><?php endif; ?></table><p><a href="?view=results&void=1">Voided papers</a></p></div>

<?php elseif ($view === 'paper'): $r = apt_result($db, $u, $id); if (!$r): ?><div class="card">Not found.</div><?php else: $items = json_decode((string)$r['items'], true) ?: []; $ans = json_decode((string)$r['answers'], true) ?: []; $sec = json_decode((string)$r['sections'], true) ?: []; ?>
<div class="card"><h2><?= h((string)($r['name'] ?? $r['emp_code'])) ?> · <?= h(($tracks[$r['track']] ?? $r['track']) . ' · ' . ($levels[(int)$r['level']] ?? '')) ?></h2>
<p><?= $r['status'] === 'void' ? '<b>Voided</b> — ' . h($r['void_reason']) : ($r['status'] === 'open' ? 'In progress' : '<b>' . (int)$r['score'] . ' / ' . (int)$r['total'] . ' · ' . (float)$r['pct'] . '% · ' . h($r['band']) . '</b>') ?> · started <?= h((string)$r['started_at']) ?><?= $r['submitted_at'] ? ' · ended ' . h((string)$r['submitted_at']) . ' (' . h($r['ended']) . ')' : '' ?></p>
<?php if ($sec): ?><table><tr><th>Section</th><th>Right</th></tr><?php foreach ($sec as $k => [$ok, $of]): ?><tr><td><?= h(APT_SECTIONS[$k] ?? $k) ?></td><td><?= (int)$ok ?> / <?= (int)$of ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>
<div class="card"><h3>Answer sheet</h3><?php foreach ($items as $i => $x): $mine = $ans[(string)$i] ?? null; ?><div style="border-top:1px solid #ddd;padding:4px 0"><b><?= $i + 1 ?>.</b> <?= h($x['t']) ?> <span class="muted"><?= h(APT_SECTIONS[$x['s']] ?? '') ?></span><ol type="A"><?php foreach ($x['o'] as $k => $oi): ?><li><?= h($x['opts'][$oi] ?? '') ?><?= $k === (int)$x['r'] ? ' ✓' : '' ?><?= $mine !== null && (int)$mine === $k ? ' ← answered' : '' ?></li><?php endforeach; ?></ol><?= $mine === null ? '<span class="muted">not answered</span>' : '' ?></div><?php endforeach; ?></div>
<?php if ($can && $r['status'] !== 'open'): ?><form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="note"><input type="hidden" name="id" value="<?= $id ?>"><input name="note" style="flex:1" value="<?= h($r['hr_note']) ?>" placeholder="HR note"><button class="btn sm">Save note</button></form>
<?php if ($r['status'] === 'done'): ?><form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="void"><input type="hidden" name="id" value="<?= $id ?>"><input name="why" style="flex:1" placeholder="Why void this paper"><button class="btn sm ghost">Void</button></form><?php endif; endif; ?>
<?php endif; ?>

<?php elseif ($view === 'people' && $can): $roll = emp_on_roll($db, $u); $me = strtoupper((string)($u['emp_code'] ?? '')); ?>
<div class="card"><table><tr><th>Person</th><th>Job family</th><th>Level</th><th>Invited</th><th>Re-test</th><th></th></tr>
<?php foreach ($roll as $e): if (strtoupper($e['code']) === $me) continue; $p = apt_person($db, $e['code']); ?><tr><td><?= h($e['name']) ?> <span class="muted"><?= h($e['code'] . ' · ' . $e['department']) ?></span></td>
<td colspan="5"><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="person"><input type="hidden" name="code" value="<?= h($e['code']) ?>"><input type="hidden" name="inv_box" value="1">
<select name="track"><?= $opts($tracks, (string)($p['track'] ?? ''), 'Default (' . ($tracks[$S['default_track']] ?? '') . ')') ?></select><select name="level"><?= $opts($levels, (string)($p['level'] ?? ''), 'Default (L' . (int)$S['default_level'] . ')') ?></select>
<label><input type="checkbox" name="invited" value="1"<?= (int)($p['invited'] ?? 0) ? ' checked' : '' ?>> invited</label><?php if ((int)($p['retest'] ?? 0)): ?><span class="muted">re-test allowed</span><?php else: ?> <label><input type="checkbox" name="retest" value="1"> allow a re-test</label> <input name="retest_reason" size="14" placeholder="why"><?php endif; ?> <button class="btn sm">Save</button></form></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'bank' && $can): $qs = apt_questions($db, (string)($_GET['sec'] ?? ''), !empty($_GET['off'])); $cov = apt_coverage($db); $fq = $_SESSION['apt_q'] ?? []; unset($_SESSION['apt_q']); ?>
<div class="card"><h3>What a paper can draw (active questions)</h3><table><tr><th>Job family</th><th>Level</th><?php foreach (APT_SECTIONS as $s => $l): ?><th><?= h($l) ?></th><?php endforeach; ?></tr>
<?php foreach ($cov as $t => $ls) foreach ($ls as $l => $row): ?><tr><td><?= h($tracks[$t] ?? $t) ?></td><td>L<?= $l ?></td><?php foreach ($row as $s => $n): $need = (int)($S['blueprint'][$l][$s] ?? 0); ?><td><?= $n < $need ? '<b>' . $n . ' / ' . $need . '</b>' : $n . ' / ' . $need ?></td><?php endforeach; ?></tr><?php endforeach; ?></table><p class="muted">Bold: fewer questions than the level's paper asks for in that section (the paper takes what there is, nearest levels first).</p></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="q"><h3>Add a question</h3><p class="row" style="flex-wrap:wrap"><label>Section <select name="sec"><?= $opts(APT_SECTIONS, (string)($fq['sec'] ?? '')) ?></select></label> <label>Levels <select name="lvl_min"><?= $opts([1 => 1, 2 => 2, 3 => 3, 4 => 4], (string)($fq['lvl_min'] ?? '1')) ?></select> to <select name="lvl_max"><?= $opts([1 => 1, 2 => 2, 3 => 3, 4 => 4], (string)($fq['lvl_max'] ?? '4')) ?></select></label>
<span>Job families: <label><input type="checkbox" name="tracks[]" value="*" checked> every family</label><?php foreach ($tracks as $k => $l): ?> <label><input type="checkbox" name="tracks[]" value="<?= h($k) ?>"> <?= h($l) ?></label><?php endforeach; ?></span></p>
<p><input name="q" style="width:100%" placeholder="Question" value="<?= h((string)($fq['q'] ?? '')) ?>"></p><?php for ($i = 0; $i < IND_OPTS_MAX; $i++): ?><p class="row"><label><input type="radio" name="ans" value="<?= $i ?>"<?= (string)($fq['ans'] ?? '') === (string)$i ? ' checked' : '' ?>> right</label> <input name="opt[<?= $i ?>]" style="flex:1" placeholder="Option <?= chr(65 + $i) ?>" value="<?= h((string)($fq['opt'][$i] ?? '')) ?>"></p><?php endfor; ?>
<p><label><input type="checkbox" name="keep_order" value="1"> keep the options in this order (e.g. "All of the above")</label></p><button class="btn sm">Add</button></form>
<form class="card row"><input type="hidden" name="view" value="bank"><select name="sec" aria-label="Section"><?= $opts(APT_SECTIONS, (string)($_GET['sec'] ?? ''), 'Every section') ?></select><label><input type="checkbox" name="off" value="1"<?= !empty($_GET['off']) ? ' checked' : '' ?>> taken out</label><button class="btn sm">Show</button></form>
<div class="card"><table><tr><th>Section</th><th>Levels</th><th>Families</th><th>Question</th><th></th></tr><?php foreach ($qs as $q): $o = json_decode((string)$q['opts'], true) ?: []; ?><tr><td><?= h(APT_SECTIONS[$q['sec']] ?? $q['sec']) ?></td><td><?= (int)$q['lvl_min'] ?>–<?= (int)$q['lvl_max'] ?></td><td><?= $q['tracks'] === '*' ? 'every' : h(implode(', ', array_map(fn($k) => $tracks[$k] ?? $k, explode(',', $q['tracks'])))) ?></td>
<td><?= h($q['q']) ?><br><span class="muted"><?= h(implode(' · ', array_map(fn($k, $t) => ($k === (int)$q['ans'] ? '✓ ' : '') . $t, array_keys($o), $o))) ?></span><?= $q['off_reason'] !== '' ? '<br><span class="muted">Out: ' . h($q['off_reason']) . '</span>' : '' ?></td>
<td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="qoff"><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>"><input type="hidden" name="on" value="<?= (int)$q['active'] ? '0' : '1' ?>"><?php if ((int)$q['active']): ?><input name="why" size="10" placeholder="why"><?php endif; ?><button class="btn sm ghost"><?= (int)$q['active'] ? 'Take out' : 'Bring back' ?></button></form></td></tr><?php endforeach; ?>
<?php if (!$qs): ?><tr><td colspan="5" class="muted">No questions.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'settings' && is_superadmin($u)): $rl = role_labels(); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Aptitude test settings</h2>
<?php foreach (['manage_roles' => 'Run the test (people, bank, results)', 'view_roles' => 'See results only'] as $k => $l): ?><p><b><?= h($l) ?></b><br><?php foreach ($rl as $c => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($c) ?>"<?= in_array($c, $S[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label> <?php endforeach; ?></p><?php endforeach; ?>
<p class="row" style="flex-wrap:wrap"><label>Who may take it <select name="unlock"><?= $opts(APT_UNLOCK, (string)$S['unlock']) ?></select></label> <label>Default job family <select name="default_track"><?= $opts($tracks, (string)$S['default_track']) ?></select></label> <label>Default level <select name="default_level"><?= $opts($levels, (string)$S['default_level']) ?></select></label> <label>Fewest questions a paper may have <input name="min_items" size="3" value="<?= (int)$S['min_items'] ?>"></label></p>
<p class="row" style="flex-wrap:wrap"><label>Job families ("key | name" per line)<br><textarea name="tracks" rows="5" style="width:min(360px,100%)"><?= h($S['tracks']) ?></textarea></label> <label>Level names (4 lines)<br><textarea name="levels" rows="5" style="width:min(280px,100%)"><?= h($S['levels']) ?></textarea></label></p>
<table><tr><th>Level</th><th>Minutes</th><?php foreach (APT_SECTIONS as $l): ?><th><?= h($l) ?></th><?php endforeach; ?></tr><?php for ($l = 1; $l <= 4; $l++): ?><tr><td>L<?= $l ?></td><td><input name="minutes[<?= $l ?>]" size="3" value="<?= (int)$S['minutes'][$l] ?>"></td><?php foreach (APT_SECTIONS as $s => $_): ?><td><input name="blueprint[<?= $l ?>][<?= $s ?>]" size="3" value="<?= (int)($S['blueprint'][$l][$s] ?? 0) ?>"></td><?php endforeach; ?></tr><?php endfor; ?></table>
<p><b>Bands</b> (from %)<br><?php $i = 0; foreach ($S['bands'] + ['' => ''] as $n => $f): ?><input name="band_name[<?= $i ?>]" size="10" value="<?= h((string)$n) ?>" aria-label="Band name"> <input name="band_from[<?= $i ?>]" size="3" value="<?= h((string)$f) ?>" aria-label="Band from (%)"> &nbsp; <?php $i++; endforeach; ?><span class="muted">below the lowest: Very weak</span></p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif; ?>
<?php ui_foot();
