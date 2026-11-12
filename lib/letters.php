<?php
/**
 * Letters — templates, letters, approval, issue, answer, verify, employee requests.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - HR keeps the templates: plain text with {{fields}}. Fields from the person's record fill themselves (name, code,
 *    designation, department, branch, joining / confirmation / last day, company, today); others are typed per letter.
 *    A letter cannot be issued while a field is empty.
 *  - Each template names its signatory role. Who signs = the signatory set for that role on the letter's date (kept by
 *    valid-from date; the letter keeps the name it was issued with). When the template needs approval, a login with
 *    that role approves it first; nobody approves a letter they made or one about themselves. Sending back needs a
 *    note. Changing an approved letter needs approval again.
 *  - Issue freezes the text (SHA-256 kept), gives a reference (<CODE>/<year>/<0001>) and a 10-character verification
 *    code printed on the letter. Letters needing an answer get a private link (only its SHA-256 kept, 60 days); the
 *    person types their name to accept / decline / acknowledge (time and address kept). Offers past "accept by" lapse.
 *  - verify.php shows type, reference, name, designation and date only — never pay or contact details.
 *  - Employees see their own issued letters (by their linked code) and ask HR for a letter (open request; issuing the
 *    letter closes it).
 *  - Nothing is deleted: a letter is withdrawn with a reason; every step is in letter_log.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const LET_RESP = ['none' => 'No answer needed', 'accept' => 'Accept / decline', 'ack' => 'Acknowledge'];
const LET_ANSWER = ['accept' => ['yes' => 'Accepted', 'no' => 'Declined'], 'ack' => ['yes' => 'Acknowledged']];
const LET_STATUS = ['draft' => 'Draft', 'approval' => 'Waiting for approval', 'approved' => 'Approved', 'issued' => 'Issued', 'withdrawn' => 'Withdrawn', 'lapsed' => 'Lapsed'];
const LET_HR_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];
const LET_LINK_DAYS = 60;
const LET_AUTO_FIELDS = ['name', 'code', 'designation', 'department', 'branch', 'doj', 'confirm_date', 'last_day', 'company', 'date', 'ref', 'probation_months'];

/* ---------------- pure ---------------- */

/** {{fields}} used in a body, in order, once each. */
function let_fields(string $body): array {
    preg_match_all('/\{\{\s*([a-z][a-z0-9_]{0,30})\s*\}\}/', $body, $m);
    return array_values(array_unique($m[1]));
}

/** Fields still empty. */
function let_missing(string $body, array $vars): array {
    return array_values(array_filter(let_fields($body), fn($f) => trim((string)($vars[$f] ?? '')) === ''));
}

/** Body → HTML: blank line = paragraph, "- " = bullet, **bold**; every value escaped. Empty fields show as [field]. */
function let_render(string $body, array $vars): string {
    $fill = fn(string $t) => preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]{0,30})\s*\}\}/', function ($m) use ($vars) {
        $v = trim((string)($vars[$m[1]] ?? ''));
        return $v === '' ? '<mark>[' . h($m[1]) . ']</mark>' : h($v);
    }, h($t));
    $bold = fn(string $t) => preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $t);
    $out = '';
    foreach (preg_split('/\R\s*\R/', trim(str_replace("\r", '', $body))) as $para) {
        $text = []; $list = [];
        $flush = function () use (&$text, &$list, &$out) {
            if ($text) $out .= '<p>' . implode('<br>', $text) . '</p>';
            if ($list) $out .= '<ul><li>' . implode('</li><li>', $list) . '</li></ul>';
            $text = []; $list = [];
        };
        foreach (preg_split('/\R/', $para) as $line) {
            if (str_starts_with(ltrim($line), '- ')) { if ($text) $flush(); $list[] = $bold($fill(substr(ltrim($line), 2))); }
            else { if ($list) $flush(); $text[] = $bold($fill($line)); }
        }
        $flush();
    }
    return $out;
}

