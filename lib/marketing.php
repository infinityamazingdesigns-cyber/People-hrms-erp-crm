<?php
// Marketing & ROAS — rules. Campaigns get a code (MK0001) that goes in the tracked link, so leads and ad spend find their
// campaign by code → platform id → UTM campaign → name. Spend per campaign per day (typed, or a platform's
// campaign-by-day report read by its column titles; one import line per campaign + day, a changed figure replaces it and
// the old line is kept switched off). Leads (typed, file, or the public lead hook with a key) become an enquiry, or a note
// on an open one. Consent per number, append-only. Orders → campaigns: a hand pick wins, else the customer's latest lead
// within the window before the order. ROAS = order value before tax ÷ spend. Content plan. Pure functions first.
declare(strict_types=1);
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/enquiries.php';
require_once __DIR__ . '/table_read.php';

/** key => [label, group, utm source, utm medium, enquiry source]. */
const MK_CHANNELS = [
    'social_page' => ['Social media page (organic)', 'Social media', 'social', 'social', 'Social media (organic)'],
    'messaging' => ['Messaging campaign (WhatsApp / chat)', 'Messaging', 'whatsapp', 'message', 'WhatsApp campaign'],
    'email' => ['Email / newsletter', 'Messaging', 'email', 'email', 'Email campaign'],
    'sms' => ['SMS', 'Messaging', 'sms', 'message', 'SMS campaign'],
    'social_ads' => ['Social media ads', 'Paid ads', 'social', 'paid_social', 'Social media ads'],
    'search_ads' => ['Search ads', 'Paid ads', 'search', 'cpc', 'Search ads'],
    'display_ads' => ['Display / video ads', 'Paid ads', 'display', 'display', 'Social media ads'],
    'marketplace' => ['Online marketplace / listing', 'Listings', 'marketplace', 'listing', 'Online marketplace'],
    'local' => ['Maps / local listing', 'Listings', 'maps', 'local', 'Search / maps listing'],
    'seo' => ['Website / SEO', 'Website & search', 'website', 'organic', 'Website form'],
    'referral' => ['Referral / dealer', 'Offline', 'referral', 'referral', 'Customer referral'],
    'expo' => ['Exhibition / trade show', 'Offline', 'expo', 'offline', 'Exhibition / trade fair'],
    'print' => ['Print / outdoor', 'Offline', 'print', 'offline', 'Other'],
    'other' => ['Other', 'Offline', 'other', 'other', 'Other'],
];
const MK_STATUSES = ['Planned', 'Live', 'Paused', 'Ended', 'Archived'];
const MK_OBJECTIVES = ['Leads / enquiries', 'Sales', 'Awareness', 'Website traffic', 'Re-engage customers', 'Dealer / partner'];
const MK_POST_STATUSES = ['Planned', 'Ready', 'Published', 'Dropped'];
/** kind => [label, enquiry source]. */
const MK_LEAD_KINDS = ['form' => ['Website / form', 'Website form'], 'whatsapp' => ['WhatsApp message', 'WhatsApp message'], 'call' => ['Call', 'Inbound phone call'],
                       'email' => ['Email reply', 'Email enquiry'], 'visit' => ['Visit / meeting', 'Walk-in'], 'expo' => ['Met at exhibition', 'Exhibition / trade fair'],
                       'portal' => ['Portal enquiry', 'Online marketplace'], 'adform' => ['Ad lead form', 'Social media ads'], 'import' => ['Imported list', 'Other']];
const MK_CONSENT_HOW = ['Asked in person', 'Asked on a call', 'Replied on WhatsApp', 'Website form tick', 'Signed form', 'Replied STOP', 'Asked to stop', 'Other'];
const MK_REF_RE = '/\bMK(\d{4,6})\b/i';
const MK_SPEND_COLS = [
    'campaign' => ['campaign', 'campaignname', 'campaigns', 'campaigngroupname', 'adsetname', 'name'],
    'campaign_id' => ['campaignid', 'campaigngroupid'],
    'day' => ['day', 'date', 'reportingstarts', 'startdateinutc', 'startdate', 'week', 'month'],
    'amount' => ['amountspent', 'amountspentinr', 'amountspentininr', 'cost', 'spend', 'spent', 'totalspent', 'totalspend', 'amount'],
    'impressions' => ['impressions', 'impr', 'impression'],
    'clicks' => ['clicks', 'linkclicks', 'clicksall', 'clicksonlink'],
    'leads' => ['leads', 'results', 'conversions', 'leadformsubmissions', 'leadssubmitted'],
    'currency' => ['currency', 'currencycode'],
];
const MK_LEAD_COLS = [
    'name' => ['name', 'fullname', 'contactname', 'customername', 'lead', 'leadname'],
    'phone' => ['mobile', 'phone', 'phonenumber', 'mobilenumber', 'whatsapp', 'whatsappnumber', 'contactnumber'],
    'email' => ['email', 'emailaddress', 'mail', 'emailid'],
    'campaign' => ['campaign', 'campaignname', 'source', 'utmcampaign'],
    'date' => ['date', 'createdtime', 'created', 'createdat', 'leaddate', 'submittedon'],
];
const MK_DEFAULTS = [
    'all_roles' => ['SUPERADMIN', 'CEO'], 'own_roles' => ['MARKETING'], 'view_roles' => ['MANAGER', 'SALES'],
    'window_days' => 90, 'lead_enquiry' => 1, 'currency' => 'INR', 'hook_per_15min' => 600, 'chat_number' => '',
];

/* ---------------- pure ---------------- */

