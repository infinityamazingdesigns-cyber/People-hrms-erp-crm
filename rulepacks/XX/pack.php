<?php
// No statutory rules: pay as entered (gross = CTC, no deductions besides what HR types).
declare(strict_types=1);
if (!function_exists('rp_none_statutory')) {
    function rp_none_statutory(array $earn, float $fullGross, array $emp, array $R, string $state, string $ym): array { return ['emp' => [], 'er' => [], 'notes' => []]; }
}
return ['label' => 'No statutory rules', 'fn' => 'rp_none_statutory', 'defaults' => [],
    'statutory' => ['schemes' => ['tax' => 'Tax on salary', 'lab' => 'Labour laws & registrations'],
        'map' => ['tax' => ['ee' => ['Income tax (TDS)'], 'er' => [], 'charges' => [], 'by_state' => false]], 'state_schemes' => [], 'portals' => [], 'dues' => [], 'regs' => []]];
