<?php
// Complaints & CAPA rules (lib/quality.php) — synthetic data only.
require_once WN_ROOT . '/lib/quality.php';
$qR = qc_rights_for(['QUALITY'], QC_DEFAULTS); $qE = qc_rights_for(['EMPLOYEE'], QC_DEFAULTS); $qC = qc_rights_for(['CEO'], QC_DEFAULTS);
t('QC01 rights from settings: everyone reports; quality handles and approves; CEO only sees', $qR['handle'] && $qR['approve'] && $qE['report'] && !$qE['handle'] && !$qE['view'] && $qC['view'] && !$qC['approve']);
$cats = qc_cats(); $today = '2026-11-19';
[$d, $e] = qc_case_input(['kind' => 'customer', 'severity' => 'Low', 'safety' => '1', 'branch_id' => '2', 'customer_text' => ' TEST  Buyer ', 'qty_scope' => 'lot', 'lot_qty' => '40', 'qty' => '3'], [2], $cats, $today);
t('QC02 case form: a safety risk makes it Critical; whole lot = the lot size is the count', !$e && $d['severity'] === 'Critical' && $d['qty'] === 40.0 && $d['customer_text'] === 'TEST Buyer');
[, $e] = qc_case_input(['kind' => 'x', 'branch_id' => '3', 'category' => 'nope', 'reported_on' => '2026-12-01', 'qty_scope' => 'pieces', 'qty' => '9', 'lot_qty' => '4'], [2], $cats, $today);
t('QC03 case form refuses unknown kind, branch out of scope, nothing to go on, unknown category, a future date, a lot smaller than the faulty pieces', isset($e['kind'], $e['branch_id'], $e['description'], $e['category'], $e['reported_on'], $e['lot_qty']));
$draft = ['status' => 'Draft', 'kind' => 'customer', 'created_by' => 5, 'customer_id' => null, 'customer_text' => '', 'product_id' => null, 'product_text' => '', 'description' => 'short', 'category' => '', 'qty_scope' => '', 'qty' => null, 'severity' => 'Medium'];
t('QC04 a draft names what it still needs before sending', count(qc_submit_blocks($draft)) === 5
    && qc_submit_blocks(['customer_text' => 'TEST', 'product_text' => 'TEST P', 'description' => 'It stopped after an hour', 'category' => 'Other', 'qty_scope' => 'pieces', 'qty' => 2.0] + $draft) === []);
$x = fn(array $r, int $uid, string $note = '', ?array $k = null) => ['rights' => $r, 'uid' => $uid, 'note' => $note, 'closeNoCapa' => ['Low'], 'capa' => $k];
$open = ['status' => 'Submitted', 'created_by' => 5, 'severity' => 'Medium'] + $draft;
t('QC05 moves only along the flow, each behind its right; only the reporter sends a draft; rejecting needs a reason',
    str_contains(qc_move_check($draft, 'Submitted', $x($qE, 9)), 'role') && str_starts_with(qc_move_check($draft, 'Submitted', $x($qE, 5)), 'Before sending')
    && qc_move_check($open, 'Closed', $x($qR, 9)) !== '' && str_contains(qc_move_check($open, 'Under review', $x($qE, 5)), 'role') && qc_move_check($open, 'Under review', $x($qR, 9)) === ''
    && qc_move_check($open, 'Rejected', $x($qR, 9)) === 'Write the reason.' && qc_move_check($open, 'Rejected', $x($qR, 9, 'TEST dup')) === '');
$rev = ['status' => 'Under review'] + $open; $low = ['severity' => 'Low'] + $rev;
t('QC06 closing without a CAPA: only an allowed severity, only with a note; with a CAPA only after the check', qc_move_check($rev, 'Closed', $x($qR, 9, 'n')) !== '' && qc_move_check($low, 'Closed', $x($qR, 9)) === 'Write the reason.'
    && qc_move_check($low, 'Closed', $x($qR, 9, 'TEST replaced')) === '' && qc_move_check($low, 'Closed', $x($qR, 9, 'n', ['rc_at' => null, 'rc_by' => null, 'eff_result' => '', 'owners' => [], 'done' => 0, 'open' => 0])) !== '');
$k = ['rc_at' => '2026-11-10', 'rc_by' => 7, 'eff_result' => 'passed', 'owners' => [8], 'done' => 1, 'open' => 0]; $pv = ['status' => 'Pending verification'] + $open;
t('QC07 kept apart: an action owner or the root-cause confirmer cannot close; another approver can, once checked', qc_move_check($pv, 'Closed', $x($qR, 8, '', $k)) !== '' && qc_move_check($pv, 'Closed', $x($qR, 7, '', $k)) !== ''
    && qc_move_check($pv, 'Closed', $x($qR, 9, '', $k)) === '' && qc_move_check($pv, 'Closed', $x($qR, 9, '', ['eff_result' => 'failed'] + $k)) !== '' && qc_move_check($pv, 'Closed', $x($qR, 9, '', ['rc_at' => null] + $k)) !== '');
