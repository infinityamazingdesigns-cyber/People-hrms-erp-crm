<?php
// Stock & inventory — rules. One item list; every movement is a numbered document posted through stk_post(), which
// writes insert-only ledger lines (stk_move) and moves the running balance (stk_bal) in the same transaction under row
// locks taken in one order. Never below zero unless the setting allows it. Corrections: a reversal document (its own
// number, opposite quantities, today's date, a reason) or a stock count (only the differences are posted). Transfers
// leave the sender at once and wait in transit until the receiving branch takes them in (all, part, or closed short).
declare(strict_types=1);
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/crm_orders.php';   // so_fy(), so_order_no(), so_uuid(), so_date()

const STK_KINDS = ['FG' => 'Finished goods', 'SFG' => 'Semi-finished goods', 'RM' => 'Raw material', 'BO' => 'Bought-out part', 'TR' => 'Traded item', 'CN' => 'Consumable', 'PK' => 'Packing material'];
const STK_MAKE = ['FG', 'SFG'];
/** move kind => [label, sign (+1 in, -1 out, 0 either)] */
const STK_MOVES = [
    'OPEN' => ['Opening stock', 0],
    'IN_PUR' => ['Purchase receipt', 1], 'IN_RET' => ['Customer return', 1], 'IN_JOB' => ['Back from job work', 1], 'IN_OTH' => ['Other receipt', 1],
    'OUT_SALE' => ['Sale / dispatch', -1], 'OUT_ISS' => ['Issue to production / department', -1], 'OUT_SMP' => ['Sample / demo', -1],
    'OUT_JOB' => ['Sent for job work', -1], 'OUT_SCRAP' => ['Scrap / damage', -1], 'OUT_OTH' => ['Other issue', -1],
    'XFR_OUT' => ['Transfer out', -1], 'XFR_IN' => ['Transfer in', 1], 'ADJ' => ['Stock count adjustment', 0],
];
/** document kind => [title, number code, right, move kinds it may carry] */
const STK_DOCS = [
    'IN' => ['Goods in', 'GRN', 'store', ['IN_PUR', 'IN_RET', 'IN_JOB', 'IN_OTH']],
    'OUT' => ['Goods out', 'ISS', 'store', ['OUT_SALE', 'OUT_ISS', 'OUT_SMP', 'OUT_JOB', 'OUT_SCRAP', 'OUT_OTH']],
    'OPN' => ['Opening stock', 'OPN', 'store', ['OPEN']],
    'XFR' => ['Transfer', 'STN', 'store', ['XFR_OUT', 'XFR_IN']],
    'CNT' => ['Stock count', 'ADJ', 'adjust', ['ADJ']],
    'REV' => ['Reversal', 'REV', 'adjust', []],
];
const STK_DOC_STATUS = ['posted' => 'Posted', 'transit' => 'In transit', 'received' => 'Received', 'short' => 'Received short', 'reversed' => 'Reversed'];
const STK_MAX_LINES = 300;
const STK_DEFAULTS = ['view_roles' => ['SUPERADMIN', 'STORE', 'CEO'], 'store_roles' => ['SUPERADMIN', 'STORE'], 'adjust_roles' => ['SUPERADMIN', 'STORE'],
                      'negative' => false, 'lock_until' => '', 'units' => "NOS\nPCS\nSET\nMTR\nKG\nLTR\nBOX\nROLL\nPAIR\nLOT"];
/** Statement columns: key => move kinds. */
const STK_STATEMENT = ['in_pur' => ['IN_PUR'], 'in_xfr' => ['XFR_IN'], 'in_oth' => ['IN_RET', 'IN_JOB', 'IN_OTH'],
                       'out_sale' => ['OUT_SALE'], 'out_use' => ['OUT_ISS'], 'out_xfr' => ['XFR_OUT'], 'out_oth' => ['OUT_SMP', 'OUT_JOB', 'OUT_SCRAP', 'OUT_OTH'], 'adj' => ['ADJ', 'OPEN']];

/* ---------------- pure ---------------- */

