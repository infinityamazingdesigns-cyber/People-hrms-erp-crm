<?php
/* Shared layout rules (assets/app.css, lib/ui.php) — UI pass 23 Nov 2026 */
$css = file_get_contents(WN_ROOT . '/assets/app.css'); $ui = file_get_contents(WN_ROOT . '/lib/ui.php');
t('UX01 a tick box sits beside its words, never stretched by a row rule', str_contains($css, 'label:has(> input[type=checkbox])') && str_contains($css, '.row input:not([type=checkbox]):not([type=radio])'));
t('UX02 wide tables scroll in their own box with the first column kept in view', str_contains($ui, "w.className='tbl-wrap'") && str_contains($css, '.tbl-wrap.scrolls>table>*>tr>:first-child{position:sticky'));
t('UX03 module tabs are one sideways row on a phone', str_contains($css, '.main>nav.row:first-of-type{flex-wrap:nowrap!important;overflow-x:auto'));
t('UX04 fields in table cells get a name from their column and row', str_contains($ui, "i.setAttribute('aria-label',n.slice(0,80))"));
t('UX05 grids never push a phone page sideways', str_contains($css, 'minmax(min(220px,100%),1fr)') && str_contains($css, '.grid2>*,.grid>*{min-width:0}'));
