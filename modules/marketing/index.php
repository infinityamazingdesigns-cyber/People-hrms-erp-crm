<?php
// Marketing & ROAS — dashboard (ROAS by campaign), campaigns (+ one campaign: links, spend, leads, posts), spend import,
// leads (add, import, link, hook key), consent per number, orders → campaigns, content plan, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/marketing.php';
$u = module_require('marketing');
$db = pdo(); $set = mk_settings(); $R = mk_rights($u); $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN');
$view = (string)($_GET['view'] ?? 'dash'); $today = date('Y-m-d');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : $today;
if (!$R['view']) { ui_head('Marketing & ROAS', $u, 'marketing'); echo '<div class="card">Your role has no access to marketing.</div>'; ui_foot(); exit; }

$readFile = function (): array {
    $t = $_FILES['f']['tmp_name'] ?? '';
    if (is_string($t) && $t !== '' && is_uploaded_file($t)) { $rows = tbl_read($t, (string)$_FILES['f']['name']); @unlink($t); return $rows; }
    if (trim((string)($_POST['paste'] ?? '')) !== '') return array_map(fn($l) => str_getcsv($l, str_contains($l, "\t") ? "\t" : ','), preg_split('/\r\n|\n|\r/', trim((string)$_POST['paste'])));
    throw new RuntimeException('Pick a file or paste rows.');
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $back = 'index.php?view=c&id=' . $id;
    try {
        switch ($a) {
            case 'cmp': $id = mk_campaign_save($db, $u, $_POST, $id); flash('Saved.'); $back = 'index.php?view=c&id=' . $id; break;
            case 'spend': mk_spend_add($db, $u, $id, $_POST); flash('Spend added.'); break;
            case 'spendoff': $c = (int)($_POST['cid'] ?? 0); mk_spend_off($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Switched off.'); $back = 'index.php?view=c&id=' . $c; break;
            case 'spendcheck': case 'spendsave':
                if ($a === 'spendcheck') { $_SESSION['mk_spend'] = ['uid' => $uid, 'at' => time(), 'rows' => $readFile(), 'mdy' => !empty($_POST['mdy'])]; flash('Checked — nothing saved yet.'); }
                else { $p = $_SESSION['mk_spend'] ?? null; if (!$p || $p['uid'] !== $uid || time() - $p['at'] > 3600) throw new RuntimeException('Check the file again.');
                       $n = mk_spend_import($db, $u, $p['rows'], $p['mdy'], true); unset($_SESSION['mk_spend']); flash("Saved: {$n['new']} new, {$n['replaced']} replaced, {$n['same']} unchanged."); }
                $back = 'index.php?view=spend'; break;
            case 'lead':
                $cid = (int)($_POST['campaign_id'] ?? 0); if ($cid && (!($c = mk_campaign($db, $cid)) || !mk_can($u, $c))) throw new RuntimeException('Campaign not found.');
                [$lid] = mk_lead_add($db, ['campaign_id' => $cid] + $_POST, 'staff', $uid); flash('Lead recorded.'); $back = 'index.php?view=leads'; break;
            case 'leadcheck': case 'leadsave':
                $back = 'index.php?view=leads';
                if ($a === 'leadcheck') { [$rows, $bad] = mk_lead_rows($readFile(), !empty($_POST['mdy'])); $_SESSION['mk_leads'] = ['uid' => $uid, 'at' => time(), 'rows' => $rows, 'bad' => $bad, 'kind' => (string)($_POST['kind'] ?? 'import'), 'cid' => (int)($_POST['campaign_id'] ?? 0)]; flash('Checked — nothing saved yet.'); break; }
                $p = $_SESSION['mk_leads'] ?? null; if (!$p || $p['uid'] !== $uid || time() - $p['at'] > 3600) throw new RuntimeException('Check the file again.');
                if ($p['cid'] && (!($c = mk_campaign($db, $p['cid'])) || !mk_can($u, $c))) throw new RuntimeException('Campaign not found.');
                $n = 0; foreach ($p['rows'] as $r) { [, $new] = mk_lead_add($db, ['kind' => $p['kind'], 'campaign_id' => $p['cid'], 'lead_at' => $r['date'] ? $r['date'] . ' 09:00:00' : ''] + $r, 'import', $uid); $n += (int)$new; }
                unset($_SESSION['mk_leads']); flash("Saved $n leads."); break;
            case 'leadlink': mk_lead_link($db, $u, $id, (int)($_POST['campaign_id'] ?? 0)); flash('Linked — the name is remembered for next time.'); $back = 'index.php?view=leads'; break;
            case 'hookkey': $_SESSION['mk_key_once'] = mk_hook_key_new($db, $u); flash('New key made — the old one stopped.'); $back = 'index.php?view=leads#hook'; break;
            case 'consent': mk_consent_set($db, $u, (string)($_POST['phone'] ?? ''), (string)($_POST['answer'] ?? ''), (string)($_POST['how'] ?? ''), (string)($_POST['note'] ?? '')); flash('Recorded.'); $back = 'index.php?view=consent&phone=' . urlencode(crm_phone_norm((string)$_POST['phone'])); break;
            case 'attr': mk_attr_set($db, $u, $id, (int)($_POST['campaign_id'] ?? 0)); flash('Order linked.'); $back = 'index.php?view=orders&from=' . urlencode($from) . '&to=' . urlencode($to); break;
            case 'post': mk_post_save($db, $u, $_POST, $id); flash('Saved.'); $back = 'index.php?view=posts'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = mk_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['marketing'] ?? ['roles' => module_roles('marketing')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['marketing', 1, implode(',', $st['roles'] ?: module_roles('marketing')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'marketing'); flash('Settings saved.'); $back = 'index.php?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'cmp') { $_SESSION['mk_form'] = $_POST; $back = 'index.php?view=' . ($id ? 'edit&id=' . $id : 'new'); } }
    redirect($back);
}

$cmps = mk_campaigns($db, $u); $cmpOpts = array_column(array_map(fn($c) => ['id' => $c['id'], 'l' => $c['ref'] . ' · ' . $c['name']], $cmps), 'l', 'id');
$money = fn($v) => $v === null ? '—' : number_format((float)$v, 2);
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$chans = array_map(fn($c) => $c[0], MK_CHANNELS); $bn = array_column(branches(), 'name', 'id');
$period = fn(string $v) => '<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="' . h($v) . '">From <input type="date" name="from" value="' . h($from) . '"> to <input type="date" name="to" value="' . h($to) . '"><button class="btn sm">Show</button></form>';

if ($view === 'csv') {
    $rep = mk_report($db, $u, $from, $to);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="marketing-' . $from . '-' . $to . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('mk_csv_cell', ['Code', 'Campaign', 'Channel', 'Status', 'Spend', 'Impressions', 'Clicks', 'Leads', 'Orders', 'Order value before tax', 'ROAS', 'Cost per lead', 'Cost per order', 'CTR %'])) . "\r\n";
    foreach ($rep['rows'] as $c) echo implode(',', array_map('mk_csv_cell', [$c['ref'], $c['name'], $chans[$c['channel']] ?? $c['channel'], $c['status'], $c['spend'], $c['impr'], $c['clicks'], $c['leads'], $c['orders'], $c['value'], $c['m']['roas'], $c['m']['cpl'], $c['m']['cpo'], $c['m']['ctr']])) . "\r\n";
    audit($uid, 'mk_export', 'csv'); exit;
}
if ($view === 'yescsv' && $R['all']) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="consent-yes.csv"');
    echo "\xEF\xBB\xBFNumber,Since\r\n"; foreach (mk_consent_yes($db) as $r) echo mk_csv_cell('+' . $r['phone']) . ',' . mk_csv_cell($r['at']) . "\r\n";
    audit($uid, 'mk_export', 'consent'); exit;
}
if (in_array($view, ['c', 'edit'], true)) { $c = mk_campaign($db, (int)($_GET['id'] ?? 0)); if (!$c || !mk_can($u, $c, $view === 'edit')) { http_response_code(404); $view = 'none'; } }

ui_head('Marketing & ROAS', $u, 'marketing');
$tab = fn(string $k, string $l) => '<a class="btn sm' . ($view === $k ? '' : ' ghost') . '" href="?view=' . $k . '">' . h($l) . '</a> '; ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('dash', 'ROAS') . $tab('campaigns', 'Campaigns') . ($R['own'] ? $tab('spend', 'Ad spend') : '') . $tab('leads', 'Leads') . $tab('consent', 'Consent') . $tab('orders', 'Orders') . $tab('posts', 'Content plan') . ($isAdmin ? $tab('settings', 'Settings') : '') ?></nav>

<?php if ($view === 'dash'): if ($R['own']) mk_attr_sweep($db, date('Y-m-d', strtotime('-' . (int)$set['window_days'] . ' day')));
    $rep = mk_report($db, $u, $from, $to); $t = $rep['total']; echo $period('dash'); ?>
<div class="card row" style="flex-wrap:wrap"><span>Spend <b><?= $money($t['spend']) ?></b></span> · <span>Leads <b><?= (int)$t['leads'] ?></b></span> · <span>Orders <b><?= (int)$t['orders'] ?></b></span> · <span>Order value <b><?= $money($t['value']) ?></b></span>
 · <span>ROAS <b><?= $t['m']['roas'] === null ? '—' : $t['m']['roas'] . '×' ?></b></span> · <span>Cost per lead <b><?= $money($t['m']['cpl']) ?></b></span><?= $rep['share'] !== null ? ' · <span>Campaigns explain <b>' . $rep['share'] . '%</b> of order value</span>' : '' ?></div>
<div class="card"><table><tr><th>Campaign</th><th>Channel</th><th>Spend</th><th>Leads</th><th>Orders</th><th>Value</th><th>ROAS</th><th>CPL</th><th>Cost / order</th><th>CTR</th></tr>
<?php foreach ($rep['rows'] as $c): ?><tr><td><a href="?view=c&id=<?= (int)$c['id'] ?>"><?= h($c['ref']) ?></a> <?= h($c['name']) ?><div class="muted"><?= h($c['status']) ?></div></td><td><?= h($chans[$c['channel']] ?? $c['channel']) ?></td>
<td><?= $money($c['spend']) ?></td><td><?= $c['leads'] ?></td><td><?= $c['orders'] ?><?= $c['other_cur'] ? ' <span class="muted">+' . $c['other_cur'] . ' other currency</span>' : '' ?></td><td><?= $money($c['value']) ?></td>
<td><?= $c['m']['roas'] === null ? '—' : $c['m']['roas'] . '×' ?></td><td><?= $money($c['m']['cpl']) ?></td><td><?= $money($c['m']['cpo']) ?></td><td><?= $c['m']['ctr'] === null ? '—' : $c['m']['ctr'] . '%' ?></td></tr><?php endforeach; ?>
<?php if (!$rep['rows']): ?><tr><td colspan="10" class="muted">No campaigns yet.</td></tr><?php endif; ?></table>
<p class="muted">ROAS = order value before tax ÷ spend (<?= h($rep['currency']) ?> only). Drafts and cancelled orders never count. An order counts for a campaign picked by hand, else the customer's latest lead in the <?= (int)$set['window_days'] ?> days before it.</p>
<a class="btn sm ghost" href="?view=csv&from=<?= h($from) ?>&to=<?= h($to) ?>">Download CSV</a></div>

<?php elseif ($view === 'campaigns'): $all = mk_campaigns($db, $u, !empty($_GET['arch'])); ?>
<div class="card row" style="justify-content:space-between"><span><?= count($all) ?> campaigns <a href="?view=campaigns<?= empty($_GET['arch']) ? '&arch=1' : '' ?>"><?= empty($_GET['arch']) ? 'show archived too' : 'hide archived' ?></a></span><?php if ($R['own']): ?><a class="btn sm" href="?view=new">New campaign</a><?php endif; ?></div>
<div class="card"><table><tr><th>Code</th><th>Name</th><th>Channel</th><th>Status</th><th>Dates</th><th>Budget</th><th>Owner</th><th>Branch</th></tr>
<?php foreach ($all as $c): ?><tr><td><a href="?view=c&id=<?= (int)$c['id'] ?>"><?= h($c['ref']) ?></a></td><td><?= h($c['name']) ?></td><td><?= h($chans[$c['channel']] ?? '') ?></td><td><?= h($c['status']) ?></td>
<td><?= h(trim(($c['start_date'] ?? '') . ' – ' . ($c['end_date'] ?? ''), ' –')) ?></td><td><?= $money($c['budget']) ?></td><td><?= h((string)$c['owner_name']) ?></td><td><?= h($c['branch_id'] ? ($bn[$c['branch_id']] ?? '') : 'Every branch') ?></td></tr><?php endforeach; ?>
<?php if (!$all): ?><tr><td colspan="8" class="muted">No campaigns yet.</td></tr><?php endif; ?></table></div>

<?php elseif (($view === 'new' && $R['own']) || $view === 'edit'):
    $f = $_SESSION['mk_form'] ?? []; unset($_SESSION['mk_form']); $e = $f ?: ($view === 'edit' ? $c : []); $myB = array_intersect_key($bn, array_flip(mk_user_branches($u))); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="cmp"><input type="hidden" name="id" value="<?= (int)($view === 'edit' ? $c['id'] : 0) ?>">
<h2><?= $view === 'edit' ? 'Change ' . h($c['ref']) : 'New campaign' ?></h2>
<div class="grid"><label>Name * <input name="name" value="<?= h((string)($e['name'] ?? '')) ?>" required maxlength="120"></label>
<label>Channel * <select name="channel" required><?= $opts($chans, (string)($e['channel'] ?? ''), 'Pick the channel') ?></select></label>
<label>Objective <select name="objective"><?= $opts(array_combine(MK_OBJECTIVES, MK_OBJECTIVES), (string)($e['objective'] ?? ''), '—') ?></select></label>
<label>Status <select name="status"><?= $opts(array_combine(MK_STATUSES, MK_STATUSES), (string)($e['status'] ?? 'Planned')) ?></select></label>
<label>Branch <select name="branch_id"><?= $opts($myB, (string)($e['branch_id'] ?? ''), 'Every branch') ?></select></label>
<label>Starts <input type="date" name="start_date" value="<?= h((string)($e['start_date'] ?? '')) ?>"></label><label>Ends <input type="date" name="end_date" value="<?= h((string)($e['end_date'] ?? '')) ?>"></label>
<label>Budget <input name="budget" value="<?= h((string)($e['budget'] ?? '')) ?>" inputmode="decimal"></label></div>
<h3>Tracking</h3><div class="grid"><label>Landing page (https://) <input name="landing_url" value="<?= h((string)($e['landing_url'] ?? '')) ?>"></label>
<label>UTM source <input name="utm_source" value="<?= h((string)($e['utm_source'] ?? '')) ?>" placeholder="from the channel"></label><label>UTM medium <input name="utm_medium" value="<?= h((string)($e['utm_medium'] ?? '')) ?>" placeholder="from the channel"></label>
<label>UTM campaign <input name="utm_campaign" value="<?= h((string)($e['utm_campaign'] ?? '')) ?>" placeholder="from the name"></label>
<label>Platform campaign ids (comma separated) <input name="ext_ids" value="<?= h((string)($e['ext_ids'] ?? '')) ?>"></label>
<label>Other names it appears under (comma separated) <input name="match_names" value="<?= h((string)($e['match_names'] ?? '')) ?>"></label></div>
<label>Notes <textarea name="notes" rows="2"><?= h((string)($e['notes'] ?? '')) ?></textarea></label><button class="btn">Save</button></form>

<?php elseif ($view === 'c'):
    $L = mk_links($c, (string)$set['chat_number']); $w = mk_can($u, $c, true);
    $sp = $db->prepare('SELECT s.*, u.name AS who FROM mk_spend s LEFT JOIN users u ON u.id = s.created_by WHERE s.campaign_id = ? ORDER BY s.day DESC, s.id DESC LIMIT 200'); $sp->execute([(int)$c['id']]); $spend = $sp->fetchAll();
    $leads = mk_leads($db, $u, ['campaign' => (int)$c['id']]); $rep = array_values(array_filter(mk_report($db, $u, '2000-01-01', $today)['rows'], fn($r) => (int)$r['id'] === (int)$c['id']))[0] ?? null; ?>
<div class="card"><div class="row" style="justify-content:space-between"><h2><?= h($c['name']) ?> <span class="muted"><?= h($c['ref']) ?></span></h2><?php if ($w): ?><a class="btn sm" href="?view=edit&id=<?= (int)$c['id'] ?>">Change</a><?php endif; ?></div>
<p><?= h(implode(' · ', array_filter([$chans[$c['channel']] ?? '', $c['status'], $c['objective'], $c['branch_id'] ? ($bn[$c['branch_id']] ?? '') : 'Every branch', 'owner ' . $c['owner_name'], $c['budget'] !== null ? 'budget ' . $money($c['budget']) : '']))) ?></p>
<?php if ($rep): ?><p>All time: spend <b><?= $money($rep['spend']) ?></b> · leads <b><?= $rep['leads'] ?></b> · orders <b><?= $rep['orders'] ?></b> · value <b><?= $money($rep['value']) ?></b> · ROAS <b><?= $rep['m']['roas'] === null ? '—' : $rep['m']['roas'] . '×' ?></b></p><?php endif; ?>
<?php if ($L['url']): ?><p>Tracked link: <input readonly value="<?= h($L['url']) ?>" style="width:100%" onclick="this.select()"></p><?php else: ?><p class="muted">Add a landing page to get a tracked link. Put the code <?= h($c['ref']) ?> in ads, forms and messages so leads find this campaign.</p><?php endif; ?>
<?php if ($L['chat']): ?><p>Chat link: <input readonly value="<?= h($L['chat']) ?>" style="width:100%" onclick="this.select()"></p><?php endif; ?>
<?php if ($c['notes'] !== ''): ?><p class="muted"><?= nl2br(h($c['notes'])) ?></p><?php endif; ?></div>
<div class="card"><h3>Spend</h3>
<?php if ($w): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="spend"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
<input type="date" name="day" value="<?= h($today) ?>" max="<?= h($today) ?>" required><input name="amount" placeholder="Amount" inputmode="decimal" required size="8"><input name="impressions" placeholder="Impressions" size="8"><input name="clicks" placeholder="Clicks" size="6"><input name="leads" placeholder="Leads" size="5"><input name="note" placeholder="Note (stall rent, message charges …)"><button class="btn sm">Add</button></form><?php endif; ?>
<table><tr><th>Day</th><th>Amount</th><th>Impr.</th><th>Clicks</th><th>From</th><th></th></tr>
<?php foreach ($spend as $s): ?><tr<?= $s['active'] ? '' : ' class="muted"' ?>><td><?= h($s['day']) ?></td><td><?= $money($s['amount']) ?></td><td><?= h((string)$s['impressions']) ?></td><td><?= h((string)$s['clicks']) ?></td><td><?= $s['src'] === 'import' ? 'Report' : 'Typed' ?> · <?= h((string)$s['who']) ?><?= $s['note'] !== '' ? ' · ' . h($s['note']) : '' ?></td>
<td><?php if (!$s['active']): ?>Off — <?= h($s['off_reason']) ?><?php elseif ($w): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="spendoff"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>"><input name="reason" placeholder="Why" required size="12"><button class="btn sm ghost">Switch off</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$spend): ?><tr><td colspan="6" class="muted">No spend yet.</td></tr><?php endif; ?></table></div>
<div class="card"><h3>Leads</h3><table><tr><th>When</th><th>Name</th><th>Contact</th><th>How</th><th>Enquiry</th></tr>
<?php foreach (array_slice($leads, 0, 100) as $l): ?><tr><td><?= h(substr($l['lead_at'], 0, 16)) ?></td><td><?= h($l['name']) ?></td><td><?= h(trim(($l['phone'] ? '+' . $l['phone'] : '') . ' ' . $l['email'])) ?></td><td><?= h(MK_LEAD_KINDS[$l['kind']][0] ?? '') ?></td>
<td><?= $l['eref'] ? h($l['eref']) . ($l['enquiry_how'] === 'noted' ? ' (noted)' : '') : '—' ?></td></tr><?php endforeach; ?>
<?php if (!$leads): ?><tr><td colspan="5" class="muted">No leads yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'spend' && $R['own']): $p = $_SESSION['mk_spend'] ?? null; if ($p && ($p['uid'] !== $uid || time() - $p['at'] > 3600)) $p = null; $plan = $p ? mk_spend_import($db, $u, $p['rows'], $p['mdy'], false) : null; ?>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="spendcheck"><h2>Import ad spend</h2>
<p class="muted">A campaign-by-day report from the ad platform (CSV or .xlsx). Columns are found by their titles: campaign (or campaign id), day, amount spent, impressions, clicks, leads, currency. Rows of the same campaign and day add up. Only <?= h($set['currency']) ?> is read. A report brought in again replaces that day's line; the old line is kept switched off. Checking writes nothing.</p>
<input type="file" name="f" accept=".csv,.xlsx,.txt"><textarea name="paste" rows="4" placeholder="…or paste rows here"></textarea><label><input type="checkbox" name="mdy" value="1"> Dates are month first (03/31/2026)</label> <button class="btn sm">Check</button></form>
<?php if ($plan): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="spendsave">
<p><?= $plan['new'] ?> new · <?= $plan['replaced'] ?> replace a line · <?= $plan['same'] ?> unchanged<?= $plan['other'] ? ' · ' . $plan['other'] . ' rows in another currency left out' : '' ?></p>
<?php if ($plan['unmatched']): ?><p><b>No campaign found for:</b> <?= h(implode(', ', $plan['unmatched'])) ?> — add the name or platform id to a campaign, then check again.</p><?php endif; ?>
<?php if ($plan['notRead']): ?><p class="muted">Not read: <?= h(implode(' · ', array_map(fn($k, $v) => is_int($k) ? "line $k: $v" : $v, array_keys($plan['notRead']), $plan['notRead']))) ?></p><?php endif; ?>
<table><tr><th>Campaign</th><th>Day</th><th>Amount</th><th>Clicks</th><th></th></tr><?php foreach (array_slice($plan['lines'], 0, 300) as $l): ?><tr><td><?= h($cmpOpts[$l['campaign_id_here']] ?? '') ?></td><td><?= h($l['day']) ?></td><td><?= $money($l['amount']) ?></td><td><?= h((string)$l['clicks']) ?></td><td><?= h(['new' => 'New', 'replaced' => 'Replaces', 'same' => 'Same'][$l['state']]) ?></td></tr><?php endforeach; ?></table>
<button class="btn">Save</button></form><?php endif; ?>

<?php elseif ($view === 'leads'): $lf = ['campaign' => (string)($_GET['campaign'] ?? ''), 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '']; $leads = mk_leads($db, $u, $lf);
    $p = $_SESSION['mk_leads'] ?? null; if ($p && ($p['uid'] !== $uid || time() - $p['at'] > 3600)) $p = null; $kinds = array_map(fn($k) => $k[0], MK_LEAD_KINDS); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="leads"><select name="campaign"><?= $opts(['0' => 'No campaign'] + $cmpOpts, $lf['campaign'], 'Every campaign') ?></select>From <input type="date" name="from" value="<?= h((string)$lf['from']) ?>"> to <input type="date" name="to" value="<?= h((string)$lf['to']) ?>"><button class="btn sm">Show</button></form>
<div class="card"><table><tr><th>When</th><th>Name</th><th>Contact</th><th>How</th><th>Campaign</th><th>Enquiry</th></tr>
<?php foreach ($leads as $l): ?><tr><td><?= h(substr($l['lead_at'], 0, 16)) ?></td><td><?= h($l['name']) ?></td><td><?= h(trim(($l['phone'] ? '+' . $l['phone'] : '') . ' ' . $l['email'])) ?></td><td><?= h($kinds[$l['kind']] ?? '') ?> <span class="muted"><?= h($l['via']) ?></span></td>
<td><?php if ($l['campaign_id']): ?><a href="?view=c&id=<?= (int)$l['campaign_id'] ?>"><?= h($l['cref']) ?></a><?php elseif ($R['own'] && $cmpOpts): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="leadlink"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><?= $l['seen'] !== '' ? '<span class="muted">' . h($l['seen']) . '</span>' : '' ?><select name="campaign_id"><?= $opts($cmpOpts) ?></select><button class="btn sm ghost">Link</button></form><?php else: ?>—<?php endif; ?></td>
<td><?= $l['eref'] ? h($l['eref']) . ($l['enquiry_how'] === 'noted' ? ' (noted)' : '') : '—' ?></td></tr><?php endforeach; ?>
<?php if (!$leads): ?><tr><td colspan="6" class="muted">No leads here.</td></tr><?php endif; ?></table></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="lead"><h3>Add a lead</h3><div class="grid"><label>Name <input name="name"></label><label>Mobile <input name="phone" inputmode="tel"></label><label>Email <input type="email" name="email"></label>
<label>How <select name="kind"><?= $opts($kinds, 'call') ?></select></label><label>Campaign <select name="campaign_id"><?= $opts($cmpOpts, '', 'Find it from the text below') ?></select></label><label>Campaign name / code as given <input name="campaign"></label></div>
<p class="muted"><?= $set['lead_enquiry'] && module_on('enquiries') ? 'A new lead becomes an enquiry, or a note on the open enquiry of the same number or mail.' : 'Leads are not passed to the enquiry register (switched off, or the register is off).' ?> No message text is kept.</p><button class="btn sm">Add</button></form>
<?php if ($R['own']): ?><form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="leadcheck"><h3>Import a lead list</h3>
<p class="muted">Columns by title: name, mobile, email, campaign, date. Checking writes nothing.</p><div class="row" style="flex-wrap:wrap"><input type="file" name="f" accept=".csv,.xlsx,.txt"><select name="kind"><?= $opts($kinds, 'import') ?></select><select name="campaign_id"><?= $opts($cmpOpts, '', 'Campaign from each row') ?></select><label><input type="checkbox" name="mdy" value="1"> Month-first dates</label></div>
<textarea name="paste" rows="3" placeholder="…or paste rows here"></textarea><button class="btn sm">Check</button></form>
<?php if ($p): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="leadsave"><p><?= count($p['rows']) ?> leads read<?= $p['bad'] ? ' · not read: ' . h(implode(', ', array_map(fn($k, $v) => is_int($k) ? "line $k ($v)" : $v, array_keys($p['bad']), $p['bad']))) : '' ?></p>
<table><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Campaign</th><th>Date</th></tr><?php foreach (array_slice($p['rows'], 0, 200) as $r): ?><tr><td><?= h($r['name']) ?></td><td><?= h($r['phone']) ?></td><td><?= h($r['email']) ?></td><td><?= h($r['campaign']) ?></td><td><?= h((string)$r['date']) ?></td></tr><?php endforeach; ?></table><button class="btn">Save</button></form><?php endif; ?>
<?php endif; if ($R['all']): $k = mk_hook_key_info($db); $once = $_SESSION['mk_key_once'] ?? ''; unset($_SESSION['mk_key_once']); ?>
<div class="card" id="hook"><h3>Lead hook for website forms</h3><p>Forms post to <code><?= h(wn_site_url()) ?>/modules/marketing/lead.php</code> with the header <code>Authorization: Bearer &lt;key&gt;</code> and fields name, phone, email, campaign (or utm_campaign / utm_id), kind, id (their own reference, so a repeat adds nothing). JSON or a normal form post.</p>
<?php if ($once !== ''): ?><p><b>Key (shown once — copy it now):</b> <input readonly value="<?= h($once) ?>" style="width:100%" onclick="this.select()"></p><?php elseif ($k): ?><p class="muted">A key ending …<?= h($k['hint']) ?> is in use, made by <?= h((string)$k['name']) ?> on <?= h(substr($k['made_at'], 0, 10)) ?>. Only a fingerprint of it is kept.</p><?php else: ?><p class="muted">No key yet — the hook refuses every call.</p><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="hookkey"><button class="btn sm ghost"><?= $k ? 'Make a new key (the old one stops)' : 'Make a key' ?></button></form></div><?php endif; ?>

<?php elseif ($view === 'consent'): $ph = crm_phone_norm((string)($_GET['phone'] ?? '')); $rows = $ph !== '' ? mk_consent_get($db, $ph) : []; $state = mk_consent_state($rows); ?>
<form class="card row"><input type="hidden" name="view" value="consent"><input name="phone" value="<?= h($ph) ?>" placeholder="Mobile number with country code" inputmode="tel"><button class="btn sm">Look up</button><?php if ($R['all']): ?> <a class="btn sm ghost" href="?view=yescsv">Download the “yes” list</a><?php endif; ?></form>
<?php if ($ph !== ''): ?><div class="card"><h3>+<?= h($ph) ?> — <?= $state === 'yes' ? 'agreed to marketing messages' : ($state === 'no' ? 'said no' : 'never asked') ?></h3>
<form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="consent"><input type="hidden" name="phone" value="<?= h($ph) ?>"><select name="answer"><?= $opts(['yes' => 'Yes, they agree', 'no' => 'No / stop']) ?></select><select name="how"><?= $opts(array_combine(MK_CONSENT_HOW, MK_CONSENT_HOW)) ?></select><input name="note" placeholder="Note"><button class="btn sm">Record</button></form>
<table><tr><th>When</th><th>Answer</th><th>How</th><th>Note</th><th>By</th></tr><?php foreach ($rows as $r): ?><tr><td><?= h(substr($r['at'], 0, 16)) ?></td><td><?= h($r['answer']) ?></td><td><?= h($r['how']) ?></td><td><?= h($r['note']) ?></td><td><?= h((string)$r['by_name']) ?></td></tr><?php endforeach; ?></table>
<p class="muted">The newest answer counts. Earlier answers are kept. Never message a number that was not asked or said no.</p></div><?php endif; ?>

<?php elseif ($view === 'orders'): if ($R['own']) mk_attr_sweep($db, date('Y-m-d', strtotime('-' . (int)$set['window_days'] . ' day'))); $os = mk_orders($db, $from, $to); echo $period('orders'); ?>
<div class="card"><table><tr><th>Order</th><th>Customer</th><th>Value before tax</th><th>Campaign</th><th></th></tr>
<?php foreach ($os as $o): ?><tr><td><?= h($o['order_no']) ?><div class="muted"><?= h(substr($o['at'], 0, 10)) ?></div></td><td><?= h($o['customer']) ?></td><td><?= $money($o['order_value']) ?> <?= h($o['currency']) ?></td>
<td><?= $o['cref'] ? h($o['cref'] . ' · ' . $o['cname']) : 'No campaign' ?><div class="muted"><?= $o['how'] === 'hand' ? 'picked by hand' : ($o['how'] === 'auto' ? 'from the latest lead' : '') ?></div></td>
<td><?php if ($R['own']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="attr"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="campaign_id"><?= $opts(['0' => 'No campaign'] + $cmpOpts, (string)($o['campaign_id'] ?? '0')) ?></select><button class="btn sm ghost">Set</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$os): ?><tr><td colspan="5" class="muted">No orders in this period.</td></tr><?php endif; ?></table><p class="muted">A pick by hand (including “No campaign”) always wins. Every change is kept.</p></div>

<?php elseif ($view === 'posts'): $pf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $from : date('Y-m-01'); $pt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $to : date('Y-m-t', strtotime('+1 month'));
    $posts = mk_posts($db, $pf, $pt); $edit = null; foreach ($posts as $p) if ((int)$p['id'] === (int)($_GET['id'] ?? 0)) $edit = $p; $e = $edit ?? []; ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="posts">From <input type="date" name="from" value="<?= h($pf) ?>"> to <input type="date" name="to" value="<?= h($pt) ?>"><button class="btn sm">Show</button></form>
<div class="card"><table><tr><th>Date</th><th>Post</th><th>Channel</th><th>Campaign</th><th>Status</th><th>Owner</th></tr>
<?php foreach ($posts as $p): ?><tr><td><?= h((string)($p['due_date'] ?? 'No date')) ?></td><td><?= $R['own'] ? '<a href="?view=posts&id=' . (int)$p['id'] . '&from=' . h($pf) . '&to=' . h($pt) . '">' . h($p['title']) . '</a>' : h($p['title']) ?><?= $p['link'] !== '' ? ' · <a href="' . h($p['link']) . '" rel="noopener" target="_blank">open</a>' : '' ?></td>
<td><?= h($chans[$p['channel']] ?? '') ?></td><td><?= h((string)$p['cref']) ?></td><td><?= h($p['status']) ?></td><td><?= h((string)$p['owner_name']) ?></td></tr><?php endforeach; ?>
<?php if (!$posts): ?><tr><td colspan="6" class="muted">Nothing planned in this period.</td></tr><?php endif; ?></table></div>
<?php if ($R['own']): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="post"><input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>"><h3><?= $edit ? 'Change the post' : 'Plan a post' ?></h3>
<div class="grid"><label>Title * <input name="title" value="<?= h((string)($e['title'] ?? '')) ?>" required></label><label>Channel <select name="channel"><?= $opts($chans, (string)($e['channel'] ?? 'social_page')) ?></select></label>
<label>Date <input type="date" name="due_date" value="<?= h((string)($e['due_date'] ?? '')) ?>"></label><label>Status <select name="status"><?= $opts(array_combine(MK_POST_STATUSES, MK_POST_STATUSES), (string)($e['status'] ?? 'Planned')) ?></select></label>
<label>Campaign <select name="campaign_id"><?= $opts($cmpOpts, (string)($e['campaign_id'] ?? ''), '—') ?></select></label><label>Link once published (https://) <input name="link" value="<?= h((string)($e['link'] ?? '')) ?>"></label></div>
<label>Notes <textarea name="notes" rows="2"><?= h((string)($e['notes'] ?? '')) ?></textarea></label><button class="btn sm">Save</button></form><?php endif; ?>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['all_roles' => 'Every campaign in their branches, the lead hook key, the consent list', 'own_roles' => 'Make and change their own campaigns, spend, leads, posts', 'view_roles' => 'Read campaigns and ROAS, record leads and consent'] as $k => $l): ?><h3><?= h($l) ?></h3>
<div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Order counts for a lead's campaign within (days) <input type="number" name="window_days" value="<?= (int)$set['window_days'] ?>" min="1" max="365"></label>
<label>Currency of spend and orders <input name="currency" value="<?= h($set['currency']) ?>" maxlength="3"></label><label>Lead hook calls allowed per address every 15 min <input type="number" name="hook_per_15min" value="<?= (int)$set['hook_per_15min'] ?>"></label>
<label>Chat number for campaign chat links (digits with country code) <input name="chat_number" value="<?= h($set['chat_number']) ?>"></label></div>
<label><input type="checkbox" name="lead_enquiry" value="1"<?= $set['lead_enquiry'] ? ' checked' : '' ?>> Pass new leads to the enquiry register</label><br><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not found.</div><?php endif;
ui_foot();
