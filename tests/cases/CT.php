<?php
// Third parties & contracts rules (lib/contracts.php) — synthetic data only.
require_once WN_ROOT . '/lib/contracts.php';
$ctE = function (callable $f): string { try { $f(); return ''; } catch (Throwable $e) { return $e->getMessage(); } };
$S = CT_DEFAULTS; $rH = ct_rights_for(['HRMANAGER'], $S); $rC = ct_rights_for(['CEO'], $S); $rS = ct_rights_for(['SUPERADMIN'], $S); $rE = ct_rights_for(['EMPLOYEE'], $S);
t('CT01 rights: HR keeps the register and sees full numbers; CEO approves; Super Admin manages and approves but sees numbers masked unless given the right; others nothing',
    $rH['manage'] && $rH['sensitive'] && !$rH['approve'] && $rC['approve'] && !$rC['manage'] && $rS['manage'] && $rS['approve'] && !$rS['sensitive'] && !$rE['view']);
t('CT02 nobody approves what they asked for or uploaded', !ct_may_decide($rC, 5, 5, 9) && !ct_may_decide($rC, 9, 5, 9) && ct_may_decide($rC, 7, 5, 9) && !ct_may_decide($rH, 7, 5, 9));
[$f, $tax, $reg] = ct_party_clean(['legal_name' => 'TEST  Widgets Private Limited', 'entity_type' => 'company', 'category' => 'vendor', 'tax_id' => 'ab-12 34 567', 'reg_no' => '']);
t('CT03 party form: names compared without "Pvt Ltd" and punctuation; numbers tidied; unknown lists refused', $f['name_key'] === 'test widgets' && $tax === 'AB1234567' && $reg === ''
    && ct_name_key('TEST Widgets Pvt. Ltd.') === 'test widgets' && $ctE(fn() => ct_party_clean(['legal_name' => 'TEST A', 'entity_type' => 'x', 'category' => 'vendor'])) !== ''
    && $ctE(fn() => ct_party_clean(['legal_name' => 'TEST A', 'entity_type' => 'company', 'category' => 'vendor', 'tax_id' => '12'])) !== '');
$c = ct_contract_clean(['type' => 'NDA', 'title' => 'TEST NDA', 'effective_from' => '2026-11-01', 'expires_on' => '2027-10-31', 'notice_days' => '30']);
t('CT04 agreement form: type from the list, dates in order, notice in range', $c['notice_days'] === 30 && $ctE(fn() => ct_contract_clean(['type' => 'NDA', 'title' => 'TEST', 'effective_from' => '2026-11-01', 'expires_on' => '2026-10-01'])) !== ''
    && $ctE(fn() => ct_contract_clean(['type' => 'ZZ', 'title' => 'TEST x'])) !== '' && $ctE(fn() => ct_contract_clean(['type' => 'NDA', 'title' => 'TEST', 'notice_days' => '9999'])) !== '');
t('CT05 value in whole minor units; never a float', ct_commercial_clean(['value' => '12,50,000.5'])['value_minor'] === 125000050 && ct_commercial_clean([])['value_minor'] === null && $ctE(fn() => ct_commercial_clean(['value' => '1.234'])) !== '');
t('CT06 an executed agreement past its end reads Expired; an expired document is never Verified', ct_contract_state(['status' => 'executed', 'expires_on' => '2026-01-01'], '2026-11-19') === 'Expired'
    && ct_contract_state(['status' => 'draft', 'expires_on' => '2026-01-01'], '2026-11-19') === 'Draft' && ct_doc_state(['status' => 'verified', 'expires_on' => '2026-11-18'], '2026-11-19') === 'Expired' && ct_doc_state(['status' => 'verified', 'expires_on' => null], '2026-11-19') === 'Verified');
t('CT07 reminder bands: the smallest band at or above the days left, once each', ct_band(45, [90, 60, 30]) === 60 && ct_band(5, [90, 60, 30, 15, 7]) === 7 && ct_band(120, [90, 60]) === null && ct_band(0, [90, 7]) === 7);
t('CT08 files by their own bytes; agreement references by the organisation\'s year', ct_sniff('%PDF-1.7') === 'application/pdf' && ct_sniff("\x89PNG\r\n\x1a\nx") === 'image/png' && ct_sniff('<?php') === null
    && ct_fy('2026-03-31', 4) === '25-26' && ct_fy('2026-04-01', 4) === '26-27' && ct_fy('2026-04-01', 1) === '2026');
[$so, $se] = ct_settings_check(['manage_roles' => ['HRMANAGER'], 'approve_roles' => [], 'alert_days' => '7, 30, 30, 999, 0', 'prefix' => 't-p'], ['HRMANAGER' => 'h']);
t('CT09 settings: an approver role is needed; reminder days cleaned and sorted', count($se) === 1 && $so['alert_days'] === [30, 7] && $so['prefix'] === 'TP');
$src = (string)file_get_contents(WN_ROOT . '/lib/contracts.php');
t('CT10 nothing deleted; numbers sealed and hashed, never logged; audit family chained', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $src) && str_contains($src, 'seal($v)') && str_contains($src, 'hash_hmac')
    && in_array('ct_', AUDIT_CHAINED_PREFIXES, true) && !preg_match('/ct_log\([^;]*\$(tax|reg)\b/', $src));
