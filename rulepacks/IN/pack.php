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

if (!function_exists('rp_in_tax_id')) {
    /** GSTIN: pattern + mod-36 check character over the first 14; the state code and the PAN inside it. */
    function rp_in_tax_id(string $g): array {
        $g = strtoupper(preg_replace('/\s+/', '', $g));
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $g)) return ['ok' => false, 'why' => 'A GSTIN is 15 characters: 2 digits, the PAN (10), an entity number, Z and a check character.'];
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $sum = 0;
        for ($i = 0; $i < 14; $i++) { $p = strpos($chars, $g[$i]) * ($i % 2 ? 2 : 1); $sum += intdiv($p, 36) + $p % 36; }
        if ($chars[(36 - $sum % 36) % 36] !== $g[14]) return ['ok' => false, 'why' => 'The last character does not match — check the GSTIN for a typing slip.'];
        return ['ok' => true, 'why' => '', 'value' => $g, 'region' => substr($g, 0, 2), 'inner' => substr($g, 2, 10)];
    }
}

if (!function_exists('rp_in_sales_tax')) {
    /** GST on a sale: same state as the seller → CGST + SGST (half each), else IGST. Regions are the two-digit state codes. */
    function rp_in_sales_tax(string $seller, string $place): array {
        return $seller !== '' && $seller === $place ? [['CGST', 0.5], ['SGST', 0.5]] : [['IGST', 1.0]];
    }
    /** Indian-style amount in words: lakh and crore ("One Lakh Twenty Thousand Rupees Only", "… And Fifty Paise"). */
    function rp_in_words(float $amt, string $major = 'Rupees', string $minor = 'Paise'): string {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = fn(int $n) => $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
        $three = fn(int $n) => trim(($n >= 100 ? $ones[intdiv($n, 100)] . ' Hundred ' : '') . $two($n % 100));
        $amt = round(abs($amt), 2); $r = (int)floor($amt); $p = (int)round(($amt - $r) * 100); if ($p === 100) { $r++; $p = 0; }
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $w]) if ($r >= $div) { $n = intdiv($r, $div); $parts[] = ($n >= 100 ? preg_replace('/ ' . $major . ' Only$/', '', rp_in_words((float)$n, $major, $minor)) : $two($n)) . ' ' . $w; $r %= $div; }
        if ($r > 0) $parts[] = $three($r);
        return ($parts ? implode(' ', $parts) : 'Zero') . " $major" . ($p > 0 ? ' And ' . $two($p) . " $minor" : '') . ' Only';
    }
}

