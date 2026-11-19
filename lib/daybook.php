<?php
// Branch day book — rules. One daily cash sheet per branch, filled in with each person's own login: opening balance,
// bills, dispatch, money received, petty cash, closing balance.
//  - A person enters for their own branch (the branch of their linked employee record) and branches ticked on their
//    login, today and a few days back while the day is open. They see their own entries only, plus the day's totals.
//  - Viewers (roles from settings; default Super Admin, HR Manager, CEO) see every entry of their branches (Super
//    Admin / CEO every branch; others their ticked branches, none ticked = every branch), reports, the change register.
//  - Opening = the previous day's closing (cash counted on a closed day, else worked out); only viewers set the first
//    opening or change one, with a reason. Closing = opening + cash in − cash out. Close the day = cash counted (a note
//    when it differs); blocked while a payment screenshot / receipt is missing. Closed days are locked; viewers Check
//    or Reopen (reason).
//  - Money paid into a person's own account stays "with employee" until a viewer marks it settled.
//  - Nothing is deleted; every change is one line in dbk_log (before → after). Files in the database only.
// Pure functions first, then the database half.
declare(strict_types=1);
require_once __DIR__ . '/kyc.php';   // kyc_doc_mime()

const DBK_KINDS = ['bill' => 'Bill', 'dispatch' => 'Dispatch', 'receipt' => 'Money received', 'petty' => 'Petty cash'];
const DBK_RECEIPT_MODES = ['Cash', 'UPI', 'Bank transfer', 'Cheque'];
const DBK_PAY_MODES = ['Cash', 'UPI', 'Bank transfer'];
const DBK_PETTY_MODES = ['Cash', 'UPI', 'Card', 'Bank transfer', 'Own pocket'];
const DBK_FILE_BYTES = 8 * 1024 * 1024;
const DBK_FILES_PER_ENTRY = 4;
const DBK_AMOUNT_MAX = 100000000.0;
const DBK_BILL_DAYS = 60;
const DBK_LIST_MAX = 3000;
const DBK_DEFAULTS = ['view_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'], 'back_days' => 3,
    'petty_cats' => "Refreshments & pantry\nTravel & conveyance\nCourier & postage\nOffice supplies\nRepairs & maintenance\nUtilities\nCleaning\nOther"];

function dbk_settings(): array {
    $s = module_states()['daybook']['settings'] ?? [];
    return array_replace(DBK_DEFAULTS, array_intersect_key($s, DBK_DEFAULTS));
}

function dbk_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    $o['view_roles'] = array_values(array_intersect(array_keys($roles), (array)($in['view_roles'] ?? [])));
    if (!$o['view_roles']) $e[] = 'Pick at least one role that sees every entry.';
    $o['back_days'] = max(0, min(31, (int)($in['back_days'] ?? 3)));
    $cats = array_values(array_unique(array_filter(array_map(fn($x) => dbk_line($x, 60), preg_split('/\R/', (string)($in['petty_cats'] ?? '')) ?: []))));
    if (!$cats) $e[] = 'Keep at least one petty cash category.';
    $o['petty_cats'] = implode("\n", array_slice($cats, 0, 40));
    return [$o, $e];
}
function dbk_petty_cats(array $set): array { return array_values(array_filter(array_map('trim', explode("\n", (string)$set['petty_cats'])))); }

/** Viewer scope: null = every branch, list = those branches, false = not a viewer. Pure. */
function dbk_view_scope(array $roles, array $viewRoles, array $ticked) {
    if (array_intersect($roles, ['SUPERADMIN', 'CEO']) && array_intersect($roles, $viewRoles)) return null;
    if (!array_intersect($roles, $viewRoles)) return false;
    return $ticked ? array_values(array_map('intval', $ticked)) : null;
}

/** Everything the rules need about a login. Pure when $own is given. */
function dbk_ctx_for(array $u, array $roles, array $set, ?int $own, array $allBranches): array {
    $ticked = array_values(array_map('intval', $u['branches'] ?? []));
    return ['uid' => (int)$u['id'], 'name' => (string)$u['name'], 'code' => strtoupper((string)($u['emp_code'] ?? '')), 'own' => $own, 'ticked' => $ticked,
            'view' => dbk_view_scope($roles, (array)$set['view_roles'], $ticked), 'all' => array_values(array_map('intval', $allBranches)), 'back' => (int)$set['back_days']];
}
function dbk_is_viewer(array $c): bool { return $c['view'] !== false; }
function dbk_view_ok(array $c, int $b): bool { return $c['view'] === null || (is_array($c['view']) && in_array($b, $c['view'], true)); }
/** Branches this login fills in: own + ticked; viewers their whole scope. Pure. */
function dbk_entry_branches(array $c): array {
    if ($c['view'] === null) return $c['all'];
    $b = is_array($c['view']) ? $c['view'] : [];
    if ($c['own']) $b[] = (int)$c['own'];
    $b = array_merge($b, $c['ticked']);
    return array_values(array_filter($c['all'], fn($x) => in_array($x, $b, true)));
}
function dbk_can_use(array $c): bool { return dbk_is_viewer($c) || dbk_entry_branches($c) !== []; }

function dbk_line($s, int $max): string { return mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$s))), 0, $max); }
function dbk_amount($v, bool $zeroOk = false): float {
    if (is_string($v)) $v = str_replace([',', ' '], '', $v);
    if ($v === '' || $v === null || !is_numeric($v)) throw new RuntimeException('Enter the amount.');
    $n = round((float)$v, 2);
    if ($n < 0 || $n > DBK_AMOUNT_MAX || (!$zeroOk && $n <= 0)) throw new RuntimeException($zeroOk ? 'The amount cannot be negative.' : 'The amount must be more than zero.');
    return $n;
}
function dbk_amount_opt($v): float { return ($v === '' || $v === null) ? 0.0 : dbk_amount($v, true); }
function dbk_date(string $d): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new RuntimeException('That date is not right.');
    return $d;
}
function dbk_yes($v): bool { return in_array($v, [true, 1, '1', 'yes', 'on', 'true'], true); }
function dbk_pick(string $v, array $list, string $what): string { if (!in_array($v, $list, true)) throw new RuntimeException("Choose $what from the list."); return $v; }
function dbk_money(float $v): string { return number_format($v, 2); }

