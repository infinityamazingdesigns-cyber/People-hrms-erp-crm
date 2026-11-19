<?php
// Enquiry register rules (lib/enquiries.php) — synthetic data only.
require_once WN_ROOT . '/lib/enquiries.php';
$g = enq_groups("Products: TEST A, TEST B, TEST A\nServices: Install\nloose line\n: nothing");
t('ENQ01 "Group: a, b" lines read, repeats dropped, a line without a group goes to Other', $g === ['Products' => ['TEST A', 'TEST B'], 'Services' => ['Install'], 'Other' => ['loose line']] && enq_flat($g) === ['TEST A', 'TEST B', 'Install', 'loose line'] && enq_flat([]) === []);
t('ENQ02 follow-up state against today; closed ones never overdue', enq_attn(['status' => 'new', 'follow_up' => '2026-11-18'], '2026-11-19') === 'overdue' && enq_attn(['status' => 'quoted', 'follow_up' => '2026-11-19'], '2026-11-19') === 'today'
    && enq_attn(['status' => 'contacted', 'follow_up' => '2026-11-25'], '2026-11-19') === 'later' && enq_attn(['status' => 'new', 'follow_up' => null], '2026-11-19') === 'none' && enq_attn(['status' => 'won', 'follow_up' => '2026-01-01'], '2026-11-19') === 'closed');
t('ENQ03 stage from file words', enq_stage_from('PO received') === 'won' && enq_stage_from('Regretted') === 'lost' && enq_stage_from('In negotiation') === 'negotiation' && enq_stage_from('Quotation sent on mail') === 'quoted' && enq_stage_from('called, will revert') === 'contacted' && enq_stage_from('') === 'new');
$set = ENQ_DEFAULTS; $known = ['categories' => ['Products' => ['TEST A']], 'sources' => ['Inbound' => ['Website form']], 'lost' => ['Price too high', 'Other']];
[$d, $e] = enq_check(['customer_name' => ' TEST Buyer ', 'phone' => '+1 555 555 0123', 'source' => 'Website form', 'status' => 'new', 'follow_up' => '2026-11-20', 'category' => 'Products', 'product' => 'TEST A'], $known, '2026-11-19');
t('ENQ04 a new enquiry checked: name tidied, phone digits and key, date defaults to today', !$e && $d['customer_name'] === 'TEST Buyer' && $d['phone'] === '15555550123' && $d['phone_key'] === '5555550123' && $d['enquiry_date'] === '2026-11-19');
$err = fn(array $in) => array_keys(enq_check($in + ['customer_name' => 'x', 'email' => 'a@example.com', 'source' => 'Website form', 'follow_up' => '2026-11-20'], $known, '2026-11-19')[1]);
t('ENQ05 rules: a phone or mail, a source from the list, quote no. + value from Quotation sent, a reason for Lost, a follow-up while open, no future enquiry date',
    $err(['email' => '', 'phone' => '']) === ['phone'] && $err(['source' => 'Nope']) === ['source'] && $err(['status' => 'quoted']) === ['quote_no', 'basic']
    && $err(['status' => 'quoted', 'quote_no' => 'Q1', 'basic' => '1,000']) === [] && $err(['status' => 'lost', 'lost_reason' => 'Nope']) === ['lost_reason'] && $err(['status' => 'lost', 'lost_reason' => 'Other', 'follow_up' => '']) === []
    && $err(['follow_up' => '']) === ['follow_up'] && $err(['enquiry_date' => '2026-12-01']) === ['enquiry_date'] && $err(['product' => 'Nope', 'category' => 'Products']) === ['product'] && $err(['basic' => 'abc']) === ['basic']);
t('ENQ06 closed enquiries keep no follow-up; quotes wait longer', enq_check(['customer_name' => 'x', 'email' => 'a@example.com', 'source' => 'Website form', 'status' => 'won', 'quote_no' => 'Q', 'basic' => '5', 'follow_up' => '2026-11-20'], $known, '2026-11-19')[0]['follow_up'] === null
    && enq_follow_days('quoted', $set) === 3 && enq_follow_days('new', $set) === 1 && enq_ref('ENQ', '26-27', 5) === 'ENQ/26-27/0005');
$rows = [['status' => 'won', 'basic' => 100, 'quote_no' => 'Q', 'source' => 'A'], ['status' => 'lost', 'basic' => 50, 'quote_no' => 'Q', 'source' => 'A'], ['status' => 'new', 'basic' => null, 'quote_no' => '', 'source' => 'A'], ['status' => 'quoted', 'basic' => 30, 'quote_no' => 'Q', 'source' => '']];
$r = enq_report($rows, 'source');
t('ENQ07 report: counts, win rate = won ÷ closed, quoted and won value; a blank group shows as —', $r['A']['n'] === 3 && $r['A']['won'] === 1 && $r['A']['open'] === 1 && $r['A']['win_rate'] === 50.0 && $r['A']['quoted_value'] === 150.0 && $r['A']['won_value'] === 100.0 && $r['—']['win_rate'] === null && array_key_first($r) === 'A');
[$hi, $map] = tbl_map([['Customer Name', 'Company Details', 'Contact', 'Mailid', 'Type of Enquiry', 'Status', 'Basic Value without Tax', 'Lead Source']], ENQ_IMPORT_COLS, 1);
$x = enq_row_from(['TEST Buyer', 'TEST Co', '5555550123', '', 'products', 'quotation sent', '1200', 'Fax'], $map, $known, '2026-11-19', $set);
t('ENQ08 a file row: template titles found, category matched without case, unknown source becomes the first one with a note, follow-up from the stage', $hi === 0 && $map['basic'] === 6 && $x['category'] === 'Products' && $x['status'] === 'contacted'
    && $x['source'] === 'Website form' && str_contains($x['_note'], 'Source in the file: Fax') && $x['follow_up'] === '2026-11-20');
[$s, $se] = enq_settings_check(['all_roles' => [], 'sources' => '', 'lost_reasons' => '', 'prefix' => 'e-q!', 'tax_pct' => 150], ['SUPERADMIN' => 's']);
t('ENQ09 settings: a see-all role needed, sources never empty, reason list never empty, prefix and tax in range', count($se) === 2 && $s['sources'] === ENQ_DEFAULTS['sources'] && $s['lost_reasons'] === 'Other' && $s['prefix'] === 'EQ' && $s['tax_pct'] == 100);
$y = enq_row_from(['TEST Buyer', '', '5555550123', '', '', 'quotation sent', '', 'Website form'], $map, $known, '2026-11-19', $set);
t('ENQ10 a quote stage in a file without the quotation details comes in as Contacted, the file\'s word kept', $y['status'] === 'contacted' && str_contains($y['_note'], 'Status in the file: quotation sent') && $y['source'] === 'Website form');
