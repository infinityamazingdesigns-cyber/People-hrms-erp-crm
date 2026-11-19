<?php
// Recruitment intelligence — the hiring desk's business contacts (institutes / placement officers, recruitment
// consultants, labour contractors), companies (our own, clients, outside firms watched for poaching), recruitment
// projects and vacancies with a job code (JOB-<id>, to put in posts); an outside firm's openings are "observed only"
// and give hiring trends. Contact lists come in from CSV / .xlsx (columns by title; Check writes nothing; an existing
// contact only gets its blanks filled). Business data only — never a candidate's details. Nothing is deleted.
declare(strict_types=1);
require_once __DIR__ . '/table_read.php';

const RI_KINDS = ['institute' => 'Institute / placement officer', 'consultant' => 'Recruitment consultant', 'contractor' => 'Labour contractor'];
const RI_FIELDS = ['org' => 200, 'person' => 120, 'phone' => 20, 'phone2' => 80, 'email' => 160, 'website' => 300, 'city' => 80, 'region' => 60,
                   'type' => 80, 'focus' => 300, 'status' => 160, 'terms' => 200, 'remarks' => 1000];
const RI_FIELD_LABELS = ['org' => 'Organisation', 'person' => 'Contact person', 'phone' => 'Phone', 'phone2' => 'Other numbers', 'email' => 'Email', 'website' => 'Website',
                         'city' => 'City', 'region' => 'State / region', 'type' => 'Type', 'focus' => 'Courses / expertise', 'status' => 'Status', 'terms' => 'Terms / fees', 'remarks' => 'Remarks'];
/** Accepted column titles (compared without spaces / punctuation). */
const RI_COLS = [
    'org' => ['organisation', 'organization', 'company', 'companyname', 'institute', 'nameoftheinstitute', 'institutename', 'college', 'agency', 'agencyname', 'firm'],
    'person' => ['name', 'contactperson', 'contact', 'person', 'tpo', 'placementofficer', 'contactname'],
    'phone' => ['phone', 'mobile', 'contactnumber', 'contactnumbers', 'contactno', 'phonenumber', 'mobileno', 'whatsapp'],
    'email' => ['email', 'emailid', 'mail', 'emailaddress'],
    'website' => ['website', 'websitelink', 'web', 'url'],
    'city' => ['city', 'location', 'town', 'regionofworking', 'district'],
    'region' => ['state', 'region', 'province'],
    'type' => ['type', 'typeofinstitute', 'category'],
    'focus' => ['courses', 'targetstudents', 'expertise', 'specialisation', 'specialization', 'domain', 'trades'],
    'status' => ['status', 'followupstatus'],
    'terms' => ['terms', 'fees', 'price', 'commercials', 'charges'],
    'remarks' => ['remarks', 'notes', 'comment', 'comments', 'afterfollowupstatus', 'secondfollowup', 'priority'],
];
const RI_COMPANY_TYPES = ['own' => 'Our organisation', 'client' => 'Client', 'outside' => 'Outside company'];
const RI_PROJECT_STATUS = ['planned' => 'Planned', 'active' => 'Active', 'hold' => 'On hold', 'closed' => 'Closed'];
const RI_VAC_STATUS = ['open' => 'Open', 'filled' => 'Filled', 'cancelled' => 'Cancelled', 'observed' => 'Observed only'];
const RI_COMPANY_SUFFIX = ['private', 'pvt', 'limited', 'ltd', 'llp', 'llc', 'inc', 'co', 'company', 'corporation', 'corp', 'gmbh', 'plc', 'the'];
const RI_JOB_RE = '/\bJOB[\s\-:#]*0*(\d{1,6})\b/i';
const RI_DEFAULTS = ['manage_roles' => ['SUPERADMIN', 'HRMANAGER', 'HRRECRUIT'], 'trend_days' => 90];

/* ---------------- pure ---------------- */

