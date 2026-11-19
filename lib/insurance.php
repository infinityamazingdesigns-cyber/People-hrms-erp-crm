<?php
// Group insurance — policies (with their copy), who is covered under each (card number, sum insured, covered from,
// the insurer's own employee ID, nominee), the insurer's member list read from pasted text with codes suggested for HR
// to check, renewal reminders, and requests for the policy copy. HR (settings roles, within their branch scope) sets
// lines; nobody sets their own line or answers their own request. A person sees only their own cover.
declare(strict_types=1);
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/kyc.php';           // kyc_doc_mime()

const INS_KINDS = ['health' => 'Group health (mediclaim)', 'accident' => 'Group personal accident', 'life' => 'Group term life', 'wc' => "Employees' compensation", 'other' => 'Other cover'];
const INS_RELATIONS = ['Spouse', 'Father', 'Mother', 'Son', 'Daughter', 'Brother', 'Sister', 'Other'];
const INS_REQ_STATUS = ['hr' => 'With HR', 'shared' => 'Shared', 'closed' => 'Closed', 'withdrawn' => 'Withdrawn'];
const INS_DOC_MAX = 15 * 1024 * 1024;
const INS_DEFAULTS = ['edit_roles' => ['SUPERADMIN', 'HRMANAGER'], 'renew_days' => 45];
/** Cover line fields => label (for the change log, names only). */
const INS_FIELDS = ['covered' => 'Covered', 'card_no' => 'Card number', 'sum_insured' => 'Sum insured', 'since' => 'Covered from', 'ins_id' => "Insurer's employee ID",
                    'nom_name' => 'Nominee', 'nom_relation' => 'Nominee relation', 'nom_dob' => "Nominee's date of birth", 'nom_phone' => "Nominee's phone"];

/* ---------------- pure ---------------- */

/** A card number as on the card: letters, digits, - and /; '' when blank; null when not readable. */
function ins_card_clean(string $v): ?string {
    $v = strtoupper(preg_replace('/\s+/', '', $v) ?? '');
    if ($v === '') return '';
    return preg_match('#^[A-Z0-9][A-Z0-9/\-]{3,38}$#', $v) ? $v : null;
}
function ins_date_ok(string $v): bool { $d = DateTime::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v; }
/** Where a policy stands on $today: 'future', 'ok', 'soon' (ends within $days), 'over', or '' without dates. */
function ins_period_state(?string $from, ?string $till, string $today, int $days): string {
    if (!$till) return '';
    if ($from && $today < $from) return 'future';
    if ($today > $till) return 'over';
    return (new DateTime($today))->diff(new DateTime($till))->days <= $days ? 'soon' : 'ok';
}
/** Check a policy form. Returns [clean, errors]. */
function ins_policy_check(array $in): array {
    $t = fn($k, $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n); $e = [];
    $p = ['kind' => isset(INS_KINDS[$in['kind'] ?? '']) ? (string)$in['kind'] : '', 'name' => $t('name', 120), 'insurer' => $t('insurer', 120), 'policy_no' => strtoupper($t('policy_no', 60)),
          'cover_from' => $t('cover_from', 10), 'cover_till' => $t('cover_till', 10), 'basis' => $t('basis', 120), 'helpline' => $t('helpline', 120),
          'claims_email' => strtolower($t('claims_email', 160)), 'cashless_email' => strtolower($t('cashless_email', 160)), 'note' => $t('note', 500)];
    if ($p['kind'] === '') $e[] = 'Pick the kind of cover.';
    if ($p['name'] === '') $e[] = 'Give the policy a name.';
    foreach (['cover_from' => 'Cover from', 'cover_till' => 'Cover till'] as $k => $l) if ($p[$k] !== '' && !ins_date_ok($p[$k])) $e[] = "$l: pick a date.";
    if ($p['cover_from'] !== '' && $p['cover_till'] !== '' && $p['cover_till'] < $p['cover_from']) $e[] = 'Cover till must be after cover from.';
    foreach (['claims_email' => 'Claims email', 'cashless_email' => 'Cashless email'] as $k => $l) if ($p[$k] !== '' && !filter_var($p[$k], FILTER_VALIDATE_EMAIL)) $e[] = "$l: check it.";
    $sum = preg_replace('/[^\d.]/', '', (string)($in['sum_insured'] ?? '')) ?? ''; $p['sum_insured'] = $sum === '' ? null : round((float)$sum, 2);
    if ($p['sum_insured'] !== null && $p['sum_insured'] > 1e11) $e[] = 'Sum insured looks too large.';
    foreach (['cover_from', 'cover_till'] as $k) if ($p[$k] === '') $p[$k] = null;
    return [$p, $e];
}
/**
 * One cover line merged with what was sent ($in keys missing keep their value). A card number means covered; not
 * covered clears the card. Returns [new line, errors].
 */
