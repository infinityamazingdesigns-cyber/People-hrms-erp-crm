<?php
// Enquiry register — rules. A lead becomes an enquiry: who asked, what for, where it came from, stage, quotation,
// follow-up date, owner. Stages New → Contacted → Quotation sent → Negotiation → Won / Lost. A quotation number and value
// are needed from "Quotation sent" on; Lost needs a reason; open ones need a follow-up date. A second enquiry from a
// number or mail that already has an open one becomes a note on it. Won → a CRM order. Pure functions first.
declare(strict_types=1);
require_once __DIR__ . '/crm.php';

const ENQ_STAGES = ['new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quotation sent', 'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost'];
const ENQ_OPEN = ['new', 'contacted', 'quoted', 'negotiation'];
const ENQ_NEEDS_QUOTE = ['quoted', 'negotiation', 'won'];
const ENQ_QUICK_NOTES = ['Called – no answer', 'Called – will revert', 'Quotation sent on WhatsApp', 'Quotation sent by email', 'Revised quote shared', 'Site visit done', 'Awaiting PO', 'Payment follow-up'];
const ENQ_DEFAULTS = [
    'all_roles' => ['SUPERADMIN', 'CEO'], 'branch_roles' => ['MANAGER'], 'own_roles' => ['SALES', 'MARKETING'],
    'categories' => "Products: Standard product, Custom product, Spares\nServices: Installation, Service / maintenance contract, Repair",
    'sources' => "Campaigns: WhatsApp campaign, Email campaign, SMS campaign, Search ads, Social media ads\n"
               . "Inbound: Website form, Inbound phone call, WhatsApp message, Email enquiry, Search / maps listing, Social media (organic), Walk-in\n"
               . "Outbound: Cold call, Cold email, Field sales visit\nMarketplaces & tenders: Online marketplace, Tender portal\n"
               . "Events: Exhibition / trade fair, Seminar\nRelationships: Repeat customer, Customer referral, Dealer / distributor, Consultant / contractor, Employee referral\nOther: Other",
    'lost_reasons' => "Price too high\nDelivery time\nWent with competitor\nNo response from customer\nRequirement cancelled / on hold\nTechnical mismatch\nItem not supplied\nOther",
    'tax_pct' => 18, 'follow_new' => 1, 'follow_quoted' => 3, 'prefix' => 'ENQ', 'phone_digits' => 10,
];

/* ---------------- pure ---------------- */

/** "Group: a, b" lines → [group => [items]]. */
function enq_groups(string $text): array {
    $o = [];
    foreach (crm_lines($text) as $l) {
        [$g, $items] = str_contains($l, ':') ? array_map('trim', explode(':', $l, 2)) : ['Other', $l];
        $its = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 160), explode(',', $items)), 'strlen')));
        if ($g !== '' && $its) $o[mb_substr($g, 0, 80)] = array_values(array_unique(array_merge($o[$g] ?? [], $its)));
    }
    return $o;
}
function enq_flat(array $groups): array { return array_values(array_unique(array_merge(...array_values($groups ?: [[]])))); }
/** Where an open enquiry stands against today: overdue / today / later / none; closed ones: closed. */
function enq_attn(array $e, string $today): string {
    if (!in_array($e['status'], ENQ_OPEN, true)) return 'closed';
    $f = (string)($e['follow_up'] ?? '');
    return $f === '' ? 'none' : ($f < $today ? 'overdue' : ($f === $today ? 'today' : 'later'));
}
/** Days to the next follow-up when an enquiry moves to $status (Quotation sent waits longer). */
function enq_follow_days(string $status, array $set): int { return $status === 'quoted' ? (int)$set['follow_quoted'] : (int)$set['follow_new']; }
/** A stage from words in a file ("PO received" → won, "regret" → lost …). */
function enq_stage_from(string $s): string {
    $s = mb_strtolower($s);
    foreach (['lost' => '/lost|drop|reject|cancel|regret/', 'won' => '/won|order|\bpo\b|confirm/', 'negotiation' => '/nego/', 'quoted' => '/quot|offer|sent/', 'contacted' => '/contact|call|follow|discuss|visit/'] as $k => $re)
        if (preg_match($re, $s)) return $k;
    return 'new';
}
function enq_ref(string $prefix, string $fy, int $n): string { return $prefix . '/' . $fy . '/' . sprintf('%04d', $n); }
function enq_money(string $v): ?float { $v = trim(str_replace([',', ' '], '', $v)); return $v === '' ? null : (is_numeric($v) && (float)$v >= 0 ? round((float)$v, 2) : -1.0); }

