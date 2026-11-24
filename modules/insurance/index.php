<?php
// Group insurance — policies with their copy and renewal reminder, who is covered (card, sum insured, nominee),
// the insurer's member list pasted and matched to codes for HR to check, people on the roll not covered, requests
// for the policy copy, settings. Every page is inside the module gate; HR never sets their own cover.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/insurance.php';
$u = module_require('insurance');
$db = pdo(); $S = ins_settings(); $can = ins_can($u); $uid = (int)$u['id']; $today = date('Y-m-d');
$view = (string)($_GET['view'] ?? 'policies'); $id = (int)($_GET['id'] ?? 0);

if (isset($_GET['doc'])) {
    $pid = (int)$_GET['doc'];
    if (!ins_may_open_doc($db, $u, $pid) || !($d = ins_policy_doc($db, $pid))) { http_response_code(404); exit('Not found'); }
    audit($uid, 'ins_doc_view', '#' . $pid);
    header('Content-Type: ' . $d['doc_mime']); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $d['doc_name']) . '"');
    header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'"); header('Cache-Control: no-store');
    echo $d['doc']; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $pid = (int)($_POST['id'] ?? 0); $to = '?view=policy&id=' . $pid;
    try {
        switch ($a) {
            case 'policy': $pid = ins_policy_save($db, $u, $pid, $_POST); flash('Policy saved.'); $to = '?view=policy&id=' . $pid; break;
            case 'active': ins_policy_active($db, $u, $pid, ($_POST['on'] ?? '') === '1', (string)($_POST['why'] ?? '')); flash('Saved.'); break;
            case 'doc': $f = $_FILES['doc'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pick the policy copy.');
                ins_policy_doc_save($db, $u, $pid, (string)file_get_contents($f['tmp_name']), (string)$f['name']); @unlink($f['tmp_name']); flash('Policy copy saved.'); break;
            case 'member': $in = array_intersect_key($_POST, INS_FIELDS); if (!isset($_POST['covered'])) $in['covered'] = '0';
                $ch = ins_member_set($db, $u, $pid, (string)($_POST['code'] ?? ''), $in, 'hr', (string)($_POST['note'] ?? '')); flash($ch ? 'Saved: ' . implode(', ', array_map(fn($k) => INS_FIELDS[$k], $ch)) . '.' : 'Nothing changed.'); break;
            case 'paste': $rows = ins_parse_list((string)($_POST['text'] ?? '')); if (!$rows) throw new RuntimeException('No card numbers found in the pasted text.');
                $_SESSION['ins_paste'] = [$pid, $rows]; flash(count($rows) . ' line(s) read — check the suggested codes, then save.'); $to = '?view=import&id=' . $pid; break;
            case 'import': $rows = []; foreach ((array)($_POST['code'] ?? []) as $i => $c) if (!empty($_POST['take'][$i])) $rows[] = ['code' => $c, 'card' => $_POST['card'][$i] ?? '', 'sum' => $_POST['sum'][$i] ?? '', 'ins_id' => $_POST['ins_id'][$i] ?? ''];
                $r = ins_import($db, $u, $pid, $rows, (string)($_POST['since'] ?? '')); unset($_SESSION['ins_paste']);
                flash(count($r['saved']) . ' saved, ' . count($r['same']) . ' already the same' . ($r['skipped'] ? '; left out: ' . implode('; ', array_map(fn($c, $w) => "$c ($w)", array_keys($r['skipped']), $r['skipped'])) : '') . '.', $r['skipped'] ? 'err' : 'ok'); break;
            case 'answer': ins_request_decide($db, $u, (int)$_POST['rid'], (string)($_POST['go'] ?? ''), (string)($_POST['note'] ?? '')); flash('Answered.'); $to = '?view=requests'; break;
            case 'settings':
                if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = ins_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['insurance'] ?? ['roles' => module_roles('insurance')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['insurance', 1, implode(',', $st['roles'] ?: module_roles('insurance')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'insurance'); flash('Settings saved.'); $to = '?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'policy' && !$pid) { $_SESSION['ins_form'] = $_POST; $to = '?view=policy'; } if ($a === 'answer') $to = '?view=requests'; if ($a === 'settings') $to = '?view=settings'; }
    redirect('index.php' . $to);
}

$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$m = fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2); $dt = fn($v) => $v ? date('j M Y', strtotime((string)$v)) : '';
$hid = fn(string $a, int $i = 0) => csrf_field() . '<input type="hidden" name="a" value="' . $a . '">' . ($i ? '<input type="hidden" name="id" value="' . $i . '">' : '');
$state = fn(array $p) => ['future' => 'Starts ' . $dt($p['cover_from']), 'ok' => 'In force', 'soon' => 'Renewal due', 'over' => 'Ended', '' => ''][ins_period_state($p['cover_from'], $p['cover_till'], $today, (int)$S['renew_days'])];
$waiting = ins_requests_waiting($db, $u);
$tab = fn(string $v, string $l) => ' <a class="btn sm' . ($view === $v ? '' : ' ghost') . '" href="?view=' . $v . '">' . h($l) . '</a>';
ui_head('Group insurance', $u, 'insurance'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('policies', 'Policies') . $tab('requests', 'Requests' . ($waiting ? ' · ' . count($waiting) : '')) ?><?php if ($can): ?><?= $tab('policy', 'Add a policy') ?><?php endif; ?><?php if (is_superadmin($u)): ?><?= $tab('settings', 'Settings') ?><?php endif; ?></nav>
<?php if (!$can && $view !== 'settings'): ?><div class="card">Your role reads only. The cover of each person is kept by <?= h(implode(', ', array_map(fn($c) => role_labels()[$c] ?? $c, $S['edit_roles']))) ?>.</div><?php endif; ?>

<?php if ($view === 'policies'): $ps = ins_policies($db, !empty($_GET['all'])); $ren = ins_renewals($db); ?>
<?php if ($ren): ?><div class="card"><b>Renewal due:</b> <?= h(implode(', ', array_map(fn($p) => $p['name'] . ' (' . $dt($p['cover_till']) . ')', $ren))) ?></div><?php endif; ?>
<div class="card"><table><tr><th>Policy</th><th>Kind</th><th>Insurer</th><th>Number</th><th>Period</th><th>Covered</th><th>Copy</th><th></th></tr>
<?php foreach ($ps as $p): ?><tr><td><a href="?view=policy&id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a><?= (int)$p['active'] ? '' : ' <span class="muted">(off)</span>' ?></td><td><?= h(INS_KINDS[$p['kind']] ?? $p['kind']) ?></td><td><?= h($p['insurer']) ?></td><td><?= h($p['policy_no']) ?></td><td><?= h(trim($dt($p['cover_from']) . ' – ' . $dt($p['cover_till']), ' –')) ?></td><td><?= (int)$p['n'] ?></td><td><?= $p['doc_at'] ? 'on file' : '' ?></td><td><?= h($state($p)) ?></td></tr><?php endforeach; ?>
<?php if (!$ps): ?><tr><td colspan="8" class="muted">No policies yet.</td></tr><?php endif; ?></table><p><a href="?view=policies&all=1">Show switched-off policies too</a></p></div>

<?php elseif ($view === 'policy'): $p = $id ? ins_policy_get($db, $id) : null; $x = $p ?: ($_SESSION['ins_form'] ?? []); unset($_SESSION['ins_form']); ?>
<?php if ($id && !$p): ?><div class="card">Not found.</div><?php else: ?>
<?php if ($p): ?><div class="card"><h2><?= h($p['name']) ?> · <?= h(INS_KINDS[$p['kind']] ?? '') ?> <?= (int)$p['active'] ? '' : '<span class="muted">(off)</span>' ?></h2><p><?= h($p['insurer']) ?> · <?= h($p['policy_no']) ?> · <?= h(trim($dt($p['cover_from']) . ' – ' . $dt($p['cover_till']), ' –')) ?> · <?= h($state($p)) ?><?= $p['sum_insured'] !== null ? ' · sum insured ' . $m($p['sum_insured']) : '' ?></p>
<p><?= $p['doc_at'] ? '<a href="?doc=' . (int)$p['id'] . '" target="_blank">Open the policy copy</a> (' . h($p['doc_name']) . ')' : '<span class="muted">No policy copy yet.</span>' ?></p>
<?php if ($can): ?><form method="post" enctype="multipart/form-data" class="row"><?= $hid('doc', (int)$p['id']) ?><input type="file" name="doc" accept=".pdf,image/*" required aria-label="Document"><button class="btn sm ghost"><?= $p['doc_at'] ? 'Replace the copy' : 'Upload the copy' ?></button></form>
<form method="post" class="row"><?= $hid('active', (int)$p['id']) ?><input type="hidden" name="on" value="<?= (int)$p['active'] ? '0' : '1' ?>"><?php if ((int)$p['active']): ?><input name="why" placeholder="Why switch off (e.g. renewed as a new policy)" style="flex:1"><?php endif; ?><button class="btn sm ghost"><?= (int)$p['active'] ? 'Switch off' : 'Switch on again' ?></button></form><?php endif; ?></div><?php endif; ?>
<?php if ($can): ?><form method="post" class="card"><?= $hid('policy', (int)($p['id'] ?? 0)) ?><h2><?= $p ? 'Change details' : 'Add a policy' ?></h2>
<p class="row" style="flex-wrap:wrap"><label>Kind <select name="kind"><?= $opts(INS_KINDS, (string)($x['kind'] ?? 'health')) ?></select></label> <label>Name <input name="name" required value="<?= h((string)($x['name'] ?? '')) ?>"></label> <label>Insurer <input name="insurer" value="<?= h((string)($x['insurer'] ?? '')) ?>"></label> <label>Policy number <input name="policy_no" value="<?= h((string)($x['policy_no'] ?? '')) ?>"></label></p>
<p class="row" style="flex-wrap:wrap"><label>Cover from <input type="date" name="cover_from" value="<?= h((string)($x['cover_from'] ?? '')) ?>"></label> <label>Cover till <input type="date" name="cover_till" value="<?= h((string)($x['cover_till'] ?? '')) ?>"></label> <label>Sum insured for each person <input name="sum_insured" size="10" value="<?= h((string)($x['sum_insured'] ?? '')) ?>"></label> <label>Basis <input name="basis" placeholder="e.g. employee only, floater" value="<?= h((string)($x['basis'] ?? '')) ?>"></label></p>
<p class="row" style="flex-wrap:wrap"><label>Claims / cashless helpline <input name="helpline" value="<?= h((string)($x['helpline'] ?? '')) ?>"></label> <label>Claims email <input name="claims_email" value="<?= h((string)($x['claims_email'] ?? '')) ?>"></label> <label>Cashless email <input name="cashless_email" value="<?= h((string)($x['cashless_email'] ?? '')) ?>"></label></p>
<p><label>Note <input name="note" style="width:100%" value="<?= h((string)($x['note'] ?? '')) ?>"></label></p><button class="btn">Save</button></form><?php endif; ?>
<?php if ($p): $ms = ins_members($db, $u, (int)$p['id']); $roll = emp_on_roll($db, $u); $nc = $can ? ins_not_covered($db, $u, (int)$p['id']) : []; ?>
<div class="card"><h2>Covered · <?= count(array_filter($ms, fn($r) => (int)$r['covered'])) ?></h2><table><tr><th>Person</th><th>Covered</th><th>Card</th><th>Sum insured</th><th>From</th><th>Insurer's ID</th><th>Nominee</th></tr>
<?php foreach ($ms as $r): ?><tr><td><?= h((string)$r['name']) ?> <span class="muted"><?= h($r['emp_code']) ?></span></td><td><?= (int)$r['covered'] ? 'Yes' : 'No' ?></td><td><?= h($r['card_no']) ?></td><td><?= $m($r['sum_insured']) ?></td><td><?= h($dt($r['since'])) ?></td><td><?= h($r['ins_id']) ?></td><td><?= h(trim($r['nom_name'] . ($r['nom_relation'] !== '' ? ' (' . $r['nom_relation'] . ')' : ''))) ?></td></tr><?php endforeach; ?>
<?php if (!$ms): ?><tr><td colspan="7" class="muted">Nobody yet.</td></tr><?php endif; ?></table></div>
<?php if ($can && (int)$p['active']): ?>
<form method="post" class="card"><?= $hid('member', (int)$p['id']) ?><h3>Set one person's cover</h3>
<p class="row" style="flex-wrap:wrap"><select name="code" required aria-label="Employee"><option value="">Person</option><?php foreach ($roll as $e): if (strtoupper($e['code']) === strtoupper((string)($u['emp_code'] ?? ''))) continue; ?><option value="<?= h($e['code']) ?>"><?= h($e['name'] . ' · ' . $e['code']) ?></option><?php endforeach; ?></select>
<label><input type="checkbox" name="covered" value="1" checked> covered</label> <input name="card_no" placeholder="Card number"> <input name="sum_insured" size="9" placeholder="Sum insured"> <label>From <input type="date" name="since"></label> <input name="ins_id" size="10" placeholder="Insurer's ID"></p>
<p class="row" style="flex-wrap:wrap"><input name="nom_name" placeholder="Nominee"> <select name="nom_relation" aria-label="Nominee relation"><?= $opts(array_combine(INS_RELATIONS, INS_RELATIONS), '', 'Relation') ?></select> <label>Nominee's date of birth <input type="date" name="nom_dob" max="<?= $today ?>"></label> <input name="nom_phone" placeholder="Nominee's phone"> <input name="note" placeholder="Note"></p>
<p class="muted">Blank boxes clear that detail for the person picked. Your own cover is set by another HR person.</p><button class="btn sm">Save</button></form>
<form method="post" class="card"><?= $hid('paste', (int)$p['id']) ?><h3>Paste the insurer's member list</h3><p class="muted">Copy the rows (ID, card number, name, date of birth …) from the insurer's list. Codes are suggested; you check each before saving.</p><textarea name="text" rows="6" style="width:100%" aria-label="Message"></textarea><button class="btn sm">Read the list</button></form>
<?php if ($nc): ?><div class="card"><h3>On the roll, not covered · <?= count($nc) ?></h3><p><?= h(implode(', ', array_map(fn($e) => $e['name'] . ' (' . $e['code'] . ')', array_slice($nc, 0, 80)))) ?><?= count($nc) > 80 ? ' …' : '' ?></p></div><?php endif; ?>
<?php endif; ?>
<?php $hs = ins_history($db, (int)$p['id']); if ($hs): ?><div class="card"><h3>Changes</h3><table><tr><th>When</th><th>Who</th><th>Person</th><th>What</th><th>Fields</th><th>Note</th></tr><?php foreach (array_slice($hs, 0, 40) as $l): ?><tr><td><?= h($l['at']) ?></td><td><?= h((string)$l['by_name']) ?></td><td><?= h($l['emp_code']) ?></td><td><?= h($l['what']) ?></td><td><?= h(implode(', ', array_map(fn($k) => INS_FIELDS[$k] ?? $k, array_filter(explode(', ', $l['fields']))))) ?></td><td><?= h($l['note']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php endif; ?><?php endif; ?>

<?php elseif ($view === 'import' && $can): [$pid, $rows] = $_SESSION['ins_paste'] ?? [0, []]; $master = emp_all($db); $p = ins_policy_get($db, (int)$pid); ?>
<?php if (!$rows || !$p): ?><div class="card">Nothing read yet — paste the list on the policy page.</div><?php else: ?>
<form method="post" class="card"><?= $hid('import', (int)$pid) ?><h2><?= h($p['name']) ?> · <?= count($rows) ?> line(s) from the insurer</h2>
<table><tr><th>Take</th><th>Insurer's ID</th><th>Card</th><th>Name on the list</th><th>Sum</th><th>Employee code</th><th>How</th></tr>
<?php foreach ($rows as $i => $r): [$c, $how] = ins_match($r, $master); ?><tr><td><input type="checkbox" name="take[<?= $i ?>]" value="1"<?= $how === 'code' ? ' checked' : '' ?>></td><td><?= h($r['ins_id']) ?><input type="hidden" name="ins_id[<?= $i ?>]" value="<?= h($r['ins_id']) ?>"></td><td><?= h($r['card']) ?><input type="hidden" name="card[<?= $i ?>]" value="<?= h($r['card']) ?>"></td><td><?= h($r['name']) ?></td><td><?= h($r['sum']) ?><input type="hidden" name="sum[<?= $i ?>]" value="<?= h($r['sum']) ?>"></td>
<td><input name="code[<?= $i ?>]" size="10" value="<?= h($c) ?>"><?= $c !== '' && isset($master[$c]) ? ' <span class="muted">' . h($master[$c]['name']) . '</span>' : '' ?></td><td><?= h(['code' => 'same code', 'near' => 'code nearly the same, name agrees', 'name' => 'name only — check', '' => 'no match'][$how]) ?></td></tr><?php endforeach; ?></table>
<p class="row"><label>Covered from (for people with no date yet) <input type="date" name="since" value="<?= h((string)$p['cover_from']) ?>"></label> <button class="btn">Save the ticked lines</button></p></form><?php endif; ?>

<?php elseif ($view === 'requests'): ?>
<div class="card"><h2>Policy copy requests</h2><?php if (!$waiting): ?><p class="muted">None waiting<?= $can ? '' : ' for you' ?>.</p><?php endif; ?>
<?php foreach ($waiting as $r): ?><form method="post" class="row" style="flex-wrap:wrap;border-top:1px solid #ddd;padding:8px 0"><?= $hid('answer') ?><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><span><b><?= h((string)$r['name']) ?></b> <?= h($r['emp_code']) ?> · <?= h((string)$r['policy']) ?> · <?= h($dt($r['created_at'])) ?><?= $r['note'] !== '' ? ' — ' . h($r['note']) : '' ?><?= $r['doc_at'] ? '' : ' <span class="muted">(no copy on file)</span>' ?></span>
<input name="note" placeholder="Note to the person" style="flex:1"><button class="btn sm" name="go" value="share">Share the copy</button> <button class="btn sm ghost" name="go" value="close">Close</button></form><?php endforeach; ?></div>

<?php elseif ($view === 'settings' && is_superadmin($u)): $rl = role_labels(); ?>
<form method="post" class="card"><?= $hid('settings') ?><h2>Insurance settings</h2><p><b>Keep the records</b><br><?php foreach ($rl as $c => $n): ?><label><input type="checkbox" name="edit_roles[]" value="<?= h($c) ?>"<?= in_array($c, $S['edit_roles'], true) ? ' checked' : '' ?>> <?= h($n) ?></label> <?php endforeach; ?></p>
<p><label>Remind about renewal this many days before the end <input name="renew_days" size="4" value="<?= (int)$S['renew_days'] ?>"></label></p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif; ?>
<?php ui_foot();
