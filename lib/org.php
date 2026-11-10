<?php
/**
 * Organisation settings and branches. Everything an organisation sets up lives here — never in code:
 * name, legal name, logo, colours, time zone, currency, country (statutory rule pack), branches and their spellings.
 */
declare(strict_types=1);

const ORG_DEFAULTS = [
    'name' => 'Your Company',
    'legal_name' => '',
    'tagline' => '',
    'country' => 'IN',               // statutory rule pack (see docs/RULE_PACKS.md)
    'timezone' => 'Asia/Kolkata',
    'currency' => 'INR',
    'date_format' => 'd M Y',
    'color_primary' => '#13265C',
    'color_accent' => '#F2B705',
    'logo' => '',                    // data: URL of the uploaded logo (PNG / SVG / WebP, ≤ 300 KB)
    'support_email' => '',
    'week_start' => 'Mon',
    'fy_start_month' => '4',         // financial / leave year start (1–12)
    'setup_done' => '0',
];

/** Fields the Organisation screen edits, with their checks. */
const ORG_FIELDS = [
    'name' => ['label' => 'Display name', 'max' => 80, 'required' => true],
    'legal_name' => ['label' => 'Legal name', 'max' => 160],
    'tagline' => ['label' => 'Tagline', 'max' => 120],
    'country' => ['label' => 'Country (statutory rules)', 'choices' => ['IN' => 'India', 'AE' => 'UAE', 'GB' => 'United Kingdom', 'US' => 'United States', 'XX' => 'Other / none']],
    'timezone' => ['label' => 'Time zone', 'max' => 40],
    'currency' => ['label' => 'Currency', 'choices' => ['INR' => 'INR ₹', 'AED' => 'AED', 'GBP' => 'GBP £', 'USD' => 'USD $', 'EUR' => 'EUR €']],
    'date_format' => ['label' => 'Date format', 'choices' => ['d M Y' => '07 Nov 2026', 'd/m/Y' => '07/11/2026', 'Y-m-d' => '2026-11-07', 'm/d/Y' => '11/07/2026']],
    'color_primary' => ['label' => 'Primary colour', 'color' => true],
    'color_accent' => ['label' => 'Accent colour', 'color' => true],
    'support_email' => ['label' => 'HR support email', 'email' => true, 'max' => 160],
    'week_start' => ['label' => 'Week starts on', 'choices' => ['Mon' => 'Monday', 'Sun' => 'Sunday', 'Sat' => 'Saturday']],
    'fy_start_month' => ['label' => 'Year starts in month', 'choices' => ['1' => 'January', '4' => 'April', '7' => 'July', '10' => 'October']],
];

function org(string $k): string {
    static $cache = null;
    if ($cache === null || !empty($GLOBALS['WN_ORG_RESET'])) {
        $GLOBALS['WN_ORG_RESET'] = false;
        $cache = ORG_DEFAULTS;
        try { foreach (pdo()->query('SELECT k, v FROM org_setting') as $r) $cache[$r['k']] = (string)$r['v']; } catch (Throwable $e) {}
    }
    return (string)($cache[$k] ?? '');
}

function org_set(string $k, string $v, ?int $by): void {
    pdo()->prepare('INSERT INTO org_setting (k, v, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)')
         ->execute([$k, $v, $by]);
    $GLOBALS['WN_ORG_RESET'] = true;
}

/** Check one field; returns [clean value, ?error]. Pure. */
function org_check(string $k, string $v): array {
    $f = ORG_FIELDS[$k] ?? null;
    if (!$f) return ['', 'Unknown field'];
    $v = trim($v);
    if (!empty($f['required']) && $v === '') return ['', $f['label'] . ' is required'];
    if (isset($f['max']) && mb_strlen($v) > $f['max']) return ['', $f['label'] . ' is too long'];
    if (isset($f['choices']) && !array_key_exists($v, $f['choices'])) return ['', $f['label'] . ': pick from the list'];
    if (!empty($f['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) return ['', $f['label'] . ': use #RRGGBB'];
    if (!empty($f['email']) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) return ['', $f['label'] . ': not an email address'];
    if ($k === 'timezone' && !in_array($v, timezone_identifiers_list(), true)) return ['', 'Time zone: not recognised'];
    return [$v, null];
}

/* ---------- Branches ---------- */

/** @return list<array> active first, then by sort / name */
function branches(bool $activeOnly = true): array {
    try {
        $sql = 'SELECT * FROM branch' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, sort, name';
        return pdo()->query($sql)->fetchAll();
    } catch (Throwable $e) { return []; }
}

function branch_key(string $s): string { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }

/** Any spelling (name, code or an alias) → the branch's own name; unknown text is returned as it was. Pure over $list. */
function branch_label(string $raw, ?array $list = null): string {
    $k = branch_key($raw);
    if ($k === '') return $raw;
    foreach ($list ?? branches(false) as $b) {
        $keys = [branch_key($b['name']), branch_key($b['code'])];
        foreach (explode(',', (string)$b['aliases']) as $a) if (trim($a) !== '') $keys[] = branch_key($a);
        if (in_array($k, $keys, true)) return (string)$b['name'];
    }
    return $raw;
}