function ins_member_merge(array $old, array $in, string $today): array {
    $n = $old + ['covered' => 0, 'card_no' => '', 'sum_insured' => null, 'since' => null, 'ins_id' => '', 'nom_name' => '', 'nom_relation' => '', 'nom_dob' => null, 'nom_phone' => '']; $e = [];
    if (array_key_exists('card_no', $in)) { $c = ins_card_clean((string)$in['card_no']); if ($c === null) $e[] = 'Card number: letters, digits and - / only (as on the card).'; else $n['card_no'] = $c; }
    if (array_key_exists('covered', $in)) $n['covered'] = in_array((string)$in['covered'], ['1', 'yes', 'on', 'true'], true) ? 1 : 0;
    elseif (array_key_exists('card_no', $in) && $n['card_no'] !== '') $n['covered'] = 1;
    if (array_key_exists('sum_insured', $in)) { $v = preg_replace('/[^\d.]/', '', (string)$in['sum_insured']) ?? ''; $n['sum_insured'] = $v === '' ? null : round((float)$v, 2); if ($n['sum_insured'] !== null && $n['sum_insured'] > 1e11) $e[] = 'Sum insured looks too large.'; }
    if (array_key_exists('since', $in)) { $v = trim((string)$in['since']); if ($v !== '' && !ins_date_ok($v)) $e[] = 'Covered from: pick a date.'; $n['since'] = $v === '' ? null : $v; }
    if (array_key_exists('ins_id', $in)) $n['ins_id'] = mb_substr(strtoupper(trim(strip_tags((string)$in['ins_id']))), 0, 40);
    if (array_key_exists('nom_name', $in)) $n['nom_name'] = mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$in['nom_name']))), 0, 80);
    if (array_key_exists('nom_relation', $in)) { $v = trim((string)$in['nom_relation']); if ($v !== '' && !in_array($v, INS_RELATIONS, true)) $e[] = 'Nominee relation: pick from the list.'; $n['nom_relation'] = $v; }
    if (array_key_exists('nom_dob', $in)) { $v = trim((string)$in['nom_dob']); if ($v !== '' && (!ins_date_ok($v) || $v > $today)) $e[] = "Nominee's date of birth: a past date."; $n['nom_dob'] = $v === '' ? null : $v; }
    if (array_key_exists('nom_phone', $in)) { $v = preg_replace('/[^\d+]/', '', (string)$in['nom_phone']) ?? ''; if ($v !== '' && !preg_match('/^\+?\d{7,15}$/', $v)) $e[] = "Nominee's phone: 7 to 15 digits."; $n['nom_phone'] = $v; }
    if ($n['nom_name'] === '' && ($n['nom_relation'] !== '' || $n['nom_dob'] || $n['nom_phone'] !== '')) $e[] = "Give the nominee's name too.";
    if (!(int)$n['covered']) $n['card_no'] = '';
    return [$n, $e];
}
/** Field names that differ between two cover lines. */
function ins_changed(array $a, array $b): array { $o = []; foreach (INS_FIELDS as $k => $_) if ((string)($a[$k] ?? '') !== (string)($b[$k] ?? '')) $o[] = $k; return $o; }
/**
 * Rows from text pasted out of the insurer's member list: an ID (employee code as the insurer wrote it) before a card
 * number, then name, date of birth (dd/mm/yyyy), relation and sum insured when there. Also "CODE, CARD" or tab separated.
 * Returns [ins_id, card, name, dob, rel, sum].
 */
