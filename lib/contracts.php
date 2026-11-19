<?php
// Third parties & contracts — rules. A party register (vendors, contractors, consultants …) with its tax / registration
// numbers sealed at rest, shown masked, and found by a keyed hash for the duplicate check; contacts; agreements whose
// every PDF is a new version (SHA-256), never replaced or deleted; approval bound to the exact file on the server (a new
// version supersedes a pending or given approval); never decided by the person who asked or uploaded the file; a copy
// signed outside recorded by an approver; amendments as new agreements; compliance documents with expiry, verified only
// by someone other than the uploader (Expired wins over Verified); expiry reminders once per item per band. Pure first.
declare(strict_types=1);

const CT_CATEGORIES = ['vendor' => 'Vendor / supplier', 'contractor' => 'Contractor / manpower agency', 'consultant' => 'Consultant / professional services',
    'marketing' => 'Marketing / advertising agency', 'it' => 'Website / IT service provider', 'freelancer' => 'Freelancer / independent professional',
    'facility' => 'Maintenance / facility services', 'other' => 'Other external party'];
const CT_ENTITY_TYPES = ['company' => 'Company', 'llp' => 'Limited liability partnership', 'partnership' => 'Partnership firm', 'proprietorship' => 'Sole proprietor',
    'individual' => 'Individual', 'trust' => 'Trust / society', 'govt' => 'Government body', 'other' => 'Other'];
const CT_STATUS = ['prospect' => 'Onboarding', 'active' => 'Active', 'suspended' => 'Suspended', 'terminated' => 'Terminated', 'closed' => 'Closed'];
const CT_RISK = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
const CT_DD = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'cleared' => 'Cleared', 'rejected' => 'Rejected'];
const CT_TYPES = ['NDA' => 'Non-disclosure agreement', 'MSA' => 'Master service agreement', 'SVC' => 'Service agreement', 'CON' => 'Contractor agreement',
    'CNS' => 'Consultancy agreement', 'SOW' => 'Statement of work', 'PC' => 'Purchase contract', 'WO' => 'Work order', 'DPA' => 'Data processing agreement',
    'IP' => 'IP / content ownership agreement', 'SEC' => 'Confidentiality & security undertaking', 'AMD' => 'Amendment', 'EXT' => 'Extension', 'REN' => 'Renewal', 'TRM' => 'Termination agreement'];
const CT_CONTRACT_STATUS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved for signing', 'executed' => 'Executed', 'cancelled' => 'Cancelled'];
const CT_DOC_KINDS = ['tax' => 'Tax registration', 'registration' => 'Business registration / incorporation', 'signatory' => 'Authorised signatory proof',
    'licence' => 'Licence / certification', 'insurance' => 'Insurance', 'labour' => 'Contract labour licence', 'social' => 'Social security / wage compliance',
    'dpa' => 'Data protection undertaking', 'other' => 'Other document'];
const CT_DOC_STATUS = ['uploaded' => 'Not verified yet', 'verified' => 'Verified', 'rejected' => 'Rejected'];
const CT_PDF_MAX = 15728640; const CT_DOC_MAX = 10485760;
const CT_DEFAULTS = ['view_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'], 'manage_roles' => ['SUPERADMIN', 'HRMANAGER'], 'approve_roles' => ['SUPERADMIN', 'CEO'],
    'sensitive_roles' => ['HRMANAGER'], 'commercial_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'], 'alert_days' => [90, 60, 30, 15, 7], 'prefix' => 'TPC',
    'tax_label' => 'Tax ID', 'reg_label' => 'Registration no.'];

/* ---------------- pure ---------------- */

