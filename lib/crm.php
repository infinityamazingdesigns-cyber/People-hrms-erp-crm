<?php
// CRM masters — rules. One record per customer (contacts, mobiles with WhatsApp yes / no, emails, addresses, notes),
// products, the duplicate check across the whole organisation (tax ID, mobile, email, similar name at the same city /
// postcode), merge (the kept record gets everything; the other points at it), archive instead of delete, CSV / Excel
// import with a check that writes nothing, and a change history. Pure functions first, then the database half.
declare(strict_types=1);
require_once __DIR__ . '/table_read.php';

const CRM_KINDS = ['company' => 'Company', 'person' => 'Person'];
const CRM_NAME_STOP = ['m', 's', 'ms', 'mss', 'messrs', 'pvt', 'private', 'ltd', 'limited', 'llp', 'llc', 'gmbh', 'co', 'company', 'corp', 'corporation', 'inc',
                       'the', 'and', 'of', 'enterprise', 'enterprises', 'industries', 'industry', 'ind', 'pl', 'plc', 'opc', 'firm', 'group'];
const CRM_DUP_NAME_MIN = 85;     // similar name with the same city / postcode
const CRM_DUP_NAME_ALONE = 93;   // similar name when either side has no place
const CRM_DEFAULTS = ['all_roles' => ['SUPERADMIN', 'CEO'], 'own_roles' => ['SALES'], 'view_roles' => [], 'admin_roles' => ['SUPERADMIN', 'CEO'],
                      'categories' => "Dealer\nEnd user\nContractor\nOEM\nGovernment", 'units' => "pcs\nset\nkg\nm\nlitre\nbox", 'phone_digits' => 10,
                      // orders and dispatch (part 2)
                      'dispatch_roles' => ['SUPERADMIN', 'STORE'], 'order_prefix' => 'SO', 'link_days' => 30, 'public_po' => true, 'public_qty' => true,
                      'same_day_cutoff' => '', 'notify_default' => true, 'ready_needs_no_blockers' => true,
                      'order_sources' => "Phone call\nEmail\nWhatsApp\nWalk-in\nQuotation\nTender\nOnline marketplace\nOther",
                      'internal_blockers' => "Waiting for production\nWaiting for purchase", 'customer_blockers' => "Waiting for the customer's advance\nWaiting for the customer to confirm the dispatch date"];
const CRM_IMPORT_COLS = [
    'name' => ['name', 'customer', 'customername', 'company', 'companyname', 'party', 'partyname', 'client', 'clientname', 'firm'],
    'contact' => ['contact', 'contactperson', 'contactname', 'person', 'attention'],
    'mobile' => ['mobile', 'mobileno', 'phone', 'phoneno', 'contactno', 'whatsapp', 'tel', 'telephone', 'mobilenumber', 'phonenumber'],
    'email' => ['email', 'emailid', 'mail', 'emailaddress'],
    'tax_id' => ['gstin', 'gst', 'gstno', 'taxid', 'vat', 'vatno', 'taxnumber'],
    'city' => ['city', 'town', 'district'], 'state' => ['state', 'province', 'region'], 'postcode' => ['pincode', 'pin', 'postcode', 'zip', 'zipcode'],
    'address' => ['address', 'address1', 'addressline1', 'street'], 'category' => ['category', 'type', 'customertype', 'segment'], 'industry' => ['industry', 'sector'],
    'branch' => ['branch', 'office', 'location']];
const CRM_PRODUCT_COLS = ['sku' => ['sku', 'code', 'itemcode', 'productcode', 'partno', 'partnumber', 'model', 'modelno'], 'name' => ['name', 'item', 'itemname', 'product', 'productname', 'description'],
    'category' => ['category', 'group', 'itemgroup'], 'unit' => ['unit', 'uom', 'units'], 'tax_code' => ['hsn', 'hsncode', 'sac', 'taxcode', 'hsnsac'], 'tax_pct' => ['gst', 'gstpct', 'tax', 'taxpct', 'taxrate', 'vatpct'],
    'rate' => ['rate', 'price', 'mrp', 'unitprice', 'listprice']];

function crm_settings(): array { $s = module_states()['crm']['settings'] ?? []; return array_replace(CRM_DEFAULTS, array_intersect_key($s, CRM_DEFAULTS)); }
function crm_lines(string $s): array { return array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 80), explode("\n", str_replace("\r", '', $s))), 'strlen'))); }

function crm_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['all_roles', 'own_roles', 'view_roles', 'admin_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['admin_roles']) $e[] = 'Pick at least one role for merging, importing and archiving.';
    $o['categories'] = implode("\n", array_slice(crm_lines((string)($in['categories'] ?? '')), 0, 60));
    $o['units'] = implode("\n", array_slice(crm_lines((string)($in['units'] ?? '')), 0, 40)) ?: 'pcs';
    $o['phone_digits'] = max(6, min(12, (int)($in['phone_digits'] ?? 10)));
    $o['dispatch_roles'] = array_values(array_intersect(array_keys($roles), (array)($in['dispatch_roles'] ?? [])));
    $o['order_prefix'] = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($in['order_prefix'] ?? 'SO'))) ?: 'SO';
    $o['order_prefix'] = substr($o['order_prefix'], 0, 6);
    $o['link_days'] = max(1, min(365, (int)($in['link_days'] ?? 30)));
    foreach (['public_po', 'public_qty', 'notify_default', 'ready_needs_no_blockers'] as $k) $o[$k] = !empty($in[$k]);
    $c = trim((string)($in['same_day_cutoff'] ?? ''));
    if ($c !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $c)) { $e[] = 'Same-day cut-off is a time like 17:00, or empty.'; $c = ''; }
    $o['same_day_cutoff'] = $c;
    foreach (['order_sources' => 30, 'internal_blockers' => 20, 'customer_blockers' => 20] as $k => $max) $o[$k] = implode("\n", array_slice(crm_lines((string)($in[$k] ?? '')), 0, $max));
    if ($o['order_sources'] === '') $o['order_sources'] = 'Other';
    return [$o, $e];
}