/** Every phone number in a cell (7–15 digits each, + kept), first one first. */
function ri_phones(string $s): array {
    $out = [];
    foreach (preg_split('#[,;/|\n]+|\s{2,}#', $s) ?: [] as $p) { $d = preg_replace('/[^\d+]/', '', $p) ?? ''; $digits = preg_replace('/\D/', '', $d) ?? '';
        if (strlen($digits) >= 7 && strlen($digits) <= 15) $out[] = (str_starts_with($d, '+') ? '+' : '') . $digits; }
    return array_values(array_unique($out));
}
function ri_email(string $s): string { foreach (preg_split('/[\s,;]+/', strtolower(trim($s))) ?: [] as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) return $e; return ''; }
function ri_norm(string $s): string { return (string)preg_replace('/[^a-z0-9]/', '', strtolower($s)); }
/** A contact's match key — kind + organisation + (phone, else email, else person). */
function ri_contact_key(array $c): string { $who = $c['phone'] !== '' ? ri_norm($c['phone']) : ($c['email'] !== '' ? $c['email'] : ri_norm($c['person'])); return sha1($c['kind'] . '|' . ri_norm($c['org']) . '|' . $who); }
/** Clean a contact (form or file row). Returns [contact, errors]. */
function ri_contact_clean(array $in, bool $strict = true): array {
    $e = []; $kind = (string)($in['kind'] ?? '');
    if (!isset(RI_KINDS[$kind])) $e[] = 'Pick what kind of contact this is.';
    $c = ['kind' => $kind];
    foreach (RI_FIELDS as $f => $max) $c[$f] = mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($in[$f] ?? '')))), 0, $max);
    $rawPhone = mb_substr(trim(strip_tags((string)($in['phone'] ?? ''))), 0, 200); $ph = ri_phones($rawPhone); $c['phone'] = $ph[0] ?? ''; if (count($ph) > 1) $c['phone2'] = mb_substr(trim($c['phone2'] . ($c['phone2'] !== '' ? ', ' : '') . implode(', ', array_slice($ph, 1)), ', '), 0, 80);
    $rawMail = $c['email']; $c['email'] = ri_email($rawMail);
    if ($rawPhone !== '' && !$ph) { if ($strict) $e[] = 'The phone number should have 7 to 15 digits.'; else $c['remarks'] = mb_substr(trim($c['remarks'] . ' · Number as written: ' . $rawPhone, ' ·'), 0, 1000); }
    if ($rawMail !== '' && $c['email'] === '') { if ($strict) $e[] = 'The email address does not look right.'; else $c['remarks'] = mb_substr(trim($c['remarks'] . ' · Email as written: ' . $rawMail, ' ·'), 0, 1000); }
    if ($c['website'] !== '' && !preg_match('#^https?://#i', $c['website'])) $c['website'] = mb_substr('https://' . $c['website'], 0, 300);
    if ($c['org'] === '' && $c['person'] === '') $e[] = "Give an organisation or a person's name.";
    if (!$strict && $c['phone'] === '' && $c['email'] === '' && $c['website'] === '' && $kind !== 'institute') $e[] = 'no number, email or website';
    $c['match_key'] = ri_contact_key($c);
    return [$c, $e];
}
/** The contact rows of a read table: [contacts with their file row, skipped [row => why]]. Rows repeating in the file are joined. */
function ri_rows_to_contacts(array $rows, string $kind, string $src): array {
    [$hi, $map] = tbl_map($rows, RI_COLS, 2); $out = []; $skip = [];
    if ($hi < 0) return [[], ['0' => 'No title row found (need at least two of: organisation, name, phone, email, city …).']];
    foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
        if (!array_filter($r, fn($x) => trim((string)$x) !== '')) continue;
        $in = ['kind' => $kind]; foreach ($map as $f => $j) $in[$f] = (string)($r[$j] ?? '');
        [$c, $e] = ri_contact_clean($in, false);
        if ($e) { $skip[(string)($i + 1)] = implode('; ', $e); continue; }
        $c['src'] = mb_substr($src . ' · row ' . ($i + 1), 0, 120);
        if (isset($out[$c['match_key']])) { foreach (RI_FIELDS as $f => $max) if ($out[$c['match_key']][$f] === '' && $c[$f] !== '') $out[$c['match_key']][$f] = $c[$f]; continue; }
        $out[$c['match_key']] = $c;
    }
    return [array_values($out), $skip];
}
/** Blanks an existing contact would get from a new one: field => value. */
function ri_fill(array $old, array $new): array { $o = []; foreach (RI_FIELDS as $f => $_) if (trim((string)$old[$f]) === '' && $new[$f] !== '') $o[$f] = $new[$f]; return $o; }
function ri_company_words(string $name): string { return implode(' ', array_filter(preg_split('/[^a-z0-9]+/', strtolower($name)) ?: [], fn($x) => $x !== '' && !in_array($x, RI_COMPANY_SUFFIX, true))); }
function ri_company_key(string $name): string { return sha1(ri_company_words($name)); }
function ri_day(string $s, string $what): string { $s = trim($s); if ($s === '') return ''; $d = DateTime::createFromFormat('!Y-m-d', $s); if (!$d || $d->format('Y-m-d') !== $s) throw new InvalidArgumentException("The $what date does not look right."); return $s; }
function ri_years(string $s): ?float { $t = trim($s); if ($t === '') return null; if (!is_numeric($t) || (float)$t < 0 || (float)$t > 60) throw new InvalidArgumentException('Experience in years, 0 to 60.'); return round((float)$t, 1); }
function ri_amount(string $s): ?float { $t = str_replace([',', ' '], '', trim($s)); if ($t === '') return null; if (!is_numeric($t) || (float)$t < 0) throw new InvalidArgumentException('Pay: a number.'); return round((float)$t, 2); }
function ri_job_code(int $id): string { return 'JOB-' . $id; }
function ri_job_find(string $text): int { return preg_match(RI_JOB_RE, $text, $m) ? (int)$m[1] : 0; }
/** A vacancy form for a company of $type. Outside companies' openings are only watched. Returns clean values or throws. */
function ri_vacancy_input(array $in, string $type): array {
    $clip = fn(string $f, int $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($in[$f] ?? '')))), 0, $max);
    $v = ['company_id' => (int)($in['company_id'] ?? 0), 'project_id' => (int)($in['project_id'] ?? 0) ?: null, 'req_id' => (int)($in['req_id'] ?? 0) ?: null,
          'title' => $clip('title', 160), 'dept' => $clip('dept', 80), 'location' => $clip('location', 80), 'openings' => max(1, min(999, (int)($in['openings'] ?? 1))),
          'exp_min' => ri_years((string)($in['exp_min'] ?? '')), 'exp_max' => ri_years((string)($in['exp_max'] ?? '')),
          'pay_min' => ri_amount((string)($in['pay_min'] ?? '')), 'pay_max' => ri_amount((string)($in['pay_max'] ?? '')),
          'posted_on' => ri_day((string)($in['posted_on'] ?? ''), 'posted'), 'closes_on' => ri_day((string)($in['closes_on'] ?? ''), 'closing'),
          'status' => (string)($in['status'] ?? ''), 'src' => $clip('src', 120), 'notes' => mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 1000)];
    if ($v['company_id'] <= 0) throw new InvalidArgumentException('Pick the hiring company.');
    if ($v['title'] === '') throw new InvalidArgumentException('Give the role title.');
    if ($type === 'outside') {
        if ($v['status'] === '' || $v['status'] === 'open') $v['status'] = 'observed';
        if (!in_array($v['status'], ['observed', 'cancelled'], true)) throw new InvalidArgumentException("An outside company's opening is only watched — \"Observed only\".");
        $v['project_id'] = null; $v['req_id'] = null;
    } else {
        if ($v['status'] === '') $v['status'] = 'open';
        if (!in_array($v['status'], ['open', 'filled', 'cancelled'], true)) throw new InvalidArgumentException('Pick open, filled or cancelled.');
        if ($type !== 'own') $v['req_id'] = null;   // only our own positions have a requisition
    }
    if ($v['exp_min'] !== null && $v['exp_max'] !== null && $v['exp_max'] < $v['exp_min']) throw new InvalidArgumentException('Maximum experience is below the minimum.');
    if ($v['pay_min'] !== null && $v['pay_max'] !== null && $v['pay_max'] < $v['pay_min']) throw new InvalidArgumentException('Maximum pay is below the minimum.');
    if ($v['posted_on'] !== '' && $v['closes_on'] !== '' && $v['closes_on'] < $v['posted_on']) throw new InvalidArgumentException('The closing date is before the posted date.');
    foreach (['posted_on', 'closes_on'] as $k) if ($v[$k] === '') $v[$k] = null;
    return $v;
}
/** Outside openings by company within the window: [company => [openings, roles, last seen]], busiest first. */
function ri_trend_calc(array $rows): array {
    $o = [];
    foreach ($rows as $r) { $k = (string)$r['company']; $o[$k] ??= ['openings' => 0, 'roles' => [], 'last' => '', 'watch' => (int)($r['watch'] ?? 0)];
        $o[$k]['openings'] += (int)$r['openings']; $o[$k]['roles'][(string)$r['title']] = true; $d = (string)($r['posted_on'] ?: substr((string)$r['created_at'], 0, 10)); if ($d > $o[$k]['last']) $o[$k]['last'] = $d; }
    foreach ($o as &$x) $x['roles'] = array_keys($x['roles']); unset($x);
    uasort($o, fn($a, $b) => [$b['watch'], $b['openings']] <=> [$a['watch'], $a['openings']]);
    return $o;
}