function ct_settings(): array { $s = module_states()['contracts']['settings'] ?? []; return array_replace(CT_DEFAULTS, array_intersect_key($s, CT_DEFAULTS)); }
function ct_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['view_roles', 'manage_roles', 'approve_roles', 'sensitive_roles', 'commercial_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['manage_roles']) $e[] = 'Pick at least one role that keeps the register.';
    if (!$o['approve_roles']) $e[] = 'Pick at least one role that approves agreements.';
    $d = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($in['alert_days'] ?? ''))), fn($n) => $n > 0 && $n <= 365))); rsort($d);
    $o['alert_days'] = array_slice($d ?: CT_DEFAULTS['alert_days'], 0, 8);
    $o['prefix'] = substr(strtoupper((string)preg_replace('/[^A-Za-z]/', '', (string)($in['prefix'] ?? 'TPC'))), 0, 6) ?: 'TPC';
    foreach (['tax_label' => 'Tax ID', 'reg_label' => 'Registration no.'] as $k => $def) $o[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, 40) ?: $def;
    return [$o, $e];
}
/** Rights from roles; Super Admin always views, manages and approves (but sees full numbers only with the sensitive right). */
function ct_rights_for(array $roles, array $set): array {
    $h = fn($k) => (bool)array_intersect($roles, $set[$k . '_roles']); $sa = in_array('SUPERADMIN', $roles, true);
    $r = ['manage' => $sa || $h('manage'), 'approve' => $sa || $h('approve'), 'sensitive' => $h('sensitive'), 'commercial' => $sa || $h('commercial')];
    $r['view'] = $sa || $h('view') || $r['manage'] || $r['approve']; return $r;
}
/** Approval is never by the requester or the person who uploaded the version. */
function ct_may_decide(array $rights, int $uid, int $requestedBy, int $uploadedBy): bool { return $rights['approve'] && $uid !== $requestedBy && $uid !== $uploadedBy; }
function ct_name_key(string $n): string {
    $n = mb_strtolower($n); $n = (string)preg_replace('/\b(private|pvt|limited|ltd|llp|inc|co|company|corp|corporation|the|and|&)\b\.?/u', ' ', $n);
    return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n));
}
function ct_id_norm(string $v): string { return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $v)); }
function ct_txt($v, int $n): string { return mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)$v)), 0, $n); }
function ct_date($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null; }
function ct_pick(string $v, array $list, string $what): string { if (!isset($list[$v])) throw new RuntimeException("Pick the $what."); return $v; }
/** A party form → [fields, tax id, registration no.]. */
function ct_party_clean(array $in): array {
    $f = ['legal_name' => ct_txt($in['legal_name'] ?? '', 160), 'trade_name' => ct_txt($in['trade_name'] ?? '', 160), 'entity_type' => ct_pick((string)($in['entity_type'] ?? ''), CT_ENTITY_TYPES, 'entity type'),
          'category' => ct_pick((string)($in['category'] ?? ''), CT_CATEGORIES, 'category'), 'purpose' => ct_txt($in['purpose'] ?? '', 500), 'address' => ct_txt($in['address'] ?? '', 400),
          'city' => ct_txt($in['city'] ?? '', 60), 'state' => ct_txt($in['state'] ?? '', 60), 'postcode' => ct_txt($in['postcode'] ?? '', 12),
          'branch_id' => (int)($in['branch_id'] ?? 0) ?: null, 'owner_id' => (int)($in['owner_id'] ?? 0) ?: null, 'onboarded_on' => ct_date($in['onboarded_on'] ?? ''),
          'status' => ct_pick((string)($in['status'] ?? 'prospect'), CT_STATUS, 'status'), 'risk' => ct_pick((string)($in['risk'] ?? 'medium'), CT_RISK, 'risk level'),
          'dd_status' => ct_pick((string)($in['dd_status'] ?? 'not_started'), CT_DD, 'due-diligence state')];
    if (mb_strlen($f['legal_name']) < 2) throw new RuntimeException('Write the legal name as registered.');
    $f['name_key'] = ct_name_key($f['legal_name']);
    $tax = ct_id_norm((string)($in['tax_id'] ?? '')); $reg = ct_id_norm((string)($in['reg_no'] ?? ''));
    foreach (['tax ID' => $tax, 'registration no.' => $reg] as $l => $v) if ($v !== '' && (strlen($v) < 5 || strlen($v) > 30)) throw new RuntimeException("The $l looks wrong (5 to 30 letters and digits).");
    return [$f, $tax, $reg];
}
function ct_contract_clean(array $in): array {
    $f = ['type' => ct_pick((string)($in['type'] ?? ''), CT_TYPES, 'agreement type'), 'title' => ct_txt($in['title'] ?? '', 200), 'scope' => mb_substr(trim((string)($in['scope'] ?? '')), 0, 2000),
          'owner_id' => (int)($in['owner_id'] ?? 0) ?: null, 'effective_from' => ct_date($in['effective_from'] ?? ''), 'expires_on' => ct_date($in['expires_on'] ?? ''),
          'notice_days' => ($n = trim((string)($in['notice_days'] ?? ''))) === '' ? null : (int)$n];
    if (mb_strlen($f['title']) < 3) throw new RuntimeException('Give the agreement a title.');
    if ($f['effective_from'] && $f['expires_on'] && $f['expires_on'] < $f['effective_from']) throw new RuntimeException('The end date is before the start date.');
    if ($f['notice_days'] !== null && ($f['notice_days'] < 0 || $f['notice_days'] > 3650)) throw new RuntimeException('Notice period: 0 to 3650 days.');
    return $f;
}
/** Value and terms — read only from a login with the commercial right. */
function ct_commercial_clean(array $in): array {
    $v = trim((string)preg_replace('/[^\d.]/', '', (string)($in['value'] ?? '')));
    if ($v !== '' && !preg_match('/^\d{1,13}(\.\d{1,2})?$/', $v)) throw new RuntimeException('Value: a number.');
    [$a, $b] = array_pad(explode('.', $v), 2, '');
    return ['value_minor' => $v === '' ? null : (int)$a * 100 + (int)str_pad($b, 2, '0'), 'terms' => mb_substr(trim((string)($in['terms'] ?? '')), 0, 1000)];
}
/** Shown state: an executed agreement past its end date reads Expired; Expired wins over Verified for documents. */
function ct_contract_state(array $c, string $today): string { return $c['status'] === 'executed' && !empty($c['expires_on']) && $c['expires_on'] < $today ? 'Expired' : (CT_CONTRACT_STATUS[$c['status']] ?? $c['status']); }
function ct_doc_state(array $d, string $today): string { return !empty($d['expires_on']) && $d['expires_on'] < $today ? 'Expired' : (CT_DOC_STATUS[$d['status']] ?? $d['status']); }
/** The reminder band for days left: the smallest alert day count at or above it; null = not due yet. */
function ct_band(int $left, array $days): ?int { $b = null; foreach ($days as $d) if ($left <= $d && ($b === null || $d < $b)) $b = $d; return $b; }
function ct_fy(string $ymd, int $start): string { $y = (int)substr($ymd, 0, 4); $m = (int)substr($ymd, 5, 2); if ($start <= 1) return (string)$y; $s = $m >= $start ? $y : $y - 1; return sprintf('%02d-%02d', $s % 100, ($s + 1) % 100); }
function ct_sniff(string $b): ?string {
    if (str_starts_with($b, '%PDF-')) return 'application/pdf'; if (str_starts_with($b, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) return 'image/png'; return null;
}
function ct_file_name(string $n, string $fallback): string { $n = (string)preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($n)); return mb_substr(trim($n), 0, 160) ?: $fallback; }

