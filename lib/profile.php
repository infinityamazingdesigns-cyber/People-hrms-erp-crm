<?php
/**
 * My profile — each person keeps their own personal details; HR verifies before anything changes.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - The person edits only personal fields (PF_FIELDS). Employment details (code, name, department, designation,
 *    branch, joining date, manager) are read-only from the employee master; a wrong one is reported as a note.
 *  - Sending creates one open change per person (sending again replaces it; Withdraw takes it back).
 *  - The HR desk (Super Admin, HR Manager, HR Operations) accepts field by field; unticked fields are declined;
 *    sending everything back needs a note. Nobody decides their own change. Branch scope applies.
 *  - Accepted date of birth and gender are copied to the employee master; every accepted / declined field is
 *    written to the employee history.
 *  - Anniversary and spouse only for married people; the emergency contact must not be the person's own number;
 *    the personal email must not be on the organisation's own mail domain.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const PF_SECTIONS = ['about' => 'About you', 'contact' => 'Personal contact', 'address' => 'Address', 'emergency' => 'Emergency contact',
    'nominee' => 'Nominee', 'education' => 'Education & experience'];
const PF_GENDER = ['Female', 'Male', 'Other'];
const PF_MARITAL = ['Single', 'Married', 'Widowed', 'Divorced', 'Separated'];
const PF_BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
/** field => [label, section, type, option (max length / list), hint] */
const PF_FIELDS = [
    'dob' => ['Date of birth', 'about', 'date', 0, ''],
    'gender' => ['Gender', 'about', 'select', PF_GENDER, ''],
    'marital' => ['Marital status', 'about', 'select', PF_MARITAL, ''],
    'anniversary' => ['Wedding anniversary', 'about', 'date', 0, 'Only if married'],
    'blood' => ['Blood group', 'about', 'select', PF_BLOOD, ''],
    'father_name' => ["Father's / guardian's name", 'about', 'text', 80, ''],
    'spouse_name' => ["Spouse's name", 'about', 'text', 80, 'Only if married'],
    'mobile' => ['Personal mobile', 'contact', 'tel', 0, ''],
    'alt_mobile' => ['Other mobile', 'contact', 'tel', 0, 'Optional'],
    'personal_email' => ['Personal email', 'contact', 'email', 120, 'Not your work email'],
    'cur_addr' => ['Current address', 'address', 'area', 300, ''],
    'cur_pin' => ['Current postal code', 'address', 'pin', 0, ''],
    'perm_addr' => ['Permanent address', 'address', 'area', 300, ''],
    'perm_pin' => ['Permanent postal code', 'address', 'pin', 0, ''],
    'em_name' => ['Name', 'emergency', 'text', 80, ''],
    'em_relation' => ['Relation', 'emergency', 'text', 40, 'For example: father, spouse'],
    'em_phone' => ['Mobile', 'emergency', 'tel', 0, ''],
    'nom_name' => ['Nominee name', 'nominee', 'text', 80, 'For provident fund and insurance'],
    'nom_relation' => ['Relation', 'nominee', 'text', 40, ''],
    'qualification' => ['Highest qualification', 'education', 'text', 80, ''],
    'institute' => ['College / institute', 'education', 'text', 120, ''],
    'pass_year' => ['Year passed', 'education', 'year', 0, ''],
    'prev_employer' => ['Last employer before joining', 'education', 'text', 120, 'Leave empty if this is your first job'],
    'exp_years' => ['Experience before joining (years)', 'education', 'num', 0, 'For example: 3 or 2.5'],
    'languages' => ['Languages known', 'education', 'text', 120, ''],
];
const PF_HR_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];
const PF_STATUS = ['hr' => 'Waiting for HR', 'approved' => 'Approved', 'partly' => 'Partly approved', 'rejected' => 'Sent back', 'withdrawn' => 'Withdrawn'];

/* ---------------- pure ---------------- */

/** Phone number: digits with an optional leading +, 7–15 digits. */
function pf_phone(string $v): ?string {
    $d = preg_replace('/[\s().-]/', '', $v);
    return preg_match('/^\+?\d{7,15}$/', $d) ? $d : null;
}