/* ---------------- settings, rights, log ---------------- */

function ri_settings(): array { $s = module_states()['recintel']['settings'] ?? []; return array_replace(RI_DEFAULTS, array_intersect_key($s, RI_DEFAULTS)); }
function ri_settings_check(array $in, array $roles): array {
    $o = ['manage_roles' => array_values(array_intersect(array_keys($roles), (array)($in['manage_roles'] ?? []))), 'trend_days' => max(7, min(730, (int)($in['trend_days'] ?? 90)))];
    return [$o, $o['manage_roles'] ? [] : ['Pick at least one role that keeps these records.']];
}
function ri_can(array $u): bool { return has_role($u, ...ri_settings()['manage_roles']); }
function ri_need(array $u): void { if (!ri_can($u)) throw new RuntimeException('Your role reads only.'); }
function ri_log(PDO $db, string $ent, int $id, string $what, array $fields, ?int $by, string $note = ''): void {
    $db->prepare('INSERT INTO ri_log (entity, entity_id, what, fields, note, by_user) VALUES (?,?,?,?,?,?)')->execute([$ent, $id, $what, mb_substr(implode(', ', $fields), 0, 400), mb_substr($note, 0, 300), $by]);
}
function ri_history(PDO $db, string $ent, int $id): array { $s = $db->prepare('SELECT l.*, u.name AS by_name FROM ri_log l LEFT JOIN users u ON u.id = l.by_user WHERE entity = ? AND entity_id = ? ORDER BY l.id DESC LIMIT 50'); $s->execute([$ent, $id]); return $s->fetchAll(); }