/**
 * Balance of $target from the branch's days in order. $days = rows (day, state, opening, opening_src, counted);
 * $cash = day => [in, out] (out negative). Opening = set by a viewer, else the previous day's closing (counted on a
 * closed day, else worked out); null when there is no earlier day and none was set. Pure.
 */
function dbk_walk(array $days, array $cash, string $target): array {
    usort($days, fn($a, $b) => strcmp((string)$a['day'], (string)$b['day']));
    $prev = null; $res = null;
    foreach ($days as $d) {
        $day = (string)$d['day'];
        if ($day > $target) break;
        $src = (string)($d['opening_src'] ?? 'auto');
        $open = $src === 'set' && $d['opening'] !== null ? (float)$d['opening'] : $prev;
        [$in, $out] = $cash[$day] ?? [0.0, 0.0];
        $calc = round(($open ?? 0.0) + $in + $out, 2);
        $closed = in_array((string)($d['state'] ?? 'open'), ['closed', 'checked'], true);
        $eff = $closed && $d['counted'] !== null ? (float)$d['counted'] : $calc;
        if ($day === $target) $res = ['opening' => $open, 'openingSrc' => $open === null ? 'none' : ($src === 'set' ? 'set' : 'auto'), 'cashIn' => round($in, 2), 'cashOut' => round(-$out, 2), 'calc' => $calc];
        $prev = $eff;
    }
    if ($res === null) {
        [$in, $out] = $cash[$target] ?? [0.0, 0.0];
        $res = ['opening' => $prev, 'openingSrc' => $prev === null ? 'none' : 'auto', 'cashIn' => round($in, 2), 'cashOut' => round(-$out, 2), 'calc' => round(($prev ?? 0.0) + $in + $out, 2)];
    }
    return $res;
}

/**
 * Checks one entry and works out its effect on cash in hand. $bill = the bill row a dispatch / money received points
 * at (already checked to be of this branch), or null. Pure.
 * @return array{data:array,party:string,city:string,amount:float,cash:float,ref:?int,withEmp:int}
 */
function dbk_clean(string $kind, array $in, ?array $bill, array $pettyCats): array {
    $t = fn(string $k, int $max = 120) => dbk_line($in[$k] ?? '', $max);
    switch ($kind) {
        case 'bill':
            $d = ['challan' => $t('challan', 40), 'bill_no' => $t('bill_no', 40), 'party' => $t('party'), 'city' => $t('city', 80), 'amount' => dbk_amount($in['amount'] ?? ''),
                  'frt_ours' => dbk_yes($in['frt_ours'] ?? false), 'frt_in_bill' => dbk_yes($in['frt_in_bill'] ?? false), 'frt_amt' => 0.0, 'note' => $t('note', 300)];
            if ($d['challan'] === '' && $d['bill_no'] === '') throw new RuntimeException('Enter the bill number or the challan number.');
            if ($d['party'] === '') throw new RuntimeException('Enter the party name.');
            if ($d['frt_ours'] || $d['frt_in_bill']) $d['frt_amt'] = dbk_amount($in['frt_amt'] ?? '');
            return ['data' => $d, 'party' => $d['party'], 'city' => $d['city'], 'amount' => $d['amount'], 'cash' => 0.0, 'ref' => null, 'withEmp' => 0];
        case 'dispatch':
            if (!$bill) throw new RuntimeException('Pick the bill from the list.');
            $how = dbk_pick((string)($in['how'] ?? ''), ['direct', 'lr'], 'how it went (direct or by transporter)');
            $d = ['bill' => (int)$bill['id'], 'how' => $how, 'transporter' => '', 'lr_no' => '', 'auto_amt' => dbk_amount_opt($in['auto_amt'] ?? ''), 'auto_mode' => '',
                  'frt_by' => dbk_pick((string)($in['frt_by'] ?? 'none'), ['ours', 'party', 'none'], 'who paid the freight'), 'frt_amt' => 0.0, 'frt_mode' => '', 'note' => $t('note', 300)];
            if ($how === 'lr') { $d['transporter'] = $t('transporter'); $d['lr_no'] = $t('lr_no', 40); if ($d['transporter'] === '' || $d['lr_no'] === '') throw new RuntimeException('Enter the transporter and the LR number.'); }
            if ($d['auto_amt'] > 0) $d['auto_mode'] = dbk_pick((string)($in['auto_mode'] ?? ''), DBK_PAY_MODES, 'how the local transport was paid');
            if ($d['frt_by'] === 'ours') { $d['frt_amt'] = dbk_amount($in['frt_amt'] ?? ''); $d['frt_mode'] = dbk_pick((string)($in['frt_mode'] ?? ''), DBK_PAY_MODES, 'how the freight was paid'); }
            $cash = -(($d['auto_mode'] === 'Cash' ? $d['auto_amt'] : 0) + ($d['frt_mode'] === 'Cash' ? $d['frt_amt'] : 0));
            return ['data' => $d, 'party' => (string)$bill['party'], 'city' => (string)$bill['city'], 'amount' => round($d['auto_amt'] + $d['frt_amt'], 2), 'cash' => round($cash, 2), 'ref' => (int)$bill['id'], 'withEmp' => 0];
        case 'receipt':
            $d = ['bill' => $bill ? (int)$bill['id'] : 0, 'party' => $t('party') ?: (string)($bill['party'] ?? ''), 'city' => $t('city', 80) ?: (string)($bill['city'] ?? ''),
                  'amount' => dbk_amount($in['amount'] ?? ''), 'mode' => dbk_pick((string)($in['mode'] ?? ''), DBK_RECEIPT_MODES, 'how the money came'), 'into' => 'company', 'person' => '', 'ref' => $t('ref', 80), 'note' => $t('note', 300)];
            if ($d['party'] === '') throw new RuntimeException('Enter the party name.');
            if (in_array($d['mode'], ['UPI', 'Bank transfer'], true)) {
                $d['into'] = dbk_pick((string)($in['into'] ?? ''), ['company', 'person'], 'whose account it went into');
                if ($d['into'] === 'person') {
                    $d['person'] = $t('person');
                    if ($d['person'] === '') throw new RuntimeException("Whose account did it go into? Enter the person's name.");
                    if ($d['ref'] === '') throw new RuntimeException('Enter the payment reference.');
                }
            }
            return ['data' => $d, 'party' => $d['party'], 'city' => $d['city'], 'amount' => $d['amount'], 'cash' => $d['mode'] === 'Cash' ? $d['amount'] : 0.0, 'ref' => $bill ? (int)$bill['id'] : null, 'withEmp' => $d['into'] === 'person' ? 1 : 0];
        case 'petty':
            $d = ['particulars' => $t('particulars', 200), 'category' => dbk_pick((string)($in['category'] ?? ''), $pettyCats, 'a category'), 'purpose' => $t('purpose', 200), 'bill_no' => $t('bill_no', 40),
                  'amount' => dbk_amount($in['amount'] ?? ''), 'mode' => dbk_pick((string)($in['mode'] ?? ''), DBK_PETTY_MODES, 'how it was paid'),
                  'no_receipt' => dbk_yes($in['no_receipt'] ?? false), 'no_receipt_why' => $t('no_receipt_why', 300), 'note' => $t('note', 300)];
            if ($d['particulars'] === '') throw new RuntimeException('Enter the particulars (what was bought or paid for).');
            if ($d['no_receipt'] && $d['no_receipt_why'] === '') throw new RuntimeException('Say why there is no receipt: paid to whom and why.');
            if (!$d['no_receipt']) $d['no_receipt_why'] = '';
            return ['data' => $d, 'party' => '', 'city' => '', 'amount' => $d['amount'], 'cash' => $d['mode'] === 'Cash' ? -$d['amount'] : 0.0, 'ref' => null, 'withEmp' => 0];
    }
    throw new RuntimeException('Unknown entry.');
}