/** A tax ID checked by the country's rule (rule pack). No rule = kept as typed (letters, digits, - and /). Pure. */
function crm_tax_check(string $v, ?callable $rule): array {
    $v = strtoupper(trim($v));
    if ($v === '') return ['ok' => true, 'value' => ''];
    if ($rule) { $r = $rule($v); return $r['ok'] ? ['ok' => true, 'value' => $r['value'], 'region' => $r['region'] ?? '', 'inner' => $r['inner'] ?? ''] : ['ok' => false, 'why' => $r['why']]; }
    return preg_match('/^[A-Z0-9\/-]{4,20}$/', $v) ? ['ok' => true, 'value' => $v] : ['ok' => false, 'why' => 'A tax ID is 4–20 letters and digits.'];
}

/** A mobile number as digits; a local number of $digits gets nothing added; anything shorter than 8 digits is refused (''). Pure. */
function crm_phone_norm(string $s): string {
    $d = (string)preg_replace('/\D/', '', $s);
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    return strlen($d) >= 8 && strlen($d) <= 15 ? $d : '';
}
/** The part that identifies a number (last digits of the local length). Pure. */
function crm_phone_key(string $d, int $digits): string { return strlen($d) > $digits ? substr($d, -$digits) : $d; }
function crm_email_norm(string $s): string { $s = strtolower(trim($s)); return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : ''; }
function crm_split(string $s): array { return array_values(array_filter(array_map('trim', preg_split('#[,;/|\n]+#', $s) ?: []), 'strlen')); }

/** A name made comparable: lower case, no punctuation, without words like Pvt / Ltd / Industries. Pure. */
function crm_norm_name(string $s): string {
    $s = mb_strtolower(str_replace('&', ' and ', $s));
    $s = (string)preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
    $w = array_values(array_filter(preg_split('/\s+/', trim($s)) ?: [], 'strlen'));
    $k = array_values(array_filter($w, fn($x) => !in_array($x, CRM_NAME_STOP, true)));
    return implode(' ', $k ?: $w);
}
/** How alike two compared names are, 0–100. Pure. */
function crm_name_sim(string $a, string $b): int {
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 100;
    similar_text($a, $b, $p);
    $x = substr($a, 0, 255); $y = substr($b, 0, 255);
    $lev = (int)round(100 * (1 - levenshtein($x, $y) / max(strlen($x), strlen($y))));
    $glued = str_replace(' ', '', $a) === str_replace(' ', '', $b) ? 99 : 0;
    $ta = explode(' ', $a); $tb = explode(' ', $b);
    [$s, $l] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
    $within = !array_diff($s, $l) && mb_strlen(implode('', $s)) >= 5 ? 90 : 0;
    return (int)max(round($p), $lev, $glued, $within);
}

/**
 * Possible duplicates of a customer among others: exact (tax ID, a mobile, an email) or similar name at the same
 * place (≥ 85) / without a place on either side (≥ 93). $c and rows: [id, name, tax_id, phones[], emails[], places[]].
 * Pairs marked "not the same" are skipped. Pure.
 */
function crm_dupes(array $c, array $rows, array $notSame = [], int $digits = 10): array {
    $out = []; $norm = crm_norm_name((string)$c['name']);
    $pk = array_map(fn($p) => crm_phone_key((string)$p, $digits), $c['phones'] ?? []);
    foreach ($rows as $r) {
        if ((int)$r['id'] === (int)($c['id'] ?? 0) || in_array((int)$r['id'], $notSame, true)) continue;
        $why = [];
        if (($c['tax_id'] ?? '') !== '' && strcasecmp((string)$c['tax_id'], (string)($r['tax_id'] ?? '')) === 0) $why[] = 'same tax ID';
        if (array_intersect($pk, array_map(fn($p) => crm_phone_key((string)$p, $digits), $r['phones'] ?? []))) $why[] = 'same mobile';
        if (array_intersect(array_map("strval", $c["emails"] ?? []), array_map("strval", $r["emails"] ?? []))) $why[] = 'same email';
        $exact = (bool)$why;
        if (!$exact) {
            $sim = crm_name_sim($norm, crm_norm_name((string)$r['name']));
            $cp = array_map('mb_strtolower', array_filter($c['places'] ?? [])); $rp = array_map('mb_strtolower', array_filter($r['places'] ?? []));
            $same = (bool)array_intersect($cp, $rp);
            if (($same && $sim >= CRM_DUP_NAME_MIN) || ((!$cp || !$rp) && $sim >= CRM_DUP_NAME_ALONE) || $sim === 100) $why[] = "similar name ($sim)" . ($same ? ' at the same place' : '');
        }
        if ($why) $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'exact' => $exact, 'why' => implode(', ', $why)];
    }
    usort($out, fn($a, $b) => [$b['exact'], $a['name']] <=> [$a['exact'], $b['name']]);
    return $out;
}

/** Next code like C00001. Pure. */
function crm_next_code(string $prefix, ?string $last): string { $n = $last && preg_match('/(\d+)$/', $last, $m) ? (int)$m[1] + 1 : 1; return $prefix . sprintf('%05d', $n); }

