<?php
// A quotation to print or save as PDF (browser print, A4): header, customer, lines, tax by the country's split,
// total in words, bank / payment details, terms; an introduction page when ticked. A draft carries a stamp.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/quotes.php';
$u = module_require('quotes');
$db = pdo();
try { $q = qt_get($db, (int)($_GET['id'] ?? 0)); } catch (Throwable $e) { $q = null; }
if (!$q || !qt_can($u, $q)) { http_response_code(404); exit('Not found'); }
header('Cache-Control: no-store');
$h = $q['head'] ? (json_decode((string)$q['head'], true) ?: []) + qt_head($db, $q['branch_id'] !== null ? (int)$q['branch_id'] : null) : qt_head($db, $q['branch_id'] !== null ? (int)$q['branch_id'] : null);
$cu = $q['customer']; $pack = qt_tax_pack(); $m = fn($v) => number_format((float)$v, 2); $qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
$contact = null; foreach ($cu['contacts'] as $c) if ((int)$c['id'] === (int)$q['contact_id']) $contact = $c;
$stamp = ['Draft' => 'DRAFT', 'Revised' => 'REVISED', 'Lost' => '', 'Won' => '', 'Sent' => ''][$q['status']] ?? '';
audit((int)$u['id'], 'qt_print', '#' . $q['id']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title><?= h(qt_display_no($q)) ?></title>
<style>@page{size:A4;margin:14mm}body{font:12px/1.45 system-ui,Segoe UI,Arial,sans-serif;color:#111;margin:0}.pg{position:relative;max-width:190mm;margin:auto}.hd{display:flex;justify-content:space-between;border-bottom:2px solid #13265c;padding-bottom:8px}
.hd h1{margin:0;font-size:18px;color:#13265c}.muted{color:#555}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;vertical-align:top}th{background:#f3f4f7}td.n{text-align:right;white-space:nowrap}
.stamp{position:absolute;top:40%;left:10%;font-size:90px;color:rgba(200,0,0,.12);transform:rotate(-25deg);pointer-events:none}.intro{page-break-after:always;white-space:pre-line}.two{display:flex;gap:16px}.two>div{flex:1}h3{margin:14px 0 4px;font-size:13px;color:#13265c}
@media print{.np{display:none}}</style></head><body><div class="np" style="padding:8px"><button onclick="print()">Print / Save as PDF</button></div>
<?php $top = function () use ($h) { ?><div class="hd"><div><h1><?= h($h['legal_name']) ?></h1><?= $h['tagline'] !== '' ? '<div class="muted">' . h($h['tagline']) . '</div>' : '' ?><div><?= nl2br(h($h['address'])) ?></div></div>
<div style="text-align:right"><?= $h['tax_id'] !== '' ? h(crm_tax_label()) . ': ' . h($h['tax_id']) . '<br>' : '' ?><?= nl2br(h($h['reg_lines'])) ?><br><?= h(implode(' · ', array_filter([$h['sales_phone'], $h['sales_email'], $h['website']]))) ?></div></div><?php }; ?>
<?php if ((int)$q['show_intro'] && trim($h['intro']) !== ''): ?><div class="pg intro"><?php $top(); ?><h3>Introduction</h3><?= h($h['intro']) ?></div><?php endif; ?>
<div class="pg"><?php if ($stamp): ?><div class="stamp"><?= $stamp ?></div><?php endif; ?><?php $top(); ?>
<h2 style="text-align:center;margin:10px 0">Quotation</h2>
<div class="two"><div><b>To</b><br><?= h($cu['name']) ?><?= $contact ? '<br>Attn: ' . h($contact['name']) . ($contact['designation'] !== '' ? ', ' . h($contact['designation']) : '') : '' ?><br><?= h($q['bill_to']) ?><?= $cu['tax_id'] ? '<br>' . h(crm_tax_label()) . ': ' . h($cu['tax_id']) : '' ?></div>
<div><b>Number</b> <?= h(qt_display_no($q)) ?><br><b>Date</b> <?= h($q['quote_date']) ?><br><b>Valid till</b> <?= h($q['valid_till']) ?><br><b>Deliver to</b> <?= h($q['ship_to']) ?><?= $q['place_region'] !== '' ? '<br><b>Place of supply</b> ' . h((string)($pack['regions'][$q['place_region']] ?? $q['place_region'])) . ' (' . h($q['place_region']) . ')' : '' ?></div></div>
<table><tr><th>#</th><th>Product</th><th>Tax code</th><th>Qty</th><th>Rate</th><th>Disc</th><th>Tax</th><th>Amount</th></tr>
<?php foreach ($q['lines'] as $l): if ($l['product_id'] === null) continue; ?><tr><td><?= (int)$l['line_no'] ?></td><td><b><?= h($l['name']) ?></b><?= $l['sku'] !== '' ? ' <span class="muted">' . h($l['sku']) . '</span>' : '' ?><?= $l['description'] !== '' ? '<br>' . h($l['description']) : '' ?></td><td><?= h($l['tax_code']) ?></td><td class="n"><?= $qty($l['qty']) ?> <?= h($l['unit']) ?></td><td class="n"><?= $m($l['rate']) ?></td><td class="n"><?= (float)$l['discount_pct'] ?>%</td><td class="n"><?= (float)$l['tax_pct'] ?>%</td><td class="n"><?= $m($l['taxable']) ?></td></tr><?php endforeach; ?>
<?php foreach ($q['lines'] as $l): if ($l['product_id'] !== null) continue; ?><tr><td></td><td colspan="5"><?= h($l['name']) ?> <?= h($l['description']) ?></td><td class="n"><?= (float)$l['tax_pct'] ?>%</td><td class="n"><?= $m($l['taxable']) ?></td></tr><?php endforeach; ?>
<tr><td colspan="7" class="n">Before tax</td><td class="n"><?= $m($q['taxable']) ?></td></tr><?php foreach ($q['parts'] as [$lbl, $amt]): ?><tr><td colspan="7" class="n"><?= h($lbl) ?></td><td class="n"><?= $m($amt) ?></td></tr><?php endforeach; ?>
<?php if ((float)$q['round_off']): ?><tr><td colspan="7" class="n">Round off</td><td class="n"><?= $m($q['round_off']) ?></td></tr><?php endif; ?><tr><td colspan="7" class="n"><b>Total (<?= h($q['currency']) ?>)</b></td><td class="n"><b><?= $m($q['total']) ?></b></td></tr></table>
<p><b>Amount in words:</b> <?= h(qt_words((float)$q['total'])) ?></p>
<div class="two"><div><h3>Payment</h3><?= h($q['payment_terms']) ?><?= $q['delivery_terms'] !== '' ? '<h3>Delivery</h3>' . h($q['delivery_terms']) : '' ?><?= $q['note'] !== '' ? '<h3>Note</h3>' . nl2br(h($q['note'])) : '' ?></div>
<div><?= trim($h['bank']) !== '' ? '<h3>Bank & payment details</h3>' . nl2br(h($h['bank'])) : '' ?></div></div>
<?php if ($t = crm_lines($h['terms'])): ?><h3>Terms & conditions</h3><ol><?php foreach ($t as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ol><?php endif; ?>
<p style="margin-top:28px;text-align:right">For <?= h($h['legal_name']) ?><br><br><br>Authorised signatory</p></div></body></html>