/**
 * Check one enquiry from a form. $known: [categories => [cat => [products]], sources => [...], lost => [...]].
 * Returns [clean, errors] (field => message).
 */
function enq_check(array $in, array $known, string $today, int $phoneDigits = 10): array {
    $t = fn($k, $n) => mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in[$k] ?? '')))), 0, $n);
    $d = ['customer_name' => $t('customer_name', 200), 'company' => $t('company', 200), 'phone' => crm_phone_norm($t('phone', 30)), 'email' => crm_email_norm($t('email', 160)),
          'category' => $t('category', 80), 'product' => $t('product', 160), 'source' => $t('source', 80), 'status' => (string)($in['status'] ?? 'new'),
          'lost_reason' => '', 'quote_no' => $t('quote_no', 60), 'quote_date' => $t('quote_date', 10) ?: null, 'enquiry_date' => $t('enquiry_date', 10) ?: $today,
          'follow_up' => $t('follow_up', 10) ?: null];
    $e = [];
    if ($d['customer_name'] === '') $e['customer_name'] = 'Enter the customer name.';
    if (trim((string)($in['phone'] ?? '')) !== '' && $d['phone'] === '') $e['phone'] = 'Check the phone number.';
    if (trim((string)($in['email'] ?? '')) !== '' && $d['email'] === '') $e['email'] = 'Check the mail ID.';
    if ($d['phone'] === '' && $d['email'] === '' && !isset($e['phone']) && !isset($e['email'])) $e['phone'] = 'Add a phone number or a mail ID so the team can follow up.';
    if (!isset(ENQ_STAGES[$d['status']])) { $e['status'] = 'Pick a stage.'; $d['status'] = 'new'; }
    if (!in_array($d['source'], enq_flat($known['sources']), true)) $e['source'] = 'Choose where the enquiry came from.';
    if ($d['category'] !== '' && !isset($known['categories'][$d['category']])) $e['category'] = 'Pick a category from the list.';
    if ($d['product'] !== '' && $d['category'] !== '' && !in_array($d['product'], $known['categories'][$d['category']] ?? [], true)) $e['product'] = 'Pick a product of that category.';
    $b = enq_money((string)($in['basic'] ?? '')); if ($b !== null && $b < 0) { $e['basic'] = 'The value must be a number.'; $b = null; }
    $d['basic'] = $b;
    $tx = trim((string)($in['tax_pct'] ?? '')); $d['tax_pct'] = $tx === '' ? null : (is_numeric($tx) && (float)$tx >= 0 && (float)$tx <= 100 ? round((float)$tx, 2) : -1.0);
    if ($d['tax_pct'] !== null && $d['tax_pct'] < 0) $e['tax_pct'] = 'Tax % is 0–100.';
    if (in_array($d['status'], ENQ_NEEDS_QUOTE, true)) { if ($d['quote_no'] === '') $e['quote_no'] = 'Enter the quotation number for this stage.'; if (!$d['basic']) $e['basic'] = 'Enter the quoted value before tax.'; }
    if ($d['status'] === 'lost') { $d['lost_reason'] = $t('lost_reason', 80); if (!in_array($d['lost_reason'], $known['lost'], true)) $e['lost_reason'] = 'Choose why it was lost.'; }
    foreach (['enquiry_date' => 'Enquiry date', 'quote_date' => 'Quotation date', 'follow_up' => 'Follow-up'] as $k => $l)
        if ($d[$k] !== null && (!($x = DateTime::createFromFormat('!Y-m-d', $d[$k])) || $x->format('Y-m-d') !== $d[$k])) { $e[$k] = "$l: pick a date."; $d[$k] = null; }
    if ($d['enquiry_date'] !== null && $d['enquiry_date'] > $today) $e['enquiry_date'] = 'The enquiry date cannot be in the future.';
    if (in_array($d['status'], ENQ_OPEN, true) && !$d['follow_up']) $e['follow_up'] = 'Open enquiries need a follow-up date.';
    if (!in_array($d['status'], ENQ_OPEN, true)) $d['follow_up'] = null;
    $d['phone_key'] = crm_phone_key($d['phone'], $phoneDigits);
    return [$d, $e];
}
/** Counts by stage, source, category and person: enquiries, won, lost, open, quoted value, won value, win rate (won ÷ closed). */
function enq_report(array $rows, string $by): array {
    $o = [];
    foreach ($rows as $r) {
        $k = (string)($r[$by] ?? '') ?: '—';
        $o[$k] ??= ['n' => 0, 'open' => 0, 'won' => 0, 'lost' => 0, 'quoted_value' => 0.0, 'won_value' => 0.0];
        $x = &$o[$k]; $x['n']++;
        if (in_array($r['status'], ENQ_OPEN, true)) $x['open']++;
        if ($r['status'] === 'won') { $x['won']++; $x['won_value'] += (float)$r['basic']; }
        if ($r['status'] === 'lost') $x['lost']++;
        if (in_array($r['status'], ENQ_NEEDS_QUOTE, true) || ($r['status'] === 'lost' && $r['quote_no'] !== '')) $x['quoted_value'] += (float)$r['basic'];
        unset($x);
    }
    foreach ($o as &$x) $x['win_rate'] = $x['won'] + $x['lost'] ? round(100 * $x['won'] / ($x['won'] + $x['lost']), 1) : null;
    unset($x);
    uasort($o, fn($a, $b) => [$b['n'], $b['won']] <=> [$a['n'], $a['won']]);
    return $o;
}
/** One pasted / file row in the column order of the template → a raw enquiry (checked later by enq_check). */
function enq_row_from(array $c, array $map, array $known, string $today, array $set): array {
    $g = fn(string $f) => isset($map[$f]) ? trim((string)preg_replace('/\s+/u', ' ', (string)($c[$map[$f]] ?? ''))) : '';
    $raw = $g('status'); $st = enq_stage_from($raw);
    if (in_array($st, ENQ_NEEDS_QUOTE, true) && ($g('quote_no') === '' || !(float)str_replace(',', '', $g('basic')))) $st = 'contacted';   // a quote stage without its details comes in as Contacted (the file's word stays in the note)
    $cat = $g('category'); $prod = $g('product'); $cats = $known['categories'];
    $catHit = ''; foreach ($cats as $k => $items) { if (strcasecmp($k, $cat) === 0) $catHit = $k; foreach ($items as $p) if ($prod !== '' && strcasecmp($p, $prod) === 0) { $catHit = $k; $prod = $p; } }
    $src = $g('source'); $srcHit = 'Other'; foreach (enq_flat($known['sources']) as $s) if (strcasecmp($s, $src) === 0) $srcHit = $s;
    $d = ['customer_name' => $g('customer_name'), 'company' => $g('company'), 'phone' => $g('phone'), 'email' => $g('email'), 'category' => $catHit, 'product' => $catHit !== '' && in_array($prod, $cats[$catHit], true) ? $prod : '',
          'source' => in_array($srcHit, enq_flat($known['sources']), true) ? $srcHit : (enq_flat($known['sources'])[0] ?? ''), 'status' => $st, 'quote_no' => $g('quote_no'), 'basic' => $g('basic'), 'enquiry_date' => '',
          'follow_up' => in_array($st, ENQ_OPEN, true) ? date('Y-m-d', strtotime($today . ' +' . enq_follow_days($st, $set) . ' day')) : '', 'lost_reason' => $st === 'lost' ? (in_array('Other', $known['lost'], true) ? 'Other' : ($known['lost'][0] ?? '')) : '',
          '_note' => trim(implode(' · ', array_filter([$raw !== '' ? 'Status in the file: ' . $raw : '', $src !== '' && strcasecmp($src, $srcHit) !== 0 ? 'Source in the file: ' . $src : '', $cat !== '' && $catHit === '' ? 'Type in the file: ' . $cat : ''])))];
    if ($d['enquiry_date'] === '' && ($dt = $g('enquiry_date')) !== '' && preg_match('#^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$#', $dt, $m)) $d['enquiry_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $g('enquiry_date'))) $d['enquiry_date'] = $g('enquiry_date');
    return $d;
}
const ENQ_IMPORT_COLS = [
    'customer_name' => ['customername', 'customer', 'name', 'contactperson', 'party', 'client'], 'company' => ['company', 'companydetails', 'companyname', 'firm'],
    'phone' => ['contact', 'phone', 'mobile', 'mobileno', 'contactno', 'phoneno'], 'email' => ['mailid', 'email', 'emailid', 'mail'],
    'category' => ['typeofenquiry', 'category', 'type', 'productcategory'], 'product' => ['product', 'model', 'item'], 'status' => ['status', 'stage'],
    'basic' => ['basicvaluewithouttax', 'basicvalue', 'value', 'amount', 'basic'], 'source' => ['leadsource', 'source'], 'quote_no' => ['quotationno', 'quoteno', 'quotation'],
    'enquiry_date' => ['enquirydate', 'date']];

function enq_settings(): array {
    $s = module_states()['enquiries']['settings'] ?? [];
    return array_replace(ENQ_DEFAULTS, array_intersect_key($s, ENQ_DEFAULTS));
}
function enq_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['all_roles', 'branch_roles', 'own_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['all_roles']) $e[] = 'Pick at least one role that sees every enquiry.';
    foreach (['categories' => 60, 'sources' => 40, 'lost_reasons' => 30] as $k => $n) $o[$k] = implode("\n", array_slice(crm_lines((string)($in[$k] ?? '')), 0, $n));
    if (!enq_groups($o['sources'])) { $e[] = 'Give at least one lead source.'; $o['sources'] = ENQ_DEFAULTS['sources']; }
    if (!crm_lines($o['lost_reasons'])) $o['lost_reasons'] = 'Other';
    $o['tax_pct'] = max(0, min(100, round((float)($in['tax_pct'] ?? 18), 2)));
    $o['follow_new'] = max(0, min(30, (int)($in['follow_new'] ?? 1))); $o['follow_quoted'] = max(0, min(60, (int)($in['follow_quoted'] ?? 3)));
    $o['prefix'] = substr(strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($in['prefix'] ?? 'ENQ'))), 0, 6) ?: 'ENQ';
    $o['phone_digits'] = max(6, min(12, (int)($in['phone_digits'] ?? 10)));
    return [$o, $e];
}
function enq_known(array $set): array { return ['categories' => enq_groups($set['categories']), 'sources' => enq_groups($set['sources']), 'lost' => crm_lines($set['lost_reasons'])]; }

