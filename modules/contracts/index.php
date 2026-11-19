<?php
// Third parties & contracts — parties, one party (details, numbers masked, contacts, documents, agreements), one
// agreement (versions, approval, signed copy, amendment, cancel, history), waiting for me, due soon, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/contracts.php';
$u = module_require('contracts');
$db = pdo(); $R = ct_rights($u); $set = ct_settings(); $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN'); $today = date('Y-m-d');
$view = (string)($_GET['view'] ?? 'list');
if (!$R['view']) { ui_head('Third parties & contracts', $u, 'contracts'); echo '<div class="card">Your role has no access here.</div>'; ui_foot(); exit; }
if ($view === 'file') {
    try { [$b, $n, $m] = ct_file($db, $u, (string)($_GET['k'] ?? ''), (int)($_GET['id'] ?? 0)); } catch (Throwable $e) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $m); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $n) . '"');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'"); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    echo $b; exit;
}
$upload = function (string $field): array { $t = $_FILES[$field]['tmp_name'] ?? ''; if (!is_string($t) || $t === '' || !is_uploaded_file($t)) throw new RuntimeException('Pick a file.'); $b = (string)file_get_contents($t); @unlink($t); return [$b, (string)$_FILES[$field]['name']]; };
$reveal = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $back = 'index.php';
    try {
        switch ($a) {
            case 'party': $id = ct_party_save($db, $u, $_POST, $id, !empty($_POST['same_ok'])); flash('Saved.'); $back = 'index.php?view=p&id=' . $id; break;
            case 'reveal': $back = 'index.php?view=p&id=' . $id; $reveal = ct_identity($db, $u, $id); $view = 'p'; $_GET['id'] = $id; break;
            case 'contact': ct_contact_add($db, $u, $id, $_POST); flash('Contact added.'); $back = 'index.php?view=p&id=' . $id; break;
            case 'contactoff': ct_contact_active($db, $u, (int)($_POST['cid'] ?? 0), !empty($_POST['on'])); flash('Saved.'); $back = 'index.php?view=p&id=' . $id; break;
            case 'doc': [$b, $n] = $upload('f'); ct_doc_add($db, $u, $id, (string)($_POST['kind'] ?? ''), $b, $n, $_POST); flash('Document added.'); $back = 'index.php?view=p&id=' . $id . '#docs'; break;
            case 'doccheck': ct_doc_check($db, $u, (int)($_POST['did'] ?? 0), ($_POST['ok'] ?? '') === '1', (string)($_POST['remark'] ?? '')); flash('Saved.'); $back = 'index.php?view=p&id=' . $id . '#docs'; break;
            case 'contract': $cid = ct_contract_create($db, $u, $id, $_POST, ((int)($_POST['parent'] ?? 0)) ?: null); flash('Agreement made.'); $back = 'index.php?view=c&id=' . $cid; break;
            case 'cedit': ct_contract_update($db, $u, $id, $_POST); flash('Saved.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'version': [$b, $n] = $upload('f'); ct_version_add($db, $u, $id, $b, $n, (string)($_POST['note'] ?? '')); flash('Version added.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'signed': [$b, $n] = $upload('f'); ct_version_add($db, $u, $id, $b, $n, (string)($_POST['note'] ?? ''), true, (string)($_POST['signed_on'] ?? '')); flash('Signed copy recorded — executed.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'ask': ct_approval_request($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Sent for approval.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'decide': ct_approval_decide($db, $u, (int)($_POST['aid'] ?? 0), ($_POST['ok'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Saved.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'withdraw': ct_approval_withdraw($db, $u, $id); flash('Request withdrawn.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'cancel': ct_contract_cancel($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Cancelled.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = ct_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['contracts'] ?? ['roles' => module_roles('contracts')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['contracts', 1, implode(',', $st['roles'] ?: module_roles('contracts')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'contracts'); flash('Settings saved.'); $back = 'index.php?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'party') { $_SESSION['ct_form'] = $_POST; $back = $id ? 'index.php?view=p&id=' . $id : 'index.php?view=new'; } elseif ($id && $a !== 'reveal') $back = 'index.php?view=' . (in_array($a, ['contact', 'contactoff', 'doc', 'doccheck', 'contract'], true) ? 'p' : 'c') . '&id=' . $id; }
    if ($reveal === null) redirect($back);
}
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$people = $db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR); $bn = array_column(branches(), 'name', 'id');
$money = fn($m) => $m === null ? '—' : number_format((int)$m / 100, 2) . ' ' . (org('currency') ?: '');
$waiting = ct_waiting($db, $u);
ui_head('Third parties & contracts', $u, 'contracts');
$tab = fn(string $k, string $l) => '<a class="btn sm' . ($view === $k ? '' : ' ghost') . '" href="?view=' . $k . '">' . h($l) . '</a> '; ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('list', 'Parties') . $tab('contracts', 'Agreements') . ($R['manage'] ? $tab('new', 'New party') : '') . ($R['approve'] ? $tab('waiting', 'Waiting for me' . ($waiting ? ' (' . count($waiting) . ')' : '')) : '') . $tab('due', 'Ending soon') . ($isAdmin ? $tab('settings', 'Settings') : '') ?></nav>

<?php $partyForm = function (array $e, int $id) use ($opts, $people, $bn, $set): void { ?>
<div class="grid"><label>Legal name * <input name="legal_name" value="<?= h((string)($e['legal_name'] ?? '')) ?>" required></label><label>Trade name <input name="trade_name" value="<?= h((string)($e['trade_name'] ?? '')) ?>"></label>
<label>Entity type <select name="entity_type"><?= $opts(CT_ENTITY_TYPES, (string)($e['entity_type'] ?? 'company')) ?></select></label><label>Category <select name="category"><?= $opts(CT_CATEGORIES, (string)($e['category'] ?? 'vendor')) ?></select></label>
<label><?= h($set['tax_label']) ?> <input name="tax_id" placeholder="<?= $id && !empty($e['tax_tail']) ? 'on file ending ' . h($e['tax_tail']) . ' — blank keeps it' : '' ?>" autocomplete="off"></label>
<label><?= h($set['reg_label']) ?> <input name="reg_no" placeholder="<?= $id && !empty($e['reg_tail']) ? 'on file ending ' . h($e['reg_tail']) . ' — blank keeps it' : '' ?>" autocomplete="off"></label>
<label>Address <input name="address" value="<?= h((string)($e['address'] ?? '')) ?>"></label><label>City <input name="city" value="<?= h((string)($e['city'] ?? '')) ?>"></label><label>State / region <input name="state" value="<?= h((string)($e['state'] ?? '')) ?>"></label><label>Postcode <input name="postcode" value="<?= h((string)($e['postcode'] ?? '')) ?>"></label>
<label>Branch <select name="branch_id"><?= $opts($bn, (string)($e['branch_id'] ?? ''), 'Every branch') ?></select></label><label>Owner <select name="owner_id"><?= $opts($people, (string)($e['owner_id'] ?? ''), '—') ?></select></label><label>Onboarded on <input type="date" name="onboarded_on" value="<?= h((string)($e['onboarded_on'] ?? '')) ?>"></label>
<label>Status <select name="status"><?= $opts(CT_STATUS, (string)($e['status'] ?? 'prospect')) ?></select></label><label>Risk <select name="risk"><?= $opts(CT_RISK, (string)($e['risk'] ?? 'medium')) ?></select></label><label>Due diligence <select name="dd_status"><?= $opts(CT_DD, (string)($e['dd_status'] ?? 'not_started')) ?></select></label></div>
<label>What they do for us <input name="purpose" value="<?= h((string)($e['purpose'] ?? '')) ?>"></label><label><input type="checkbox" name="same_ok" value="1"> A different party (same name as one on the register)</label><?php }; ?>

<?php if ($view === 'list'): $f = ['q' => (string)($_GET['q'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'category' => (string)($_GET['category'] ?? '')]; $rows = ct_parties($db, $f); ?>
<form class="card row" style="flex-wrap:wrap"><input name="q" value="<?= h($f['q']) ?>" placeholder="Name or reference"><select name="status"><?= $opts(CT_STATUS, $f['status'], 'Any status') ?></select><select name="category"><?= $opts(CT_CATEGORIES, $f['category'], 'Any category') ?></select><button class="btn sm">Show</button></form>
<div class="card"><table><tr><th>Ref</th><th>Party</th><th>Category</th><th>Status</th><th>Risk</th><th>Due diligence</th></tr>
<?php foreach ($rows as $p): ?><tr><td><a href="?view=p&id=<?= (int)$p['id'] ?>"><?= h($p['ref']) ?></a></td><td><?= h($p['legal_name']) ?><?= $p['trade_name'] !== '' ? '<div class="muted">' . h($p['trade_name']) . '</div>' : '' ?></td><td><?= h(CT_CATEGORIES[$p['category']] ?? '') ?></td><td><?= h(CT_STATUS[$p['status']]) ?></td><td><?= h(CT_RISK[$p['risk']]) ?></td><td><?= h(CT_DD[$p['dd_status']]) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No parties yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'new' && $R['manage']): $e = $_SESSION['ct_form'] ?? []; unset($_SESSION['ct_form']); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="party"><h2>New party</h2><?php $partyForm($e, 0); ?><button class="btn">Save</button></form>

<?php elseif ($view === 'p' && ($p = ct_party($db, (int)($_GET['id'] ?? 0)))): $pid = (int)$p['id']; $e = ($_SESSION['ct_form'] ?? null) ?: $p; unset($_SESSION['ct_form']); ?>
<div class="card"><h2><?= h($p['legal_name']) ?> <span class="muted"><?= h($p['ref']) ?></span></h2>
<p><?= h(implode(' · ', array_filter([CT_CATEGORIES[$p['category']] ?? '', CT_ENTITY_TYPES[$p['entity_type']] ?? '', CT_STATUS[$p['status']], 'risk ' . CT_RISK[$p['risk']], 'due diligence ' . strtolower(CT_DD[$p['dd_status']])]))) ?></p>
<p><?= h($set['tax_label']) ?>: <b><?= $reveal ? h($reveal['tax'] ?: '—') : h($p['tax_tail'] !== '' ? mask(str_repeat('0', 6) . $p['tax_tail']) : '—') ?></b> · <?= h($set['reg_label']) ?>: <b><?= $reveal ? h($reveal['reg'] ?: '—') : h($p['reg_tail'] !== '' ? mask(str_repeat('0', 6) . $p['reg_tail']) : '—') ?></b>
<?php if ($R['sensitive'] && !$reveal && ($p['tax_tail'] !== '' || $p['reg_tail'] !== '')): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="reveal"><input type="hidden" name="id" value="<?= $pid ?>"><button class="btn sm ghost">Show in full</button></form><?php endif; ?><?= $reveal ? ' <span class="muted">(shown once, recorded)</span>' : '' ?></p></div>
<?php if ($R['manage']): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="party"><input type="hidden" name="id" value="<?= $pid ?>"><h3>Details</h3><?php $partyForm($e, $pid); ?><button class="btn sm">Save</button></form><?php endif; ?>
<div class="card"><h3>Contacts</h3><table><?php foreach (ct_contacts($db, $pid) as $c): ?><tr<?= $c['active'] ? '' : ' class="muted"' ?>><td><?= h($c['name']) ?><?= $c['signatory'] ? ' <b>signatory</b>' : '' ?></td><td><?= h($c['role']) ?></td><td><?= h($c['email']) ?></td><td><?= h($c['phone']) ?></td>
<td><?php if ($R['manage']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="contactoff"><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>"><input type="hidden" name="on" value="<?= $c['active'] ? '' : '1' ?>"><button class="btn sm ghost"><?= $c['active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table>
<?php if ($R['manage']): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="contact"><input type="hidden" name="id" value="<?= $pid ?>"><input name="name" placeholder="Name" required><input name="role" placeholder="Role"><input name="email" placeholder="Email"><input name="phone" placeholder="Phone"><label><input type="checkbox" name="signatory" value="1"> signs</label><button class="btn sm">Add</button></form><?php endif; ?></div>
<div class="card" id="docs"><h3>Compliance documents</h3><table><tr><th>Document</th><th>Valid</th><th>State</th><th></th></tr>
<?php foreach (ct_docs($db, $pid) as $d): ?><tr<?= $d['replaced_by'] ? ' class="muted"' : '' ?>><td><a href="?view=file&k=d&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= h(CT_DOC_KINDS[$d['kind']] ?? $d['kind']) ?></a><?= $d['number_tail'] !== '' ? ' <span class="muted">…' . h($d['number_tail']) . '</span>' : '' ?><?= $d['replaced_by'] ? ' <span class="muted">replaced</span>' : '' ?></td>
<td><?= h(trim(($d['valid_from'] ?? '') . ' – ' . ($d['expires_on'] ?? ''), ' –')) ?></td><td><?= h(ct_doc_state($d, $today)) ?><?= $d['remark'] !== '' ? '<div class="muted">' . h($d['remark']) . '</div>' : '' ?></td>
<td><?php if ($R['manage'] && !$d['replaced_by'] && $d['status'] === 'uploaded' && (int)$d['uploaded_by'] !== $uid): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="doccheck"><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="did" value="<?= (int)$d['id'] ?>"><input name="remark" placeholder="Remark (needed to reject)" size="14"><button class="btn sm" name="ok" value="1">Verify</button><button class="btn sm ghost" name="ok" value="0">Reject</button></form><?php endif; ?></td></tr><?php endforeach; ?></table>
<?php if ($R['manage']): ?><form method="post" enctype="multipart/form-data" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="doc"><input type="hidden" name="id" value="<?= $pid ?>"><select name="kind"><?= $opts(CT_DOC_KINDS) ?></select><input name="number" placeholder="Number on it (last 4 kept)" autocomplete="off"><input type="date" name="valid_from"><input type="date" name="expires_on"><input type="file" name="f" accept="application/pdf,image/jpeg,image/png" required><button class="btn sm">Add</button></form><p class="muted">Someone other than the uploader verifies it. A new copy of the same kind replaces the old one (kept).</p><?php endif; ?></div>
<div class="card"><h3>Agreements</h3><table><?php foreach (ct_contracts($db, ['party' => $pid]) as $c): ?><tr><td><a href="?view=c&id=<?= (int)$c['id'] ?>"><?= h($c['ref']) ?></a></td><td><?= h($c['title']) ?></td><td><?= h(ct_contract_state($c, $today)) ?></td><td><?= h((string)$c['expires_on']) ?></td></tr><?php endforeach; ?></table>
<?php if ($R['manage'] && !in_array($p['status'], ['terminated', 'closed'], true)): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="contract"><input type="hidden" name="id" value="<?= $pid ?>"><select name="type"><?= $opts(CT_TYPES) ?></select><input name="title" placeholder="Title" required><input type="date" name="effective_from" title="From"><input type="date" name="expires_on" title="Until"><button class="btn sm">New agreement</button></form><?php endif; ?></div>
<div class="card"><h3>History</h3><?php foreach (ct_history($db, 'party', $pid) as $l): ?><div class="muted"><?= h(substr($l['at'], 0, 16) . ' · ' . ($l['who'] ?? 'system') . ' — ' . $l['action'] . ($l['detail'] !== '' ? ': ' . $l['detail'] : '')) ?></div><?php endforeach; ?></div>

<?php elseif ($view === 'c' && ($c = ct_contract($db, (int)($_GET['id'] ?? 0)))): $cid = (int)$c['id']; $vs = ct_versions($db, $cid); $ap = ct_open_approval($db, $cid); ?>
<div class="card"><h2><?= h($c['title']) ?> <span class="muted"><?= h($c['ref']) ?></span></h2>
<p><b><?= h(ct_contract_state($c, $today)) ?></b> · <a href="?view=p&id=<?= (int)$c['party_id'] ?>"><?= h($c['legal_name']) ?></a> · <?= h(CT_TYPES[$c['type']]) ?> · <?= h(trim(($c['effective_from'] ?? '') . ' – ' . ($c['expires_on'] ?? ''), ' –')) ?><?= $c['notice_days'] !== null ? ' · notice ' . (int)$c['notice_days'] . ' days' : '' ?><?= $c['parent_id'] ? ' · amends <a href="?view=c&id=' . (int)$c['parent_id'] . '">#' . (int)$c['parent_id'] . '</a>' : '' ?></p>
<?php if ($R['commercial']): ?><p>Value <?= h($money($c['value_minor'])) ?><?= $c['terms'] !== '' ? ' · ' . h($c['terms']) : '' ?></p><?php endif; ?>
<?php if ($c['status'] === 'executed'): ?><p>Executed on <?= h((string)$c['executed_on']) ?> (<?= h($c['how_signed'] === 'outside' ? 'signed outside, recorded' : $c['how_signed']) ?>).</p><?php elseif ($c['status'] === 'cancelled'): ?><p>Cancelled — <?= h($c['cancel_note']) ?></p><?php endif; ?></div>
<?php if ($R['manage'] && $c['status'] === 'draft'): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="cedit"><input type="hidden" name="id" value="<?= $cid ?>"><h3>Details</h3>
<div class="grid"><label>Title <input name="title" value="<?= h($c['title']) ?>"></label><label>From <input type="date" name="effective_from" value="<?= h((string)$c['effective_from']) ?>"></label><label>Until <input type="date" name="expires_on" value="<?= h((string)$c['expires_on']) ?>"></label><label>Notice (days) <input type="number" name="notice_days" value="<?= h((string)$c['notice_days']) ?>"></label>
<label>Owner <select name="owner_id"><?= $opts($people, (string)$c['owner_id']) ?></select></label><?php if ($R['commercial']): ?><label>Value <input name="value" value="<?= $c['value_minor'] === null ? '' : h(number_format((int)$c['value_minor'] / 100, 2, '.', '')) ?>"></label><label>Payment terms <input name="terms" value="<?= h($c['terms']) ?>"></label><?php endif; ?></div>
<label>Scope <textarea name="scope" rows="2"><?= h($c['scope']) ?></textarea></label><button class="btn sm">Save</button></form><?php endif; ?>
<div class="card"><h3>Versions</h3><table><tr><th>No.</th><th>File</th><th>SHA-256</th><th>By</th><th></th></tr><?php foreach ($vs as $v): ?><tr><td>v<?= (int)$v['no'] ?><?= (int)$c['current_version'] === (int)$v['id'] ? ' <b>current</b>' : '' ?><?= $v['signed'] ? ' signed' : '' ?></td><td><a href="?view=file&k=v&id=<?= (int)$v['id'] ?>" target="_blank" rel="noopener"><?= h($v['file_name']) ?></a></td><td class="muted"><?= h(substr($v['sha256'], 0, 16)) ?>…</td><td><?= h((string)$v['who']) ?> <?= h(substr($v['uploaded_at'], 0, 10)) ?></td><td><?= h($v['note']) ?></td></tr><?php endforeach; ?>
<?php if (!$vs): ?><tr><td colspan="5" class="muted">No file yet.</td></tr><?php endif; ?></table>
<?php if ($R['manage'] && in_array($c['status'], ['draft', 'pending', 'approved'], true)): ?><form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="version"><input type="hidden" name="id" value="<?= $cid ?>"><input type="file" name="f" accept="application/pdf" required><input name="note" placeholder="What changed"><button class="btn sm">Add version</button></form><?php if ($c['status'] !== 'draft'): ?><p class="muted">A new version sets aside the approval — it needs asking again.</p><?php endif; ?><?php endif; ?></div>
<div class="card"><h3>Approval</h3>
<?php if ($c['status'] === 'draft' && $R['manage'] && $c['current_version']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="ask"><input type="hidden" name="id" value="<?= $cid ?>"><input name="note" placeholder="Note for the approver"><button class="btn sm">Ask for approval</button></form><?php endif; ?>
<?php if ($ap): ?><p>Waiting — v<?= (int)$ap['no'] ?>.</p><?php if (ct_may_decide($R, $uid, (int)$ap['requested_by'], (int)$ap['uploaded_by'])): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="decide"><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="aid" value="<?= (int)$ap['id'] ?>"><input name="note" placeholder="Note (needed to send back)"><button class="btn sm" name="ok" value="1">Approve</button><button class="btn sm ghost" name="ok" value="0">Send back</button></form>
<?php elseif ((int)$ap['requested_by'] === $uid): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="withdraw"><input type="hidden" name="id" value="<?= $cid ?>"><button class="btn sm ghost">Withdraw the request</button></form><?php endif; ?><?php endif; ?>
<?php if ($R['approve'] && in_array($c['status'], ['draft', 'pending', 'approved'], true)): ?><form method="post" enctype="multipart/form-data" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="signed"><input type="hidden" name="id" value="<?= $cid ?>"><span>Record a copy signed outside:</span><input type="file" name="f" accept="application/pdf" required><input type="date" name="signed_on" max="<?= $today ?>" required><button class="btn sm ghost">Record — executed</button></form><?php endif; ?>
<table><?php foreach (ct_approvals($db, $cid) as $x): ?><tr class="muted"><td>v<?= (int)$x['no'] ?></td><td><?= h($x['status']) ?></td><td>asked by <?= h((string)$x['req_name']) ?> <?= h(substr($x['requested_at'], 0, 10)) ?></td><td><?= $x['dec_name'] ? h($x['dec_name'] . ' ' . substr((string)$x['decided_at'], 0, 10)) : '' ?> <?= h($x['decision_note']) ?></td></tr><?php endforeach; ?></table></div>
<?php if ($R['manage']): ?><div class="card row" style="flex-wrap:wrap"><?php if ($c['status'] === 'executed'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="contract"><input type="hidden" name="id" value="<?= (int)$c['party_id'] ?>"><input type="hidden" name="parent" value="<?= $cid ?>"><select name="type"><?= $opts(array_intersect_key(CT_TYPES, array_flip(['AMD', 'EXT', 'REN', 'TRM'])), 'AMD') ?></select><input name="title" value="<?= h(mb_substr('Amendment to ' . $c['ref'], 0, 200)) ?>" required><button class="btn sm">Start</button></form>
<?php elseif ($c['status'] !== 'cancelled'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="<?= $cid ?>"><input name="note" placeholder="Why cancel" required><button class="btn sm ghost">Cancel the agreement</button></form><?php endif; ?></div><?php endif; ?>
<div class="card"><h3>History</h3><?php foreach (ct_history($db, 'contract', $cid) as $l): ?><div class="muted"><?= h(substr($l['at'], 0, 16) . ' · ' . ($l['who'] ?? 'system') . ' — ' . $l['action'] . ($l['detail'] !== '' ? ': ' . $l['detail'] : '')) ?></div><?php endforeach; ?></div>

<?php elseif ($view === 'contracts'): $st = (string)($_GET['status'] ?? ''); $rows = ct_contracts($db, ['status' => $st]); ?>
<form class="card row"><input type="hidden" name="view" value="contracts"><select name="status"><?= $opts(CT_CONTRACT_STATUS, $st, 'Any state') ?></select><button class="btn sm">Show</button></form>
<div class="card"><table><tr><th>Ref</th><th>Party</th><th>Title</th><th>State</th><th>Until</th></tr><?php foreach ($rows as $c): ?><tr><td><a href="?view=c&id=<?= (int)$c['id'] ?>"><?= h($c['ref']) ?></a></td><td><?= h($c['legal_name']) ?></td><td><?= h($c['title']) ?></td><td><?= h(ct_contract_state($c, $today)) ?></td><td><?= h((string)$c['expires_on']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="muted">None.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'waiting' && $R['approve']): ?>
<div class="card"><table><tr><th>Agreement</th><th>Party</th><th>Version</th></tr><?php foreach ($waiting as $a): ?><tr><td><a href="?view=c&id=<?= (int)$a['contract_id'] ?>"><?= h($a['ref']) ?></a> <?= h($a['title']) ?></td><td><?= h($a['legal_name']) ?></td><td>v<?= (int)$a['no'] ?></td></tr><?php endforeach; ?>
<?php if (!$waiting): ?><tr><td colspan="3" class="muted">Nothing waiting for you.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'due'): $rows = ct_due($db, $today); ?>
<div class="card"><table><tr><th>Ends</th><th>What</th><th>Party</th></tr><?php foreach ($rows as $r): ?><tr><td><?= $r['expires_on'] < $today ? '<b>Ended</b> ' : '' ?><?= h($r['expires_on']) ?></td><td><?= $r['what'] === 'contract' ? '<a href="?view=c&id=' . (int)$r['id'] . '">' . h($r['label']) . '</a>' : h(CT_DOC_KINDS[$r['label']] ?? $r['label']) ?></td><td><a href="?view=p&id=<?= (int)$r['party_id'] ?>"><?= h($r['legal_name']) ?></a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3" class="muted">Nothing ending in the next 90 days.</td></tr><?php endif; ?></table><p class="muted">Reminders go to the owner and the uploader at <?= h(implode(', ', $set['alert_days'])) ?> days (run <code>php tools/ct_alerts.php</code> daily).</p></div>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['view_roles' => 'See the register and agreements', 'manage_roles' => 'Keep parties, contacts, documents and agreements', 'approve_roles' => 'Approve agreements and record signed copies', 'sensitive_roles' => 'See tax / registration numbers in full', 'commercial_roles' => 'See and set agreement values and terms'] as $k => $l): ?><h3><?= h($l) ?></h3>
<div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Reminder days before an end date <input name="alert_days" value="<?= h(implode(', ', $set['alert_days'])) ?>"></label><label>Agreement reference starts with <input name="prefix" value="<?= h($set['prefix']) ?>" maxlength="6"></label>
<label>Name of the tax number <input name="tax_label" value="<?= h($set['tax_label']) ?>"></label><label>Name of the registration number <input name="reg_label" value="<?= h($set['reg_label']) ?>"></label></div><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not found.</div><?php endif;
ui_foot();
