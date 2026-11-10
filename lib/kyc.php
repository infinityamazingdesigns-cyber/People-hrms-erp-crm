<?php
/**
 * KYC & bank — ID numbers and the salary bank account, sealed at rest, masked everywhere, verified by HR.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - Values are sealed (seal(), AES-256-GCM with config seal_key); lists only ever read the masked hints.
 *  - Formats come from the organisation's country (KYC_PACKS); countries without a pack get open formats.
 *  - National ID: only the last 4 characters are ever kept (a full number is checked first where the country has a
 *    check digit, then cut).
 *  - The person sends a change; a change to tax ID, national ID or bank needs its document (on file or attached).
 *    One open change per person (sending again replaces it; Withdraw takes it back).
 *  - HR desk verifies field by field; nobody verifies their own; a change to an already verified bank account is
 *    verified only by the HR Manager or Super Admin. Branch scope applies.
 *  - Full values only on the HR desk's verify screen, and every look is audited. The person sees their own social
 *    security number in full (to check it); everything else masked.
 *  - Documents (PDF / JPEG / PNG / WebP, checked by their bytes, 5 MB) live in the database, opened only by the
 *    person and the HR desk, every look audited; never deleted — switched off with a reason.
 *  - Audit lines carry field names, never values.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const KYC_SECTIONS = ['id' => 'ID', 'social' => 'Social security', 'bank' => 'Bank for salary'];
/** field => [section, needs document kind (or '')] */
const KYC_FIELDS = [
    'tax_id' => ['id', 'tax_id'], 'national_id' => ['id', 'national_id'],
    'social_id' => ['social', ''], 'health_id' => ['social', ''],
    'bank_holder' => ['bank', 'bank'], 'bank_acct' => ['bank', 'bank'], 'bank_code' => ['bank', 'bank'], 'bank_name' => ['bank', ''],
];
const KYC_BANK = ['bank_holder', 'bank_acct', 'bank_code'];
const KYC_OWN_FULL = ['social_id'];
const KYC_DOCS = ['tax_id' => 'Tax ID card', 'national_id' => 'National ID card', 'bank' => 'Cheque / passbook / bank statement',
    'social_id' => 'Social security document', 'photo' => 'Photograph', 'education' => 'Education certificate', 'experience' => 'Experience / relieving letter', 'other' => 'Other'];
const KYC_DOC_MAX = 5 * 1024 * 1024;
const KYC_HR_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];
const KYC_BANK_ROLES = ['SUPERADMIN', 'HRMANAGER'];
const KYC_STATUS = ['hr' => 'With HR', 'approved' => 'Verified', 'partly' => 'Partly verified', 'rejected' => 'Sent back', 'withdrawn' => 'Withdrawn'];

/**
 * Country packs: field => [label, pattern or special check, hint]. 'XX' is used for any country without a pack.
 * Special checks: 'last4' (keep the last 4), 'verhoeff12' (12-digit check digit, then keep the last 4), 'text'.
 */
const KYC_PACKS = [
    'IN' => [
        'tax_id' => ['PAN', '/^[A-Z]{3}[PCHFATBLJG][A-Z]\d{4}[A-Z]$/', '10 characters: 5 letters, 4 digits, 1 letter'],
        'national_id' => ['Aadhaar (last 4 digits kept)', 'verhoeff12', 'Type the full 12 digits or just the last 4'],
        'social_id' => ['UAN (provident fund)', '/^\d{12}$/', '12 digits'],
        'health_id' => ['ESIC number', '/^\d{10}$/', '10 digits, only if you have one'],
        'bank_holder' => ['Account holder name', 'text', 'As printed on the cheque'],
        'bank_acct' => ['Bank account number', '/^\d{9,18}$/', '9 to 18 digits'],
        'bank_code' => ['IFSC', '/^[A-Z]{4}0[A-Z0-9]{6}$/', '11 characters, the 5th is a zero'],
        'bank_name' => ['Bank and branch', 'text', ''],
    ],
    'GB' => [
        'tax_id' => ['National Insurance number', '/^[A-CEGHJ-PR-TW-Z]{2}\d{6}[A-D]$/', 'Like QQ123456C'],
        'bank_acct' => ['Account number', '/^\d{8}$/', '8 digits'],
        'bank_code' => ['Sort code', '/^\d{6}$/', '6 digits'],
    ],
    'US' => [
        'tax_id' => ['SSN (last 4 kept)', 'last4', 'Only the last 4 digits are kept'],
        'bank_acct' => ['Account number', '/^\d{4,17}$/', '4 to 17 digits'],
        'bank_code' => ['Routing number', '/^\d{9}$/', '9 digits'],
    ],
    'XX' => [
        'tax_id' => ['Tax ID', '/^[A-Z0-9-]{4,20}$/', 'Letters and digits'],
        'national_id' => ['National ID (last 4 kept)', 'last4', 'Only the last 4 characters are kept'],
        'social_id' => ['Social security number', '/^[A-Z0-9-]{4,20}$/', 'If your country has one'],
        'health_id' => ['Health insurance number', '/^[A-Z0-9-]{4,20}$/', 'If you have one'],
        'bank_holder' => ['Account holder name', 'text', 'As on the bank record'],
        'bank_acct' => ['Account number / IBAN', '/^[A-Z0-9]{6,34}$/', 'Digits, or the full IBAN'],
        'bank_code' => ['Bank code (SWIFT / routing / sort)', '/^[A-Z0-9-]{4,15}$/', ''],
        'bank_name' => ['Bank and branch', 'text', ''],
    ],
];

