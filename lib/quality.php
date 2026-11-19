<?php
// Complaints & CAPA — rules. One case = one occurrence (customer or internal). A draft needs a branch and one of customer /
// product / description and gets its number at once (CC/<branch>/<year>/0001); the same request id never makes a second
// case. Status moves only along QC_FLOW, each behind its right; reject / reopen / going back need a reason. Closing needs
// the CAPA's root cause confirmed and its effectiveness check passed — or, for a severity the settings allow, no CAPA and a
// note. Kept apart: nobody who owns an action confirms the root cause; nobody who owns an action or confirmed the root cause
// checks effectiveness or closes. Repeats are only suggested; a handler confirms, and confirmed repeats point at the
// earliest case. Nothing is deleted: the timeline is insert-only and keeps the description before every edit. Pure first.
declare(strict_types=1);
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/enquiries.php';   // so_fy_enq()
require_once __DIR__ . '/kyc.php';         // kyc_doc_mime()
require_once __DIR__ . '/push.php';

const QC_KINDS = ['customer' => 'Customer complaint', 'internal' => 'Internal / production problem', 'billing' => 'Billing or order problem',
                  'dispatch' => 'Dispatch or delivery problem', 'supplier' => 'Supplier problem', 'service' => 'Service or maintenance', 'other' => 'Other'];
const QC_PRODUCT_KINDS = ['customer', 'internal', 'dispatch', 'service'];   // need how many pieces at submit
const QC_STATUSES = ['Draft', 'Submitted', 'Under review', 'Investigation', 'Action in progress', 'Pending verification', 'Closed', 'Reopened', 'Rejected'];
const QC_OPEN = ['Submitted', 'Under review', 'Investigation', 'Action in progress', 'Pending verification', 'Reopened'];
const QC_LOCKED = ['Closed', 'Rejected'];
/** from => [to => right]: handle (assign, investigate), approve (reject, close, reopen, send back from verification). */
const QC_FLOW = [
    'Draft' => ['Submitted' => 'report'],
    'Submitted' => ['Under review' => 'handle', 'Investigation' => 'handle', 'Rejected' => 'approve'],
    'Under review' => ['Investigation' => 'handle', 'Rejected' => 'approve', 'Closed' => 'approve'],
    'Investigation' => ['Action in progress' => 'handle', 'Under review' => 'handle', 'Closed' => 'approve'],
    'Action in progress' => ['Pending verification' => 'handle', 'Investigation' => 'handle'],
    'Pending verification' => ['Closed' => 'approve', 'Action in progress' => 'approve'],
    'Closed' => ['Reopened' => 'approve'], 'Rejected' => ['Reopened' => 'approve'],
    'Reopened' => ['Investigation' => 'handle'],
];
/** Moves that need a written reason: "to" or "to<from". */
const QC_NOTE_NEEDED = ['Rejected', 'Reopened', 'Under review<Investigation', 'Investigation<Action in progress', 'Action in progress<Pending verification'];
const QC_SEVERITY = ['Critical', 'High', 'Medium', 'Low'];
const QC_QTY_SCOPES = ['lot' => 'The whole lot', 'pieces' => 'Some pieces'];
const QC_ACTION_TYPES = ['containment' => 'Containment (stop it now)', 'corrective' => 'Corrective (fix the cause)', 'preventive' => 'Preventive (stop it elsewhere)'];
const QC_METHODS = ['5why' => '5 Whys', 'fishbone' => 'Fishbone', 'fault' => 'Fault analysis', 'other' => 'Other method'];
const QC_PRIORITY = ['High', 'Medium', 'Low'];
const QC_LINK_TYPES = ['repeat' => 'Repeat of this problem', 'related' => 'Related, different issue', 'not_same' => 'Not the same'];
const QC_FILE_MAX = 10485760; const QC_VIDEO_MAX = 15728640; const QC_FILES_MAX = 20;
const QC_STOP_WORDS = ['the', 'a', 'an', 'is', 'was', 'were', 'are', 'be', 'been', 'of', 'in', 'on', 'at', 'to', 'for', 'and', 'or', 'not', 'no', 'it', 'its',
                       'this', 'that', 'with', 'from', 'by', 'due', 'some', 'there', 'their', 'has', 'have', 'had', 'problem', 'issue', 'customer', 'complaint'];
const QC_DEFAULTS = [
    'handle_roles' => ['SUPERADMIN', 'QUALITY'], 'approve_roles' => ['SUPERADMIN', 'QUALITY'], 'view_roles' => ['CEO', 'MANAGER'],
    'categories' => "Product not working\nWorks on and off\nPhysical damage\nElectrical or connection issue\nMechanical issue\nInstallation issue\nPerformance issue\nQuality defect\nWrong or short item supplied\nBilling discrepancy\nDispatch or delivery issue\nDocumentation issue\nSupplier problem\nOther",
    'depts' => "Sales\nAccounts\nProduction\nQuality\nEngineering\nService\nPurchase\nStores\nDispatch\nOther",
    'close_no_capa' => ['Low'], 'target' => ['Critical' => 7, 'High' => 15, 'Medium' => 30, 'Low' => 45], 'sla' => ['Critical' => 1, 'High' => 3, 'Medium' => 7, 'Low' => 15],
    'match_min' => 70, 'prefix' => 'CC',
];

/* ---------------- pure ---------------- */