function mk_settings(): array { $s = module_states()['marketing']['settings'] ?? []; return array_replace(MK_DEFAULTS, array_intersect_key($s, MK_DEFAULTS)); }
function mk_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['all_roles', 'own_roles', 'view_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['all_roles']) $e[] = 'Pick at least one role that sees every campaign.';
    $o['window_days'] = max(1, min(365, (int)($in['window_days'] ?? 90)));
    $o['lead_enquiry'] = empty($in['lead_enquiry']) ? 0 : 1;
    $o['currency'] = strtoupper(substr((string)preg_replace('/[^A-Za-z]/', '', (string)($in['currency'] ?? 'INR')), 0, 3)) ?: 'INR';
    $o['hook_per_15min'] = max(10, min(5000, (int)($in['hook_per_15min'] ?? 600)));
    $o['chat_number'] = substr((string)preg_replace('/\D/', '', (string)($in['chat_number'] ?? '')), 0, 15);
    return [$o, $e];
}
/** all = every campaign in branch scope (and settings / hook key); own = make and change their own; view = read. */
function mk_rights_for(array $roles, array $set): array {
    $all = (bool)array_intersect($roles, $set['all_roles']); $own = $all || (bool)array_intersect($roles, $set['own_roles']);
    return ['all' => $all, 'own' => $own, 'view' => $own || (bool)array_intersect($roles, $set['view_roles'])];
}
function mk_ref(int $id): string { return 'MK' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }
function mk_slug(string $s): string { return trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_'); }
function mk_names(string $s): array { return array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $s)), 'strlen'))); }
function mk_txt($v, int $n): string { return mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags(str_replace("\r", '', (string)$v)))), 0, $n); }
/** Amount / count from a report cell: "1,234.50", "₹ 99" → number; "", "-", "--", "n/a" → null. */
function mk_num($v): ?float {
    $s = trim((string)$v);
    if ($s === '' || $s === '-' || $s === '--' || strtolower($s) === 'n/a') return null;
    $s = (string)preg_replace('/[^\d.\-]/', '', str_replace(',', '', $s));
    return is_numeric($s) ? (float)$s : null;
}
/** Report date → Y-m-d: ISO, day first (month first when $mdy; a first part over 12 is a day anyway), "1 Oct 2026", Excel serial. */
function mk_day($v, bool $mdy = false): ?string {
    $s = trim((string)$v);
    if ($s === '') return null;
    if (preg_match('/^\d{5}(\.\d+)?$/', $s)) { $n = (int)$s; return $n > 30000 && $n < 80000 ? gmdate('Y-m-d', ($n - 25569) * 86400) : null; }
    $s = (string)preg_replace('/[ T]+\d{1,2}:\d{2}(:\d{2})?.*$/', '', $s);
    if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $s, $m)) {
        [$a, $b] = [(int)$m[1], (int)$m[2]];
        [$d, $mo] = ($mdy && $a <= 12) || $b > 12 ? [$b, $a] : [$a, $b];
        return checkdate($mo, $d, (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $mo, $d) : null;
    }
    foreach (['!Y-m-d', '!Y/m/d', '!j M Y', '!j-M-Y', '!M j, Y', '!j F Y', '!F j, Y'] as $f) {
        $d = DateTime::createFromFormat($f, $s); $e = DateTime::getLastErrors();
        if ($d && ($e === false || (!$e['warning_count'] && !$e['error_count']))) return $d->format('Y-m-d');
    }
    return null;
}
/** A campaign form → [clean, errors]. $branches = branch ids the login may use. */
function mk_campaign_input(array $in, array $branches): array {
    $e = [];
    $d = ['name' => mk_txt($in['name'] ?? '', 120), 'channel' => (string)($in['channel'] ?? ''), 'objective' => (string)($in['objective'] ?? ''),
          'status' => (string)($in['status'] ?? 'Planned'), 'branch_id' => (int)($in['branch_id'] ?? 0) ?: null,
          'start_date' => trim((string)($in['start_date'] ?? '')) ?: null, 'end_date' => trim((string)($in['end_date'] ?? '')) ?: null,
          'landing_url' => trim((string)($in['landing_url'] ?? '')), 'ext_ids' => implode(', ', array_slice(mk_names(mk_txt($in['ext_ids'] ?? '', 500)), 0, 20)),
          'match_names' => implode(', ', array_slice(mk_names(mk_txt($in['match_names'] ?? '', 500)), 0, 20)), 'notes' => mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 1000)];
    if ($d['name'] === '') $e['name'] = 'Give the campaign a name.';
    if (!isset(MK_CHANNELS[$d['channel']])) { $e['channel'] = 'Pick the channel.'; $d['channel'] = 'other'; }
    if (!in_array($d['status'], MK_STATUSES, true)) $e['status'] = 'Pick the status.';
    if ($d['objective'] !== '' && !in_array($d['objective'], MK_OBJECTIVES, true)) $e['objective'] = 'Pick the objective.';
    if ($d['branch_id'] !== null && !in_array($d['branch_id'], $branches, true)) $e['branch_id'] = 'That branch is outside your scope.';
    foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $k => $l) if ($d[$k] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[$k])) $e[$k] = "$l is not a date.";
    if ($d['start_date'] && $d['end_date'] && $d['end_date'] < $d['start_date']) $e['end_date'] = 'The end date is before the start.';
    $b = mk_num($in['budget'] ?? ''); $d['budget'] = $b;
    if ($b !== null && $b < 0) $e['budget'] = 'The budget cannot be below 0.';
    if ($d['landing_url'] !== '' && (!preg_match('#^https://[^\s/]+\.[^\s]+$#i', $d['landing_url']) || strlen($d['landing_url']) > 500)) $e['landing_url'] = 'The landing page must be an https:// address.';
    [, , $us, $um] = MK_CHANNELS[$d['channel']];
    $d['utm_source'] = mk_slug(mk_txt($in['utm_source'] ?? '', 60)) ?: $us;
    $d['utm_medium'] = mk_slug(mk_txt($in['utm_medium'] ?? '', 60)) ?: $um;
    $d['utm_campaign'] = mk_slug(mk_txt($in['utm_campaign'] ?? '', 80)) ?: mk_slug($d['name']);
    return [$d, $e];
}
/** The tracked link (landing page + UTM + the code) and a chat link with the code in the first message. */
function mk_links(array $c, string $chatNumber = ''): array {
    $url = '';
    if ($c['landing_url'] !== '') {
        $p = array_filter(['utm_source' => $c['utm_source'], 'utm_medium' => $c['utm_medium'], 'utm_campaign' => $c['utm_campaign'], 'utm_id' => (string)$c['ref']], 'strlen');
        $url = $c['landing_url'] . (str_contains($c['landing_url'], '?') ? '&' : '?') . http_build_query($p);
    }
    $chat = $chatNumber !== '' && $c['ref'] ? 'https://wa.me/' . $chatNumber . '?text=' . rawurlencode('Hello, please share details (' . $c['ref'] . ')') : '';
    return ['url' => $url, 'chat' => $chat];
}
/** Match index from campaign rows (id, ref, name, utm_campaign, ext_ids, match_names, status). Archived ones left out. */
function mk_index_of(array $rows): array {
    $o = ['ref' => [], 'ext' => [], 'utm' => [], 'name' => []];
    foreach ($rows as $c) {
        if (($c['status'] ?? '') === 'Archived') continue;
        $id = (int)$c['id'];
        if (!empty($c['ref'])) $o['ref'][strtoupper((string)$c['ref'])] = $id;
        foreach (mk_names((string)$c['ext_ids']) as $x) $o['ext'][$x] ??= $id;
        if ((string)$c['utm_campaign'] !== '') $o['utm'][mb_strtolower((string)$c['utm_campaign'])] ??= $id;
        $o['name'][mb_strtolower(trim((string)$c['name']))] ??= $id;
        foreach (mk_names((string)$c['match_names']) as $x) $o['name'][$x] ??= $id;
    }
    return $o;
}
/** Which campaign? Code (MK0001) in any text first, then platform id, UTM campaign, name / listed name. */
function mk_resolve(array $ix, string ...$seen): ?int {
    foreach ($seen as $s) if (preg_match(MK_REF_RE, $s, $m)) { $r = mk_ref((int)$m[1]); if (isset($ix['ref'][$r])) return $ix['ref'][$r]; }
    foreach ($seen as $s) {
        $k = mb_strtolower(trim($s)); if ($k === '') continue;
        foreach (['ext', 'utm', 'name'] as $w) if (isset($ix[$w][$k])) return $ix[$w][$k];
        $sl = mk_slug($k); if ($sl !== '' && isset($ix['utm'][$sl])) return $ix['utm'][$sl];
    }
    return null;
}
/**
 * A platform's campaign-by-day report → lines per campaign + day (ad-set rows add up). Rows in another currency than
 * $cur are left out and counted. Returns [lines, notRead (row => reason), otherCurrency].
 */