/** Values from the employee record and the organisation. Dates as "12 November 2026". */
function let_auto_vars(?array $emp, string $branchName, string $company, int $probationMonths, string $today): array {
    $d = fn($v) => $v ? date('j F Y', strtotime((string)$v)) : '';
    return array_filter(['name' => $emp['name'] ?? '', 'code' => $emp['code'] ?? '', 'designation' => $emp['designation'] ?? '', 'department' => $emp['department'] ?? '',
        'branch' => $branchName, 'doj' => $d($emp['doj'] ?? null), 'confirm_date' => $d($emp['confirm_date'] ?? null), 'last_day' => $d($emp['last_day'] ?? null),
        'company' => $company, 'date' => $d($today), 'probation_months' => (string)$probationMonths], fn($v) => $v !== '');
}

/** "OFFER/2026/0007". */
function let_ref(string $code, int $year, int $n): string { return sprintf('%s/%d/%04d', $code, $year, $n); }

/** 10-character code without look-alike characters. */
function let_code(): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $a[random_int(0, 31)];
    return $s;
}

/** Signatory for a role on a day: newest active row with from_day ≤ day. */
function let_signer_on(array $rows, string $role, string $day): ?array {
    $pick = null;
    foreach ($rows as $r) if ((int)$r['active'] && $r['role_code'] === $role && $r['from_day'] <= $day && (!$pick || $r['from_day'] >= $pick['from_day'])) $pick = $r;
    return $pick;
}

/** Same first word (and the rest) — for the typed name on an answer. Pure. */
function let_name_ok(string $typed, string $person): bool { return emp_same_person($typed, $person); }

