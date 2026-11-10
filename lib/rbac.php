<?php
/**
 * Roles, branch scope and the owner lock.
 *
 * The owner (users.is_owner = 1) is the person who installed the product. They always hold SUPERADMIN,
 * every role check that asks for SUPERADMIN passes for them, and nothing on any screen or API can take that away:
 * not deactivation, not removing the role, not deleting, not another Super Admin. Only the owner may hand
 * ownership to someone else (owner_transfer()), and only with their own password.
 */
declare(strict_types=1);

function rbac_config(): array {
    static $c = null;
    return $c ??= require WN_ROOT . '/config/rbac.php';
}

/** @return array<string,string> code => label */
function role_labels(): array { return array_map(fn($r) => $r['label'], rbac_config()['roles']); }

/** @return list<string> */
function user_roles(int $uid, bool $isOwner = false): array {
    $codes = [];
    try {
        $s = pdo()->prepare('SELECT role_code FROM user_role WHERE user_id = ? ORDER BY role_code');
        $s->execute([$uid]);
        $codes = array_column($s->fetchAll(), 'role_code');
    } catch (Throwable $e) {}
    if ($isOwner && !in_array('SUPERADMIN', $codes, true)) $codes[] = 'SUPERADMIN';
    return array_values(array_intersect($codes, array_keys(rbac_config()['roles'])));
}

/** @return list<int> branch ids; empty = every branch */
function user_branch_ids(int $uid): array {
    try {
        $s = pdo()->prepare('SELECT branch_id FROM user_branch WHERE user_id = ?');
        $s->execute([$uid]);
        return array_map('intval', array_column($s->fetchAll(), 'branch_id'));
    } catch (Throwable $e) { return []; }
}

function has_role(array $u, string ...$codes): bool {
    if (!empty($u['is_owner']) && in_array('SUPERADMIN', $codes, true)) return true;
    return (bool)array_intersect($codes, $u['roles'] ?? []);
}

function is_superadmin(array $u): bool { return has_role($u, 'SUPERADMIN'); }
function is_owner(array $u): bool { return !empty($u['is_owner']); }

/** Employee-only login: no role beyond EMPLOYEE (deny by default everywhere). */
function self_only(array $u): bool {
    $r = $u['roles'] ?? [];
    return !is_owner($u) && ($r === [] || $r === ['EMPLOYEE']);
}

/** May $actor give $role to someone? Pure over the config. */
function can_assign(array $actor, string $role): bool {
    if (!isset(rbac_config()['roles'][$role])) return false;
    if (is_owner($actor)) return true;
    foreach ($actor['roles'] ?? [] as $r) {
        $a = rbac_config()['assignable'][$r] ?? [];
        if ($a === '*' || (is_array($a) && in_array($role, $a, true))) return true;
    }
    return false;
}

/** Data class check: may this login see $class (basic, contact, personal, identity, pay, bank)? */
function data_allowed(array $u, string $class): bool {
    $allowed = rbac_config()['data'][$class] ?? [];
    if (in_array('*', $allowed, true)) return true;
    return $allowed !== [] && has_role($u, ...$allowed);
}

/* ---------- Owner lock ---------- */

/** Refusal text when $target may not be changed this way by $actor, else null. Pure. */
function owner_guard(array $actor, array $target, string $what): ?string {
    if (empty($target['is_owner'])) return null;
    return match ($what) {
        'deactivate', 'delete', 'remove_superadmin' => 'The owner always stays an active Super Admin.',
        'reset_password', 'edit' => ((int)$actor['id'] === (int)$target['id']) ? null : 'Only the owner changes the owner\'s own login.',
        default => null,
    };
}

/** Save a person's roles. SUPERADMIN is kept on the owner whatever is posted. */
function user_roles_save(array $actor, array $target, array $codes): ?string {
    $known = array_keys(rbac_config()['roles']);
    $codes = array_values(array_unique(array_intersect($codes, $known)));
    if (!empty($target['is_owner']) && !in_array('SUPERADMIN', $codes, true)) $codes[] = 'SUPERADMIN';
    $before = user_roles((int)$target['id']);
    foreach (array_merge(array_diff($codes, $before), array_diff($before, $codes)) as $changed) {
        if (!can_assign($actor, $changed)) return 'You cannot give or take away ' . (role_labels()[$changed] ?? $changed) . '.';
    }
    if ((int)$actor['id'] === (int)$target['id'] && !is_owner($actor) && $codes !== $before) return 'Nobody changes their own roles.';
    $db = pdo();
    $db->beginTransaction();
    $db->prepare('DELETE FROM user_role WHERE user_id = ?')->execute([(int)$target['id']]);
    $ins = $db->prepare('INSERT INTO user_role (user_id, role_code, given_by) VALUES (?,?,?)');
    foreach ($codes as $c) $ins->execute([(int)$target['id'], $c, (int)$actor['id']]);
    $db->commit();
    audit((int)$actor['id'], 'role_set', '#' . $target['id'] . ' ' . implode(',', $codes));
    return null;
}
