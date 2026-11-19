<?php
// Recruitment intelligence — contacts (institutes, consultants, contractors) with file import, companies (our own,
// clients, outside firms watched), recruitment projects, vacancies with job codes, hiring trends of outside firms.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/recintel.php';
require_once WN_ROOT . '/lib/recruitment.php';
$u = module_require('recintel');
$db = pdo(); $S = ri_settings(); $can = ri_can($u); $uid = (int)$u['id']; $pay = data_allowed($u, 'pay');
$view = (string)($_GET['view'] ?? 'contacts'); $id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $pid = (int)($_POST['id'] ?? 0); $to = '?view=' . $view;
    try {
        switch ($a) {
            case 'contact': $pid = ri_contact_save($db, $u, $pid, $_POST); flash('Contact saved.'); $to = '?view=contact&id=' . $pid; break;
            case 'contact_on': ri_contact_active($db, $u, $pid, ($_POST['on'] ?? '') === '1', (string)($_POST['why'] ?? '')); flash('Saved.'); $to = '?view=contact&id=' . $pid; break;
            case 'import': case 'import_save':
                $save = $a === 'import_save';
                if (!$save) { $f = $_FILES['file'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pick the contact file.');
                    if ($f['size'] > 10 * 1048576) throw new RuntimeException('The file is over 10 MB.');
                    $rows = tbl_read($f['tmp_name'], (string)$f['name']); $sha = hash_file('sha256', $f['tmp_name']); @unlink($f['tmp_name']);
                    $_SESSION['ri_imp'] = ['rows' => $rows, 'name' => (string)$f['name'], 'sha' => $sha, 'kind' => (string)($_POST['kind'] ?? ''), 'source' => (string)($_POST['source'] ?? ''), 'by' => $uid, 'at' => time()]; }
                $p = $_SESSION['ri_imp'] ?? null; if (!$p || $p['by'] !== $uid || time() - $p['at'] > 3600) throw new RuntimeException('Check the file again.');
                $r = ri_import($db, $u, $p['rows'], $p['kind'], $p['name'], $p['sha'], $p['source'], $save);
                if ($save) { unset($_SESSION['ri_imp']); flash($r['added'] . ' added, ' . $r['filled'] . ' filled in, ' . $r['same'] . ' already there, ' . $r['skipped'] . ' left out.'); $to = '?view=contacts'; }
                else { $_SESSION['ri_plan'] = $r; $to = '?view=import'; } break;
            case 'company': $pid = ri_company_save($db, $u, $pid, $_POST); flash('Company saved.'); $to = '?view=companies'; break;
            case 'company_on': ri_company_active($db, $u, $pid, ($_POST['on'] ?? '') === '1', (string)($_POST['why'] ?? '')); flash('Saved.'); $to = '?view=companies'; break;
            case 'project': ri_project_save($db, $u, $pid, $_POST); flash('Project saved.'); $to = '?view=projects'; break;
            case 'vacancy': $pid = ri_vacancy_save($db, $u, $pid, $_POST); flash('Saved · job code ' . ri_job_code($pid) . '.'); $to = '?view=vacancy&id=' . $pid; break;
            case 'settings':
                if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = ri_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['recintel'] ?? ['roles' => module_roles('recintel')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['recintel', 1, implode(',', $st['roles'] ?: module_roles('recintel')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'recintel'); flash('Settings saved.'); $to = '?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err'); $_SESSION['ri_form'] = $_POST;
        $to = ['contact' => '?view=contact&id=' . $pid, 'contact_on' => '?view=contact&id=' . $pid, 'import' => '?view=import', 'import_save' => '?view=import', 'company' => '?view=company&id=' . $pid,
               'company_on' => '?view=companies', 'project' => '?view=project&id=' . $pid, 'vacancy' => '?view=vacancy&id=' . $pid, 'settings' => '?view=settings'][$a] ?? '?view=contacts';
    }
    redirect('index.php' . $to);
}
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$hid = fn(string $a, int $i = 0) => csrf_field() . '<input type="hidden" name="a" value="' . $a . '">' . ($i ? '<input type="hidden" name="id" value="' . $i . '">' : '');
$f = $_SESSION['ri_form'] ?? []; unset($_SESSION['ri_form']);
$val = fn(array $x, string $k) => h((string)($f[$k] ?? $x[$k] ?? ''));
$tab = fn(string $v, string $l) => ' <a class="btn sm' . ($view === $v ? '' : ' ghost') . '" href="?view=' . $v . '">' . h($l) . '</a>';
$hist = function (string $ent, int $id) use ($db) { $hs = ri_history($db, $ent, $id); if (!$hs) return; ?><div class="card"><h3>Changes</h3><table><?php foreach ($hs as $l): ?><tr><td><?= h($l['at']) ?></td><td><?= h((string)$l['by_name']) ?></td><td><?= h($l['what']) ?></td><td><?= h($l['fields']) ?></td><td><?= h($l['note']) ?></td></tr><?php endforeach; ?></table></div><?php };
ui_head('Recruitment intelligence', $u, 'recintel'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('contacts', 'Contacts') . $tab('companies', 'Companies') . $tab('projects', 'Projects') . $tab('vacancies', 'Vacancies') . $tab('trends', 'Hiring trends') ?><?php if ($can): ?><?= $tab('import', 'Import contacts') ?><?php endif; ?><?php if (is_superadmin($u)): ?><?= $tab('settings', 'Settings') ?><?php endif; ?></nav>
<?php if (!$can && $view !== 'settings'): ?><div class="card muted">Your role reads only.</div><?php endif; ?>

<?php if ($view === 'contacts'): $k = (string)($_GET['kind'] ?? ''); $q = (string)($_GET['q'] ?? ''); $off = !empty($_GET['off']); $cs = ri_contacts($db, $k, $q, $off); $n = ri_counts($db); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="contacts"><select name="kind"><?= $opts(array_combine(array_keys(RI_KINDS), array_map(fn($c) => RI_KINDS[$c] . ' · ' . $n[$c], array_keys(RI_KINDS))), $k, 'Every kind') ?></select><input name="q" value="<?= h($q) ?>" placeholder="Name, city, course, phone"><label><input type="checkbox" name="off" value="1"<?= $off ? ' checked' : '' ?>> switched off</label><button class="btn sm">Show</button><?php if ($can): ?> <a class="btn sm" href="?view=contact">Add a contact</a><?php endif; ?></form>
<div class="card"><table><tr><th>Kind</th><th>Organisation</th><th>Person</th><th>Phone</th><th>Email</th><th>City</th><th>Focus</th><th>Status</th></tr>
<?php foreach ($cs as $c): ?><tr><td><?= h(RI_KINDS[$c['kind']] ?? $c['kind']) ?></td><td><a href="?view=contact&id=<?= (int)$c['id'] ?>"><?= h($c['org'] ?: '—') ?></a></td><td><?= h($c['person']) ?></td><td><?= h($c['phone']) ?></td><td><?= h($c['email']) ?></td><td><?= h($c['city']) ?></td><td><?= h(mb_strimwidth($c['focus'], 0, 60, '…')) ?></td><td><?= h($c['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$cs): ?><tr><td colspan="8" class="muted">No contacts.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'contact'): $x = $id ? ri_contact_get($db, $id) : []; if ($id && !$x): ?><div class="card">Not found.</div><?php else: ?>
<form method="post" class="card"><?= $hid('contact', $id) ?><h2><?= $id ? h($x['org'] ?: $x['person']) . ((int)$x['active'] ? '' : ' <span class="muted">(off — ' . h($x['off_reason']) . ')</span>') : 'Add a contact' ?></h2>
<p><label>Kind <select name="kind"<?= $can ? '' : ' disabled' ?>><?= $opts(RI_KINDS, (string)($f['kind'] ?? $x['kind'] ?? 'institute')) ?></select></label></p>
<div class="grid"><?php foreach (RI_FIELD_LABELS as $k => $l): if ($k === 'remarks') continue; ?><label><?= h($l) ?> <input name="<?= $k ?>" maxlength="<?= RI_FIELDS[$k] ?>" value="<?= $val($x, $k) ?>"<?= $can ? '' : ' readonly' ?>></label><?php endforeach; ?></div>
<p><label>Remarks<br><textarea name="remarks" rows="3" style="width:100%"<?= $can ? '' : ' readonly' ?>><?= $val($x, 'remarks') ?></textarea></label></p><?= $id ? '<p class="muted">From ' . h($x['src']) . '</p>' : '' ?>
<?php if ($can): ?><button class="btn">Save</button><?php endif; ?></form>
<?php if ($id && $can): ?><form method="post" class="card row"><?= $hid('contact_on', $id) ?><input type="hidden" name="on" value="<?= (int)$x['active'] ? '0' : '1' ?>"><?php if ((int)$x['active']): ?><input name="why" placeholder="Why switch off" style="flex:1"><?php endif; ?><button class="btn sm ghost"><?= (int)$x['active'] ? 'Switch off' : 'Switch on again' ?></button></form><?php endif; ?>
<?php if ($id) $hist('contact', $id); endif; ?>

<?php elseif ($view === 'import' && $can): $plan = $_SESSION['ri_plan'] ?? null; unset($_SESSION['ri_plan']); $imp = $_SESSION['ri_imp'] ?? null; ?>
<form method="post" enctype="multipart/form-data" class="card"><?= $hid('import') ?><h2>Import contacts</h2><p class="muted">CSV or Excel (.xlsx), first sheet. Columns are found by their titles (organisation, name, phone, email, city, state, courses / expertise, terms, remarks …). Check writes nothing; a contact already on the list only gets its blank details filled. The file is not kept.</p>
<p class="row" style="flex-wrap:wrap"><select name="kind" required><?= $opts(RI_KINDS, '', 'The file holds…') ?></select><input name="source" placeholder="Where the list came from (e.g. a consultant)" style="flex:1"><input type="file" name="file" required accept=".csv,.xlsx,.txt"><button class="btn sm">Check</button></p></form>
<?php if ($plan && $imp): ?><div class="card"><h3><?= h($imp['name']) ?> · <?= h(RI_KINDS[$imp['kind']] ?? '') ?></h3><p><?= (int)$plan['added'] ?> new · <?= (int)$plan['filled'] ?> to fill in · <?= (int)$plan['same'] ?> already there · <?= (int)$plan['skipped'] ?> left out</p>
<table><tr><th>What</th><th>Organisation</th><th>Person</th><th>Phone</th><th>Email</th><th>City</th><th>Fills</th></tr><?php foreach (array_slice($plan['plan'], 0, 300) as [$w, $c, $fl]): ?><tr><td><?= h($w) ?></td><td><?= h($c['org']) ?></td><td><?= h($c['person']) ?></td><td><?= h($c['phone']) ?></td><td><?= h($c['email']) ?></td><td><?= h($c['city']) ?></td><td><?= h(implode(', ', array_map(fn($k) => RI_FIELD_LABELS[$k] ?? $k, $fl))) ?></td></tr><?php endforeach; ?></table>
<?php if ($plan['skip']): ?><p class="muted">Left out: <?= h(implode('; ', array_map(fn($r, $w) => "row $r ($w)", array_keys(array_slice($plan['skip'], 0, 30, true)), array_slice($plan['skip'], 0, 30, true)))) ?></p><?php endif; ?>
<?php if ($plan['added'] || $plan['filled']): ?><form method="post"><?= $hid('import_save') ?><button class="btn">Save</button></form><?php endif; ?></div><?php endif; ?>
<?php $ims = ri_imports($db); if ($ims): ?><div class="card"><h3>Earlier imports</h3><table><?php foreach ($ims as $i): ?><tr><td><?= h($i['at']) ?></td><td><?= h($i['file_name']) ?></td><td><?= h(RI_KINDS[$i['kind']] ?? '') ?></td><td><?= h($i['source']) ?></td><td><?= (int)$i['added'] ?> added · <?= (int)$i['filled'] ?> filled · <?= (int)$i['skipped'] ?> left out</td><td><?= h((string)$i['by_name']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<?php elseif ($view === 'companies' || $view === 'company'): $cs = ri_companies($db, (string)($_GET['type'] ?? ''), !empty($_GET['off'])); $x = $view === 'company' && $id ? (ri_company_get($db, $id) ?? []) : []; ?>
<div class="card"><table><tr><th>Company</th><th>Type</th><th>Industry</th><th>City</th><th>Watched</th><th>Live openings</th><th></th></tr>
<?php foreach ($cs as $c): ?><tr><td><a href="?view=company&id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a><?= $c['aliases'] !== '' ? ' <span class="muted">(' . h($c['aliases']) . ')</span>' : '' ?></td><td><?= h(RI_COMPANY_TYPES[$c['type']]) ?></td><td><?= h($c['industry']) ?></td><td><?= h($c['city']) ?></td><td><?= (int)$c['watch'] ? 'Yes' : '' ?></td><td><?= (int)$c['live'] ?></td>
<td><?php if ($can): ?><form method="post" class="row"><?= $hid('company_on', (int)$c['id']) ?><input type="hidden" name="on" value="<?= (int)$c['active'] ? '0' : '1' ?>"><?php if ((int)$c['active']): ?><input name="why" size="12" placeholder="Why off"><?php endif; ?><button class="btn sm ghost"><?= (int)$c['active'] ? 'Off' : 'On' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$cs): ?><tr><td colspan="7" class="muted">No companies yet.</td></tr><?php endif; ?></table><p><a href="?view=companies&off=1">Switched off</a></p></div>
<?php if ($can): ?><form method="post" class="card"><?= $hid('company', (int)($x['id'] ?? 0)) ?><h3><?= $x ? 'Change ' . h($x['name']) : 'Add a company' ?></h3><p class="row" style="flex-wrap:wrap">
<label>Name <input name="name" required value="<?= $val($x, 'name') ?>"></label> <label>Type <select name="type"><?= $opts(RI_COMPANY_TYPES, (string)($f['type'] ?? $x['type'] ?? 'outside')) ?></select></label> <label>Other names <input name="aliases" placeholder="comma separated" value="<?= $val($x, 'aliases') ?>"></label> <label>Industry <input name="industry" value="<?= $val($x, 'industry') ?>"></label> <label>City <input name="city" value="<?= $val($x, 'city') ?>"></label>
<label><input type="checkbox" name="watch" value="1"<?= !empty($f['watch'] ?? $x['watch'] ?? 0) ? ' checked' : '' ?>> watch for poaching (outside only)</label></p><button class="btn sm">Save</button></form><?php if ($x) $hist('company', (int)$x['id']); endif; ?>

<?php elseif ($view === 'projects' || $view === 'project'): $ps = ri_projects($db, !empty($_GET['closed'])); $x = $view === 'project' && $id ? (ri_project_get($db, $id) ?? []) : []; $cos = array_filter(ri_companies($db), fn($c) => $c['type'] !== 'outside'); ?>
<div class="card"><table><tr><th>Project</th><th>For</th><th>Site</th><th>Owner</th><th>Target</th><th>Status</th><th>Open vacancies</th></tr>
<?php foreach ($ps as $p): ?><tr><td><a href="?view=project&id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a></td><td><?= h($p['company']) ?></td><td><?= h($p['site']) ?></td><td><?= h((string)$p['owner']) ?></td><td><?= h((string)$p['target_on']) ?></td><td><?= h(RI_PROJECT_STATUS[$p['status']]) ?></td><td><?= (int)$p['open_vac'] ?></td></tr><?php endforeach; ?>
<?php if (!$ps): ?><tr><td colspan="7" class="muted">No projects.</td></tr><?php endif; ?></table><p><a href="?view=projects&closed=1">Closed projects</a></p></div>
<?php if ($can): $owners = $db->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name")->fetchAll(); ?><form method="post" class="card"><?= $hid('project', (int)($x['id'] ?? 0)) ?><h3><?= $x ? 'Change ' . h($x['name']) : 'Add a project' ?></h3><p class="row" style="flex-wrap:wrap">
<label>Name <input name="name" required value="<?= $val($x, 'name') ?>"></label> <label>For <select name="company_id"><?= $opts(array_column($cos, 'name', 'id'), (string)($f['company_id'] ?? $x['company_id'] ?? ''), '—') ?></select></label> <label>Site <input name="site" value="<?= $val($x, 'site') ?>"></label>
<label>Owner <select name="owner_id"><?= $opts(array_column($owners, 'name', 'id'), (string)($f['owner_id'] ?? $x['owner_id'] ?? ''), '—') ?></select></label> <label>Start <input type="date" name="start_on" value="<?= $val($x, 'start_on') ?>"></label> <label>Target <input type="date" name="target_on" value="<?= $val($x, 'target_on') ?>"></label> <label>Status <select name="status"><?= $opts(RI_PROJECT_STATUS, (string)($f['status'] ?? $x['status'] ?? 'planned')) ?></select></label></p>
<p><input name="notes" placeholder="Notes" style="width:100%" value="<?= $val($x, 'notes') ?>"></p><button class="btn sm">Save</button></form><?php if ($x) $hist('project', (int)$x['id']); endif; ?>

<?php elseif ($view === 'vacancies'): $wh = (string)($_GET['w'] ?? 'live'); $q = (string)($_GET['q'] ?? ''); $vs = ri_vacancies($db, $wh, $q); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="vacancies"><select name="w"><?= $opts(['live' => 'Open and observed', 'open' => 'Open', 'observed' => 'Observed only', 'closed' => 'Filled / cancelled'], $wh) ?></select><input name="q" value="<?= h($q) ?>" placeholder="Role, company, place or JOB-12"><button class="btn sm">Show</button><?php if ($can): ?> <a class="btn sm" href="?view=vacancy">Add a vacancy</a><?php endif; ?></form>
<div class="card"><table><tr><th>Job code</th><th>Role</th><th>Company</th><th>Project</th><th>Place</th><th>Openings</th><th>Experience</th><th>Closes</th><th>Status</th></tr>
<?php foreach ($vs as $v): ?><tr><td><a href="?view=vacancy&id=<?= (int)$v['id'] ?>"><?= h(ri_job_code((int)$v['id'])) ?></a></td><td><?= h($v['title']) ?></td><td><?= h($v['company']) ?><?= (int)$v['watch'] ? ' <b>watched</b>' : '' ?></td><td><?= h((string)$v['project']) ?></td><td><?= h($v['location']) ?></td><td><?= (int)$v['openings'] ?></td><td><?= h(trim(($v['exp_min'] !== null ? (float)$v['exp_min'] : '') . '–' . ($v['exp_max'] !== null ? (float)$v['exp_max'] : ''), '–')) ?></td><td><?= h((string)$v['closes_on']) ?></td><td><?= h(RI_VAC_STATUS[$v['status']]) ?></td></tr><?php endforeach; ?>
<?php if (!$vs): ?><tr><td colspan="9" class="muted">No vacancies.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'vacancy'): $x = $id ? (ri_vacancy_get($db, $id) ?? []) : []; $cos = ri_companies($db); $pjs = ri_projects($db); $reqs = module_on('recruitment') ? array_filter(rc_req_list($db, $u), fn($r) => in_array($r['status'], ['open', 'hold'], true)) : []; ?>
<?php if ($x): ?><div class="card"><h2><?= h(ri_job_code((int)$x['id'])) ?> · <?= h($x['title']) ?> · <?= h($x['company']) ?></h2><p class="muted">Put the job code in posts and messages so replies can be matched to this vacancy.</p></div><?php endif; ?>
<?php if ($can): ?><form method="post" class="card"><?= $hid('vacancy', (int)($x['id'] ?? 0)) ?><h3><?= $x ? 'Change' : 'Add a vacancy' ?></h3><p class="row" style="flex-wrap:wrap">
<label>Company <select name="company_id" required><?= $opts(array_combine(array_column($cos, 'id'), array_map(fn($c) => $c['name'] . ' (' . RI_COMPANY_TYPES[$c['type']] . ')', $cos)) ?: [], (string)($f['company_id'] ?? $x['company_id'] ?? ''), '—') ?></select></label> <label>Role <input name="title" required value="<?= $val($x, 'title') ?>"></label> <label>Department <input name="dept" value="<?= $val($x, 'dept') ?>"></label> <label>Place <input name="location" value="<?= $val($x, 'location') ?>"></label> <label>Openings <input name="openings" size="4" value="<?= $val($x, 'openings') ?: '1' ?>"></label></p>
<p class="row" style="flex-wrap:wrap"><label>Experience from <input name="exp_min" size="4" value="<?= $val($x, 'exp_min') ?>"></label> <label>to <input name="exp_max" size="4" value="<?= $val($x, 'exp_max') ?>"></label><?php if ($pay): ?> <label>Pay from <input name="pay_min" size="9" value="<?= $val($x, 'pay_min') ?>"></label> <label>to <input name="pay_max" size="9" value="<?= $val($x, 'pay_max') ?>"></label><?php endif; ?>
<label>Posted <input type="date" name="posted_on" value="<?= $val($x, 'posted_on') ?>"></label> <label>Closes <input type="date" name="closes_on" value="<?= $val($x, 'closes_on') ?>"></label> <label>Status <select name="status"><?= $opts(RI_VAC_STATUS, (string)($f['status'] ?? $x['status'] ?? ''), 'Default') ?></select></label></p>
<p class="row" style="flex-wrap:wrap"><label>Project <select name="project_id"><?= $opts(array_column($pjs, 'name', 'id'), (string)($f['project_id'] ?? $x['project_id'] ?? ''), '—') ?></select></label> <label>Our requisition <select name="req_id"><?= $opts(array_combine(array_column($reqs, 'id'), array_map(fn($r) => $r['ref'] . ' · ' . $r['title'], $reqs)) ?: [], (string)($f['req_id'] ?? $x['req_id'] ?? ''), '—') ?></select></label> <label>Seen on / source <input name="src" value="<?= $val($x, 'src') ?>"></label></p>
<p><input name="notes" placeholder="Notes" style="width:100%" value="<?= $val($x, 'notes') ?>"></p><p class="muted">An outside company's opening is kept as "Observed only" — it feeds the hiring trends, never a project or a requisition.</p><button class="btn sm">Save</button></form><?php endif; ?>
<?php if ($x) $hist('vacancy', (int)$x['id']); ?>

<?php elseif ($view === 'trends'): $tr = ri_trends($db); ?>
<div class="card"><h2>Outside companies hiring · last <?= (int)$S['trend_days'] ?> days</h2><p class="muted">From openings recorded as "Observed only". Watched companies come first.</p><table><tr><th>Company</th><th>Openings</th><th>Roles</th><th>Last seen</th></tr>
<?php foreach ($tr as $c => $t): ?><tr><td><?= h($c) ?><?= $t['watch'] ? ' <b>watched</b>' : '' ?></td><td><?= (int)$t['openings'] ?></td><td><?= h(implode(', ', array_slice($t['roles'], 0, 8))) ?></td><td><?= h($t['last']) ?></td></tr><?php endforeach; ?>
<?php if (!$tr): ?><tr><td colspan="4" class="muted">Nothing observed in this window.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'settings' && is_superadmin($u)): ?>
<form method="post" class="card"><?= $hid('settings') ?><h2>Recruitment intelligence settings</h2><p><b>Keep the records</b><br><?php foreach (role_labels() as $c => $n): ?><label><input type="checkbox" name="manage_roles[]" value="<?= h($c) ?>"<?= in_array($c, $S['manage_roles'], true) ? ' checked' : '' ?>> <?= h($n) ?></label> <?php endforeach; ?></p>
<p><label>Hiring trends look back this many days <input name="trend_days" size="4" value="<?= (int)$S['trend_days'] ?>"></label></p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif; ?>
<?php ui_foot();
