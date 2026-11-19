<?php
// Public: a customer follows their order by the private link we sent them. No sign-in; throttled; noindex; no-store.
// Customer-safe fields only — never prices, payment, internal remarks or staff names.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/crm_orders.php';
module_public('crm');
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$db = pdo(); $v = null; $err = '';
if (!so_public_throttle($db)) { http_response_code(429); $err = 'Too many tries. Please wait a minute.'; }
elseif (!($v = so_public_view($db, (string)($_GET['t'] ?? '')))) { http_response_code(404); $err = 'This link is not valid any more. Ask us for a new one.'; }
$qty = fn($x) => rtrim(rtrim(number_format((float)$x, 3, '.', ''), '0'), '.');
ui_head('Order tracking');
if ($err !== ''): ?><section class="card"><p><?= h($err) ?></p></section><?php else: $keys = array_keys(SO_STAGES); $cur = array_search($v['stage'], $keys, true); ?>
<section class="card"><h2>Order <?= h($v['order']) ?></h2><p><?= h($v['customer']) ?><?= $v['po'] ? ' · PO ' . h($v['po']) : '' ?></p>
<?php if ($v['stage'] === 'cancelled'): ?><p><b>This order was cancelled.</b></p><?php else: ?>
<ol class="row" style="flex-wrap:wrap;list-style:none;padding:0"><?php foreach (array_slice($keys, 0, 6) as $i => $k): ?><li class="btn sm<?= $i <= $cur ? '' : ' ghost' ?>" style="cursor:default"><?= h(SO_STAGES[$k]) ?></li><?php endforeach; ?></ol><?php endif; ?>
<?php if ($v['dispatch_date'] && !in_array($v['stage'], ['delivered', 'cancelled'], true)): ?><p>Dispatch <?= $v['confirmed'] ? 'date agreed' : 'expected' ?>: <?= h($v['dispatch_date']) ?></p><?php endif; ?>
<?php if ($v['remarks'] !== ''): ?><p><?= h($v['remarks']) ?></p><?php endif; ?></section>
<section class="card"><h3>Items</h3><table><tr><th>Item</th><?php if ($v['items'] && $v['items'][0]['ordered'] !== null): ?><th>Ordered</th><th>Sent</th><?php endif; ?></tr>
<?php foreach ($v['items'] as $i): ?><tr><td><?= h($i['description']) ?></td><?php if ($i['ordered'] !== null): ?><td><?= $qty($i['ordered']) ?> <?= h($i['unit']) ?></td><td><?= $qty($i['dispatched']) ?></td><?php endif; ?></tr><?php endforeach; ?></table></section>
<?php if ($v['shipments']): ?><section class="card"><h3>Consignments</h3><?php foreach ($v['shipments'] as $s): ?><p><b><?= h($s['carrier']) ?></b> · <?= h($s['ref']) ?> · sent <?= h($s['date']) ?> · <?= h($s['status']) ?><?= $s['delivered_at'] ? ' ' . h(substr($s['delivered_at'], 0, 10)) : '' ?>
<?php if ($s['track']): ?> · <a href="<?= h($s['track']['url']) ?>" rel="noopener noreferrer" target="_blank">Track with <?= h($s['carrier']) ?></a><?= $s['track']['copy'] ? ' (enter the number there)' : '' ?><?php endif; ?></p><?php endforeach; ?></section><?php endif; ?>
<section class="card"><h3>Updates</h3><?php foreach (array_reverse($v['timeline']) as $e): ?><div><?= h(substr($e['at'], 0, 16)) ?> — <?= h($e['to_v']) ?></div><?php endforeach; ?></section>
<?php endif; ui_foot();