function qc_settings(): array { $s = module_states()['quality']['settings'] ?? []; return array_replace(QC_DEFAULTS, array_intersect_key($s, QC_DEFAULTS)); }
function qc_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['handle_roles', 'approve_roles', 'view_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['handle_roles']) $e[] = 'Pick at least one role that handles cases.';
    if (!$o['approve_roles']) $e[] = 'Pick at least one role that approves and closes.';
    foreach (['categories' => 60, 'depts' => 40] as $k => $n) $o[$k] = implode("\n", array_slice(crm_lines((string)($in[$k] ?? '')), 0, $n)) ?: QC_DEFAULTS[$k];
    $o['close_no_capa'] = array_values(array_intersect(QC_SEVERITY, (array)($in['close_no_capa'] ?? [])));
    foreach (['target' => [1, 365], 'sla' => [1, 90]] as $k => [$lo, $hi]) foreach (QC_SEVERITY as $s) $o[$k][$s] = max($lo, min($hi, (int)($in[$k][$s] ?? QC_DEFAULTS[$k][$s])));
    $o['match_min'] = max(40, min(100, (int)($in['match_min'] ?? 70)));
    $o['prefix'] = substr(strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($in['prefix'] ?? 'CC'))), 0, 6) ?: 'CC';
    return [$o, $e];
}
/** report = everyone the module lets in (own cases); handle; approve; view = every case in branch scope. */
function qc_rights_for(array $roles, array $set): array {
    $h = (bool)array_intersect($roles, $set['handle_roles']); $a = (bool)array_intersect($roles, $set['approve_roles']);
    return ['report' => true, 'handle' => $h, 'approve' => $a, 'view' => $h || $a || (bool)array_intersect($roles, $set['view_roles'])];
}
function qc_case_no(string $prefix, string $branch, string $fy, int $n): string {
    return $prefix . '/' . (strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $branch)) ?: 'BR') . '/' . $fy . '/' . sprintf('%04d', $n);
}
function qc_txt($v, int $n): string { return mb_substr(trim((string)preg_replace("/[ \t]+/u", ' ', strip_tags(str_replace("\r", '', (string)$v)))), 0, $n); }
/** A case form → [clean, errors]. $branches = ids the login may use; $cats = category list. */
function qc_case_input(array $in, array $branches, array $cats, string $today): array {
    $e = []; $t = fn($k, $n) => qc_txt($in[$k] ?? '', $n);
    $d = ['kind' => (string)($in['kind'] ?? 'customer'), 'severity' => (string)($in['severity'] ?? 'Medium'), 'safety' => empty($in['safety']) ? 0 : 1,
          'branch_id' => (int)($in['branch_id'] ?? 0), 'customer_id' => (int)($in['customer_id'] ?? 0) ?: null, 'customer_text' => $t('customer_text', 200),
          'contact_name' => $t('contact_name', 120), 'contact_phone' => crm_phone_norm($t('contact_phone', 30)), 'product_id' => (int)($in['product_id'] ?? 0) ?: null,
          'product_text' => $t('product_text', 200), 'serial_no' => $t('serial_no', 80), 'batch' => $t('batch', 80), 'qty_scope' => (string)($in['qty_scope'] ?? ''),
          'invoice_no' => $t('invoice_no', 40), 'category' => $t('category', 80), 'title' => $t('title', 160), 'description' => qc_txt($in['description'] ?? '', 5000),
          'first_seen' => trim((string)($in['first_seen'] ?? '')) ?: null, 'reported_on' => trim((string)($in['reported_on'] ?? '')) ?: $today, 'containment' => $t('containment', 1000)];
    if (!isset(QC_KINDS[$d['kind']])) { $e['kind'] = 'Pick the kind of problem.'; $d['kind'] = 'other'; }
    if (!in_array($d['severity'], QC_SEVERITY, true)) { $e['severity'] = 'Pick the severity.'; $d['severity'] = 'Medium'; }
    if ($d['safety']) $d['severity'] = 'Critical';
    if (!in_array($d['branch_id'], $branches, true)) $e['branch_id'] = 'Pick a branch in your scope.';
    if ($d['customer_text'] === '' && $d['product_text'] === '' && $d['description'] === '' && !$d['customer_id'] && !$d['product_id']) $e['description'] = 'Give at least a customer, a product or what happened.';
    if ($d['category'] !== '' && !in_array($d['category'], $cats, true)) $e['category'] = 'Pick a category from the list.';
    if ($d['qty_scope'] !== '' && !isset(QC_QTY_SCOPES[$d['qty_scope']])) $e['qty_scope'] = 'Pick the whole lot or some pieces.';
    foreach (['first_seen' => 'First seen', 'reported_on' => 'Reported on'] as $k => $l) if ($d[$k] !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[$k]) || $d[$k] > $today)) $e[$k] = "$l must be a date, not in the future.";
    $n = fn($k) => ($v = trim((string)($in[$k] ?? ''))) === '' ? null : (is_numeric($v) && (float)$v >= 0 ? round((float)$v, 3) : -1.0);
    $q = $n('qty'); $lot = $n('lot_qty');
    if ($q !== null && $q < 0) { $e['qty'] = 'Faulty pieces must be a number.'; $q = null; }
    if ($lot !== null && $lot < 0) { $e['lot_qty'] = 'The lot size must be a number.'; $lot = null; }
    if ($d['qty_scope'] === 'lot') $q = $lot;
    if ($d['qty_scope'] === 'pieces' && $q !== null && $lot !== null && $lot < $q) $e['lot_qty'] = 'The lot cannot be smaller than the faulty pieces.';
    $d['qty'] = $q; $d['lot_qty'] = $lot;
    return [$d, $e];
}
/** What a draft still needs before it is sent. */
function qc_submit_blocks(array $c): array {
    $b = [];
    if (in_array($c['kind'], ['customer', 'billing', 'dispatch', 'service'], true) && !$c['customer_id'] && trim((string)$c['customer_text']) === '') $b[] = 'the customer';
    if (!$c['product_id'] && trim((string)$c['product_text']) === '' && $c['kind'] !== 'billing') $b[] = 'the product';
    if (mb_strlen(trim((string)$c['description'])) < 10) $b[] = 'what happened (at least a sentence)';
    if ((string)$c['category'] === '') $b[] = 'a category';
    if (in_array($c['kind'], QC_PRODUCT_KINDS, true) && ($c['qty_scope'] === '' || $c['qty'] === null)) $b[] = 'how many pieces are faulty (whole lot or some pieces)';
    return $b;
}
/**
 * May this move happen? $x: rights [handle, approve], uid, reporter (created_by), note, closeNoCapa (severities), capa (null or
 * [rc_at, rc_by, eff_result, owners (action owner ids, not dropped), done, open]). Returns '' or the reason it is refused.
 */
