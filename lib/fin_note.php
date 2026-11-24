<?php
/** Finance seen from other modules: where a record stands in the books. Light on purpose — no Finance rules loaded. */
declare(strict_types=1);

if (!function_exists('fin_has')) {
function fin_has(PDO $db, string $t): bool {
    static $c = [];
    if (!isset($c[$t])) { $q = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'); $q->execute([$t]); $c[$t] = (bool)$q->fetchColumn(); }
    return $c[$t];
}
}

/**
 * Where a source record stands in the books, for its own module's page: '' when Finance is off for the login or the
 * record has not come in, else a short line with the voucher state (and a link for logins who can open Finance).
 */
function fin_feed_note(PDO $db, array $u, string $src, string $ref): string {
    try {
        if (!in_array($src, ['payroll', 'claim', 'vendor', 'sale', 'purchase'], true) || !module_allowed($u, 'finance') || !fin_has($db, 'fin_feed')) return '';
        $q = $db->prepare('SELECT v.id, v.status, v.number FROM fin_feed f JOIN fin_voucher v ON v.id = f.voucher_id WHERE f.source = ? AND f.source_ref = ?');
        $q->execute([$src, $ref]);
        $v = $q->fetch();
        if (!$v) return '<span class="pill">Not in the books yet</span>';
        $t = $v['status'] === 'posted' ? 'In the books · ' . $v['number'] : 'In the books · ' . (['draft' => 'Draft', 'waiting' => 'Waiting for approval'][$v['status']] ?? $v['status']);
        return '<a class="pill" href="' . '../finance/index.php?v=voucher&amp;id=' . (int)$v['id'] . '">' . h($t) . '</a>';
    } catch (Throwable $e) { return ''; }
}

