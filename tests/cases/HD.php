<?php
// Help desk rules (lib/helpdesk.php) — synthetic data only.
require_once WN_ROOT . '/lib/helpdesk.php';
$hdT = [['title' => 'Applying for leave', 'keywords' => 'leave apply holiday sick', 'answer' => 'A'], ['title' => 'Marking attendance', 'keywords' => 'punch check in attendance', 'answer' => 'B']];
t('HD01 matching: keywords and title words score, the best topic wins, weak matches give nothing', hd_best($hdT, 'How do I apply for sick leave?')['answer'] === 'A' && hd_best($hdT, 'punching in')['answer'] === 'B'
    && hd_best($hdT, 'weather today') === null && hd_score('X', '', '') === 0 && hd_norm('  Hello, WORLD! ') === 'hello world');
[$hc, $he] = hd_clean_contact(['name' => ' TEST – Desk ', 'mobile' => '+1 (555) 010-0100', 'email' => 'Desk@Example.com', 'whatsapp' => '1', 'office_ids' => ['2', '2', 'x', '3']]);
t('HD02 contacts: official number cleaned, email lower case, locations deduplicated; a number or email needed', $he === null && $hc['mobile'] === '+15550100100' && $hc['email'] === 'desk@example.com' && $hc['office_ids'] === '2,3'
    && hd_clean_contact(['name' => 'T'])[1] !== null && hd_clean_contact(['name' => 'T', 'mobile' => 'abc'])[1] !== null && hd_clean_contact(['name' => 'T', 'email' => 'x', 'mobile' => ''])[1] !== null
    && hd_clean_contact(['name' => 'T', 'email' => 'a@example.com', 'linkedin' => 'https://example.com/x'])[1] !== null);
t('HD03 WhatsApp: a + number as it is, otherwise the setting\'s dial code', hd_whatsapp_link('+1 555 010 0100') === 'https://wa.me/15550100100' && hd_whatsapp_link('0555 010 0100', '1') === 'https://wa.me/15550100100' && hd_whatsapp_link('5550100100') === 'https://wa.me/5550100100');
t('HD04 links: map, review and social links only on their own sites', hd_clean_map('https://maps.app.goo.gl/abc') !== null && hd_clean_map('https://evil.example.com/maps') === null && hd_clean_map('') === ''
    && hd_clean_social('linkedin', 'https://www.linkedin.com/company/test-co') !== null && hd_clean_social('linkedin', 'https://example.com/company/x') === null && hd_clean_social('nope', 'https://x.example') === null
    && hd_clean_review('https://g.page/r/AbC123/review') !== null && hd_clean_review('https://example.com/r') === null);
[$ho, $hoe] = hd_clean_office(['name' => 'TEST HO', 'kind' => 'zzz', 'branch_id' => '9', 'phone' => '555 0100 100'], [1, 2]);
t('HD05 locations: unknown type → branch office, unknown branch dropped, phone cleaned', $hoe === null && $ho['kind'] === 'branch' && $ho['branch_id'] === null && $ho['phone'] === '5550100100' && hd_clean_office(['name' => ''], [])[1] !== null);
[$tp, $te] = hd_clean_topic(['title' => 'TEST Gate passes', 'answer' => '<b>TEST</b> answer', 'lang' => 'xx', 'module_key' => 'nope'], ['en' => 'English'], ['leave']);
t('HD06 answers: short name from the title, tags stripped, unknown language → English, unknown screen dropped', $te === null && $tp['slug'] === 'test-gate-passes' && $tp['answer'] === 'TEST answer' && $tp['lang'] === 'en' && $tp['module_key'] === ''
    && hd_clean_topic(['title' => 'T', 'answer' => ''], ['en' => 'E'], [])[1] !== null);
[$hs, $hse] = hd_settings_check(['edit_roles' => ['HRMANAGER', 'NOPE'], 'langs' => "hi Hindi\nbad\nfr French", 'dial_code' => '+44'], ['HRMANAGER' => 'h']);
t('HD07 settings: English always kept, languages "xx Name", dial code digits', !$hse && $hs['edit_roles'] === ['HRMANAGER'] && hd_langs($hs) === ['en' => 'English', 'hi' => 'Hindi', 'fr' => 'French'] && $hs['dial_code'] === '44'
    && count(hd_settings_check([], [])[1]) === 1);
$hdSeed = json_encode(HD_SEED, JSON_UNESCAPED_UNICODE);
t('HD08 the starting answers are about the product\'s screens only and point at real modules', count(HD_SEED) >= 10 && !array_diff(array_filter(array_column(HD_SEED, 4)), array_keys(module_catalog())) && !preg_match('/\d{3,}/', $hdSeed));
t('HD09 nothing deleted', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', file_get_contents(WN_ROOT . '/lib/helpdesk.php')));