function ins_parse_list(string $text): array {
    $out = []; $seen = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim(str_replace("\xC2\xA0", ' ', $line));
        if ($line === '' || !preg_match('/\b([A-Z]{1,4}\d{6,16}(?:-\d{1,3}[A-Z]?)?)\b/', $line, $cm, PREG_OFFSET_CAPTURE)) continue;
        $card = $cm[1][0]; $before = trim(substr($line, 0, $cm[1][1])); $after = trim(substr($line, $cm[1][1] + strlen($card)));
        $before = trim((string)preg_replace('/^\d{1,4}[.)]?\s+/', '', $before));
        $id = strtoupper((string)preg_replace('/[\s,;|]+/', '', $before));
        if ($id === '' || !preg_match('/^[A-Z0-9_\-\/]{2,30}$/', $id)) continue;
        $name = ''; $dob = ''; $rel = ''; $sum = '';
        if (preg_match('/^(.*?)\s+(\d{2})[\/\-.](\d{2})[\/\-.](\d{4})\b(.*)$/', $after, $m)) {
            $name = trim($m[1]); $dob = checkdate((int)$m[3], (int)$m[2], (int)$m[4]) ? "$m[4]-$m[3]-$m[2]" : '';
            if (preg_match('/\b(self|spouse|son|daughter|father|mother|child)\b/i', $m[5], $r)) $rel = ucfirst(strtolower($r[1]));
            if (preg_match('/(\d[\d,]{3,})\s*$/', $m[5], $s)) $sum = str_replace(',', '', $s[1]);
        } else $name = trim((string)preg_replace('/^[,;|\t ]+/', '', $after));
        if (isset($seen[$id . '|' . $card])) continue; $seen[$id . '|' . $card] = true;
        $out[] = ['ins_id' => $id, 'card' => $card, 'name' => mb_substr($name, 0, 120), 'dob' => $dob, 'rel' => $rel, 'sum' => $sum];
    }
    return $out;
}
/**
 * The master code a listed row most likely belongs to: the same code; else a code one or two letters off whose name
 * agrees; else a single name match. Returns [code, how] — how: code / near / name / ''. HR checks every suggestion.
 */
function ins_match(array $row, array $master): array {
    $id = strtoupper($row['ins_id']);
    if (isset($master[$id])) return [$id, 'code'];
    $nk = emp_words((string)$row['name']);
    $hits = function (array $p) use ($nk): int {
        $pk = emp_words((string)($p['name'] ?? '')); if (!$nk || !$pk) return 0;
        $hit = 0; foreach ($nk as $w) foreach ($pk as $x) if ($w === $x || (strlen($w) > 3 && strlen($x) > 3 && levenshtein($w, $x) <= 1)) { $hit++; break; }
        return $hit >= min(2, count($nk), count($pk)) ? $hit : 0;
    };
    $best = function (array $c): string { if (!$c) return ''; arsort($c); $v = array_values($c); return count($v) === 1 || $v[0] > $v[1] ? (string)array_key_first($c) : ''; };
    $near = []; $byName = [];
    foreach ($master as $c => $p) if (($h = $hits($p)) > 0) { $byName[(string)$c] = $h; if (levenshtein($id, (string)$c) <= 2) $near[(string)$c] = $h; }
    if (($c = $best($near)) !== '') return [$c, 'near'];
    if (($c = $best($byName)) !== '') return [$c, 'name'];
    return ['', ''];
}

/* ---------------- settings and rights ---------------- */

