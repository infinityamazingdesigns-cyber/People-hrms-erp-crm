<?php
/**
 * Finance part 2 — the books joined to the other modules (ported from the reference build's Finance steps 3–7, logic only).
 *
 *  - Ledger mapping: which ledger each feed line goes to (fin_map), kept by an Accounts head / manager or the setup roles.
 *  - Feeds: records other modules already finished come in as DRAFT vouchers, one per source record, ever
 *    (request_id "feed-<source>-<ref>" + fin_feed). A person then posts them or sends them for approval as usual.
 *      payroll  — a confirmed month → one journal of TOTALS only (never a person or code); only the payroll-feed roles.
 *      claim    — an expense claim marked paid → a payment (expenses by category, money received before, paid from).
 *      vendor   — a vendor bill marked paid → a payment.
 *      sale     — a CRM order dispatched → a journal (customers, sales before tax, output tax).
 *      purchase — a purchase order issued and fully received → a journal (purchases, input tax, suppliers).
 *  - Opening balances as at the books start, while the books start is not locked.
 *  - Profit & loss, balance sheet and the management view, worked out from posted vouchers each time.
 *  - Bank reconciliation: statement lines imported once each (hash), matched to posted vouchers on that bank ledger
 *    (same side and amount within FIN_REC_DAYS, sure ones together), left out with a reason, reconciliation statement,
 *    suggested drafts from earlier matched lines (rule based, nothing leaves the site).
 * Nothing here posts, pays or files; nothing is deleted. Pure functions first, then the database half.
 */
declare(strict_types=1);
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/table_read.php';
require_once __DIR__ . '/fin_note.php';

/** key => [label, nature it normally sits in]. */
const FIN_MAP_KEYS = [
    'pay.salary' => ['Salaries and wages', 'expense'], 'pay.employer' => ['Employer contributions', 'expense'],
    'pay.net' => ['Salaries payable', 'liability'], 'pay.stat' => ['Statutory dues payable', 'liability'],
    'pay.tds' => ['Tax deducted on salaries', 'liability'], 'pay.loan' => ['Staff loans and advances', 'asset'],
    'pay.other' => ['Other salary deductions', 'liability'],
    'claim.expense' => ['Staff expenses (claims)', 'expense'], 'claim.advance' => ['Advances given to staff', 'asset'],
    'claim.paid' => ['Bank / cash claims are paid from', 'asset'],
    'vendor.expense' => ['Office expenses (vendor bills)', 'expense'], 'vendor.paid' => ['Bank / cash vendor bills are paid from', 'asset'],
    'sale.debtors' => ['Customers (debtors)', 'asset'], 'sale.sales' => ['Sales', 'income'], 'sale.tax' => ['Output tax', 'liability'],
    'purchase.stock' => ['Purchases', 'expense'], 'purchase.tax' => ['Input tax', 'asset'], 'purchase.creditors' => ['Suppliers (creditors)', 'liability'],
    'bank.suspense' => ['Suspense for bank lines', 'liability'],
];
/** source => [label, map keys it needs, voucher type]. */
const FIN_FEEDS = [
    'payroll' => ['Payroll month (totals)', ['pay.salary', 'pay.net'], 'journal'],
    'claim' => ['Expense claims paid', ['claim.expense', 'claim.paid'], 'payment'],
    'vendor' => ['Vendor bills paid', ['vendor.expense', 'vendor.paid'], 'payment'],
    'sale' => ['CRM orders dispatched', ['sale.debtors', 'sale.sales'], 'journal'],
    'purchase' => ['Purchase orders received', ['purchase.stock', 'purchase.creditors'], 'journal'],
];
const FIN_FEED_LIST = 200;
const FIN_REC_DAYS = 7;
const FIN_SALE_DONE = ['Dispatched', 'Delivered', 'Closed'];
const FIN_STMT_COLS = [
    'date' => ['date', 'txndate', 'transactiondate', 'valuedate', 'postingdate', 'trandate'],
    'narr' => ['narration', 'description', 'particulars', 'remarks', 'details', 'transactiondetails'],
    'ref' => ['ref', 'refno', 'reference', 'chequeno', 'chqno', 'chqrefno', 'utr', 'referenceno'],
    'out' => ['withdrawal', 'withdrawals', 'debit', 'withdrawalamt', 'withdrawalamount', 'debitamount', 'dr'],
    'in' => ['deposit', 'deposits', 'credit', 'depositamt', 'depositamount', 'creditamount', 'cr'],
    'amt' => ['amount', 'amt', 'transactionamount'],
];

/* ------------------------------------------------------------------ pure */

/** Rupees-style amount (float / decimal string) → minor units, rounded once. Pure. */
function fin_dec_minor($v): int {
    if (is_string($v)) { $s = trim(str_replace([',', ' '], '', $v)); $neg = str_starts_with($s, '-'); $m = fin_minor(ltrim($s, '-+')); if ($m !== null) return $neg ? -$m : $m; }
    return (int)round((float)$v * 100);
}

/** May these roles bring in this feed? Pure. */
function fin_feed_can(array $R, array $codes, string $src, array $set): bool {
    if (!isset(FIN_FEEDS[$src]) || empty($R['enter'])) return false;
    return $src === 'payroll' ? (bool)array_intersect($codes, (array)($set['payroll_feed_roles'] ?? ['SUPERADMIN'])) : true;
}

/**
 * Payroll journal from a confirmed month's lines (each: gross, net, deductions label => amount, employer label => amount).
 * Totals only. Returns [lines [[map key, dr, cr, note]], heads, problem]. Pure.
 */