/** Check a template form. Pure. */
function let_template_check(array $in, array $roleCodes): array {
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    if (!preg_match('/^[A-Z][A-Z0-9]{1,11}$/', $code)) throw new InvalidArgumentException('Code: 2–12 letters or digits, starting with a letter (used in the reference).');
    $name = trim(strip_tags((string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Give a name up to 80 characters.');
    $body = trim(str_replace("\r", '', (string)($in['body'] ?? '')));
    if (mb_strlen($body) < 20) throw new InvalidArgumentException('Write the letter text.');
    if (mb_strlen($body) > 20000) throw new InvalidArgumentException('The text is too long.');
    if (preg_match('/<\s*script|javascript:/i', $body)) throw new InvalidArgumentException('Plain text only.');
    $resp = (string)($in['resp'] ?? 'none');
    if (!isset(LET_RESP[$resp])) throw new InvalidArgumentException('Pick the answer needed.');
    $role = (string)($in['signer_role'] ?? '');
    if (!in_array($role, $roleCodes, true)) throw new InvalidArgumentException('Pick who signs.');
    return ['code' => $code, 'name' => $name, 'body' => $body, 'resp' => $resp, 'signer_role' => $role,
            'needs_approval' => !empty($in['needs_approval']) ? 1 : 0, 'for_employees' => !empty($in['for_employees']) ? 1 : 0];
}

/* ---------------- data ---------------- */

function let_is_hr(array $u): bool { return has_role($u, ...LET_HR_ROLES); }
function let_own(array $u, array $l): bool { return $l['emp_code'] !== '' && strcasecmp($l['emp_code'], (string)($u['emp_code'] ?? '')) === 0; }

function let_templates(PDO $db, bool $activeOnly = true): array {
    return $db->query('SELECT * FROM letter_template' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name')->fetchAll();
}
function let_template(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM letter_template WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function let_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM letter WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function let_signers(PDO $db): array { return $db->query('SELECT * FROM letter_signer ORDER BY role_code, from_day DESC, id DESC')->fetchAll(); }

function let_log(PDO $db, int $id, string $text, ?int $by): void {
    $db->prepare('INSERT INTO letter_log (letter_id, text, by_user) VALUES (?,?,?)')->execute([$id, mb_substr($text, 0, 300), $by]);
}

function let_vars(PDO $db, array $l): array {
    $emp = $l['emp_code'] !== '' ? emp_by_code($db, $l['emp_code']) : null;
    $br = $emp && $emp['branch_id'] ? branch_label((string)(array_column(branches(false), 'name', 'id')[$emp['branch_id']] ?? '')) : '';
    $pm = function_exists('pr_settings') ? (int)pr_settings()['months'] : 3;
    $auto = let_auto_vars($emp, $br, (string)org('name'), $pm, date('Y-m-d'));
    $typed = json_decode((string)$l['fields'], true) ?: [];
    $v = array_replace($auto, array_filter($typed, fn($x) => trim((string)$x) !== ''));
    if (!$emp) $v['name'] = $l['person'];
    if ($l['accept_by']) $v['accept_by'] = date('j F Y', strtotime($l['accept_by']));
    if ($l['ref']) $v['ref'] = $l['ref'];
    return $v;
}

/** Make or change a draft. $in: template_id, emp_code or person + email, fields[], accept_by. Approved letters go back to draft. */
function let_save(PDO $db, array $u, ?int $id, array $in): int {
    if (!let_is_hr($u)) throw new RuntimeException('Only HR prepares letters.');
    $old = $id ? let_get($db, $id) : null;
    if ($id && (!$old || !in_array($old['status'], ['draft', 'approval', 'approved'], true))) throw new RuntimeException('Only a letter not yet issued can be changed.');
    $t = let_template($db, (int)($old['template_id'] ?? $in['template_id'] ?? 0));
    if (!$t || (!$old && !$t['active'])) throw new RuntimeException('Pick the letter type.');
    $code = strtoupper(trim((string)($in['emp_code'] ?? $old['emp_code'] ?? '')));
    $emp = $code !== '' ? emp_by_code($db, $code) : null;
    if ($code !== '' && !$emp) throw new RuntimeException('No employee with code ' . $code . '.');
    if ($emp && !emp_in_scope($u, $emp)) throw new RuntimeException('This person is outside your branches.');
    if ($emp && strcasecmp($emp['code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('HR does not prepare letters about themselves — ask a colleague.');
    $person = $emp ? (string)$emp['name'] : trim(strip_tags((string)($in['person'] ?? '')));
    if ($person === '' || mb_strlen($person) > 160) throw new RuntimeException('Give the person\'s name (or pick an employee).');
    $email = strtolower(trim((string)($in['email'] ?? ($emp['work_email'] ?? ''))));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email: not an email address.');
    $fields = [];
    foreach ((array)($in['fields'] ?? []) as $k => $v) if (preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string)$k) && in_array($k, let_fields($t['body']), true)) $fields[$k] = mb_substr(trim(strip_tags((string)$v)), 0, 500);
    $ab = emp_date((string)($in['accept_by'] ?? ''));
    if ($ab === 'bad') throw new RuntimeException('Accept by: not a date.');
    if ($id) {
        $db->prepare("UPDATE letter SET emp_code = ?, person = ?, email = ?, fields = ?, accept_by = ?, status = 'draft', approver_id = NULL, approved_at = NULL WHERE id = ?")
           ->execute([$emp['code'] ?? '', $person, $email, json_encode($fields), $ab, $id]);
        let_log($db, $id, $old['status'] === 'draft' ? 'Changed' : 'Changed after ' . strtolower(LET_STATUS[$old['status']]) . ' — back to draft', (int)$u['id']);
    } else {
        $db->prepare('INSERT INTO letter (template_id, type_code, person, emp_code, email, fields, resp, accept_by, signer_role, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$t['id'], $t['code'], $person, $emp['code'] ?? '', $email, json_encode($fields), $t['resp'], $ab, $t['signer_role'], (int)$u['id']]);
        $id = (int)$db->lastInsertId();
        if (!empty($in['request_id'])) $db->prepare("UPDATE letter SET request_id = ? WHERE id = ?")->execute([(int)$in['request_id'], $id]);
        let_log($db, $id, 'Made from "' . $t['name'] . '"', (int)$u['id']);
    }
    audit((int)$u['id'], 'letter_save', '#' . $id);
    return $id;
}

/** Send a draft for the signatory's approval. */
function let_ask_approval(PDO $db, array $u, int $id): void {
    $l = let_get($db, $id); $t = $l ? let_template($db, (int)$l['template_id']) : null;
    if (!$l || $l['status'] !== 'draft' || !let_is_hr($u)) throw new RuntimeException('Only a draft is sent for approval.');
    if (!$t['needs_approval']) throw new RuntimeException('This letter type needs no approval.');
    if ($m = let_missing($t['body'], let_vars($db, $l))) throw new RuntimeException('Fill in first: ' . implode(', ', $m) . '.');
    $db->prepare("UPDATE letter SET status = 'approval' WHERE id = ?")->execute([$id]);
    let_log($db, $id, 'Sent for approval', (int)$u['id']);
}

/** May this login approve? Holds the signer role, did not make it, it is not about them. */
function let_can_approve(array $u, array $l): bool {
    return $l['status'] === 'approval' && has_role($u, $l['signer_role']) && (int)$l['created_by'] !== (int)$u['id'] && !let_own($u, $l);
}

function let_decide(PDO $db, array $u, int $id, bool $ok, string $note): void {
    $l = let_get($db, $id);
    if (!$l || !let_can_approve($u, $l)) throw new RuntimeException($l && let_own($u, $l) ? 'Not for a letter about yourself.' : ($l && (int)$l['created_by'] === (int)$u['id'] ? 'You made this letter — someone else approves it.' : 'This letter is not waiting for you.'));
    $note = trim(mb_substr(strip_tags($note), 0, 500));
    if (!$ok && $note === '') throw new InvalidArgumentException('Say what to change.');
    $db->prepare('UPDATE letter SET status = ?, approver_id = ?, approved_at = ' . ($ok ? 'NOW()' : 'NULL') . ', approve_note = ? WHERE id = ?')->execute([$ok ? 'approved' : 'draft', (int)$u['id'], $note, $id]);
    let_log($db, $id, $ok ? 'Approved' . ($note !== '' ? ': ' . $note : '') : 'Sent back: ' . $note, (int)$u['id']);
    audit((int)$u['id'], 'letter_' . ($ok ? 'approve' : 'return'), '#' . $id);
}

/** Issue: freeze the text, reference, verification code, signatory, answer link. Returns the link token (shown once) or null. */
function let_issue(PDO $db, array $u, int $id): ?string {
    $l = let_get($db, $id); $t = $l ? let_template($db, (int)$l['template_id']) : null;
    if (!$l || !let_is_hr($u)) throw new RuntimeException('Letter not found.');
    if (let_own($u, $l)) throw new RuntimeException('Not for a letter about yourself.');
    if ($t['needs_approval'] && $l['status'] !== 'approved') throw new RuntimeException('This letter needs the signatory\'s approval first.');
    if (!$t['needs_approval'] && $l['status'] !== 'draft') throw new RuntimeException('Only a draft can be issued.');
    $today = date('Y-m-d');
    $sg = let_signer_on(let_signers($db), $l['signer_role'], $today);
    if (!$sg) throw new RuntimeException('Set a signatory for this role first (Letters → Signatories).');
    if ($l['resp'] === 'accept' && $l['accept_by'] && $l['accept_by'] < $today) throw new RuntimeException('"Accept by" is already past.');
    $token = $l['resp'] === 'none' ? null : bin2hex(random_bytes(24));
    $db->beginTransaction();
    try {
        $s = $db->prepare('SELECT COUNT(*) FROM letter WHERE type_code = ? AND ref IS NOT NULL AND YEAR(issued_at) = ? FOR UPDATE'); $s->execute([$l['type_code'], (int)date('Y')]);
        $ref = let_ref($l['type_code'], (int)date('Y'), (int)$s->fetchColumn() + 1);
        $l['ref'] = $ref;
        $vars = let_vars($db, $l);
        if ($m = let_missing($t['body'], $vars)) throw new RuntimeException('Fill in first: ' . implode(', ', $m) . '.');
        $html = let_render($t['body'], $vars);
        for ($i = 0; ; $i++) {
            try {
                $db->prepare("UPDATE letter SET status = 'issued', ref = ?, body_html = ?, sha256 = ?, verify_code = ?, signer_name = ?, signer_title = ?, token_hash = ?, link_until = ?, issued_by = ?, issued_at = NOW() WHERE id = ?")
                   ->execute([$ref, $html, hash('sha256', $html), $code = let_code(), $sg['name'], $sg['title'], $token ? hash('sha256', $token) : null,
                              $token ? date('Y-m-d', strtotime('+' . LET_LINK_DAYS . ' days')) : null, (int)$u['id'], $id]);
                break;
            } catch (PDOException $e) { if ($i >= 4) throw $e; }
        }
        if ($l['request_id']) $db->prepare("UPDATE letter_request SET status = 'done', letter_id = ?, done_by = ?, done_at = NOW() WHERE id = ? AND status = 'open'")->execute([$id, (int)$u['id'], $l['request_id']]);
        let_log($db, $id, 'Issued · ' . $ref . ' · verification ' . $code, (int)$u['id']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'letter_issue', '#' . $id);
    return $token;
}

/** New answer link (the old one stops). */
function let_new_link(PDO $db, array $u, int $id): string {
    $l = let_get($db, $id);
    if (!$l || $l['status'] !== 'issued' || $l['resp'] === 'none' || $l['response'] || !let_is_hr($u)) throw new RuntimeException('No answer is waiting on this letter.');
    $t = bin2hex(random_bytes(24));
    $db->prepare('UPDATE letter SET token_hash = ?, link_until = ? WHERE id = ?')->execute([hash('sha256', $t), date('Y-m-d', strtotime('+' . LET_LINK_DAYS . ' days')), $id]);
    let_log($db, $id, 'New answer link made', (int)$u['id']);
    return $t;
}

function let_withdraw(PDO $db, array $u, int $id, string $reason): void {
    $l = let_get($db, $id);
    if (!$l || !let_is_hr($u) || in_array($l['status'], ['withdrawn', 'lapsed'], true)) throw new RuntimeException('Nothing to withdraw.');
    if (trim($reason) === '') throw new InvalidArgumentException('Give a reason.');
    $db->prepare("UPDATE letter SET status = 'withdrawn', token_hash = NULL, withdrawn_reason = ? WHERE id = ?")->execute([mb_substr(trim(strip_tags($reason)), 0, 300), $id]);
    let_log($db, $id, 'Withdrawn: ' . $reason, (int)$u['id']);
    audit((int)$u['id'], 'letter_withdraw', '#' . $id);
}

/** Letter for an answer link, or null (unknown, replaced, expired). */
function let_by_token(PDO $db, string $token): ?array {
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
    $s = $db->prepare("SELECT * FROM letter WHERE token_hash = ? AND status = 'issued'"); $s->execute([hash('sha256', $token)]);
    $l = $s->fetch();
    return $l && (!$l['link_until'] || $l['link_until'] >= date('Y-m-d')) ? $l : null;
}

/** Why an answer cannot be taken ('' = already answered, 'ok' = can). Pure over the row. */
function let_answer_state(array $l, string $today): string {
    if ($l['resp'] === 'none') return 'No answer is needed for this letter.';
    if ($l['response']) return '';
    if ($l['status'] !== 'issued') return 'This letter is ' . strtolower(LET_STATUS[$l['status']] ?? $l['status']) . '. Please contact HR.';
    if ($l['accept_by'] && $l['accept_by'] < $today) return 'The date to accept has passed. Please contact HR.';
    return 'ok';
}

function let_answer(PDO $db, array $l, string $answer, string $typed, string $ip, ?int $uid = null): string {
    $why = let_answer_state($l, date('Y-m-d'));
    if ($why !== 'ok') throw new RuntimeException($why === '' ? 'Your answer is already recorded.' : $why);
    $map = LET_ANSWER[$l['resp']] ?? [];
    if (!isset($map[$answer])) throw new InvalidArgumentException('Choose an answer.');
    $typed = trim(preg_replace('/\s+/', ' ', $typed) ?? '');
    if (!let_name_ok($typed, $l['person'])) throw new InvalidArgumentException('Type your full name as it is on the letter.');
    $s = $db->prepare('UPDATE letter SET response = ?, resp_name = ?, resp_at = NOW(), resp_ip = ?, token_hash = NULL WHERE id = ? AND response IS NULL');
    $s->execute([$answer, mb_substr($typed, 0, 160), mb_substr($ip, 0, 45), (int)$l['id']]);
    if ($s->rowCount() !== 1) throw new RuntimeException('Your answer is already recorded.');
    let_log($db, (int)$l['id'], $map[$answer] . ' by ' . $typed . ($uid ? ' (signed in)' : ' (link)'), $uid);
    audit($uid, 'letter_answer', '#' . $l['id'] . ' ' . $answer);
    return $map[$answer];
}

/** Offers past "accept by" with no answer become Lapsed. */
function let_housekeeping(PDO $db): int {
    $s = $db->prepare("UPDATE letter SET status = 'lapsed', token_hash = NULL WHERE status = 'issued' AND resp = 'accept' AND response IS NULL AND accept_by IS NOT NULL AND accept_by < ?");
    $s->execute([date('Y-m-d')]);
    return $s->rowCount();
}

/** What the public verify page may show. */
function let_verify(PDO $db, string $code): ?array {
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-HJ-NP-Z2-9]{10}$/', $code)) return null;
    $s = $db->prepare('SELECT l.*, t.name AS tname FROM letter l JOIN letter_template t ON t.id = l.template_id WHERE l.verify_code = ?'); $s->execute([$code]);
    $l = $s->fetch();
    if (!$l) return null;
    $f = json_decode((string)$l['fields'], true) ?: [];
    $emp = $l['emp_code'] !== '' ? emp_by_code($db, $l['emp_code']) : null;
    return ['state' => $l['status'] === 'withdrawn' ? 'withdrawn' : 'valid', 'type' => $l['tname'], 'ref' => $l['ref'], 'person' => $l['person'],
            'designation' => $f['designation'] ?? ($emp['designation'] ?? ''), 'issued' => substr((string)$l['issued_at'], 0, 10), 'sha256' => $l['sha256']];
}

/** Letters for HR's list (in their branches). */
function let_list(PDO $db, array $u, string $status = ''): array {
    $s = $db->prepare('SELECT l.*, t.name AS tname, e.branch_id FROM letter l JOIN letter_template t ON t.id = l.template_id LEFT JOIN employee e ON e.code = l.emp_code'
        . ($status !== '' ? ' WHERE l.status = ?' : '') . ' ORDER BY l.id DESC LIMIT 300');
    $s->execute($status !== '' ? [$status] : []);
    return array_values(array_filter($s->fetchAll(), fn($l) => !$l['branch_id'] || emp_in_scope($u, $l)));
}

/** Letters waiting for this login's approval. */
function let_waiting(PDO $db, array $u): array {
    $s = $db->query("SELECT l.*, t.name AS tname FROM letter l JOIN letter_template t ON t.id = l.template_id WHERE l.status = 'approval' ORDER BY l.id");
    return array_values(array_filter($s->fetchAll(), fn($l) => let_can_approve($u, $l)));
}

/** A person's own issued letters (types shown to employees). */
function let_mine(PDO $db, string $code): array {
    if ($code === '') return [];
    $s = $db->prepare("SELECT l.*, t.name AS tname FROM letter l JOIN letter_template t ON t.id = l.template_id WHERE l.emp_code = ? AND l.status IN ('issued','lapsed') AND t.for_employees = 1 ORDER BY l.issued_at DESC");
    $s->execute([$code]);
    return $s->fetchAll();
}

function let_request(PDO $db, array $u, int $templateId, string $purpose): int {
    $code = (string)($u['emp_code'] ?? '');
    if ($code === '') throw new RuntimeException('Your login is not linked to an employee record.');
    $t = let_template($db, $templateId);
    if (!$t || !$t['active'] || !$t['for_employees']) throw new RuntimeException('Pick a letter from the list.');
    $s = $db->prepare("SELECT COUNT(*) FROM letter_request WHERE emp_code = ? AND template_id = ? AND status = 'open'"); $s->execute([$code, $templateId]);
    if ((int)$s->fetchColumn()) throw new RuntimeException('You already asked for this letter. HR will issue it.');
    $db->prepare('INSERT INTO letter_request (emp_code, user_id, template_id, purpose) VALUES (?,?,?,?)')->execute([$code, (int)$u['id'], $templateId, mb_substr(trim(strip_tags($purpose)), 0, 300)]);
    audit((int)$u['id'], 'letter_request', $t['code']);
    return (int)$db->lastInsertId();
}

function let_request_close(PDO $db, array $u, int $id, string $note): void {
    if (!let_is_hr($u)) throw new RuntimeException('Only HR closes requests.');
    if (trim($note) === '') throw new InvalidArgumentException('Write a note for the person.');
    $db->prepare("UPDATE letter_request SET status = 'closed', note = ?, done_by = ?, done_at = NOW() WHERE id = ? AND status = 'open'")->execute([mb_substr(trim(strip_tags($note)), 0, 300), (int)$u['id'], $id]);
}

function let_requests_open(PDO $db, array $u): array {
    $rows = $db->query("SELECT r.*, t.name AS tname, t.id AS tid, e.name, e.branch_id FROM letter_request r JOIN letter_template t ON t.id = r.template_id LEFT JOIN employee e ON e.code = r.emp_code WHERE r.status = 'open' ORDER BY r.id")->fetchAll();
    return array_values(array_filter($rows, fn($r) => !$r['branch_id'] || emp_in_scope($u, $r)));
}

function let_template_save(PDO $db, array $u, ?int $id, array $in): void {
    if (!has_role($u, 'SUPERADMIN', 'HRMANAGER')) throw new RuntimeException('Only the Super Admin or HR Manager changes templates.');
    $t = let_template_check($in, array_keys(rbac_config()['roles']));
    if ($id) $db->prepare('UPDATE letter_template SET code = ?, name = ?, body = ?, resp = ?, signer_role = ?, needs_approval = ?, for_employees = ?, updated_by = ? WHERE id = ?')->execute([...array_values($t), (int)$u['id'], $id]);
    else $db->prepare('INSERT INTO letter_template (code, name, body, resp, signer_role, needs_approval, for_employees, updated_by) VALUES (?,?,?,?,?,?,?,?)')->execute([...array_values($t), (int)$u['id']]);
    audit((int)$u['id'], 'letter_template_save', $t['code']);
}

function let_signer_save(PDO $db, array $u, array $in): void {
    if (!has_role($u, 'SUPERADMIN', 'HRMANAGER')) throw new RuntimeException('Only the Super Admin or HR Manager sets signatories.');
    $role = (string)($in['role_code'] ?? '');
    if (!isset(rbac_config()['roles'][$role])) throw new InvalidArgumentException('Pick the role.');
    $name = trim(strip_tags((string)($in['name'] ?? ''))); $title = trim(strip_tags((string)($in['title'] ?? '')));
    if ($name === '' || $title === '') throw new InvalidArgumentException('Give the name and title as they should be printed.');
    $from = emp_date((string)($in['from_day'] ?? '')) ?: date('Y-m-d');
    if ($from === 'bad') throw new InvalidArgumentException('Valid from: not a date.');
    $db->prepare('INSERT INTO letter_signer (role_code, name, title, from_day, set_by) VALUES (?,?,?,?,?)')->execute([$role, mb_substr($name, 0, 120), mb_substr($title, 0, 120), $from, (int)$u['id']]);
    audit((int)$u['id'], 'letter_signer_set', $role);
}
