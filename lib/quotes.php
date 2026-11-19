<?php
// Quotations — rules. Started from an enquiry or a customer; numbered per branch and year (QT/<branch>/<year>/0001);
// a draft is changed freely, Mark sent locks it and keeps the header as it was; Revise makes the next -R draft and the
// sent one stays as Revised; Won → a CRM order (once), Lost → a reason. Sales tax by the country rule pack (India: CGST +
// SGST in the seller's state, else IGST); other charges follow the highest goods rate. The enquiry moves with it.
declare(strict_types=1);
require_once __DIR__ . '/crm_orders.php';
require_once __DIR__ . '/enquiries.php';

const QT_STATUSES = ['Draft', 'Sent', 'Won', 'Lost', 'Revised'];
const QT_MAX_LINES = 150;
const QT_CHARGE_KINDS = ['Freight', 'Packing & forwarding', 'Loading / unloading', 'Installation', 'Insurance', 'Other charges'];
/** Header fields (company row 0 + a row per branch; a branch's blank field takes the company's). */
const QT_HEAD_FIELDS = [
    'legal_name' => ['Company name', 160], 'tagline' => ['Tagline', 160], 'tax_id' => ['Tax ID (printed)', 30], 'reg_lines' => ['Other registrations (printed, one a line)', 400],
    'address' => ['Address', 400], 'sales_phone' => ['Sales phone', 40], 'sales_email' => ['Sales email', 120], 'website' => ['Website', 120],
    'bank' => ['Bank and payment details (printed)', 800], 'pay_options' => ['Payment terms to pick from (one a line)', 1500], 'payment_terms' => ['Default payment terms', 200],
    'validity_days' => ['Valid for (days)', 3], 'round_mode' => ['Round the total (nearest / none)', 7], 'intro' => ['Introduction page', 4000], 'terms' => ['Terms & conditions (one a line)', 3000],
];
const QT_HEAD_NEEDED = ['legal_name' => 'Company name', 'address' => 'Address', 'sales_phone' => 'Sales phone'];
const QT_DEFAULTS = ['prefix' => 'QT', 'lost_reasons' => "Price too high\nDelivery time\nWent with competitor\nNo response from customer\nRequirement cancelled / on hold\nTechnical mismatch\nOther",
                     'need_tax_id' => true, 'follow_days' => 3];

/* ---------------- pure ---------------- */

/** Region code of a state / province name (or a code), by the pack's region list and aliases. '' when unknown. */
function qt_region(string $s, array $regions, array $aliases = []): string {
    $s = trim($s); if ($s === '') return '';
    if (isset($regions[$s])) return $s;
    $n = preg_replace('/[^a-z]/', '', strtolower(str_replace('&', 'and', $s)));
    if (isset($aliases[$n])) return (string)$aliases[$n];
    foreach ($regions as $c => $name) if (preg_replace('/[^a-z]/', '', strtolower((string)$name)) === $n) return (string)$c;
    return '';
}
/**
 * Totals. $lines: [qty, rate, discount_pct, tax_pct, charge?]; charges take the highest goods tax rate. $parts = the
 * pack's split (e.g. [['CGST', .5], ['SGST', .5]]). Each line's tax is rounded, then split; the total rounds to the
 * whole unit when $round. Returns [lines, totals: taxable, discount, charges, tax_total, parts [[label, amount]], round_off, total].
 */