/** Clean the whole form. $workDomain = the organisation's own mail domain (personal email may not use it). */
function pf_clean(array $in, string $workDomain = '', ?string $today = null): array {
    $today ??= date('Y-m-d');
    $out = []; $err = [];
    foreach (PF_FIELDS as $k => [$label, $sec, $type, $opt]) {
        $raw = $in[$k] ?? '';
        $v = is_scalar($raw) ? trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', (string)$raw)) ?? '') : '';
        if ($type !== 'area') $v = str_replace("\n", ' ', $v);
        $v = strip_tags($v);
        $name = PF_SECTIONS[$sec] . ' · ' . $label;
        if ($v !== '') switch ($type) {
            case 'date':
                $t = DateTime::createFromFormat('!Y-m-d', $v);
                if (!$t || $t->format('Y-m-d') !== $v) { $err[] = "$name: choose a date"; $v = ''; break; }
                if ($v > $today) { $err[] = "$name cannot be in the future"; $v = ''; break; }
                if ($k === 'dob') { $age = $t->diff(new DateTime($today))->y; if ($age < 14 || $age > 90) { $err[] = "$name: check the year"; $v = ''; } }
                break;
            case 'select': if (!in_array($v, $opt, true)) { $err[] = "$name: choose from the list"; $v = ''; } break;
            case 'tel': $p = pf_phone($v); if ($p === null) { $err[] = "$name: give a full phone number"; $v = ''; } else $v = $p; break;
            case 'email':
                $v = strtolower($v);
                if (strlen($v) > $opt || !filter_var($v, FILTER_VALIDATE_EMAIL)) { $err[] = "$name: give a full email address"; $v = ''; }
                elseif ($workDomain !== '' && str_ends_with($v, '@' . strtolower($workDomain))) { $err[] = "$name: give your own email, not the work one"; $v = ''; }
                break;
            case 'pin': $v = strtoupper(preg_replace('/\s+/', '', $v)); if (!preg_match('/^[A-Z0-9-]{3,10}$/', $v)) { $err[] = "$name: letters and digits only"; $v = ''; } break;
            case 'year': $y = (int)substr($today, 0, 4); if (!preg_match('/^\d{4}$/', $v) || (int)$v < 1950 || (int)$v > $y) { $err[] = "$name: a year like 2018"; $v = ''; } break;
            case 'num':
                $v = str_replace(',', '.', $v);
                if (!preg_match('/^\d{1,2}(\.\d)?$/', $v) || (float)$v > 50) { $err[] = "$name: years, for example 3 or 2.5"; $v = ''; }
                else $v = rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
                break;
            default: if (mb_strlen($v) > $opt) { $err[] = "$name: at most $opt characters"; $v = mb_substr($v, 0, (int)$opt); }
        }
        $out[$k] = $v;
    }
    if ($out['marital'] !== 'Married') { $out['anniversary'] = ''; $out['spouse_name'] = ''; }
    if ($out['mobile'] !== '' && $out['mobile'] === $out['alt_mobile']) $out['alt_mobile'] = '';
    if ($out['em_phone'] !== '' && $out['em_phone'] === $out['mobile']) $err[] = 'Emergency contact · Mobile: give someone else\'s number, not your own';
    return [$out, $err];
}

/** field => [old, new] for fields that changed. */
function pf_diff(array $old, array $new): array {
    $d = [];
    foreach (PF_FIELDS as $k => $_) if ((string)($old[$k] ?? '') !== (string)($new[$k] ?? '')) $d[$k] = [(string)($old[$k] ?? ''), (string)($new[$k] ?? '')];
    return $d;
}

/** [filled, total] — how complete a profile is. */
function pf_completeness(array $p): array {
    $req = array_diff(array_keys(PF_FIELDS), ['alt_mobile', 'anniversary', 'spouse_name', 'prev_employer', 'exp_years', 'languages']);
    if (($p['marital'] ?? '') === 'Married') $req[] = 'spouse_name';
    return [count(array_filter($req, fn($k) => trim((string)($p[$k] ?? '')) !== '')), count($req)];
}

/** Apply HR's decision to a profile: returns [new profile, accepted, declined, status]. */
function pf_apply(array $profile, array $fields, array $accept): array {
    $acc = array_values(array_intersect(array_keys($fields), $accept));
    $dec = array_values(array_diff(array_keys($fields), $acc));
    foreach ($acc as $k) $profile[$k] = (string)$fields[$k][1];
    if (($profile['marital'] ?? '') !== 'Married') { $profile['anniversary'] = ''; $profile['spouse_name'] = ''; }
    $status = !$acc ? 'rejected' : ($dec ? 'partly' : 'approved');
    return [$profile, $acc, $dec, $status];
}

/* ---------------- database ---------------- */

function pf_is_hr(array $u): bool { return has_role($u, ...PF_HR_ROLES); }

function pf_work_domain(): string {
    $e = org('support_email');
    return str_contains($e, '@') ? substr(strrchr($e, '@'), 1) : '';
}

function pf_profile(PDO $db, string $code): array {
    $s = $db->prepare('SELECT data FROM employee_profile WHERE emp_code = ?');
    $s->execute([strtoupper($code)]);
    return json_decode((string)$s->fetchColumn(), true) ?: [];
}

function pf_row(array $r): array {
    $r['fields'] = json_decode((string)$r['fields'], true) ?: [];
    $r['accepted'] = json_decode((string)($r['accepted'] ?? ''), true) ?: [];
    return $r;
}

function pf_open(PDO $db, int $uid): ?array {
    $s = $db->prepare("SELECT * FROM employee_profile_change WHERE user_id = ? AND status = 'hr' ORDER BY id DESC LIMIT 1");
    $s->execute([$uid]);
    return ($r = $s->fetch()) ? pf_row($r) : null;
}

