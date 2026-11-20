<?php
/**
 * Module map — every module, how they connect (needs / needed by) and who may open what.
 * Read straight from the catalog (config/modules.php), the saved module states and config/rbac.php every time, so a new
 * module, role or right shows by itself. Super Admin only; read-only; no person's data, no counts.
 */
declare(strict_types=1);

/**
 * Pure: the map from catalog + states + roles.
 * @param array<string,array> $cat     module catalog
 * @param array<string,array> $states  module_states()
 * @param array<string,string> $roles  code => label
 * @return array{groups:array, mods:array, roles:array, edges:int, on:int}
 */
function mm_build(array $cat, array $states, array $roles): array {
    $mods = []; $by = [];
    foreach ($cat as $k => $m) {
        $st = $states[$k] ?? null;
        $on = !empty($m['core']) || !empty($st['enabled']);
        $allowed = ($st && !empty($st['roles'])) ? $st['roles'] : ($m['roles'] ?? []);
        $mods[$k] = [
            'key' => $k, 'name' => (string)$m['name'], 'group' => (string)$m['group'], 'about' => (string)($m['about'] ?? ''),
            'status' => (string)$m['status'], 'core' => !empty($m['core']), 'on' => $on,
            'needs' => array_values(array_filter($m['needs'] ?? [], fn($d) => isset($cat[$d]))),
            'needed_by' => [], 'roles' => array_values(array_intersect(array_keys($roles), $allowed)),
            'self' => !empty($m['self']), 'entry' => (string)($m['entry'] ?? ''),
        ];
        $by[$m['group']][] = $k;
    }
    $edges = 0;
    foreach ($mods as $k => $m) foreach ($m['needs'] as $d) { $mods[$d]['needed_by'][] = $k; $edges++; }
    $groups = [];
    foreach ($by as $g => $keys) $groups[$g] = $keys;
    $roleRows = [];
    foreach ($roles as $code => $label) {
        $roleRows[$code] = ['label' => $label, 'mods' => array_values(array_filter(array_keys($mods), fn($k) => in_array($code, $mods[$k]['roles'], true)))];
    }
    return ['groups' => $groups, 'mods' => $mods, 'roles' => $roleRows, 'edges' => $edges, 'on' => count(array_filter($mods, fn($m) => $m['on']))];
}

/** Pure: every module a module pulls in, directly or not (switching it on turns these on). */
function mm_needs_all(array $mods, string $k, array $seen = []): array {
    foreach ($mods[$k]['needs'] ?? [] as $d) if (!isset($seen[$d])) { $seen[$d] = true; $seen = array_fill_keys(mm_needs_all($mods, $d, $seen), true) + $seen; }
    return array_keys($seen);
}