function ins_settings(): array { $s = module_states()['insurance']['settings'] ?? []; return array_replace(INS_DEFAULTS, array_intersect_key($s, INS_DEFAULTS)); }
function ins_settings_check(array $in, array $roles): array {
    $o = ['edit_roles' => array_values(array_intersect(array_keys($roles), (array)($in['edit_roles'] ?? []))), 'renew_days' => max(1, min(180, (int)($in['renew_days'] ?? 45)))];
    return [$o, $o['edit_roles'] ? [] : ['Pick at least one role that keeps the insurance records.']];
}
function ins_can(array $u): bool { return has_role($u, ...ins_settings()['edit_roles']); }
/** May this login set the cover of this person? In scope and never their own. */
function ins_may_person(array $u, ?array $emp): bool { return $emp && ins_can($u) && emp_in_scope($u, $emp) && strtoupper((string)($u['emp_code'] ?? '')) !== strtoupper((string)$emp['code']); }
function ins_log(PDO $db, ?int $pid, string $code, string $what, array $fields, ?int $by, string $note = ''): void {
    $db->prepare('INSERT INTO ins_log (policy_id, emp_code, what, fields, note, by_user) VALUES (?,?,?,?,?,?)')->execute([$pid, $code, $what, mb_substr(implode(', ', $fields), 0, 300), mb_substr($note, 0, 300), $by]);
}
function ins_tell(PDO $db, int $uid, string $subject, string $text): void {
    if (!module_on('email') || !function_exists('mail_queue')) return;
    try { $s = $db->prepare('SELECT email FROM users WHERE id = ? AND active = 1'); $s->execute([$uid]); $to = (string)$s->fetchColumn();
          if (filter_var($to, FILTER_VALIDATE_EMAIL)) mail_queue($db, ['to' => $to, 'subject' => $subject, 'text' => $text . "\n\nOpen My insurance in " . org('name') . '.'], 'notice', 'insurance'); } catch (Throwable $e) {}
}

/* ---------------- policies ---------------- */