/** Why a day cannot be closed yet. $entries in dbk_present() shape. Pure. */
function dbk_close_blocks(array $entries): array {
    $out = [];
    foreach ($entries as $e) {
        if (!empty($e['removedAt'])) continue;
        $nf = count($e['files'] ?? []);
        if ($e['kind'] === 'receipt' && !empty($e['withEmp']) && !$nf) $out[] = 'Payment screenshot missing: ' . ($e['data']['party'] ?? '') . ' ' . dbk_money($e['amount']) . ' (' . $e['by'] . ')';
        if ($e['kind'] === 'petty' && empty($e['data']['no_receipt']) && !$nf) $out[] = 'Receipt missing: ' . ($e['data']['particulars'] ?? '') . ' ' . dbk_money($e['amount']) . ' (' . $e['by'] . ')';
    }
    return $out;
}

/** Short words for a log line / list. Pure. */
function dbk_summary(string $kind, array $p): string {
    $amt = dbk_money((float)($p['amount'] ?? 0));
    return match ($kind) {
        'bill' => 'Bill ' . trim(($p['bill_no'] ?? '') . (($p['challan'] ?? '') !== '' ? ' / challan ' . $p['challan'] : '')) . ' · ' . ($p['party'] ?? '') . ' · ' . $amt,
        'dispatch' => 'Dispatch · ' . ($p['party'] ?? '') . ' · ' . (($p['how'] ?? '') === 'lr' ? ($p['transporter'] ?? '') . ' LR ' . ($p['lr_no'] ?? '') : 'direct to party'),
        'receipt' => 'Money received · ' . ($p['party'] ?? '') . ' · ' . $amt . ' · ' . ($p['mode'] ?? ''),
        'petty' => 'Petty cash · ' . ($p['particulars'] ?? '') . ' · ' . $amt,
        default => $kind,
    };
}

/** Totals by branch and by person. $entries in dbk_present() shape. Pure. */
function dbk_totals(array $entries): array {
    $z = ['bills' => 0, 'billAmt' => 0.0, 'dispatches' => 0, 'received' => 0.0, 'recCash' => 0.0, 'recUpi' => 0.0, 'recBank' => 0.0, 'recCheque' => 0.0,
          'withEmp' => 0.0, 'petty' => 0.0, 'pettyCash' => 0.0, 'freight' => 0.0, 'auto' => 0.0, 'entries' => 0];
    $br = []; $pp = [];
    foreach ($entries as $e) {
        $b = (int)$e['branch']; $k = (int)$e['byId'];
        $br[$b] ??= $z; $pp[$k] ??= $z + ['name' => $e['by'], 'code' => $e['byCode']];
        foreach ([&$br[$b], &$pp[$k]] as &$t) {
            $t['entries']++; $d = $e['data'];
            switch ($e['kind']) {
                case 'bill': $t['bills']++; $t['billAmt'] += $e['amount']; break;
                case 'dispatch': $t['dispatches']++; $t['auto'] += (float)($d['auto_amt'] ?? 0); $t['freight'] += (float)($d['frt_amt'] ?? 0); break;
                case 'receipt':
                    $t['received'] += $e['amount'];
                    $t[['Cash' => 'recCash', 'UPI' => 'recUpi', 'Bank transfer' => 'recBank', 'Cheque' => 'recCheque'][$d['mode'] ?? 'Cash'] ?? 'recCash'] += $e['amount'];
                    if ($e['withEmp'] && empty($e['settledAt'])) $t['withEmp'] += $e['amount'];
                    break;
                case 'petty': $t['petty'] += $e['amount']; if (($d['mode'] ?? '') === 'Cash') $t['pettyCash'] += $e['amount']; break;
            }
        }
        unset($t);
    }
    $r = function (array $t) { foreach ($t as $k => $v) if (is_float($v)) $t[$k] = round($v, 2); return $t; };
    $br = array_map($r, $br); ksort($br);
    $pp = array_map($r, $pp); uasort($pp, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
    return ['branches' => $br, 'people' => $pp];
}

function dbk_csv_cell($v): string {
    $s = (string)$v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) $s = "'" . $s;
    return '"' . str_replace('"', '""', $s) . '"';
}

