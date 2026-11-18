<?php
// A settlement document on letterhead, ready to print / save as PDF. Marked DRAFT until approved.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/leave.php';
require_once WN_ROOT . '/lib/fnf.php';
$u = module_require('fnf');
$db = pdo();
$set = fnf_settings();
$r = fnf_get($db, (int)($_GET['id'] ?? 0));
$doc = (string)($_GET['doc'] ?? 'statement');
$e = $r ? emp_by_code($db, $r['emp_code']) : null;
if (!$r || !isset(FNF_DOCS[$doc]) || !fnf_can($u, 'see', $set, strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) || ($e && !emp_in_scope($u, $e))) { http_response_code(404); exit('Not found'); }
$f = $r['f']; $fig = fnf_figures($db, $f); $L = $fig['leave'];
$M = fn($v) => h(pay_money((float)$v));
$co = org('legal_name') ?: org('name'); $logo = org('logo');
$final = in_array($r['status'], ['approved', 'paid'], true);
audit((int)$u['id'], 'fnf_print', '#' . $r['id'] . ' ' . $doc);
header('Cache-Control: no-store');
$ack = $set['notice_text'] !== '' ? $set['notice_text'] : 'I have received the amount above in full and final settlement of all my dues from ' . $co . ', and I have no further claim on the company.';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h(FNF_DOCS[$doc] . ' · ' . $r['name']) ?></title>
<style>
@page{size:A4;margin:16mm}body{font:14px/1.5 Arial,sans-serif;color:#111;max-width:780px;margin:24px auto;padding:0 18px;background:#fff}
.lh{display:flex;gap:14px;align-items:center;border-bottom:2px solid <?= h(org('color_primary') ?: '#13265C') ?>;padding-bottom:10px;margin-bottom:18px}.lh img{max-height:56px}.lh b{font-size:19px;display:block}.lh small{color:#555}
h1{font-size:18px;text-align:center;margin:8px 0 16px}table{width:100%;border-collapse:collapse;margin:8px 0}td,th{border:1px solid #bbb;padding:5px 8px;text-align:left}td.n{text-align:right}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.sig{display:flex;justify-content:space-between;margin-top:56px}
.stamp{position:fixed;top:40%;left:0;right:0;text-align:center;font:bold 80px Arial;color:rgba(200,0,0,.12);transform:rotate(-20deg);pointer-events:none}.bar{margin-bottom:14px}@media print{.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="print()">Print / save as PDF</button></div>
<?php if (!$final): ?><div class="stamp">DRAFT · NOT APPROVED</div><?php endif; ?>
<div class="lh"><?php if ($logo !== '' && str_starts_with($logo, 'data:image/')): ?><img src="<?= h($logo) ?>" alt=""><?php endif; ?><div><b><?= h($co) ?></b><small><?= h(org('address')) ?></small></div></div>
<?php if ($doc === 'statement'): ?>
<h1>Full and final settlement</h1>
<table><tbody><tr><td>Name</td><td><?= h($r['name']) ?></td><td>Code</td><td><?= h($r['emp_code']) ?></td></tr>
<tr><td>Designation</td><td><?= h($f['designation'] ?? '') ?></td><td>Department</td><td><?= h($f['department'] ?? '') ?></td></tr>
<tr><td>Joined</td><td><?= !empty($f['doj']) ? h(date('j M Y', strtotime($f['doj']))) : '' ?></td><td>Last working day</td><td><?= h(date('j M Y', strtotime($r['lwd']))) ?></td></tr></tbody></table>
<div class="two"><table><thead><tr><th>Earnings</th><th></th></tr></thead><tbody><?php foreach ($fig['lines'] as $l): ?><tr><td><?= h($l['label']) ?></td><td class="n"><?= $M($l['amount']) ?></td></tr><?php endforeach; ?>
<?php foreach ($fig['earn'] as $x): ?><tr><td><?= h($x['label']) ?></td><td class="n"><?= $M($x['amount']) ?></td></tr><?php endforeach; ?><tr><th>Total</th><th class="n"><?= $M($fig['earn_total']) ?></th></tr></tbody></table>
<table><thead><tr><th>Deductions</th><th></th></tr></thead><tbody><?php foreach ($fig['lines'] as $l) if ($l['tax'] > 0): ?><tr><td>Tax by state · <?= h(date('M Y', strtotime($l['ym'] . '-01'))) ?></td><td class="n"><?= $M($l['tax']) ?></td></tr><?php endif; ?>
<?php if ($L['cut'] > 0): ?><tr><td>Leave adjusted (<?= h((string)$L['excess']) ?> days)</td><td class="n"><?= $M($L['cut']) ?></td></tr><?php endif; ?>
<?php foreach ($fig['ded'] as $x): ?><tr><td><?= h($x['label']) ?></td><td class="n"><?= $M($x['amount']) ?></td></tr><?php endforeach; ?><tr><th>Total</th><th class="n"><?= $M($fig['ded_total']) ?></th></tr></tbody></table></div>
<table><tbody><tr><td>Net</td><td class="n"><?= $M($fig['net']) ?></td></tr><?php if ($fig['paid_before']): ?><tr><td>Already paid<?= !empty($f['paid_before_note']) ? ' (' . h($f['paid_before_note']) . ')' : '' ?></td><td class="n"><?= $M($fig['paid_before']) ?></td></tr><?php endif; ?>
<tr><th>Balance payable</th><th class="n"><?= $M($fig['balance']) ?></th></tr></tbody></table>
<p>In words: <b><?= h(fnf_words($fig['balance'], org('currency'))) ?> only</b></p>
<div class="sig"><span>Prepared by</span><span>Checked by</span><span>Approved by</span></div>
<?php else: ?>
<h1>Acknowledgement</h1>
<p>I, <b><?= h($r['name']) ?></b> (<?= h($r['emp_code']) ?>), <?= h($f['designation'] ?? '') ?>, whose last working day was <?= h(date('j F Y', strtotime($r['lwd']))) ?>, acknowledge that I have received <b><?= $M($fig['balance']) ?></b> (<?= h(fnf_words($fig['balance'], org('currency'))) ?> only)<?= $r['paid_ref'] ? ' by ' . h($r['paid_ref']) : '' ?>.</p>
<p><?= nl2br(h($ack)) ?></p>
<div class="sig"><span>Signature<br><br><?= h($r['name']) ?></span><span>Date</span></div>
<?php endif; ?>
</body></html>