function mk_spend_rows(array $rows, bool $mdy, string $cur): array {
    [$h, $m] = tbl_map($rows, MK_SPEND_COLS, 3);
    if ($h < 0 || !isset($m['day'], $m['amount']) || (!isset($m['campaign']) && !isset($m['campaign_id']))) return [[], ['The file needs columns for campaign, day and amount spent.'], 0];
    $out = []; $bad = []; $other = 0;
    foreach (array_slice($rows, $h + 1, null, true) as $i => $r) {
        $g = fn($f) => isset($m[$f]) ? trim((string)($r[$m[$f]] ?? '')) : '';
        if (implode('', array_map('strval', $r)) === '') continue;
        $name = $g('campaign'); $cid = $g('campaign_id');
        if (preg_match('/^(total|grand total|results from)/i', $name)) continue;
        $day = mk_day($g('day'), $mdy); $amt = mk_num($g('amount'));
        if ($day === null) { $bad[$i + 1] = 'Day not read'; continue; }
        if ($amt === null || $amt < 0) { $bad[$i + 1] = 'Amount not read'; continue; }
        $c = strtoupper($g('currency'));
        if ($c !== '' && $c !== $cur) { $other++; continue; }
        $k = mb_strtolower($cid !== '' ? $cid : $name) . '|' . $day;
        $o = &$out[$k];
        $o ??= ['campaign' => $name, 'campaign_id' => $cid, 'day' => $day, 'amount' => 0.0, 'impressions' => null, 'clicks' => null, 'leads' => null];
        $o['amount'] = round($o['amount'] + $amt, 2);
        foreach (['impressions', 'clicks', 'leads'] as $f) { $v = mk_num($g($f)); if ($v !== null) $o[$f] = (int)(($o[$f] ?? 0) + $v); }
        unset($o);
    }
    return [array_values($out), $bad, $other];
}
/** A lead list → rows [name, phone, email, campaign, date]; rows with neither number nor mail are named in notRead. */
function mk_lead_rows(array $rows, bool $mdy = false): array {
    [$h, $m] = tbl_map($rows, MK_LEAD_COLS, 2);
    if ($h < 0 || (!isset($m['phone']) && !isset($m['email']))) return [[], ['The file needs a mobile or email column.']];
    $out = []; $bad = [];
    foreach (array_slice($rows, $h + 1, null, true) as $i => $r) {
        $g = fn($f) => isset($m[$f]) ? trim((string)($r[$m[$f]] ?? '')) : '';
        if (implode('', array_map('strval', $r)) === '') continue;
        $ph = crm_phone_norm($g('phone')); $em = crm_email_norm($g('email'));
        if ($ph === '' && $em === '') { $bad[$i + 1] = 'No mobile or email'; continue; }
        $out[] = ['name' => mk_txt($g('name'), 160), 'phone' => $ph, 'email' => $em, 'campaign' => mk_txt($g('campaign'), 160), 'date' => mk_day($g('date'), $mdy)];
    }
    return [$out, $bad];
}
/** ROAS, cost per lead, cost per order, click-through rate; null when the base is 0. */
function mk_metrics(float $spend, float $value, int $leads, int $orders, ?int $impr, ?int $clicks): array {
    $r = fn($a, $b, $p = 2) => $b > 0 ? round($a / $b, $p) : null;
    return ['roas' => $r($value, $spend), 'cpl' => $r($spend, $leads), 'cpo' => $r($spend, $orders), 'ctr' => $impr ? $r(100 * (float)$clicks, $impr) : null];
}
/** The campaign of the latest lead (with a campaign) within $days before $punch. $leads: [campaign_id, lead_at]. */
function mk_auto_pick(array $leads, string $punch, int $days): ?int {
    $from = date('Y-m-d H:i:s', strtotime($punch) - $days * 86400); $best = null; $at = '';
    foreach ($leads as $l) {
        if (empty($l['campaign_id']) || $l['lead_at'] > $punch || $l['lead_at'] < $from) continue;
        if ($l['lead_at'] >= $at) { $at = $l['lead_at']; $best = (int)$l['campaign_id']; }
    }
    return $best;
}
/** The enquiry source for a lead: its campaign's channel, else its kind; must be on the register's list, else "Other" / the first. */
function mk_enq_source(string $kind, ?string $channel, array $known): string {
    $want = [$channel !== null ? (MK_CHANNELS[$channel][4] ?? '') : '', MK_LEAD_KINDS[$kind][1] ?? '', 'Other'];
    foreach ($want as $w) if ($w !== '' && in_array($w, $known, true)) return $w;
    return $known[0] ?? 'Other';
}
/** Newest consent row → yes / no / '' (never asked). */
function mk_consent_state(array $rows): string { if (!$rows) return ''; usort($rows, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']); return (string)$rows[0]['answer']; }
function mk_csv_cell($v): string { $v = (string)$v; if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; }

/* ---------------- database ---------------- */

function mk_rights(array $u): array { return mk_rights_for(user_roles((int)$u['id'], !empty($u['is_owner'])), mk_settings()); }
function mk_can(array $u, array $c, bool $write = false): bool {
    $R = mk_rights($u); $in = $c['branch_id'] === null || emp_in_scope($u, ['branch_id' => $c['branch_id']]);
    if ($R['all']) return $in;
    if ($write) return $R['own'] && (int)$c['owner_user'] === (int)$u['id'];
    return $R['view'] && ($in || (int)$c['owner_user'] === (int)$u['id']);
}
function mk_log(PDO $db, string $ent, int $id, string $act, string $detail = '', ?int $by = null): void {
    $db->prepare('INSERT INTO mk_log (entity, entity_id, action, detail, by_user) VALUES (?,?,?,?,?)')->execute([$ent, $id, $act, mb_substr($detail, 0, 300), $by]);
}
function mk_campaign(PDO $db, int $id): ?array { $s = $db->prepare('SELECT c.*, u.name AS owner_name FROM mk_campaign c LEFT JOIN users u ON u.id = c.owner_user WHERE c.id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function mk_campaigns(PDO $db, array $u, bool $archived = false): array {
    $rows = $db->query('SELECT c.*, u.name AS owner_name FROM mk_campaign c LEFT JOIN users u ON u.id = c.owner_user ORDER BY c.id DESC')->fetchAll();
    return array_values(array_filter($rows, fn($c) => mk_can($u, $c) && ($archived || $c['status'] !== 'Archived')));
}
function mk_index(PDO $db): array { return mk_index_of($db->query('SELECT id, ref, name, utm_campaign, ext_ids, match_names, status FROM mk_campaign')->fetchAll()); }
function mk_user_branches(array $u): array {
    $all = array_map('intval', array_column(branches(), 'id'));
    return array_values(array_filter($all, fn($b) => emp_in_scope($u, ['branch_id' => $b])));
}

function mk_campaign_save(PDO $db, array $u, array $in, int $id = 0): int {
    $R = mk_rights($u); if (!$R['own']) throw new RuntimeException('Your role cannot make campaigns.');
    $old = $id ? mk_campaign($db, $id) : null;
    if ($id && (!$old || !mk_can($u, $old, true))) throw new RuntimeException('Campaign not found.');
    [$d, $e] = mk_campaign_input($in, mk_user_branches($u));
    if ($e) throw new RuntimeException(implode(' ', $e));
    $owner = (int)($in['owner_user'] ?? 0) ?: (int)($old['owner_user'] ?? $u['id']);
    if (!$R['all']) $owner = (int)$u['id'];
    $d['owner_user'] = $owner;
    if ($old) {
        $db->prepare('UPDATE mk_campaign SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?')->execute([...array_values($d), $id]);
        $ch = array_keys(array_filter($d, fn($v, $k) => (string)$v !== (string)($old[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($ch) mk_log($db, 'campaign', $id, 'edit', implode(', ', $ch), (int)$u['id']);
    } else {
        $d['created_by'] = (int)$u['id'];
        $db->prepare('INSERT INTO mk_campaign (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
        $id = (int)$db->lastInsertId();
        $db->prepare('UPDATE mk_campaign SET ref = ? WHERE id = ?')->execute([mk_ref($id), $id]);
        mk_log($db, 'campaign', $id, 'add', '', (int)$u['id']);
    }
    audit((int)$u['id'], $old ? 'mk_campaign_edit' : 'mk_campaign_add', '#' . $id);
    return $id;
}

/* ---- spend ---- */

function mk_spend_add(PDO $db, array $u, int $cid, array $in): int {
    $c = mk_campaign($db, $cid); if (!$c || !mk_can($u, $c, true)) throw new RuntimeException('Campaign not found.');
    $day = mk_day((string)($in['day'] ?? '')); $amt = mk_num($in['amount'] ?? '');
    if ($day === null || $day > date('Y-m-d')) throw new RuntimeException('Give the day the money was spent (not a future day).');
    if ($amt === null || $amt <= 0) throw new RuntimeException('Give the amount spent.');
    $int = fn($k) => ($v = mk_num($in[$k] ?? '')) === null ? null : max(0, (int)$v);
    $db->prepare('INSERT INTO mk_spend (campaign_id, day, src, amount, impressions, clicks, leads, currency, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$cid, $day, 'manual', round($amt, 2), $int('impressions'), $int('clicks'), $int('leads'), mk_settings()['currency'], mk_txt($in['note'] ?? '', 300), (int)$u['id']]);
    $id = (int)$db->lastInsertId(); mk_log($db, 'spend', $id, 'add', 'campaign #' . $cid, (int)$u['id']); audit((int)$u['id'], 'mk_spend_add', '#' . $id);
    return $id;
}
function mk_spend_off(PDO $db, array $u, int $id, string $reason): void {
    $s = $db->prepare('SELECT * FROM mk_spend WHERE id = ?'); $s->execute([$id]); $r = $s->fetch();
    if (!$r || !$r['active']) throw new RuntimeException('Spend line not found.');
    $c = mk_campaign($db, (int)$r['campaign_id']); if (!$c || !mk_can($u, $c, true)) throw new RuntimeException('Spend line not found.');
    $reason = mk_txt($reason, 300); if ($reason === '') throw new RuntimeException('Say why the line is switched off.');
    $db->prepare('UPDATE mk_spend SET active = 0, uk = NULL, off_reason = ?, off_by = ?, off_at = NOW() WHERE id = ?')->execute([$reason, (int)$u['id'], $id]);
    mk_log($db, 'spend', $id, 'off', '', (int)$u['id']); audit((int)$u['id'], 'mk_spend_off', '#' . $id);
}
/** Plan (save = false writes nothing) or save a spend report. Returns [new, same, replaced, unmatched (names), notRead, otherCurrency]. */
function mk_spend_import(PDO $db, array $u, array $rows, bool $mdy, bool $save): array {
    $set = mk_settings(); [$lines, $bad, $other] = mk_spend_rows($rows, $mdy, $set['currency']);
    $ix = mk_index($db); $n = ['new' => 0, 'same' => 0, 'replaced' => 0, 'unmatched' => [], 'notRead' => $bad, 'other' => $other, 'lines' => []];
    $cache = [];
    if ($save) $db->beginTransaction();
    try {
        foreach ($lines as $l) {
            $cid = mk_resolve($ix, $l['campaign'], $l['campaign_id']);
            if ($cid === null) { $n['unmatched'][$l['campaign'] ?: $l['campaign_id']] = 1; continue; }
            $cache[$cid] ??= ($c = mk_campaign($db, $cid)) && mk_can($u, $c, true);
            if (!$cache[$cid]) { $n['unmatched'][$l['campaign'] ?: $l['campaign_id']] = 1; continue; }
            $uk = $cid . '|' . $l['day'] . '|import';
            $s = $db->prepare('SELECT * FROM mk_spend WHERE uk = ?'); $s->execute([$uk]); $old = $s->fetch();
            $same = $old && (float)$old['amount'] === (float)$l['amount'] && (int)$old['impressions'] === (int)$l['impressions'] && (int)$old['clicks'] === (int)$l['clicks'] && (int)$old['leads'] === (int)$l['leads'];
            $k = $same ? 'same' : ($old ? 'replaced' : 'new'); $n[$k]++;
            $n['lines'][] = $l + ['campaign_id_here' => $cid, 'state' => $k];
            if (!$save || $same) continue;
            if ($old) $db->prepare('UPDATE mk_spend SET active = 0, uk = NULL, off_reason = ?, off_by = ?, off_at = NOW() WHERE id = ?')->execute(['Replaced by a newer report', (int)$u['id'], $old['id']]);
            $db->prepare('INSERT INTO mk_spend (campaign_id, day, src, amount, impressions, clicks, leads, currency, uk, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
               ->execute([$cid, $l['day'], 'import', $l['amount'], $l['impressions'], $l['clicks'], $l['leads'], $set['currency'], $uk, (int)$u['id']]);
        }
        if ($save) { $db->commit(); audit((int)$u['id'], 'mk_spend_import', 'new ' . $n['new'] . ', replaced ' . $n['replaced']); }
    } catch (Throwable $x) { if ($save) $db->rollBack(); throw $x; }
    $n['unmatched'] = array_keys($n['unmatched']);
    return $n;
}

/* ---- leads ---- */

/**
 * Record a lead. $in: name, phone, email, kind, campaign / seen texts (campaign name, utm_campaign, utm_id, ref),
 * campaign_id (staff pick), ext_ref, lead_at. Returns [id, isNew].
 */
function mk_lead_add(PDO $db, array $in, string $via, ?int $by): array {
    $ext = mk_txt($in['ext_ref'] ?? '', 80) ?: null;
    if ($ext !== null) { $s = $db->prepare('SELECT id FROM mk_lead WHERE ext_ref = ?'); $s->execute([$ext]); if ($id = (int)$s->fetchColumn()) return [$id, false]; }
    $digits = (int)enq_settings()['phone_digits'];
    $ph = crm_phone_norm((string)($in['phone'] ?? '')); $em = crm_email_norm((string)($in['email'] ?? ''));
    if ($ph === '' && $em === '') throw new RuntimeException('A lead needs a mobile number or an email.');
    $kind = isset(MK_LEAD_KINDS[$in['kind'] ?? '']) ? (string)$in['kind'] : 'form';
    $seen = array_map(fn($x) => mk_txt($x, 160), array_filter([(string)($in['campaign'] ?? ''), (string)($in['utm_id'] ?? ''), (string)($in['utm_campaign'] ?? ''), (string)($in['ref'] ?? '')], 'strlen'));
    $cid = (int)($in['campaign_id'] ?? 0) ?: mk_resolve(mk_index($db), ...$seen);
    $at = mk_txt($in['lead_at'] ?? '', 19); $at = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $at) && $at <= date('Y-m-d H:i:s') ? $at : date('Y-m-d H:i:s');
    $cust = enq_customer_match($db, $ph, $em, $digits);
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO mk_lead (campaign_id, kind, name, phone, phone_key, email, seen, customer_id, via, ext_ref, lead_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$cid, $kind, mk_txt($in['name'] ?? '', 160), $ph, $ph !== '' ? crm_phone_key($ph, $digits) : '', $em, $seen[0] ?? '', $cust, $via, $ext, $at, $by]);
        $id = (int)$db->lastInsertId();
        if (mk_settings()['lead_enquiry'] && module_on('enquiries')) mk_lead_enquiry($db, $id);
        $db->commit();
    } catch (Throwable $x) { $db->rollBack(); throw $x; }
    audit($by ?? 0, 'mk_lead_add', '#' . $id . ' via ' . $via);
    return [$id, true];
}
/** A lead → a new enquiry, or a note on the open one with the same number / mail. Inside the lead's transaction. */
function mk_lead_enquiry(PDO $db, int $leadId): void {
    $s = $db->prepare('SELECT l.*, c.channel, c.ref AS cref, c.name AS cname, c.owner_user AS cowner, c.branch_id AS cbranch FROM mk_lead l LEFT JOIN mk_campaign c ON c.id = l.campaign_id WHERE l.id = ?');
    $s->execute([$leadId]); $l = $s->fetch(); if (!$l) return;
    $what = MK_LEAD_KINDS[$l['kind']][0] . ($l['cref'] ? ' · campaign ' . $l['cref'] . ' (' . $l['cname'] . ')' : '');
    if ($m = enq_open_match($db, $l['phone_key'], $l['email'])) {
        enq_note($db, (int)$m['id'], 'New lead again — ' . $what, null, true);
        $db->prepare("UPDATE mk_lead SET enquiry_id = ?, enquiry_how = 'noted' WHERE id = ?")->execute([$m['id'], $leadId]);
        return;
    }
    $set = enq_settings(); $known = enq_flat(enq_known($set)['sources']);
    $cname = '';
    if ($l['customer_id']) { $q = $db->prepare('SELECT name FROM crm_customer WHERE id = ?'); $q->execute([$l['customer_id']]); $cname = (string)$q->fetchColumn(); }
    $today = date('Y-m-d'); $fy = so_fy_enq($today);
    $db->prepare('INSERT INTO enq_seq (fy, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$fy]);
    $n = $db->prepare('SELECT n FROM enq_seq WHERE fy = ?'); $n->execute([$fy]);
    $d = ['ref' => enq_ref($set['prefix'], $fy, (int)$n->fetchColumn()), 'enquiry_date' => $today, 'customer_name' => $l['name'] !== '' ? $l['name'] : ($cname ?: 'Lead ' . ($l['phone'] ?: $l['email'])),
          'company' => $cname, 'phone' => $l['phone'], 'phone_key' => $l['phone_key'], 'email' => $l['email'], 'customer_id' => $l['customer_id'],
          'source' => mk_enq_source($l['kind'], $l['channel'], $known), 'status' => 'new', 'follow_up' => date('Y-m-d', strtotime('+' . max(0, (int)$set['follow_new']) . ' day')),
          'tax_pct' => (float)$set['tax_pct'], 'branch_id' => $l['cbranch'], 'created_by' => (int)($l['created_by'] ?? 0) ?: (int)($l['cowner'] ?? 0)];
    $db->prepare('INSERT INTO enq_enquiry (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
    $eid = (int)$db->lastInsertId();
    enq_note($db, $eid, 'From a lead — ' . $what, null, true);
    $db->prepare("UPDATE mk_lead SET enquiry_id = ?, enquiry_how = 'new' WHERE id = ?")->execute([$eid, $leadId]);
}
function mk_leads(PDO $db, array $u, array $f): array {
    $w = ['1=1']; $p = [];
    if (!empty($f['campaign'])) { $w[] = 'l.campaign_id = ?'; $p[] = (int)$f['campaign']; }
    if (($f['campaign'] ?? '') === '0') $w[] = 'l.campaign_id IS NULL';
    if (!empty($f['from'])) { $w[] = 'l.lead_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to'])) { $w[] = 'l.lead_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
    $s = $db->prepare('SELECT l.*, c.ref AS cref, c.name AS cname, c.branch_id, c.owner_user, e.ref AS eref FROM mk_lead l LEFT JOIN mk_campaign c ON c.id = l.campaign_id LEFT JOIN enq_enquiry e ON e.id = l.enquiry_id WHERE ' . implode(' AND ', $w) . ' ORDER BY l.id DESC LIMIT 500');
    $s->execute($p);
    $R = mk_rights($u);
    return array_values(array_filter($s->fetchAll(), fn($l) => $R['all'] || ($l['campaign_id'] ? mk_can($u, $l) : $R['view'])));
}
/** Link a lead that came in without a campaign; its name is remembered on the campaign for next time. */
function mk_lead_link(PDO $db, array $u, int $leadId, int $cid): void {
    $c = mk_campaign($db, $cid); if (!$c || !mk_can($u, $c, true)) throw new RuntimeException('Campaign not found.');
    $s = $db->prepare('SELECT * FROM mk_lead WHERE id = ?'); $s->execute([$leadId]); $l = $s->fetch();
    if (!$l || $l['campaign_id']) throw new RuntimeException('That lead already has a campaign.');
    $db->prepare('UPDATE mk_lead SET campaign_id = ? WHERE id = ?')->execute([$cid, $leadId]);
    $seen = mb_strtolower(trim((string)$l['seen']));
    if ($seen !== '' && !preg_match(MK_REF_RE, $seen) && !in_array($seen, mk_names((string)$c['match_names']), true) && count(mk_names((string)$c['match_names'])) < 20)
        $db->prepare('UPDATE mk_campaign SET match_names = ? WHERE id = ?')->execute([trim($c['match_names'] . ', ' . $seen, ', '), $cid]);
    mk_log($db, 'lead', $leadId, 'link', 'campaign #' . $cid, (int)$u['id']);
}

/* ---- hook key ---- */

function mk_hook_key_new(PDO $db, array $u): string {
    if (!mk_rights($u)['all']) throw new RuntimeException('Only a full-rights login makes the lead hook key.');
    $key = 'wnlead_' . bin2hex(random_bytes(20));
    $db->exec('UPDATE mk_hook_key SET active = 0 WHERE active = 1');
    $db->prepare('INSERT INTO mk_hook_key (key_hash, hint, made_by) VALUES (?,?,?)')->execute([hash('sha256', $key), substr($key, -4), (int)$u['id']]);
    audit((int)$u['id'], 'mk_hook_key', 'new');
    return $key;
}
function mk_hook_key_info(PDO $db): ?array { return $db->query('SELECT k.hint, k.made_at, u.name FROM mk_hook_key k LEFT JOIN users u ON u.id = k.made_by WHERE k.active = 1 ORDER BY k.id DESC LIMIT 1')->fetch() ?: null; }
function mk_hook_ok(PDO $db, string $key): bool {
    if (!preg_match('/^wnlead_[0-9a-f]{40}$/', $key)) return false;
    $s = $db->prepare('SELECT 1 FROM mk_hook_key WHERE key_hash = ? AND active = 1'); $s->execute([hash('sha256', $key)]);
    return (bool)$s->fetchColumn();
}

/* ---- consent ---- */

function mk_consent_get(PDO $db, string $phone): array { $s = $db->prepare('SELECT c.*, u.name AS by_name FROM mk_consent c LEFT JOIN users u ON u.id = c.by_user WHERE c.phone = ? ORDER BY c.id DESC'); $s->execute([$phone]); return $s->fetchAll(); }
function mk_consent_set(PDO $db, array $u, string $phone, string $answer, string $how, string $note): void {
    if (!mk_rights($u)['view']) throw new RuntimeException('Your role cannot record consent.');
    $ph = crm_phone_norm($phone); if ($ph === '') throw new RuntimeException('Check the mobile number.');
    if (!in_array($answer, ['yes', 'no'], true)) throw new RuntimeException('Pick yes or no.');
    if (!in_array($how, MK_CONSENT_HOW, true)) throw new RuntimeException('Pick how the customer told you.');
    $db->prepare('INSERT INTO mk_consent (phone, answer, how, note, by_user) VALUES (?,?,?,?,?)')->execute([$ph, $answer, $how, mk_txt($note, 300), (int)$u['id']]);
    audit((int)$u['id'], 'mk_consent', $answer);
}
/** Numbers whose newest answer is yes (for a marketing list), at most $limit. */
function mk_consent_yes(PDO $db, int $limit = 5000): array {
    return $db->query("SELECT c.phone, c.at FROM mk_consent c JOIN (SELECT phone, MAX(id) AS id FROM mk_consent GROUP BY phone) n ON n.id = c.id WHERE c.answer = 'yes' ORDER BY c.phone LIMIT " . max(1, $limit))->fetchAll();
}

/* ---- orders → campaigns ---- */

function mk_attr_current(PDO $db, int $orderId): ?array { $s = $db->prepare('SELECT * FROM mk_attr WHERE order_id = ? ORDER BY id DESC LIMIT 1'); $s->execute([$orderId]); return $s->fetch() ?: null; }
function mk_attr_set(PDO $db, array $u, int $orderId, int $cid): void {
    if (!mk_rights($u)['own']) throw new RuntimeException('Your role cannot link orders.');
    $o = $db->prepare('SELECT id FROM crm_order WHERE id = ?'); $o->execute([$orderId]); if (!$o->fetchColumn()) throw new RuntimeException('Order not found.');
    if ($cid) { $c = mk_campaign($db, $cid); if (!$c || !mk_can($u, $c)) throw new RuntimeException('Campaign not found.'); }
    $db->prepare("INSERT INTO mk_attr (order_id, campaign_id, how, by_user) VALUES (?,?,'hand',?)")->execute([$orderId, $cid ?: null, (int)$u['id']]);
    audit((int)$u['id'], 'mk_attr', 'order #' . $orderId);
}
/** Orders punched since $since with no link yet get one from the customer's latest lead (or "no campaign"), once. */
function mk_attr_sweep(PDO $db, string $since): int {
    $days = (int)mk_settings()['window_days']; $n = 0;
    $s = $db->prepare("SELECT o.id, o.customer_id, COALESCE(o.submitted_at, o.received_at) AS at FROM crm_order o WHERE o.status NOT IN ('Draft','Cancelled') AND COALESCE(o.submitted_at, o.received_at) >= ?
                       AND NOT EXISTS (SELECT 1 FROM mk_attr a WHERE a.order_id = o.id) LIMIT 2000");
    $s->execute([$since]);
    $q = $db->prepare('SELECT campaign_id, lead_at FROM mk_lead WHERE campaign_id IS NOT NULL AND (customer_id = ? OR phone_key IN (SELECT RIGHT(number, ?) FROM crm_phone WHERE customer_id = ?) OR email IN (SELECT email FROM crm_email WHERE customer_id = ?))');
    $digits = (int)enq_settings()['phone_digits'];
    foreach ($s->fetchAll() as $o) {
        $q->execute([$o['customer_id'], $digits, $o['customer_id'], $o['customer_id']]);
        $cid = mk_auto_pick($q->fetchAll(), (string)$o['at'], $days);
        $db->prepare("INSERT INTO mk_attr (order_id, campaign_id, how) VALUES (?,?,'auto')")->execute([$o['id'], $cid]);
        $n++;
    }
    return $n;
}
/** Orders in a period with their current campaign. */
function mk_orders(PDO $db, string $from, string $to): array {
    $s = $db->prepare("SELECT o.id, o.order_no, o.order_value, o.currency, o.status, COALESCE(o.submitted_at, o.received_at) AS at, cu.name AS customer,
                       a.campaign_id, a.how, c.ref AS cref, c.name AS cname FROM crm_order o JOIN crm_customer cu ON cu.id = o.customer_id
                       LEFT JOIN mk_attr a ON a.id = (SELECT MAX(id) FROM mk_attr WHERE order_id = o.id) LEFT JOIN mk_campaign c ON c.id = a.campaign_id
                       WHERE o.status NOT IN ('Draft','Cancelled') AND COALESCE(o.submitted_at, o.received_at) BETWEEN ? AND ? ORDER BY at DESC LIMIT 1000");
    $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    return $s->fetchAll();
}

/* ---- report ---- */

/** Per campaign in the period: spend, impressions, clicks, leads, orders, value before tax, metrics; plus totals. */
function mk_report(PDO $db, array $u, string $from, string $to): array {
    $cur = mk_settings()['currency'];
    $cs = []; foreach (mk_campaigns($db, $u, true) as $c) $cs[(int)$c['id']] = $c + ['spend' => 0.0, 'impr' => null, 'clicks' => null, 'leads' => 0, 'orders' => 0, 'value' => 0.0, 'other_cur' => 0];
    $s = $db->prepare('SELECT campaign_id, SUM(amount) a, SUM(impressions) i, SUM(clicks) k FROM mk_spend WHERE active = 1 AND day BETWEEN ? AND ? GROUP BY campaign_id'); $s->execute([$from, $to]);
    foreach ($s->fetchAll() as $r) if (isset($cs[$r['campaign_id']])) { $x = &$cs[$r['campaign_id']]; $x['spend'] = (float)$r['a']; $x['impr'] = $r['i'] === null ? null : (int)$r['i']; $x['clicks'] = $r['k'] === null ? null : (int)$r['k']; unset($x); }
    $s = $db->prepare('SELECT campaign_id, COUNT(*) n FROM mk_lead WHERE campaign_id IS NOT NULL AND lead_at BETWEEN ? AND ? GROUP BY campaign_id'); $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    foreach ($s->fetchAll() as $r) if (isset($cs[$r['campaign_id']])) $cs[$r['campaign_id']]['leads'] = (int)$r['n'];
    $all = 0.0; $explained = 0.0;
    foreach (mk_orders($db, $from, $to) as $o) {
        if ($o['currency'] === $cur) $all += (float)$o['order_value'];
        if (!$o['campaign_id'] || !isset($cs[$o['campaign_id']])) continue;
        $x = &$cs[$o['campaign_id']];
        if ($o['currency'] !== $cur) { $x['other_cur']++; unset($x); continue; }
        $x['orders']++; $x['value'] += (float)$o['order_value']; $explained += (float)$o['order_value']; unset($x);
    }
    $t = ['spend' => 0.0, 'leads' => 0, 'orders' => 0, 'value' => 0.0, 'impr' => 0, 'clicks' => 0];
    foreach ($cs as $id => $c) {
        $cs[$id]['m'] = mk_metrics($c['spend'], $c['value'], $c['leads'], $c['orders'], $c['impr'], $c['clicks']);
        foreach (['spend', 'leads', 'orders', 'value'] as $k) $t[$k] += $c[$k];
        $t['impr'] += (int)$c['impr']; $t['clicks'] += (int)$c['clicks'];
    }
    $cs = array_filter($cs, fn($c) => $c['spend'] > 0 || $c['leads'] || $c['orders'] || $c['status'] !== 'Archived');
    return ['rows' => array_values($cs), 'total' => $t + ['m' => mk_metrics($t['spend'], $t['value'], $t['leads'], $t['orders'], $t['impr'] ?: null, $t['clicks'])],
            'share' => $all > 0 ? round(100 * $explained / $all, 1) : null, 'currency' => $cur];
}

/* ---- content plan ---- */

function mk_post_save(PDO $db, array $u, array $in, int $id = 0): int {
    if (!mk_rights($u)['own']) throw new RuntimeException('Your role cannot plan posts.');
    $old = null;
    if ($id) { $s = $db->prepare('SELECT * FROM mk_post WHERE id = ?'); $s->execute([$id]); $old = $s->fetch(); if (!$old || (!mk_rights($u)['all'] && (int)$old['owner_user'] !== (int)$u['id'])) throw new RuntimeException('Post not found.'); }
    $d = ['campaign_id' => (int)($in['campaign_id'] ?? 0) ?: null, 'channel' => (string)($in['channel'] ?? ''), 'title' => mk_txt($in['title'] ?? '', 200),
          'due_date' => trim((string)($in['due_date'] ?? '')) ?: null, 'status' => (string)($in['status'] ?? 'Planned'), 'link' => trim((string)($in['link'] ?? '')),
          'notes' => mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 1000)];
    if ($d['title'] === '') throw new RuntimeException('Give the post a title.');
    if (!isset(MK_CHANNELS[$d['channel']])) throw new RuntimeException('Pick the channel.');
    if (!in_array($d['status'], MK_POST_STATUSES, true)) throw new RuntimeException('Pick the status.');
    if ($d['due_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['due_date'])) throw new RuntimeException('The date is not a date.');
    if ($d['link'] !== '' && !preg_match('#^https://\S+$#i', $d['link'])) throw new RuntimeException('The link must be an https:// address.');
    if ($d['campaign_id'] && (!($c = mk_campaign($db, $d['campaign_id'])) || !mk_can($u, $c))) throw new RuntimeException('Campaign not found.');
    if ($old) $db->prepare('UPDATE mk_post SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?')->execute([...array_values($d), $id]);
    else { $d += ['owner_user' => (int)$u['id'], 'created_by' => (int)$u['id']]; $db->prepare('INSERT INTO mk_post (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d)); $id = (int)$db->lastInsertId(); }
    mk_log($db, 'post', $id, $old ? 'edit' : 'add', '', (int)$u['id']);
    return $id;
}
function mk_posts(PDO $db, string $from, string $to): array {
    $s = $db->prepare('SELECT p.*, c.ref AS cref, u.name AS owner_name FROM mk_post p LEFT JOIN mk_campaign c ON c.id = p.campaign_id LEFT JOIN users u ON u.id = p.owner_user
                       WHERE p.due_date IS NULL OR p.due_date BETWEEN ? AND ? ORDER BY p.due_date IS NULL, p.due_date, p.id');
    $s->execute([$from, $to]); return $s->fetchAll();
}