// ---------- database ----------

function dbk_ctx(PDO $db, array $u): array {
    $own = null;
    if (!empty($u['emp_code']) && ($e = emp_by_code($db, (string)$u['emp_code'])) && $e['branch_id']) $own = (int)$e['branch_id'];
    return dbk_ctx_for($u, user_roles((int)$u['id'], !empty($u['is_owner'])), dbk_settings(), $own, array_column(branches(), 'id'));
}

function dbk_day_row(PDO $db, int $b, string $day): ?array { $s = $db->prepare('SELECT * FROM dbk_day WHERE branch_id = ? AND day = ?'); $s->execute([$b, $day]); return $s->fetch() ?: null; }
function dbk_day_make(PDO $db, int $b, string $day): array { $db->prepare('INSERT IGNORE INTO dbk_day (branch_id, day) VALUES (?, ?)')->execute([$b, $day]); return dbk_day_row($db, $b, $day); }

function dbk_balance(PDO $db, int $b, string $day): array {
    $s = $db->prepare('SELECT day, state, opening, opening_src, counted FROM dbk_day WHERE branch_id = ? AND day <= ? ORDER BY day'); $s->execute([$b, $day]);
    $days = $s->fetchAll();
    $c = $db->prepare('SELECT day, SUM(CASE WHEN cash > 0 THEN cash ELSE 0 END) AS i, SUM(CASE WHEN cash < 0 THEN cash ELSE 0 END) AS o FROM dbk_entry WHERE branch_id = ? AND day <= ? AND removed_at IS NULL GROUP BY day');
    $c->execute([$b, $day]);
    $cash = []; foreach ($c->fetchAll() as $r) $cash[(string)$r['day']] = [(float)$r['i'], (float)$r['o']];
    return dbk_walk($days, $cash, $day);
}

function dbk_entry_row(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM dbk_entry WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

function dbk_bill(PDO $db, int $b, int $id, int $selfId = 0, bool $forDispatch = false): ?array {
    if ($id <= 0) return null;
    $r = dbk_entry_row($db, $id);
    if (!$r || $r['kind'] !== 'bill' || (int)$r['branch_id'] !== $b || $r['removed_at'] !== null) throw new RuntimeException('Pick the bill from the list.');
    if ($forDispatch) {
        $s = $db->prepare("SELECT id FROM dbk_entry WHERE kind = 'dispatch' AND ref_id = ? AND removed_at IS NULL AND id <> ? LIMIT 1"); $s->execute([$id, $selfId]);
        if ($s->fetchColumn()) throw new RuntimeException('This bill is already dispatched. Change that dispatch instead.');
    }
    return $r;
}

function dbk_clean_db(PDO $db, string $kind, array $in, int $b, int $selfId = 0): array {
    $bill = in_array($kind, ['dispatch', 'receipt'], true) ? dbk_bill($db, $b, (int)($in['bill'] ?? 0), $selfId, $kind === 'dispatch') : null;
    return dbk_clean($kind, $in, $bill, dbk_petty_cats(dbk_settings()));
}

function dbk_present(array $r, array $files = []): array {
    return ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'branch' => (int)$r['branch_id'], 'day' => (string)$r['day'], 'party' => (string)$r['party'], 'city' => (string)$r['city'],
            'amount' => (float)$r['amount'], 'cash' => (float)$r['cash'], 'ref' => $r['ref_id'] !== null ? (int)$r['ref_id'] : null, 'data' => json_decode((string)$r['data'], true) ?: [],
            'withEmp' => (int)$r['with_emp'] === 1, 'settledAt' => $r['settled_at'], 'settleNote' => (string)$r['settle_note'], 'byId' => (int)$r['by_id'], 'by' => (string)$r['by_name'],
            'byCode' => (string)$r['by_code'], 'at' => (string)$r['created_at'], 'editedAt' => $r['updated_at'], 'removedAt' => $r['removed_at'], 'removeReason' => (string)$r['remove_reason'], 'files' => $files];
}
function dbk_log_shape(array $r): array { $p = dbk_present($r); return ['party' => $p['party'], 'city' => $p['city'], 'amount' => $p['amount'], 'cash' => $p['cash']] + $p['data']; }

