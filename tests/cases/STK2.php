<?php
// Stock part 2 rules (lib/stock_make.php) — synthetic data only.
require_once WN_ROOT . '/lib/stock_make.php';
t('STK10 revisions run A → B … Z → AA → AB', stk_next_rev('') === 'A' && stk_next_rev('A') === 'B' && stk_next_rev('Z') === 'AA' && stk_next_rev('AZ') === 'BA' && stk_next_rev('ZZ') === 'AAA');
$plan = stk_scale([['item_id' => 1, 'qty' => 2, 'scrap_pct' => 0, 'code' => 'P1'], ['item_id' => 2, 'qty' => 0.5, 'scrap_pct' => 10, 'code' => 'P2']], 4, 10, [1 => 3.0]);
t('STK11 production plan scales the BOM by quantity made ÷ BOM output, scrap added, shortage against stock', $plan[0]['qty'] === 5.0 && $plan[0]['short'] === 2.0 && $plan[0]['on_hand'] === 3.0 && $plan[1]['qty'] === 1.375 && $plan[1]['short'] === 1.375 && $plan[0]['code'] === 'P1');
$map = [10 => [11, 12], 11 => [13], 13 => []];
t('STK12 circle check: a part that is made from the item (at any depth) is refused', stk_reaches($map, 11, 13) && stk_reaches($map, 10, 13) && !stk_reaches($map, 12, 10) && !stk_reaches($map, 13, 10) && stk_reaches($map, 5, 5));
t('STK13 made-item cost from the parts used, unknown when any part has no cost', stk_made_cost([1 => 2.0, 2 => 3.0], [1 => 10.0, 2 => 1.5], 2) === 12.25 && stk_made_cost([1 => 2.0], [1 => null], 1) === null && stk_made_cost([], [], 1) === null);
t('STK14 production lines: made item in, parts out, statement counts them as made / used', count(stk_check_lines('PRD', [['item_id' => 1, 'qty' => '2', 'kind' => 'PRD_IN'], ['item_id' => 2, 'qty' => '-4', 'kind' => 'PRD_USE']])) === 2
    && ($s = stk_statement_calc([['item_id' => 1, 'd' => '2026-11-05', 'qty' => 2, 'kind' => 'PRD_IN'], ['item_id' => 2, 'd' => '2026-11-05', 'qty' => -4, 'kind' => 'PRD_USE']], '2026-11-01', '2026-11-30'))
    && $s[1]['in_prd'] === 2.0 && $s[2]['out_use'] === 4.0 && $s[2]['closing'] === -4.0);
[$st, $se] = stk_settings_check(['store_roles' => ['STORE'], 'produce_roles' => ['PRODHEAD', 'X'], 'ship_from' => '2026-02-30'], ['STORE' => 's', 'PRODHEAD' => 'p']);
t('STK15 settings: production roles, dispatch start day checked', $st['produce_roles'] === ['PRODHEAD'] && $st['ship_from'] === '' && count($se) === 1);