/** A quantity from a form: up to 3 decimals; null when not a number. */
function stk_q($v): ?float { $v = trim(str_replace(',', '', (string)$v)); return is_numeric($v) ? round((float)$v, 3) : null; }
function stk_show(float $q): string { $s = rtrim(rtrim(number_format($q, 3, '.', ','), '0'), '.'); return $s === '' || $s === '-0' ? '0' : $s; }
/** Balances after the deltas; the keys whose stock would go below zero on a line that takes stock out. $have/$delta: key => qty. */
function stk_short(array $have, array $lines): array {
    $after = $have; foreach ($lines as $l) $after[$l['key']] = round(($after[$l['key']] ?? 0) + $l['qty'], 3);
    $o = []; foreach ($lines as $l) if ($l['qty'] < 0 && $after[$l['key']] < 0) $o[$l['key']] = ['have' => (float)($have[$l['key']] ?? 0), 'need' => round((float)($have[$l['key']] ?? 0) - $after[$l['key']], 3)];
    return $o;
}
/** Check lines for a document kind: right kind, quantity with the right sign, not too big. Returns cleaned lines or throws. */
function stk_check_lines(string $docKind, array $lines): array {
    if (!$lines) throw new RuntimeException('Add at least one item.');
    if (count($lines) > STK_MAX_LINES) throw new RuntimeException('Up to ' . STK_MAX_LINES . ' lines in one entry.');
    $o = [];
    foreach (array_values($lines) as $i => $l) {
        $n = $i + 1; $mk = (string)($l['kind'] ?? '');
        if (!isset(STK_MOVES[$mk]) || ($docKind !== 'REV' && !in_array($mk, STK_DOCS[$docKind][3] ?? [], true))) throw new RuntimeException("Line $n: wrong kind of entry.");
        $q = stk_q($l['qty'] ?? ''); if ($q === null || $q == 0.0) throw new RuntimeException("Line $n: write the quantity.");
        $sign = STK_MOVES[$mk][1];
        if ($docKind !== 'REV' && $sign !== 0 && ($q > 0) !== ($sign > 0)) throw new RuntimeException("Line $n: the quantity has the wrong sign.");
        if (abs($q) > 99999999) throw new RuntimeException("Line $n: the quantity is too large.");
        $o[] = ['item_id' => (int)($l['item_id'] ?? 0), 'qty' => $q, 'kind' => $mk, 'branch_id' => (int)($l['branch_id'] ?? 0), 'rate' => ($r = trim((string)($l['rate'] ?? ''))) === '' || !is_numeric($r) || (float)$r < 0 ? null : round((float)$r, 2), 'note' => mb_substr(trim((string)($l['note'] ?? '')), 0, 300)];
    }
    return $o;
}
/** Where an item stands: below zero, below its minimum (stock + in transit), nothing, or fine. */
function stk_state(float $qty, float $min, float $transit = 0): string { return $qty < 0 ? 'below_zero' : ($min > 0 && $qty + $transit < $min ? 'below_min' : ($qty == 0.0 ? 'zero' : 'ok')); }
/** What to order or make: the gap to the minimum, at least the reorder quantity; 0 when stock + in transit covers it. */
function stk_suggest(float $qty, float $transit, float $min, float $reorder): float { $gap = round($min - $qty - $transit, 3); return $gap > 0 ? max($gap, $reorder) : 0.0; }
/** A stock count: counted vs books → the adjustment lines (only differences). */
function stk_count_lines(array $books, array $counted): array {
    $o = []; foreach ($counted as $item => $c) { $d = round($c - (float)($books[$item] ?? 0), 3); if ($d != 0.0) $o[] = ['item_id' => (int)$item, 'qty' => $d, 'kind' => 'ADJ', 'note' => 'Books ' . stk_show((float)($books[$item] ?? 0)) . ', counted ' . stk_show($c)]; }
    return $o;
}
/** Receiving a transfer. $open: item => quantity still in transit; $want: item => received now (missing = all of it, or 0 when closing).
 *  Returns [lines [item => qty], short (bool), status]. */
function stk_receive_plan(array $open, array $want, bool $close): array {
    $lines = []; $short = false;
    foreach ($open as $item => $left) {
        $q = array_key_exists($item, $want) ? (float)$want[$item] : ($close ? 0.0 : (float)$left);
        if ($q < 0) throw new RuntimeException('Quantities cannot be below zero.');
        if ($q > (float)$left + 1e-9) throw new RuntimeException('More received than was sent on a line.');
        if ($q > 0) $lines[$item] = $q;
        if (round((float)$left - $q, 3) > 0) $short = true;
    }
    if (!$lines && !$close) throw new RuntimeException('Write what arrived.');
    return [$lines, $short, $short && !$close ? 'transit' : ($short ? 'short' : 'received')];
}
/** Statement rows from ledger lines [item_id, d, qty, kind] for one period: opening, columns of STK_STATEMENT, closing. */
function stk_statement_calc(array $moves, string $from, string $to): array {
    $o = [];
    foreach ($moves as $m) {
        if ($m['d'] > $to) continue;
        $r = &$o[(int)$m['item_id']]; $r ??= ['opening' => 0.0, 'closing' => 0.0] + array_fill_keys(array_keys(STK_STATEMENT), 0.0);
        $q = (float)$m['qty']; $r['closing'] = round($r['closing'] + $q, 3);
        if ($m['d'] < $from) $r['opening'] = round($r['opening'] + $q, 3);
        else foreach (STK_STATEMENT as $k => $kinds) if (in_array($m['kind'], $kinds, true)) { $r[$k] = round($r[$k] + (str_starts_with($k, 'out_') ? -$q : $q), 3); break; }
        unset($r);
    }
    return $o;
}

/* ---------------- settings and rights ---------------- */