function fin_payroll_lines(array $rows): array {
    $gross = 0; $net = 0; $heads = 0; $ded = []; $er = [];
    foreach ($rows as $r) {
        if (!is_array($r) || !empty($r['missing_ctc'])) continue;
        $heads++;
        $gross += fin_dec_minor($r['gross'] ?? 0); $net += fin_dec_minor($r['net'] ?? 0);
        foreach ((array)($r['deductions'] ?? []) as $k => $v) $ded[(string)$k] = ($ded[(string)$k] ?? 0) + fin_dec_minor($v);
        foreach ((array)($r['employer'] ?? []) as $k => $v) $er[(string)$k] = ($er[(string)$k] ?? 0) + fin_dec_minor($v);
    }
    if (!$heads || $gross <= 0) return [[], $heads, 'No processed salaries in this month.'];
    if ($net < 0) return [[], $heads, 'Deductions are more than the salaries; check the month in Payroll.'];
    $erT = array_sum($er);
    $L = [['pay.salary', $gross, 0, 'Gross salaries']];
    if ($erT > 0) $L[] = ['pay.employer', $erT, 0, 'Employer contributions'];
    $L[] = ['pay.net', 0, $net, 'Net salaries payable'];
    ksort($ded); ksort($er);
    foreach ($ded as $k => $v) {
        if ($v <= 0) continue;
        $key = match (true) { str_contains(strtolower($k), 'tds') || str_contains(strtolower($k), 'income tax') => 'pay.tds',
            str_contains(strtolower($k), 'loan') || str_contains(strtolower($k), 'advance') => 'pay.loan',
            str_contains(strtolower($k), 'other') || str_contains(strtolower($k), 'arrear') => 'pay.other', default => 'pay.stat' };
        $L[] = [$key, 0, $v, mb_substr($k, 0, 200)];
    }
    foreach ($er as $k => $v) if ($v > 0) $L[] = ['pay.stat', 0, $v, mb_substr($k . ' (employer)', 0, 200)];
    $dr = 0; $cr = 0; foreach ($L as $l) { $dr += $l[1]; $cr += $l[2]; }
    if ($dr !== $cr) $L[] = ['pay.other', $cr > $dr ? $cr - $dr : 0, $dr > $cr ? $dr - $cr : 0, 'Rounding'];
    return [$L, $heads, ''];
}

/** Lines of a paid claim from its lines (category, kind dr / cr, amount, allowed, active). Returns [lines, problem]. Pure. */
function fin_claim_lines(array $lines, int $credits): array {
    $by = []; $dr = 0; $cr = 0;
    foreach ($lines as $l) {
        if (empty($l['active']) || empty($l['allowed'])) continue;
        $p = fin_dec_minor($l['amount']);
        if (($l['kind'] ?? 'dr') === 'cr') { $cr += $p; continue; }
        $by[(string)$l['category']] = ($by[(string)$l['category']] ?? 0) + $p; $dr += $p;
    }
    $cr = max($cr, $credits);
    if ($dr <= 0) return [[], 'No allowed lines on the claim.'];
    if ($dr - $cr <= 0) return [[], 'Money received covers the claim; nothing was paid out. Make a journal by hand if needed.'];
    ksort($by);
    $L = [];
    foreach ($by as $cat => $p) $L[] = ['claim.expense', $p, 0, mb_substr($cat, 0, 200)];
    if ($cr > 0) $L[] = ['claim.advance', 0, $cr, 'Money received before the claim'];
    $L[] = ['claim.paid', 0, $dr - $cr, 'Paid'];
    return [$L, ''];
}

/** Sale journal lines from an order's before-tax value and total. Returns [lines, problem]. Pure. */
function fin_sale_lines(int $value, int $total, string $customer): array {
    if ($value <= 0 || $total < $value) return [[], 'Before-tax value and total do not fit together.'];
    $L = [['sale.debtors', $total, 0, mb_substr($customer, 0, 200)], ['sale.sales', 0, $value, 'Before tax']];
    if ($total > $value) $L[] = ['sale.tax', 0, $total - $value, 'Tax on the order'];
    return [$L, ''];
}
/** Purchase journal lines from a PO's tax and total (round-off stays with purchases). Returns [lines, problem]. Pure. */
function fin_purchase_lines(int $tax, int $total, string $vendor): array {
    if ($total <= 0 || $tax < 0 || $tax >= $total) return [[], 'Tax and total do not fit together.'];
    $L = [['purchase.stock', $total - $tax, 0, 'Before tax']];
    if ($tax > 0) $L[] = ['purchase.tax', $tax, 0, 'Tax on the order'];
    $L[] = ['purchase.creditors', 0, $total, mb_substr($vendor, 0, 200)];
    return [$L, ''];
}

/** Opening balances: Dr total − Cr total (0 = they agree). $L rows with opening_minor. Pure. */
function fin_opening_diff(array $L): int { $s = 0; foreach ($L as $l) $s += (int)$l['opening_minor']; return $s; }

/**
 * Profit and loss from ledgers and their movements between two dates. Income = Cr − Dr, expense = Dr − Cr.
 * Restricted ledgers are one line per group for logins without the right. Pure.
 */
function fin_pl_build(array $L, array $mv, bool $sens): array {
    $side = ['income' => [], 'expense' => []]; $tot = ['income' => 0, 'expense' => 0];
    foreach ($L as $l) {
        $n = (string)$l['nature'];
        if (!isset($side[$n])) continue;
        [$d, $c] = $mv[(int)$l['id']] ?? [0, 0];
        $a = $n === 'income' ? $c - $d : $d - $c;
        if ($a === 0) continue;
        $g = (string)$l['group_name'];
        $side[$n][$g] ??= ['group' => $g, 'rows' => [], 'hidden' => 0, 'total' => 0];
        if ((int)$l['restricted'] && !$sens) $side[$n][$g]['hidden'] += $a; else $side[$n][$g]['rows'][] = [$l['name'], (int)$l['id'], $a];
        $side[$n][$g]['total'] += $a; $tot[$n] += $a;
    }
    foreach ($side as &$gs) { foreach ($gs as &$g) if ($g['hidden'] !== 0) $g['rows'][] = ['Restricted ledgers (total)', null, $g['hidden']]; unset($g); ksort($gs); $gs = array_values($gs); }
    unset($gs);
    return ['income' => $side['income'], 'expense' => $side['expense'], 'income_total' => $tot['income'], 'expense_total' => $tot['expense'], 'profit' => $tot['income'] - $tot['expense']];
}

