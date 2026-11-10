<?php
// KYC rules (lib/kyc.php) — synthetic values only (valid formats, not real numbers).
require_once WN_ROOT . '/lib/kyc.php';
t('KY01 India pack: tax ID format, bank code with zero in 5th place', kyc_check('tax_id', 'aaapz9999z', 'IN')[1] === '' && kyc_check('bank_code', 'TEST0000001', 'IN')[1] === '' && kyc_check('bank_code', 'TESTX000001', 'IN')[1] !== '');  // guard:allow synthetic format check
// 12-digit test number with a valid check digit, built here so no real ID is written down
$base = '23412341234'; $ok = null; for ($d = 0; $d <= 9; $d++) if (kyc_verhoeff($base . $d)) $ok = $base . $d;
t('KY02 national ID: full number checked then cut to last 4; bad check digit refused', kyc_check('national_id', $ok, 'IN')[0] === substr($ok, -4) && kyc_check('national_id', $base . (((int)substr($ok, -1) + 1) % 10), 'IN')[1] !== '');
t('KY03 national ID: last 4 alone accepted', kyc_check('national_id', '1234', 'IN') === ['1234', '']);
t('KY04 other countries get open formats', kyc_check('tax_id', 'AB-12345', 'AE')[1] === '' && kyc_check('bank_acct', 'GB33BUKB20201555555555', 'AE')[1] === '' && kyc_label('tax_id', 'AE') === 'Tax ID');
t('KY05 UK and US packs', kyc_check('bank_code', '123456', 'GB')[1] === '' && kyc_check('tax_id', '123-45-6789', 'US')[0] === '6789');
t('KY06 masks never show more than the last 4', kyc_mask_one('bank_acct', '123456789012') === 'XXXXXXXX9012' && kyc_mask_one('national_id', '1234') === 'XXXX-1234' && kyc_mask_one('bank_name', 'Test Bank') === 'Test Bank');
t('KY07 changes to tax ID / national ID / bank need documents', kyc_docs_missing(['tax_id' => 'X', 'bank_acct' => '1', 'social_id' => '2'], ['bank']) === ['tax_id']);
t('KY08 documents checked by their bytes', kyc_doc_mime("%PDF-1.4") === 'application/pdf' && kyc_doc_mime("<html>") === null && kyc_doc_mime("\x89PNG\r\n\x1a\nxx") === 'image/png');
$ops = ['id' => 5, 'roles' => ['HROPS']]; $mgr = ['id' => 6, 'roles' => ['HRMANAGER']];
t('KY09 verified bank changes only by HR Manager / Super Admin', !kyc_can_verify($ops, ['bank_acct'], ['bank_acct' => []]) && kyc_can_verify($mgr, ['bank_acct'], ['bank_acct' => []]) && kyc_can_verify($ops, ['bank_acct'], []));
[$v, $e] = kyc_clean(['tax_id' => 'bad', 'bank_name' => ' Test  Bank <b>x</b> '], 'IN');
t('KY10 clean: errors named, text tidied', count($e) === 1 && $v === ['bank_name' => 'Test Bank x']);