function qc_move_check(array $c, string $to, array $x): string {
    $from = (string)$c['status'];
    if (!isset(QC_FLOW[$from][$to])) return "A case cannot go from $from to $to.";
    $right = QC_FLOW[$from][$to];
    if ($right === 'report' ? (int)$c['created_by'] !== (int)$x['uid'] : empty($x['rights'][$right])) return 'Your role cannot make this move.';
    $needNote = in_array($to, QC_NOTE_NEEDED, true) || in_array($to . '<' . $from, QC_NOTE_NEEDED, true) || ($to === 'Closed' && $from !== 'Pending verification');
    if ($needNote && trim((string)$x['note']) === '') return 'Write the reason.';
    $k = $x['capa'] ?? null;
    if ($to === 'Submitted' && ($b = qc_submit_blocks($c))) return 'Before sending, add ' . implode(', ', $b) . '.';
    if ($to === 'Action in progress' && $from === 'Investigation' && (!$k || ($k['done'] + $k['open']) < 1)) return 'Start the CAPA and add at least one action first.';
    if ($to === 'Pending verification') { if (!$k || !$k['done']) return 'Mark at least one action done first.'; if ($k['open']) return 'Some actions are still open: mark them done or drop them.'; }
    if ($to === 'Closed') {
        if ($from === 'Pending verification') {
            if (!$k || !$k['rc_at']) return 'The root cause is not confirmed yet.';
            if ($k['eff_result'] !== 'passed') return 'The effectiveness check has not passed yet.';
            if (in_array((int)$x['uid'], array_map('intval', $k['owners']), true) || (int)$k['rc_by'] === (int)$x['uid']) return 'Someone who owns an action or confirmed the root cause cannot close the case.';
        } elseif ($k) return 'A case with a CAPA closes after its effectiveness check.';
        elseif (!in_array($c['severity'], $x['closeNoCapa'], true)) return 'A ' . $c['severity'] . ' case needs a CAPA with a passed effectiveness check before it closes.';
    }
    return '';
}
/** Days after which an open case with no update counts as stale; target close date from submission. */
function qc_target_date(string $severity, array $set, string $from): string { return date('Y-m-d', strtotime($from . ' +' . (int)($set['target'][$severity] ?? 30) . ' day')); }
function qc_is_stale(array $c, array $set, string $now): bool {
    if (!in_array($c['status'], QC_OPEN, true)) return false;
    $last = (string)($c['last_event_at'] ?: $c['submitted_at'] ?: $c['created_at']);
    return strtotime($now) - strtotime($last) > 86400 * (int)($set['sla'][$c['severity']] ?? 7);
}
/** Lower case, letters and digits of any script, common words out. */
function qc_norm_text(string $s): string {
    $s = (string)preg_replace('/[^\p{L}\p{N}\p{M} ]+/u', ' ', mb_strtolower($s));
    return implode(' ', array_values(array_filter(preg_split('/\s+/u', trim($s)) ?: [], fn($w) => $w !== '' && !in_array($w, QC_STOP_WORDS, true))));
}
/** How alike two normalised texts are, 0–100 (best of similar_text, edit distance, word overlap; all words of the shorter → 90). */
function qc_sim(string $a, string $b): int {
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 100;
    $x = substr($a, 0, 255); $y = substr($b, 0, 255);
    similar_text($x, $y, $p);
    $lev = (int)round(100 * (1 - levenshtein($x, $y) / max(strlen($x), strlen($y), 1)));
    $wa = array_unique(explode(' ', $a)); $wb = array_unique(explode(' ', $b)); $common = count(array_intersect($wa, $wb));
    $jac = (int)round(100 * $common / max(1, count(array_unique(array_merge($wa, $wb)))));
    $small = min(count($wa), count($wb));
    return (int)max(round($p), $lev, $jac, $small >= 3 && $common === $small ? 90 : 0);
}
/** Why two cases look like the same problem: exact (serial / batch + product / invoice + product / quoted number) or a score. */
function qc_match_why(array $c, array $o, int $min): ?array {
    $same = fn($k) => trim((string)$c[$k]) !== '' && mb_strtolower(trim((string)$c[$k])) === mb_strtolower(trim((string)$o[$k]));
    $prod = ($c['product_id'] && (int)$c['product_id'] === (int)$o['product_id']) || $same('product_text');
    if ($same('serial_no')) return ['exact' => true, 'score' => 100, 'why' => 'Same serial number'];
    if ($prod && $same('batch')) return ['exact' => true, 'score' => 100, 'why' => 'Same batch and product'];
    if ($prod && $same('invoice_no')) return ['exact' => true, 'score' => 100, 'why' => 'Same invoice and product'];
    if ($o['case_no'] !== '' && str_contains(mb_strtolower((string)$c['description']), mb_strtolower((string)$o['case_no']))) return ['exact' => true, 'score' => 100, 'why' => 'Mentions ' . $o['case_no']];
    if (!$prod) return null;
    $s = qc_sim(qc_norm_text($c['title'] . ' ' . $c['description']), qc_norm_text($o['title'] . ' ' . $o['description']));
    return $s >= $min ? ['exact' => false, 'score' => $s, 'why' => 'Same product, words ' . $s . '% alike'] : null;
}
/** Root (earliest case) for every case in groups joined by confirmed repeats. $cases: id => reported_on; $pairs: [[a, b]]. */
function qc_roots(array $cases, array $pairs): array {
    $p = []; $find = function ($x) use (&$p, &$find) { $p[$x] ??= $x; return $p[$x] === $x ? $x : ($p[$x] = $find($p[$x])); };
    foreach ($pairs as [$a, $b]) { $ra = $find($a); $rb = $find($b); if ($ra !== $rb) $p[$ra] = $rb; }
    $groups = []; foreach (array_keys($cases) as $id) $groups[$find($id)][] = $id;
    $out = [];
    foreach ($groups as $ids) {
        if (count($ids) < 2) { $out[$ids[0]] = null; continue; }
        usort($ids, fn($x, $y) => [$cases[$x], $x] <=> [$cases[$y], $y]);
        foreach ($ids as $id) $out[$id] = $ids[0];
    }
    return $out;
}
/** Evidence type by its own bytes: PDF / JPEG / PNG / WebP, MP4 / MOV video. */
function qc_file_mime(string $bytes): ?string {
    if ($m = kyc_doc_mime($bytes)) return $m;
    if (substr($bytes, 4, 4) === 'ftyp') return substr($bytes, 8, 2) === 'qt' ? 'video/quicktime' : 'video/mp4';
    return null;
}
function qc_csv_cell($v): string { $v = (string)$v; if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; }
/** Counts for the dashboard from case rows (drafts and rejected left out). */
function qc_summary(array $rows, array $set, string $now): array {
    $o = ['total' => 0, 'open' => 0, 'closed' => 0, 'overdue' => 0, 'stale' => 0, 'repeats' => 0, 'safety' => 0, 'bySeverity' => [], 'byCategory' => [], 'byStatus' => [], 'closeDays' => null];
    $days = [];
    foreach ($rows as $c) {
        if (in_array($c['status'], ['Draft', 'Rejected'], true)) continue;
        $o['total']++; $o['byStatus'][$c['status']] = ($o['byStatus'][$c['status']] ?? 0) + 1;
        $o['byCategory'][$c['category'] ?: 'No category'] = ($o['byCategory'][$c['category'] ?: 'No category'] ?? 0) + 1;
        if ($c['root_id'] && (int)$c['root_id'] !== (int)$c['id']) $o['repeats']++;
        if ($c['safety']) $o['safety']++;
        if (in_array($c['status'], QC_OPEN, true)) {
            $o['open']++; $o['bySeverity'][$c['severity']] = ($o['bySeverity'][$c['severity']] ?? 0) + 1;
            if ($c['target_close'] && $c['target_close'] < substr($now, 0, 10)) $o['overdue']++;
            if (qc_is_stale($c, $set, $now)) $o['stale']++;
        }
        if ($c['status'] === 'Closed' && $c['submitted_at'] && $c['closed_at']) { $o['closed']++; $days[] = (strtotime($c['closed_at']) - strtotime($c['submitted_at'])) / 86400; }
    }
    if ($days) $o['closeDays'] = round(array_sum($days) / count($days), 1);
    return $o;
}

/* ---------------- database ---------------- */

function qc_rights(array $u): array { return qc_rights_for(user_roles((int)$u['id'], !empty($u['is_owner'])), qc_settings()); }
function qc_cats(): array { return crm_lines(qc_settings()['categories']); }
function qc_branches_for(array $u): array { return array_values(array_filter(array_map('intval', array_column(branches(), 'id')), fn($b) => emp_in_scope($u, ['branch_id' => $b]))); }
function qc_case(PDO $db, int $id, bool $lock = false): ?array { $s = $db->prepare('SELECT * FROM qc_case WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')); $s->execute([$id]); return $s->fetch() ?: null; }
function qc_owns_action(PDO $db, int $caseId, int $uid): bool {
    $s = $db->prepare("SELECT 1 FROM qc_action a JOIN qc_capa k ON k.id = a.capa_id WHERE k.case_id = ? AND a.owner_id = ? AND a.status <> 'dropped' LIMIT 1"); $s->execute([$caseId, $uid]);
    return (bool)$s->fetchColumn();
}
/** Drafts: the reporter only. Others: reporter, owner, an action owner, or view rights in branch scope. */
function qc_sees(PDO $db, array $u, array $c): bool {
    $me = (int)$u['id'];
    if ($c['status'] === 'Draft') return (int)$c['created_by'] === $me;
    if ((int)$c['created_by'] === $me || (int)$c['owner_id'] === $me) return true;
    if (qc_rights($u)['view'] && emp_in_scope($u, ['branch_id' => $c['branch_id']])) return true;
    return qc_owns_action($db, (int)$c['id'], $me);
}
function qc_need(PDO $db, array $u, ?array $c, string $right = ''): array {
    if (!$c || !qc_sees($db, $u, $c)) throw new RuntimeException('Case not found.');
    if ($right !== '' && (!qc_rights($u)[$right] || !emp_in_scope($u, ['branch_id' => $c['branch_id']]))) throw new RuntimeException('Your role cannot do this.');
    return $c;
}
function qc_event(PDO $db, int $caseId, ?int $by, string $kind, string $note = '', ?string $from = null, ?string $to = null, ?string $before = null): void {
    $db->prepare('INSERT INTO qc_event (case_id, kind, from_status, to_status, note, before_text, by_user) VALUES (?,?,?,?,?,?,?)')->execute([$caseId, $kind, $from, $to, mb_substr($note, 0, 5000), $before, $by]);
    $db->prepare('UPDATE qc_case SET last_event_at = NOW() WHERE id = ?')->execute([$caseId]);
}
function qc_people_with(PDO $db, string $right, int $branch): array {
    $set = qc_settings(); $roles = $set[$right . '_roles'] ?? []; if (!$roles) return [];
    $s = $db->prepare('SELECT DISTINCT u.id FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ')');
    $s->execute($roles); $o = [];
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) { $uu = ['id' => (int)$id, 'branches' => user_branch_ids((int)$id)]; if (emp_in_scope($uu, ['branch_id' => $branch])) $o[] = (int)$id; }
    return $o;
}
function qc_tell(PDO $db, int $uid, array $c, string $title, string $body): void { notify_user($db, $uid, 'quality', $title . ' · ' . $c['case_no'], mb_substr($body, 0, 200), wn_base() . '/modules/quality/index.php?view=c&id=' . (int)$c['id']); }

