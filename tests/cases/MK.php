<?php
// Marketing rules (lib/marketing.php) — synthetic data only.
require_once WN_ROOT . '/lib/marketing.php';
$mkE = function (callable $f): string { try { $f(); return ''; } catch (Throwable $e) { return $e->getMessage(); } };
$mkR = mk_rights_for(['MARKETING'], MK_DEFAULTS); $mkA = mk_rights_for(['CEO'], MK_DEFAULTS); $mkV = mk_rights_for(['SALES'], MK_DEFAULTS); $mkN = mk_rights_for(['EMPLOYEE'], MK_DEFAULTS);
t('MK01 rights from settings: full / own / read / none', $mkA['all'] && !$mkR['all'] && $mkR['own'] && !$mkV['own'] && $mkV['view'] && !$mkN['view']);
[$c, $e] = mk_campaign_input(['name' => ' TEST  Spring offer ', 'channel' => 'search_ads', 'status' => 'Live', 'branch_id' => '2', 'budget' => '12,500', 'landing_url' => 'https://example.com/offer', 'ext_ids' => 'A1, a1 ,B2'], [2]);
t('MK02 campaign form: UTM from the channel and name, ids deduplicated, budget with commas', !$e && $c['utm_source'] === 'search' && $c['utm_medium'] === 'cpc' && $c['utm_campaign'] === 'test_spring_offer' && $c['ext_ids'] === 'a1, b2' && $c['budget'] === 12500.0);
[, $e] = mk_campaign_input(['name' => '', 'channel' => 'nope', 'branch_id' => '3', 'landing_url' => 'http://example.com', 'start_date' => '2026-11-10', 'end_date' => '2026-11-01'], [2]);
t('MK03 campaign form refuses a missing name, unknown channel, branch out of scope, plain http, end before start', isset($e['name'], $e['channel'], $e['branch_id'], $e['landing_url'], $e['end_date']));
$L = mk_links(['landing_url' => 'https://example.com/p?x=1', 'utm_source' => 'search', 'utm_medium' => 'cpc', 'utm_campaign' => 'spring', 'ref' => 'MK0007'], '15550001111');
t('MK04 tracked link carries UTM and the code; chat link carries the code', $L['url'] === 'https://example.com/p?x=1&utm_source=search&utm_medium=cpc&utm_campaign=spring&utm_id=MK0007' && str_contains($L['chat'], 'MK0007') && mk_links(['landing_url' => '', 'ref' => 'MK1'] + $L, '')['chat'] === '');
$ix = mk_index_of([['id' => 7, 'ref' => 'MK0007', 'name' => 'TEST Spring', 'utm_campaign' => 'spring', 'ext_ids' => '998877', 'match_names' => 'test spring 26', 'status' => 'Live'],
                   ['id' => 8, 'ref' => 'MK0008', 'name' => 'TEST Old', 'utm_campaign' => 'old', 'ext_ids' => '', 'match_names' => '', 'status' => 'Archived']]);
t('MK05 campaign found by code in any text, platform id, UTM, name or listed name; archived ones never', mk_resolve($ix, 'Hi please share (mk7)') === null && mk_resolve($ix, 'please share (MK0007)') === 7
    && mk_resolve($ix, '998877') === 7 && mk_resolve($ix, 'Spring') === 7 && mk_resolve($ix, 'TEST spring 26') === 7 && mk_resolve($ix, 'old') === null && mk_resolve($ix, 'MK0008') === null);
t('MK06 report dates: ISO, day first, month first when asked, words, Excel serial', mk_day('2026-10-05') === '2026-10-05' && mk_day('05/10/2026') === '2026-10-05' && mk_day('10/05/2026', true) === '2026-10-05'
    && mk_day('25/10/2026', true) === '2026-10-25' && mk_day('5 Oct 2026') === '2026-10-05' && mk_day('46300') === gmdate('Y-m-d', (46300 - 25569) * 86400) && mk_day('31/02/2026') === null);