/** Send (or replace) the person's open change. Returns the change id, 0 when nothing changed. */
function pf_submit(PDO $db, array $u, array $emp, array $clean, string $note): int {
    $note = trim(mb_substr(strip_tags($note), 0, 500));
    $diff = pf_diff(pf_profile($db, $emp['code']), $clean);
    if (!$diff && $note === '') return 0;
    $db->beginTransaction();
    $db->prepare("UPDATE employee_profile_change SET status = 'withdrawn' WHERE user_id = ? AND status = 'hr'")->execute([(int)$u['id']]);
    $db->prepare('INSERT INTO employee_profile_change (user_id, emp_code, branch_id, fields, note) VALUES (?,?,?,?,?)')
       ->execute([(int)$u['id'], strtoupper($emp['code']), $emp['branch_id'], json_encode($diff, JSON_UNESCAPED_UNICODE), $note]);
    $id = (int)$db->lastInsertId();
    $db->commit();
    audit((int)$u['id'], 'profile_sent', '#' . $id . ' ' . implode(',', array_keys($diff)));
    return $id;
}

function pf_withdraw(PDO $db, array $u, int $id): bool {
    $s = $db->prepare("UPDATE employee_profile_change SET status = 'withdrawn' WHERE id = ? AND user_id = ? AND status = 'hr'");
    $s->execute([$id, (int)$u['id']]);
    if ($s->rowCount()) audit((int)$u['id'], 'profile_withdrawn', '#' . $id);
    return $s->rowCount() > 0;
}

/** Waiting changes in this HR login's scope (never their own). */
function pf_waiting(PDO $db, array $u): array {
    $out = [];
    foreach ($db->query("SELECT c.*, e.name, e.designation FROM employee_profile_change c LEFT JOIN employee e ON e.code = c.emp_code WHERE c.status = 'hr' ORDER BY c.id") as $r) {
        if ((int)$r['user_id'] === (int)$u['id'] || strcasecmp((string)$r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) continue;
        if (!emp_in_scope($u, ['branch_id' => $r['branch_id']])) continue;
        $out[] = pf_row($r);
    }
    return $out;
}

/** HR decides a change. Returns the new status. */
function pf_decide(PDO $db, array $u, int $id, array $accept, string $note): string {
    if (!pf_is_hr($u)) throw new RuntimeException('Only the HR desk decides profile changes.');
    $note = trim(mb_substr(strip_tags($note), 0, 500));
    $db->beginTransaction();
    try {
        $s = $db->prepare('SELECT * FROM employee_profile_change WHERE id = ? FOR UPDATE'); $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) throw new RuntimeException('Change not found.');
        $r = pf_row($r);
        if ((int)$r['user_id'] === (int)$u['id'] || strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('You cannot decide a change to your own profile.');
        if (!emp_in_scope($u, ['branch_id' => $r['branch_id']])) throw new RuntimeException('This person is outside your branches.');
        if ($r['status'] !== 'hr') throw new RuntimeException('This change is already decided.');
        $before = pf_profile($db, $r['emp_code']);
        [$after, $acc, $dec, $status] = pf_apply($before, $r['fields'], $accept);
        if (!$acc && $note === '' && $r['fields']) throw new RuntimeException('Write why it is sent back.');
        if (!$r['fields']) $status = 'approved';   // only a note: HR has answered it
        if ($acc) $db->prepare('INSERT INTO employee_profile (emp_code, data, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by = VALUES(updated_by)')
                     ->execute([$r['emp_code'], json_encode($after, JSON_UNESCAPED_UNICODE), (int)$u['id']]);
        $db->prepare('UPDATE employee_profile_change SET status = ?, accepted = ?, hr_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?')
           ->execute([$status, json_encode($acc), $note, (int)$u['id'], $id]);
        $emp = emp_by_code($db, $r['emp_code']);
        if ($emp) {
            $log = $db->prepare('INSERT INTO employee_log (emp_id, field, old_value, new_value, kind, note, by_id) VALUES (?,?,?,?,?,?,?)');
            foreach ($acc as $k) $log->execute([$emp['id'], 'profile.' . $k, mb_substr($r['fields'][$k][0], 0, 400), mb_substr($r['fields'][$k][1], 0, 400), 'profile', $note, (int)$u['id']]);
            foreach ($dec as $k) $log->execute([$emp['id'], 'profile.' . $k, '', 'declined', 'profile', $note, (int)$u['id']]);
            $sync = array_intersect_key(['dob' => $after['dob'] ?? '', 'gender' => $after['gender'] ?? ''], array_flip($acc));
            if ($sync) $db->prepare('UPDATE employee SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($sync))) . ' WHERE id = ?')
                          ->execute([...array_map(fn($k, $v) => $v === '' && $k === 'dob' ? null : $v, array_keys($sync), array_values($sync)), $emp['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'profile_' . $status, '#' . $id . ' accepted: ' . implode(',', $acc));
    return $status;
}

/** The person's own changes, newest first. */
function pf_history(PDO $db, int $uid, int $limit = 10): array {
    $s = $db->prepare('SELECT * FROM employee_profile_change WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
    $s->execute([$uid]);
    return array_map('pf_row', $s->fetchAll());
}