/** Save a case (new or a draft / open case). The same request id returns the same case. */
function qc_save(PDO $db, array $u, array $in, int $id = 0): int {
    [$d, $e] = qc_case_input($in, qc_branches_for($u), qc_cats(), date('Y-m-d'));
    if ($e) throw new RuntimeException(implode(' ', array_unique($e)));
    $uid = (int)$u['id'];
    if ($d['customer_id']) { $s = $db->prepare('SELECT name FROM crm_customer WHERE id = ?'); $s->execute([$d['customer_id']]); if (!($n = $s->fetchColumn())) throw new RuntimeException('Customer not found.'); $d['customer_text'] = (string)$n; }
    if ($d['product_id']) { $s = $db->prepare('SELECT name FROM crm_product WHERE id = ?'); $s->execute([$d['product_id']]); if (!($n = $s->fetchColumn())) throw new RuntimeException('Product not found.'); $d['product_text'] = (string)$n; }
    if (!$id) {
        $rid = (string)($in['request_id'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/', $rid)) throw new RuntimeException('Reload the form and try again.');
        $s = $db->prepare('SELECT id FROM qc_case WHERE request_id = ?'); $s->execute([$rid]); if ($x = (int)$s->fetchColumn()) return $x;
        $db->beginTransaction();
        try {
            $fy = so_fy_enq($d['reported_on']);
            $db->prepare('INSERT INTO qc_seq (branch_id, fy, n) VALUES (?,?,1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$d['branch_id'], $fy]);
            $n = $db->prepare('SELECT n FROM qc_seq WHERE branch_id = ? AND fy = ?'); $n->execute([$d['branch_id'], $fy]);
            $b = $db->prepare('SELECT code FROM branch WHERE id = ?'); $b->execute([$d['branch_id']]);
            $d += ['case_no' => qc_case_no(qc_settings()['prefix'], (string)$b->fetchColumn(), $fy, (int)$n->fetchColumn()), 'request_id' => $rid, 'created_by' => $uid];
            $db->prepare('INSERT INTO qc_case (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            $id = (int)$db->lastInsertId(); qc_event($db, $id, $uid, 'edit', 'Case opened as a draft');
            $db->commit();
        } catch (Throwable $x) { $db->rollBack(); throw $x; }
        audit($uid, 'qc_add', '#' . $id);
        return $id;
    }
    $db->beginTransaction();
    try {
        $c = qc_case($db, $id, true);
        if (!$c || !qc_sees($db, $u, $c)) throw new RuntimeException('Case not found.');
        if (in_array($c['status'], QC_LOCKED, true)) throw new RuntimeException('A closed or rejected case is locked. Reopen it first.');
        if (!($c['status'] === 'Draft' ? (int)$c['created_by'] === $uid : qc_rights($u)['handle'])) throw new RuntimeException('Your role cannot change this case.');
        if ((int)($in['version'] ?? 0) && (int)$in['version'] !== (int)$c['version']) throw new RuntimeException('Someone else changed this case. Reload it and try again.');
        if ($c['status'] !== 'Draft') unset($d['branch_id']);
        $ch = array_keys(array_filter($d, fn($v, $k) => (string)$v !== (string)$c[$k], ARRAY_FILTER_USE_BOTH));
        if ($ch) {
            $db->prepare('UPDATE qc_case SET ' . implode(', ', array_map(fn($k) => "$k = ?", $ch)) . ', version = version + 1 WHERE id = ?')->execute([...array_map(fn($k) => $d[$k], $ch), $id]);
            qc_event($db, $id, $uid, 'edit', 'Changed: ' . implode(', ', $ch), null, null, in_array('description', $ch, true) ? (string)$c['description'] : null);
        }
        $db->commit();
    } catch (Throwable $x) { $db->rollBack(); throw $x; }
    return $id;
}
function qc_capa(PDO $db, int $caseId): ?array { $s = $db->prepare('SELECT * FROM qc_capa WHERE case_id = ?'); $s->execute([$caseId]); return $s->fetch() ?: null; }
function qc_capa_facts(PDO $db, ?array $k): ?array {
    if (!$k) return null;
    $s = $db->prepare('SELECT owner_id, status FROM qc_action WHERE capa_id = ?'); $s->execute([$k['id']]); $o = ['owners' => [], 'done' => 0, 'open' => 0];
    foreach ($s->fetchAll() as $a) { if ($a['status'] !== 'dropped') $o['owners'][] = (int)$a['owner_id']; if ($a['status'] === 'done') $o['done']++; if ($a['status'] === 'open') $o['open']++; }
    return $o + ['rc_at' => $k['rc_at'], 'rc_by' => $k['rc_by'], 'eff_result' => $k['eff_result']];
}
/** Moves this login may make now (to => needs a note). */
function qc_moves(PDO $db, array $u, array $c): array {
    $x = ['rights' => qc_rights($u), 'uid' => (int)$u['id'], 'note' => 'x', 'closeNoCapa' => qc_settings()['close_no_capa'], 'capa' => qc_capa_facts($db, qc_capa($db, (int)$c['id']))];
    $out = [];
    foreach (array_keys(QC_FLOW[$c['status']] ?? []) as $to) {
        $r = qc_move_check($c, $to, $x);
        if ($r === '' || str_starts_with($r, 'Before sending')) $out[$to] = in_array($to, QC_NOTE_NEEDED, true) || in_array($to . '<' . $c['status'], QC_NOTE_NEEDED, true) || ($to === 'Closed' && $c['status'] !== 'Pending verification');
    }
    return $out;
}
function qc_status(PDO $db, array $u, int $id, string $to, string $note): void {
    $note = qc_txt($note, 2000); $uid = (int)$u['id']; $tell = [];
    $db->beginTransaction();
    try {
        $c = qc_case($db, $id, true);
        if (!$c || !qc_sees($db, $u, $c)) throw new RuntimeException('Case not found.');
        if ($c['status'] !== 'Draft' && !emp_in_scope($u, ['branch_id' => $c['branch_id']])) throw new RuntimeException('That case is outside your branches.');
        $set = qc_settings(); $k = qc_capa($db, $id);
        $r = qc_move_check($c, $to, ['rights' => qc_rights($u), 'uid' => $uid, 'note' => $note, 'closeNoCapa' => $set['close_no_capa'], 'capa' => qc_capa_facts($db, $k)]);
        if ($r !== '') throw new RuntimeException($r);
        $from = $c['status']; $x = [];
        if ($to === 'Submitted') { $x['submitted_at'] = date('Y-m-d H:i:s'); if (!$c['target_close']) $x['target_close'] = qc_target_date($c['severity'], $set, date('Y-m-d')); }
        if ($from === 'Submitted' && !$c['first_response_at']) $x['first_response_at'] = date('Y-m-d H:i:s');
        if ($to === 'Closed') $x['closed_at'] = date('Y-m-d H:i:s');
        if ($to === 'Reopened') { $x['closed_at'] = null; if ($k && $k['eff_result'] === 'passed') { $db->prepare("UPDATE qc_capa SET eff_result = '', version = version + 1 WHERE id = ?")->execute([$k['id']]); qc_event($db, $id, $uid, 'capa', 'Effectiveness check reset: the case was reopened'); } }
        $cols = ['status' => $to] + $x;
        $db->prepare('UPDATE qc_case SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($cols))) . ', version = version + 1 WHERE id = ?')->execute([...array_values($cols), $id]);
        qc_event($db, $id, $uid, 'status', $note, $from, $to);
        $c['status'] = $to;
        if ($to === 'Submitted') {
            $urgent = $c['severity'] === 'Critical' || $c['safety'];
            $who = qc_people_with($db, 'handle', (int)$c['branch_id']); if ($urgent) $who = array_merge($who, qc_people_with($db, 'approve', (int)$c['branch_id']));
            foreach (array_unique($who) as $w) if ($w !== $uid) $tell[] = [$w, $urgent ? 'Critical complaint' . ($c['safety'] ? ' (safety)' : '') : 'New complaint', trim($c['product_text'] . ' · ' . $c['customer_text'], ' ·')];
        } elseif ((int)$c['created_by'] !== $uid && in_array($to, ['Closed', 'Rejected', 'Reopened'], true)) $tell[] = [(int)$c['created_by'], 'Complaint ' . strtolower($to), $note ?: $to];
        if ($c['owner_id'] && (int)$c['owner_id'] !== $uid && $to !== 'Submitted') $tell[] = [(int)$c['owner_id'], 'Case moved to ' . $to, $note ?: $to];
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    foreach ($tell as [$w, $t, $b]) qc_tell($db, $w, $c, $t, $b);
    audit($uid, 'qc_status', '#' . $id . ' ' . $from . ' → ' . $to);
}
/** Owner, department, severity, safety, target date (handle). Moving the target date needs a reason. */
function qc_assign(PDO $db, array $u, int $id, array $in): void {
    $c = qc_need($db, $u, qc_case($db, $id), 'handle'); $uid = (int)$u['id'];
    if (!in_array($c['status'], QC_OPEN, true)) throw new RuntimeException('Only an open case is assigned.');
    $set = qc_settings(); $ch = []; $note = qc_txt($in['note'] ?? '', 1000);
    $owner = (int)($in['owner_id'] ?? 0) ?: null;
    if ($owner) { $s = $db->prepare('SELECT 1 FROM users WHERE id = ? AND active = 1'); $s->execute([$owner]); if (!$s->fetchColumn()) throw new RuntimeException('Pick an active login as owner.'); }
    if ($owner !== ($c['owner_id'] ? (int)$c['owner_id'] : null)) $ch['owner_id'] = $owner;
    $dept = qc_txt($in['dept'] ?? '', 60); if ($dept !== '' && !in_array($dept, crm_lines($set['depts']), true)) throw new RuntimeException('Pick a department from the list.');
    if ($dept !== $c['dept']) $ch['dept'] = $dept;
    $safety = empty($in['safety']) ? 0 : 1; $sev = $safety ? 'Critical' : (string)($in['severity'] ?? $c['severity']);
    if (!in_array($sev, QC_SEVERITY, true)) throw new RuntimeException('Pick the severity.');
    if ($sev !== $c['severity']) $ch['severity'] = $sev; if ($safety !== (int)$c['safety']) $ch['safety'] = $safety;
    $t = trim((string)($in['target_close'] ?? '')) ?: null;
    if ($t !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) throw new RuntimeException('The target date is not a date.');
    if ($t !== $c['target_close']) { if ($c['target_close'] && $note === '') throw new RuntimeException('Say why the target date moves.'); $ch['target_close'] = $t; }
    if (!$ch) return;
    if (!$c['first_response_at']) $ch['first_response_at'] = date('Y-m-d H:i:s');
    $db->prepare('UPDATE qc_case SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($ch))) . ', version = version + 1 WHERE id = ?')->execute([...array_values($ch), $id]);
    qc_event($db, $id, $uid, 'assign', 'Changed: ' . implode(', ', array_diff(array_keys($ch), ['first_response_at'])) . ($note !== '' ? ' — ' . $note : ''));
    if (isset($ch['owner_id']) && $owner && $owner !== $uid) qc_tell($db, $owner, $c, 'Case for you', $c['product_text'] ?: $c['title']);
    audit($uid, 'qc_assign', '#' . $id);
}
function qc_note(PDO $db, array $u, int $id, string $note): void {
    $c = qc_need($db, $u, qc_case($db, $id)); $note = qc_txt($note, 2000);
    if ($note === '') throw new RuntimeException('Write the note.');
    qc_event($db, $id, (int)$u['id'], 'note', $note);
    if ($c['owner_id'] && (int)$c['owner_id'] !== (int)$u['id']) qc_tell($db, (int)$c['owner_id'], $c, 'Note on a case', $note);
}

/* ---- evidence ---- */

function qc_file_add(PDO $db, array $u, int $id, string $tmp, string $name): int {
    $c = qc_need($db, $u, qc_case($db, $id));
    if (in_array($c['status'], QC_LOCKED, true)) throw new RuntimeException('A closed or rejected case is locked.');
    $n = $db->prepare('SELECT COUNT(*) FROM qc_file WHERE case_id = ? AND active = 1'); $n->execute([$id]); if ((int)$n->fetchColumn() >= QC_FILES_MAX) throw new RuntimeException('A case keeps at most ' . QC_FILES_MAX . ' files.');
    $bytes = (string)file_get_contents($tmp); @unlink($tmp);
    $mime = qc_file_mime($bytes); if (!$mime) throw new RuntimeException('Add a photo (JPEG / PNG / WebP), a PDF or a video (MP4 / MOV).');
    if (strlen($bytes) > (str_starts_with($mime, 'video/') ? QC_VIDEO_MAX : QC_FILE_MAX)) throw new RuntimeException('The file is too big.');
    $db->prepare('INSERT INTO qc_file (case_id, name, mime, size, sha256, data, by_user) VALUES (?,?,?,?,?,?,?)')->execute([$id, mb_substr(basename($name), 0, 160) ?: 'file', $mime, strlen($bytes), hash('sha256', $bytes), $bytes, (int)$u['id']]);
    $fid = (int)$db->lastInsertId(); qc_event($db, $id, (int)$u['id'], 'file', 'File added: ' . mb_substr(basename($name), 0, 160));
    return $fid;
}
function qc_file_off(PDO $db, array $u, int $fid, string $reason): void {
    $s = $db->prepare('SELECT * FROM qc_file WHERE id = ? AND active = 1'); $s->execute([$fid]); $f = $s->fetch(); if (!$f) throw new RuntimeException('File not found.');
    $c = qc_need($db, $u, qc_case($db, (int)$f['case_id']));
    if ((int)$f['by_user'] !== (int)$u['id'] && !qc_rights($u)['handle']) throw new RuntimeException('Only who added it or a handler takes a file off.');
    $reason = qc_txt($reason, 300); if ($reason === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE qc_file SET active = 0, off_reason = ?, off_by = ? WHERE id = ?')->execute([$reason, (int)$u['id'], $fid]);
    qc_event($db, (int)$c['id'], (int)$u['id'], 'file', 'File taken off: ' . $f['name'] . ' — ' . $reason);
}
function qc_file_get(PDO $db, array $u, int $fid): array {
    $s = $db->prepare('SELECT * FROM qc_file WHERE id = ?'); $s->execute([$fid]); $f = $s->fetch(); if (!$f) throw new RuntimeException('File not found.');
    qc_need($db, $u, qc_case($db, (int)$f['case_id']));
    audit((int)$u['id'], 'qc_file_view', '#' . $fid);
    return $f;
}

/* ---- repeats ---- */

/** Cases that may be the same problem (not drafts, not itself, not already linked or marked not the same), up to 5. */
function qc_matches(PDO $db, array $c): array {
    $set = qc_settings();
    $s = $db->prepare("SELECT * FROM qc_case WHERE id <> ? AND status <> 'Draft' AND reported_on >= ? AND id NOT IN (SELECT b_id FROM qc_link WHERE a_id = ? UNION SELECT a_id FROM qc_link WHERE b_id = ?) ORDER BY id DESC LIMIT 2000");
    $s->execute([$c['id'], date('Y-m-d', strtotime($c['reported_on'] . ' -3 year')), $c['id'], $c['id']]);
    $out = [];
    foreach ($s->fetchAll() as $o) if ($w = qc_match_why($c, $o, (int)$set['match_min'])) $out[] = $w + ['case' => $o];
    usort($out, fn($a, $b) => [$b['exact'], $b['score']] <=> [$a['exact'], $a['score']]);
    return array_slice($out, 0, 5);
}
/** Mark two cases (handle): repeat / related / not_same. Roots are rebuilt so every repeat points at the earliest case. */
function qc_link_set(PDO $db, array $u, int $id, int $other, string $type, string $note = ''): void {
    if (!isset(QC_LINK_TYPES[$type])) throw new RuntimeException('Pick how the cases relate.');
    $c = qc_need($db, $u, qc_case($db, $id), 'handle'); $o = qc_need($db, $u, qc_case($db, $other));
    if ($id === $other || $o['status'] === 'Draft') throw new RuntimeException('Pick another case.');
    [$a, $b] = $id < $other ? [$id, $other] : [$other, $id];
    $db->prepare("INSERT INTO qc_link (a_id, b_id, type, note, by_user, decided_by, decided_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE type = VALUES(type), note = VALUES(note), decided_by = VALUES(decided_by), decided_at = NOW()")
       ->execute([$a, $b, $type, qc_txt($note, 500), (int)$u['id'], (int)$u['id']]);
    foreach ([$id, $other] as $x) qc_event($db, $x, (int)$u['id'], 'link', QC_LINK_TYPES[$type] . ': ' . ($x === $id ? $o['case_no'] : $c['case_no']));
    qc_rebuild_roots($db);
    audit((int)$u['id'], 'qc_link', '#' . $a . '-' . $b . ' ' . $type);
}
function qc_rebuild_roots(PDO $db): void {
    $pairs = $db->query("SELECT a_id, b_id FROM qc_link WHERE type = 'repeat'")->fetchAll(PDO::FETCH_NUM);
    $ids = array_unique(array_merge(...array_map(fn($p) => [(int)$p[0], (int)$p[1]], $pairs ?: [[0, 0]])));
    $ids = array_filter($ids);
    $db->exec("UPDATE qc_case SET root_id = NULL WHERE root_id IS NOT NULL");
    if (!$ids) return;
    $s = $db->query('SELECT id, reported_on FROM qc_case WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    $roots = qc_roots(array_map('strval', $s->fetchAll(PDO::FETCH_KEY_PAIR)), array_map(fn($p) => [(int)$p[0], (int)$p[1]], $pairs));
    $up = $db->prepare('UPDATE qc_case SET root_id = ? WHERE id = ?');
    foreach ($roots as $id => $r) if ($r) $up->execute([$r, $id]);
}
function qc_links(PDO $db, int $id): array {
    $s = $db->prepare('SELECT l.*, c.id AS other_id, c.case_no, c.status, c.reported_on FROM qc_link l JOIN qc_case c ON c.id = IF(l.a_id = ?, l.b_id, l.a_id) WHERE l.a_id = ? OR l.b_id = ? ORDER BY c.reported_on');
    $s->execute([$id, $id, $id]); return $s->fetchAll();
}

/* ---- CAPA ---- */

function qc_capa_start(PDO $db, array $u, int $id): int {
    $c = qc_need($db, $u, qc_case($db, $id), 'handle');
    if (!in_array($c['status'], ['Under review', 'Investigation', 'Reopened', 'Submitted'], true)) throw new RuntimeException('A CAPA is started while the case is reviewed or investigated.');
    if ($k = qc_capa($db, $id)) return (int)$k['id'];
    $db->prepare('INSERT INTO qc_capa (case_id, statement, created_by) VALUES (?,?,?)')->execute([$id, mb_substr($c['title'] ?: $c['description'], 0, 3000), (int)$u['id']]);
    $kid = (int)$db->lastInsertId(); qc_event($db, $id, (int)$u['id'], 'capa', 'CAPA started');
    return $kid;
}
function qc_capa_need(PDO $db, array $u, int $kid, string $right): array {
    $s = $db->prepare('SELECT * FROM qc_capa WHERE id = ?'); $s->execute([$kid]); $k = $s->fetch(); if (!$k) throw new RuntimeException('CAPA not found.');
    $c = qc_need($db, $u, qc_case($db, (int)$k['case_id']), $right);
    if (in_array($c['status'], QC_LOCKED, true)) throw new RuntimeException('The case is closed. Reopen it first.');
    return [$k, $c];
}
function qc_whys(PDO $db, int $kid): array { $s = $db->prepare('SELECT step, text FROM qc_why WHERE capa_id = ? ORDER BY step'); $s->execute([$kid]); return $s->fetchAll(PDO::FETCH_KEY_PAIR); }
function qc_capa_save(PDO $db, array $u, int $kid, array $in): void {
    [$k, $c] = qc_capa_need($db, $u, $kid, 'handle'); $locked = (bool)$k['rc_at']; $ch = [];
    foreach (['statement' => 3000, 'contributing' => 3000, 'root_cause' => 3000, 'eff_criteria' => 1000] as $f => $n) if (array_key_exists($f, $in)) { $v = qc_txt($in[$f], $n); if ($v !== (string)$k[$f]) $ch[$f] = $v; }
    if (isset($ch['statement']) && $ch['statement'] === '') throw new RuntimeException('The problem statement cannot be empty.');
    if (isset($in['method']) && $in['method'] !== $k['method']) { if (!isset(QC_METHODS[$in['method']])) throw new RuntimeException('Pick a method.'); $ch['method'] = (string)$in['method']; }
    if (array_key_exists('eff_review_on', $in)) { $d = trim((string)$in['eff_review_on']) ?: null; if ($d !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new RuntimeException('The review date is not a date.'); if ($d !== $k['eff_review_on']) $ch['eff_review_on'] = $d; }
    $prev = qc_whys($db, $kid); $why = [];
    foreach ((array)($in['whys'] ?? []) as $i => $t) { $i = (int)$i; if ($i < 1 || $i > 5) continue; $t = qc_txt($t, 500); if ($t !== (string)($prev[$i] ?? '')) $why[$i] = $t; }
    if ($locked && ($why || isset($ch['root_cause']) || isset($ch['method']))) throw new RuntimeException('The root cause is confirmed and locked.');
    if (!$ch && !$why) return;
    if ($ch) $db->prepare('UPDATE qc_capa SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($ch))) . ', version = version + 1 WHERE id = ?')->execute([...array_values($ch), $kid]);
    foreach ($why as $i => $t) $db->prepare('INSERT INTO qc_why (capa_id, step, text) VALUES (?,?,?) ON DUPLICATE KEY UPDATE text = VALUES(text)')->execute([$kid, $i, $t]);
    qc_event($db, (int)$c['id'], (int)$u['id'], 'capa', 'CAPA changed: ' . implode(', ', array_merge(array_keys($ch), $why ? ['why ' . implode(', ', array_keys($why))] : [])), null, null, isset($ch['root_cause']) && $k['root_cause'] !== '' ? (string)$k['root_cause'] : null);
}
/** Confirm the root cause (approve; never an action owner). With 5 Whys at least one why is filled. */
function qc_capa_confirm(PDO $db, array $u, int $kid, bool $on, string $reason = ''): void {
    [$k, $c] = qc_capa_need($db, $u, $kid, 'approve'); $uid = (int)$u['id'];
    if ($on) {
        if ($k['rc_at']) throw new RuntimeException('The root cause is already confirmed.');
        if (trim((string)$k['root_cause']) === '') throw new RuntimeException('Write the root cause first.');
        if ($k['method'] === '5why' && !array_filter(qc_whys($db, $kid), 'strlen')) throw new RuntimeException('Fill in the whys that led to the root cause.');
        if (qc_owns_action($db, (int)$c['id'], $uid)) throw new RuntimeException('Someone who owns an action of this CAPA cannot confirm its root cause.');
        $db->prepare('UPDATE qc_capa SET rc_by = ?, rc_at = NOW(), version = version + 1 WHERE id = ?')->execute([$uid, $kid]);
        qc_event($db, (int)$c['id'], $uid, 'capa', 'Root cause confirmed: ' . mb_substr($k['root_cause'], 0, 1000));
    } else {
        if (!$k['rc_at']) throw new RuntimeException('The root cause is not confirmed.');
        $reason = qc_txt($reason, 1000); if ($reason === '') throw new RuntimeException('Write the reason.');
        $db->prepare("UPDATE qc_capa SET rc_by = NULL, rc_at = NULL, eff_result = '', version = version + 1 WHERE id = ?")->execute([$kid]);
        qc_event($db, (int)$c['id'], $uid, 'capa', 'Root cause opened again: ' . $reason);
    }
    audit($uid, 'qc_rc', '#' . $kid . ($on ? ' confirmed' : ' opened'));
}
function qc_action_add(PDO $db, array $u, int $kid, array $in): int {
    [$k, $c] = qc_capa_need($db, $u, $kid, 'handle');
    if ($k['eff_result'] === 'passed') throw new RuntimeException('This CAPA was verified. Reopen the case to add actions.');
    $type = (string)($in['type'] ?? ''); if (!isset(QC_ACTION_TYPES[$type])) throw new RuntimeException('Pick containment, corrective or preventive.');
    $text = qc_txt($in['text'] ?? '', 500); if (mb_strlen($text) < 5) throw new RuntimeException('Describe the action.');
    $owner = (int)($in['owner_id'] ?? 0); $s = $db->prepare('SELECT 1 FROM users WHERE id = ? AND active = 1'); $s->execute([$owner]); if (!$owner || !$s->fetchColumn()) throw new RuntimeException('Pick the action owner.');
    $due = (string)($in['due'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < date('Y-m-d')) throw new RuntimeException('Give a due date, not in the past.');
    $prio = (string)($in['priority'] ?? 'Medium'); if (!in_array($prio, QC_PRIORITY, true)) throw new RuntimeException('Pick a priority.');
    $db->prepare('INSERT INTO qc_action (capa_id, type, text, owner_id, priority, due, created_by) VALUES (?,?,?,?,?,?,?)')->execute([$kid, $type, $text, $owner, $prio, $due, (int)$u['id']]);
    $aid = (int)$db->lastInsertId();
    qc_event($db, (int)$c['id'], (int)$u['id'], 'action', ucfirst($type) . ' action by ' . $due . ': ' . $text);
    if ($owner !== (int)$u['id']) qc_tell($db, $owner, $c, 'CAPA action for you', $text . ' · due ' . $due);
    return $aid;
}
/** done (owner or handler, with how), reopen / drop (handler, reason), due (owner or handler, reason). */
function qc_action_update(PDO $db, array $u, int $aid, string $what, array $in): void {
    $s = $db->prepare('SELECT * FROM qc_action WHERE id = ?'); $s->execute([$aid]); $x = $s->fetch(); if (!$x) throw new RuntimeException('Action not found.');
    $s = $db->prepare('SELECT * FROM qc_capa WHERE id = ?'); $s->execute([$x['capa_id']]); $k = $s->fetch();
    $c = qc_need($db, $u, qc_case($db, (int)$k['case_id']));
    if (in_array($c['status'], QC_LOCKED, true) || $k['eff_result'] === 'passed') throw new RuntimeException('This CAPA is verified or the case is closed.');
    $inv = qc_rights($u)['handle'] && emp_in_scope($u, ['branch_id' => $c['branch_id']]); $mine = (int)$x['owner_id'] === (int)$u['id'];
    $note = qc_txt($in['note'] ?? '', 1000);
    switch ($what) {
        case 'done': if (!$mine && !$inv) throw new RuntimeException('Your role cannot do this.'); if ($x['status'] !== 'open') throw new RuntimeException('This action is not open.');
            if (mb_strlen($note) < 5) throw new RuntimeException('Say how it was done (the evidence).');
            $db->prepare("UPDATE qc_action SET status = 'done', done_at = NOW(), done_note = ? WHERE id = ?")->execute([$note, $aid]); $line = 'Action done: ' . $x['text'] . ' — ' . $note; break;
        case 'reopen': case 'drop': if (!$inv) throw new RuntimeException('Your role cannot do this.'); if ($note === '') throw new RuntimeException('Write the reason.');
            if ($what === 'drop' && $x['status'] !== 'open') throw new RuntimeException('This action is not open.'); if ($what === 'reopen' && $x['status'] === 'open') throw new RuntimeException('This action is open already.');
            $db->prepare('UPDATE qc_action SET status = ?, done_at = NULL, done_note = ? WHERE id = ?')->execute([$what === 'drop' ? 'dropped' : 'open', $note, $aid]); $line = ($what === 'drop' ? 'Action dropped: ' : 'Action opened again: ') . $x['text'] . ' — ' . $note; break;
        case 'due': if (!$mine && !$inv) throw new RuntimeException('Your role cannot do this.'); if ($x['status'] !== 'open') throw new RuntimeException('This action is not open.');
            $d = (string)($in['due'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < date('Y-m-d')) throw new RuntimeException('Give a due date, not in the past.');
            if ($note === '') throw new RuntimeException('Say why the date moves.');
            $db->prepare('UPDATE qc_action SET due = ? WHERE id = ?')->execute([$d, $aid]); $line = 'Action due ' . $x['due'] . ' → ' . $d . ': ' . $x['text'] . ' — ' . $note; break;
        default: throw new RuntimeException('Unknown change.');
    }
    qc_event($db, (int)$c['id'], (int)$u['id'], 'action', $line);
}
/** Effectiveness check (approve): root cause confirmed, criteria written, no open action, one done; never an action owner or the confirmer. Failed → back to Action in progress. */
function qc_capa_verify(PDO $db, array $u, int $kid, string $result, string $note): void {
    if (!in_array($result, ['passed', 'failed'], true)) throw new RuntimeException('Passed or failed?');
    $note = qc_txt($note, 1000); if (mb_strlen($note) < 5) throw new RuntimeException('Say how effectiveness was proven (or why it failed).');
    [$k, $c] = qc_capa_need($db, $u, $kid, 'approve'); $uid = (int)$u['id']; $f = qc_capa_facts($db, $k);
    if ($k['eff_result'] === 'passed') throw new RuntimeException('This CAPA has passed its check already.');
    if (!$k['rc_at']) throw new RuntimeException('Confirm the root cause first.');
    if (trim((string)$k['eff_criteria']) === '') throw new RuntimeException('Write the effectiveness criteria first.');
    if ($f['open']) throw new RuntimeException('Some actions are still open.'); if (!$f['done']) throw new RuntimeException('No action was done yet.');
    if (in_array($uid, $f['owners'], true) || (int)$k['rc_by'] === $uid) throw new RuntimeException('The check is made by someone who did not own an action or confirm the root cause.');
    $db->prepare('UPDATE qc_capa SET eff_result = ?, eff_note = ?, eff_by = ?, eff_at = NOW(), version = version + 1 WHERE id = ?')->execute([$result, $note, $uid, $kid]);
    qc_event($db, (int)$c['id'], $uid, 'capa', 'Effectiveness check ' . $result . ': ' . $note);
    if ($result === 'failed' && $c['status'] === 'Pending verification') { $db->prepare("UPDATE qc_case SET status = 'Action in progress', version = version + 1 WHERE id = ?")->execute([$c['id']]); qc_event($db, (int)$c['id'], $uid, 'status', 'Effectiveness check failed', 'Pending verification', 'Action in progress'); }
    if ($c['owner_id'] && (int)$c['owner_id'] !== $uid) qc_tell($db, (int)$c['owner_id'], $c, 'Effectiveness check ' . $result, $note);
    audit($uid, 'qc_verify', '#' . $kid . ' ' . $result);
}
function qc_my_actions(PDO $db, int $uid): array {
    $s = $db->prepare("SELECT a.*, c.id AS case_id, c.case_no FROM qc_action a JOIN qc_capa k ON k.id = a.capa_id JOIN qc_case c ON c.id = k.case_id WHERE a.owner_id = ? AND a.status = 'open' ORDER BY a.due"); $s->execute([$uid]); return $s->fetchAll();
}

/* ---- lists, reminders ---- */

function qc_list(PDO $db, array $u, array $f = []): array {
    $w = ['1=1']; $p = [];
    if (($f['status'] ?? 'open') === 'open') $w[] = "c.status IN ('" . implode("','", QC_OPEN) . "')";
    elseif (in_array($f['status'] ?? '', QC_STATUSES, true)) { $w[] = 'c.status = ?'; $p[] = $f['status']; }
    if (in_array($f['severity'] ?? '', QC_SEVERITY, true)) { $w[] = 'c.severity = ?'; $p[] = $f['severity']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(c.case_no LIKE ? OR c.customer_text LIKE ? OR c.product_text LIKE ? OR c.serial_no LIKE ? OR c.title LIKE ?)'; array_push($p, ...array_fill(0, 5, '%' . $q . '%')); }
    if (!empty($f['from'])) { $w[] = 'c.reported_on >= ?'; $p[] = $f['from']; } if (!empty($f['to'])) { $w[] = 'c.reported_on <= ?'; $p[] = $f['to']; }
    $s = $db->prepare('SELECT c.*, u.name AS owner_name FROM qc_case c LEFT JOIN users u ON u.id = c.owner_id WHERE ' . implode(' AND ', $w) . ' ORDER BY c.id DESC LIMIT ' . (int)($f['limit'] ?? 1000));
    $s->execute($p);
    return array_values(array_filter($s->fetchAll(), fn($c) => qc_sees($db, $u, $c)));
}
/** Reminders, once a day per item and person: overdue actions → owner; stale cases → owner (else handlers); submitted cases nobody took after a day → handlers. */
function qc_alert_tick(PDO $db): int {
    $set = qc_settings(); $today = date('Y-m-d'); $now = date('Y-m-d H:i:s'); $n = 0;
    $once = function (string $item, int $uid) use ($db, $today): bool { $s = $db->prepare('INSERT IGNORE INTO qc_alert (item, day, user_id) VALUES (?,?,?)'); $s->execute([$item, $today, $uid]); return $s->rowCount() > 0; };
    foreach ($db->query("SELECT c.*, a.id AS act_id, a.text, a.due, a.owner_id AS act_owner FROM qc_action a JOIN qc_capa k ON k.id = a.capa_id JOIN qc_case c ON c.id = k.case_id WHERE a.status = 'open' AND a.due < CURDATE()")->fetchAll() as $r)
        if ($once('act' . $r['act_id'], (int)$r['act_owner'])) { qc_tell($db, (int)$r['act_owner'], $r, 'CAPA action overdue', $r['text'] . ' · due ' . $r['due']); $n++; }
    foreach ($db->query("SELECT * FROM qc_case WHERE status IN ('" . implode("','", QC_OPEN) . "')")->fetchAll() as $c) {
        if (qc_is_stale($c, $set, $now)) foreach ($c['owner_id'] ? [(int)$c['owner_id']] : qc_people_with($db, 'handle', (int)$c['branch_id']) as $w) if ($once('stale' . $c['id'], $w)) { qc_tell($db, $w, $c, 'No update on a case', $c['severity'] . ' · ' . ($c['product_text'] ?: $c['title'])); $n++; }
        if (!$c['owner_id'] && $c['status'] === 'Submitted' && strtotime($now) - strtotime((string)$c['submitted_at']) > 86400) foreach (qc_people_with($db, 'handle', (int)$c['branch_id']) as $w) if ($once('own' . $c['id'], $w)) { qc_tell($db, $w, $c, 'Case with no owner', $c['severity'] . ' · ' . ($c['product_text'] ?: $c['title'])); $n++; }
    }
    return $n;
}
