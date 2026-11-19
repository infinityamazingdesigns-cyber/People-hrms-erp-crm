<?php
// CRM part 2 rules (lib/crm_orders.php) — synthetic data only.
require_once WN_ROOT . '/lib/crm_orders.php';
t('ORD01 year labels and order numbers', so_fy('2026-11-19', 4) === '26-27' && so_fy('2027-03-31', 4) === '26-27' && so_fy('2026-03-31', 4) === '25-26' && so_fy('2026-06-01', 1) === '2026'
    && so_order_no('SO', 'br-1', '26-27', 7) === 'SO/BR1/26-27/0007' && so_order_no('SO', '', '2026', 12) === 'SO/2026/0012');
t('ORD02 status moves: forward by hand, dispatch states only by consignments, nothing after closed / cancelled', so_can_move('Draft', 'Submitted') && so_can_move('Submitted', 'In progress') && so_can_move('In progress', 'Ready for dispatch')
    && !so_can_move('Ready for dispatch', 'Dispatched') && !so_can_move('Partly dispatched', 'Cancelled') && so_can_move('Dispatched', 'Delivered') && !so_can_move('Closed', 'Draft') && !so_can_move('Cancelled', 'Submitted'));
t('ORD03 line value: discount before tax, rounded', so_line_value(3, 100, 10, 18) === [270.0, 48.6, 318.6] && so_line_value(1, 0.333, 0, 0) === [0.33, 0.0, 0.33]);
$o = fn($s, $d = 'Not assigned') => ['status' => $s, 'dispatch_status' => $d];
t('ORD04 customer stage from the order and its consignments', so_stage_of($o('Draft'), []) === '' && so_stage_of($o('Submitted'), []) === 'received' && so_stage_of($o('In progress'), []) === 'preparing'
    && so_stage_of($o('Ready for dispatch'), []) === 'packing' && so_stage_of($o('Partly dispatched'), ['Booked']) === 'sent' && so_stage_of($o('Dispatched'), ['Booked', 'In transit']) === 'on_way'
    && so_stage_of($o('Delivered'), ['Delivered']) === 'delivered' && so_stage_of($o('Cancelled'), []) === 'cancelled');
t('ORD05 the customer stage never steps back (cancel excepted)', so_stage_next('', 'received') === 'received' && so_stage_next('packing', 'preparing') === '' && so_stage_next('packing', 'sent') === 'sent'
    && so_stage_next('sent', 'sent') === '' && so_stage_next('on_way', 'cancelled') === 'cancelled' && so_stage_next('x', '') === '');
t('ORD06 carrier tracking address: {ref} filled and encoded, else copy the number, none without a page', so_carrier_link(['track_url' => 'https://track.example.com/?n={ref}'], 'AB 12') === ['url' => 'https://track.example.com/?n=AB%2012', 'copy' => false]
    && so_carrier_link(['track_url' => 'https://track.example.com/'], 'X')['copy'] === true && so_carrier_link(['track_url' => ''], 'X') === null);
$txt = so_stage_text('TEST Org', 'SO/1', 'sent', 'TEST Couriers', 'https://example.com/t');
t('ORD07 customer update: order, stage, carrier and link only', str_contains($txt, 'SO/1') && str_contains($txt, 'Handed to transport (TEST Couriers)') && str_contains($txt, 'https://example.com/t') && !preg_match('/\d+\.\d{2}/', $txt));
t('ORD08 punch day and cut-off', so_punch_day('2026-11-10 16:59:00', '17:00') === '2026-11-10' && so_punch_day('2026-11-10 17:01:00', '17:00') === '2026-11-11' && so_punch_day('2026-11-10 23:00:00', '') === '2026-11-10');
$orders = [['id' => 1, 'punched_at' => '2026-11-10 09:00:00', 'first_dispatch_at' => '2026-11-10 15:00:00'],
           ['id' => 2, 'punched_at' => '2026-11-10 10:00:00', 'first_dispatch_at' => '2026-11-11 11:00:00'],
           ['id' => 3, 'punched_at' => '2026-11-10 11:00:00', 'first_dispatch_at' => null],
           ['id' => 4, 'punched_at' => '2026-11-10 12:00:00', 'first_dispatch_at' => null],
           ['id' => 5, 'punched_at' => '2026-11-11 08:00:00', 'first_dispatch_at' => '2026-11-11 08:30:00']];
$bl = [3 => [['customer_side' => 1, 'created_at' => '2026-11-10 11:30:00', 'resolved_at' => null]], 4 => [['customer_side' => 0, 'created_at' => '2026-11-10 12:30:00', 'resolved_at' => '2026-11-10 18:00:00']]];
$sd = so_sameday_calc($orders, $bl, '');
t('ORD09 same-day score: customer holds left out; a blocker cleared the same day is no hold; next-day and median counted', $sd['total']['punched'] === 5 && $sd['total']['same_day'] === 2 && $sd['total']['customer_hold'] === 1
    && $sd['total']['internal_hold'] === 0 && $sd['total']['late'] === 2 && $sd['total']['next_day'] === 1 && $sd['total']['score'] === 50.0 && $sd['total']['median_min'] === 360
    && count($sd['days']) === 2 && $sd['days'][1]['score'] === 100.0 && so_sameday_calc([], [], '')['total']['score'] === null);
t('ORD10 request ids: a good one kept, anything else replaced', so_uuid('0F8FAD5B-D9CB-469F-A165-70867728950E') === '0f8fad5b-d9cb-469f-a165-70867728950e' && preg_match('/^[0-9a-f-]{36}$/', so_uuid('x')) === 1 && so_uuid('') !== so_uuid(''));
[$st, $se] = crm_settings_check(['admin_roles' => ['SUPERADMIN'], 'dispatch_roles' => ['STORE', 'NOPE'], 'order_prefix' => 'so-x!', 'link_days' => 999, 'same_day_cutoff' => '25:00', 'order_sources' => ''], ['SUPERADMIN' => 's', 'STORE' => 't']);
t('ORD11 order settings: roles, prefix, link days, cut-off checked, sources never empty', $st['dispatch_roles'] === ['STORE'] && $st['order_prefix'] === 'SOX' && $st['link_days'] === 365 && $st['same_day_cutoff'] === '' && count($se) === 1 && $st['order_sources'] === 'Other');