/* ---------------- contacts ---------------- */

function ri_contact_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM ri_contact WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function ri_contacts(PDO $db, string $kind = '', string $q = '', bool $off = false): array {
    $w = ['active = ?']; $p = [$off ? 0 : 1];
    if (isset(RI_KINDS[$kind])) { $w[] = 'kind = ?'; $p[] = $kind; }
    if (($q = trim($q)) !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $w[] = '(org LIKE ? OR person LIKE ? OR city LIKE ? OR focus LIKE ? OR email LIKE ? OR phone LIKE ?)'; array_push($p, $l, $l, $l, $l, $l, $l); }
    $s = $db->prepare('SELECT * FROM ri_contact WHERE ' . implode(' AND ', $w) . ' ORDER BY kind, org, person LIMIT 1000'); $s->execute($p); return $s->fetchAll();
}
function ri_counts(PDO $db): array { $o = array_fill_keys(array_keys(RI_KINDS), 0); foreach ($db->query('SELECT kind, COUNT(*) n FROM ri_contact WHERE active = 1 GROUP BY kind') as $r) $o[$r['kind']] = (int)$r['n']; return $o; }
function ri_contact_save(PDO $db, array $u, int $id, array $in): int {
    ri_need($u); [$c, $e] = ri_contact_clean($in); if ($e) throw new InvalidArgumentException(implode(' ', $e));
    if ($id === 0) {
        $q = $db->prepare('SELECT id FROM ri_contact WHERE match_key = ? AND active = 1 LIMIT 1'); $q->execute([$c['match_key']]); if ($q->fetchColumn()) throw new InvalidArgumentException('This contact is already on the list.');
        $cols = array_merge(['kind'], array_keys(RI_FIELDS), ['src', 'match_key', 'created_by']);
        $db->prepare('INSERT INTO ri_contact (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute([$c['kind'], ...array_map(fn($f) => $c[$f], array_keys(RI_FIELDS)), 'Added by hand', $c['match_key'], (int)$u['id']]);
        $id = (int)$db->lastInsertId(); ri_log($db, 'contact', $id, 'add', [], (int)$u['id']); return $id;
    }
    $x = ri_contact_get($db, $id); if (!$x) throw new InvalidArgumentException('That contact is not on the list.');
    $ch = array_values(array_filter(array_merge(['kind'], array_keys(RI_FIELDS)), fn($f) => (string)$x[$f] !== (string)$c[$f])); if (!$ch) return $id;
    $db->prepare('UPDATE ri_contact SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_merge($ch, ['match_key']))) . ' WHERE id = ?')->execute([...array_map(fn($f) => $c[$f], $ch), $c['match_key'], $id]);
    ri_log($db, 'contact', $id, 'edit', $ch, (int)$u['id']); return $id;
}
function ri_contact_active(PDO $db, array $u, int $id, bool $on, string $why): void {
    ri_need($u); if (!ri_contact_get($db, $id)) throw new InvalidArgumentException('That contact is not on the list.');
    $why = mb_substr(trim(strip_tags($why)), 0, 200); if (!$on && $why === '') throw new InvalidArgumentException('Say why this contact is switched off.');
    $db->prepare('UPDATE ri_contact SET active = ?, off_reason = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : $why, $id]); ri_log($db, 'contact', $id, $on ? 'on' : 'off', [], (int)$u['id'], $why);
}
/** Check ($save false, writes nothing) or Save a contact file. Existing contacts only get their blanks filled. */
function ri_import(PDO $db, array $u, array $rows, string $kind, string $file, string $sha, string $source, bool $save): array {
    ri_need($u); if (!isset(RI_KINDS[$kind])) throw new InvalidArgumentException('Pick what kind of contacts the file holds.');
    [$cs, $skip] = ri_rows_to_contacts($rows, $kind, $source !== '' ? $source : $file);
    $res = ['plan' => [], 'added' => 0, 'filled' => 0, 'same' => 0, 'skipped' => count($skip), 'skip' => $skip];
    $find = $db->prepare('SELECT * FROM ri_contact WHERE match_key = ? AND active = 1 LIMIT 1');
    if ($save) $db->beginTransaction();
    try {
        foreach ($cs as $c) {
            $find->execute([$c['match_key']]); $old = $find->fetch() ?: null;
            if (!$old) { $res['plan'][] = ['New', $c, []]; $res['added']++;
                if ($save) { $cols = array_merge(['kind'], array_keys(RI_FIELDS), ['src', 'match_key', 'created_by']); $db->prepare('INSERT INTO ri_contact (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                    ->execute([$c['kind'], ...array_map(fn($f) => $c[$f], array_keys(RI_FIELDS)), $c['src'], $c['match_key'], (int)$u['id']]); ri_log($db, 'contact', (int)$db->lastInsertId(), 'import', [], (int)$u['id'], $file); } continue; }
            $fill = ri_fill($old, $c);
            if (!$fill) { $res['plan'][] = ['Already there', $c, []]; $res['same']++; continue; }
            $res['plan'][] = ['Fill blanks', $c, array_keys($fill)]; $res['filled']++;
            if ($save) { $db->prepare('UPDATE ri_contact SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($fill))) . ' WHERE id = ?')->execute([...array_values($fill), (int)$old['id']]); ri_log($db, 'contact', (int)$old['id'], 'import_fill', array_keys($fill), (int)$u['id'], $file); }
        }
        if ($save) { $db->prepare('INSERT INTO ri_import (file_name, sha256, source, kind, added, filled, same, skipped, by_user) VALUES (?,?,?,?,?,?,?,?,?)')->execute([mb_substr($file, 0, 200), $sha, mb_substr(trim(strip_tags($source)), 0, 200), $kind, $res['added'], $res['filled'], $res['same'], $res['skipped'], (int)$u['id']]); $db->commit(); }
    } catch (Throwable $e) { if ($save && $db->inTransaction()) $db->rollBack(); throw $e; }
    if ($save) audit((int)$u['id'], 'ri_import', $kind . ' added ' . $res['added'] . ' filled ' . $res['filled']);
    return $res;
}
function ri_imports(PDO $db): array { return $db->query('SELECT i.*, u.name AS by_name FROM ri_import i LEFT JOIN users u ON u.id = i.by_user ORDER BY i.id DESC LIMIT 20')->fetchAll(); }

/* ---------------- companies, projects, vacancies ---------------- */

function ri_company_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM ri_company WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function ri_company_find(PDO $db, string $name): ?array {
    $k = ri_company_key($name); $s = $db->prepare('SELECT * FROM ri_company WHERE match_key = ? AND active = 1 LIMIT 1'); $s->execute([$k]); if ($x = $s->fetch()) return $x;
    foreach ($db->query("SELECT * FROM ri_company WHERE active = 1 AND aliases <> ''") as $x) foreach (explode(',', (string)$x['aliases']) as $a) if (trim($a) !== '' && ri_company_key($a) === $k) return $x;
    return null;
}
function ri_companies(PDO $db, string $type = '', bool $off = false): array {
    $s = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM ri_vacancy v WHERE v.company_id = c.id AND v.status IN ('open','observed')) AS live FROM ri_company c WHERE c.active = ?" . (isset(RI_COMPANY_TYPES[$type]) ? ' AND c.type = ?' : '') . " ORDER BY FIELD(c.type, 'own', 'client', 'outside'), c.name");
    $s->execute(isset(RI_COMPANY_TYPES[$type]) ? [$off ? 0 : 1, $type] : [$off ? 0 : 1]); return $s->fetchAll();
}
function ri_company_save(PDO $db, array $u, int $id, array $in): int {
    ri_need($u);
    $c = ['name' => mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 200), 'aliases' => mb_substr(trim(strip_tags((string)($in['aliases'] ?? ''))), 0, 400), 'type' => (string)($in['type'] ?? ''),
          'industry' => mb_substr(trim(strip_tags((string)($in['industry'] ?? ''))), 0, 120), 'city' => mb_substr(trim(strip_tags((string)($in['city'] ?? ''))), 0, 80), 'watch' => empty($in['watch']) ? 0 : 1];
    if (ri_company_words($c['name']) === '') throw new InvalidArgumentException("Give the company's name.");
    if (!isset(RI_COMPANY_TYPES[$c['type']])) throw new InvalidArgumentException('Pick our organisation, client or outside company.');
    if ($c['type'] !== 'outside') $c['watch'] = 0;
    $same = ri_company_find($db, $c['name']); if ($same && (int)$same['id'] !== $id) throw new InvalidArgumentException('This company is already on the list as "' . $same['name'] . '".');
    $c['match_key'] = ri_company_key($c['name']);
    if ($id === 0) { $db->prepare('INSERT INTO ri_company (name, aliases, type, industry, city, watch, match_key, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([...array_values($c), (int)$u['id']]); $id = (int)$db->lastInsertId(); ri_log($db, 'company', $id, 'add', [], (int)$u['id']); return $id; }
    $x = ri_company_get($db, $id); if (!$x) throw new InvalidArgumentException('That company is not on the list.');
    if ($x['type'] !== $c['type'] && $c['type'] === 'outside') { $q = $db->prepare("SELECT (SELECT COUNT(*) FROM ri_vacancy WHERE company_id = ? AND status = 'open') + (SELECT COUNT(*) FROM ri_project WHERE company_id = ? AND status <> 'closed')"); $q->execute([$id, $id]); if ((int)$q->fetchColumn()) throw new InvalidArgumentException('This company has open vacancies or projects — close them before marking it an outside company.'); }
    $ch = array_values(array_filter(['name', 'aliases', 'type', 'industry', 'city', 'watch'], fn($k) => (string)$x[$k] !== (string)$c[$k])); if (!$ch) return $id;
    $db->prepare('UPDATE ri_company SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_merge($ch, ['match_key']))) . ' WHERE id = ?')->execute([...array_map(fn($k) => $c[$k], $ch), $c['match_key'], $id]);
    ri_log($db, 'company', $id, 'edit', $ch, (int)$u['id']); return $id;
}
function ri_company_active(PDO $db, array $u, int $id, bool $on, string $why): void {
    ri_need($u); $x = ri_company_get($db, $id); if (!$x) throw new InvalidArgumentException('That company is not on the list.');
    $why = mb_substr(trim(strip_tags($why)), 0, 200);
    if (!$on) { if ($why === '') throw new InvalidArgumentException('Say why this company is switched off.'); $q = $db->prepare("SELECT (SELECT COUNT(*) FROM ri_vacancy WHERE company_id = ? AND status IN ('open','observed')) + (SELECT COUNT(*) FROM ri_project WHERE company_id = ? AND status <> 'closed')"); $q->execute([$id, $id]); if ((int)$q->fetchColumn()) throw new InvalidArgumentException("Close this company's open vacancies and projects first."); }
    elseif (($same = ri_company_find($db, $x['name'])) && (int)$same['id'] !== $id) throw new InvalidArgumentException('Another company with this name is already on the list.');
    $db->prepare('UPDATE ri_company SET active = ?, off_reason = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : $why, $id]); ri_log($db, 'company', $id, $on ? 'on' : 'off', [], (int)$u['id'], $why);
}
function ri_project_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM ri_project WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function ri_projects(PDO $db, bool $closed = false): array {
    return $db->query("SELECT p.*, c.name AS company, u.name AS owner, (SELECT COUNT(*) FROM ri_vacancy v WHERE v.project_id = p.id AND v.status = 'open') AS open_vac FROM ri_project p JOIN ri_company c ON c.id = p.company_id LEFT JOIN users u ON u.id = p.owner_id
        WHERE p.status " . ($closed ? "= 'closed'" : "<> 'closed'") . " ORDER BY FIELD(p.status, 'active', 'planned', 'hold', 'closed'), p.target_on IS NULL, p.target_on, p.name")->fetchAll();
}
function ri_project_save(PDO $db, array $u, int $id, array $in): int {
    ri_need($u);
    $p = ['name' => mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 160), 'company_id' => (int)($in['company_id'] ?? 0), 'site' => mb_substr(trim(strip_tags((string)($in['site'] ?? ''))), 0, 120),
          'owner_id' => (int)($in['owner_id'] ?? 0) ?: null, 'start_on' => ri_day((string)($in['start_on'] ?? ''), 'start') ?: null, 'target_on' => ri_day((string)($in['target_on'] ?? ''), 'target') ?: null,
          'status' => (string)($in['status'] ?? 'planned'), 'notes' => mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 1000)];
    if ($p['name'] === '') throw new InvalidArgumentException('Give the project a name.');
    if (!isset(RI_PROJECT_STATUS[$p['status']])) throw new InvalidArgumentException("Pick the project's status.");
    if ($p['start_on'] && $p['target_on'] && $p['target_on'] < $p['start_on']) throw new InvalidArgumentException('The target date is before the start date.');
    $co = ri_company_get($db, $p['company_id']); if (!$co || !(int)$co['active']) throw new InvalidArgumentException('Pick the company this project hires for.');
    if ($co['type'] === 'outside') throw new InvalidArgumentException("Projects are for our organisation and clients — an outside company's openings are only watched.");
    if ($id === 0) { $db->prepare('INSERT INTO ri_project (' . implode(',', array_keys($p)) . ', created_by) VALUES (' . implode(',', array_fill(0, count($p) + 1, '?')) . ')')->execute([...array_values($p), (int)$u['id']]); $id = (int)$db->lastInsertId(); ri_log($db, 'project', $id, 'add', [], (int)$u['id']); return $id; }
    $x = ri_project_get($db, $id); if (!$x) throw new InvalidArgumentException('That project is not on the list.');
    $ch = array_values(array_filter(array_keys($p), fn($k) => (string)($x[$k] ?? '') !== (string)($p[$k] ?? ''))); if (!$ch) return $id;
    $db->prepare('UPDATE ri_project SET ' . implode(', ', array_map(fn($k) => "$k = ?", $ch)) . ' WHERE id = ?')->execute([...array_map(fn($k) => $p[$k], $ch), $id]); ri_log($db, 'project', $id, 'edit', $ch, (int)$u['id']); return $id;
}
const RI_VAC_FIELDS = ['company_id', 'project_id', 'req_id', 'title', 'dept', 'location', 'openings', 'exp_min', 'exp_max', 'pay_min', 'pay_max', 'posted_on', 'closes_on', 'status', 'src', 'notes'];
function ri_vacancy_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT v.*, c.name AS company, c.type AS company_type FROM ri_vacancy v JOIN ri_company c ON c.id = v.company_id WHERE v.id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
/** Pay figures are shown only to logins with pay data rights; saving without them keeps the stored figures. */
function ri_vacancy_save(PDO $db, array $u, int $id, array $in): int {
    ri_need($u); $co = ri_company_get($db, (int)($in['company_id'] ?? 0)); if (!$co || !(int)$co['active']) throw new InvalidArgumentException('Pick the hiring company.');
    $v = ri_vacancy_input($in, (string)$co['type']); $old = $id ? ri_vacancy_get($db, $id) : null; if ($id && !$old) throw new InvalidArgumentException('That vacancy is not on the list.');
    if (!data_allowed($u, 'pay')) { $v['pay_min'] = $old['pay_min'] ?? null; $v['pay_max'] = $old['pay_max'] ?? null; }
    if ($v['project_id']) { $pj = ri_project_get($db, $v['project_id']); if (!$pj || (int)$pj['company_id'] !== (int)$co['id']) throw new InvalidArgumentException('That project is for another company.'); if (!$id && $pj['status'] === 'closed') throw new InvalidArgumentException('That project is closed.'); }
    if ($v['req_id']) { $q = $db->prepare("SELECT id FROM ri_vacancy WHERE req_id = ? AND status = 'open' AND id <> ? LIMIT 1"); $q->execute([$v['req_id'], $id]); if ($q->fetchColumn()) throw new InvalidArgumentException('That requisition already has an open job code.'); }
    if (!$id) { $db->prepare('INSERT INTO ri_vacancy (' . implode(',', RI_VAC_FIELDS) . ', created_by) VALUES (' . implode(',', array_fill(0, count(RI_VAC_FIELDS) + 1, '?')) . ')')->execute([...array_map(fn($k) => $v[$k], RI_VAC_FIELDS), (int)$u['id']]); $id = (int)$db->lastInsertId(); ri_log($db, 'vacancy', $id, 'add', [], (int)$u['id'], ri_job_code($id)); return $id; }
    $same = fn($a, $b) => is_numeric($a) && is_numeric($b) ? (float)$a === (float)$b : (string)($a ?? '') === (string)($b ?? '');
    $ch = array_values(array_filter(RI_VAC_FIELDS, fn($k) => !$same($old[$k], $v[$k]))); if (!$ch) return $id;
    $db->prepare('UPDATE ri_vacancy SET ' . implode(', ', array_map(fn($k) => "$k = ?", $ch)) . ' WHERE id = ?')->execute([...array_map(fn($k) => $v[$k], $ch), $id]); ri_log($db, 'vacancy', $id, 'edit', $ch, (int)$u['id']); return $id;
}
function ri_vacancies(PDO $db, string $which = 'live', string $q = ''): array {
    $st = ['live' => "('open','observed')", 'open' => "('open')", 'observed' => "('observed')", 'closed' => "('filled','cancelled')"][$which] ?? "('open','observed')";
    $w = "v.status IN $st"; $p = [];
    if (($q = trim($q)) !== '') { if ($j = ri_job_find($q)) { $w .= ' AND v.id = ?'; $p[] = $j; } else { $l = '%' . addcslashes($q, '%_\\') . '%'; $w .= ' AND (v.title LIKE ? OR c.name LIKE ? OR v.location LIKE ?)'; array_push($p, $l, $l, $l); } }
    $s = $db->prepare("SELECT v.*, c.name AS company, c.type AS company_type, c.watch, p.name AS project FROM ri_vacancy v JOIN ri_company c ON c.id = v.company_id LEFT JOIN ri_project p ON p.id = v.project_id WHERE $w ORDER BY v.status = 'observed', v.closes_on IS NULL, v.closes_on, v.id DESC LIMIT 500");
    $s->execute($p); return $s->fetchAll();
}
/** Hiring trends: outside companies' observed openings in the window. */
function ri_trends(PDO $db, ?int $days = null): array {
    $days ??= (int)ri_settings()['trend_days'];
    $s = $db->prepare("SELECT v.title, v.openings, v.posted_on, v.created_at, c.name AS company, c.watch FROM ri_vacancy v JOIN ri_company c ON c.id = v.company_id WHERE c.type = 'outside' AND v.status = 'observed' AND COALESCE(v.posted_on, DATE(v.created_at)) >= ?");
    $s->execute([date('Y-m-d', strtotime("-$days days"))]); return ri_trend_calc($s->fetchAll());
}