$ai = ['status' => 'Action in progress'] + $open;
t('QC08 verification needs every action done or dropped, one done; going back needs a reason', qc_move_check($ai, 'Pending verification', $x($qR, 9, '', ['open' => 1] + $k)) !== '' && qc_move_check($ai, 'Pending verification', $x($qR, 9, 'n', ['done' => 0, 'open' => 0] + $k)) !== ''
    && qc_move_check($ai, 'Pending verification', $x($qR, 9, 'TEST all done', $k)) === '' && qc_move_check($ai, 'Investigation', $x($qR, 9)) === 'Write the reason.'
    && qc_move_check(['status' => 'Investigation'] + $open, 'Action in progress', $x($qR, 9, 'n')) !== '');
$set = QC_DEFAULTS;
t('QC09 target close from the severity; stale after the severity\'s days without an update', qc_target_date('High', $set, '2026-11-01') === '2026-11-16'
    && qc_is_stale(['status' => 'Investigation', 'severity' => 'Critical', 'last_event_at' => '2026-11-17 10:00:00', 'submitted_at' => null, 'created_at' => '2026-11-01'], $set, '2026-11-19 10:00:00')
    && !qc_is_stale(['status' => 'Closed', 'severity' => 'Critical', 'last_event_at' => '2026-01-01', 'submitted_at' => null, 'created_at' => '2026-01-01'], $set, '2026-11-19 10:00:00'));
$a = ['case_no' => 'CC/A/26-27/0002', 'product_id' => 3, 'product_text' => 'TEST Hoist', 'serial_no' => '', 'batch' => 'B7', 'invoice_no' => '', 'title' => 'Brake noise', 'description' => 'Brake makes noise when lifting full load'];
$b = ['case_no' => 'CC/A/26-27/0001', 'serial_no' => '', 'batch' => 'B9', 'invoice_no' => '', 'title' => 'Noise', 'description' => 'The brake makes noise while lifting full load'] + $a;
t('QC10 repeats: same serial / batch + product are exact; same product and words alike scores; another product never by words', qc_match_why(['serial_no' => 'S1'] + $a, ['serial_no' => 's1', 'product_id' => 9, 'product_text' => 'x'] + $b, 70)['exact'] === true
    && qc_match_why(['batch' => 'B9'] + $a, $b, 70)['why'] === 'Same batch and product' && ($m = qc_match_why($a, $b, 70)) && !$m['exact'] && $m['score'] >= 70
    && qc_match_why($a, ['product_id' => 9, 'product_text' => 'other'] + $b, 70) === null && qc_match_why(['description' => 'Same as CC/A/26-27/0001'] + $a, ['product_id' => 9, 'product_text' => 'other'] + $b, 70)['exact']);
$roots = qc_roots([1 => '2026-05-01', 2 => '2026-03-01', 3 => '2026-09-01', 4 => '2026-01-01'], [[1, 3], [3, 2]]);
t('QC11 confirmed repeats point at the earliest case of the whole chain; a lone case has none', $roots[1] === 2 && $roots[3] === 2 && $roots[2] === 2 && $roots[4] === null);
t('QC12 evidence by its own bytes', qc_file_mime('%PDF-1.4') === 'application/pdf' && qc_file_mime("\xFF\xD8\xFFx") === 'image/jpeg' && qc_file_mime("\0\0\0\x18ftypmp42") === 'video/mp4'
    && qc_file_mime("\0\0\0\x14ftypqt  ") === 'video/quicktime' && qc_file_mime('<?php') === null);
$S = qc_summary([['id' => 1, 'status' => 'Draft', 'severity' => 'Low', 'category' => '', 'root_id' => null, 'safety' => 0], ['id' => 2, 'status' => 'Investigation', 'severity' => 'High', 'category' => 'Other', 'root_id' => 3, 'safety' => 1, 'target_close' => '2026-11-01', 'last_event_at' => '2026-11-19', 'submitted_at' => '2026-10-01', 'created_at' => '2026-10-01'],
    ['id' => 3, 'status' => 'Closed', 'severity' => 'Low', 'category' => 'Other', 'root_id' => 3, 'safety' => 0, 'submitted_at' => '2026-10-01 00:00:00', 'closed_at' => '2026-10-11 00:00:00', 'target_close' => null]], $set, '2026-11-19 10:00:00');
t('QC13 dashboard: drafts left out, overdue, repeats (not the first), average days to close', $S['total'] === 2 && $S['open'] === 1 && $S['overdue'] === 1 && $S['repeats'] === 1 && $S['safety'] === 1 && $S['closeDays'] === 10.0 && $S['byCategory'] === ['Other' => 2]);
[$so, $se] = qc_settings_check(['handle_roles' => ['QUALITY'], 'approve_roles' => [], 'close_no_capa' => ['Low', 'Huge'], 'target' => ['Critical' => '0'], 'match_min' => '5', 'prefix' => 'c-c!'], ['QUALITY' => 'q']);
t('QC14 settings: an approver role is needed, unknown severities dropped, days and match level kept in range', count($se) === 1 && $so['close_no_capa'] === ['Low'] && $so['target']['Critical'] === 1 && $so['match_min'] === 40 && $so['prefix'] === 'CC'
    && qc_case_no('CC', 'ho-1', '26-27', 7) === 'CC/HO1/26-27/0007');
$src = (string)file_get_contents(WN_ROOT . '/lib/quality.php');
t('QC15 nothing deleted; audit family chained', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $src) && in_array('qc_', AUDIT_CHAINED_PREFIXES, true) && qc_csv_cell('@x') === "\"'@x\"");