/** Balance sheet from ledgers and balances as at a date: assets (Dr +), liabilities (Cr +), profit not yet closed. Pure. */
function fin_bs_build(array $L, array $bal, bool $sens): array {
    $side = ['asset' => [], 'liability' => []]; $tot = ['asset' => 0, 'liability' => 0]; $pl = 0;
    foreach ($L as $l) {
        $b = $bal[(int)$l['id']] ?? 0; $n = (string)$l['nature'];
        if (in_array($n, ['income', 'expense'], true)) { $pl -= $b; continue; }
        if (!isset($side[$n]) || $b === 0) continue;
        $a = $n === 'asset' ? $b : -$b;
        $g = (string)$l['group_name'];
        $side[$n][$g] ??= ['group' => $g, 'rows' => [], 'hidden' => 0, 'total' => 0];
        if ((int)$l['restricted'] && !$sens) $side[$n][$g]['hidden'] += $a; else $side[$n][$g]['rows'][] = [$l['name'], (int)$l['id'], $a];
        $side[$n][$g]['total'] += $a; $tot[$n] += $a;
    }
    foreach ($side as &$gs) { foreach ($gs as &$g) if ($g['hidden'] !== 0) $g['rows'][] = ['Restricted ledgers (total)', null, $g['hidden']]; unset($g); ksort($gs); $gs = array_values($gs); }
    unset($gs);
    return ['assets' => $side['asset'], 'liabilities' => $side['liability'], 'asset_total' => $tot['asset'], 'liability_total' => $tot['liability'],
            'profit' => $pl, 'diff' => $tot['asset'] - $tot['liability'] - $pl];
}

/** A statement date: 2026-11-24, 24/11/2026, 24-11-2026, 24.11.26, 24-Nov-2026, 24 Nov 2026 → Y-m-d or null. Pure. */
function fin_stmt_date(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^\d{5}(\.\d+)?$/', $s)) return date('Y-m-d', ((int)$s - 25569) * 86400);   // spreadsheet serial day
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $s, $m)) [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})[\s\-]([A-Za-z]{3})[a-z]*[\s\-,]+(\d{2,4})$/', $s, $m)) {
        $mo = array_search(strtolower($m[2]), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);
        if ($mo === false) return null;
        [$d, $mo, $y] = [(int)$m[1], $mo + 1, (int)$m[3]];
    } else return null;
    if ($y < 100) $y += 2000;
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}
/** A statement amount cell → minor units (unsigned), '' → 0, null when unreadable. Pure. */
function fin_stmt_amt(string $s): ?int {
    $s = trim(str_ireplace(['dr', 'cr', ',', ' '], '', $s));
    if ($s === '' || $s === '-') return 0;
    return fin_minor(ltrim($s, '-+'));
}
/**
 * Rows of a bank statement file → [lines [date, narr, ref, out, in, hash], errors]. Columns by their titles; a single
 * signed Amount column also works (minus = withdrawal). Pure.
 */
function fin_stmt_rows(array $rows): array {
    [$h, $m] = tbl_map($rows, FIN_STMT_COLS, 2);
    if ($h < 0 || !isset($m['date']) || (!isset($m['out']) && !isset($m['in']) && !isset($m['amt']))) return [[], ['The file needs a date column and withdrawal / deposit (or amount) columns.']];
    $out = []; $err = []; $seen = [];
    foreach (array_slice($rows, $h + 1, null, true) as $i => $r) {
        $cell = fn($f) => isset($m[$f]) ? trim((string)($r[$m[$f]] ?? '')) : '';
        if (implode('', array_map('strval', $r)) === '') continue;
        $d = fin_stmt_date($cell('date'));
        if ($d === null) { if ($cell('date') !== '' && !preg_match('/total|balance|opening|closing/i', implode(' ', array_map('strval', $r)))) $err[] = 'Row ' . ($i + 1) . ': the date is not readable.'; continue; }
        if (isset($m['amt']) && !isset($m['out']) && !isset($m['in'])) {
            $raw = $cell('amt'); $a = fin_stmt_amt($raw);
            $neg = str_starts_with(trim($raw), '-') || stripos($raw, 'dr') !== false;
            [$o, $in] = $a === null ? [null, null] : ($neg ? [$a, 0] : [0, $a]);
        } else { $o = fin_stmt_amt($cell('out')); $in = fin_stmt_amt($cell('in')); }
        if ($o === null || $in === null) { $err[] = 'Row ' . ($i + 1) . ': the amount is not readable.'; continue; }
        if (($o > 0) === ($in > 0)) { if ($o || $in) $err[] = 'Row ' . ($i + 1) . ': both a withdrawal and a deposit.'; continue; }
        $narr = mb_substr(preg_replace('/\s+/', ' ', $cell('narr')), 0, 300); $ref = mb_substr($cell('ref'), 0, 80);
        $key = implode('|', [$d, $narr, $ref, $o, $in]);
        $seen[$key] = ($seen[$key] ?? 0) + 1;                       // two identical lines in one file are two lines
        $out[] = [$d, $narr, $ref, $o, $in, hash('sha256', $key . '|' . $seen[$key])];
    }
    return [$out, $err];
}

/**
 * Candidates for each open statement line among unmatched book entries on the same bank ledger: same side (deposit ↔
 * book Dr, withdrawal ↔ book Cr), same amount, within FIN_REC_DAYS. $lines [id, line_date, out_minor, in_minor],
 * $entries [entry key, vdate, dr_minor, cr_minor]. Returns line id => [entry keys]. Pure.
 */
