<?php
// Stock part 2 — bills of materials, production, CRM dispatches off stock.
//  - A bill of materials belongs to a finished / semi-finished item: the parts and quantities that make out_qty of it,
//    with a scrap %. Every save is a new revision (A, B … Z, AA); the earlier one stays, switched off. Never a circle.
//  - Production: the plan scales the active BOM and shows stock and shortage (writes nothing); posting brings the made
//    item in (costed from its parts when every part has a cost) and takes the parts out — what was really used may differ.
//  - CRM consignments from the start day (setting) go out of stock once each as Sale / dispatch at the order's branch;
//    a CRM product needs its stock item once (link one, or make a finished-goods item from the product).
declare(strict_types=1);
require_once __DIR__ . '/stock.php';

/* ---------------- pure ---------------- */

/** A → B … Z → AA → AB (like drawing revisions). */
function stk_next_rev(string $r): string {
    if ($r === '') return 'A';
    $s = strtoupper($r); $i = strlen($s) - 1;
    while ($i >= 0) { if ($s[$i] !== 'Z') { $s[$i] = chr(ord($s[$i]) + 1); return $s; } $s[$i] = 'A'; $i--; }
    return 'A' . $s;
}
/** What making $qty takes: each part's quantity × qty ÷ out_qty × (1 + scrap %), to 3 decimals; shortage against $have (item => qty). */
function stk_scale(array $bomLines, float $outQty, float $qty, array $have = []): array {
    $o = [];
    foreach ($bomLines as $l) {
        $need = round((float)$l['qty'] * $qty / ($outQty ?: 1) * (1 + (float)$l['scrap_pct'] / 100), 3);
        $h = (float)($have[(int)$l['item_id']] ?? 0);
        $o[] = ['item_id' => (int)$l['item_id'], 'qty' => $need, 'on_hand' => $h, 'short' => max(0.0, round($need - $h, 3))] + array_intersect_key($l, array_flip(['code', 'name', 'uom', 'std_cost']));
    }
    return $o;
}
/** Does $from (following the active BOMs in $boms: item => [part ids]) reach $target? A circle check, depth-capped. */
function stk_reaches(array $boms, int $from, int $target, int $depth = 0): bool {
    if ($from === $target || $depth > 12) return true;
    foreach ($boms[$from] ?? [] as $p) if (stk_reaches($boms, (int)$p, $target, $depth + 1)) return true;
    return false;
}
/** Cost per unit of a made item from the parts used (item => qty) and their costs (item => cost or null); null when any is unknown. */
function stk_made_cost(array $use, array $costs, float $made): ?float {
    if (!$use || $made <= 0) return null; $t = 0.0;
    foreach ($use as $i => $q) { if (($costs[$i] ?? null) === null) return null; $t += $q * (float)$costs[$i]; }
    return round($t / $made, 2);
}

/* ---------------- database ---------------- */