/* ---------------- pure ---------------- */

/** The pack for a country: its own rules over the generic ones. */
function kyc_pack(string $country): array { return array_replace(KYC_PACKS['XX'], KYC_PACKS[$country] ?? []); }

function kyc_label(string $k, string $country): string { return kyc_pack($country)[$k][0] ?? $k; }

function kyc_verhoeff(string $num): bool {
    static $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
                 [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    static $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,0,7,8,6],
                 [4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    $c = 0; $r = strrev($num);
    for ($i = 0, $n = strlen($r); $i < $n; $i++) $c = $d[$c][$p[$i % 8][(int)$r[$i]]];
    return $c === 0;
}

/** Check one value. Returns [clean, error]. */
function kyc_check(string $k, string $v, string $country): array {
    $v = trim($v);
    if ($v === '') return ['', ''];
    $rule = kyc_pack($country)[$k] ?? null;
    if (!$rule) return ['', ''];
    [$label, $check, $hint] = $rule;
    if ($check === 'text') return [mb_substr(strip_tags(preg_replace('/\s+/u', ' ', $v)), 0, 80), ''];
    $c = strtoupper(preg_replace('/[\s\/.-]+/', '', $v));
    if ($check === 'last4') return strlen($c) >= 4 && ctype_alnum($c) ? [substr($c, -4), ''] : ['', "$label: at least the last 4 characters"];
    if ($check === 'verhoeff12') {
        if (preg_match('/^\d{4}$/', $c)) return [$c, ''];
        if (preg_match('/^[2-9]\d{11}$/', $c) && kyc_verhoeff($c)) return [substr($c, -4), ''];
        return ['', "$label: the 12-digit number does not check out — or give only the last 4 digits"];
    }
    return preg_match($check, $c) ? [$c, ''] : ['', $label . ($hint ? ': ' . $hint : ': not in the right format')];
}

/** Clean a whole form: only fields that were filled. Returns [values, errors]. */
function kyc_clean(array $in, string $country): array {
    $out = []; $err = [];
    foreach (array_keys(KYC_FIELDS) as $k) {
        [$v, $e] = kyc_check($k, (string)($in[$k] ?? ''), $country);
        if ($e) $err[] = $e; elseif ($v !== '') $out[$k] = $v;
    }
    return [$out, $err];
}

function kyc_mask_one(string $k, string $v): string {
    if ($v === '') return '';
    if (in_array($k, ['bank_holder', 'bank_name'], true)) return $v;
    if ($k === 'national_id') return 'XXXX-' . $v;
    return str_repeat('X', max(0, strlen($v) - 4)) . substr($v, -4);
}
function kyc_hints(array $vals): array { $o = []; foreach ($vals as $k => $v) $o[$k] = kyc_mask_one($k, (string)$v); return $o; }

/** Document kinds a change still needs: $vals = changed fields, $have = kinds on file or attached. */
function kyc_docs_missing(array $vals, array $have): array {
    $need = [];
    foreach (array_keys($vals) as $k) if (($d = KYC_FIELDS[$k][1] ?? '') !== '' && !in_array($d, $have, true)) $need[$d] = true;
    return array_keys($need);
}

function kyc_doc_mime(string $bytes): ?string {
    if (str_starts_with($bytes, '%PDF-')) return 'application/pdf';
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) return 'image/png';
    if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
    return null;
}

/** May this login verify the accepted fields, given what is already verified? */
function kyc_can_verify(array $u, array $acc, array $verified): bool {
    $bankChange = array_intersect($acc, KYC_BANK) && array_intersect(array_keys($verified), KYC_BANK);
    return !$bankChange || has_role($u, ...KYC_BANK_ROLES);
}

/* ---------------- database ---------------- */

function kyc_is_hr(array $u): bool { return has_role($u, ...KYC_HR_ROLES); }
function kyc_country(): string { return org('country') ?: 'XX'; }

