<?php
// Enquiry register — register, follow-ups due, one enquiry (form, notes, quick notes, make CRM customer / order),
// reports by source / product / person, import, CSV, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/enquiries.php';
$u = module_require('enquiries');
$db = pdo(); $set = enq_settings(); $known = enq_known($set); $R = enq_rights($u); $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN');
$view = (string)($_GET['view'] ?? 'list'); $today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = 'index.php?view=e&id=' . $id;
    try {
        switch ($a) {
            case 'save': $id = enq_save($db, $u, $id, $_POST, !empty($_POST['force'])); flash('Saved.'); $to = 'index.php?view=e&id=' . $id; break;
            case 'note': $e = enq_get($db, $id); if (!$e || !enq_can($u, $e)) throw new RuntimeException('Enquiry not found.'); if (trim((string)($_POST['body'] ?? '')) === '') throw new RuntimeException('Write the note.'); enq_note($db, $id, (string)$_POST['body'], $uid); flash('Note added.'); break;
            case 'quick': enq_quick($db, $u, $id, (string)$_POST['to']); flash('Moved to ' . ENQ_STAGES[$_POST['to']] . '.'); $to = (string)($_POST['back'] ?? '') === 'list' ? 'index.php' : $to; break;
            case 'remove': enq_remove($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Removed.'); $to = 'index.php'; break;
            case 'customer': $cid = enq_make_customer($db, $u, $id); flash('Linked to the CRM customer.'); break;
            case 'import':
                if (!$R['branch']) throw new RuntimeException('Only a manager imports enquiries.');
                $rows = [];
                $t = $_FILES['f']['tmp_name'] ?? '';
                if (is_string($t) && $t !== '' && is_uploaded_file($t)) { $rows = tbl_read($t, (string)$_FILES['f']['name']); @unlink($t); }
                elseif (trim((string)($_POST['paste'] ?? '')) !== '') { foreach (preg_split('/\r\n|\n|\r/', (string)$_POST['paste']) as $l) $rows[] = str_getcsv($l, str_contains($l, "\t") ? "\t" : ','); }
                else throw new RuntimeException('Pick a file or paste rows.');
                $_SESSION['enq_plan'] = ['uid' => $uid, 'at' => time(), 'rows' => enq_import_plan($db, $rows)]; flash('Checked — nothing saved yet.'); $to = 'index.php?view=import'; break;
            case 'importsave':
                $p = $_SESSION['enq_plan'] ?? null; if (!$p || $p['uid'] !== $uid || time() - $p['at'] > 3600) throw new RuntimeException('Check the rows again.');
                $n = enq_import_apply($db, $u, $p['rows']); unset($_SESSION['enq_plan']); flash("Saved: {$n['new']} new, {$n['note']} noted on open enquiries, {$n['skipped']} left out."); $to = 'index.php'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = enq_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['enquiries'] ?? ['roles' => module_roles('enquiries')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['enquiries', 1, implode(',', $st['roles'] ?: module_roles('enquiries')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'enquiries'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        if ($a === 'save') { $_SESSION['enq_form'] = $_POST; $to = 'index.php?view=' . ($id ? 'edit&id=' . $id : 'new'); }
        if ($a === 'import' || $a === 'importsave') $to = 'index.php?view=import';
        if ($a === 'settings') $to = 'index.php?view=settings';
    }
    redirect($to);
}

$filters = ['q' => (string)($_GET['q'] ?? ''), 'stage' => (string)($_GET['stage'] ?? 'open'), 'attn' => (string)($_GET['attn'] ?? ''), 'source' => (string)($_GET['source'] ?? ''), 'category' => (string)($_GET['category'] ?? ''), 'assigned' => (string)($_GET['assigned'] ?? ''), 'sort' => (string)($_GET['sort'] ?? '')];
if ($view === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="enquiries.csv"');
    $q = fn($v) => '"' . str_replace('"', '""', (in_array(substr((string)$v, 0, 1), ['=', '+', '-', '@'], true) ? "'" : '') . (string)$v) . '"';
    echo "\xEF\xBB\xBF" . implode(',', array_map($q, ['Ref', 'Date', 'Customer', 'Company', 'Phone', 'Email', 'Category', 'Product', 'Source', 'Stage', 'Quotation', 'Value before tax', 'Tax %', 'Follow-up', 'Owner', 'Lost reason'])) . "\r\n";
    foreach (enq_list($db, $u, ['stage' => 'all'] + $filters + ['limit' => 100000]) as $e) echo implode(',', array_map($q, [$e['ref'], $e['enquiry_date'], $e['customer_name'], $e['company'], $e['phone'], $e['email'], $e['category'], $e['product'], $e['source'], ENQ_STAGES[$e['status']], $e['quote_no'], $e['basic'], $e['tax_pct'], $e['follow_up'], $e['assignee'], $e['lost_reason']])) . "\r\n";
    audit($uid, 'enq_export', 'csv'); exit;
}

$team = enq_team($db); $bn = array_column(branches(), 'name', 'id');
$money = fn($v) => $v === null || $v === '' ? '—' : number_format((float)$v, 2);
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$grouped = fn(array $groups, string $c) => implode('', array_map(fn($g, $items) => '<optgroup label="' . h($g) . '">' . implode('', array_map(fn($x) => '<option' . ($x === $c ? ' selected' : '') . '>' . h($x) . '</option>', $items)) . '</optgroup>', array_keys($groups), $groups));
$attnPill = fn(array $e) => ['overdue' => '<b>Overdue</b> ', 'today' => '<b>Today</b> '][enq_attn($e, $today)] ?? '';
$form = function (array $e) use ($opts, $grouped, $known, $set, $team, $bn, $R, $uid): void { $isNew = empty($e['id']); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
<h2><?= $isNew ? 'New enquiry' : 'Change ' . h($e['ref']) ?></h2>
<h3>Customer</h3><div class="grid"><label>Customer name * <input name="customer_name" value="<?= h((string)($e['customer_name'] ?? '')) ?>" required></label><label>Company <input name="company" value="<?= h((string)($e['company'] ?? '')) ?>"></label>
<label>Phone <input name="phone" value="<?= h((string)($e['phone'] ?? '')) ?>" inputmode="tel"></label><label>Mail ID <input type="email" name="email" value="<?= h((string)($e['email'] ?? '')) ?>"></label></div>
<h3>Enquiry</h3><div class="grid"><label>Category <select name="category"><?= $opts(array_combine(array_keys($known['categories']), array_keys($known['categories'])) ?: [], (string)($e['category'] ?? ''), '—') ?></select></label>
<label>Product <select name="product"><option value="">—</option><?= $grouped($known['categories'], (string)($e['product'] ?? '')) ?></select></label>
<label>Lead source * <select name="source" required><option value="">Choose a source</option><?= $grouped($known['sources'], (string)($e['source'] ?? '')) ?></select></label>
<label>Stage <select name="status"><?= $opts(ENQ_STAGES, (string)($e['status'] ?? 'new')) ?></select></label>
<label>Reason lost (when lost) <select name="lost_reason"><?= $opts(array_combine($known['lost'], $known['lost']), (string)($e['lost_reason'] ?? ''), '—') ?></select></label>
<?php if ($bn): ?><label>Branch <select name="branch_id"><?= $opts($bn, (string)($e['branch_id'] ?? ''), '—') ?></select></label><?php endif; ?></div>
<h3>Quotation <span class="muted">(needed from “Quotation sent” on)</span></h3><div class="grid"><label>Quotation no. <input name="quote_no" value="<?= h((string)($e['quote_no'] ?? '')) ?>"></label><label>Date <input type="date" name="quote_date" value="<?= h((string)($e['quote_date'] ?? '')) ?>"></label>
<label>Value before tax <input name="basic" value="<?= h((string)($e['basic'] ?? '')) ?>" inputmode="decimal"></label><label>Tax % <input name="tax_pct" value="<?= h((string)($e['tax_pct'] ?? $set['tax_pct'])) ?>" size="4"></label></div>
<h3>Follow-up</h3><div class="grid"><label>Enquiry date <input type="date" name="enquiry_date" value="<?= h((string)($e['enquiry_date'] ?? date('Y-m-d'))) ?>"></label>
<label>Next follow-up <input type="date" name="follow_up" value="<?= h((string)($e['follow_up'] ?? date('Y-m-d', strtotime('+' . (int)$set['follow_new'] . ' day')))) ?>"></label>
<?php if ($R['assign']): ?><label>Given to <select name="assigned_user"><?= $opts($team, (string)($e['assigned_user'] ?? ($isNew ? $uid : '')), 'Nobody yet') ?></select></label><?php endif; ?></div>
<label>Note <textarea name="note" rows="2"></textarea></label>
<?php if ($isNew): ?><label><input type="checkbox" name="force" value="1"> A different requirement (add even if this number already has an open enquiry)</label><br><?php endif; ?>
<button class="btn">Save</button></form>
<?php };
$fromForm = function (): array { $f = $_SESSION['enq_form'] ?? []; unset($_SESSION['enq_form']); return $f; };

if (in_array($view, ['e', 'edit'], true)) { $e = enq_get($db, (int)($_GET['id'] ?? 0)); if (!$e || !enq_can($u, $e)) { http_response_code(404); $view = 'none'; } }
ui_head('Enquiry register', $u, 'enquiries'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'list' ? '' : ' ghost' ?>" href="index.php">Register</a> <a class="btn sm<?= $view === 'due' ? '' : ' ghost' ?>" href="?view=due">Follow-ups due</a>
<?php if ($R['own']): ?> <a class="btn sm<?= $view === 'new' ? '' : ' ghost' ?>" href="?view=new">New enquiry</a><?php endif; ?> <a class="btn sm<?= $view === 'report' ? '' : ' ghost' ?>" href="?view=report">Reports</a>
<?php if ($R['branch']): ?> <a class="btn sm<?= $view === 'import' ? '' : ' ghost' ?>" href="?view=import">Import</a><?php endif; ?><?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'list' || $view === 'due'): $f = $view === 'due' ? ['stage' => 'open', 'attn' => 'due'] + $filters : $filters; $rows = enq_list($db, $u, $f);
    $open = enq_list($db, $u, ['stage' => 'open']); $ov = count(array_filter($open, fn($e) => enq_attn($e, $today) === 'overdue')); $td = count(array_filter($open, fn($e) => enq_attn($e, $today) === 'today')); ?>
<div class="card row" style="flex-wrap:wrap"><span><b><?= count($open) ?></b> open</span> · <a href="?view=list&attn=overdue"><b><?= $ov ?></b> overdue</a> · <a href="?view=list&attn=today"><b><?= $td ?></b> due today</a></div>
<?php if ($view === 'list'): ?><form class="card row" style="flex-wrap:wrap"><input name="q" value="<?= h($filters['q']) ?>" placeholder="Ref, customer, company, phone, mail, quotation">
<select name="stage"><?= $opts(['open' => 'Open', 'all' => 'All stages'] + ENQ_STAGES, $filters['stage']) ?></select><select name="attn"><?= $opts(['overdue' => 'Overdue', 'today' => 'Due today', 'due' => 'Due or overdue'], $filters['attn'], 'Any follow-up') ?></select>
<select name="source"><option value="">Any source</option><?= $grouped($known['sources'], $filters['source']) ?></select><select name="category"><?= $opts(array_combine(array_keys($known['categories']), array_keys($known['categories'])) ?: [], $filters['category'], 'Any category') ?></select>
<?php if ($R['assign']): ?><select name="assigned"><?= $opts(['none' => 'Nobody yet'] + $team, $filters['assigned'], 'Anyone') ?></select><?php endif; ?><select name="sort"><?= $opts(['' => 'Follow-up date', 'newest' => 'Newest first'], $filters['sort']) ?></select>
<button class="btn sm">Show</button> <a class="btn sm ghost" href="?view=csv&<?= h(http_build_query($filters)) ?>">Download CSV</a></form><?php endif; ?>
<div class="card"><table><tr><th>Ref</th><th>Customer</th><th>Enquiry</th><th>Stage</th><th>Value</th><th>Follow-up</th><th>Owner</th><th></th></tr>
<?php foreach ($rows as $e): $next = ['new' => 'contacted', 'contacted' => 'quoted', 'quoted' => 'negotiation', 'negotiation' => 'won'][$e['status']] ?? ''; ?><tr><td><a href="?view=e&id=<?= (int)$e['id'] ?>"><?= h($e['ref']) ?></a></td><td><?= h($e['customer_name']) ?><div class="muted"><?= h($e['company']) ?></div></td>
<td><?= h($e['product'] ?: $e['category']) ?><div class="muted"><?= h($e['source']) ?></div></td><td><?= h(ENQ_STAGES[$e['status']]) ?></td><td><?= $money($e['basic']) ?></td><td><?= $attnPill($e) ?><?= h((string)$e['follow_up']) ?></td><td><?= h((string)($e['assignee'] ?? '—')) ?></td>
<td><?php if ($next): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="quick"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="to" value="<?= $next ?>"><input type="hidden" name="back" value="list"><button class="btn sm ghost">→ <?= h(ENQ_STAGES[$next]) ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="muted"><?= $view === 'due' ? 'Nothing due — well done.' : 'No enquiries here.' ?></td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'new' && $R['own']): $f = $fromForm(); $form($f); ?>

<?php elseif ($view === 'edit'): $f = $fromForm(); $form(($f ? $f + ['id' => $e['id'], 'ref' => $e['ref']] : $e)); ?>

<?php elseif ($view === 'e'):
    $notes = $db->prepare('SELECT n.*, u.name AS who FROM enq_note n LEFT JOIN users u ON u.id = n.by_user WHERE n.enquiry_id = ? ORDER BY n.id DESC'); $notes->execute([(int)$e['id']]);
    $tax = $e['basic'] !== null ? round((float)$e['basic'] * (float)$e['tax_pct'] / 100, 2) : null; $wa = $e['phone'] !== '' ? 'https://wa.me/' . $e['phone'] : ''; ?>
<div class="card"><div class="row" style="justify-content:space-between"><h2><?= h($e['customer_name']) ?> <span class="muted"><?= h($e['ref']) ?></span></h2><a class="btn sm" href="?view=edit&id=<?= (int)$e['id'] ?>">Change</a></div>
<p><b><?= h(ENQ_STAGES[$e['status']]) ?></b><?= $e['status'] === 'lost' ? ' — ' . h($e['lost_reason']) : '' ?> · <?= $attnPill($e) ?>follow-up <?= h((string)($e['follow_up'] ?? '—')) ?> · <?= h((string)($e['assignee'] ?? 'nobody yet')) ?><?= $e['removed_at'] ? ' · <b>Removed</b> — ' . h($e['removed_reason']) : '' ?></p>
<p><?= h(implode(' · ', array_filter([$e['company'], $e['phone'] !== '' ? '+' . $e['phone'] : '', $e['email'], $e['category'], $e['product'], 'from ' . $e['source'], 'on ' . $e['enquiry_date']]))) ?>
<?= $wa ? ' · <a href="' . h($wa) . '" rel="noopener" target="_blank">WhatsApp</a>' : '' ?><?= $e['email'] !== '' ? ' · <a href="mailto:' . h($e['email']) . '">Mail</a>' : '' ?></p>
<?php if ($e['quote_no'] !== '' || $e['basic'] !== null): ?><p>Quotation <?= h($e['quote_no']) ?><?= $e['quote_date'] ? ' of ' . h($e['quote_date']) : '' ?> · <?= $money($e['basic']) ?> + tax <?= $money($tax) ?> = <?= $money($e['basic'] !== null ? (float)$e['basic'] + $tax : null) ?></p><?php endif; ?>
<div class="row" style="flex-wrap:wrap"><?php foreach (array_diff(array_keys(ENQ_STAGES), [$e['status'], 'lost']) as $k): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="quick"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="to" value="<?= $k ?>"><button class="btn sm ghost">→ <?= h(ENQ_STAGES[$k]) ?></button></form><?php endforeach; ?></div></div>
<?php if (module_on('crm')): ?><div class="card row" style="flex-wrap:wrap"><?php if ($e['customer_id']): ?>CRM customer: <a href="../crm/index.php?view=cust&id=<?= (int)$e['customer_id'] ?>">open</a><?php if ($e['status'] === 'won' && !$e['order_id']): ?> · <a class="btn sm" href="../crm/orders.php?view=new&cust=<?= (int)$e['customer_id'] ?>">Make the order</a><?php endif; ?>
<?php if ($e['order_id']): ?> · <a href="../crm/orders.php?view=order&id=<?= (int)$e['order_id'] ?>">Its order</a><?php endif; ?>
<?php if (module_on('quotes') && in_array($e['status'], ENQ_OPEN, true)): ?> · <a class="btn sm ghost" href="../quotes/index.php?view=new&cust=<?= (int)$e['customer_id'] ?>&enq=<?= (int)$e['id'] ?>">Make quotation</a><?php endif; ?>
<?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="customer"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><button class="btn sm ghost">Make a CRM customer</button></form><span class="muted">No CRM customer has this phone or mail yet.</span><?php endif; ?></div><?php endif; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="note"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><h3>Notes</h3>
<div class="row" style="flex-wrap:wrap"><?php foreach (ENQ_QUICK_NOTES as $q): ?><button class="btn sm ghost" name="body" value="<?= h($q) ?>"><?= h($q) ?></button><?php endforeach; ?></div>
<textarea name="body" rows="2" placeholder="Write a note"></textarea><button class="btn sm">Add</button>
<?php foreach ($notes as $n): ?><div<?= (int)$n['sys'] ? ' class="muted"' : '' ?>><?= h(substr($n['at'], 0, 16)) ?> · <?= h((string)($n['who'] ?? 'system')) ?> — <?= h($n['body']) ?></div><?php endforeach; ?></form>
<?php if ($R['branch'] && !$e['removed_at']): ?><form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="remove"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input name="reason" placeholder="Why remove it (entered by mistake, duplicate …)" required><button class="btn sm ghost">Remove</button></form><?php endif; ?>

<?php elseif ($view === 'report'): $from = (string)($_GET['from'] ?? date('Y-m-01', strtotime('-2 month'))); $to = (string)($_GET['to'] ?? $today); $by = in_array($_GET['by'] ?? '', ['source', 'category', 'product', 'assignee', 'status'], true) ? (string)$_GET['by'] : 'source';
    $rows = array_values(array_filter(enq_list($db, $u, ['stage' => 'all', 'limit' => 100000]), fn($e) => $e['enquiry_date'] >= $from && $e['enquiry_date'] <= $to));
    $rep = enq_report(array_map(fn($e) => $e + ['status_l' => ENQ_STAGES[$e['status']]], $rows), $by === 'status' ? 'status_l' : $by); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="report">From <input type="date" name="from" value="<?= h($from) ?>"> to <input type="date" name="to" value="<?= h($to) ?>">
<select name="by"><?= $opts(['source' => 'By lead source', 'category' => 'By category', 'product' => 'By product', 'assignee' => 'By person', 'status' => 'By stage'], $by) ?></select><button class="btn sm">Show</button></form>
<div class="card"><table><tr><th></th><th>Enquiries</th><th>Open</th><th>Won</th><th>Lost</th><th>Win rate</th><th>Quoted value</th><th>Won value</th></tr>
<?php foreach ($rep as $k => $x): ?><tr><td><?= h((string)$k) ?></td><td><?= $x['n'] ?></td><td><?= $x['open'] ?></td><td><?= $x['won'] ?></td><td><?= $x['lost'] ?></td><td><?= $x['win_rate'] === null ? '—' : $x['win_rate'] . '%' ?></td><td><?= $money($x['quoted_value']) ?></td><td><?= $money($x['won_value']) ?></td></tr><?php endforeach; ?>
<?php if (!$rep): ?><tr><td colspan="8" class="muted">No enquiries in this period.</td></tr><?php endif; ?></table><p class="muted">Win rate = won ÷ (won + lost). Values before tax.</p></div>

<?php elseif ($view === 'import' && $R['branch']): $p = $_SESSION['enq_plan'] ?? null; if ($p && ($p['uid'] !== $uid || time() - $p['at'] > 3600)) $p = null; ?>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="import"><h2>Import enquiries</h2>
<p class="muted">A CSV / Excel file with titles, or rows pasted from a sheet in this order: Customer name, Company, Contact, Mail ID, Type of enquiry, Status, Value before tax, Enquiry date, Lead source, Quotation no. Status words map to stages; names not on the lists are kept in a note. Checking writes nothing.</p>
<input type="file" name="f" accept=".csv,.xlsx,.txt"><textarea name="paste" rows="5" placeholder="…or paste rows here"></textarea><button class="btn sm">Check</button></form>
<?php if ($p): $c = array_count_values(array_column($p['rows'], 'state')); ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="importsave">
<p><?= (int)($c['new'] ?? 0) ?> new · <?= (int)($c['note'] ?? 0) ?> already open (a note is added there) · <?= (int)($c['twice'] ?? 0) ?> twice in the file · <?= (int)($c['error'] ?? 0) ?> not read</p>
<table><tr><th>Line</th><th>Customer</th><th>Stage</th><th>Source</th><th>Result</th></tr><?php foreach ($p['rows'] as $r): ?><tr><td><?= (int)$r['line'] ?></td><td><?= h($r['d']['customer_name']) ?></td><td><?= h(ENQ_STAGES[$r['d']['status']] ?? '') ?></td><td><?= h($r['d']['source']) ?></td>
<td><?= ['new' => 'New', 'note' => 'Note on ' . h($r['match']['ref'] ?? ''), 'twice' => 'Twice in the file — left out', 'error' => 'Not read: ' . h($r['why'])][$r['state']] ?><?= $r['note'] !== '' ? '<div class="muted">' . h($r['note']) . '</div>' : '' ?></td></tr><?php endforeach; ?></table><button class="btn">Save</button></form><?php endif; ?>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['all_roles' => 'See every enquiry in their branches and give them out', 'branch_roles' => 'Managers: their branches\' enquiries, give out, import, remove', 'own_roles' => 'Work on their own enquiries'] as $k => $l): ?><h3><?= h($l) ?></h3>
<div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Categories and products (one category a line: “Category: product, product”) <textarea name="categories" rows="6"><?= h($set['categories']) ?></textarea></label>
<label>Lead sources (one group a line: “Group: source, source”) <textarea name="sources" rows="6"><?= h($set['sources']) ?></textarea></label><label>Reasons lost (one a line) <textarea name="lost_reasons" rows="6"><?= h($set['lost_reasons']) ?></textarea></label>
<label>Tax % by default <input name="tax_pct" value="<?= h((string)$set['tax_pct']) ?>"></label><label>Follow-up after a new / moved enquiry (days) <input type="number" name="follow_new" value="<?= (int)$set['follow_new'] ?>"></label>
<label>Follow-up after a quotation (days) <input type="number" name="follow_quoted" value="<?= (int)$set['follow_quoted'] ?>"></label><label>Reference starts with <input name="prefix" value="<?= h($set['prefix']) ?>" maxlength="6"></label>
<label>Digits of a local phone number <input type="number" name="phone_digits" value="<?= (int)$set['phone_digits'] ?>"></label></div><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not found.</div><?php endif;
ui_foot();