function stk_bom_active(PDO $db, int $item): ?array { $s = $db->prepare('SELECT * FROM stk_bom WHERE item_id = ? AND active = 1 ORDER BY id DESC LIMIT 1'); $s->execute([$item]); return $s->fetch() ?: null; }
function stk_bom_lines(PDO $db, int $bom): array {
    $s = $db->prepare('SELECT bl.*, i.code, i.name, i.uom, i.kind, i.std_cost FROM stk_bom_line bl JOIN stk_item i ON i.id = bl.item_id WHERE bl.bom_id = ? ORDER BY bl.line_no'); $s->execute([$bom]);
    return $s->fetchAll();
}
/** Every active BOM as item => [part ids] (for the circle check). */
function stk_bom_map(PDO $db): array { $o = []; foreach ($db->query('SELECT b.item_id, l.item_id AS part FROM stk_bom b JOIN stk_bom_line l ON l.bom_id = b.id WHERE b.active = 1') as $r) $o[(int)$r['item_id']][] = (int)$r['part']; return $o; }
function stk_bom_save(PDO $db, array $u, int $item, array $in): int {
    if (!stk_rights($u)['store'] && !stk_rights($u)['produce']) throw new RuntimeException('Your role cannot do this.');
    $it = stk_item_row($db, $item);
    if (!in_array($it['kind'], STK_MAKE, true)) throw new RuntimeException('Only finished and semi-finished items have a bill of materials.');
    $out = stk_q($in['out_qty'] ?? 1) ?? 0; if ($out <= 0) throw new RuntimeException('The bill of materials makes a quantity above zero.');
    $map = stk_bom_map($db); $lines = []; $seen = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        $c = (int)($l['item_id'] ?? 0); if (!$c) continue;
        if ($c === $item) throw new RuntimeException('An item cannot be part of itself.');
        if (isset($seen[$c])) throw new RuntimeException('A part is on two lines — put the total on one line.');
        $seen[$c] = true; $p = stk_item_row($db, $c);
        if (!(int)$p['active']) throw new RuntimeException("{$p['code']} is switched off.");
        $q = round((float)str_replace(',', '', (string)($l['qty'] ?? '')), 4); if ($q <= 0) throw new RuntimeException("{$p['code']}: write the quantity used.");
        $sc = round((float)($l['scrap_pct'] ?? 0), 2); if ($sc < 0 || $sc >= 100) throw new RuntimeException("{$p['code']}: scrap is 0 to 99 %.");
        if (stk_reaches($map, $c, $item)) throw new RuntimeException("{$p['code']} is itself made from {$it['code']} — a bill of materials cannot go round in a circle.");
        $lines[] = [$c, $q, $sc];
    }
    if (!$lines) throw new RuntimeException('Add at least one part.');
    $db->beginTransaction();
    try {
        $l = $db->prepare('SELECT rev FROM stk_bom WHERE item_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'); $l->execute([$item]);
        $rev = stk_next_rev((string)($l->fetchColumn() ?: ''));
        $db->prepare('UPDATE stk_bom SET active = 0, off_why = ? WHERE item_id = ? AND active = 1')->execute(["Replaced by rev $rev", $item]);
        $db->prepare('INSERT INTO stk_bom (item_id, rev, out_qty, note, created_by) VALUES (?,?,?,?,?)')->execute([$item, $rev, $out, mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 300), (int)$u['id']]);
        $bom = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO stk_bom_line (bom_id, line_no, item_id, qty, scrap_pct) VALUES (?,?,?,?,?)');
        foreach ($lines as $n => [$c, $q, $sc]) $ins->execute([$bom, $n + 1, $c, $q, $sc]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'stk_bom', '#' . $item . ' rev ' . $rev);
    return $bom;
}
/** The plan for making $qty at a branch. Writes nothing. */
function stk_prod_plan(PDO $db, array $u, int $item, $qty, int $branch): array {
    stk_need($u, 'produce', $branch);
    $it = stk_item_row($db, $item);
    if (!in_array($it['kind'], STK_MAKE, true)) throw new RuntimeException('Only finished and semi-finished items are produced.');
    $q = stk_q($qty); if ($q === null || $q <= 0) throw new RuntimeException('Write how many were made.');
    $bom = stk_bom_active($db, $item); $lines = [];
    if ($bom) {
        $bl = stk_bom_lines($db, (int)$bom['id']); $have = [];
        if ($bl) { $s = $db->prepare('SELECT item_id, qty FROM stk_bal WHERE branch_id = ? AND item_id IN (' . implode(',', array_map(fn($l) => (int)$l['item_id'], $bl)) . ')'); $s->execute([$branch]); $have = array_map('floatval', $s->fetchAll(PDO::FETCH_KEY_PAIR)); }
        $lines = stk_scale($bl, (float)$bom['out_qty'], $q, $have);
    }
    return ['item' => $it, 'qty' => $q, 'branch_id' => $branch, 'bom' => $bom, 'lines' => $lines];
}
/** Post production: the made item comes in, the parts go out (the plan's quantities, or what was really used). */
function stk_produce(PDO $db, array $u, array $in): int {
    if ($had = stk_seen($db, (string)($in['request_id'] ?? ''))) return $had;
    $branch = (int)($in['branch_id'] ?? 0);
    $plan = stk_prod_plan($db, $u, (int)($in['item_id'] ?? 0), $in['qty'] ?? '', $branch);
    $use = [];
    if (isset($in['used']) && is_array($in['used'])) {
        foreach ($in['used'] as $c => $q) { if (trim((string)$q) === '') continue; $x = stk_q($q); if ($x === null || $x < 0) throw new RuntimeException('Quantities used are zero or more.'); if ((int)$c === (int)$plan['item']['id']) throw new RuntimeException('An item cannot be used to make itself.'); if ($x > 0) $use[(int)$c] = ($use[(int)$c] ?? 0) + $x; }
    } else foreach ($plan['lines'] as $l) if ($l['qty'] > 0) $use[$l['item_id']] = $l['qty'];
    $note = mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 500);
    if (!$use && mb_strlen($note) < 4) throw new RuntimeException('No parts are used — write why in the note (for example "assembled from parts already issued").');
    if (!$plan['bom'] && $use && mb_strlen($note) < 4) throw new RuntimeException('This item has no bill of materials yet — write a note on what was used.');
    $costs = []; foreach (array_keys($use) as $c) $costs[$c] = stk_item_row($db, $c)['std_cost'];
    $lines = [['item_id' => (int)$plan['item']['id'], 'qty' => $plan['qty'], 'kind' => 'PRD_IN', 'rate' => stk_made_cost($use, $costs, $plan['qty'])]];
    foreach ($use as $c => $q) $lines[] = ['item_id' => $c, 'qty' => -$q, 'kind' => 'PRD_USE', 'rate' => $costs[$c]];
    return stk_post($db, $u, ['kind' => 'PRD', 'sub' => 'PRD_IN', 'branch_id' => $branch, 'd' => $in['d'] ?? '', 'ref_no' => $in['ref_no'] ?? '', 'note' => $note, 'request_id' => $in['request_id'] ?? '',
                              'out_item_id' => (int)$plan['item']['id'], 'out_qty' => $plan['qty'], 'bom_id' => $plan['bom']['id'] ?? null], $lines);
}