return [
    'label' => 'India',
    'tax_id' => ['label' => 'GSTIN', 'fn' => 'rp_in_tax_id'],
    // Sales tax on quotations / orders: regions are the GST state codes (the first two digits of a GSTIN).
    'sales_tax' => ['label' => 'GST', 'fn' => 'rp_in_sales_tax', 'words' => 'rp_in_words', 'regions' => [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi',
        '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram',
        '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
        '24' => 'Gujarat', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala',
        '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh'],
        'aliases' => ['orissa' => '21', 'pondicherry' => '34', 'newdelhi' => '07', 'nctofdelhi' => '07', 'uttaranchal' => '05', 'jandk' => '01']],
    'fn' => 'rp_in_statutory',
    'defaults' => [
        'pf' => ['on' => true, 'emp_pct' => 12, 'er_pct' => 12, 'eps_pct' => 8.33, 'ceiling' => 15000, 'parts' => ['Basic'], 'charges' => true, 'edli_pct' => 0.5, 'admin_pct' => 0.5],
        'esic' => ['on' => true, 'emp_pct' => 0.75, 'er_pct' => 3.25, 'ceiling' => 21000],
        'pt' => [],
        'lwf' => [], 'lwf_deduct' => true,
    ],
    'returns' => ['ecr' => 'PF return (ECR text)', 'esi' => 'ESI monthly sheet (CSV)', 'state' => 'Professional tax and labour welfare fund by state (CSV)'],
    // Statutory compliance: schemes, which payslip lines make up each, and public national links / dues / registrations
    // seeded once (the Super Admin confirms each; state links and dues are added for the organisation's own states).
    'statutory' => [
        'schemes' => ['pf' => 'Provident fund (EPFO)', 'esi' => 'State insurance (ESIC)', 'pt' => 'Professional tax', 'lwf' => 'Labour welfare fund',
                      'tds' => 'Income tax on salary (TDS)', 'lab' => 'Labour laws & registrations'],
        'map' => ['pf' => ['ee' => ['PF'], 'er' => ['PF (EPF)', 'PF (pension)'], 'charges' => ['EDLI', 'PF admin'], 'by_state' => false],
                  'esi' => ['ee' => ['ESI'], 'er' => ['ESI'], 'charges' => [], 'by_state' => false],
                  'pt' => ['ee' => ['Professional tax'], 'er' => [], 'charges' => [], 'by_state' => true],
                  'lwf' => ['ee' => ['Labour welfare fund'], 'er' => ['Labour welfare fund'], 'charges' => [], 'by_state' => true],
                  'tds' => ['ee' => ['Income tax (TDS)'], 'er' => [], 'charges' => [], 'by_state' => false]],
        'state_schemes' => ['pt', 'lwf'],
        'portals' => [   // key => [scheme, audience, name, https address, note]
            'pf_member' => ['pf', 'emp', 'EPFO member portal (UAN)', 'https://unifiedportal-mem.epfindia.gov.in/memberinterface/', 'UAN activation, KYC, nominee, claims and transfers'],
            'pf_passbook' => ['pf', 'emp', 'EPF passbook', 'https://passbook.epfindia.gov.in/MemberPassBook/login', 'Monthly credits by member ID'],
            'pf_emp' => ['pf', 'pay', 'EPFO employer portal', 'https://unifiedportal-emp.epfindia.gov.in/epfo/', 'Upload the ECR, make the challan, pay; new joiners\' UAN and exits'],
            'esi_ip' => ['esi', 'emp', 'ESIC insured person portal', 'https://www.esic.in/EmployeePortal/login.aspx', 'e-Pehchan card, family details, entitlement'],
            'esi_emp' => ['esi', 'pay', 'ESIC employer portal', 'https://www.esic.gov.in/', 'Monthly contribution and challan; new IP registration and exits'],
            'tds_pay' => ['tds', 'pay', 'Income-tax e-filing — e-Pay Tax', 'https://www.incometax.gov.in/iec/foportal/', 'TAN login: tax deducted on salary'],
            'tds_traces' => ['tds', 'proc', 'TRACES — statements and Form 16', 'https://www.tdscpc.gov.in/', 'Quarterly statements, Form 16, defaults'],
            'tds_emp' => ['tds', 'emp', 'Income-tax e-filing — Form 26AS / AIS', 'https://www.incometax.gov.in/iec/foportal/', 'Each person checks the tax deposited against their PAN'],
            'shram' => ['lab', 'proc', 'Shram Suvidha — labour registrations', 'https://shramsuvidha.gov.in/', 'Establishment registration and the Labour Identification Number'],
        ],
        'dues' => [      // key => [scheme, label, day (31 = last), wage months ('' = every), months after, kind, who, checked]
            'pf' => ['pf', 'Provident fund — ECR and challan', 15, '', 1, 'both', 'hr', 1],
            'esi' => ['esi', 'State insurance — monthly contribution', 15, '', 1, 'both', 'hr', 1],
            'tds' => ['tds', 'Tax on salary — deposit', 7, '1,2,4,5,6,7,8,9,10,11,12', 1, 'pay', 'accounts', 1],
            'tds_mar' => ['tds', 'Tax on salary — March deposit', 30, '3', 1, 'pay', 'accounts', 1],
            'tds_q' => ['tds', 'Quarterly TDS statement (24Q)', 31, '6,9,12', 1, 'file', 'ca', 0],
            'tds_q4' => ['tds', 'Quarterly TDS statement (24Q) — last quarter', 31, '3', 2, 'file', 'ca', 0],
        ],
        'regs' => [      // key => [scheme, label]
            'pf_code' => ['pf', 'PF establishment code'], 'esi_code' => ['esi', 'ESIC employer code'],
            'tan' => ['tds', 'Tax deduction account number (TAN)'], 'lin' => ['lab', 'Labour Identification Number (LIN)'],
        ],
    ],
];
