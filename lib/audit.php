<?php
/**
 * Audit log. Sensitive families go on a SHA-256 hash chain (prev_hash → row_hash) that audit_verify() checks,
 * so a deleted or edited row is detectable. Everything else is a plain row. Never write values of ID numbers,
 * bank details or salaries into an audit line — field names and counts only.
 */
declare(strict_types=1);

const AUDIT_CHAINED_PREFIXES = ['login', 'logout', 'user_', 'role_', 'module_', 'org_', 'owner_', 'pay_', 'kyc_', 'bank_', 'seal_'];

function audit_is_sensitive(string $action): bool {
    foreach (AUDIT_CHAINED_PREFIXES as $p) if (str_starts_with($action, $p)) return true;
    return false;
}

function audit_row_hash(?string $prev, ?int $uid, string $action, string $detail, string $ip, string $at): string {
    return hash('sha256', implode('|', [(string)$prev, (string)$uid, $action, $detail, $ip, $at]));
}

function audit(?int $uid, string $action, string $detail = ''): void {
    $detail = mb_substr($detail, 0, 1000);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $at = date('Y-m-d H:i:s');
    try {
        $db = pdo();
        if (!audit_is_sensitive($action)) {
            $db->prepare('INSERT INTO audit (user_id, action, detail, ip, at) VALUES (?,?,?,?,?)')->execute([$uid, $action, $detail, $ip, $at]);
            return;
        }
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        $prev = $db->query('SELECT row_hash FROM audit WHERE chained = 1 ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn() ?: null;
        $hash = audit_row_hash($prev, $uid, $action, $detail, $ip, $at);
        $db->prepare('INSERT INTO audit (user_id, action, detail, ip, at, chained, prev_hash, row_hash) VALUES (?,?,?,?,?,1,?,?)')
           ->execute([$uid, $action, $detail, $ip, $at, $prev, $hash]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        // never lose the event: fall back to a plain row
        try { pdo()->prepare('INSERT INTO audit (user_id, action, detail, ip, at) VALUES (?,?,?,?,?)')->execute([$uid, $action, $detail, $ip, $at]); } catch (Throwable $e2) {}
    }
}

/** @return array{ok:bool, rows:int, broken_at:?int} */
function audit_verify(PDO $db): array {
    $prev = null; $n = 0;
    foreach ($db->query('SELECT id, user_id, action, detail, ip, at, prev_hash, row_hash FROM audit WHERE chained = 1 ORDER BY id') as $r) {
        $n++;
        $uid = $r['user_id'] === null ? null : (int)$r['user_id'];
        if ($r['prev_hash'] !== $prev || audit_row_hash($prev, $uid, $r['action'], $r['detail'], $r['ip'], (string)$r['at']) !== $r['row_hash'])
            return ['ok' => false, 'rows' => $n, 'broken_at' => (int)$r['id']];
        $prev = $r['row_hash'];
    }
    return ['ok' => true, 'rows' => $n, 'broken_at' => null];
}
