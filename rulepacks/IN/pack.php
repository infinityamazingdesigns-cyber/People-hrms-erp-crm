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
        $o = ['emp' => [], 'er' => [], 'notes' => [], 'wages' => []];
        $gross = array_sum($earn);
        $pf = $R['pf'] ?? [];
        if (!empty($pf['on']) && !empty($emp['pf_on'])) {
            $wage = 0.0;
            foreach ((array)($pf['parts'] ?? ['Basic']) as $p) $wage += (float)($earn[$p] ?? 0);
            $ceil = (float)($pf['ceiling'] ?? 15000);
            if (empty($emp['pf_full']) && $ceil > 0) $wage = min($wage, $ceil);
            $o['wages']['pf'] = $wage;
            $o['wages']['eps'] = min($wage, $ceil > 0 ? $ceil : $wage);
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
            $o['wages']['esi'] = $gross;
            $o['emp']['ESI'] = ceil($gross * (float)($es['emp_pct'] ?? 0.75) / 100);
            $o['er']['ESI'] = ceil($gross * (float)($es['er_pct'] ?? 3.25) / 100);
        }
        if (empty($emp['pt_exempt']) && $state !== '') {
            $slabs = $R['pt'][$state] ?? null;
            if ($slabs === null) $o['notes'][] = 'Professional tax not set for ' . $state;
            else if (($pt = rp_in_pt($slabs, $gross, (int)substr($ym, 5, 2))) > 0) $o['emp']['Professional tax'] = $pt;
        }
        $lw = $R['lwf'][$state] ?? null;
        if ($state !== '' && $lw && in_array((int)substr($ym, 5, 2), (array)$lw['months'], true) && $gross > 0) {
            if (!empty($R['lwf_deduct'])) { $o['emp']['Labour welfare fund'] = (float)$lw['emp']; $o['er']['Labour welfare fund'] = (float)$lw['er']; }
            else $o['er']['Labour welfare fund'] = (float)$lw['emp'] + (float)$lw['er'];
        }
        return $o;
    }

    /** "6 18 6,12" → employee 6, employer 18, in June and December. Pure. */
    function rp_in_lwf_parse(string $t): ?array {
        $t = trim($t);
        if ($t === '') return null;
        if (!preg_match('/^(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+([0-9 ,]+)$/', $t, $m)) throw new InvalidArgumentException('Labour welfare fund: write employee, employer, months (e.g. "6 18 6,12").');
        $months = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $m[3])), fn($x) => $x >= 1 && $x <= 12)));
        if (!$months) throw new InvalidArgumentException('Labour welfare fund: give the months it is paid (1–12).');
        sort($months);
        return ['emp' => (float)$m[1], 'er' => (float)$m[2], 'months' => $months];
    }

    /** PF monthly return (ECR 2.0 text) from locked lines. $uan = code => 12-digit UAN. Pure. */
    function rp_in_ecr(array $lines, array $uan): array {
        $out = []; $miss = [];
        foreach ($lines as $l) {
            // Lines locked before wage bases were kept: Basic up to the usual ceiling, only for people with PF deducted.
            $pfw = (float)($l['wages']['pf'] ?? (isset($l['deductions']['PF']) ? min((float)($l['earned']['Basic'] ?? 0), 15000.0) : 0));
            if ($pfw <= 0 || !empty($l['missing_ctc'])) continue;
            $u = preg_replace('/\D/', '', (string)($uan[strtoupper($l['emp_code'])] ?? ''));
            if (strlen($u) !== 12) { $miss[] = [$l['emp_code'], $l['name'], 'No 12-digit UAN']; continue; }
            $eps = (float)($l['employer']['PF (pension)'] ?? 0);
            $out[] = implode('#~#', [$u, strtoupper(trim(preg_replace('/[^A-Za-z .]/', '', (string)$l['name']))), (int)round(array_sum((array)$l['earned'])), (int)round($pfw),
                $eps > 0 ? (int)round((float)($l['wages']['eps'] ?? $pfw)) : 0, (int)round((float)($l['wages']['eps'] ?? $pfw)), (int)round((float)($l['deductions']['PF'] ?? 0)), (int)round($eps),
                (int)round((float)($l['employer']['PF (EPF)'] ?? 0)), (int)round((float)($l['days'] ?? 0) - (float)($l['paid_days'] ?? 0)), 0]);
        }
        return ['text' => $out ? implode("\n", $out) . "\n" : '', 'count' => count($out), 'left_out' => $miss];
    }

    /** ESI monthly sheet (CSV rows) from locked lines. $ip = code => 10-digit number; $exits = code => last day. Pure. */
    function rp_in_esi(array $lines, array $ip, string $ym, array $exits = []): array {
        $rows = [['IP Number', 'IP Name', 'Days paid', 'Total monthly wages', 'Reason code for zero days', 'Last working day']]; $miss = [];
        foreach ($lines as $l) {
            if (!isset($l['deductions']['ESI']) && !isset($l['employer']['ESI'])) continue;
            $n = preg_replace('/\D/', '', (string)($ip[strtoupper($l['emp_code'])] ?? ''));
            if (strlen($n) !== 10) { $miss[] = [$l['emp_code'], $l['name'], 'No 10-digit ESI number']; continue; }
            $days = (int)round((float)($l['paid_days'] ?? 0));
            $lwd = isset($exits[strtoupper($l['emp_code'])]) && substr((string)$exits[strtoupper($l['emp_code'])], 0, 7) === $ym ? date('d/m/Y', strtotime((string)$exits[strtoupper($l['emp_code'])])) : '';
            $rows[] = [$n, (string)$l['name'], $days, $days === 0 ? 0 : (int)round((float)($l['wages']['esi'] ?? array_sum((array)$l['earned']))), $lwd !== '' ? 2 : ($days === 0 ? 1 : ''), $lwd];
        }
        return ['rows' => $rows, 'count' => count($rows) - 1, 'left_out' => $miss];
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
        'lwf' => [], 'lwf_deduct' => true,
    ],
    'returns' => ['ecr' => 'PF return (ECR text)', 'esi' => 'ESI monthly sheet (CSV)', 'state' => 'Professional tax and labour welfare fund by state (CSV)'],
];
