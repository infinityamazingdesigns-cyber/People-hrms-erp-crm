<?php
/**
 * India rule pack — provident fund, state insurance, professional tax. Ported from the reference build as rules,
 * never as one company's choices. Every figure here is a default the Super Admin confirms on Payroll → Rules (with the
 * month it applies from); professional-tax slabs differ by state and are entered there (none are assumed).
 */
declare(strict_types=1);

if (!function_exists('rp_in_statutory')) {
    /**
     * $earn = earned components (name => amount), $fullGross = monthly gross for a full month, $emp = pay profile
     * (pf_on, pf_full, esic_on: null = automatic, pt_exempt), $state = the branch's state, $ym = 'YYYY-MM'.
     * Returns ['emp' => [label => amount], 'er' => [label => amount], 'notes' => [...]], whole currency units.
     */
    function rp_in_statutory(array $earn, float $fullGross, array $emp, array $R, string $state, string $ym): array {
        $o = ['emp' => [], 'er' => [], 'notes' => []];
        $gross = array_sum($earn);
        $pf = $R['pf'] ?? [];
        if (!empty($pf['on']) && !empty($emp['pf_on'])) {
            $wage = 0.0;
            foreach ((array)($pf['parts'] ?? ['Basic']) as $p) $wage += (float)($earn[$p] ?? 0);
            $ceil = (float)($pf['ceiling'] ?? 15000);
            if (empty($emp['pf_full']) && $ceil > 0) $wage = min($wage, $ceil);
            $o['emp']['PF'] = round($wage * (float)($pf['emp_pct'] ?? 12) / 100);
            $er = round($wage * (float)($pf['er_pct'] ?? 12) / 100);
            $eps = round(min($wage, $ceil > 0 ? $ceil : $wage) * (float)($pf['eps_pct'] ?? 8.33) / 100);
            $o['er']['PF (EPF)'] = $er - min($eps, $er);
            $o['er']['PF (pension)'] = min($eps, $er);
            if (!empty($pf['charges'])) {
                $o['er']['EDLI'] = round(min($wage, $ceil > 0 ? $ceil : $wage) * (float)($pf['edli_pct'] ?? 0.5) / 100);
                $o['er']['PF admin'] = round($wage * (float)($pf['admin_pct'] ?? 0.5) / 100);
            }
        }
        $es = $R['esic'] ?? [];
        $covered = $emp['esic_on'] === null || $emp['esic_on'] === '' ? $fullGross <= (float)($es['ceiling'] ?? 21000) : (bool)$emp['esic_on'];
        if (!empty($es['on']) && $covered && $gross > 0) {
            $o['emp']['ESI'] = ceil($gross * (float)($es['emp_pct'] ?? 0.75) / 100);
            $o['er']['ESI'] = ceil($gross * (float)($es['er_pct'] ?? 3.25) / 100);
        }
        if (empty($emp['pt_exempt']) && $state !== '') {
            $slabs = $R['pt'][$state] ?? null;
            if ($slabs === null) $o['notes'][] = 'Professional tax not set for ' . $state;
            else if (($pt = rp_in_pt($slabs, $gross, (int)substr($ym, 5, 2))) > 0) $o['emp']['Professional tax'] = $pt;
        }
        return $o;
    }

    /** Slab amount for a month's gross; a slab may carry a different amount for one month (e.g. February). Pure. */
    function rp_in_pt(array $slabs, float $gross, int $month): float {
        foreach ($slabs as $s) {
            if ($gross < (float)$s['min'] || ($s['max'] !== null && $gross > (float)$s['max'])) continue;
            return (float)(isset($s['month'], $s['month_amt']) && (int)$s['month'] === $month ? $s['month_amt'] : $s['amt']);
        }
        return 0.0;
    }
}

return [
    'label' => 'India',
    'fn' => 'rp_in_statutory',
    'defaults' => [
        'pf' => ['on' => true, 'emp_pct' => 12, 'er_pct' => 12, 'eps_pct' => 8.33, 'ceiling' => 15000, 'parts' => ['Basic'], 'charges' => true, 'edli_pct' => 0.5, 'admin_pct' => 0.5],
        'esic' => ['on' => true, 'emp_pct' => 0.75, 'er_pct' => 3.25, 'ceiling' => 21000],
        'pt' => [],
    ],
];