function ins_policies(PDO $db, bool $all = false): array { return $db->query('SELECT id, kind, name, insurer, policy_no, cover_from, cover_till, sum_insured, basis, helpline, claims_email, cashless_email, note, doc_name, doc_at, active, (SELECT COUNT(*) FROM ins_member m WHERE m.policy_id = p.id AND m.covered = 1) n FROM ins_policy p' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY active DESC, cover_till DESC, name')->fetchAll(); }
function ins_policy_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT id, kind, name, insurer, policy_no, cover_from, cover_till, sum_insured, basis, helpline, claims_email, cashless_email, note, doc_name, doc_mime, doc_at, active FROM ins_policy WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function ins_policy_save(PDO $db, array $u, int $id, array $in): int {
    if (!ins_can($u)) throw new RuntimeException('Your role does not keep insurance records.');
    [$p, $e] = ins_policy_check($in); if ($e) throw new RuntimeException(implode(' ', $e));
    $old = $id ? ins_policy_get($db, $id) : null; if ($id && !$old) throw new RuntimeException('Policy not found.');
    if ($old) {
        $ch = array_keys(array_filter($p, fn($v, $k) => (string)($old[$k] ?? '') !== (string)($v ?? ''), ARRAY_FILTER_USE_BOTH)); if (!$ch) return $id;
        $db->prepare('UPDATE ins_policy SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($p))) . ', updated_by = ? WHERE id = ?')->execute([...array_values($p), (int)$u['id'], $id]);
        ins_log($db, $id, '', 'policy_edit', $ch, (int)$u['id']);
    } else {
        $db->prepare('INSERT INTO ins_policy (' . implode(',', array_keys($p)) . ', created_by, updated_by) VALUES (' . implode(',', array_fill(0, count($p) + 2, '?')) . ')')->execute([...array_values($p), (int)$u['id'], (int)$u['id']]);
        $id = (int)$db->lastInsertId(); ins_log($db, $id, '', 'policy_add', [], (int)$u['id'], $p['name']);
    }
    audit((int)$u['id'], 'ins_policy_save', '#' . $id);
    return $id;
}
/** A renewal is a new policy; the old one is switched off with a reason, never removed. */
function ins_policy_active(PDO $db, array $u, int $id, bool $on, string $why): void {
    if (!ins_can($u)) throw new RuntimeException('Your role does not keep insurance records.');
    if (!$on && ($why = trim(strip_tags($why))) === '') throw new RuntimeException('Write why it is switched off (for example: renewed as a new policy).');
    $db->prepare('UPDATE ins_policy SET active = ?, updated_by = ? WHERE id = ?')->execute([$on ? 1 : 0, (int)$u['id'], $id]);
    ins_log($db, $id, '', $on ? 'policy_on' : 'policy_off', [], (int)$u['id'], $why);
}
function ins_policy_doc_save(PDO $db, array $u, int $id, string $bytes, string $name): void {
    if (!ins_can($u)) throw new RuntimeException('Your role does not keep insurance records.');
    if (!ins_policy_get($db, $id)) throw new RuntimeException('Policy not found.');
    if ($bytes === '' || strlen($bytes) > INS_DOC_MAX) throw new RuntimeException('The policy copy is up to 15 MB.');
    $mime = kyc_doc_mime($bytes); if ($mime === null) throw new RuntimeException('Policy copy: PDF, JPEG, PNG or WebP only.');
    $db->prepare('UPDATE ins_policy SET doc = ?, doc_name = ?, doc_mime = ?, doc_at = NOW(), updated_by = ? WHERE id = ?')->execute([$bytes, mb_substr(basename($name), 0, 160), $mime, (int)$u['id'], $id]);
    ins_log($db, $id, '', 'policy_doc', [], (int)$u['id'], mb_substr(basename($name), 0, 160));
}
/** May this login open the policy copy? The keepers, or a person whose request for it was shared. */
function ins_may_open_doc(PDO $db, array $u, int $pid): bool {
    if (ins_can($u)) return true;
    $s = $db->prepare("SELECT 1 FROM ins_request WHERE user_id = ? AND status = 'shared' AND policy_id = ? LIMIT 1"); $s->execute([(int)$u['id'], $pid]);
    return (bool)$s->fetchColumn();
}
function ins_policy_doc(PDO $db, int $id): ?array { $s = $db->prepare('SELECT doc, doc_name, doc_mime FROM ins_policy WHERE id = ? AND doc IS NOT NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
/** Policies ending within the renewal window, or ended while still switched on. */
function ins_renewals(PDO $db, ?string $today = null): array {
    $today ??= date('Y-m-d'); $d = (int)ins_settings()['renew_days'];
    return array_values(array_filter(ins_policies($db), fn($p) => in_array(ins_period_state($p['cover_from'], $p['cover_till'], $today, $d), ['soon', 'over'], true)));
}

/* ---------------- cover lines ---------------- */

function ins_member_get(PDO $db, int $pid, string $code): ?array { $s = $db->prepare('SELECT * FROM ins_member WHERE policy_id = ? AND emp_code = ?'); $s->execute([$pid, strtoupper($code)]); return $s->fetch() ?: null; }
function ins_members(PDO $db, array $u, int $pid): array {
    $s = $db->prepare('SELECT m.*, e.name, e.branch_id, e.department FROM ins_member m LEFT JOIN employee e ON e.code = m.emp_code WHERE m.policy_id = ? ORDER BY m.covered DESC, e.name'); $s->execute([$pid]);
    return array_values(array_filter($s->fetchAll(), fn($r) => emp_in_scope($u, ['branch_id' => $r['branch_id']])));
}
/** Set one person's cover under a policy. Returns the changed field names ([] when nothing changed). */
function ins_member_set(PDO $db, array $u, int $pid, string $code, array $in, string $what = 'hr', string $note = '', ?string $today = null): array {
    $emp = emp_by_code($db, strtoupper(trim($code)));
    if (!$emp) throw new RuntimeException('Pick a person from the employee master.');
    if (strtoupper((string)($u['emp_code'] ?? '')) === strtoupper((string)$emp['code'])) throw new RuntimeException('Your own cover is set by another HR person.');
    if (!ins_may_person($u, $emp)) throw new RuntimeException('This person is outside your access.');
    $p = ins_policy_get($db, $pid); if (!$p || !(int)$p['active']) throw new RuntimeException('Pick an active policy.');
    $old = ins_member_get($db, $pid, $emp['code']) ?? [];
    [$new, $e] = ins_member_merge($old, $in, $today ?? date('Y-m-d')); if ($e) throw new RuntimeException(implode(' ', $e));
    $ch = ins_changed($old, $new);
    if (!$ch || ($old === [] && !(int)$new['covered'] && array_diff($ch, ['covered']) === [])) return [];
    $f = array_intersect_key($new, INS_FIELDS);
    $db->prepare('INSERT INTO ins_member (policy_id, emp_code, ' . implode(', ', array_keys($f)) . ', updated_by) VALUES (?, ?, ' . implode(',', array_fill(0, count($f), '?')) . ', ?) ON DUPLICATE KEY UPDATE '
        . implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($f))) . ', updated_by = VALUES(updated_by)')->execute([$pid, strtoupper($emp['code']), ...array_values($f), (int)$u['id']]);
    ins_log($db, $pid, strtoupper($emp['code']), $what, $ch, (int)$u['id'], $note);
    audit((int)$u['id'], 'ins_member_set', '#' . $pid . ' ' . strtoupper($emp['code']) . ' · ' . implode(', ', $ch));   // field names only
    return $ch;
}
/** Save the rows HR checked from the insurer's list: [[code, card, sum, ins_id], …]. */
function ins_import(PDO $db, array $u, int $pid, array $rows, string $since = ''): array {
    if (!ins_can($u)) throw new RuntimeException('Your role does not keep insurance records.');
    $res = ['saved' => [], 'same' => [], 'skipped' => []];
    foreach ($rows as $r) {
        $code = strtoupper(trim((string)($r['code'] ?? ''))); if ($code === '') continue;
        $in = ['covered' => '1', 'card_no' => (string)($r['card'] ?? ''), 'ins_id' => (string)($r['ins_id'] ?? '')];
        if (($r['sum'] ?? '') !== '') $in['sum_insured'] = (string)$r['sum'];
        if ($since !== '' && !(ins_member_get($db, $pid, $code)['since'] ?? null)) $in['since'] = $since;
        try { $res[ins_member_set($db, $u, $pid, $code, $in, 'import', "From the insurer's list") ? 'saved' : 'same'][] = $code; }
        catch (RuntimeException $e) { $res['skipped'][$code] = $e->getMessage(); }
    }
    return $res;
}
/** People on the roll in scope with no line under a policy (or not covered). */
function ins_not_covered(PDO $db, array $u, int $pid): array {
    $s = $db->prepare('SELECT emp_code FROM ins_member WHERE policy_id = ? AND covered = 1'); $s->execute([$pid]); $have = array_flip(array_map('strtoupper', $s->fetchAll(PDO::FETCH_COLUMN)));
    return array_values(array_filter(emp_on_roll($db, $u), fn($e) => !isset($have[strtoupper($e['code'])])));
}
/** The login's own cover: every active policy with its line (null = no line). */
function ins_mine(PDO $db, string $code): array {
    if ($code === '') return [];
    $o = []; foreach (ins_policies($db) as $p) $o[] = ['policy' => $p, 'line' => ins_member_get($db, (int)$p['id'], $code)];
    return $o;
}
function ins_history(PDO $db, int $pid, string $code = ''): array {
    $s = $db->prepare('SELECT l.*, u.name AS by_name FROM ins_log l LEFT JOIN users u ON u.id = l.by_user WHERE l.policy_id = ?' . ($code !== '' ? ' AND l.emp_code = ?' : '') . ' ORDER BY l.id DESC LIMIT 200');
    $s->execute($code !== '' ? [$pid, strtoupper($code)] : [$pid]); return $s->fetchAll();
}

/* ---------------- requests for the policy copy ---------------- */

function ins_request_add(PDO $db, array $u, int $pid, string $note = ''): int {
    $code = strtoupper((string)($u['emp_code'] ?? '')); if ($code === '') throw new RuntimeException('Your login is not linked to an employee record yet — ask HR.');
    $p = ins_policy_get($db, $pid); if (!$p || !(int)$p['active']) throw new RuntimeException('Pick a policy.');
    $s = $db->prepare("SELECT 1 FROM ins_request WHERE user_id = ? AND policy_id = ? AND status = 'hr'"); $s->execute([(int)$u['id'], $pid]); if ($s->fetchColumn()) throw new RuntimeException('Your request is already with HR.');
    $db->prepare('INSERT INTO ins_request (user_id, emp_code, policy_id, note) VALUES (?,?,?,?)')->execute([(int)$u['id'], $code, $pid, mb_substr(trim(strip_tags($note)), 0, 500)]);
    $id = (int)$db->lastInsertId(); audit((int)$u['id'], 'ins_request', '#' . $id);
    return $id;
}
function ins_request_withdraw(PDO $db, array $u, int $id): void {
    $s = $db->prepare("UPDATE ins_request SET status = 'withdrawn', decided_at = NOW() WHERE id = ? AND user_id = ? AND status = 'hr'"); $s->execute([$id, (int)$u['id']]);
    if (!$s->rowCount()) throw new RuntimeException('Nothing to withdraw.');
}
function ins_my_requests(PDO $db, int $uid): array { $s = $db->prepare('SELECT r.*, p.name AS policy FROM ins_request r LEFT JOIN ins_policy p ON p.id = r.policy_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 20'); $s->execute([$uid]); return $s->fetchAll(); }
/** Requests waiting for this keeper: in scope, never their own. */
function ins_requests_waiting(PDO $db, array $u): array {
    if (!ins_can($u)) return [];
    $s = $db->prepare("SELECT r.*, p.name AS policy, p.doc_at, e.name, e.branch_id FROM ins_request r LEFT JOIN ins_policy p ON p.id = r.policy_id LEFT JOIN employee e ON e.code = r.emp_code WHERE r.status = 'hr' AND r.user_id <> ? ORDER BY r.created_at"); $s->execute([(int)$u['id']]);
    $me = strtoupper((string)($u['emp_code'] ?? ''));
    return array_values(array_filter($s->fetchAll(), fn($r) => strtoupper($r['emp_code']) !== $me && emp_in_scope($u, ['branch_id' => $r['branch_id']])));
}
/** Share (the copy opens for the person — needs the copy on file, or a note saying how it was sent) or close (a note). */
function ins_request_decide(PDO $db, array $u, int $id, string $go, string $note = ''): string {
    $w = array_column(ins_requests_waiting($db, $u), null, 'id'); $r = $w[$id] ?? null;
    if (!$r) throw new RuntimeException('This request is not waiting for you (it may be your own, already answered, or outside your access).');
    $note = mb_substr(trim(strip_tags($note)), 0, 500); $new = $go === 'share' ? 'shared' : 'closed';
    if ($new === 'shared' && !$r['doc_at'] && $note === '') throw new RuntimeException('Upload the policy copy first, or write how you sent it.');
    if ($new === 'closed' && $note === '') throw new RuntimeException('Write a note for the person.');
    $db->prepare("UPDATE ins_request SET status = ?, hr_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'hr'")->execute([$new, $note, (int)$u['id'], $id]);
    audit((int)$u['id'], 'ins_request_' . $new, '#' . $id);
    ins_tell($db, (int)$r['user_id'], $new === 'shared' ? 'Your insurance policy copy is shared' : 'Your insurance request is answered', $note !== '' ? 'HR: ' . $note : 'The policy copy now opens from My insurance.');
    return $new;
}