/* ---------------- rights ---------------- */

/** all = every enquiry (branch scope); branch = their branches' enquiries + unassigned ones there; own = theirs. */
function enq_rights(array $u): array {
    $r = user_roles((int)$u['id'], !empty($u['is_owner'])); $s = enq_settings();
    $all = (bool)array_intersect($r, $s['all_roles']); $br = $all || (bool)array_intersect($r, $s['branch_roles']);
    return ['all' => $all, 'branch' => $br, 'own' => $br || (bool)array_intersect($r, $s['own_roles']), 'assign' => $br];
}
function enq_can(array $u, array $e): bool {
    $R = enq_rights($u); $mine = (int)($e['assigned_user'] ?? 0) === (int)$u['id'] || ((int)($e['assigned_user'] ?? 0) === 0 && (int)$e['created_by'] === (int)$u['id']);
    $inBranch = $e['branch_id'] === null || emp_in_scope($u, ['branch_id' => $e['branch_id']]);
    if ($R['all'] || $R['branch']) return $inBranch;
    return $R['own'] && $mine;
}

/* ---------------- database ---------------- */

function enq_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT e.*, u.name AS assignee FROM enq_enquiry e LEFT JOIN users u ON u.id = e.assigned_user WHERE e.id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}
function enq_note(PDO $db, int $id, string $body, ?int $by, bool $sys = false): void {
    $body = mb_substr(trim(strip_tags(str_replace("\r", '', $body))), 0, 1000);
    if ($body === '') return;
    $db->prepare('INSERT INTO enq_note (enquiry_id, body, sys, by_user) VALUES (?,?,?,?)')->execute([$id, $body, $sys ? 1 : 0, $by]);
}
/** An open enquiry with the same phone (local part) or mail, or null. */
function enq_open_match(PDO $db, string $phoneKey, string $email, int $skip = 0): ?array {
    if ($phoneKey === '' && $email === '') return null;
    $s = $db->prepare("SELECT id, ref, customer_name FROM enq_enquiry WHERE removed_at IS NULL AND id <> ? AND status IN ('new','contacted','quoted','negotiation')
                       AND ((? <> '' AND phone_key = ?) OR (? <> '' AND email = ?)) ORDER BY id LIMIT 1");
    $s->execute([$skip, $phoneKey, $phoneKey, $email, $email]);
    return $s->fetch() ?: null;
}
/** The CRM customer with that mobile or mail (whole organisation), or null. */
function enq_customer_match(PDO $db, string $phone, string $email, int $digits): ?int {
    if ($phone !== '') { $k = crm_phone_key($phone, $digits); $s = $db->prepare('SELECT p.customer_id FROM crm_phone p JOIN crm_customer c ON c.id = p.customer_id WHERE p.active = 1 AND c.merged_into IS NULL AND RIGHT(p.number, ?) = ? LIMIT 1'); $s->execute([strlen($k), $k]); if ($id = (int)$s->fetchColumn()) return $id; }
    if ($email !== '') { $s = $db->prepare('SELECT e.customer_id FROM crm_email e JOIN crm_customer c ON c.id = e.customer_id WHERE e.active = 1 AND c.merged_into IS NULL AND e.email = ? LIMIT 1'); $s->execute([$email]); if ($id = (int)$s->fetchColumn()) return $id; }
    return null;
}
/** The people an enquiry may be given to (logins in the module's roles). */
function enq_team(PDO $db): array {
    $s = enq_settings(); $roles = array_values(array_unique(array_merge($s['all_roles'], $s['branch_roles'], $s['own_roles'])));
    if (!$roles) return [];
    $q = $db->prepare('SELECT DISTINCT u.id, u.name FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ') ORDER BY u.name');
    $q->execute($roles);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}
/**
 * Add or change an enquiry. A new one from a number / mail with an open enquiry is refused with the other's reference
 * unless $force (a different requirement). Returns the id.
 */
function enq_save(PDO $db, array $u, int $id, array $in, bool $force = false): int {
    $R = enq_rights($u); if (!$R['own']) throw new RuntimeException('Your role cannot do this.');
    $set = enq_settings(); [$d, $e] = enq_check($in, enq_known($set), date('Y-m-d'), (int)$set['phone_digits']);
    if ($e) throw new RuntimeException(implode(' ', array_unique($e)));
    $old = $id ? enq_get($db, $id) : null;
    if ($id && (!$old || $old['removed_at'] || !enq_can($u, $old))) throw new RuntimeException('Enquiry not found.');
    $d['tax_pct'] ??= $old ? (float)$old['tax_pct'] : (float)$set['tax_pct'];
    $to = (int)($in['assigned_user'] ?? 0);
    if ($R['assign']) { if ($to && !isset(enq_team($db)[$to])) throw new RuntimeException('Give it to someone on the enquiry team.'); $d['assigned_user'] = $to ?: null; }
    else $d['assigned_user'] = $old ? $old['assigned_user'] : (int)$u['id'];
    $br = (int)($in['branch_id'] ?? 0) ?: null;
    if ($br && !emp_in_scope($u, ['branch_id' => $br])) throw new RuntimeException('That branch is outside your scope.');
    $d['branch_id'] = $br ?? ($old['branch_id'] ?? null);
    if (!$old || $old['phone'] !== $d['phone'] || $old['email'] !== $d['email']) $d['customer_id'] = enq_customer_match($db, $d['phone'], $d['email'], (int)$set['phone_digits']) ?? ($old['customer_id'] ?? null);
    if (!$id && !$force && ($m = enq_open_match($db, $d['phone_key'], $d['email']))) throw new RuntimeException('An open enquiry from this number or mail is there already: ' . $m['ref'] . ' (' . $m['customer_name'] . '). Add a note there, or tick "A different requirement" to add it anyway.');
    $db->beginTransaction();
    try {
        if ($old) {
            $db->prepare('UPDATE enq_enquiry SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?')->execute([...array_values($d), $id]);
            if ($old['status'] !== $d['status']) enq_note($db, $id, 'Stage: ' . ENQ_STAGES[$old['status']] . ' → ' . ENQ_STAGES[$d['status']] . ($d['status'] === 'lost' ? ' (' . $d['lost_reason'] . ')' : ''), (int)$u['id'], true);
            if ($d['quote_no'] !== '' && ($old['quote_no'] !== $d['quote_no'] || (float)$old['basic'] !== (float)$d['basic'])) enq_note($db, $id, 'Quotation ' . $d['quote_no'] . ' · ' . number_format((float)$d['basic'], 2) . ' before tax', (int)$u['id'], true);
            if ((int)$old['assigned_user'] !== (int)$d['assigned_user']) enq_note($db, $id, 'Given to ' . ($d['assigned_user'] ? enq_team($db)[$d['assigned_user']] : 'nobody yet'), (int)$u['id'], true);
        } else {
            $fy = so_fy_enq($d['enquiry_date']);
            $db->prepare('INSERT INTO enq_seq (fy, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$fy]);
            $n = $db->prepare('SELECT n FROM enq_seq WHERE fy = ?'); $n->execute([$fy]);
            $d += ['ref' => enq_ref($set['prefix'], $fy, (int)$n->fetchColumn()), 'created_by' => (int)$u['id']];
            $db->prepare('INSERT INTO enq_enquiry (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            $id = (int)$db->lastInsertId();
        }
        if (trim((string)($in['note'] ?? '')) !== '') enq_note($db, $id, (string)$in['note'], (int)$u['id']);
        $db->commit();
    } catch (Throwable $x) { $db->rollBack(); throw $x; }
    audit((int)$u['id'], $old ? 'enq_edit' : 'enq_add', '#' . $id);
    return $id;
}
/** Year label by the organisation's first month (shared with orders). */
function so_fy_enq(string $ymd): string {
    $m0 = (int)(org('fy_start_month') ?: 4); $y = (int)substr($ymd, 0, 4); $m = (int)substr($ymd, 5, 2);
    if ($m0 <= 1) return (string)$y;
    $s = $m >= $m0 ? $y : $y - 1; return sprintf('%02d-%02d', $s % 100, ($s + 1) % 100);
}
/** A quick stage move from the list: only when nothing else is needed (quote details already there, not Lost). */
function enq_quick(PDO $db, array $u, int $id, string $to): void {
    $e = enq_get($db, $id); if (!$e || $e['removed_at'] || !enq_can($u, $e)) throw new RuntimeException('Enquiry not found.');
    if (!isset(ENQ_STAGES[$to]) || $to === 'lost') throw new RuntimeException('Open the enquiry to mark it lost.');
    if (in_array($to, ENQ_NEEDS_QUOTE, true) && ($e['quote_no'] === '' || !(float)$e['basic'])) throw new RuntimeException('Open the enquiry and add the quotation number and value first.');
    $set = enq_settings();
    $f = in_array($to, ENQ_OPEN, true) ? (($e['follow_up'] && $e['follow_up'] >= date('Y-m-d')) ? $e['follow_up'] : date('Y-m-d', strtotime('+' . enq_follow_days($to, $set) . ' day'))) : null;
    $db->prepare('UPDATE enq_enquiry SET status = ?, follow_up = ?, lost_reason = \'\' WHERE id = ?')->execute([$to, $f, $id]);
    enq_note($db, $id, 'Stage: ' . ENQ_STAGES[$e['status']] . ' → ' . ENQ_STAGES[$to], (int)$u['id'], true);
}
function enq_remove(PDO $db, array $u, int $id, string $reason): void {
    $e = enq_get($db, $id); if (!$e || $e['removed_at'] || !enq_can($u, $e)) throw new RuntimeException('Enquiry not found.');
    if (!enq_rights($u)['branch']) throw new RuntimeException('Only a manager removes an enquiry.');
    if (($reason = trim(strip_tags($reason))) === '') throw new RuntimeException('Say why it is removed.');
    $db->prepare('UPDATE enq_enquiry SET removed_at = NOW(), removed_by = ?, removed_reason = ? WHERE id = ?')->execute([(int)$u['id'], mb_substr($reason, 0, 300), $id]);
    audit((int)$u['id'], 'enq_remove', '#' . $id);
}
/** Make a CRM customer from the enquiry (or link the one that matches) — needs CRM rights to add customers. */
function enq_make_customer(PDO $db, array $u, int $id): int {
    $e = enq_get($db, $id); if (!$e || !enq_can($u, $e)) throw new RuntimeException('Enquiry not found.');
    if ($e['customer_id']) return (int)$e['customer_id'];
    $cid = crm_customer_save($db, $u, 0, ['name' => $e['company'] !== '' ? $e['company'] : $e['customer_name'], 'kind' => $e['company'] !== '' ? 'company' : 'person', 'source' => mb_substr($e['source'], 0, 60),
        'branch_id' => $e['branch_id'] ?? 0, 'phone' => $e['phone'] !== '' ? [$e['phone']] : [], 'wa' => [1], 'email' => $e['email'] !== '' ? [$e['email']] : [], 'cname' => $e['company'] !== '' ? [$e['customer_name']] : []]);
    $db->prepare('UPDATE enq_enquiry SET customer_id = ? WHERE id = ?')->execute([$cid, $id]);
    enq_note($db, $id, 'CRM customer made from this enquiry', (int)$u['id'], true);
    return $cid;
}
/** Enquiries this login sees. $f: q, stage (all / open / a stage), attn (overdue / today / due), source, category, assigned, removed */
function enq_list(PDO $db, array $u, array $f = []): array {
    $w = [empty($f['removed']) ? 'e.removed_at IS NULL' : 'e.removed_at IS NOT NULL']; $p = [];
    $st = (string)($f['stage'] ?? 'open');
    if ($st === 'open') $w[] = "e.status IN ('new','contacted','quoted','negotiation')"; elseif (isset(ENQ_STAGES[$st])) { $w[] = 'e.status = ?'; $p[] = $st; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $l = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w[] = '(e.ref LIKE ? OR e.customer_name LIKE ? OR e.company LIKE ? OR e.email LIKE ? OR e.phone LIKE ? OR e.quote_no LIKE ?)'; array_push($p, $l, $l, $l, $l, '%' . (preg_replace('/\D/', '', $q) ?: '#') . '%', $l); }
    foreach (['source', 'category'] as $k) if (($f[$k] ?? '') !== '') { $w[] = "e.$k = ?"; $p[] = $f[$k]; }
    if (($f['assigned'] ?? '') !== '') { if ($f['assigned'] === 'none') $w[] = 'e.assigned_user IS NULL'; else { $w[] = 'e.assigned_user = ?'; $p[] = (int)$f['assigned']; } }
    $today = date('Y-m-d');
    if (($f['attn'] ?? '') === 'overdue') { $w[] = 'e.follow_up < ?'; $p[] = $today; } elseif (($f['attn'] ?? '') === 'today') { $w[] = 'e.follow_up = ?'; $p[] = $today; } elseif (($f['attn'] ?? '') === 'due') { $w[] = 'e.follow_up <= ?'; $p[] = $today; }
    $s = $db->prepare('SELECT e.*, u.name AS assignee FROM enq_enquiry e LEFT JOIN users u ON u.id = e.assigned_user WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . (($f['sort'] ?? '') === 'newest' ? 'e.id DESC' : "e.follow_up IS NULL, e.follow_up, e.id DESC") . ' LIMIT ' . (int)($f['limit'] ?? 500));
    $s->execute($p);
    return array_values(array_filter($s->fetchAll(), fn($e) => enq_can($u, $e)));
}
/** Check a file / pasted rows: writes nothing. Each row: new / note (open one there) / error. */
function enq_import_plan(PDO $db, array $rows): array {
    $set = enq_settings(); $known = enq_known($set);
    [$hi, $map] = tbl_map($rows, ENQ_IMPORT_COLS, 1);
    if ($hi < 0) { $hi = -1; $map = array_flip(['customer_name', 'company', 'phone', 'email', 'category', 'status', 'basic', 'enquiry_date', 'source', 'quote_no']); }   // the template's order, no titles
    $plan = []; $seen = [];
    foreach (array_slice($rows, $hi + 1, null, true) as $i => $c) {
        if (!array_filter($c, fn($x) => trim((string)$x) !== '')) continue;
        $raw = enq_row_from($c, $map, $known, date('Y-m-d'), $set);
        [$d, $e] = enq_check($raw, $known, date('Y-m-d'), (int)$set['phone_digits']);
        $r = ['line' => $i + 1, 'd' => $d, 'note' => $raw['_note'], 'why' => implode(' ', array_unique($e))];
        $k = $d['phone_key'] ?: $d['email'];
        if ($e) $r['state'] = 'error';
        elseif ($k !== '' && isset($seen[$k])) { $r['state'] = 'twice'; }
        elseif (in_array($d['status'], ENQ_OPEN, true) && ($m = enq_open_match($db, $d['phone_key'], $d['email']))) { $r['state'] = 'note'; $r['match'] = $m; }
        else $r['state'] = 'new';
        if ($k !== '') $seen[$k] = true;
        $plan[] = $r;
    }
    return $plan;
}
function enq_import_apply(PDO $db, array $u, array $plan): array {
    if (!enq_rights($u)['branch']) throw new RuntimeException('Only a manager imports enquiries.');
    $n = ['new' => 0, 'note' => 0, 'skipped' => 0];
    foreach ($plan as $r) {
        if ($r['state'] === 'note') { enq_note($db, (int)$r['match']['id'], 'Again in an imported file: ' . $r['d']['customer_name'] . ($r['note'] !== '' ? ' — ' . $r['note'] : ''), (int)$u['id'], true); $n['note']++; continue; }
        if ($r['state'] !== 'new') { $n['skipped']++; continue; }
        $in = $r['d']; $in['basic'] = $in['basic'] === null ? '' : (string)$in['basic']; $in['tax_pct'] = ''; $in['note'] = $r['note'] !== '' ? 'Imported · ' . $r['note'] : 'Imported';
        enq_save($db, $u, 0, $in, true); $n['new']++;
    }
    audit((int)$u['id'], 'enq_import', 'new ' . $n['new'] . ' notes ' . $n['note']);
    return $n;
}