function fin_rec_candidates(array $lines, array $entries): array {
    $c = [];
    foreach ($lines as $l) {
        $c[(int)$l['id']] = [];
        $amt = (int)$l['in_minor'] ?: (int)$l['out_minor']; $in = (int)$l['in_minor'] > 0;
        foreach ($entries as $e) {
            $ea = $in ? (int)$e['dr_minor'] : (int)$e['cr_minor'];
            if ($ea !== $amt) continue;
            if (abs((strtotime((string)$e['vdate']) - strtotime((string)$l['line_date'])) / 86400) > FIN_REC_DAYS) continue;
            $c[(int)$l['id']][] = (string)$e['key'];
        }
    }
    return $c;
}
/** Sure matches: a line with exactly one candidate that no other line also wants. Returns line id => entry key. Pure. */
function fin_rec_sure(array $cand): array {
    $want = []; foreach ($cand as $ks) foreach ($ks as $k) $want[$k] = ($want[$k] ?? 0) + 1;
    $out = [];
    foreach ($cand as $lid => $ks) if (count($ks) === 1 && $want[$ks[0]] === 1) $out[(int)$lid] = $ks[0];
    return $out;
}
/** The first two meaningful words of a narration (numbers and short words left out). Pure. */
function fin_rec_words(string $s): string {
    $w = array_values(array_filter(preg_split('/[^a-z]+/', strtolower($s)), fn($x) => strlen($x) > 2 && !in_array($x, ['neft', 'rtgs', 'imps', 'upi', 'the', 'and', 'ref', 'txn', 'from', 'to', 'by'], true)));
    return implode(' ', array_slice($w, 0, 2));
}
/** Suggested ledger for a narration from earlier matched lines [[narration, ledger id]]: [ledger id, times] or null. Pure. */
function fin_rec_suggest(string $narr, array $history): ?array {
    $k = fin_rec_words($narr);
    if ($k === '') return null;
    $n = [];
    foreach ($history as [$h, $lid]) if (fin_rec_words((string)$h) === $k) $n[(int)$lid] = ($n[(int)$lid] ?? 0) + 1;
    if (!$n) return null;
    arsort($n);
    return [(int)array_key_first($n), (int)reset($n)];
}
/**
 * Bank reconciliation statement. $book = book balance (Dr +) as at the date; $bookOpen = unmatched book entries up to the
 * date [dr, cr]; $bankOpen = open statement lines up to the date [out, in]. Pure.
 */
function fin_brs_build(int $book, array $bookOpen, array $bankOpen): array {
    $notCleared = 0; $notPresented = 0; foreach ($bookOpen as $e) { $notCleared += (int)$e['dr_minor']; $notPresented += (int)$e['cr_minor']; }
    $bankIn = 0; $bankOut = 0; foreach ($bankOpen as $l) { $bankIn += (int)$l['in_minor']; $bankOut += (int)$l['out_minor']; }
    return ['book' => $book, 'not_cleared' => $notCleared, 'not_presented' => $notPresented, 'bank_in' => $bankIn, 'bank_out' => $bankOut,
            'bank' => $book - $notCleared + $notPresented + $bankIn - $bankOut];
}

/* ------------------------------------------------------------------ database: mapping and feeds */