function stk_settings(): array { $s = module_states()['stock']['settings'] ?? []; return array_replace(STK_DEFAULTS, array_intersect_key($s, STK_DEFAULTS)); }
function stk_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['view_roles', 'store_roles', 'adjust_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['store_roles']) $e[] = 'Pick at least one role that keeps the store.';
    $o['negative'] = !empty($in['negative']);
    $l = trim((string)($in['lock_until'] ?? ''));
    if ($l !== '' && (!($d = DateTime::createFromFormat('!Y-m-d', $l)) || $d->format('Y-m-d') !== $l)) { $e[] = 'Closed up to: pick a date or leave it empty.'; $l = ''; }
    $o['lock_until'] = $l;
    $o['units'] = implode("\n", array_slice(array_values(array_unique(array_map('strtoupper', crm_lines_s((string)($in['units'] ?? ''))))), 0, 40)) ?: 'NOS';
    return [$o, $e];
}
function crm_lines_s(string $s): array { return array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 12), explode("\n", str_replace("\r", '', $s))), 'strlen'))); }
function stk_rights(array $u): array {
    $r = user_roles((int)$u['id'], !empty($u['is_owner'])); $s = stk_settings(); $h = fn($k) => (bool)array_intersect($r, $s[$k]);
    $store = $h('store_roles'); $adj = $h('adjust_roles');
    return ['view' => $store || $adj || $h('view_roles'), 'store' => $store, 'adjust' => $adj, 'admin' => in_array('SUPERADMIN', $r, true)];
}
/** Branches this login works with: every active branch inside its scope. */
function stk_branches(array $u): array { $o = []; foreach (branches() as $b) if (emp_in_scope($u, ['branch_id' => (int)$b['id']])) $o[(int)$b['id']] = (string)$b['name']; return $o; }
function stk_need(array $u, string $right, int $branch): void {
    if (!(stk_rights($u)[$right] ?? false)) throw new RuntimeException('Your role cannot do this.');
    if (!isset(stk_branches($u)[$branch])) throw new RuntimeException('Pick a branch you work with.');
}

/* ---------------- database ---------------- */

