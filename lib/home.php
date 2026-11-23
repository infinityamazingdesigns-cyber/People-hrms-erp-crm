<?php
/**
 * Home page — each login's own day at a glance: today's punches, up to 10 urgent tasks with a tick, what waits for
 * them to act on (each count only from a module the login may open, through that module's own waiting rule), and how
 * many of their requests are still open. Own data only; never another person's record or any amount.
 */
declare(strict_types=1);
require_once __DIR__ . '/myday.php';

const HM_TASKS_SHOW = 10;

/** Pure: why a task is urgent — late, today, tomorrow, high — or '' (not urgent). */
function hm_task_why(array $t, string $today): string {
    $d = (string)($t['due_date'] ?? '');
    if ($d !== '' && $d < $today) return 'late';
    if ($d === $today) return 'today';
    if ($d !== '' && $d === date('Y-m-d', strtotime($today . ' +1 day'))) return 'tomorrow';
    return (int)($t['prio'] ?? 2) === 1 ? 'high' : '';
}

/** Pure: the urgent tasks first (late → today → tomorrow → high), then by due date / time; at most $max. */
function hm_task_pick(array $tasks, string $today, int $max = HM_TASKS_SHOW): array {
    $rank = ['late' => 0, 'today' => 1, 'tomorrow' => 2, 'high' => 3];
    $keep = [];
    foreach ($tasks as $t) {
        if (!empty($t['done_at']) || !empty($t['removed_at']) || ($t['review'] ?? '') === 'waiting') continue;
        $w = hm_task_why($t, $today);
        if ($w === '') continue;
        $t['why'] = $w; $keep[] = $t;
    }
    usort($keep, fn($a, $b) => [$rank[$a['why']], (int)$a['prio'], (string)($a['due_date'] ?? '9999'), (string)($a['due_time'] ?? '99'), (int)$a['id']]
        <=> [$rank[$b['why']], (int)$b['prio'], (string)($b['due_date'] ?? '9999'), (string)($b['due_time'] ?? '99'), (int)$b['id']]);
    return array_slice($keep, 0, $max);
}

/** Pure: drop empty lines and sort the biggest first. */
function hm_waiting_clean(array $lines): array {
    $lines = array_values(array_filter($lines, fn($l) => (int)$l[1] > 0));
    usort($lines, fn($a, $b) => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));
    return $lines;
}

/** What waits for this login: [label, count, link]. Each from the module's own waiting rule, only when allowed. */
function hm_waiting(PDO $db, array $u): array {
    $L = [];
    $try = function (string $key, string $lib, callable $f) use ($u, &$L) {
        if (!module_allowed($u, $key)) return;
        try { require_once WN_ROOT . '/lib/' . $lib . '.php'; foreach ($f() as $l) $L[] = $l; } catch (Throwable $e) { error_log('home waiting ' . $key . ': ' . $e->getMessage()); }
    };
    $try('leave', 'leave', fn() => [['Leave to decide', lv_waiting($db, $u), 'modules/leave/index.php']]);
    $try('attendance', 'att_requests', function () use ($db, $u) { $w = atr_waiting($db, $u); return [['Attendance corrections', count($w['mine_mgr']) + count($w['hr']), 'modules/attendance/requests.php']]; });
    $try('claims', 'claims', function () use ($db, $u) { $w = ec_waiting($db, $u); return [['Expense claims to act on', $w['audit'] + $w['approve'] + $w['pay'], 'modules/claims/index.php'], ['Expense claims returned to you', $w['returned'], 'modules/claims/index.php']]; });
    $try('letters', 'letters', fn() => [['Letters to approve', count(let_waiting($db, $u)), 'modules/letters/index.php']]);
    $try('profile', 'profile', fn() => pf_is_hr($u) ? [['Profile updates to verify', count(pf_waiting($db, $u)), 'modules/profile/index.php?view=hr']] : []);
    $try('kyc', 'kyc', fn() => kyc_is_hr($u) ? [['KYC changes to verify', count(kyc_waiting($db, $u)), 'modules/kyc/index.php?view=hr']] : []);
    $try('insurance', 'insurance', fn() => [['Insurance requests', count(ins_requests_waiting($db, $u)), 'modules/insurance/index.php']]);
    $try('training', 'training', fn() => [['Training requests', count(tr_req_waiting($db, $u)), 'modules/training/index.php']]);
    $try('conduct', 'conduct', function () use ($db, $u) { $w = dc_waiting($db, $u); return [['Conduct cases to act on', $w['hr'] + $w['approve'] + $w['appeal'], 'modules/conduct/index.php'], ['Conduct letters to answer', $w['reply'], 'modules/conduct/index.php'], ['Check-ins to give', $w['checkins'], 'modules/conduct/index.php']]; });
    $try('vendorbills', 'vendorbills', function () use ($db, $u) { $w = vb_waiting($db, vb_ctx($db, $u)); return [['Vendor bills to act on', $w['check'] + $w['approve'] + $w['pay'], 'modules/vendorbills/index.php'], ['Vendor bills returned to you', $w['returned'], 'modules/vendorbills/index.php']]; });
    $try('purchase', 'purchase', function () use ($db, $u) { $w = pur_waiting($db, $u); return [['Purchase approvals', count($w['reqs']) + count($w['orders']) + count($w['vendors']), 'modules/purchase/index.php']]; });
    $try('contracts', 'contracts', fn() => [['Agreements to approve', count(ct_waiting($db, $u)), 'modules/contracts/index.php']]);
    $try('tasks', 'tasks', function () use ($db, $u) { $l = tk_lists($db, $u); return [['Tasks to approve', count($l['approve']), 'modules/tasks/index.php?view=given']]; });
    return hm_waiting_clean($L);
}

function hm_payload(PDO $db, array $u, string $today): array {
    $md = md_payload($db, $u, $today);
    $tasks = [];
    if (module_allowed($u, 'tasks')) {
        $s = $db->prepare('SELECT id, title, prio, due_date, due_time, review, done_at, removed_at, owner_id FROM task WHERE assignee_id = ? AND done_at IS NULL AND removed_at IS NULL ORDER BY id LIMIT 300');
        try { $s->execute([(int)$u['id']]); $tasks = hm_task_pick($s->fetchAll(), $today); } catch (Throwable $e) { $tasks = []; }
    }
    $open = count(array_filter($md['requests'], fn($r) => (($r['stage']['state'] ?? '') === 'open')));
    return ['today' => $md['today'], 'code' => $md['code'], 'tasks' => $tasks, 'waiting' => hm_waiting($db, $u), 'open' => $open, 'notices' => $md['notices'],
        'punch' => module_allowed($u, 'attendance') && $md['code']];
}