function dbk_log(PDO $db, array $c, int $b, ?string $day, string $act, string $detail = '', ?int $entry = null, string $kind = '', ?array $before = null, ?array $after = null): void {
    $j = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
    $db->prepare('INSERT INTO dbk_log (by_id, by_name, branch_id, day, entry_id, kind, act, detail, before_j, after_j) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$c['uid'], mb_substr($c['name'], 0, 120), $b, $day, $entry, $kind, $act, mb_substr($detail, 0, 300), $j($before), $j($after)]);
}

/** May this login change this day of this branch? Throws the reason when not. */
function dbk_day_writable(PDO $db, array $c, int $b, string $day, ?string $today = null): void {
    $today ??= date('Y-m-d');
    dbk_date($day);
    if (!in_array($b, dbk_entry_branches($c), true)) throw new RuntimeException('You do not fill in the day book of that branch.');
    if ($day > $today) throw new RuntimeException('That day has not come yet.');
    if (!dbk_is_viewer($c) && $day < date('Y-m-d', strtotime($today . ' -' . $c['back'] . ' days'))) throw new RuntimeException('Only the last ' . $c['back'] . ' days can be filled in. Ask a viewer for an older day.');
    $d = dbk_day_row($db, $b, $day);
    if ($d && $d['state'] !== 'open') throw new RuntimeException('This day is closed. A viewer can reopen it.');
}

function dbk_add(PDO $db, array $c, int $b, string $day, string $kind, array $in, ?string $today = null): int {
    if (!isset(DBK_KINDS[$kind])) throw new RuntimeException('Unknown entry.');
    dbk_day_writable($db, $c, $b, $day, $today);
    $x = dbk_clean_db($db, $kind, $in, $b);
    $db->beginTransaction();
    try {
        $d = dbk_day_make($db, $b, $day);
        if ($d['state'] !== 'open') throw new RuntimeException('This day is closed. A viewer can reopen it.');
        $db->prepare('INSERT INTO dbk_entry (day_id, branch_id, day, kind, ref_id, party, city, amount, cash, data, with_emp, by_id, by_code, by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([(int)$d['id'], $b, $day, $kind, $x['ref'], $x['party'], $x['city'], $x['amount'], $x['cash'], json_encode($x['data'], JSON_UNESCAPED_UNICODE), $x['withEmp'], $c['uid'], $c['code'], mb_substr($c['name'], 0, 120)]);
        $id = (int)$db->lastInsertId();
        $row = dbk_entry_row($db, $id);
        dbk_log($db, $c, $b, $day, 'add', dbk_summary($kind, dbk_log_shape($row)), $id, $kind, null, dbk_log_shape($row));
        dbk_party_keep($db, $c, $kind, $x['data']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $id;
}

function dbk_entry_for_change(PDO $db, array $c, int $id): array {
    $r = dbk_entry_row($db, $id);
    if (!$r || $r['removed_at'] !== null) throw new RuntimeException('That entry is not there.');
    if ((int)$r['by_id'] !== $c['uid'] && !(dbk_is_viewer($c) && dbk_view_ok($c, (int)$r['branch_id']))) throw new RuntimeException('You can change only your own entries.');
    $d = dbk_day_row($db, (int)$r['branch_id'], (string)$r['day']);
    if ($d && $d['state'] !== 'open') throw new RuntimeException('This day is closed. A viewer can reopen it.');
    return $r;
}

function dbk_edit(PDO $db, array $c, int $id, array $in): void {
    $r = dbk_entry_for_change($db, $c, $id);
    $x = dbk_clean_db($db, (string)$r['kind'], $in, (int)$r['branch_id'], $id);
    $before = dbk_log_shape($r);
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE dbk_entry SET ref_id = ?, party = ?, city = ?, amount = ?, cash = ?, data = ?, with_emp = ?, settled_at = IF(? = 1, settled_at, NULL), settled_by = IF(? = 1, settled_by, NULL), updated_at = NOW(), updated_by = ? WHERE id = ?')
           ->execute([$x['ref'], $x['party'], $x['city'], $x['amount'], $x['cash'], json_encode($x['data'], JSON_UNESCAPED_UNICODE), $x['withEmp'], $x['withEmp'], $x['withEmp'], $c['uid'], $id]);
        $after = dbk_log_shape(dbk_entry_row($db, $id));
        if ($after != $before) dbk_log($db, $c, (int)$r['branch_id'], (string)$r['day'], 'edit', dbk_summary((string)$r['kind'], $after), $id, (string)$r['kind'], $before, $after);
        dbk_party_keep($db, $c, (string)$r['kind'], $x['data']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function dbk_remove(PDO $db, array $c, int $id, string $reason): void {
    $r = dbk_entry_for_change($db, $c, $id);
    $reason = dbk_line($reason, 300);
    if ($reason === '') throw new RuntimeException('Say why it is removed.');
    if ($r['kind'] === 'bill') {
        $s = $db->prepare('SELECT COUNT(*) FROM dbk_entry WHERE ref_id = ? AND removed_at IS NULL'); $s->execute([$id]);
        if ((int)$s->fetchColumn()) throw new RuntimeException('This bill has a dispatch or money received. Remove those first.');
    }
    $db->prepare('UPDATE dbk_entry SET removed_at = NOW(), removed_by = ?, remove_reason = ? WHERE id = ? AND removed_at IS NULL')->execute([$c['uid'], $reason, $id]);
    dbk_log($db, $c, (int)$r['branch_id'], (string)$r['day'], 'remove', $reason, $id, (string)$r['kind'], dbk_log_shape($r), null);
}

function dbk_party_keep(PDO $db, array $c, string $kind, array $d): void {
    $ins = $db->prepare('INSERT IGNORE INTO dbk_party (kind, name, city, created_by) VALUES (?,?,?,?)');
    if (in_array($kind, ['bill', 'receipt'], true) && ($d['party'] ?? '') !== '') $ins->execute(['party', $d['party'], (string)($d['city'] ?? ''), $c['uid']]);
    if ($kind === 'dispatch' && ($d['transporter'] ?? '') !== '') $ins->execute(['transporter', $d['transporter'], '', $c['uid']]);
}
function dbk_parties(PDO $db): array {
    $o = ['party' => [], 'transporter' => []];
    foreach ($db->query('SELECT kind, name, city FROM dbk_party ORDER BY name, city LIMIT 5000') as $r) $o[$r['kind'] === 'transporter' ? 'transporter' : 'party'][] = $r;
    return $o;
}

function dbk_file_add(PDO $db, array $c, int $entryId, string $data, string $name): int {
    $r = dbk_entry_for_change($db, $c, $entryId);
    if ($data === '') throw new RuntimeException('The file is empty.');
    if (strlen($data) > DBK_FILE_BYTES) throw new RuntimeException('Up to 8 MB a file.');
    $n = $db->prepare('SELECT COUNT(*) FROM dbk_file WHERE entry_id = ?'); $n->execute([$entryId]);
    if ((int)$n->fetchColumn() >= DBK_FILES_PER_ENTRY) throw new RuntimeException('Up to ' . DBK_FILES_PER_ENTRY . ' files on one entry.');
    $type = kyc_doc_mime($data);
    if (!$type) throw new RuntimeException('Photos (JPEG, PNG, WebP) or PDF only.');
    $name = mb_substr((string)preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', basename($name)) ?: 'file', 0, 120);
    $db->prepare('INSERT INTO dbk_file (entry_id, by_id, name, type, size, data) VALUES (?,?,?,?,?,?)')->execute([$entryId, $c['uid'], $name, $type, strlen($data), $data]);
    $fid = (int)$db->lastInsertId();
    dbk_log($db, $c, (int)$r['branch_id'], (string)$r['day'], 'file', $name, $entryId, (string)$r['kind']);
    return $fid;
}
function dbk_entry_visible(array $c, array $r): bool { return (int)$r['by_id'] === $c['uid'] || (dbk_is_viewer($c) && dbk_view_ok($c, (int)$r['branch_id'])); }
function dbk_file_get(PDO $db, array $c, int $fid): ?array {
    $s = $db->prepare('SELECT f.name, f.type, f.data, e.by_id, e.branch_id FROM dbk_file f JOIN dbk_entry e ON e.id = f.entry_id WHERE f.id = ?'); $s->execute([$fid]);
    $f = $s->fetch();
    return $f && dbk_entry_visible($c, $f) ? $f : null;
}
function dbk_files_for(PDO $db, array $ids): array {
    if (!$ids) return [];
    $o = [];
    foreach ($db->query('SELECT id, entry_id, name, type FROM dbk_file WHERE entry_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id') as $f) $o[(int)$f['entry_id']][] = ['id' => (int)$f['id'], 'name' => $f['name'], 'type' => $f['type']];
    return $o;
}

function dbk_day_entries(PDO $db, int $b, string $day): array {
    $s = $db->prepare('SELECT * FROM dbk_entry WHERE branch_id = ? AND day = ? AND removed_at IS NULL ORDER BY id'); $s->execute([$b, $day]);
    $rows = $s->fetchAll(); $files = dbk_files_for($db, array_column($rows, 'id'));
    return array_map(fn($r) => dbk_present($r, $files[(int)$r['id']] ?? []), $rows);
}

function dbk_close(PDO $db, array $c, int $b, string $day, $counted, string $note, ?string $today = null): void {
    $today ??= date('Y-m-d');
    dbk_date($day);
    if (!in_array($b, dbk_entry_branches($c), true)) throw new RuntimeException('You do not fill in the day book of that branch.');
    if ($day > $today) throw new RuntimeException('That day has not come yet.');
    $d = dbk_day_make($db, $b, $day);
    if ($d['state'] !== 'open') throw new RuntimeException('This day is already closed.');
    if ($bl = dbk_close_blocks(dbk_day_entries($db, $b, $day))) throw new RuntimeException('Before closing: ' . implode('; ', array_slice($bl, 0, 3)) . (count($bl) > 3 ? ' …' : ''));
    $bal = dbk_balance($db, $b, $day);
    if ($bal['opening'] === null) throw new RuntimeException('The opening balance is not set yet. A viewer sets the first opening balance of a branch.');
    $cnt = dbk_amount($counted, true);
    $note = dbk_line($note, 300);
    if (abs($cnt - $bal['calc']) >= 0.01 && $note === '') throw new RuntimeException('The cash counted is different from the closing balance. Add a note saying why.');
    $db->prepare("UPDATE dbk_day SET state = 'closed', calc_close = ?, counted = ?, close_note = ?, closed_by = ?, closed_at = NOW() WHERE id = ? AND state = 'open'")->execute([$bal['calc'], $cnt, $note, $c['uid'], (int)$d['id']]);
    dbk_log($db, $c, $b, $day, 'close', 'Closing ' . dbk_money($bal['calc']) . ' · counted ' . dbk_money($cnt) . ($note !== '' ? ' · ' . $note : ''), null, '', null, ['calc' => $bal['calc'], 'counted' => $cnt, 'note' => $note]);
}

function dbk_viewer_day(PDO $db, array $c, int $b, string $day): array {
    dbk_date($day);
    if (!dbk_is_viewer($c) || !dbk_view_ok($c, $b)) throw new RuntimeException('Only the day book viewers can do this.');
    return dbk_day_make($db, $b, $day);
}
function dbk_check(PDO $db, array $c, int $b, string $day, string $note): void {
    $d = dbk_viewer_day($db, $c, $b, $day);
    if ($d['state'] !== 'closed') throw new RuntimeException($d['state'] === 'checked' ? 'Already checked.' : 'The day has to be closed before it is checked.');
    $note = dbk_line($note, 300);
    $db->prepare("UPDATE dbk_day SET state = 'checked', checked_by = ?, checked_at = NOW(), check_note = ? WHERE id = ?")->execute([$c['uid'], $note, (int)$d['id']]);
    dbk_log($db, $c, $b, $day, 'check', $note);
}
function dbk_reopen(PDO $db, array $c, int $b, string $day, string $note): void {
    $d = dbk_viewer_day($db, $c, $b, $day);
    if ($d['state'] === 'open') throw new RuntimeException('This day is open.');
    $note = dbk_line($note, 300);
    if ($note === '') throw new RuntimeException('Say why the day is reopened.');
    $before = ['state' => $d['state'], 'calc' => $d['calc_close'] !== null ? (float)$d['calc_close'] : null, 'counted' => $d['counted'] !== null ? (float)$d['counted'] : null, 'note' => $d['close_note']];
    $db->prepare("UPDATE dbk_day SET state = 'open', calc_close = NULL, counted = NULL, close_note = '', closed_by = NULL, closed_at = NULL, checked_by = NULL, checked_at = NULL, check_note = '' WHERE id = ?")->execute([(int)$d['id']]);
    dbk_log($db, $c, $b, $day, 'reopen', $note, null, '', $before, null);
}
function dbk_set_opening(PDO $db, array $c, int $b, string $day, $amount, string $note): void {
    $d = dbk_viewer_day($db, $c, $b, $day);
    if ($d['state'] !== 'open') throw new RuntimeException('Reopen the day first.');
    $a = dbk_amount($amount, true);
    $note = dbk_line($note, 300);
    if ($note === '') throw new RuntimeException('Say why the opening balance is set or changed.');
    $old = dbk_balance($db, $b, $day);
    $db->prepare("UPDATE dbk_day SET opening = ?, opening_src = 'set', opening_note = ?, opening_by = ?, opening_at = NOW() WHERE id = ?")->execute([$a, $note, $c['uid'], (int)$d['id']]);
    dbk_log($db, $c, $b, $day, 'opening', 'Opening ' . dbk_money($a) . ' · ' . $note, null, '', ['opening' => $old['opening'], 'src' => $old['openingSrc']], ['opening' => $a, 'src' => 'set']);
}
function dbk_settle(PDO $db, array $c, int $id, string $note): void {
    $r = dbk_entry_row($db, $id);
    if (!$r || $r['removed_at'] !== null || (int)$r['with_emp'] !== 1) throw new RuntimeException('That entry is not money with an employee.');
    if (!dbk_is_viewer($c) || !dbk_view_ok($c, (int)$r['branch_id'])) throw new RuntimeException('Only the day book viewers can do this.');
    if ($r['settled_at'] !== null) throw new RuntimeException('Already settled.');
    $db->prepare('UPDATE dbk_entry SET settled_at = NOW(), settled_by = ?, settle_note = ? WHERE id = ? AND settled_at IS NULL')->execute([$c['uid'], dbk_line($note, 300), $id]);
    dbk_log($db, $c, (int)$r['branch_id'], (string)$r['day'], 'settle', dbk_line($note, 300), $id, 'receipt');
}

/** The day sheet of one branch: balance, state, and the entries this login may see. */
function dbk_day_view(PDO $db, array $c, int $b, string $day, ?string $today = null): array {
    $today ??= date('Y-m-d');
    dbk_date($day);
    $viewer = dbk_is_viewer($c) && dbk_view_ok($c, $b);
    if (!$viewer && !in_array($b, dbk_entry_branches($c), true)) throw new RuntimeException('You do not have the day book of that branch.');
    $d = dbk_day_row($db, $b, $day);
    $all = dbk_day_entries($db, $b, $day);
    $mine = array_values(array_filter($all, fn($e) => $viewer || $e['byId'] === $c['uid']));
    $state = $d['state'] ?? 'open';
    $canEnter = in_array($b, dbk_entry_branches($c), true) && $day <= $today && ($viewer || $day >= date('Y-m-d', strtotime($today . ' -' . $c['back'] . ' days')));
    return ['branch' => $b, 'day' => $day, 'state' => $state, 'viewer' => $viewer, 'row' => $d] + dbk_balance($db, $b, $day)
        + ['entries' => $mine, 'others' => count($all) - count($mine), 'canEnter' => $canEnter && $state === 'open', 'blocks' => dbk_close_blocks($all)];
}

/** Bills of a branch for the dispatch / money received pickers: number, party, city, amount, dispatched. */
function dbk_bill_choices(PDO $db, int $b, ?string $today = null): array {
    $today ??= date('Y-m-d');
    $s = $db->prepare("SELECT b.id, b.day, b.party, b.city, b.amount, b.data, (SELECT COUNT(*) FROM dbk_entry x WHERE x.ref_id = b.id AND x.kind = 'dispatch' AND x.removed_at IS NULL) AS sent
                       FROM dbk_entry b WHERE b.branch_id = ? AND b.kind = 'bill' AND b.removed_at IS NULL AND b.day >= ? ORDER BY b.day DESC, b.id DESC LIMIT 400");
    $s->execute([$b, date('Y-m-d', strtotime($today . ' -' . DBK_BILL_DAYS . ' days'))]);
    return array_map(function ($r) { $d = json_decode((string)$r['data'], true) ?: []; return ['id' => (int)$r['id'], 'day' => $r['day'], 'no' => trim(($d['bill_no'] ?? '') . (($d['challan'] ?? '') !== '' ? ' / ' . $d['challan'] : '')), 'party' => $r['party'], 'city' => $r['city'], 'amount' => (float)$r['amount'], 'sent' => (int)$r['sent'] > 0]; }, $s->fetchAll());
}

function dbk_scope_sql(array $c, string $a = 'e'): array {
    if ($c['view'] === null) return ['1=1', []];
    if (is_array($c['view']) && $c['view']) return ["($a.branch_id IN (" . implode(',', array_fill(0, count($c['view']), '?')) . ") OR $a.by_id = ?)", [...$c['view'], $c['uid']]];
    return ["$a.by_id = ?", [$c['uid']]];
}

function dbk_range(string $from, string $to, ?string $today = null): array {
    $today ??= date('Y-m-d');
    $from = $from !== '' ? dbk_date($from) : date('Y-m-01', strtotime($today));
    $to = $to !== '' ? dbk_date($to) : $today;
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) > 400 * 86400) throw new RuntimeException('Pick at most about a year at a time.');
    return [$from, $to];
}

function dbk_entries(PDO $db, array $c, string $from, string $to, array $f = []): array {
    [$w, $p] = dbk_scope_sql($c);
    $sql = "SELECT e.* FROM dbk_entry e WHERE $w AND e.day BETWEEN ? AND ? AND e.removed_at IS NULL"; $p[] = $from; $p[] = $to;
    if ((int)($f['branch'] ?? 0)) { $sql .= ' AND e.branch_id = ?'; $p[] = (int)$f['branch']; }
    if ((int)($f['user'] ?? 0)) { $sql .= ' AND e.by_id = ?'; $p[] = (int)$f['user']; }
    if (isset(DBK_KINDS[$f['kind'] ?? ''])) { $sql .= ' AND e.kind = ?'; $p[] = $f['kind']; }
    $s = $db->prepare($sql . ' ORDER BY e.day DESC, e.id DESC LIMIT ' . DBK_LIST_MAX); $s->execute($p);
    $rows = $s->fetchAll(); $files = dbk_files_for($db, array_column($rows, 'id'));
    return array_map(fn($r) => dbk_present($r, $files[(int)$r['id']] ?? []), $rows);
}

/** Change register: every line this login may see (viewers their branches, others their own), newest first. */
function dbk_register(PDO $db, array $c, string $from, string $to, int $branch = 0): array {
    if (dbk_is_viewer($c)) { if ($c['view'] === null) { $w = '1=1'; $p = []; } else { $w = '(l.branch_id IN (' . implode(',', array_fill(0, max(1, count($c['view'])), '?')) . ') OR l.by_id = ?)'; $p = [...($c['view'] ?: [0]), $c['uid']]; } }
    else { $w = 'l.by_id = ?'; $p = [$c['uid']]; }
    $sql = "SELECT l.* FROM dbk_log l WHERE $w AND (l.day BETWEEN ? AND ? OR l.at BETWEEN ? AND ?)"; array_push($p, $from, $to, $from . ' 00:00:00', $to . ' 23:59:59');
    if ($branch) { $sql .= ' AND l.branch_id = ?'; $p[] = $branch; }
    $s = $db->prepare($sql . ' ORDER BY l.id DESC LIMIT ' . DBK_LIST_MAX); $s->execute($p);
    return $s->fetchAll();
}

/** Viewer report: totals, days (open / closed / checked, cash differences), freight by transporter, bills not dispatched, money still with employees. */
function dbk_report(PDO $db, array $c, string $from, string $to, int $branch = 0): array {
    if (!dbk_is_viewer($c)) throw new RuntimeException('Reports are for the day book viewers.');
    if ($branch && !dbk_view_ok($c, $branch)) throw new RuntimeException('That branch is not in your access.');
    $e = array_values(array_filter(dbk_entries($db, $c, $from, $to, ['branch' => $branch]), fn($x) => dbk_view_ok($c, $x['branch'])));
    [$w, $p] = $c['view'] === null ? ['1=1', []] : ['branch_id IN (' . implode(',', array_fill(0, max(1, count($c['view'])), '?')) . ')', $c['view'] ?: [0]];
    $s = $db->prepare("SELECT branch_id, day, state, calc_close, counted FROM dbk_day WHERE $w AND day BETWEEN ? AND ?" . ($branch ? ' AND branch_id = ?' : '') . ' ORDER BY day');
    $s->execute([...$p, $from, $to, ...($branch ? [$branch] : [])]);
    $days = [];
    foreach ($s->fetchAll() as $d) {
        $b = (int)$d['branch_id'];
        $days[$b] ??= ['open' => 0, 'closed' => 0, 'checked' => 0, 'diff' => 0.0, 'lastDay' => '', 'lastClose' => null];
        $days[$b][$d['state']] = ($days[$b][$d['state']] ?? 0) + 1;
        if ($d['counted'] !== null && $d['calc_close'] !== null) $days[$b]['diff'] = round($days[$b]['diff'] + (float)$d['counted'] - (float)$d['calc_close'], 2);
        if ($d['state'] !== 'open') { $days[$b]['lastDay'] = $d['day']; $days[$b]['lastClose'] = (float)$d['counted']; }
    }
    $tr = [];
    foreach ($e as $x) if ($x['kind'] === 'dispatch') {
        $k = ($x['data']['how'] ?? '') === 'lr' ? (string)$x['data']['transporter'] : 'Direct to party';
        $tr[$k] ??= ['n' => 0, 'auto' => 0.0, 'ours' => 0.0, 'party' => 0];
        $tr[$k]['n']++; $tr[$k]['auto'] += (float)($x['data']['auto_amt'] ?? 0);
        if (($x['data']['frt_by'] ?? '') === 'ours') $tr[$k]['ours'] += (float)$x['data']['frt_amt']; elseif (($x['data']['frt_by'] ?? '') === 'party') $tr[$k]['party']++;
    }
    ksort($tr);
    $nd = []; foreach ($e as $x) if ($x['kind'] === 'bill') $nd[$x['id']] = $x;
    if ($nd) foreach ($db->query("SELECT ref_id FROM dbk_entry WHERE kind = 'dispatch' AND removed_at IS NULL AND ref_id IN (" . implode(',', array_keys($nd)) . ')')->fetchAll(PDO::FETCH_COLUMN) as $r) unset($nd[(int)$r]);
    [$sw, $sp] = dbk_scope_sql($c);
    $m = $db->prepare("SELECT e.* FROM dbk_entry e WHERE $sw AND e.kind = 'receipt' AND e.with_emp = 1 AND e.settled_at IS NULL AND e.removed_at IS NULL" . ($branch ? ' AND e.branch_id = ?' : '') . ' ORDER BY e.day');
    $m->execute([...$sp, ...($branch ? [$branch] : [])]);
    $mr = $m->fetchAll(); $mf = dbk_files_for($db, array_column($mr, 'id'));
    $withEmp = array_values(array_filter(array_map(fn($r) => dbk_present($r, $mf[(int)$r['id']] ?? []), $mr), fn($x) => dbk_view_ok($c, $x['branch'])));
    return ['totals' => dbk_totals($e), 'days' => $days, 'transporters' => $tr, 'notDispatched' => array_values($nd), 'withEmp' => $withEmp, 'entries' => $e];
}

function dbk_csv(array $entries, array $bn): string {
    $line = fn(array $c) => implode(',', array_map('dbk_csv_cell', $c)) . "\r\n";
    $o = $line(['Date', 'Branch', 'Kind', 'Summary', 'Party', 'City', 'Amount', 'Cash effect', 'Mode', 'With employee', 'Settled', 'By', 'Entered at']);
    foreach ($entries as $e) $o .= $line([$e['day'], $bn[$e['branch']] ?? '', DBK_KINDS[$e['kind']] ?? $e['kind'], dbk_summary($e['kind'], $e['data'] + ['party' => $e['party'], 'amount' => $e['amount']]),
        $e['party'], $e['city'], number_format($e['amount'], 2, '.', ''), number_format($e['cash'], 2, '.', ''), $e['data']['mode'] ?? '', $e['withEmp'] ? 'Yes' : '', $e['settledAt'] ? 'Yes' : '', $e['by'], $e['at']]);
    return $o;
}