/** ['values' => (only when $full), 'hints', 'verified'] */
function kyc_record(PDO $db, string $code, bool $full = false): array {
    $s = $db->prepare('SELECT * FROM employee_kyc WHERE emp_code = ?');
    $s->execute([strtoupper($code)]);
    $r = $s->fetch();
    if (!$r) return ['values' => [], 'hints' => [], 'verified' => []];
    return ['values' => $full ? (json_decode(unseal((string)$r['box']), true) ?: []) : [],
            'hints' => json_decode((string)$r['hints'], true) ?: [], 'verified' => json_decode((string)$r['verified'], true) ?: []];
}

function kyc_docs(PDO $db, string $code): array {
    $s = $db->prepare('SELECT id, kind, mime, size, added_at, change_id FROM employee_doc WHERE emp_code = ? AND removed_at IS NULL ORDER BY id DESC');
    $s->execute([strtoupper($code)]);
    return $s->fetchAll();
}

/** Read uploads: $_FILES['doc'] arrays + kinds in $_POST['doc_kind'][]. Returns [list of [kind, bytes, mime], errors]. */
function kyc_uploads(array $files, array $kinds): array {
    $out = []; $err = [];
    foreach ((array)($files['tmp_name'] ?? []) as $i => $tmp) {
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;
        $kind = (string)($kinds[$i] ?? '');
        if (!isset(KYC_DOCS[$kind])) { $err[] = 'Pick what each document is.'; continue; }
        if (filesize($tmp) > KYC_DOC_MAX) { $err[] = 'A document is over 5 MB.'; continue; }
        $bytes = (string)file_get_contents($tmp);
        if (!($m = kyc_doc_mime($bytes))) { $err[] = 'Documents: PDF, JPEG, PNG or WebP only.'; continue; }
        $out[] = [$kind, $bytes, $m];
    }
    return [$out, $err];
}

function kyc_doc_add(PDO $db, string $code, string $kind, string $bytes, string $mime, ?int $by, ?int $changeId = null): int {
    $db->prepare('INSERT INTO employee_doc (emp_code, kind, mime, bytes, sha256, size, change_id, added_by) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([strtoupper($code), $kind, $mime, $bytes, hash('sha256', $bytes), strlen($bytes), $changeId, $by]);
    return (int)$db->lastInsertId();
}

function kyc_open(PDO $db, int $uid): ?array {
    $s = $db->prepare("SELECT * FROM employee_kyc_change WHERE user_id = ? AND status = 'hr' ORDER BY id DESC LIMIT 1");
    $s->execute([$uid]);
    $r = $s->fetch();
    if (!$r) return null;
    $r['hints'] = json_decode((string)$r['hints'], true) ?: [];
    return $r;
}

/** Person sends a change (with documents). Returns change id. */
function kyc_submit(PDO $db, array $u, array $emp, array $vals, array $uploads): int {
    $code = strtoupper($emp['code']);
    $have = array_merge(array_column(kyc_docs($db, $code), 'kind'), array_column($uploads, 0));
    if ($miss = kyc_docs_missing($vals, $have)) throw new RuntimeException('Attach: ' . implode(', ', array_map(fn($k) => KYC_DOCS[$k], $miss)) . '.');
    $cur = kyc_record($db, $code, true)['values'];
    $vals = array_filter($vals, fn($v, $k) => ($cur[$k] ?? '') !== $v, ARRAY_FILTER_USE_BOTH);
    if (!$vals && !$uploads) throw new RuntimeException('Nothing changed.');
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE employee_kyc_change SET status = 'withdrawn' WHERE user_id = ? AND status = 'hr'")->execute([(int)$u['id']]);
        $db->prepare('INSERT INTO employee_kyc_change (user_id, emp_code, branch_id, box, hints) VALUES (?,?,?,?,?)')
           ->execute([(int)$u['id'], $code, $emp['branch_id'], seal(json_encode($vals)), json_encode(kyc_hints($vals))]);
        $id = (int)$db->lastInsertId();
        foreach ($uploads as [$kind, $bytes, $mime]) kyc_doc_add($db, $code, $kind, $bytes, $mime, (int)$u['id'], $id);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'kyc_sent', '#' . $id . ' ' . implode(',', array_keys($vals)) . ' docs:' . count($uploads));
    return $id;
}

function kyc_withdraw(PDO $db, array $u, int $id): bool {
    $s = $db->prepare("UPDATE employee_kyc_change SET status = 'withdrawn' WHERE id = ? AND user_id = ? AND status = 'hr'");
    $s->execute([$id, (int)$u['id']]);
    if ($s->rowCount()) audit((int)$u['id'], 'kyc_withdrawn', '#' . $id);
    return $s->rowCount() > 0;
}