/** Field-by-field changes between two snapshots (screen words). Pure. */
function crm_diff(array $old, array $new): array {
    $o = [];
    foreach ($new as $k => $v) { $a = is_array($old[$k] ?? null) ? implode(', ', $old[$k]) : (string)($old[$k] ?? ''); $b = is_array($v) ? implode(', ', $v) : (string)$v; if ($a !== $b) $o[$k] = [$a, $b]; }
    return $o;
}

/** One row of a customer file → a clean customer, plus what could not be read. Pure. */
function crm_import_row(array $cells, array $map, ?callable $taxRule): array {
    $g = fn(string $f) => isset($map[$f]) ? trim((string)($cells[$map[$f]] ?? '')) : '';
    $c = ['name' => mb_substr(trim((string)preg_replace('/\s+/u', ' ', $g('name'))), 0, 200), 'contact' => mb_substr($g('contact'), 0, 120), 'category' => mb_substr($g('category'), 0, 80),
          'industry' => mb_substr($g('industry'), 0, 80), 'branch' => $g('branch'), 'address' => mb_substr($g('address'), 0, 200), 'city' => mb_substr($g('city'), 0, 80),
          'state' => mb_substr($g('state'), 0, 60), 'postcode' => mb_substr($g('postcode'), 0, 12), 'phones' => [], 'emails' => [], 'tax_id' => '', 'bad' => []];
    foreach (crm_split($g('mobile')) as $p) { $n = crm_phone_norm($p); if ($n !== '') $c['phones'][] = $n; else $c['bad'][] = 'mobile'; }
    foreach (crm_split($g('email')) as $p) { $n = crm_email_norm($p); if ($n !== '') $c['emails'][] = $n; else $c['bad'][] = 'email'; }
    $t = crm_tax_check($g('tax_id'), $taxRule);
    if ($t['ok']) $c['tax_id'] = $t['value']; else $c['bad'][] = 'tax ID';
    $c['phones'] = array_values(array_unique($c['phones'])); $c['emails'] = array_values(array_unique($c['emails']));
    $c['places'] = array_filter([$c['city'], $c['postcode']]);
    return $c;
}

// ---------- database ----------

function crm_pack(): array {
    $c = preg_replace('/[^A-Z]/', '', strtoupper(org('country')));
    $f = WN_ROOT . '/rulepacks/' . $c . '/pack.php';
    return $c !== '' && is_file($f) ? (require $f) : (require WN_ROOT . '/rulepacks/XX/pack.php');
}
function crm_tax_rule(): ?callable { $p = crm_pack(); $fn = $p['tax_id']['fn'] ?? ''; return $fn !== '' && function_exists($fn) ? $fn : null; }
function crm_tax_label(): string { return crm_pack()['tax_id']['label'] ?? 'Tax ID'; }

function crm_roles(array $u): array { return user_roles((int)$u['id'], !empty($u['is_owner'])); }
/** What this login may do: all (every customer in branch scope), own (customers they own + create), view (read), admin (merge, import, archive). */
function crm_rights(array $u): array {
    $r = crm_roles($u); $s = crm_settings(); $h = fn($k) => (bool)array_intersect($r, $s[$k]);
    $all = $h('all_roles') || $h('admin_roles');
    return ['all' => $all, 'own' => $all || $h('own_roles'), 'view' => $all || $h('own_roles') || $h('view_roles'), 'admin' => $h('admin_roles'), 'edit' => $all || $h('own_roles'), 'dispatch' => $h('dispatch_roles')];
}
function crm_need(array $u, string $k): void { if (!crm_rights($u)[$k]) throw new RuntimeException('Your role cannot do this.'); }

/** May this login see / change this customer? */
function crm_can(array $u, array $c, bool $write = false): bool {
    $R = crm_rights($u);
    $branch = $c['branch_id'] === null || emp_in_scope($u, ['branch_id' => $c['branch_id']]);
    if ($R['all']) return $branch;
    if ($R['own'] && (int)$c['owner_user'] === (int)$u['id']) return true;
    return !$write && $branch && (bool)array_intersect(crm_roles($u), crm_settings()['view_roles']);
}

function crm_customer_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM crm_customer WHERE id = ?'); $s->execute([$id]); $c = $s->fetch();
    if (!$c) return null;
    $order = ['crm_contact' => 'is_primary DESC, id', 'crm_phone' => 'is_primary DESC, id', 'crm_email' => 'is_primary DESC, id', 'crm_address' => 'ship_default DESC, id'];
    foreach (['contacts' => 'crm_contact', 'phones' => 'crm_phone', 'emails' => 'crm_email', 'addresses' => 'crm_address'] as $k => $t) {
        $q = $db->prepare("SELECT * FROM $t WHERE customer_id = ? AND active = 1 ORDER BY " . $order[$t]); $q->execute([$id]); $c[$k] = $q->fetchAll();
    }
    return $c;
}