function fin_map_get(PDO $db): array { return array_map('intval', $db->query('SELECT map_key, ledger_id FROM fin_map WHERE ledger_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR)); }
function fin_map_save(PDO $db, array $R, int $uid, array $in): int {
    fin_need($R, 'masters', 'Only an Accounts head / manager or the setup roles keep the mapping.');
    $L = fin_ledger_map($db); $old = fin_map_get($db); $n = 0;
    $st = $db->prepare('INSERT INTO fin_map (map_key, ledger_id, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE ledger_id = VALUES(ledger_id), updated_by = VALUES(updated_by)');
    foreach (FIN_MAP_KEYS as $k => $_) {
        if (!array_key_exists($k, (array)($in['map'] ?? []))) continue;
        $lid = (int)$in['map'][$k] ?: null;
        if ($lid !== null && (!isset($L[$lid]) || !(int)$L[$lid]['active'])) throw new RuntimeException('"' . FIN_MAP_KEYS[$k][0] . '": pick an active ledger.');
        if (str_ends_with($k, '.paid') && $lid !== null && !in_array($L[$lid]['kind'], ['bank', 'cash'], true)) throw new RuntimeException('"' . FIN_MAP_KEYS[$k][0] . '" needs a bank or cash ledger.');
        if (($old[$k] ?? null) === $lid) continue;
        $st->execute([$k, $lid, $uid]); $n++;
    }
    if ($n) fin_log($db, $uid, 'map_save', 'map', '0', "$n keys");
    return $n;
}

/** Already brought in for a source: ref => voucher id. */
function fin_feed_done(PDO $db, string $src): array {
    $q = $db->prepare('SELECT source_ref, voucher_id FROM fin_feed WHERE source = ?'); $q->execute([$src]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Records waiting to come in from a source, newest first, with how many already came. */
function fin_feed_items(PDO $db, array $R, array $codes, string $src): array {
    $o = ['items' => [], 'done' => 0, 'missing' => false];
    if (!fin_feed_can($R, $codes, $src, fin_settings())) return $o;
    $done = fin_feed_done($db, $src); $o['done'] = count($done);
    try {
        foreach (fin_feed_rows($db, $src) as $it) {
            if (isset($done[$it['ref']])) continue;
            $o['items'][] = $it;
            if (count($o['items']) >= FIN_FEED_LIST) break;
        }
    } catch (PDOException $e) { $o['missing'] = true; }
    return $o;
}
/** Candidate rows of a source (not yet filtered by fin_feed): [ref, date, label, sub, amount, problem]. */
function fin_feed_rows(PDO $db, string $src, ?string $only = null): array {
    $out = []; $w = $only !== null ? ' AND x.id = ' . (int)$only : '';
    if ($src === 'payroll') {
        if (!fin_has($db, 'pay_month')) return [];
        $q = $db->prepare("SELECT ym, ver FROM pay_month WHERE status IN ('confirmed','released')" . ($only !== null ? ' AND ym = ?' : '') . ' ORDER BY ym DESC LIMIT 36');
        $q->execute($only !== null ? [$only] : []);
        foreach ($q->fetchAll() as $m) {
            $s = $db->prepare('SELECT data FROM pay_snapshot WHERE ym = ? AND ver = ?'); $s->execute([$m['ym'], (int)$m['ver']]);
            [$lines, $heads, $prob] = fin_payroll_lines(array_map(fn($d) => json_decode((string)$d, true), $s->fetchAll(PDO::FETCH_COLUMN)));
            $g = 0; foreach ($lines as $l) $g += $l[1];
            $out[] = ['ref' => $m['ym'], 'date' => date('Y-m-t', strtotime($m['ym'] . '-01')), 'label' => 'Salaries for ' . date('F Y', strtotime($m['ym'] . '-01')),
                      'sub' => $heads . ' people · totals only', 'amount' => $g, 'problem' => $prob, 'lines' => $lines, 'narr' => 'Salaries for ' . date('F Y', strtotime($m['ym'] . '-01')) . ' (payroll totals)'];
        }
    } elseif ($src === 'claim') {
        if (!fin_has($db, 'ec_claim')) return [];
        foreach ($db->query("SELECT x.id, x.claim_no, x.paid_on, x.credit_amount, x.pay_mode, x.pay_ref FROM ec_claim x WHERE x.status = 'paid' AND x.paid_on IS NOT NULL$w ORDER BY x.paid_on DESC, x.id DESC LIMIT 400")->fetchAll() as $c) {
            $q = $db->prepare('SELECT category, kind, amount, allowed, active FROM ec_line WHERE claim_id = ?'); $q->execute([(int)$c['id']]);
            [$lines, $prob] = fin_claim_lines($q->fetchAll(), fin_dec_minor($c['credit_amount'] ?? 0));
            $paid = 0; foreach ($lines as $l) if ($l[0] === 'claim.paid') $paid = $l[2];
            $out[] = ['ref' => (string)$c['id'], 'date' => (string)$c['paid_on'], 'label' => 'Claim ' . $c['claim_no'], 'sub' => (string)$c['pay_mode'], 'amount' => $paid, 'problem' => $prob,
                      'lines' => $lines, 'narr' => 'Expense claim ' . $c['claim_no'] . ($c['pay_ref'] !== '' ? ' · ref ' . $c['pay_ref'] : '')];
        }
    } elseif ($src === 'vendor') {
        if (!fin_has($db, 'vb_bill')) return [];
        foreach ($db->query("SELECT x.id, x.ref, x.kind, x.vendor, x.vendor_bill_no, x.paid_on, x.amount, x.pay_mode, x.pay_ref FROM vb_bill x WHERE x.status = 'paid' AND x.paid_on IS NOT NULL$w ORDER BY x.paid_on DESC, x.id DESC LIMIT 400")->fetchAll() as $b) {
            $p = fin_dec_minor($b['amount']);
            $out[] = ['ref' => (string)$b['id'], 'date' => (string)$b['paid_on'], 'label' => $b['ref'] . ' · ' . $b['kind'], 'sub' => (string)$b['vendor'], 'amount' => $p,
                      'problem' => $p > 0 ? '' : 'No amount on the bill.', 'lines' => $p > 0 ? [['vendor.expense', $p, 0, mb_substr((string)$b['kind'], 0, 200)], ['vendor.paid', 0, $p, 'Paid']] : [],
                      'narr' => $b['ref'] . ' · ' . $b['kind'] . ($b['vendor'] !== '' ? ' · ' . $b['vendor'] : '') . ($b['vendor_bill_no'] !== '' ? ' · bill ' . $b['vendor_bill_no'] : '') . ($b['pay_ref'] !== '' ? ' · ref ' . $b['pay_ref'] : '')];
        }
    } elseif ($src === 'sale') {
        if (!fin_has($db, 'crm_order')) return [];
        $st = implode(',', array_map(fn($s) => $db->quote($s), FIN_SALE_DONE));
        foreach ($db->query("SELECT x.id, x.order_no, x.first_dispatch_at, x.submitted_at, x.order_value, x.order_total, x.currency, c.name AS customer FROM crm_order x LEFT JOIN crm_customer c ON c.id = x.customer_id WHERE x.status IN ($st)$w ORDER BY x.first_dispatch_at DESC, x.id DESC LIMIT 400")->fetchAll() as $r) {
            $cur = (string)org('currency');
            [$lines, $prob] = fin_sale_lines(fin_dec_minor($r['order_value']), fin_dec_minor($r['order_total']), (string)$r['customer']);
            if ($cur !== '' && $r['currency'] !== $cur) { $lines = []; $prob = 'The order is in ' . $r['currency'] . '; make the journal by hand at the rate.'; }
            $out[] = ['ref' => (string)$r['id'], 'date' => substr((string)($r['first_dispatch_at'] ?: $r['submitted_at']), 0, 10), 'label' => 'Order ' . $r['order_no'], 'sub' => (string)$r['customer'],
                      'amount' => fin_dec_minor($r['order_total']), 'problem' => $prob, 'lines' => $lines, 'narr' => 'Sale · order ' . $r['order_no'] . ' dispatched'];
        }
    } elseif ($src === 'purchase') {
        if (!fin_has($db, 'pur_order')) return [];
        foreach ($db->query("SELECT x.id, x.no, x.issued_at, x.tax, x.total, v.name AS vendor,
                 (SELECT COUNT(*) FROM pur_order_line l WHERE l.order_id = x.id AND l.active = 1 AND l.qty_recv < l.qty) AS open_lines,
                 (SELECT MAX(l.id) FROM pur_order_line l WHERE l.order_id = x.id AND l.active = 1) AS has_lines
                 FROM pur_order x LEFT JOIN pur_vendor v ON v.id = x.vendor_id WHERE x.status = 'issued'$w ORDER BY x.issued_at DESC, x.id DESC LIMIT 400")->fetchAll() as $p) {
            if ((int)$p['open_lines'] || !$p['has_lines']) continue;
            [$lines, $prob] = fin_purchase_lines(fin_dec_minor($p['tax']), fin_dec_minor($p['total']), (string)$p['vendor']);
            $out[] = ['ref' => (string)$p['id'], 'date' => substr((string)$p['issued_at'], 0, 10), 'label' => 'PO ' . $p['no'], 'sub' => (string)$p['vendor'] . ' · fully received',
                      'amount' => fin_dec_minor($p['total']), 'problem' => $prob, 'lines' => $lines, 'narr' => 'Purchase · PO ' . $p['no'] . ' received'];
        }
    }
    return $out;
}

/** Bring ticked records in as draft vouchers. Each on its own; one refusal never stops the rest. Returns [made, problems]. */
function fin_feed_make(PDO $db, array $R, array $codes, int $uid, string $src, array $refs, string $today): array {
    if (!fin_feed_can($R, $codes, $src, fin_settings())) throw new RuntimeException($src === 'payroll' ? 'Only the payroll-feed roles bring in payroll.' : 'Only the Accounts team brings records into the books.');
    $map = fin_map_get($db);
    foreach (FIN_FEEDS[$src][1] as $k) if (empty($map[$k])) throw new RuntimeException('Set the ledger for "' . FIN_MAP_KEYS[$k][0] . '" on Ledger mapping first.');
    $done = fin_feed_done($db, $src); $B = fin_book($db);
    $made = 0; $bad = [];
    foreach (array_slice(array_unique(array_map('strval', $refs)), 0, FIN_FEED_LIST) as $ref) {
        if (isset($done[$ref])) continue;
        try {
            $it = fin_feed_rows($db, $src, $ref)[0] ?? throw new RuntimeException('not found or not finished in its module');
            if ($it['problem'] !== '') throw new RuntimeException($it['problem']);
            $date = min((string)$it['date'], $today);
            if (!empty($B['books_from']) && $date < $B['books_from']) throw new RuntimeException('dated before the books start; bring it in through the opening balances');
            $in = ['vtype' => FIN_FEEDS[$src][2], 'vdate' => $date, 'narration' => mb_substr((string)$it['narr'], 0, 500), 'request_id' => 'feed-' . $src . '-' . preg_replace('/[^a-z0-9-]/i', '', $ref),
                   'line_ledger' => [], 'line_dr' => [], 'line_cr' => [], 'line_note' => []];
            foreach ($it['lines'] as [$k, $d, $c, $note]) {
                if (empty($map[$k])) throw new RuntimeException('set the ledger for "' . FIN_MAP_KEYS[$k][0] . '" on Ledger mapping');
                $in['line_ledger'][] = $map[$k]; $in['line_dr'][] = $d ? fin_amt($d) : ''; $in['line_cr'][] = $c ? fin_amt($c) : ''; $in['line_note'][] = $note;
            }
            $vid = fin_voucher_save($db, $R, $uid, $in, false, $today);
            $db->prepare('INSERT IGNORE INTO fin_feed (source, source_ref, voucher_id, made_by) VALUES (?,?,?,?)')->execute([$src, $ref, $vid, $uid]);
            fin_log($db, $uid, 'feed_draft', 'voucher', (string)$vid, $src);
            $made++;
        } catch (RuntimeException $e) { $bad[] = $ref . ': ' . $e->getMessage(); }
    }
    return [$made, $bad];
}

/** Records waiting to come in, over every feed this login may bring in (home page count). */
function fin_feed_waiting(PDO $db, array $u): int {
    try {
        $R = fin_rights($db, $u); $codes = user_roles((int)$u['id'], !empty($u['is_owner'])); $n = 0;
        if (empty($R['enter'])) return 0;
        foreach (array_keys(FIN_FEEDS) as $s) foreach (fin_feed_items($db, $R, $codes, $s)['items'] as $it) if ($it['problem'] === '') $n++;
        return $n;
    } catch (Throwable $e) { return 0; }
}

/* ------------------------------------------------------------------ openings and reports */

function fin_openings_open(array $B): bool { return empty($B['books_from']) || empty($B['locked_upto']) || $B['locked_upto'] < $B['books_from']; }
/** Save opening balances: ops[ledger id] = amount, side[ledger id] = dr / cr. Returns ledgers changed. */
function fin_openings_save(PDO $db, array $R, int $uid, array $in): int {
    fin_need($R, 'masters', 'Only an Accounts head / manager or the setup roles keep opening balances.');
    if (!fin_openings_open(fin_book($db))) throw new RuntimeException('The books start is locked; opening balances no longer change.');
    $L = fin_ledger_map($db); $n = 0; $bad = [];
    $up = $db->prepare('UPDATE fin_ledger SET opening_minor = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
    foreach ((array)($in['ops'] ?? []) as $lid => $amt) {
        $lid = (int)$lid;
        if (!isset($L[$lid])) continue;
        if ((int)$L[$lid]['restricted'] && empty($R['sens'])) continue;
        $s = trim((string)$amt); $m = $s === '' ? 0 : fin_minor($s);
        if ($m === null) { $bad[] = $L[$lid]['name']; continue; }
        if (($in['side'][$lid] ?? 'dr') === 'cr') $m = -$m;
        if ($m === (int)$L[$lid]['opening_minor']) continue;
        $up->execute([$m, $uid, $lid]); $n++;
    }
    if ($bad) throw new RuntimeException('Not an amount for: ' . implode(', ', array_slice($bad, 0, 5)) . ($n ? " ($n others saved)" : '') . '.');
    if ($n) fin_log($db, $uid, 'openings', 'ledger', '0', "$n ledgers");
    return $n;
}
function fin_pl(PDO $db, array $R, string $from, string $to): array { return fin_pl_build(fin_ledgers($db, true), fin_movements($db, $from, $to), !empty($R['sens'])); }
function fin_bs(PDO $db, array $R, string $date): array { return fin_bs_build(fin_ledgers($db, true), fin_balances($db, $date), !empty($R['sens'])); }

/** Group balance as at a date (Dr +), group and its children, restricted ledgers counted (a total is not a detail). */
function fin_group_balance(PDO $db, array $bal, string $name): int {
    $G = fin_groups($db, true); $ids = [];
    foreach ($G as $g) if ($g['name'] === $name) $ids[(int)$g['id']] = 1;
    for ($i = 0; $i < 4; $i++) foreach ($G as $g) if (isset($ids[(int)($g['parent_id'] ?? 0)])) $ids[(int)$g['id']] = 1;
    $t = 0; foreach (fin_ledgers($db, true) as $l) if (isset($ids[(int)$l['group_id']])) $t += $bal[(int)$l['id']] ?? 0;
    return $t;
}
/** Management view: profit this month and year, cash, customers / suppliers / tax owed, open bank lines, records to bring in. */
function fin_mgmt(PDO $db, array $R, array $u, string $today, int $fyStart): array {
    $bal = fin_balances($db, $today); $month = substr($today, 0, 7) . '-01'; $fy = fin_fy_start($today, $fyStart);
    $cash = 0; foreach (fin_ledgers($db) as $l) if (in_array($l['kind'], ['bank', 'cash'], true)) $cash += $bal[(int)$l['id']] ?? 0;
    $open = (int)$db->query("SELECT COUNT(*) FROM fin_stmt_line WHERE state = 'open'")->fetchColumn();
    $months = [];
    for ($i = 5; $i >= 0; $i--) { $f = date('Y-m-01', strtotime("$month -$i month")); $t = min(date('Y-m-t', strtotime($f)), $today); $p = fin_pl($db, $R, $f, $t); $months[] = [substr($f, 0, 7), $p['income_total'], $p['expense_total'], $p['profit']]; }
    return ['pl_month' => fin_pl($db, $R, $month, $today), 'pl_fy' => fin_pl($db, $R, $fy, $today), 'cash' => $cash,
            'debtors' => fin_group_balance($db, $bal, 'Sundry Debtors'), 'creditors' => -fin_group_balance($db, $bal, 'Sundry Creditors'),
            'tax' => -fin_group_balance($db, $bal, 'Duties & Taxes'), 'open_lines' => $open, 'feeds' => fin_feed_waiting($db, $u), 'months' => $months];
}

/* ------------------------------------------------------------------ bank reconciliation */

function fin_bank_ledger(PDO $db, int $lid): array {
    $L = fin_ledger_map($db);
    if (!isset($L[$lid]) || $L[$lid]['kind'] !== 'bank') throw new RuntimeException('Pick a bank ledger.');
    return $L[$lid];
}
/** Import a statement file for a bank ledger. The same line never comes in twice. Returns [added, skipped, errors]. */
function fin_stmt_import(PDO $db, array $R, int $uid, int $lid, string $path, string $name): array {
    fin_need($R, 'enter', 'Only the Accounts team imports statements.');
    fin_bank_ledger($db, $lid);
    [$lines, $err] = fin_stmt_rows(tbl_read($path, $name, 20000));
    if (!$lines && $err) throw new RuntimeException(implode(' ', array_slice($err, 0, 5)));
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO fin_stmt (ledger_id, file_name, created_by) VALUES (?,?,?)')->execute([$lid, mb_substr(basename($name), 0, 160), $uid]);
        $sid = (int)$db->lastInsertId(); $add = 0;
        $ins = $db->prepare('INSERT IGNORE INTO fin_stmt_line (stmt_id, ledger_id, line_date, narration, ref, out_minor, in_minor, line_hash) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($lines as [$d, $n, $r, $o, $i, $h]) { $ins->execute([$sid, $lid, $d, $n, $r, $o, $i, $h]); $add += $ins->rowCount(); }
        $skip = count($lines) - $add;
        $db->prepare('UPDATE fin_stmt SET added = ?, skipped = ? WHERE id = ?')->execute([$add, $skip, $sid]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    fin_log($db, $uid, 'stmt_import', 'stmt', (string)$sid, "$add lines, $skip already there");
    return [$add, $skip, $err];
}
/** Posted entries on a bank ledger not yet matched to a statement line: [key "voucher:line", voucher_id, vdate, number, narration, dr, cr]. */
function fin_rec_entries(PDO $db, int $lid, string $to = ''): array {
    $q = $db->prepare('SELECT CONCAT(v.id, ":", e.line_no) AS `key`, v.id AS voucher_id, v.vdate, v.number, v.narration, e.dr_minor, e.cr_minor FROM fin_entry e JOIN fin_voucher v ON v.id = e.voucher_id AND e.rev = v.rev
        WHERE e.ledger_id = ? AND v.status = "posted"' . ($to !== '' ? ' AND v.vdate <= ?' : '') . ' AND NOT EXISTS (SELECT 1 FROM fin_stmt_line s WHERE s.ledger_id = e.ledger_id AND s.state = "matched" AND s.voucher_id = v.id) ORDER BY v.vdate, v.id');
    $q->execute($to !== '' ? [$lid, $to] : [$lid]);
    return $q->fetchAll();
}
function fin_rec_lines(PDO $db, int $lid, string $state = 'open', int $limit = 300): array {
    $q = $db->prepare('SELECT s.*, v.number FROM fin_stmt_line s LEFT JOIN fin_voucher v ON v.id = s.voucher_id WHERE s.ledger_id = ? AND s.state = ? ORDER BY s.line_date, s.id LIMIT ' . max(1, min(2000, $limit)));
    $q->execute([$lid, $state]);
    return $q->fetchAll();
}
/** Match one line to a voucher (its entry on this ledger must be the same side and amount). */
function fin_rec_match(PDO $db, array $R, int $uid, int $line, int $vid): void {
    fin_need($R, 'enter', 'Only the Accounts team reconciles.');
    $q = $db->prepare('SELECT * FROM fin_stmt_line WHERE id = ?'); $q->execute([$line]);
    $l = $q->fetch() ?: throw new RuntimeException('Line not found.');
    if ($l['state'] !== 'open') throw new RuntimeException('That line is already settled.');
    $ok = false;
    foreach (fin_rec_entries($db, (int)$l['ledger_id']) as $e) if ((int)$e['voucher_id'] === $vid && ((int)$l['in_minor'] ? (int)$e['dr_minor'] === (int)$l['in_minor'] : (int)$e['cr_minor'] === (int)$l['out_minor'])) $ok = true;
    if (!$ok) throw new RuntimeException('That voucher has no unmatched entry of the same side and amount on this bank.');
    $db->prepare("UPDATE fin_stmt_line SET state = 'matched', voucher_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND state = 'open'")->execute([$vid, $uid, $line]);
    fin_log($db, $uid, 'rec_match', 'stmt_line', (string)$line, 'voucher #' . $vid);
}
/** Match every sure line of a bank ledger. Returns how many. */
function fin_rec_auto(PDO $db, array $R, int $uid, int $lid): int {
    fin_need($R, 'enter', 'Only the Accounts team reconciles.');
    $E = fin_rec_entries($db, $lid); $byKey = []; foreach ($E as $e) $byKey[$e['key']] = $e;
    $n = 0;
    foreach (fin_rec_sure(fin_rec_candidates(fin_rec_lines($db, $lid, 'open', 2000), $E)) as $line => $key) {
        $st = $db->prepare("UPDATE fin_stmt_line SET state = 'matched', voucher_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND state = 'open'");
        $st->execute([(int)$byKey[$key]['voucher_id'], $uid, $line]); $n += $st->rowCount();
    }
    if ($n) fin_log($db, $uid, 'rec_auto', 'ledger', (string)$lid, "$n lines");
    return $n;
}
/** Unmatch a line, or leave it out / bring it back — each with a reason. */
function fin_rec_set(PDO $db, array $R, int $uid, int $line, string $to, string $reason): void {
    fin_need($R, 'enter', 'Only the Accounts team reconciles.');
    $reason = mb_substr(trim($reason), 0, 300);
    if ($reason === '') throw new RuntimeException('Give the reason.');
    $from = ['open' => ['matched', 'out'], 'out' => ['open']][$to] ?? throw new RuntimeException('Unknown step.');
    $q = $db->prepare('UPDATE fin_stmt_line SET state = ?, voucher_id = NULL, note = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND state IN (' . implode(',', array_map(fn($s) => $db->quote($s), $from)) . ')');
    $q->execute([$to, $reason, $uid, $line]);
    if (!$q->rowCount()) throw new RuntimeException('That line is not in a state to change.');
    fin_log($db, $uid, $to === 'out' ? 'rec_out' : 'rec_unmatch', 'stmt_line', (string)$line, 'reason given');
}
/** Suggested ledger for each open line of a bank ledger from earlier matched lines: line id => [ledger id, times]. */
function fin_rec_suggestions(PDO $db, int $lid, array $open): array {
    $q = $db->prepare('SELECT s.narration, e.ledger_id FROM fin_stmt_line s JOIN fin_voucher v ON v.id = s.voucher_id JOIN fin_entry e ON e.voucher_id = v.id AND e.rev = v.rev AND e.ledger_id <> s.ledger_id
        WHERE s.ledger_id = ? AND s.state = "matched" ORDER BY s.id DESC LIMIT 2000');
    $q->execute([$lid]);
    $hist = array_map(fn($r) => [$r['narration'], (int)$r['ledger_id']], $q->fetchAll());
    $o = []; foreach ($open as $l) if ($s = fin_rec_suggest((string)$l['narration'], $hist)) $o[(int)$l['id']] = $s;
    return $o;
}
/** Make a DRAFT payment / receipt from an open line (suggested ledger, else the suspense ledger). Returns the voucher id. */
function fin_rec_draft(PDO $db, array $R, int $uid, int $line, int $ledger, string $today): int {
    fin_need($R, 'enter', 'Only the Accounts team makes vouchers.');
    $q = $db->prepare('SELECT * FROM fin_stmt_line WHERE id = ?'); $q->execute([$line]);
    $l = $q->fetch() ?: throw new RuntimeException('Line not found.');
    if ($l['state'] !== 'open') throw new RuntimeException('That line is already settled.');
    if (!$ledger) $ledger = fin_map_get($db)['bank.suspense'] ?? 0;
    if (!$ledger) throw new RuntimeException('Pick the other ledger, or set "Suspense for bank lines" on Ledger mapping.');
    $in = (int)$l['in_minor'] > 0; $amt = fin_amt($in ? (int)$l['in_minor'] : (int)$l['out_minor']);
    $bank = (int)$l['ledger_id'];
    $id = fin_voucher_save($db, $R, $uid, ['vtype' => $in ? 'receipt' : 'payment', 'vdate' => min((string)$l['line_date'], $today), 'request_id' => 'bank-' . $line,
        'narration' => mb_substr('Bank line: ' . $l['narration'] . ($l['ref'] !== '' ? ' · ' . $l['ref'] : ''), 0, 500),
        'line_ledger' => $in ? [$bank, $ledger] : [$ledger, $bank], 'line_dr' => [$amt, ''], 'line_cr' => ['', $amt], 'line_note' => ['', '']], false, $today);
    $db->prepare('UPDATE fin_stmt_line SET draft_id = ? WHERE id = ?')->execute([$id, $line]);
    fin_log($db, $uid, 'rec_draft', 'voucher', (string)$id, 'from bank line');
    return $id;
}
/** Reconciliation statement for a bank ledger as at a date. */
function fin_brs(PDO $db, int $lid, string $date): array {
    $book = fin_balances($db, $date)[$lid] ?? 0;
    $q = $db->prepare("SELECT * FROM fin_stmt_line WHERE ledger_id = ? AND state = 'open' AND line_date <= ? ORDER BY line_date");
    $q->execute([$lid, $date]);
    $bankOpen = $q->fetchAll(); $bookOpen = fin_rec_entries($db, $lid, $date);
    return fin_brs_build($book, $bookOpen, $bankOpen) + ['book_rows' => $bookOpen, 'bank_rows' => $bankOpen];
}