function kyc_waiting(PDO $db, array $u): array {
    $out = [];
    foreach ($db->query("SELECT c.*, e.name FROM employee_kyc_change c LEFT JOIN employee e ON e.code = c.emp_code WHERE c.status = 'hr' ORDER BY c.id") as $r) {
        if ((int)$r['user_id'] === (int)$u['id'] || strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) continue;
        if (!emp_in_scope($u, ['branch_id' => $r['branch_id']])) continue;
        $out[] = $r;
    }
    return $out;
}

/** Full values of a waiting change, for the HR desk only. Audited. */
function kyc_change_full(PDO $db, array $u, array $r): array {
    if (!kyc_is_hr($u)) return [];
    audit((int)$u['id'], 'kyc_view', '#' . $r['id'] . ' ' . $r['emp_code']);
    return json_decode(unseal((string)$r['box']), true) ?: [];
}

function kyc_decide(PDO $db, array $u, int $id, array $accept, string $note): string {
    if (!kyc_is_hr($u)) throw new RuntimeException('Only the HR desk verifies KYC.');
    $note = trim(mb_substr(strip_tags($note), 0, 500));
    $db->beginTransaction();
    try {
        $s = $db->prepare('SELECT * FROM employee_kyc_change WHERE id = ? FOR UPDATE'); $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) throw new RuntimeException('Change not found.');
        if ((int)$r['user_id'] === (int)$u['id'] || strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('You cannot verify your own KYC.');
        if (!emp_in_scope($u, ['branch_id' => $r['branch_id']])) throw new RuntimeException('This person is outside your branches.');
        if ($r['status'] !== 'hr') throw new RuntimeException('This change is already decided.');
        $vals = json_decode(unseal((string)$r['box']), true) ?: [];
        $acc = array_values(array_intersect(array_keys($vals), $accept));
        $rec = kyc_record($db, $r['emp_code'], true);
        if (!kyc_can_verify($u, $acc, $rec['verified'])) throw new RuntimeException('A change to verified bank details is verified by the HR Manager or Super Admin.');
        if (!$acc && $note === '' && $vals) throw new RuntimeException('Write why it is sent back.');
        if ($acc) {
            $new = array_merge($rec['values'], array_intersect_key($vals, array_flip($acc)));
            $ver = $rec['verified'];
            foreach ($acc as $k) $ver[$k] = ['by' => (int)$u['id'], 'at' => date('Y-m-d H:i')];
            $db->prepare('INSERT INTO employee_kyc (emp_code, box, hints, verified, updated_by) VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE box = VALUES(box), hints = VALUES(hints), verified = VALUES(verified), updated_by = VALUES(updated_by)')
               ->execute([$r['emp_code'], seal(json_encode($new)), json_encode(kyc_hints($new)), json_encode($ver), (int)$u['id']]);
        }
        $status = !$vals ? 'approved' : (!$acc ? 'rejected' : (count($acc) === count($vals) ? 'approved' : 'partly'));
        $db->prepare('UPDATE employee_kyc_change SET status = ?, accepted = ?, hr_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?')
           ->execute([$status, json_encode($acc), $note, (int)$u['id'], $id]);
        if ($emp = emp_by_code($db, $r['emp_code'])) {
            $log = $db->prepare('INSERT INTO employee_log (emp_id, field, old_value, new_value, kind, note, by_id) VALUES (?,?,?,?,?,?,?)');
            foreach ($vals as $k => $v) $log->execute([$emp['id'], 'kyc.' . $k, kyc_mask_one($k, (string)($rec['values'][$k] ?? '')),
                in_array($k, $acc, true) ? kyc_mask_one($k, (string)$v) : 'declined', 'kyc', $note, (int)$u['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'kyc_' . $status, '#' . $id . ' verified: ' . implode(',', $acc));
    return $status;
}

/** Stream a document if this login may open it (owner or HR desk in scope). Audited. */
function kyc_doc_send(PDO $db, array $u, int $docId): void {
    $s = $db->prepare('SELECT d.*, e.branch_id FROM employee_doc d LEFT JOIN employee e ON e.code = d.emp_code WHERE d.id = ? AND d.removed_at IS NULL');
    $s->execute([$docId]);
    $d = $s->fetch();
    $own = $d && strcasecmp($d['emp_code'], (string)($u['emp_code'] ?? '')) === 0;
    if (!$d || (!$own && !(kyc_is_hr($u) && emp_in_scope($u, ['branch_id' => $d['branch_id']])))) { http_response_code(404); exit('Not found'); }
    audit((int)$u['id'], 'kyc_doc_view', '#' . $docId . ' ' . $d['emp_code']);
    header('Content-Type: ' . $d['mime']);
    header('Content-Disposition: inline; filename="' . $d['kind'] . '-' . $docId . '"');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    header('X-Content-Type-Options: nosniff');
    echo $d['bytes'];
    exit;
}