/** The duplicate-check view of every customer (active and archived, merged left out). */
function crm_dupe_rows(PDO $db): array {
    $rows = [];
    foreach ($db->query('SELECT id, name, tax_id FROM crm_customer WHERE merged_into IS NULL') as $r) $rows[(int)$r['id']] = $r + ['phones' => [], 'emails' => [], 'places' => []];
    foreach ($db->query('SELECT customer_id, number FROM crm_phone WHERE active = 1') as $r) if (isset($rows[(int)$r['customer_id']])) $rows[(int)$r['customer_id']]['phones'][] = $r['number'];
    foreach ($db->query('SELECT customer_id, email FROM crm_email WHERE active = 1') as $r) if (isset($rows[(int)$r['customer_id']])) $rows[(int)$r['customer_id']]['emails'][] = $r['email'];
    foreach ($db->query('SELECT customer_id, city, postcode FROM crm_address WHERE active = 1') as $r) if (isset($rows[(int)$r['customer_id']])) array_push($rows[(int)$r['customer_id']]['places'], $r['city'], $r['postcode']);
    return $rows;
}
function crm_not_same(PDO $db, string $kind, int $id): array {
    $s = $db->prepare('SELECT IF(a = ?, b, a) FROM crm_dup_pair WHERE kind = ? AND (a = ? OR b = ?)'); $s->execute([$id, $kind, $id, $id]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/** The record a customer from the form becomes (pure-ish: settings and pack only). */
function crm_customer_input(array $in): array {
    $t = crm_tax_check((string)($in['tax_id'] ?? ''), crm_tax_rule());
    if (!$t['ok']) throw new RuntimeException(crm_tax_label() . ': ' . $t['why']);
    $name = mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($in['name'] ?? '')))), 0, 200);
    if ($name === '') throw new RuntimeException('Give the customer a name.');
    $c = ['name' => $name, 'kind' => isset(CRM_KINDS[$in['kind'] ?? '']) ? (string)$in['kind'] : 'company', 'tax_id' => $t['value'] ?: null,
          'category' => mb_substr(trim(strip_tags((string)($in['category'] ?? ''))), 0, 80), 'industry' => mb_substr(trim(strip_tags((string)($in['industry'] ?? ''))), 0, 80),
          'source' => mb_substr(trim(strip_tags((string)($in['source'] ?? ''))), 0, 60), 'website' => '', 'branch_id' => (int)($in['branch_id'] ?? 0) ?: null];
    $w = trim((string)($in['website'] ?? ''));
    if ($w !== '') { if (!preg_match('#^https?://#i', $w)) $w = 'https://' . $w; if (!filter_var($w, FILTER_VALIDATE_URL)) throw new RuntimeException('Check the website address.'); $c['website'] = mb_substr($w, 0, 200); }
    if ($c['branch_id'] && !in_array($c['branch_id'], array_map(fn($b) => (int)$b['id'], branches()), true)) throw new RuntimeException('Pick the branch from the list.');
    $c['phones'] = []; foreach ((array)($in['phone'] ?? []) as $i => $p) { if (trim((string)$p) === '') continue; $n = crm_phone_norm((string)$p); if ($n === '') throw new RuntimeException('Check the mobile number "' . mb_substr((string)$p, 0, 20) . '".'); $c['phones'][$n] = ['number' => $n, 'whatsapp' => !empty($in['wa'][$i]) ? 1 : 0, 'label' => mb_substr(trim((string)($in['plabel'][$i] ?? '')), 0, 40)]; }
    $c['emails'] = []; foreach ((array)($in['email'] ?? []) as $e) { if (trim((string)$e) === '') continue; $n = crm_email_norm((string)$e); if ($n === '') throw new RuntimeException('Check the email "' . mb_substr((string)$e, 0, 40) . '".'); $c['emails'][$n] = $n; }
    $c['contacts'] = []; foreach ((array)($in['cname'] ?? []) as $i => $n) { $n = mb_substr(trim(strip_tags((string)$n)), 0, 120); if ($n !== '') $c['contacts'][] = ['name' => $n, 'designation' => mb_substr(trim((string)($in['cdesig'][$i] ?? '')), 0, 80)]; }
    $c['addresses'] = []; foreach ((array)($in['line1'] ?? []) as $i => $l) { $l = mb_substr(trim(strip_tags((string)$l)), 0, 200); if ($l === '') continue;
        $c['addresses'][] = ['label' => mb_substr(trim((string)($in['alabel'][$i] ?? '')), 0, 40), 'line1' => $l, 'line2' => mb_substr(trim((string)($in['line2'][$i] ?? '')), 0, 200), 'city' => mb_substr(trim((string)($in['city'][$i] ?? '')), 0, 80),
                             'state' => mb_substr(trim((string)($in['state'][$i] ?? '')), 0, 60), 'postcode' => mb_substr(trim((string)($in['postcode'][$i] ?? '')), 0, 12), 'country' => mb_substr(trim((string)($in['country'][$i] ?? '')), 0, 60)]; }
    return $c;
}