function stk_item_row(PDO $db, int $id): array { $s = $db->prepare('SELECT * FROM stk_item WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: throw new RuntimeException('That item is not on the list.'); }
function stk_branch_code(int $id): string { foreach (branches(false) as $b) if ((int)$b['id'] === $id) return (string)$b['code']; return ''; }
function stk_branch_name(int $id): string { foreach (branches(false) as $b) if ((int)$b['id'] === $id) return (string)$b['name']; return '#' . $id; }
function stk_check_date(?string $d): string {
    $d = so_date($d, 'Date', true);
    if ($d > date('Y-m-d')) throw new RuntimeException('The date cannot be after today.');
    if ($d < '2000-01-01') throw new RuntimeException('That date is too far back.');
    $lock = stk_settings()['lock_until'];
    if ($lock !== '' && $d <= $lock) throw new RuntimeException('Stock is closed up to ' . $lock . '. Pick a later date or ask the Super Admin to open the period.');
    return $d;
}
function stk_next_no(PDO $db, int $branch, string $code, string $d): string {
    $fy = so_fy($d, (int)(org('fy_start_month') ?: 4)); $bc = stk_branch_code($branch); $key = "$code|$bc|$fy";
    $db->prepare('INSERT INTO stk_seq (prefix, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$key]);
    $s = $db->prepare('SELECT n FROM stk_seq WHERE prefix = ?'); $s->execute([$key]);
    return so_order_no($code, $bc, $fy, (int)$s->fetchColumn());
}
/**
 * Post one document. $doc: kind, sub, branch_id, to_branch_id, d, ref_no, party, note, request_id, status, rev_of.
 * $lines: [item_id, qty (signed), kind, rate, note, branch_id (else the doc's)]. $opt: right, negative, own_tx, inactive_ok, date_ok.
 * The same request id returns the document already posted.
 */
function stk_post(PDO $db, array $u, array $doc, array $lines, array $opt = []): int {
    $kind = (string)($doc['kind'] ?? ''); if (!isset(STK_DOCS[$kind])) throw new RuntimeException('Unknown stock entry.');
    $branch = (int)($doc['branch_id'] ?? 0);
    stk_need($u, $opt['right'] ?? STK_DOCS[$kind][2], $branch);
    $rid = so_uuid((string)($doc['request_id'] ?? ''));
    $s = $db->prepare('SELECT id FROM stk_doc WHERE request_id = ?'); $s->execute([$rid]);
    if ($had = (int)$s->fetchColumn()) return $had;
    $d = !empty($opt['date_ok']) ? (string)$doc['d'] : stk_check_date((string)($doc['d'] ?? ''));
    $clean = stk_check_lines($kind, $lines); $items = [];
    foreach ($clean as $i => &$l) {
        $it = $items[$l['item_id']] ??= stk_item_row($db, $l['item_id']);
        if (!(int)$it['active'] && empty($opt['inactive_ok'])) throw new RuntimeException("{$it['code']} · {$it['name']} is switched off.");
        $l['branch_id'] = $l['branch_id'] ?: $branch;
        if ($l['branch_id'] !== $branch && !isset(stk_branches($u)[$l['branch_id']]) && empty($opt['other_branch_ok'])) throw new RuntimeException('Line ' . ($i + 1) . ': a branch you do not work with.');
        $l['key'] = $l['branch_id'] . ':' . $l['item_id'];
    }
    unset($l);
    $neg = !empty($opt['negative']) || stk_settings()['negative'];
    $own = ($opt['own_tx'] ?? true) && !$db->inTransaction(); if ($own) $db->beginTransaction();
    try {
        $keys = array_unique(array_column($clean, 'key')); sort($keys, SORT_NATURAL);   // lock in one order, so two posts never wait on each other
        $mk = $db->prepare('INSERT IGNORE INTO stk_bal (branch_id, item_id, qty) VALUES (?, ?, 0)'); $lk = $db->prepare('SELECT qty FROM stk_bal WHERE branch_id = ? AND item_id = ? FOR UPDATE');
        $have = [];
        foreach ($keys as $k) { [$b, $i] = array_map('intval', explode(':', $k)); $mk->execute([$b, $i]); $lk->execute([$b, $i]); $have[$k] = (float)$lk->fetchColumn(); }
        if (!$neg && ($short = stk_short($have, $clean))) {
            $k = array_key_first($short); [$b, $i] = array_map('intval', explode(':', $k)); $it = $items[$i];
            throw new RuntimeException("Not enough {$it['code']} · {$it['name']} at " . stk_branch_name($b) . ': ' . stk_show($short[$k]['have']) . ' ' . $it['uom'] . ' in stock, ' . stk_show($short[$k]['need']) . ' needed.');
        }
        $no = stk_next_no($db, $branch, STK_DOCS[$kind][1], $d);
        $db->prepare('INSERT INTO stk_doc (doc_no, request_id, kind, sub, branch_id, to_branch_id, d, ref_no, party, note, status, rev_of, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$no, $rid, $kind, (string)($doc['sub'] ?? ''), $branch, (int)($doc['to_branch_id'] ?? 0) ?: null, $d, mb_substr(trim((string)($doc['ref_no'] ?? '')), 0, 120),
                      mb_substr(trim((string)($doc['party'] ?? '')), 0, 160), mb_substr(trim(strip_tags((string)($doc['note'] ?? ''))), 0, 500), (string)($doc['status'] ?? 'posted'), isset($doc['rev_of']) ? (int)$doc['rev_of'] : null, (int)$u['id']]);
        $id = (int)$db->lastInsertId();
        $mv = $db->prepare('INSERT INTO stk_move (doc_id, line_no, branch_id, item_id, d, qty, kind, rate, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $up = $db->prepare('UPDATE stk_bal SET qty = qty + ? WHERE branch_id = ? AND item_id = ?');
        foreach ($clean as $n => $l) { $mv->execute([$id, $n + 1, $l['branch_id'], $l['item_id'], $d, $l['qty'], $l['kind'], $l['rate'], $l['note'], (int)$u['id']]); $up->execute([$l['qty'], $l['branch_id'], $l['item_id']]); }
        if ($own) $db->commit();
    } catch (Throwable $e) { if ($own && $db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'stk_post', '#' . $id . ' ' . $kind . ' lines ' . count($clean));
    return $id;
}
/** The document already posted with this request id (a double tap), or 0. */
function stk_seen(PDO $db, string $rid): int { if ($rid === '') return 0; $s = $db->prepare('SELECT id FROM stk_doc WHERE request_id = ?'); $s->execute([so_uuid($rid)]); return (int)$s->fetchColumn(); }
function stk_has_moves(PDO $db, int $branch, int $item): bool { $s = $db->prepare('SELECT 1 FROM stk_move WHERE branch_id = ? AND item_id = ? LIMIT 1'); $s->execute([$branch, $item]); return (bool)$s->fetchColumn(); }
/** Goods in / goods out / opening stock from a form: one kind for every line. */
function stk_entry(PDO $db, array $u, array $in): int {
    if ($had = stk_seen($db, (string)($in['request_id'] ?? ''))) return $had;
    $kind = (string)($in['kind'] ?? ''); if (!in_array($kind, ['IN', 'OUT', 'OPN'], true)) throw new RuntimeException('Unknown stock entry.');
    $sub = $kind === 'OPN' ? 'OPEN' : (string)($in['sub'] ?? '');
    if (!in_array($sub, STK_DOCS[$kind][3], true)) throw new RuntimeException('Pick what kind of ' . ($kind === 'IN' ? 'receipt' : 'issue') . ' this is.');
    $branch = (int)($in['branch_id'] ?? 0); $lines = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        if (!(int)($l['item_id'] ?? 0) && trim((string)($l['qty'] ?? '')) === '') continue;
        $q = stk_q($l['qty'] ?? ''); if ($q === null || $q <= 0) throw new RuntimeException('Write a quantity above zero on every line.');
        if ($kind === 'OPN' && stk_has_moves($db, $branch, (int)$l['item_id'])) throw new RuntimeException('Opening stock is only for items with no entry at this branch yet — use a stock count to correct it.');
        $lines[] = ['item_id' => (int)$l['item_id'], 'qty' => $kind === 'OUT' ? -$q : $q, 'kind' => $sub, 'rate' => $l['rate'] ?? '', 'note' => $l['note'] ?? ''];
    }
    $ref = trim((string)($in['ref_no'] ?? ''));
    if ($sub === 'OUT_SALE' && $ref === '') throw new RuntimeException('Write the invoice / challan number.');
    if (in_array($sub, ['OUT_SCRAP', 'OUT_OTH', 'IN_OTH'], true) && trim((string)($in['note'] ?? '')) === '') throw new RuntimeException('Write why (note).');
    return stk_post($db, $u, ['kind' => $kind, 'sub' => $sub, 'branch_id' => $branch, 'd' => $in['d'] ?? '', 'ref_no' => $ref, 'party' => $in['party'] ?? '', 'note' => $in['note'] ?? '', 'request_id' => $in['request_id'] ?? ''], $lines);
}
/** Undo a document with a reversal document (reason). A transfer waiting in transit comes back to the sender. */
function stk_reverse(PDO $db, array $u, int $id, string $reason): int {
    if (mb_strlen($reason = trim(strip_tags($reason))) < 4) throw new RuntimeException('Write why this entry is reversed.');
    $d0 = stk_doc_row($db, $id); stk_need($u, 'adjust', (int)$d0['branch_id']);
    if ($d0['kind'] === 'REV') throw new RuntimeException('A reversal cannot be reversed — post the entry again instead.');
    if ($d0['status'] === 'reversed') throw new RuntimeException('This entry is already reversed.');
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT status FROM stk_doc WHERE id = ? FOR UPDATE'); $st->execute([$id]);
        if ($st->fetchColumn() === 'reversed') throw new RuntimeException('This entry is already reversed.');
        $m = $db->prepare('SELECT item_id, branch_id, qty, kind, rate FROM stk_move WHERE doc_id = ? ORDER BY line_no'); $m->execute([$id]);
        $lines = array_map(fn($r) => ['item_id' => (int)$r['item_id'], 'branch_id' => (int)$r['branch_id'], 'qty' => -(float)$r['qty'], 'kind' => $r['kind'], 'rate' => $r['rate'], 'note' => 'Reversal of ' . $d0['doc_no']], $m->fetchAll());
        $rev = stk_post($db, $u, ['kind' => 'REV', 'sub' => $d0['kind'], 'branch_id' => (int)$d0['branch_id'], 'd' => date('Y-m-d'), 'ref_no' => $d0['doc_no'], 'party' => $d0['party'], 'note' => $reason, 'request_id' => so_uuid(''), 'rev_of' => $id],
                        $lines, ['own_tx' => false, 'inactive_ok' => true, 'other_branch_ok' => true]);
        $db->prepare("UPDATE stk_doc SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reverse_doc = ? WHERE id = ?")->execute([(int)$u['id'], $rev, $id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'stk_reverse', '#' . $id . ' > #' . $rev);
    return $rev;
}
function stk_doc_row(PDO $db, int $id): array { $s = $db->prepare('SELECT * FROM stk_doc WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: throw new RuntimeException('That stock entry is not there.'); }
function stk_xfer_send(PDO $db, array $u, array $in): int {
    if ($had = stk_seen($db, (string)($in['request_id'] ?? ''))) return $had;
    $from = (int)($in['branch_id'] ?? 0); $to = (int)($in['to_branch_id'] ?? 0);
    if ($from === $to) throw new RuntimeException('Pick two different branches.');
    if (!in_array($to, array_map(fn($b) => (int)$b['id'], branches()), true)) throw new RuntimeException('Pick the branch the goods go to.');
    $lines = []; $seen = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        if (!(int)($l['item_id'] ?? 0)) continue;
        $q = stk_q($l['qty'] ?? ''); if ($q === null || $q <= 0) throw new RuntimeException('Write a quantity above zero on every line.');
        if (isset($seen[(int)$l['item_id']])) throw new RuntimeException('An item is on two lines — put the total on one line.');
        $seen[(int)$l['item_id']] = $q; $lines[] = ['item_id' => (int)$l['item_id'], 'qty' => -$q, 'kind' => 'XFR_OUT', 'note' => $l['note'] ?? ''];
    }
    $db->beginTransaction();
    try {
        $id = stk_post($db, $u, ['kind' => 'XFR', 'sub' => 'XFR_OUT', 'branch_id' => $from, 'to_branch_id' => $to, 'd' => $in['d'] ?? '', 'ref_no' => $in['ref_no'] ?? '', 'party' => stk_branch_name($to), 'note' => $in['note'] ?? '', 'request_id' => $in['request_id'] ?? '', 'status' => 'transit'], $lines, ['own_tx' => false]);
        $x = $db->prepare('INSERT IGNORE INTO stk_xfer_line (doc_id, item_id, sent) VALUES (?,?,?)'); foreach ($seen as $item => $q) $x->execute([$id, $item, $q]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $id;
}
/** The receiving branch takes the goods in: all, part (the rest stays in transit), or closes it short with a reason. */
function stk_xfer_receive(PDO $db, array $u, int $id, array $in): void {
    $doc = stk_doc_row($db, $id);
    if ($doc['kind'] !== 'XFR' || $doc['status'] !== 'transit') throw new RuntimeException('Nothing is waiting to be received on this transfer.');
    stk_need($u, 'store', (int)$doc['to_branch_id']);
    $d = stk_check_date((string)($in['d'] ?? date('Y-m-d'))); if ($d < $doc['d']) throw new RuntimeException('Received cannot be before it was sent.');
    $close = !empty($in['close']); $note = mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 500);
    $want = []; foreach ((array)($in['recv'] ?? []) as $item => $q) if (trim((string)$q) !== '') { $x = stk_q($q); if ($x === null) throw new RuntimeException('Check the quantities.'); $want[(int)$item] = $x; }
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT status FROM stk_doc WHERE id = ? FOR UPDATE'); $st->execute([$id]);
        if ($st->fetchColumn() !== 'transit') throw new RuntimeException('This transfer was received already.');
        $s = $db->prepare('SELECT item_id, sent - received AS open_qty FROM stk_xfer_line WHERE doc_id = ? FOR UPDATE'); $s->execute([$id]);
        $open = []; foreach ($s->fetchAll() as $r) $open[(int)$r['item_id']] = (float)$r['open_qty'];
        [$lines, $short, $status] = stk_receive_plan($open, $want, $close);
        if ($status === 'short' && mb_strlen($note) < 4) throw new RuntimeException('Write why the rest did not arrive.');
        $to = (int)$doc['to_branch_id'];
        if ($lines) {
            ksort($lines);
            $mx = $db->prepare('SELECT COALESCE(MAX(line_no), 0) FROM stk_move WHERE doc_id = ?'); $mx->execute([$id]); $n = (int)$mx->fetchColumn();
            $bi = $db->prepare('INSERT IGNORE INTO stk_bal (branch_id, item_id, qty) VALUES (?, ?, 0)'); $bl = $db->prepare('SELECT qty FROM stk_bal WHERE branch_id = ? AND item_id = ? FOR UPDATE');
            $mv = $db->prepare('INSERT INTO stk_move (doc_id, line_no, branch_id, item_id, d, qty, kind, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $bu = $db->prepare('UPDATE stk_bal SET qty = qty + ? WHERE branch_id = ? AND item_id = ?'); $xu = $db->prepare('UPDATE stk_xfer_line SET received = received + ? WHERE doc_id = ? AND item_id = ?');
            foreach ($lines as $item => $q) { $bi->execute([$to, $item]); $bl->execute([$to, $item]); $mv->execute([$id, ++$n, $to, $item, $d, $q, 'XFR_IN', 'From ' . $doc['doc_no'], (int)$u['id']]); $bu->execute([$q, $to, $item]); $xu->execute([$q, $id, $item]); }
        }
        $db->prepare('UPDATE stk_doc SET status = ?, recv_by = ?, recv_at = NOW(), recv_d = ?, recv_note = ? WHERE id = ?')->execute([$status, (int)$u['id'], $d, $note, $id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'stk_receive', '#' . $id . ' ' . $status);
}
/** Physical count: counted quantities replace the books; the differences are posted (even below zero), both figures kept. */
function stk_count(PDO $db, array $u, array $in): int {
    if ($had = stk_seen($db, (string)($in['request_id'] ?? ''))) return $had;
    $branch = (int)($in['branch_id'] ?? 0); stk_need($u, 'adjust', $branch);
    if (mb_strlen($note = trim(strip_tags((string)($in['note'] ?? '')))) < 4) throw new RuntimeException('Write what this count was (for example "Monthly count, store A").');
    $counted = [];
    foreach ((array)($in['counted'] ?? []) as $item => $c) { if (trim((string)$c) === '') continue; $x = stk_q($c); if ($x === null || $x < 0) throw new RuntimeException('Counted quantities are zero or more.'); stk_item_row($db, (int)$item); $counted[(int)$item] = $x; }
    if (!$counted) throw new RuntimeException('Write at least one counted quantity.');
    ksort($counted);
    $db->beginTransaction();
    try {
        $bi = $db->prepare('INSERT IGNORE INTO stk_bal (branch_id, item_id, qty) VALUES (?, ?, 0)'); $bl = $db->prepare('SELECT qty FROM stk_bal WHERE branch_id = ? AND item_id = ? FOR UPDATE');
        $books = []; foreach ($counted as $item => $_) { $bi->execute([$branch, $item]); $bl->execute([$branch, $item]); $books[$item] = (float)$bl->fetchColumn(); }
        $lines = stk_count_lines($books, $counted);
        if (!$lines) {   // everything matched: keep the count on record
            $no = stk_next_no($db, $branch, 'ADJ', date('Y-m-d'));
            $db->prepare("INSERT INTO stk_doc (doc_no, request_id, kind, sub, branch_id, d, note, created_by) VALUES (?, ?, 'CNT', 'ADJ', ?, ?, ?, ?)")->execute([$no, so_uuid((string)($in['request_id'] ?? '')), $branch, date('Y-m-d'), $note, (int)$u['id']]);
            $id = (int)$db->lastInsertId();
        } else $id = stk_post($db, $u, ['kind' => 'CNT', 'sub' => 'ADJ', 'branch_id' => $branch, 'd' => date('Y-m-d'), 'note' => $note, 'request_id' => $in['request_id'] ?? ''], $lines, ['own_tx' => false, 'negative' => true, 'right' => 'adjust']);
        $cl = $db->prepare('INSERT IGNORE INTO stk_count_line (doc_id, item_id, system_qty, counted) VALUES (?,?,?,?)'); foreach ($counted as $item => $c) $cl->execute([$id, $item, $books[$item], $c]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $id;
}
function stk_item_save(PDO $db, array $u, array $in, int $id = 0): int {
    if (!stk_rights($u)['store']) throw new RuntimeException('Your role cannot do this.');
    $old = $id ? stk_item_row($db, $id) : null;
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['name'] ?? '')))), 0, 255); if ($name === '') throw new RuntimeException('Write the item name.');
    $kind = (string)($in['kind'] ?? 'FG'); if (!isset(STK_KINDS[$kind])) throw new RuntimeException('Pick the type of item.');
    $uom = strtoupper(mb_substr(trim((string)($in['uom'] ?? 'NOS')), 0, 12)) ?: 'NOS';
    $code = strtoupper(mb_substr(trim((string)($in['code'] ?? '')), 0, 60));
    if ($code !== '' && !preg_match('/^[A-Z0-9._\/-]+$/', $code)) throw new RuntimeException('An item code uses letters, digits, . _ / -');
    $cost = trim((string)($in['std_cost'] ?? '')) === '' ? null : stk_q($in['std_cost']); if ($cost !== null && $cost < 0) throw new RuntimeException('Cost cannot be below zero.');
    $prod = (int)($in['product_id'] ?? 0) ?: null;
    if ($prod) { $x = $db->prepare('SELECT code FROM stk_item WHERE product_id = ? AND id <> ?'); $x->execute([$prod, $id]); if ($c = $x->fetchColumn()) throw new RuntimeException("That CRM product already belongs to stock item $c."); }
    if ($old && $old['uom'] !== $uom && (bool)$db->query('SELECT 1 FROM stk_move WHERE item_id = ' . $id . ' LIMIT 1')->fetchColumn()) throw new RuntimeException('The unit cannot change once the item has stock entries.');
    if ($code === '') $code = $old['code'] ?? '';
    if ($code === '') { $db->prepare("INSERT INTO stk_seq (prefix, n) VALUES ('ITEM', 1) ON DUPLICATE KEY UPDATE n = n + 1")->execute(); do { $code = sprintf('STK-%05d', (int)$db->query("SELECT n FROM stk_seq WHERE prefix = 'ITEM'")->fetchColumn()); $t = $db->prepare('SELECT 1 FROM stk_item WHERE code = ?'); $t->execute([$code]); if ($t->fetchColumn()) $db->exec("UPDATE stk_seq SET n = n + 1 WHERE prefix = 'ITEM'"); else break; } while (true); }
    $d = $db->prepare('SELECT 1 FROM stk_item WHERE code = ? AND id <> ?'); $d->execute([$code, $id]); if ($d->fetchColumn()) throw new RuntimeException("Code $code is already used by another item.");
    $f = ['code' => $code, 'name' => $name, 'kind' => $kind, 'grp' => mb_substr(trim((string)($in['grp'] ?? '')), 0, 80), 'uom' => $uom, 'tax_code' => mb_substr(preg_replace('/[^0-9A-Za-z]/', '', (string)($in['tax_code'] ?? '')), 0, 12),
          'std_cost' => $cost, 'product_id' => $prod, 'notes' => mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 500)];
    if ($old) $db->prepare('UPDATE stk_item SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
    else { $f['created_by'] = (int)$u['id']; $db->prepare('INSERT INTO stk_item (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f)); $id = (int)$db->lastInsertId(); }
    audit((int)$u['id'], $old ? 'stk_item_edit' : 'stk_item_add', '#' . $id);
    return $id;
}
/** Switch an item off (reason; only with no stock anywhere) or back on. Never deleted. */
function stk_item_active(PDO $db, array $u, int $id, bool $on, string $reason): void {
    if (!stk_rights($u)['store']) throw new RuntimeException('Your role cannot do this.');
    stk_item_row($db, $id);
    if (!$on && mb_strlen(trim($reason)) < 4) throw new RuntimeException('Write why the item is switched off.');
    if (!$on) { $s = $db->prepare('SELECT COALESCE(SUM(ABS(qty)),0) FROM stk_bal WHERE item_id = ?'); $s->execute([$id]); if ((float)$s->fetchColumn() > 0) throw new RuntimeException('This item still has stock at a branch — bring it to zero with a count or an issue first.'); }
    $db->prepare('UPDATE stk_item SET active = ?, off_why = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : mb_substr(trim(strip_tags($reason)), 0, 255), $id]);
}
function stk_level_save(PDO $db, array $u, int $item, int $branch, array $in): void {
    stk_need($u, 'store', $branch); stk_item_row($db, $item);
    $min = stk_q($in['min_qty'] ?? 0) ?? 0; $re = stk_q($in['reorder_qty'] ?? 0) ?? 0;
    if ($min < 0 || $re < 0) throw new RuntimeException('Levels cannot be below zero.');
    $db->prepare('INSERT INTO stk_level (branch_id, item_id, min_qty, reorder_qty, bin, updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE min_qty = VALUES(min_qty), reorder_qty = VALUES(reorder_qty), bin = VALUES(bin), updated_by = VALUES(updated_by)')
       ->execute([$branch, $item, $min, $re, mb_substr(trim((string)($in['bin'] ?? '')), 0, 40), (int)$u['id']]);
}
/** Stock now: every item with an entry (or a level) in the chosen branches — quantity, minimum, in transit, value at cost, state. */
function stk_now(PDO $db, array $u, ?int $branch, array $f = []): array {
    $br = $branch ? [$branch] : array_keys(stk_branches($u)); if (!$br) return ['rows' => [], 'counts' => [], 'value' => 0.0];
    $in = implode(',', array_map('intval', $br)); $w = ['i.active = 1']; $p = [];
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $l = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w[] = '(i.code LIKE ? OR i.name LIKE ? OR i.grp LIKE ?)'; array_push($p, $l, $l, $l); }
    if (isset(STK_KINDS[$f['kind'] ?? ''])) { $w[] = 'i.kind = ?'; $p[] = $f['kind']; }
    $s = $db->prepare("SELECT i.*, COALESCE((SELECT SUM(qty) FROM stk_bal b WHERE b.item_id = i.id AND b.branch_id IN ($in)),0) qty,
        COALESCE((SELECT SUM(min_qty) FROM stk_level l WHERE l.item_id = i.id AND l.branch_id IN ($in)),0) min_qty,
        COALESCE((SELECT SUM(x.sent - x.received) FROM stk_xfer_line x JOIN stk_doc d ON d.id = x.doc_id WHERE x.item_id = i.id AND d.status = 'transit' AND d.to_branch_id IN ($in)),0) transit,
        (SELECT MAX(m.d) FROM stk_move m WHERE m.item_id = i.id AND m.branch_id IN ($in)) last_move FROM stk_item i WHERE " . implode(' AND ', $w) . ' ORDER BY i.grp, i.name');
    $s->execute($p);
    $rows = []; $counts = ['below_zero' => 0, 'below_min' => 0, 'zero' => 0, 'ok' => 0]; $value = 0.0;
    foreach ($s->fetchAll() as $r) {
        if ($r['last_move'] === null && (float)$r['min_qty'] == 0.0) continue;
        $r['state'] = stk_state((float)$r['qty'], (float)$r['min_qty'], (float)$r['transit']); $counts[$r['state']]++;
        if (($f['state'] ?? '') !== '' && $f['state'] !== $r['state']) continue;
        $r['value'] = $r['std_cost'] === null ? null : round((float)$r['qty'] * (float)$r['std_cost'], 2);
        if ($r['value'] !== null && (float)$r['qty'] > 0) $value += $r['value'];
        $rows[] = $r;
    }
    return ['rows' => $rows, 'counts' => $counts, 'value' => round($value, 2)];
}
function stk_statement(PDO $db, array $u, int $branch, string $from, string $to): array {
    stk_need($u, 'view', $branch);
    $from = so_date($from, 'From', true); $to = so_date($to, 'To', true); if ($to < $from) throw new RuntimeException('"To" is before "From".');
    $s = $db->prepare('SELECT item_id, d, qty, kind FROM stk_move WHERE branch_id = ? AND d <= ? ORDER BY d, id'); $s->execute([$branch, $to]);
    $calc = stk_statement_calc($s->fetchAll(), $from, $to); $items = [];
    if ($calc) foreach ($db->query('SELECT id, code, name, uom, grp FROM stk_item WHERE id IN (' . implode(',', array_map('intval', array_keys($calc))) . ') ORDER BY grp, name') as $i) $items[] = $i + $calc[(int)$i['id']];
    return $items;
}
function stk_reorder(PDO $db, array $u, ?int $branch): array {
    $out = [];
    foreach ($branch ? [$branch => stk_branch_name($branch)] : stk_branches($u) as $b => $name) {
        $s = $db->prepare("SELECT i.id, i.code, i.name, i.kind, i.uom, l.min_qty, l.reorder_qty, COALESCE(bal.qty, 0) qty,
            COALESCE((SELECT SUM(x.sent - x.received) FROM stk_xfer_line x JOIN stk_doc d ON d.id = x.doc_id WHERE x.item_id = i.id AND d.status = 'transit' AND d.to_branch_id = l.branch_id), 0) transit
            FROM stk_level l JOIN stk_item i ON i.id = l.item_id AND i.active = 1 LEFT JOIN stk_bal bal ON bal.item_id = l.item_id AND bal.branch_id = l.branch_id WHERE l.branch_id = ? AND l.min_qty > 0 ORDER BY i.kind, i.name");
        $s->execute([$b]);
        foreach ($s->fetchAll() as $r) if (($sg = stk_suggest((float)$r['qty'], (float)$r['transit'], (float)$r['min_qty'], (float)$r['reorder_qty'])) > 0) $out[] = $r + ['suggest' => $sg, 'action' => in_array($r['kind'], STK_MAKE, true) ? 'make' : 'buy', 'branch' => $name];
    }
    return $out;
}
function stk_docs(PDO $db, array $u, array $f = []): array {
    $br = array_keys(stk_branches($u)); if (!$br) return [];
    $in = implode(',', array_map('intval', $br)); $w = ["(d.branch_id IN ($in) OR d.to_branch_id IN ($in))"]; $p = [];
    if (isset(STK_DOCS[$f['kind'] ?? ''])) { $w[] = 'd.kind = ?'; $p[] = $f['kind']; }
    if (isset(STK_DOC_STATUS[$f['status'] ?? ''])) { $w[] = 'd.status = ?'; $p[] = $f['status']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $l = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w[] = '(d.doc_no LIKE ? OR d.ref_no LIKE ? OR d.party LIKE ?)'; array_push($p, $l, $l, $l); }
    $s = $db->prepare('SELECT d.*, u.name AS who, (SELECT COUNT(*) FROM stk_move m WHERE m.doc_id = d.id) nlines FROM stk_doc d LEFT JOIN users u ON u.id = d.created_by WHERE ' . implode(' AND ', $w) . ' ORDER BY d.d DESC, d.id DESC LIMIT 200');
    $s->execute($p);
    return $s->fetchAll();
}
function stk_ledger(PDO $db, array $u, int $item, int $branch): array {
    stk_need($u, 'view', $branch);
    $s = $db->prepare('SELECT m.*, d.doc_no, d.kind AS doc_kind, d.party, d.ref_no FROM stk_move m JOIN stk_doc d ON d.id = m.doc_id WHERE m.item_id = ? AND m.branch_id = ? ORDER BY m.d, m.id'); $s->execute([$item, $branch]);
    $run = 0.0; $o = []; foreach ($s->fetchAll() as $r) { $run = round($run + (float)$r['qty'], 3); $o[] = $r + ['after' => $run]; }
    return array_reverse($o);
}
