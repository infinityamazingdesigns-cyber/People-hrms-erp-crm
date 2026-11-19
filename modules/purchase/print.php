<?php
// A purchase / job-work order to print or save as PDF (browser print, A4). An issued order prints from the header and
// vendor frozen at issue; before that it carries a stamp.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/purchase.php';
$u = module_require('purchase');
$db = pdo();
$o = pur_ord_get($db, (int)($_GET['id'] ?? 0));
if (!$o || !pur_ord_can_see($u, $o)) { http_response_code(404); exit('Not found'); }
header('Cache-Control: no-store');
$issued = $o['status'] === 'issued';
$h = $issued ? (json_decode((string)$o['head'], true) ?: []) + qt_head($db, (int)$o['branch_id']) : qt_head($db, (int)$o['branch_id']);
$v = $issued ? (json_decode((string)$o['vendor_snap'], true) ?: []) : ($o['vendor'] ?: []);
$m = fn($x) => number_format((float)$x, 2); $q3 = fn($x) => rtrim(rtrim(number_format((float)$x, 3, '.', ''), '0'), '.');
$who = function (?int $id) use ($db): string { if (!$id) return ''; $s = $db->prepare('SELECT name FROM users WHERE id = ?'); $s->execute([$id]); return (string)$s->fetchColumn(); };
audit((int)$u['id'], 'pur_ord_print', '#' . $o['id']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title><?= h($o['no'] ?: 'Draft order') ?></title>
<style>@page{size:A4;margin:14mm}body{font:12px/1.45 system-ui,Segoe UI,Arial,sans-serif;color:#111;margin:0}.pg{position:relative;max-width:190mm;margin:auto}.hd{display:flex;justify-content:space-between;border-bottom:2px solid #13265c;padding-bottom:8px}
.hd h1{margin:0;font-size:18px;color:#13265c}.muted{color:#555}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;vertical-align:top}th{background:#f3f4f7}td.n{text-align:right;white-space:nowrap}
.stamp{position:absolute;top:40%;left:10%;font-size:80px;color:rgba(200,0,0,.12);transform:rotate(-25deg);pointer-events:none}.two{display:flex;gap:16px}.two>div{flex:1}h3{margin:14px 0 4px;font-size:13px;color:#13265c}@media print{.np{display:none}}</style></head><body>
<div class="np" style="padding:8px"><button onclick="print()">Print / Save as PDF</button></div>
<div class="pg"><?php if (!$issued): ?><div class="stamp"><?= $o['status'] === 'cancelled' ? 'CANCELLED' : 'NOT ISSUED' ?></div><?php endif; ?>
<div class="hd"><div><h1><?= h((string)$h['legal_name']) ?></h1><div><?= nl2br(h((string)$h['address'])) ?></div></div><div style="text-align:right"><?= ($h['tax_id'] ?? '') !== '' ? h(crm_tax_label()) . ': ' . h($h['tax_id']) . '<br>' : '' ?><?= nl2br(h((string)($h['reg_lines'] ?? ''))) ?></div></div>
<h2 style="text-align:center;margin:10px 0"><?= h(PUR_ORD_KINDS[$o['kind']]) ?></h2>
<div class="two"><div><b>To</b><br><?= h((string)($v['name'] ?? '')) ?><br><?= h(trim(($v['address'] ?? '') . ' ' . ($v['city'] ?? ''))) ?><?= !empty($v['tax_id']) ? '<br>' . h(crm_tax_label()) . ': ' . h($v['tax_id']) : '' ?><?= !empty($v['contact']) ? '<br>Attn: ' . h($v['contact']) : '' ?></div>
<div><b>Number</b> <?= h($o['no'] ?: '—') ?><br><b>Date</b> <?= h((string)($o['d'] ?: '')) ?><br><b>Deliver to</b> <?= h($o['deliver_to'] !== '' ? $o['deliver_to'] : (string)$h['address']) ?></div></div>
<table><tr><th>#</th><th>Item</th><th>Quantity</th><th>Rate</th><th>Tax</th><th>Deliver by</th><th>Amount</th></tr>
<?php foreach ($o['lines'] as $l): ?><tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['item']) ?></td><td class="n"><?= $q3($l['qty']) ?> <?= h($l['uom']) ?></td><td class="n"><?= $m($l['rate']) ?></td><td class="n"><?= (float)$l['tax_pct'] ?>%</td><td><?= h((string)$l['deliv_date']) ?></td><td class="n"><?= $m($l['taxable']) ?></td></tr><?php endforeach; ?>
<tr><td colspan="6" class="n">Before tax</td><td class="n"><?= $m($o['sub']) ?></td></tr><?php foreach ($o['parts'] as [$pl, $pa]): ?><tr><td colspan="6" class="n"><?= h($pl) ?></td><td class="n"><?= $m($pa) ?></td></tr><?php endforeach; ?>
<?php if ((float)$o['round_off']): ?><tr><td colspan="6" class="n">Round off</td><td class="n"><?= $m($o['round_off']) ?></td></tr><?php endif; ?><tr><td colspan="6" class="n"><b>Total</b></td><td class="n"><b><?= $m($o['total']) ?></b></td></tr></table>
<p><b>Amount in words:</b> <?= h(qt_words((float)$o['total'])) ?></p>
<?php if ($t = array_values(array_filter(array_map('trim', explode("\n", (string)$o['terms']))))): ?><h3>Terms</h3><ol><?php foreach ($t as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ol><?php endif; ?>
<div class="two" style="margin-top:28px"><div class="muted">Prepared by <?= h($who((int)$o['created_by'])) ?><?= $o['mgr_by'] ? '<br>Approved by ' . h($who((int)$o['mgr_by'])) : '' ?><?= $o['ceo_by'] ? '<br>Approved by ' . h($who((int)$o['ceo_by'])) . ' (CEO)' : '' ?></div><div style="text-align:right">For <?= h((string)$h['legal_name']) ?><br><br><br>Authorised signatory</div></div></div></body></html>