/** Save a customer (new or changed). Children are replaced by what the form shows; removed ones are switched off. Returns the id. */
function crm_customer_save(PDO $db, array $u, int $id, array $in, bool $force = false): int {
    crm_need($u, 'edit');
    $c = crm_customer_input($in);
    $old = $id ? crm_customer_get($db, $id) : null;
    if ($id && (!$old || !crm_can($u, $old, true))) throw new RuntimeException('Customer not found.');
    if ($old && $old['merged_into']) throw new RuntimeException('This record was merged into another one.');
    if ($c['branch_id'] && !emp_in_scope($u, ['branch_id' => $c['branch_id']])) throw new RuntimeException('That branch is outside your scope.');
    $check = ['id' => $id, 'name' => $c['name'], 'tax_id' => (string)$c['tax_id'], 'phones' => array_keys($c['phones']), 'emails' => array_values($c['emails']), 'places' => array_merge(array_column($c['addresses'], 'city'), array_column($c['addresses'], 'postcode'))];
    $d = crm_dupes($check, crm_dupe_rows($db), $id ? crm_not_same($db, 'cust', $id) : [], (int)crm_settings()['phone_digits']);
    $exact = array_values(array_filter($d, fn($x) => $x['exact']));
    if ($c['tax_id'] && array_filter($exact, fn($x) => str_contains($x['why'], 'tax ID'))) throw new RuntimeException(crm_tax_label() . ' already belongs to ' . $exact[0]['name'] . '.');
    if ($d && !$force) throw new RuntimeException('Looks like an existing customer: ' . implode('; ', array_map(fn($x) => $x['name'] . ' (' . $x['why'] . ')', array_slice($d, 0, 3))) . '. Open that record, or tick "It is a different customer" to save anyway.');
    if ($force && $exact && !crm_rights($u)['admin']) throw new RuntimeException('Only an admin keeps two records with the same mobile / email.');
    $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
    try {
        $f = ['name' => $c['name'], 'kind' => $c['kind'], 'tax_id' => $c['tax_id'], 'category' => $c['category'], 'industry' => $c['industry'], 'source' => $c['source'], 'website' => $c['website'], 'branch_id' => $c['branch_id']];
        if ($old) {
            $db->prepare('UPDATE crm_customer SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
            $before = crm_snapshot($old);
        } else {
            $f['owner_user'] = crm_rights($u)['all'] && (int)($in['owner_user'] ?? 0) ? (int)$in['owner_user'] : (int)$u['id'];
            $f['created_by'] = (int)$u['id'];
            $db->prepare('INSERT INTO crm_customer (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
            $id = (int)$db->lastInsertId();
            $last = $db->query("SELECT code FROM crm_customer WHERE code IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
            $db->prepare('UPDATE crm_customer SET code = ? WHERE id = ?')->execute([crm_next_code('C', $last ?: null), $id]);
            $before = [];
        }
        crm_put_children($db, $id, $c);
        crm_history($db, 'customer', $id, $before, crm_snapshot(crm_customer_get($db, $id)), (int)$u['id'], $old ? '' : 'created');
        if ($own) $db->commit();
    } catch (Throwable $e) { if ($own && $db->inTransaction()) $db->rollBack(); if ($e instanceof PDOException && str_contains($e->getMessage(), 'uk_tax')) throw new RuntimeException(crm_tax_label() . ' already belongs to another customer.'); throw $e; }
    audit((int)$u['id'], $old ? 'crm_customer_edit' : 'crm_customer_add', '#' . $id);
    return $id;
}

function crm_put_children(PDO $db, int $id, array $c): void {
    $db->prepare('UPDATE crm_phone SET active = 0 WHERE customer_id = ?')->execute([$id]);
    $p = $db->prepare('INSERT INTO crm_phone (customer_id, number, label, whatsapp, is_primary) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE active = 1, label = VALUES(label), whatsapp = VALUES(whatsapp), is_primary = VALUES(is_primary)');
    $i = 0; foreach ($c['phones'] as $x) $p->execute([$id, $x['number'], $x['label'], $x['whatsapp'], $i++ === 0 ? 1 : 0]);
    $db->prepare('UPDATE crm_email SET active = 0 WHERE customer_id = ?')->execute([$id]);
    $e = $db->prepare('INSERT INTO crm_email (customer_id, email, is_primary) VALUES (?,?,?) ON DUPLICATE KEY UPDATE active = 1, is_primary = VALUES(is_primary)');
    $i = 0; foreach ($c['emails'] as $x) $e->execute([$id, $x, $i++ === 0 ? 1 : 0]);
    $db->prepare('UPDATE crm_contact SET active = 0 WHERE customer_id = ?')->execute([$id]);
    $k = $db->prepare('INSERT INTO crm_contact (customer_id, name, designation, is_primary) VALUES (?,?,?,?)');
    $i = 0; foreach ($c['contacts'] as $x) $k->execute([$id, $x['name'], $x['designation'], $i++ === 0 ? 1 : 0]);
    $db->prepare('UPDATE crm_address SET active = 0 WHERE customer_id = ?')->execute([$id]);
    $a = $db->prepare('INSERT INTO crm_address (customer_id, label, line1, line2, city, state, postcode, country, ship_default, bill_default) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $i = 0; foreach ($c['addresses'] as $x) { $a->execute([$id, $x['label'], $x['line1'], $x['line2'], $x['city'], $x['state'], $x['postcode'], $x['country'], $i === 0 ? 1 : 0, $i === 0 ? 1 : 0]); $i++; }
}

/** What the history compares (screen words, never ids). */
function crm_snapshot(?array $c): array {
    if (!$c) return [];
    return ['Name' => $c['name'], 'Kind' => CRM_KINDS[$c['kind']] ?? $c['kind'], 'Tax ID' => (string)$c['tax_id'], 'Category' => $c['category'], 'Industry' => $c['industry'], 'Source' => $c['source'], 'Website' => $c['website'],
            'Branch' => (string)$c['branch_id'], 'Status' => $c['status'], 'Contacts' => array_column($c['contacts'] ?? [], 'name'), 'Mobiles' => array_column($c['phones'] ?? [], 'number'),
            'Emails' => array_column($c['emails'] ?? [], 'email'), 'Addresses' => array_map(fn($a) => trim($a['line1'] . ', ' . $a['city'], ', '), $c['addresses'] ?? [])];
}
function crm_history(PDO $db, string $entity, int $id, array $before, array $after, int $uid, string $reason = ''): void {
    $s = $db->prepare('INSERT INTO crm_change (entity, entity_id, field, old_value, new_value, reason, by_user) VALUES (?,?,?,?,?,?,?)');
    foreach (crm_diff($before, $after) as $f => [$a, $b]) $s->execute([$entity, $id, $f, mb_substr($a, 0, 500), mb_substr($b, 0, 500), $reason, $uid]);
}

/** Customers this login sees, with the main mobile, city and owner. */
function crm_customer_list(PDO $db, array $u, string $q = '', string $status = 'active', int $limit = 300): array {
    $w = ['c.merged_into IS NULL']; $p = [];
    if ($status !== 'all') { $w[] = 'c.status = ?'; $p[] = $status; }
    if (($q = trim($q)) !== '') { $l = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w[] = '(c.name LIKE ? OR c.code LIKE ? OR c.tax_id LIKE ? OR EXISTS (SELECT 1 FROM crm_phone ph WHERE ph.customer_id = c.id AND ph.number LIKE ?) OR EXISTS (SELECT 1 FROM crm_email em WHERE em.customer_id = c.id AND em.email LIKE ?))'; array_push($p, $l, $l, $l, '%' . preg_replace('/\D/', '', $q) . '%', $l); }
    $s = $db->prepare('SELECT c.*, u.name AS owner, (SELECT number FROM crm_phone ph WHERE ph.customer_id = c.id AND ph.active = 1 ORDER BY is_primary DESC LIMIT 1) AS mobile,
                       (SELECT city FROM crm_address a WHERE a.customer_id = c.id AND a.active = 1 ORDER BY ship_default DESC LIMIT 1) AS city FROM crm_customer c LEFT JOIN users u ON u.id = c.owner_user WHERE ' . implode(' AND ', $w) . ' ORDER BY c.name LIMIT ' . (int)$limit);
    $s->execute($p);
    return array_values(array_filter($s->fetchAll(), fn($c) => crm_can($u, $c)));
}

/** Archive (a reason needed) or bring back. Admin only. */
function crm_customer_status(PDO $db, array $u, int $id, bool $archive, string $reason): void {
    crm_need($u, 'admin');
    $c = crm_customer_get($db, $id);
    if (!$c || !crm_can($u, $c, true) || $c['merged_into']) throw new RuntimeException('Customer not found.');
    if (($reason = trim(strip_tags($reason))) === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE crm_customer SET status = ?, archived_reason = ? WHERE id = ?')->execute([$archive ? 'archived' : 'active', mb_substr($reason, 0, 300), $id]);
    crm_history($db, 'customer', $id, ['Status' => $c['status']], ['Status' => $archive ? 'archived' : 'active'], (int)$u['id'], $reason);
}

/** Give a customer to another owner (all rights). */
function crm_customer_owner(PDO $db, array $u, int $id, int $owner): void {
    crm_need($u, 'all');
    $c = crm_customer_get($db, $id); if (!$c || !crm_can($u, $c, true)) throw new RuntimeException('Customer not found.');
    $s = $db->prepare('SELECT name FROM users WHERE id = ? AND active = 1'); $s->execute([$owner]); $n = $s->fetchColumn();
    if (!$n) throw new RuntimeException('Pick an active login.');
    $o = $db->prepare('SELECT name FROM users WHERE id = ?'); $o->execute([(int)$c['owner_user']]);
    $db->prepare('UPDATE crm_customer SET owner_user = ? WHERE id = ?')->execute([$owner, $id]);
    crm_history($db, 'customer', $id, ['Owner' => (string)$o->fetchColumn()], ['Owner' => (string)$n], (int)$u['id']);
}

/** Merge $drop into $keep: the kept record gets contacts, numbers, emails, addresses, notes and its blanks filled; the other is archived and points at it. */
function crm_customer_merge(PDO $db, array $u, int $keep, int $drop): void {
    crm_need($u, 'admin');
    if ($keep === $drop) throw new RuntimeException('Pick two different records.');
    $k = crm_customer_get($db, $keep); $d = crm_customer_get($db, $drop);
    if (!$k || !$d || $k['merged_into'] || $d['merged_into'] || !crm_can($u, $k, true) || !crm_can($u, $d, true)) throw new RuntimeException('Customer not found.');
    $db->beginTransaction();
    try {
        foreach (['tax_id', 'category', 'industry', 'source', 'website', 'branch_id'] as $f) if (($k[$f] === null || $k[$f] === '') && $d[$f] !== null && $d[$f] !== '') {
            if ($f === 'tax_id') $db->prepare('UPDATE crm_customer SET tax_id = NULL WHERE id = ?')->execute([$drop]);
            $db->prepare("UPDATE crm_customer SET $f = ? WHERE id = ?")->execute([$d[$f], $keep]);
        }
        foreach (['crm_phone' => 'number', 'crm_email' => 'email'] as $t => $col)
            $db->prepare("INSERT IGNORE INTO $t (customer_id, $col, label, is_primary, active" . ($t === 'crm_phone' ? ', whatsapp' : '') . ") SELECT ?, $col, label, 0, 1" . ($t === 'crm_phone' ? ', whatsapp' : '') . " FROM $t WHERE customer_id = ? AND active = 1")->execute([$keep, $drop]);
        $db->prepare('UPDATE crm_contact SET customer_id = ?, is_primary = 0 WHERE customer_id = ? AND active = 1')->execute([$keep, $drop]);
        $db->prepare('UPDATE crm_address SET customer_id = ?, ship_default = 0, bill_default = 0 WHERE customer_id = ? AND active = 1')->execute([$keep, $drop]);
        $db->prepare("UPDATE crm_note SET entity_id = ? WHERE entity = 'customer' AND entity_id = ?")->execute([$keep, $drop]);
        $db->prepare("UPDATE crm_customer SET status = 'archived', merged_into = ?, archived_reason = ? WHERE id = ?")->execute([$keep, 'Merged into ' . $k['code'], $drop]);
        $db->prepare("INSERT INTO crm_note (entity, entity_id, body, by_user) VALUES ('customer', ?, ?, ?)")->execute([$keep, 'Merged ' . $d['code'] . ' ' . $d['name'] . ' into this record.', (int)$u['id']]);
        crm_history($db, 'customer', $keep, ['Merged' => ''], ['Merged' => $d['code'] . ' ' . $d['name']], (int)$u['id']);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'crm_customer_merge', '#' . $drop . ' > #' . $keep);
}

function crm_not_same_mark(PDO $db, array $u, string $kind, int $a, int $b): void {
    crm_need($u, 'admin');
    $db->prepare('INSERT IGNORE INTO crm_dup_pair (kind, a, b, by_user) VALUES (?,?,?,?)')->execute([$kind, min($a, $b), max($a, $b), (int)$u['id']]);
}

/** Every pair that looks like the same customer (admin screen), up to 200. */
function crm_dupe_pairs(PDO $db): array {
    $rows = crm_dupe_rows($db); $pairs = []; $seen = [];
    $ns = []; foreach ($db->query("SELECT a, b FROM crm_dup_pair WHERE kind = 'cust'") as $r) $ns[$r['a'] . '-' . $r['b']] = true;
    $digits = (int)crm_settings()['phone_digits'];
    foreach ($rows as $id => $r) {
        foreach (crm_dupes($r, array_filter($rows, fn($x) => (int)$x['id'] > $id), [], $digits) as $d) {
            $key = min($id, $d['id']) . '-' . max($id, $d['id']);
            if (isset($ns[$key]) || isset($seen[$key])) continue;
            $seen[$key] = true; $pairs[] = ['a' => $id, 'an' => $r['name'], 'b' => $d['id'], 'bn' => $d['name'], 'why' => $d['why'], 'exact' => $d['exact']];
            if (count($pairs) >= 200) return $pairs;
        }
    }
    return $pairs;
}

function crm_note_add(PDO $db, array $u, string $entity, int $id, string $body): void {
    $body = mb_substr(trim(strip_tags(str_replace("\r", '', $body))), 0, 2000);
    if ($body === '') throw new RuntimeException('Write the note.');
    if ($entity === 'customer') { $c = crm_customer_get($db, $id); if (!$c || !crm_can($u, $c)) throw new RuntimeException('Not found.'); }
    $db->prepare('INSERT INTO crm_note (entity, entity_id, body, by_user) VALUES (?,?,?,?)')->execute([$entity, $id, $body, (int)$u['id']]);
}

/** Check a customer file: writes nothing. Each row: New / Already there (blanks to fill) / Possible duplicate / Error. */
function crm_import_plan(PDO $db, array $rows): array {
    [$hi, $map] = tbl_map($rows, CRM_IMPORT_COLS, 1);
    if ($hi < 0 || !isset($map['name'])) throw new RuntimeException('No column with customer names was found (Name / Customer / Company / Party).');
    $rule = crm_tax_rule(); $exist = crm_dupe_rows($db); $digits = (int)crm_settings()['phone_digits'];
    $bn = []; foreach (branches() as $b) $bn[mb_strtolower($b['name'])] = (int)$b['id'];
    $plan = []; $byName = [];
    foreach (array_slice($rows, $hi + 1, null, true) as $i => $cells) {
        if (!array_filter($cells, fn($x) => trim((string)$x) !== '')) continue;
        $c = crm_import_row($cells, $map, $rule); $c['line'] = $i + 1;
        if ($c['name'] === '') { $plan[] = $c + ['state' => 'error', 'why' => 'No name']; continue; }
        $key = crm_norm_name($c['name']) . '|' . mb_strtolower($c['city']);
        if (isset($byName[$key])) { $j = $byName[$key]; $plan[$j]['phones'] = array_values(array_unique(array_merge($plan[$j]['phones'], $c['phones']))); $plan[$j]['emails'] = array_values(array_unique(array_merge($plan[$j]['emails'], $c['emails']))); $plan[$j]['merged']++; continue; }
        $c['branch_id'] = $bn[mb_strtolower(branch_label($c['branch']))] ?? ($bn[mb_strtolower($c['branch'])] ?? null);
        $d = crm_dupes(['id' => 0] + $c, $exist, [], $digits);
        $c['state'] = !$d ? 'new' : ($d[0]['exact'] ? 'there' : 'maybe'); $c['match'] = $d[0] ?? null; $c['merged'] = 0;
        $byName[$key] = count($plan); $plan[] = $c;
    }
    return $plan;
}

/** Save checked rows: new ones created; ones already there only get what they lack (numbers, emails, address, blanks). Ticked "maybe" rows become new. */
function crm_import_apply(PDO $db, array $u, array $plan, array $takeMaybe): array {
    crm_need($u, 'admin');
    $n = ['new' => 0, 'filled' => 0, 'skipped' => 0];
    $db->beginTransaction();
    try {
        foreach ($plan as $i => $c) {
            if ($c['state'] === 'error' || ($c['state'] === 'maybe' && !in_array($i, $takeMaybe, true))) { $n['skipped']++; continue; }
            if ($c['state'] === 'there') {
                $id = (int)$c['match']['id'];
                foreach ($c['phones'] as $p) $db->prepare('INSERT IGNORE INTO crm_phone (customer_id, number) VALUES (?,?)')->execute([$id, $p]);
                foreach ($c['emails'] as $e) $db->prepare('INSERT IGNORE INTO crm_email (customer_id, email) VALUES (?,?)')->execute([$id, $e]);
                foreach (['category', 'industry'] as $f) if ($c[$f] !== '') $db->prepare("UPDATE crm_customer SET $f = ? WHERE id = ? AND $f = ''")->execute([$c[$f], $id]);
                if ($c['tax_id'] !== '') $db->prepare('UPDATE crm_customer SET tax_id = ? WHERE id = ? AND tax_id IS NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM crm_customer WHERE tax_id = ?) x)')->execute([$c['tax_id'], $id, $c['tax_id']]);
                $n['filled']++; continue;
            }
            $in = ['name' => $c['name'], 'tax_id' => $c['tax_id'], 'category' => $c['category'], 'industry' => $c['industry'], 'source' => 'Import', 'branch_id' => $c['branch_id'] ?? 0,
                   'phone' => $c['phones'], 'wa' => array_fill(0, count($c['phones']), 1), 'email' => $c['emails'], 'cname' => $c['contact'] !== '' ? [$c['contact']] : [],
                   'line1' => $c['address'] !== '' || $c['city'] !== '' ? [$c['address'] ?: $c['city']] : [], 'city' => [$c['city']], 'state' => [$c['state']], 'postcode' => [$c['postcode']]];
            crm_customer_save($db, $u, 0, $in, true); $n['new']++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'crm_import', 'new ' . $n['new'] . ' filled ' . $n['filled']);
    return $n;
}

/** Products. */
function crm_product_input(array $in): array {
    $p = ['sku' => strtoupper(mb_substr(trim((string)($in['sku'] ?? '')), 0, 60)), 'name' => mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 200),
          'category' => mb_substr(trim(strip_tags((string)($in['category'] ?? ''))), 0, 80), 'unit' => mb_substr(trim((string)($in['unit'] ?? 'pcs')), 0, 12) ?: 'pcs',
          'tax_code' => mb_substr(preg_replace('/[^0-9A-Za-z]/', '', (string)($in['tax_code'] ?? '')), 0, 12), 'notes' => mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 500)];
    if ($p['name'] === '') throw new RuntimeException('Give the product a name.');
    if ($p['sku'] !== '' && !preg_match('/^[A-Z0-9._\/-]{1,60}$/', $p['sku'])) throw new RuntimeException('A product code uses letters, digits, . _ / -');
    $t = trim(str_replace('%', '', (string)($in['tax_pct'] ?? '0')));
    if (!is_numeric($t) || (float)$t < 0 || (float)$t > 100) throw new RuntimeException('Tax % must be 0–100.');
    $p['tax_pct'] = round((float)$t, 2);
    $r = trim(str_replace(',', '', (string)($in['rate'] ?? '')));
    if ($r !== '' && (!is_numeric($r) || (float)$r < 0)) throw new RuntimeException('The rate must be a number.');
    $p['rate'] = $r === '' ? null : round((float)$r, 2);
    return $p;
}
function crm_product_save(PDO $db, array $u, int $id, array $in): int {
    crm_need($u, 'all');
    $p = crm_product_input($in);
    $old = null; if ($id) { $s = $db->prepare('SELECT * FROM crm_product WHERE id = ?'); $s->execute([$id]); $old = $s->fetch() ?: throw new RuntimeException('Product not found.'); }
    if ($p['sku'] === '') $p['sku'] = $old['sku'] ?? crm_next_code('P', $db->query("SELECT sku FROM crm_product WHERE sku LIKE 'P0%' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null);
    $q = $db->prepare('SELECT id, name FROM crm_product WHERE (sku = ? OR LOWER(name) = LOWER(?)) AND id <> ? AND merged_into IS NULL'); $q->execute([$p['sku'], $p['name'], $id]);
    if ($o = $q->fetch()) throw new RuntimeException('Another product has that code or name: ' . $o['name'] . '.');
    if ($old) { $db->prepare('UPDATE crm_product SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($p))) . ' WHERE id = ?')->execute([...array_values($p), $id]); crm_history($db, 'product', $id, array_intersect_key($old, $p), $p, (int)$u['id']); }
    else { $p['created_by'] = (int)$u['id']; $db->prepare('INSERT INTO crm_product (' . implode(',', array_keys($p)) . ') VALUES (' . implode(',', array_fill(0, count($p), '?')) . ')')->execute(array_values($p)); $id = (int)$db->lastInsertId(); }
    return $id;
}
function crm_product_status(PDO $db, array $u, int $id, bool $on, string $reason): void {
    crm_need($u, 'admin');
    if (!$on && trim($reason) === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE crm_product SET active = ?, off_reason = ? WHERE id = ? AND merged_into IS NULL')->execute([$on ? 1 : 0, $on ? '' : mb_substr(trim(strip_tags($reason)), 0, 300), $id]);
}

/** Read a product file: new ones (by code, else name) are listed; existing codes get changed details; writes nothing until $apply. */
function crm_product_import(PDO $db, array $u, array $rows, bool $apply): array {
    crm_need($u, 'admin');
    [$hi, $map] = tbl_map($rows, CRM_PRODUCT_COLS, 1);
    if ($hi < 0 || !isset($map['name'])) throw new RuntimeException('No column with product names was found.');
    $out = ['new' => 0, 'update' => 0, 'error' => [], 'rows' => []];
    if ($apply) $db->beginTransaction();
    try {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $cells) {
            if (!array_filter($cells, fn($x) => trim((string)$x) !== '')) continue;
            $in = []; foreach ($map as $f => $col) $in[$f] = $cells[$col] ?? '';
            try { $p = crm_product_input($in); } catch (RuntimeException $e) { $out['error'][] = 'Line ' . ($i + 1) . ': ' . $e->getMessage(); continue; }
            $q = $db->prepare('SELECT id FROM crm_product WHERE ' . ($p['sku'] !== '' ? 'sku = ?' : 'LOWER(name) = LOWER(?)') . ' AND merged_into IS NULL'); $q->execute([$p['sku'] !== '' ? $p['sku'] : $p['name']]);
            $id = (int)$q->fetchColumn();
            $out[$id ? 'update' : 'new']++; $out['rows'][] = ['line' => $i + 1, 'name' => $p['name'], 'sku' => $p['sku'], 'state' => $id ? 'update' : 'new'];
            if ($apply) crm_product_save($db, $u, $id, $p);
        }
        if ($apply) $db->commit();
    } catch (Throwable $e) { if ($apply && $db->inTransaction()) $db->rollBack(); throw $e; }
    return $out;
}