[$lines, $bad, $other] = mk_spend_rows([['Campaign name', 'Ad set', 'Day', 'Amount spent', 'Impressions', 'Link clicks', 'Currency'],
    ['TEST Spring', 'a', '2026-10-01', '100.50', '1,000', '10', 'INR'], ['TEST Spring', 'b', '2026-10-01', '49.50', '500', '5', 'INR'],
    ['TEST Spring', 'c', '2026-10-02', 'n/a', '', '', 'INR'], ['TEST Spring', 'd', '2026-10-02', '20', '', '', 'USD'], ['Total', '', '', '170', '', '', '']], false, 'INR');
t('MK07 spend report: ad-set rows add up per campaign + day, other currency counted apart, bad rows named, totals skipped', count($lines) === 1 && $lines[0]['amount'] === 150.0 && $lines[0]['impressions'] === 1500 && $lines[0]['clicks'] === 15 && $other === 1 && count($bad) === 1);
[$lr, $lb] = mk_lead_rows([['Full name', 'Phone number', 'Email', 'Campaign'], ['TEST A', '+1 555 000 1111', '', 'Spring'], ['TEST B', '', '', 'x']]);
t('MK08 lead list: columns by title, a row with no number or mail is named', count($lr) === 1 && $lr[0]['phone'] === crm_phone_norm('+1 555 000 1111') && count($lb) === 1);
$m = mk_metrics(1000.0, 5000.0, 20, 4, 10000, 250);
t('MK09 ROAS = value ÷ spend; CPL, cost per order, CTR; nothing divided by 0', $m === ['roas' => 5.0, 'cpl' => 50.0, 'cpo' => 250.0, 'ctr' => 2.5] && mk_metrics(0.0, 10.0, 0, 0, null, null) === ['roas' => null, 'cpl' => null, 'cpo' => null, 'ctr' => null]);
$leads = [['campaign_id' => 3, 'lead_at' => '2026-08-01 10:00:00'], ['campaign_id' => 4, 'lead_at' => '2026-10-20 10:00:00'], ['campaign_id' => null, 'lead_at' => '2026-10-25 10:00:00'], ['campaign_id' => 5, 'lead_at' => '2026-11-05 10:00:00']];
t('MK10 order → campaign: the latest lead with a campaign before the order, inside the window', mk_auto_pick($leads, '2026-11-01 12:00:00', 90) === 4 && mk_auto_pick($leads, '2026-11-01 12:00:00', 5) === null && mk_auto_pick($leads, '2026-11-10 09:00:00', 90) === 5);
t('MK11 enquiry source from the campaign channel, else the lead kind, else Other / the first', mk_enq_source('form', 'search_ads', ['Search ads', 'Other']) === 'Search ads' && mk_enq_source('form', null, ['Website form']) === 'Website form'
    && mk_enq_source('call', 'print', ['Website form', 'Other']) === 'Other' && mk_enq_source('call', null, ['Walk-in']) === 'Walk-in');
t('MK12 consent: the newest answer counts, never asked = empty', mk_consent_state([['id' => 1, 'answer' => 'yes'], ['id' => 3, 'answer' => 'no'], ['id' => 2, 'answer' => 'yes']]) === 'no' && mk_consent_state([]) === '');
[$so, $se] = mk_settings_check(['all_roles' => [], 'own_roles' => ['MARKETING', 'NOPE'], 'window_days' => '900', 'currency' => 'us$d', 'chat_number' => '+1 (555) 000'], ['MARKETING' => 'm', 'CEO' => 'c']);
t('MK13 settings: a full-rights role is needed, unknown roles dropped, window capped, currency letters only', count($se) === 1 && $so['own_roles'] === ['MARKETING'] && $so['window_days'] === 365 && $so['currency'] === 'USD' && $so['chat_number'] === '1555000');
$src = (string)file_get_contents(WN_ROOT . '/lib/marketing.php') . file_get_contents(WN_ROOT . '/modules/marketing/lead.php');
t('MK14 nothing deleted; the hook keeps only a fingerprint of its key; CSV made safe; codes MK0001', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $src) && str_contains($src, "hash('sha256', \$key)")
    && mk_csv_cell('=1') === "\"'=1\"" && mk_ref(1) === 'MK0001' && mk_ref(12345) === 'MK12345' && $mkE(fn() => mk_spend_rows([['x']], false, 'INR')) === '');
