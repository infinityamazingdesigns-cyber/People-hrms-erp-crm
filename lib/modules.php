<?php
/**
 * Module gate. A module opens for a login only when:
 *   1. it is ON for the organisation (core modules are always on);
 *   2. the login holds a role the Super Admin allowed for it, or has a per-person "allow";
 *   3. the login has no per-person "deny".
 * The owner always passes for the administration (core) modules. Every page of a module starts with
 * module_require('<key>'); menus list only module_allowed() modules.
 */
declare(strict_types=1);

/** @return array<string,array> */
function module_catalog(): array {
    static $c = null;
    return $c ??= require WN_ROOT . '/config/modules.php';
}

/** @return array<string,array{enabled:bool,roles:list<string>,settings:array}> */
function module_states(): array {
    static $s = null;
    if ($s !== null && empty($GLOBALS['WN_MOD_RESET'])) return $s;
    $GLOBALS['WN_MOD_RESET'] = false;
    $s = [];
    try {
        foreach (pdo()->query('SELECT module_key, enabled, roles, settings FROM module_state') as $r)
            $s[$r['module_key']] = ['enabled' => (bool)$r['enabled'],
                'roles' => array_values(array_filter(explode(',', (string)$r['roles']))),
                'settings' => json_decode((string)$r['settings'], true) ?: []];
    } catch (Throwable $e) {}
    return $s;
}

function module_on(string $key): bool {
    $m = module_catalog()[$key] ?? null;
    if (!$m) return false;
    if (!empty($m['core'])) return true;
    return module_states()[$key]['enabled'] ?? false;
}

/** Roles allowed for the module: the saved list, else the catalog default. */
function module_roles(string $key): array {
    $st = module_states()[$key] ?? null;
    return $st && $st['roles'] ? $st['roles'] : (module_catalog()[$key]['roles'] ?? []);
}

function module_person(int $uid, string $key): ?string {
    try {
        $s = pdo()->prepare('SELECT mode FROM module_person WHERE user_id = ? AND module_key = ?');
        $s->execute([$uid, $key]);
        return ($m = $s->fetchColumn()) ? (string)$m : null;
    } catch (Throwable $e) { return null; }
}

/** Pure decision, testable without a database. */
function module_decide(array $mod, bool $on, array $allowedRoles, array $userRoles, bool $owner, ?string $person): bool {
    if (!empty($mod['core']) && $owner) return true;
    if (!$on) return false;
    if ($person === 'deny') return false;
    if ($person === 'allow') return true;
    if ($owner && in_array('SUPERADMIN', $allowedRoles, true)) return true;
    return (bool)array_intersect($allowedRoles, $userRoles);
}

function module_allowed(array $u, string $key): bool {
    $mod = module_catalog()[$key] ?? null;
    if (!$mod) return false;
    return module_decide($mod, module_on($key), module_roles($key), $u['roles'] ?? [], is_owner($u), module_person((int)$u['id'], $key));
}

/** Use at the top of every module page. */
function module_require(string $key, bool $api = false): array {
    $u = require_login($api);
    if (module_allowed($u, $key)) return $u;
    http_response_code(403);
    if ($api) { header('Content-Type: application/json'); echo json_encode(['error' => 'module_not_allowed']); exit; }
    ui_head('Not available', $u);
    echo '<div class="card"><h2>Not available</h2><p>This module is switched off or not given to your login. Ask your Super Admin.</p></div>';
    ui_foot(); exit;
}

/* ---------- Switching on / off (Super Admin) ---------- */

/** Modules that must be on before $key (transitively). Pure over the catalog. */
function module_needs_all(string $key, ?array $cat = null, array $seen = []): array {
    $cat ??= module_catalog();
    foreach ($cat[$key]['needs'] ?? [] as $n) {
        if (in_array($n, $seen, true)) continue;
        $seen[] = $n;
        $seen = module_needs_all($n, $cat, $seen);
    }
    return $seen;
}

/** Modules that are on and need $key. */
function module_dependants_on(string $key): array {
    $out = [];
    foreach (module_catalog() as $k => $m)
        if ($k !== $key && module_on($k) && in_array($key, module_needs_all($k), true)) $out[] = $k;
    return $out;
}

/** Turn a module on or off. Returns [list of keys changed, ?error]. */
function module_switch(array $actor, string $key, bool $on): array {
    if (!is_superadmin($actor)) return [[], 'Only a Super Admin switches modules.'];
    $m = module_catalog()[$key] ?? null;
    if (!$m) return [[], 'Unknown module.'];
    if (!empty($m['core'])) return [[], $m['name'] . ' is always on.'];
    $keys = $on ? array_merge(array_filter(module_needs_all($key), fn($k) => !module_on($k)), [$key]) : [$key];
    if (!$on && ($dep = module_dependants_on($key)))
        return [[], 'Switch off first: ' . implode(', ', array_map(fn($k) => module_catalog()[$k]['name'], $dep))];
    $st = pdo()->prepare('INSERT INTO module_state (module_key, enabled, roles, updated_by) VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by)');
    foreach ($keys as $k) $st->execute([$k, $on ? 1 : 0, implode(',', module_roles($k)), (int)$actor['id']]);
    $GLOBALS['WN_MOD_RESET'] = true;
    audit((int)$actor['id'], 'module_' . ($on ? 'on' : 'off'), implode(',', $keys));
    return [$keys, null];
}

function module_roles_save(array $actor, string $key, array $roles): ?string {
    if (!is_superadmin($actor)) return 'Only a Super Admin changes module access.';
    if (!isset(module_catalog()[$key])) return 'Unknown module.';
    $roles = array_values(array_intersect($roles, array_keys(rbac_config()['roles'])));
    if (!empty(module_catalog()[$key]['core']) && !in_array('SUPERADMIN', $roles, true)) $roles[] = 'SUPERADMIN';
    pdo()->prepare('INSERT INTO module_state (module_key, enabled, roles, updated_by) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE roles = VALUES(roles), updated_by = VALUES(updated_by)')
         ->execute([$key, module_on($key) ? 1 : 0, implode(',', $roles), (int)$actor['id']]);
    $GLOBALS['WN_MOD_RESET'] = true;
    audit((int)$actor['id'], 'module_roles', $key . ': ' . implode(',', $roles));
    return null;
}

function module_person_save(array $actor, int $uid, string $key, ?string $mode, string $reason): ?string {
    if (!is_superadmin($actor)) return 'Only a Super Admin sets per-person access.';
    if (!isset(module_catalog()[$key])) return 'Unknown module.';
    if ($mode === null) {
        pdo()->prepare('DELETE FROM module_person WHERE user_id = ? AND module_key = ?')->execute([$uid, $key]);
    } else {
        if (!in_array($mode, ['allow', 'deny'], true)) return 'Pick allow or deny.';
        if (trim($reason) === '') return 'Give a reason.';
        pdo()->prepare('INSERT INTO module_person (user_id, module_key, mode, reason, set_by) VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE mode = VALUES(mode), reason = VALUES(reason), set_by = VALUES(set_by), set_at = NOW()')
             ->execute([$uid, $key, $mode, mb_substr($reason, 0, 300), (int)$actor['id']]);
    }
    audit((int)$actor['id'], 'module_person', '#' . $uid . ' ' . $key . ' ' . ($mode ?? 'as role'));
    return null;
}

/** The menu: allowed modules by group, in catalog order. */
function module_menu(array $u): array {
    $out = [];
    foreach (module_catalog() as $k => $m)
        if (module_allowed($u, $k)) $out[$m['group']][$k] = $m;
    return $out;
}