/** CRM consignments waiting to come off stock (dispatched on or after the start day, at the login's branches). */
function stk_ship_list(PDO $db, array $u): array {
    $from = stk_settings()['ship_from']; $brs = stk_branches($u);
    if ($from === '' || !$brs || !module_on('crm')) return [];
    $in = implode(',', array_map('intval', array_keys($brs)));
    $s = $db->prepare("SELECT s.id, s.dispatch_date, s.ref, c2.name AS carrier, o.id AS order_id, o.order_no, o.branch_id, c.name AS customer
                       FROM crm_shipment s JOIN crm_order o ON o.id = s.order_id JOIN crm_customer c ON c.id = o.customer_id JOIN crm_carrier c2 ON c2.id = s.carrier_id
                       WHERE s.dispatch_date >= ? AND o.branch_id IN ($in) AND NOT EXISTS (SELECT 1 FROM stk_link k WHERE k.src = 'ship' AND k.src_id = s.id AND k.live = 1)
                       ORDER BY s.dispatch_date DESC, s.id DESC LIMIT 300");
    $s->execute([$from]);
    $ln = $db->prepare('SELECT sl.qty, l.product_id, l.description, p.sku, p.name AS product, si.id AS item_id, si.code AS item_code, si.uom AS item_uom,
                        (SELECT qty FROM stk_bal b WHERE b.item_id = si.id AND b.branch_id = ?) AS on_hand
                        FROM crm_shipment_line sl JOIN crm_order_line l ON l.id = sl.line_id JOIN crm_product p ON p.id = l.product_id
                        LEFT JOIN stk_item si ON si.product_id = l.product_id AND si.active = 1 WHERE sl.shipment_id = ? ORDER BY l.line_no');
    $o = [];
    foreach ($s->fetchAll() as $r) { $ln->execute([(int)$r['branch_id'], (int)$r['id']]); $r['lines'] = $ln->fetchAll(); $r['ready'] = !array_filter($r['lines'], fn($l) => !$l['item_id']); $o[] = $r; }
    return $o;
}
/** Link a CRM product to a stock item, or make a finished-goods item from it ($item = 0). */
function stk_link_product(PDO $db, array $u, int $product, int $item): int {
    if (!stk_rights($u)['store']) throw new RuntimeException('Your role cannot do this.');
    $p = $db->prepare('SELECT * FROM crm_product WHERE id = ?'); $p->execute([$product]); $pr = $p->fetch() ?: throw new RuntimeException('That product is not on the list.');
    if (!$item) return stk_item_save($db, $u, ['code' => $pr['sku'], 'name' => $pr['name'], 'kind' => 'FG', 'uom' => strtoupper((string)$pr['unit']), 'tax_code' => $pr['tax_code'], 'grp' => $pr['category'], 'product_id' => $product]);
    stk_item_row($db, $item);
    $x = $db->prepare('SELECT code FROM stk_item WHERE product_id = ? AND id <> ?'); $x->execute([$product, $item]);
    if ($c = $x->fetchColumn()) throw new RuntimeException("That product already belongs to stock item $c.");
    $db->prepare('UPDATE stk_item SET product_id = ? WHERE id = ?')->execute([$product, $item]);
    audit((int)$u['id'], 'stk_link_product', '#' . $product . ' > #' . $item);
    return $item;
}
/** Take consignments off stock: each one its own Goods out document (Sale / dispatch), posted once. */
function stk_ship_post(PDO $db, array $u, array $ids): array {
    $list = array_column(stk_ship_list($db, $u), null, 'id'); $done = 0; $skipped = [];
    foreach (array_unique(array_map('intval', $ids)) as $sid) {
        $r = $list[$sid] ?? null;
        if (!$r) { $skipped[] = "#$sid: not waiting any more"; continue; }
        if (!$r['ready']) { $skipped[] = "{$r['order_no']}: a product has no stock item yet"; continue; }
        $db->beginTransaction();
        try {
            $doc = stk_post($db, $u, ['kind' => 'OUT', 'sub' => 'OUT_SALE', 'branch_id' => (int)$r['branch_id'], 'd' => $r['dispatch_date'], 'ref_no' => $r['order_no'] . ' · ' . $r['carrier'] . ' ' . $r['ref'], 'party' => $r['customer'], 'note' => 'CRM consignment', 'request_id' => so_uuid('')],
                            array_map(fn($x) => ['item_id' => (int)$x['item_id'], 'qty' => -(float)$x['qty'], 'kind' => 'OUT_SALE', 'note' => $x['sku']], $r['lines']), ['own_tx' => false]);
            $db->prepare("INSERT INTO stk_link (src, src_id, doc_id, by_user) VALUES ('ship', ?, ?, ?)")->execute([$sid, $doc, (int)$u['id']]);
            $db->commit(); $done++;
        } catch (PDOException $e) { if ($db->inTransaction()) $db->rollBack(); if ($e->getCode() !== '23000') throw $e; $skipped[] = "{$r['order_no']}: already off stock"; }
        catch (RuntimeException $e) { if ($db->inTransaction()) $db->rollBack(); $skipped[] = "{$r['order_no']}: " . $e->getMessage(); }
    }
    return ['posted' => $done, 'skipped' => $skipped];
}