function qt_calc(array $lines, array $parts, bool $round = true): array {
    $top = 0.0; foreach ($lines as $l) if (empty($l['charge'])) $top = max($top, (float)$l['tax_pct']);
    $t = ['taxable' => 0.0, 'discount' => 0.0, 'charges' => 0.0, 'tax_total' => 0.0]; $pa = array_fill(0, count($parts), 0.0);
    foreach ($lines as &$l) {
        if (!empty($l['charge'])) { $l['tax_pct'] = $top; $l['qty'] = 1; $l['discount_pct'] = 0; }
        $gross = round((float)$l['qty'] * (float)$l['rate'], 2);
        $l['taxable'] = round($gross * (1 - (float)$l['discount_pct'] / 100), 2);
        $l['tax'] = round($l['taxable'] * (float)$l['tax_pct'] / 100, 2);
        $t['taxable'] += $l['taxable']; $t['discount'] += $gross - $l['taxable']; $t['tax_total'] += $l['tax'];
        if (!empty($l['charge'])) $t['charges'] += $l['taxable'];
        $left = $l['tax'];
        foreach ($parts as $i => [, $share]) { $x = $i === count($parts) - 1 ? $left : round($l['tax'] * (float)$share, 2); $pa[$i] += $x; $left = round($left - $x, 2); }
    }
    unset($l);
    foreach ($t as $k => $v) $t[$k] = round($v, 2);
    $t['parts'] = array_map(fn($p, $a) => [$p[0], round($a, 2)], $parts, $pa);
    $sum = round($t['taxable'] + $t['tax_total'], 2);
    $t['total'] = $round ? round($sum) : $sum; $t['round_off'] = round($t['total'] - $sum, 2);
    return ['lines' => $lines, 'totals' => $t];
}
/** Amount in words, international ("One Million Two Hundred Thousand … Only"). Packs may give their own (India: lakh / crore). */
function qt_words_intl(float $amt, string $major = 'Units', string $minor = 'Cents'): string {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = fn(int $n) => $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    $three = fn(int $n) => trim(($n >= 100 ? $ones[intdiv($n, 100)] . ' Hundred ' : '') . $two($n % 100));
    $amt = round(abs($amt), 2); $r = (int)floor($amt); $p = (int)round(($amt - $r) * 100); if ($p === 100) { $r++; $p = 0; }
    $parts = [];
    foreach ([[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand']] as [$div, $w]) if ($r >= $div) { $parts[] = $three(intdiv($r, $div)) . ' ' . $w; $r %= $div; }
    if ($r > 0) $parts[] = $three($r);
    return ($parts ? implode(' ', $parts) : 'Zero') . " $major" . ($p > 0 ? ' And ' . $two($p) . " $minor" : '') . ' Only';
}
function qt_display_no(array $q): string { return $q['quote_no'] . ((int)$q['rev'] ? '-R' . (int)$q['rev'] : ''); }
/** The branch's header: company row filled by the branch's own values where they are set. */
function qt_head_merge(array $company, array $branch): array {
    $o = []; foreach (QT_HEAD_FIELDS as $k => $_) $o[$k] = trim((string)($branch[$k] ?? '')) !== '' ? (string)$branch[$k] : (string)($company[$k] ?? '');
    return $o;
}
function qt_head_missing(array $h): array { return array_values(array_filter(QT_HEAD_NEEDED, fn($l, $k) => trim((string)($h[$k] ?? '')) === '', ARRAY_FILTER_USE_BOTH)); }

/* ---------------- pack, settings, rights ---------------- */

function qt_tax_pack(): array {
    $p = crm_pack()['sales_tax'] ?? [];
    $fn = (string)($p['fn'] ?? ''); $w = (string)($p['words'] ?? '');
    return ['label' => (string)($p['label'] ?? 'Tax'), 'fn' => $fn !== '' && function_exists($fn) ? $fn : null, 'words' => $w !== '' && function_exists($w) ? $w : null,
            'regions' => (array)($p['regions'] ?? []), 'aliases' => (array)($p['aliases'] ?? [])];
}
function qt_parts(string $seller, string $place): array { $p = qt_tax_pack(); return $p['fn'] ? ($p['fn'])($seller, $place) : [[$p['label'], 1.0]]; }
function qt_words(float $amt): string {
    $p = qt_tax_pack(); $cur = strtoupper(org('currency') ?: 'INR');
    [$maj, $min] = ['INR' => ['Rupees', 'Paise'], 'USD' => ['Dollars', 'Cents'], 'EUR' => ['Euros', 'Cents'], 'GBP' => ['Pounds', 'Pence'], 'AED' => ['Dirhams', 'Fils']][$cur] ?? [$cur, 'Cents'];
    return $p['words'] ? ($p['words'])($amt, $maj, $min) : qt_words_intl($amt, $maj, $min);
}
function qt_settings(): array { $s = module_states()['quotes']['settings'] ?? []; return array_replace(QT_DEFAULTS, array_intersect_key($s, QT_DEFAULTS)); }
function qt_settings_check(array $in): array {
    $o = ['prefix' => substr(strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($in['prefix'] ?? 'QT'))), 0, 6) ?: 'QT',
          'lost_reasons' => implode("\n", array_slice(crm_lines((string)($in['lost_reasons'] ?? '')), 0, 30)) ?: 'Other', 'need_tax_id' => !empty($in['need_tax_id']),
          'follow_days' => max(0, min(60, (int)($in['follow_days'] ?? 3)))];
    return [$o, []];
}
/** May this login see / change this quotation? Same groups as CRM orders (salesperson owns it). */
function qt_can(array $u, array $q, bool $write = false): bool { return so_can($u, ['branch_id' => $q['branch_id'], 'salesperson_id' => $q['salesperson_id']], $write ? 'edit' : 'view'); }
function qt_need(array $u, array $q): void { if (!qt_can($u, $q, true)) throw new RuntimeException('Your role cannot do this on this quotation.'); }

/* ---------------- database ---------------- */

function qt_head_rows(PDO $db): array { $o = []; foreach ($db->query('SELECT branch_id, fields FROM qt_head') as $r) $o[(int)$r['branch_id']] = json_decode((string)$r['fields'], true) ?: []; return $o; }
function qt_head(PDO $db, ?int $branch): array {
    $rows = qt_head_rows($db); $c = $rows[0] ?? [];
    foreach (['legal_name' => org('legal_name') ?: org('name'), 'address' => org('address'), 'validity_days' => '15', 'round_mode' => 'nearest'] as $k => $d) if (trim((string)($c[$k] ?? '')) === '') $c[$k] = $d;
    return qt_head_merge($c, $branch ? ($rows[$branch] ?? []) : []);
}
function qt_head_save(PDO $db, array $u, int $branch, array $in): void {
    crm_need($u, 'admin');
    if ($branch && !in_array($branch, array_map(fn($b) => (int)$b['id'], branches()), true)) throw new RuntimeException('Pick a branch.');
    $old = qt_head_rows($db)[$branch] ?? []; $new = [];
    foreach (QT_HEAD_FIELDS as $k => [$l, $max]) $new[$k] = mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in[$k] ?? '')))), 0, $max);
    if ($new['validity_days'] !== '' && (!ctype_digit($new['validity_days']) || (int)$new['validity_days'] < 1)) throw new RuntimeException('Valid for: a number of days.');
    if ($new['round_mode'] !== '' && !in_array($new['round_mode'], ['nearest', 'none'], true)) throw new RuntimeException('Round the total: nearest or none.');
    if ($new['tax_id'] !== '' && ($r = crm_tax_rule()) && !$r($new['tax_id'])['ok']) throw new RuntimeException(crm_tax_label() . ': ' . $r($new['tax_id'])['why']);
    if ($new['tax_id'] !== '') $new['tax_id'] = strtoupper($new['tax_id']);
    $changed = array_keys(array_filter($new, fn($v, $k) => (string)($old[$k] ?? '') !== $v, ARRAY_FILTER_USE_BOTH));
    if (!$changed) return;
    $db->prepare('INSERT INTO qt_head (branch_id, fields, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE fields = VALUES(fields), updated_by = VALUES(updated_by)')->execute([$branch, json_encode($new, JSON_UNESCAPED_UNICODE), (int)$u['id']]);
    $db->prepare('INSERT INTO qt_head_log (branch_id, changed, by_user) VALUES (?,?,?)')->execute([$branch, mb_substr(implode(', ', $changed), 0, 400), (int)$u['id']]);
    audit((int)$u['id'], 'qt_head_save', 'branch ' . $branch . ' fields ' . count($changed));
}
function qt_row(PDO $db, int $id, bool $lock = false): array { $s = $db->prepare('SELECT * FROM qt_quote WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')); $s->execute([$id]); return $s->fetch() ?: throw new RuntimeException('Quotation not found.'); }
function qt_log(PDO $db, int $id, ?int $by, string $what, string $note = ''): void { $db->prepare('INSERT INTO qt_log (quote_id, what, note, by_user) VALUES (?,?,?,?)')->execute([$id, $what, mb_substr($note, 0, 500), $by]); }
/** The seller's region: the branch's state, else the region inside the header's tax ID, else ''. */
function qt_seller_region(array $head, ?int $branch): string {
    $p = qt_tax_pack();
    foreach (branches(false) as $b) if ((int)$b['id'] === (int)$branch && ($r = qt_region((string)$b['state'], $p['regions'], $p['aliases'])) !== '') return $r;
    $t = crm_tax_check((string)$head['tax_id'], crm_tax_rule());
    return $t['ok'] ? (string)($t['region'] ?? '') : '';
}
function qt_next_no(PDO $db, ?int $branch, string $ymd): string {
    $fy = so_fy($ymd, (int)(org('fy_start_month') ?: 4)); $bc = so_branch_code($branch); $pre = qt_settings()['prefix'];
    $key = "$pre|$bc|$fy";
    $db->prepare('INSERT INTO qt_seq (prefix, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$key]);
    $s = $db->prepare('SELECT n FROM qt_seq WHERE prefix = ?'); $s->execute([$key]);
    return so_order_no($pre, $bc, $fy, (int)$s->fetchColumn());
}

/** Lines from a form: products, then other charges. */
function qt_lines_in(PDO $db, array $in): array {
    $out = []; $p = $db->prepare('SELECT * FROM crm_product WHERE id = ? AND active = 1 AND merged_into IS NULL');
    foreach (array_values((array)($in['lines'] ?? [])) as $i => $l) {
        if ((int)($l['product_id'] ?? 0) === 0 && trim((string)($l['qty'] ?? '')) === '') continue;
        $n = count($out) + 1; $p->execute([(int)($l['product_id'] ?? 0)]); $pr = $p->fetch();
        if (!$pr) throw new RuntimeException("Line $n: pick a product.");
        $rate = trim((string)($l['rate'] ?? '')) === '' ? $pr['rate'] : $l['rate'];
        if ($rate === null) throw new RuntimeException("Line $n: enter the rate (the product has none).");
        $out[] = ['product_id' => (int)$pr['id'], 'kind' => '', 'sku' => $pr['sku'], 'name' => $pr['name'], 'description' => so_txt($l['description'] ?? '', 600), 'tax_code' => $pr['tax_code'], 'unit' => $pr['unit'],
                  'qty' => so_num($l['qty'] ?? '', "Line $n quantity", 0.001, 1e8), 'rate' => so_num($rate, "Line $n rate", 0, 1e11),
                  'discount_pct' => so_num(($l['discount_pct'] ?? '') === '' ? 0 : $l['discount_pct'], "Line $n discount", 0, 100),
                  'tax_pct' => so_num(($l['tax_pct'] ?? '') === '' ? $pr['tax_pct'] : $l['tax_pct'], "Line $n tax", 0, 100)];
    }
    if (!$out) throw new RuntimeException('Add at least one product line.');
    if (count($out) > QT_MAX_LINES) throw new RuntimeException('Too many lines on one quotation.');
    foreach (array_values((array)($in['charges'] ?? [])) as $c) {
        if (trim((string)($c['amount'] ?? '')) === '') continue;
        $k = (string)($c['kind'] ?? ''); if (!in_array($k, QT_CHARGE_KINDS, true)) throw new RuntimeException('Pick the kind of charge from the list.');
        $out[] = ['product_id' => null, 'kind' => $k, 'sku' => '', 'name' => $k, 'description' => so_txt($c['label'] ?? '', 120), 'tax_code' => '', 'unit' => '', 'qty' => 1, 'rate' => so_num($c['amount'], $k, 0, 1e10), 'discount_pct' => 0, 'tax_pct' => 0, 'charge' => true];
    }
    return $out;
}
/** Create (no id) or change a draft. $in: request_id, customer_id, branch_id, enquiry_id, contact_id, bill_address_id, ship_address_id, place_region,
 *  quote_date, valid_till, payment_terms, delivery_terms, note, show_intro, salesperson_id, version, lines[], charges[] */
function qt_save(PDO $db, array $u, array $in, int $id = 0): int {
    crm_need($u, 'edit');
    $old = $id ? qt_row($db, $id) : null;
    if ($old) { qt_need($u, $old); if ($old['status'] !== 'Draft' || $old['archived_at']) throw new RuntimeException('Only a draft can be changed — revise a sent quotation.'); if ((int)($in['version'] ?? 0) !== (int)$old['version']) throw new RuntimeException('Someone changed this quotation meanwhile — open it again.'); }
    else { $req = so_uuid((string)($in['request_id'] ?? '')); $s = $db->prepare('SELECT id FROM qt_quote WHERE request_id = ?'); $s->execute([$req]); if ($x = (int)$s->fetchColumn()) return $x; }
    $cu = crm_customer_get($db, (int)($old['customer_id'] ?? $in['customer_id'] ?? 0));
    if (!$cu || $cu['status'] !== 'active' || !crm_can($u, $cu)) throw new RuntimeException('Pick an active customer you work with.');
    $branch = $old ? ($old['branch_id'] !== null ? (int)$old['branch_id'] : null) : ((int)($in['branch_id'] ?? 0) ?: ($cu['branch_id'] !== null ? (int)$cu['branch_id'] : null));
    if (!$old && $branch && !emp_in_scope($u, ['branch_id' => $branch])) throw new RuntimeException('That branch is outside your scope.');
    $enq = $old ? ($old['enquiry_id'] !== null ? (int)$old['enquiry_id'] : null) : ((int)($in['enquiry_id'] ?? 0) ?: null);
    if (!$old && $enq) { $e = enq_get($db, $enq); if (!$e || $e['removed_at'] || !in_array($e['status'], ENQ_OPEN, true) || !enq_can($u, $e)) throw new RuntimeException('That enquiry is not open.'); }
    $contact = (int)($in['contact_id'] ?? 0) ?: null;
    if ($contact && !in_array($contact, array_map(fn($c) => (int)$c['id'], $cu['contacts']), true)) throw new RuntimeException('Pick a contact person of this customer.');
    $addr = function (string $k, string $def) use ($in, $cu): ?array { $v = (int)($in[$k] ?? 0); foreach ($cu['addresses'] as $a) if ($v ? (int)$a['id'] === $v : (int)$a[$def]) return $a; if ($v) throw new RuntimeException('Pick an address of this customer.'); return $cu['addresses'][0] ?? null; };
    $bill = $addr('bill_address_id', 'bill_default'); $ship = $addr('ship_address_id', 'ship_default') ?? $bill;
    $txt = fn(?array $a) => $a ? implode(', ', array_filter([$a['line1'], $a['line2'], $a['city'], trim($a['state'] . ' ' . $a['postcode']), $a['country']])) : '';
    $head = qt_head($db, $branch); $pack = qt_tax_pack();
    $seller = qt_seller_region($head, $branch);
    $hand = trim((string)($in['place_region'] ?? ''));
    if ($hand !== '' && !isset($pack['regions'][$hand])) throw new RuntimeException('Pick the place of supply from the list.');
    $tx = crm_tax_check((string)$cu['tax_id'], crm_tax_rule());
    $place = $hand !== '' ? $hand : (qt_region((string)($ship['state'] ?? ''), $pack['regions'], $pack['aliases']) ?: (($tx['ok'] ? (string)($tx['region'] ?? '') : '') ?: qt_region((string)($bill['state'] ?? ''), $pack['regions'], $pack['aliases'])));
    $sp = (int)($in['salesperson_id'] ?? 0) ?: (int)($old['salesperson_id'] ?? $u['id']);
    if (!so_user_ok($db, $sp)) throw new RuntimeException('Pick an active salesperson.');
    if ($sp !== (int)$u['id'] && !crm_rights($u)['all'] && $sp !== (int)($old['salesperson_id'] ?? 0)) throw new RuntimeException('You make quotations in your own name.');
    $date = so_date($in['quote_date'] ?? '', 'Quotation date') ?? date('Y-m-d');
    $till = so_date($in['valid_till'] ?? '', 'Valid till') ?? date('Y-m-d', strtotime("$date +" . max(1, (int)($head['validity_days'] ?: 15)) . ' days'));
    if ($till < $date) throw new RuntimeException('"Valid till" cannot be before the quotation date.');
    $calc = qt_calc(qt_lines_in($db, $in), qt_parts($seller, $place), ($head['round_mode'] ?: 'nearest') === 'nearest'); $t = $calc['totals'];
    $f = ['customer_id' => (int)$cu['id'], 'contact_id' => $contact, 'bill_to' => mb_substr($txt($bill), 0, 500), 'ship_to' => mb_substr($txt($ship), 0, 500), 'seller_region' => $seller, 'place_region' => $place, 'place_hand' => $hand !== '' ? 1 : 0,
          'quote_date' => $date, 'valid_till' => $till, 'payment_terms' => so_txt(($in['payment_terms'] ?? '') !== '' ? $in['payment_terms'] : $head['payment_terms'], 200), 'delivery_terms' => so_txt($in['delivery_terms'] ?? '', 200),
          'note' => so_txt($in['note'] ?? '', 1000), 'show_intro' => array_key_exists('show_intro', $in) ? (int)!empty($in['show_intro']) : 1, 'currency' => strtoupper(substr(org('currency') ?: 'INR', 0, 3)),
          'taxable' => $t['taxable'], 'discount' => $t['discount'], 'charges' => $t['charges'], 'tax_total' => $t['tax_total'], 'tax_parts' => json_encode($t['parts']), 'round_off' => $t['round_off'], 'total' => $t['total'], 'salesperson_id' => $sp];
    $id = so_tx($db, function () use ($db, $u, $old, $id, $f, $calc, $branch, $enq, $in) {
        if ($old) { $db->prepare('UPDATE qt_quote SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ', version = version + 1 WHERE id = ?')->execute([...array_values($f), $id]); $db->prepare('DELETE FROM qt_line WHERE quote_id = ?')->execute([$id]); qt_log($db, $id, (int)$u['id'], 'edit'); }   // a draft's own lines only
        else {
            $f += ['quote_no' => qt_next_no($db, $branch, $f['quote_date']), 'rev' => 0, 'request_id' => so_uuid((string)($in['request_id'] ?? '')), 'branch_id' => $branch, 'enquiry_id' => $enq, 'created_by' => (int)$u['id']];
            $db->prepare('INSERT INTO qt_quote (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
            $id = (int)$db->lastInsertId(); qt_log($db, $id, (int)$u['id'], 'create', $enq ? 'From enquiry #' . $enq : '');
        }
        qt_put_lines($db, $id, $calc['lines']);
        return $id;
    });
    audit((int)$u['id'], $old ? 'qt_edit' : 'qt_add', '#' . $id);
    return $id;
}
function qt_put_lines(PDO $db, int $id, array $lines): void {
    $ins = $db->prepare('INSERT INTO qt_line (quote_id, line_no, product_id, kind, sku, name, description, tax_code, unit, qty, rate, discount_pct, tax_pct, taxable, tax) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array_values($lines) as $i => $l) $ins->execute([$id, $i + 1, $l['product_id'], $l['kind'], $l['sku'], $l['name'], $l['description'], $l['tax_code'], $l['unit'], $l['qty'], $l['rate'], $l['discount_pct'], $l['tax_pct'], $l['taxable'], $l['tax']]);
}
/** Mark sent: locked, the header kept as it is today; the enquiry moves to Quotation sent. */
function qt_send(PDO $db, array $u, int $id): void {
    so_tx($db, function () use ($db, $u, $id) {
        $q = qt_row($db, $id, true); qt_need($u, $q);
        if ($q['status'] !== 'Draft' || $q['archived_at']) throw new RuntimeException('Only a draft is sent.');
        $h = qt_head($db, $q['branch_id'] !== null ? (int)$q['branch_id'] : null);
        if ($m = qt_head_missing($h)) throw new RuntimeException('Fill in the quotation header first (Settings): ' . implode(', ', $m) . '.');
        if (qt_settings()['need_tax_id'] && crm_tax_rule() && $h['tax_id'] === '') throw new RuntimeException('Set the ' . crm_tax_label() . ' in the quotation header first.');
        $db->prepare("UPDATE qt_quote SET status = 'Sent', sent_at = NOW(), sent_by = ?, head = ?, version = version + 1 WHERE id = ?")->execute([(int)$u['id'], json_encode($h, JSON_UNESCAPED_UNICODE), $id]);
        qt_log($db, $id, (int)$u['id'], 'sent');
        qt_enq_sync($db, qt_row($db, $id), 'sent', (int)$u['id']);
    });
    audit((int)$u['id'], 'qt_sent', '#' . $id);
}
/** A sent quotation → the next -R draft (same number); the sent one stays as Revised. */
function qt_revise(PDO $db, array $u, int $id, string $reason): int {
    if (($reason = so_txt($reason, 500)) === '') throw new RuntimeException('Say what changes in the revision.');
    $nid = so_tx($db, function () use ($db, $u, $id, $reason) {
        $q = qt_row($db, $id, true); qt_need($u, $q);
        if ($q['status'] !== 'Sent' || $q['archived_at']) throw new RuntimeException('Only a sent quotation is revised.');
        $m = $db->prepare('SELECT MAX(rev) FROM qt_quote WHERE quote_no = ?'); $m->execute([$q['quote_no']]);
        $c = $q; unset($c['id']);
        $c = array_merge($c, ['rev' => (int)$m->fetchColumn() + 1, 'parent_id' => $id, 'request_id' => so_uuid(''), 'status' => 'Draft', 'head' => null, 'sent_at' => null, 'sent_by' => null, 'decided_at' => null,
                              'order_id' => null, 'lost_reason' => '', 'status_note' => '', 'created_by' => (int)$u['id'], 'created_at' => date('Y-m-d H:i:s'), 'quote_date' => date('Y-m-d'), 'version' => 1]);
        $db->prepare('INSERT INTO qt_quote (' . implode(',', array_keys($c)) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($c));
        $nid = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO qt_line (quote_id, line_no, product_id, kind, sku, name, description, tax_code, unit, qty, rate, discount_pct, tax_pct, taxable, tax) SELECT ?, line_no, product_id, kind, sku, name, description, tax_code, unit, qty, rate, discount_pct, tax_pct, taxable, tax FROM qt_line WHERE quote_id = ?')->execute([$nid, $id]);
        $db->prepare("UPDATE qt_quote SET status = 'Revised', status_note = ?, decided_at = NOW(), version = version + 1 WHERE id = ?")->execute([$reason, $id]);
        qt_log($db, $id, (int)$u['id'], 'revised', $reason); qt_log($db, $nid, (int)$u['id'], 'create', 'Revision of ' . qt_display_no($q) . ': ' . $reason);
        return $nid;
    });
    audit((int)$u['id'], 'qt_revise', '#' . $id . ' > #' . $nid);
    return $nid;
}
function qt_decide(PDO $db, array $u, int $id, string $what, string $reason = '', string $note = ''): void {
    so_tx($db, function () use ($db, $u, $id, $what, $reason, $note) {
        $q = qt_row($db, $id, true); qt_need($u, $q);
        if ($q['status'] !== 'Sent' || $q['archived_at']) throw new RuntimeException('Only a quotation that went out is won or lost.');
        if ($what === 'won') $db->prepare("UPDATE qt_quote SET status = 'Won', decided_at = NOW(), status_note = ?, version = version + 1 WHERE id = ?")->execute([so_txt($note, 500), $id]);
        elseif ($what === 'lost') {
            if (!in_array($reason, crm_lines(qt_settings()['lost_reasons']), true)) throw new RuntimeException('Pick why it was lost.');
            $db->prepare("UPDATE qt_quote SET status = 'Lost', lost_reason = ?, status_note = ?, decided_at = NOW(), version = version + 1 WHERE id = ?")->execute([$reason, so_txt($note, 500), $id]);
        } else throw new RuntimeException('Won or lost?');
        qt_log($db, $id, (int)$u['id'], $what, trim($reason . ' ' . $note));
        qt_enq_sync($db, qt_row($db, $id), $what, (int)$u['id']);
    });
    audit((int)$u['id'], 'qt_' . $what, '#' . $id);
}
/** Archive / bring back (CRM admin), with a reason; one that became an order stays. */
function qt_archive(PDO $db, array $u, int $id, bool $archive, string $reason): void {
    crm_need($u, 'admin');
    if (($reason = so_txt($reason, 300)) === '') throw new RuntimeException('Say why.');
    $q = qt_row($db, $id);
    if ($archive && $q['order_id']) throw new RuntimeException('This quotation became an order, so it stays.');
    $db->prepare('UPDATE qt_quote SET archived_at = ' . ($archive ? 'NOW()' : 'NULL') . ', archived_by = ?, archive_reason = ?, version = version + 1 WHERE id = ?')->execute([(int)$u['id'], $reason, $id]);
    qt_log($db, $id, (int)$u['id'], $archive ? 'archived' : 'restored', $reason);
    audit((int)$u['id'], $archive ? 'qt_archive' : 'qt_restore', '#' . $id);
}
/** A won quotation → a draft CRM order with the same lines (once). */
function qt_to_order(PDO $db, array $u, int $id): int {
    $q = qt_row($db, $id); qt_need($u, $q);
    if ($q['status'] !== 'Won') throw new RuntimeException('Only a won quotation becomes an order.');
    if ($q['order_id']) return (int)$q['order_id'];
    $ls = $db->prepare('SELECT * FROM qt_line WHERE quote_id = ? ORDER BY line_no'); $ls->execute([$id]); $all = $ls->fetchAll();
    $goods = array_values(array_filter($all, fn($l) => $l['product_id'] !== null)); $ch = array_filter($all, fn($l) => $l['product_id'] === null);
    $sources = crm_lines(crm_settings()['order_sources']);
    $src = in_array('Quotation', $sources, true) ? 'Quotation' : ($sources[0] ?? 'Other');
    $oid = so_create($db, $u, ['request_id' => so_uuid(''), 'branch_id' => $q['branch_id'] ?? 0, 'customer_id' => (int)$q['customer_id'], 'contact_id' => $q['contact_id'] ?? 0,
        'delivery_address' => $q['ship_to'], 'source' => $src, 'source_ref' => qt_display_no($q), 'salesperson_id' => (int)$q['salesperson_id'], 'notify_email' => crm_settings()['notify_default'] ? 1 : 0,
        'internal_remarks' => 'From quotation ' . qt_display_no($q) . ($ch ? '. Other charges on the quotation: ' . implode('; ', array_map(fn($c) => $c['name'] . ' ' . number_format((float)$c['taxable'], 2) . ' + tax ' . (float)$c['tax_pct'] . '%', $ch)) : ''),
        'lines' => array_map(fn($l) => ['product_id' => (int)$l['product_id'], 'qty' => $l['qty'], 'rate' => $l['rate'], 'discount_pct' => $l['discount_pct'], 'tax_pct' => $l['tax_pct'], 'description' => mb_substr(trim($l['name'] . ($l['description'] !== '' ? ' — ' . $l['description'] : '')), 0, 300)], $goods)]);
    $db->prepare('UPDATE qt_quote SET order_id = ?, version = version + 1 WHERE id = ? AND order_id IS NULL')->execute([$oid, $id]);
    qt_log($db, $id, (int)$u['id'], 'order', 'Order ' . so_row($db, $oid)['order_no']);
    if ($q['enquiry_id']) $db->prepare('UPDATE enq_enquiry SET order_id = ? WHERE id = ?')->execute([$oid, (int)$q['enquiry_id']]);
    return $oid;
}
/** The enquiry follows the quotation: sent → Quotation sent with its number and value; won → Won; lost → Lost with the reason. */
function qt_enq_sync(PDO $db, array $q, string $what, int $by): void {
    if (!$q['enquiry_id'] || !module_on('enquiries')) return;
    $e = enq_get($db, (int)$q['enquiry_id']); if (!$e || $e['removed_at']) return;
    $no = qt_display_no($q); $es = enq_settings();
    if ($what === 'sent') {
        $st = in_array($e['status'], ['new', 'contacted', 'quoted'], true) ? 'quoted' : $e['status'];
        $pct = (float)$q['taxable'] > 0 ? round(100 * (float)$q['tax_total'] / (float)$q['taxable'], 2) : (float)$e['tax_pct'];
        $db->prepare('UPDATE enq_enquiry SET status = ?, quote_no = ?, quote_date = ?, basic = ?, tax_pct = ?, follow_up = ? WHERE id = ?')
           ->execute([$st, $no, $q['quote_date'], $q['taxable'], min(100, $pct), date('Y-m-d', strtotime('+' . (int)qt_settings()['follow_days'] . ' day')), (int)$e['id']]);
        enq_note($db, (int)$e['id'], "Quotation $no sent · " . number_format((float)$q['taxable'], 2) . ' before tax', $by, true);
    } elseif ($what === 'won') {
        $db->prepare("UPDATE enq_enquiry SET status = 'won', follow_up = NULL, lost_reason = '' WHERE id = ?")->execute([(int)$e['id']]);
        enq_note($db, (int)$e['id'], "Quotation $no won", $by, true);
    } elseif ($what === 'lost') {
        $list = crm_lines($es['lost_reasons']); $r = in_array($q['lost_reason'], $list, true) ? $q['lost_reason'] : (in_array('Other', $list, true) ? 'Other' : ($list[0] ?? ''));
        $db->prepare("UPDATE enq_enquiry SET status = 'lost', follow_up = NULL, lost_reason = ? WHERE id = ?")->execute([$r, (int)$e['id']]);
        enq_note($db, (int)$e['id'], "Quotation $no lost · " . $q['lost_reason'], $by, true);
    }
}
function qt_get(PDO $db, int $id): array {
    $q = qt_row($db, $id);
    $s = $db->prepare('SELECT * FROM qt_line WHERE quote_id = ? ORDER BY line_no'); $s->execute([$id]); $q['lines'] = $s->fetchAll();
    $s = $db->prepare('SELECT l.*, u.name AS who FROM qt_log l LEFT JOIN users u ON u.id = l.by_user WHERE l.quote_id = ? ORDER BY l.id DESC'); $s->execute([$id]); $q['log'] = $s->fetchAll();
    $s = $db->prepare('SELECT id, rev, status FROM qt_quote WHERE quote_no = ? ORDER BY rev'); $s->execute([$q['quote_no']]); $q['revs'] = $s->fetchAll();
    $q['customer'] = crm_customer_get($db, (int)$q['customer_id']);
    $q['parts'] = json_decode((string)$q['tax_parts'], true) ?: [];
    return $q;
}
function qt_list(PDO $db, array $u, array $f = []): array {
    $w = [empty($f['archived']) ? 'q.archived_at IS NULL' : 'q.archived_at IS NOT NULL']; $p = [];
    if (($f['status'] ?? '') !== '' && in_array($f['status'], QT_STATUSES, true)) { $w[] = 'q.status = ?'; $p[] = $f['status']; }
    elseif (($f['status'] ?? '') === '') $w[] = "q.status <> 'Revised'";
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $l = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w[] = '(q.quote_no LIKE ? OR c.name LIKE ?)'; array_push($p, $l, $l); }
    $s = $db->prepare('SELECT q.*, c.name AS customer, u.name AS salesperson FROM qt_quote q JOIN crm_customer c ON c.id = q.customer_id LEFT JOIN users u ON u.id = q.salesperson_id WHERE ' . implode(' AND ', $w) . ' ORDER BY q.id DESC LIMIT ' . (int)($f['limit'] ?? 300));
    $s->execute($p);
    return array_values(array_filter($s->fetchAll(), fn($x) => qt_can($u, $x)));
}