/* ---------------- database ---------------- */

function ct_rights(array $u): array { return ct_rights_for(user_roles((int)$u['id'], !empty($u['is_owner'])), ct_settings()); }
function ct_need(array $u, string $r): void { if (!ct_rights($u)[$r]) throw new RuntimeException('Your role cannot do this.'); }
function ct_hash(string $kind, string $v): ?string { return $v === '' ? null : hash_hmac('sha256', $kind . '|' . $v, seal_key()); }
function ct_next(PDO $db, string $k): int { $db->prepare('INSERT INTO ct_seq (k, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$k]); $s = $db->prepare('SELECT n FROM ct_seq WHERE k = ?'); $s->execute([$k]); return (int)$s->fetchColumn(); }
/** Change log: ids, field names and states only — never a number or an amount. */
function ct_log(PDO $db, ?int $uid, string $ent, int $id, ?int $party, string $action, string $detail = ''): void {
    $db->prepare('INSERT INTO ct_log (user_id, entity, entity_id, party_id, action, detail) VALUES (?,?,?,?,?,?)')->execute([$uid, $ent, $id, $party, $action, mb_substr($detail, 0, 1000)]);
}
function ct_history(PDO $db, string $ent, int $id): array { $s = $db->prepare('SELECT l.*, u.name AS who FROM ct_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.entity = ? AND l.entity_id = ? ORDER BY l.id DESC LIMIT 200'); $s->execute([$ent, $id]); return $s->fetchAll(); }
function ct_people_with(PDO $db, string $right): array {
    $roles = ct_settings()[$right . '_roles']; if ($right !== 'sensitive') $roles[] = 'SUPERADMIN'; $roles = array_values(array_unique($roles));
    $s = $db->prepare('SELECT DISTINCT u.id FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ')'); $s->execute($roles);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}
function ct_tell(PDO $db, int $uid, string $title, string $body, string $url): void { if (module_on('notify')) { require_once __DIR__ . '/push.php'; notify_user($db, $uid, 'contracts', $title, $body, 'modules/contracts/' . $url); } }
/** Same tax ID / registration no. (hard — refused) or the same name (soft — asked). */
function ct_duplicates(PDO $db, string $nameKey, string $tax, string $reg, int $except = 0): array {
    $hard = [];
    foreach (['tax_hash' => ct_hash('tax', $tax), 'reg_hash' => ct_hash('reg', $reg)] as $col => $hsh) if ($hsh) { $s = $db->prepare("SELECT id, ref, legal_name FROM ct_party WHERE $col = ? AND id <> ?"); $s->execute([$hsh, $except]); if ($r = $s->fetch()) $hard[] = $r; }
    $s = $db->prepare('SELECT id, ref, legal_name FROM ct_party WHERE name_key = ? AND id <> ?'); $s->execute([$nameKey, $except]);
    return ['hard' => $hard, 'soft' => $s->fetchAll()];
}
function ct_party(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM ct_party WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function ct_parties(PDO $db, array $f = []): array {
    $w = ['1=1']; $a = [];
    if (isset(CT_STATUS[$f['status'] ?? ''])) { $w[] = 'status = ?'; $a[] = $f['status']; }
    if (isset(CT_CATEGORIES[$f['category'] ?? ''])) { $w[] = 'category = ?'; $a[] = $f['category']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(legal_name LIKE ? OR trade_name LIKE ? OR ref LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    $s = $db->prepare('SELECT * FROM ct_party WHERE ' . implode(' AND ', $w) . ' ORDER BY legal_name LIMIT 1000'); $s->execute($a); return $s->fetchAll();
}
function ct_party_save(PDO $db, array $u, array $in, int $id = 0, bool $sameNameOk = false): int {
    ct_need($u, 'manage'); [$f, $tax, $reg] = ct_party_clean($in); $uid = (int)$u['id'];
    $old = $id ? ct_party($db, $id) : null; if ($id && !$old) throw new RuntimeException('Party not found.');
    $d = ct_duplicates($db, $f['name_key'], $tax, $reg, $id);
    if ($d['hard']) throw new RuntimeException('Another party already has that number: ' . $d['hard'][0]['ref'] . ' · ' . $d['hard'][0]['legal_name'] . '.');
    if ($d['soft'] && !$sameNameOk && (!$old || $old['name_key'] !== $f['name_key'])) throw new RuntimeException('A party of the same name is on the register: ' . $d['soft'][0]['ref'] . '. Tick "A different party" to add it anyway.');
    foreach (['tax' => $tax, 'reg' => $reg] as $k => $v) if ($v !== '' || !$old) { $f[$k . '_box'] = $v === '' ? null : seal($v); $f[$k . '_hash'] = ct_hash($k, $v); $f[$k . '_tail'] = substr($v, -4); }
    if ($old) {
        $ch = array_keys(array_filter($f, fn($v, $k) => (string)$v !== (string)$old[$k], ARRAY_FILTER_USE_BOTH));
        if (!$ch) return $id;
        $db->prepare('UPDATE ct_party SET ' . implode(', ', array_map(fn($k) => "$k = ?", $ch)) . ' WHERE id = ?')->execute([...array_map(fn($k) => $f[$k], $ch), $id]);
        ct_log($db, $uid, 'party', $id, $id, 'updated', implode(', ', array_unique(array_map(fn($k) => preg_replace('/_(box|hash|tail)$/', ' no.', $k), $ch))));
        return $id;
    }
    $f += ['ref' => 'TP' . sprintf('%05d', ct_next($db, 'party')), 'created_by' => $uid];
    $db->prepare('INSERT INTO ct_party (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    $id = (int)$db->lastInsertId(); ct_log($db, $uid, 'party', $id, $id, 'created'); audit($uid, 'ct_party_add', '#' . $id);
    return $id;
}
/** Full tax ID / registration no. — sensitive right only; every look audited, never logged with the value. */
function ct_identity(PDO $db, array $u, int $id): array {
    ct_need($u, 'sensitive'); $p = ct_party($db, $id); if (!$p) throw new RuntimeException('Party not found.');
    audit((int)$u['id'], 'ct_identity_view', '#' . $id);
    return ['tax' => $p['tax_box'] ? unseal((string)$p['tax_box']) : '', 'reg' => $p['reg_box'] ? unseal((string)$p['reg_box']) : ''];
}
function ct_contact_add(PDO $db, array $u, int $pid, array $in): int {
    ct_need($u, 'manage'); if (!ct_party($db, $pid)) throw new RuntimeException('Party not found.');
    $n = ct_txt($in['name'] ?? '', 120); if ($n === '') throw new RuntimeException('Give the contact\'s name.');
    $em = strtolower(trim((string)($in['email'] ?? ''))); if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Check the email.');
    $db->prepare('INSERT INTO ct_contact (party_id, name, role, email, phone, signatory, created_by) VALUES (?,?,?,?,?,?,?)')->execute([$pid, $n, ct_txt($in['role'] ?? '', 80), $em, ct_txt(preg_replace('/[^0-9+ ]/', '', (string)($in['phone'] ?? '')), 20), empty($in['signatory']) ? 0 : 1, (int)$u['id']]);
    $cid = (int)$db->lastInsertId(); ct_log($db, (int)$u['id'], 'party', $pid, $pid, 'contact added'); return $cid;
}
function ct_contact_active(PDO $db, array $u, int $id, bool $on): void {
    ct_need($u, 'manage'); $s = $db->prepare('SELECT party_id FROM ct_contact WHERE id = ?'); $s->execute([$id]); $p = $s->fetchColumn(); if (!$p) throw new RuntimeException('Contact not found.');
    $db->prepare('UPDATE ct_contact SET active = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]); ct_log($db, (int)$u['id'], 'party', (int)$p, (int)$p, $on ? 'contact on' : 'contact off');
}
function ct_contacts(PDO $db, int $pid): array { $s = $db->prepare('SELECT * FROM ct_contact WHERE party_id = ? ORDER BY active DESC, name'); $s->execute([$pid]); return $s->fetchAll(); }
function ct_contract(PDO $db, int $id): ?array { $s = $db->prepare('SELECT c.*, p.legal_name, p.ref AS party_ref, p.status AS party_status FROM ct_contract c JOIN ct_party p ON p.id = c.party_id WHERE c.id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function ct_contracts(PDO $db, array $f = []): array {
    $w = ['1=1']; $a = [];
    if (!empty($f['party'])) { $w[] = 'c.party_id = ?'; $a[] = (int)$f['party']; }
    if (isset(CT_CONTRACT_STATUS[$f['status'] ?? ''])) { $w[] = 'c.status = ?'; $a[] = $f['status']; }
    $s = $db->prepare('SELECT c.id, c.ref, c.type, c.title, c.status, c.effective_from, c.expires_on, c.executed_on, c.how_signed, c.party_id, c.parent_id, p.legal_name FROM ct_contract c JOIN ct_party p ON p.id = c.party_id WHERE ' . implode(' AND ', $w) . ' ORDER BY c.id DESC LIMIT 500');
    $s->execute($a); return $s->fetchAll();
}
function ct_contract_create(PDO $db, array $u, int $pid, array $in, ?int $parent = null): int {
    ct_need($u, 'manage'); $p = ct_party($db, $pid); if (!$p) throw new RuntimeException('Party not found.');
    if (in_array($p['status'], ['terminated', 'closed'], true)) throw new RuntimeException('This party is ' . strtolower(CT_STATUS[$p['status']]) . ' — reopen it first.');
    $f = ct_contract_clean($in); $c = ct_rights($u)['commercial'] ? ct_commercial_clean($in) : ['value_minor' => null, 'terms' => ''];
    if ($parent !== null) { $par = ct_contract($db, $parent); if (!$par || (int)$par['party_id'] !== $pid) throw new RuntimeException('The agreement being amended is not with this party.'); }
    $set = ct_settings(); $fy = ct_fy($f['effective_from'] ?? date('Y-m-d'), (int)(org('fy_start_month') ?: 1));
    $ref = sprintf('%s/%s/%s/%03d', $set['prefix'], $f['type'], $fy, ct_next($db, "c|{$f['type']}|$fy"));
    $row = $f + $c + ['ref' => $ref, 'party_id' => $pid, 'parent_id' => $parent, 'created_by' => (int)$u['id']]; $row['owner_id'] = $row['owner_id'] ?: (int)$u['id'];
    $db->prepare('INSERT INTO ct_contract (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    $id = (int)$db->lastInsertId(); ct_log($db, (int)$u['id'], 'contract', $id, $pid, 'created', $parent ? 'amends #' . $parent : ''); audit((int)$u['id'], 'ct_contract_add', '#' . $id);
    return $id;
}
/** Details change only while a draft; after that a change is an amendment. */
function ct_contract_update(PDO $db, array $u, int $id, array $in): void {
    ct_need($u, 'manage'); $o = ct_contract($db, $id); if (!$o) throw new RuntimeException('Agreement not found.');
    if ($o['status'] !== 'draft') throw new RuntimeException('Only a draft can be edited. ' . ($o['status'] === 'executed' ? 'Add an amendment instead.' : 'Withdraw the approval request first.'));
    $f = ct_contract_clean(['type' => $o['type']] + $in); unset($f['type']); if (ct_rights($u)['commercial']) $f += ct_commercial_clean($in);
    $ch = array_keys(array_filter($f, fn($v, $k) => (string)$v !== (string)$o[$k], ARRAY_FILTER_USE_BOTH)); if (!$ch) return;
    $db->prepare('UPDATE ct_contract SET ' . implode(', ', array_map(fn($k) => "$k = ?", $ch)) . ' WHERE id = ?')->execute([...array_map(fn($k) => $f[$k], $ch), $id]);
    ct_log($db, (int)$u['id'], 'contract', $id, (int)$o['party_id'], 'updated', implode(', ', $ch));
}
function ct_versions(PDO $db, int $cid): array { $s = $db->prepare('SELECT v.id, v.no, v.file_name, v.size, v.sha256, v.signed, v.note, v.uploaded_by, v.uploaded_at, u.name AS who FROM ct_version v LEFT JOIN users u ON u.id = v.uploaded_by WHERE v.contract_id = ? ORDER BY v.no DESC'); $s->execute([$cid]); return $s->fetchAll(); }
/** Add a PDF version. A new version supersedes a pending / given approval (back to Draft). $signed = a copy signed outside (approvers), which executes the agreement. */
function ct_version_add(PDO $db, array $u, int $cid, string $bytes, string $name, string $note = '', bool $signed = false, ?string $signedOn = null): int {
    ct_need($u, $signed ? 'approve' : 'manage'); $c = ct_contract($db, $cid); if (!$c) throw new RuntimeException('Agreement not found.');
    if (in_array($c['status'], ['executed', 'cancelled'], true)) throw new RuntimeException('This agreement is ' . strtolower(CT_CONTRACT_STATUS[$c['status']]) . '; it takes no new versions.' . ($c['status'] === 'executed' ? ' Add an amendment.' : ''));
    if ($bytes === '' || strlen($bytes) > CT_PDF_MAX || ct_sniff($bytes) !== 'application/pdf') throw new RuntimeException('The agreement must be a PDF of up to 15 MB.');
    if ($signed) { $signedOn = ct_date($signedOn); if (!$signedOn || $signedOn > date('Y-m-d')) throw new RuntimeException('Give the date it was signed (not in the future).'); }
    $sha = hash('sha256', $bytes); $uid = (int)$u['id'];
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM ct_contract WHERE id = ? FOR UPDATE')->execute([$cid]);
        $n = $db->prepare('SELECT COALESCE(MAX(no), 0) + 1 FROM ct_version WHERE contract_id = ?'); $n->execute([$cid]); $no = (int)$n->fetchColumn();
        $st = $db->prepare('INSERT INTO ct_version (contract_id, no, file, file_name, size, sha256, signed, note, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->bindValue(1, $cid, PDO::PARAM_INT); $st->bindValue(2, $no, PDO::PARAM_INT); $st->bindValue(3, $bytes, PDO::PARAM_LOB); $st->bindValue(4, ct_file_name($name, "agreement-v$no.pdf"));
        $st->bindValue(5, strlen($bytes), PDO::PARAM_INT); $st->bindValue(6, $sha); $st->bindValue(7, $signed ? 1 : 0, PDO::PARAM_INT); $st->bindValue(8, ct_txt($note, 500)); $st->bindValue(9, $uid, PDO::PARAM_INT); $st->execute();
        $vid = (int)$db->lastInsertId();
        $sup = $db->prepare("UPDATE ct_approval SET status = 'superseded', decided_at = NOW() WHERE contract_id = ? AND status IN ('pending','approved')"); $sup->execute([$cid]);
        if ($signed) $db->prepare("UPDATE ct_contract SET current_version = ?, executed_version = ?, status = 'executed', executed_on = ?, how_signed = 'outside' WHERE id = ?")->execute([$vid, $vid, $signedOn, $cid]);
        else $db->prepare("UPDATE ct_contract SET current_version = ?, approved_version = NULL, status = 'draft' WHERE id = ?")->execute([$vid, $cid]);
        ct_log($db, $uid, 'contract', $cid, (int)$c['party_id'], $signed ? 'signed copy recorded' : 'version added', 'v' . $no . ' sha256 ' . substr($sha, 0, 16) . ($sup->rowCount() ? ' · approval superseded' : ''));
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit($uid, $signed ? 'ct_signed_outside' : 'ct_version', '#' . $cid . ' v' . $no);
    return $vid;
}
function ct_open_approval(PDO $db, int $cid): ?array { $s = $db->prepare("SELECT a.*, v.uploaded_by, v.no FROM ct_approval a JOIN ct_version v ON v.id = a.version_id WHERE a.contract_id = ? AND a.status = 'pending' ORDER BY a.id DESC LIMIT 1"); $s->execute([$cid]); return $s->fetch() ?: null; }
function ct_approval_request(PDO $db, array $u, int $cid, string $note = ''): int {
    ct_need($u, 'manage'); $c = ct_contract($db, $cid); if (!$c) throw new RuntimeException('Agreement not found.');
    if ($c['status'] !== 'draft') throw new RuntimeException('Only a draft can be sent for approval.'); if (!$c['current_version']) throw new RuntimeException('Upload the agreement (PDF) first.');
    $v = $db->prepare('SELECT id, sha256, no FROM ct_version WHERE id = ?'); $v->execute([$c['current_version']]); $v = $v->fetch();
    $db->beginTransaction();
    try {
        $l = $db->prepare("UPDATE ct_contract SET status = 'pending' WHERE id = ? AND status = 'draft'"); $l->execute([$cid]); if (!$l->rowCount()) throw new RuntimeException('Someone else changed this agreement — reload the page.');
        $db->prepare('INSERT INTO ct_approval (contract_id, version_id, sha256, requested_by, note) VALUES (?,?,?,?,?)')->execute([$cid, (int)$v['id'], $v['sha256'], (int)$u['id'], ct_txt($note, 500)]);
        $aid = (int)$db->lastInsertId(); ct_log($db, (int)$u['id'], 'contract', $cid, (int)$c['party_id'], 'approval requested', 'v' . $v['no']);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    foreach (ct_people_with($db, 'approve') as $w) if ($w !== (int)$u['id']) ct_tell($db, $w, 'Agreement to approve', $c['ref'] . ' · ' . $c['legal_name'], 'index.php?view=c&id=' . $cid);
    return $aid;
}
/** Approve or send back; the file must still be the one asked about (same version and SHA-256). */
function ct_approval_decide(PDO $db, array $u, int $aid, bool $ok, string $note = ''): void {
    $s = $db->prepare('SELECT a.*, v.uploaded_by, v.sha256 AS v_sha, v.no FROM ct_approval a JOIN ct_version v ON v.id = a.version_id WHERE a.id = ?'); $s->execute([$aid]); $a = $s->fetch();
    if (!$a || $a['status'] !== 'pending') throw new RuntimeException('This request is no longer waiting.');
    if (!ct_may_decide(ct_rights($u), (int)$u['id'], (int)$a['requested_by'], (int)$a['uploaded_by'])) throw new RuntimeException('This needs an approver who did not ask for it or upload the file.');
    $c = ct_contract($db, (int)$a['contract_id']);
    if ((int)$c['current_version'] !== (int)$a['version_id'] || !hash_equals((string)$a['sha256'], (string)$a['v_sha'])) throw new RuntimeException('The document changed after the request; it needs a new request.');
    $note = ct_txt($note, 500); if (!$ok && $note === '') throw new RuntimeException('Sending back needs a note.');
    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE ct_approval SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'pending'"); $up->execute([$ok ? 'approved' : 'returned', (int)$u['id'], $note, $aid]);
        if (!$up->rowCount()) throw new RuntimeException('This request is no longer waiting.');
        $db->prepare("UPDATE ct_contract SET status = ?, approved_version = ? WHERE id = ? AND status = 'pending'")->execute([$ok ? 'approved' : 'draft', $ok ? (int)$a['version_id'] : null, (int)$a['contract_id']]);
        ct_log($db, (int)$u['id'], 'contract', (int)$a['contract_id'], (int)$c['party_id'], $ok ? 'approved' : 'sent back', 'v' . $a['no'] . ($note !== '' ? ' · ' . $note : ''));
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], $ok ? 'ct_approved' : 'ct_returned', '#' . $a['contract_id'] . ' v' . $a['no']);
    ct_tell($db, (int)$a['requested_by'], $ok ? 'Agreement approved' : 'Agreement sent back', $c['ref'] . ($note !== '' ? ' · ' . $note : ''), 'index.php?view=c&id=' . (int)$a['contract_id']);
}
function ct_approval_withdraw(PDO $db, array $u, int $cid): void {
    $a = ct_open_approval($db, $cid); if (!$a) throw new RuntimeException('Nothing is waiting for approval.');
    if ((int)$a['requested_by'] !== (int)$u['id']) throw new RuntimeException('Only the person who asked can withdraw the request.');
    $db->prepare("UPDATE ct_approval SET status = 'withdrawn', decided_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$a['id']]);
    $db->prepare("UPDATE ct_contract SET status = 'draft' WHERE id = ? AND status = 'pending'")->execute([$cid]);
    ct_log($db, (int)$u['id'], 'contract', $cid, null, 'request withdrawn');
}
function ct_contract_cancel(PDO $db, array $u, int $id, string $note): void {
    ct_need($u, 'manage'); $c = ct_contract($db, $id); if (!$c) throw new RuntimeException('Agreement not found.');
    if (in_array($c['status'], ['executed', 'cancelled'], true)) throw new RuntimeException('An executed agreement ends by its term or a termination agreement, not by cancelling.');
    if (($note = ct_txt($note, 500)) === '') throw new RuntimeException('Cancelling needs a reason.');
    $db->prepare("UPDATE ct_approval SET status = 'withdrawn', decided_at = NOW() WHERE contract_id = ? AND status = 'pending'")->execute([$id]);
    $db->prepare("UPDATE ct_contract SET status = 'cancelled', cancel_note = ? WHERE id = ?")->execute([$note, $id]);
    ct_log($db, (int)$u['id'], 'contract', $id, (int)$c['party_id'], 'cancelled', $note);
}
function ct_approvals(PDO $db, int $cid): array { $s = $db->prepare('SELECT a.*, r.name AS req_name, d.name AS dec_name, v.no FROM ct_approval a LEFT JOIN users r ON r.id = a.requested_by LEFT JOIN users d ON d.id = a.decided_by JOIN ct_version v ON v.id = a.version_id WHERE a.contract_id = ? ORDER BY a.id DESC'); $s->execute([$cid]); return $s->fetchAll(); }
function ct_waiting(PDO $db, array $u): array {
    $R = ct_rights($u); $rows = $db->query("SELECT a.*, c.ref, c.title, p.legal_name, v.uploaded_by, v.no FROM ct_approval a JOIN ct_contract c ON c.id = a.contract_id JOIN ct_party p ON p.id = c.party_id JOIN ct_version v ON v.id = a.version_id WHERE a.status = 'pending' ORDER BY a.id")->fetchAll();
    return array_values(array_filter($rows, fn($a) => ct_may_decide($R, (int)$u['id'], (int)$a['requested_by'], (int)$a['uploaded_by'])));
}
function ct_doc_add(PDO $db, array $u, int $pid, string $kind, string $bytes, string $name, array $in = []): int {
    ct_need($u, 'manage'); if (!ct_party($db, $pid)) throw new RuntimeException('Party not found.'); if (!isset(CT_DOC_KINDS[$kind])) throw new RuntimeException('Pick the kind of document.');
    $mime = ct_sniff($bytes); if (!$mime || strlen($bytes) > CT_DOC_MAX) throw new RuntimeException('A document is a PDF, JPEG or PNG of up to 10 MB.');
    $from = ct_date($in['valid_from'] ?? ''); $to = ct_date($in['expires_on'] ?? ''); if ($from && $to && $to < $from) throw new RuntimeException('The end date is before the start.');
    $db->beginTransaction();
    try {
        $st = $db->prepare('INSERT INTO ct_doc (party_id, kind, file, file_name, mime, size, sha256, number_tail, valid_from, expires_on, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->bindValue(1, $pid, PDO::PARAM_INT); $st->bindValue(2, $kind); $st->bindValue(3, $bytes, PDO::PARAM_LOB); $st->bindValue(4, ct_file_name($name, $kind)); $st->bindValue(5, $mime); $st->bindValue(6, strlen($bytes), PDO::PARAM_INT);
        $st->bindValue(7, hash('sha256', $bytes)); $st->bindValue(8, substr(ct_id_norm((string)($in['number'] ?? '')), -4)); $st->bindValue(9, $from); $st->bindValue(10, $to); $st->bindValue(11, (int)$u['id'], PDO::PARAM_INT); $st->execute();
        $did = (int)$db->lastInsertId();
        $db->prepare('UPDATE ct_doc SET replaced_by = ? WHERE party_id = ? AND kind = ? AND id <> ? AND replaced_by IS NULL')->execute([$did, $pid, $kind, $did]);
        ct_log($db, (int)$u['id'], 'party', $pid, $pid, 'document added', CT_DOC_KINDS[$kind]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $did;
}
/** Verify or reject — never by the person who uploaded it. */
function ct_doc_check(PDO $db, array $u, int $id, bool $ok, string $remark = ''): void {
    ct_need($u, 'manage'); $s = $db->prepare('SELECT * FROM ct_doc WHERE id = ?'); $s->execute([$id]); $d = $s->fetch(); if (!$d) throw new RuntimeException('Document not found.');
    if ((int)$d['uploaded_by'] === (int)$u['id']) throw new RuntimeException('Someone other than the uploader checks a document.');
    if ($d['replaced_by']) throw new RuntimeException('A newer copy replaced this one.');
    $remark = ct_txt($remark, 300); if (!$ok && $remark === '') throw new RuntimeException('Rejecting needs a remark.');
    $db->prepare('UPDATE ct_doc SET status = ?, remark = ?, checked_by = ?, checked_at = NOW() WHERE id = ?')->execute([$ok ? 'verified' : 'rejected', $remark, (int)$u['id'], $id]);
    ct_log($db, (int)$u['id'], 'party', (int)$d['party_id'], (int)$d['party_id'], $ok ? 'document verified' : 'document rejected', CT_DOC_KINDS[$d['kind']] ?? '');
}
function ct_docs(PDO $db, int $pid): array { $s = $db->prepare('SELECT id, kind, file_name, mime, size, number_tail, valid_from, expires_on, status, remark, replaced_by, uploaded_by, uploaded_at, checked_by FROM ct_doc WHERE party_id = ? ORDER BY replaced_by IS NOT NULL, kind, id DESC'); $s->execute([$pid]); return $s->fetchAll(); }
/** [bytes, name, mime] of a version ('v') or document ('d'); view right; every open audited. */
function ct_file(PDO $db, array $u, string $kind, int $id): array {
    ct_need($u, 'view');
    $s = $db->prepare($kind === 'v' ? "SELECT file, file_name, 'application/pdf' AS mime FROM ct_version WHERE id = ?" : 'SELECT file, file_name, mime FROM ct_doc WHERE id = ?'); $s->execute([$id]); $r = $s->fetch();
    if (!$r || !in_array($kind, ['v', 'd'], true)) throw new RuntimeException('Not found.');
    audit((int)$u['id'], 'ct_file_open', $kind . '#' . $id); return [$r['file'], $r['file_name'], $r['mime']];
}
/** Executed agreements and current documents ending soon → one notice per item per band to its owner / uploader. */
function ct_alert_tick(PDO $db, string $today): int {
    $days = ct_settings()['alert_days']; $max = max($days); $n = 0; $ins = $db->prepare('INSERT IGNORE INTO ct_alert (entity, entity_id, expires_on, days) VALUES (?,?,?,?)');
    $q = $db->prepare("SELECT c.id, c.ref, c.expires_on, c.owner_id, c.created_by, p.legal_name FROM ct_contract c JOIN ct_party p ON p.id = c.party_id WHERE c.status = 'executed' AND c.expires_on BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)"); $q->execute([$today, $today, $max]);
    foreach ($q->fetchAll() as $c) { $left = (int)((strtotime($c['expires_on']) - strtotime($today)) / 86400); if (($b = ct_band($left, $days)) === null) continue; $ins->execute(['contract', $c['id'], $c['expires_on'], $b]); if (!$ins->rowCount()) continue;
        foreach (array_unique(array_filter([(int)$c['owner_id'], (int)$c['created_by']])) as $w) { ct_tell($db, $w, "Agreement ends in $left days", $c['ref'] . ' · ' . $c['legal_name'], 'index.php?view=c&id=' . $c['id']); $n++; } }
    $q = $db->prepare("SELECT d.id, d.kind, d.expires_on, d.party_id, d.uploaded_by, p.owner_id, p.legal_name FROM ct_doc d JOIN ct_party p ON p.id = d.party_id WHERE d.replaced_by IS NULL AND d.status <> 'rejected' AND d.expires_on BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)"); $q->execute([$today, $today, $max]);
    foreach ($q->fetchAll() as $d) { $left = (int)((strtotime($d['expires_on']) - strtotime($today)) / 86400); if (($b = ct_band($left, $days)) === null) continue; $ins->execute(['doc', $d['id'], $d['expires_on'], $b]); if (!$ins->rowCount()) continue;
        foreach (array_unique(array_filter([(int)$d['owner_id'], (int)$d['uploaded_by']])) as $w) { ct_tell($db, $w, (CT_DOC_KINDS[$d['kind']] ?? 'Document') . " ends in $left days", $d['legal_name'], 'index.php?view=p&id=' . $d['party_id']); $n++; } }
    return $n;
}
/** Agreements and documents ending within $within days (for the Due tab). */
function ct_due(PDO $db, string $today, int $within = 90): array {
    $q = $db->prepare("SELECT 'contract' AS what, c.id, c.ref AS label, c.expires_on, p.legal_name, p.id AS party_id FROM ct_contract c JOIN ct_party p ON p.id = c.party_id WHERE c.status = 'executed' AND c.expires_on <= DATE_ADD(?, INTERVAL ? DAY)
                        UNION ALL SELECT 'doc', d.id, d.kind, d.expires_on, p.legal_name, p.id FROM ct_doc d JOIN ct_party p ON p.id = d.party_id WHERE d.replaced_by IS NULL AND d.status <> 'rejected' AND d.expires_on <= DATE_ADD(?, INTERVAL ? DAY) ORDER BY expires_on");
    $q->execute([$today, $within, $today, $within]); return $q->fetchAll();
}
