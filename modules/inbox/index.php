<?php
/**
 * Inbox — the login's own company mail (Zoho Mail, Gmail), Zoho Cliq and WhatsApp Business. Each service is off until
 * this login connects it on Settings. Rules in lib/inbox.php; the WhatsApp webhook is modules/inbox/wa_hook.php.
 */
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/inbox.php';

$u = module_require('inbox');
$db = pdo();
$uid = (int)$u['id'];
$isAdmin = is_superadmin($u);
ib_owner($uid);   // every connection and message on this page is this login's own
header('Cache-Control: no-store, private');

$V = in_array($_GET['v'] ?? '', ['mail', 'cliq', 'wa', 'set'], true) ? (string)$_GET['v'] : '';
$flash = function (string $m, bool $ok = true) { flash($m, $ok ? 'ok' : 'err'); };
$go = function (string $q) { redirect('index.php' . ($q !== '' ? '?' . $q : '')); };

/* ---- OAuth callbacks ---- */
if (isset($_GET['cb'])) {
    $svc = (string)$_GET['cb'];
    $st = $_SESSION['ib_state'] ?? null;
    unset($_SESSION['ib_state']);
    try {
        if (!isset(IB_SVCS[$svc]) || $svc === 'wa') throw new RuntimeException('Unknown service');
        if (!is_array($st) || ($st[0] ?? '') !== $svc || !hash_equals((string)($st[1] ?? ''), (string)($_GET['state'] ?? '')) || time() - (int)($st[2] ?? 0) > 900)
            throw new RuntimeException('The sign-in did not match this page — press Connect again');
        if (isset($_GET['error'])) throw new RuntimeException('Not allowed in the account: ' . mb_substr((string)$_GET['error'], 0, 60));
        ib_oauth_finish($db, $svc, (string)($_GET['code'] ?? ''), $uid);
        $flash(IB_SVCS[$svc] . ' connected.');
        $go($svc === 'google' ? 'v=mail&src=google' : 'v=mail&src=zoho');
    } catch (Throwable $e) {
        if (isset(IB_SVCS[$svc])) ib_err($db, $svc, $e->getMessage());
        $flash($e->getMessage(), false); $go('v=set');
    }
}

/* ---- Connect (a plain link, not a form: the page's CSP form-action 'self' makes the browser refuse a form's redirect to
   accounts.zoho / accounts.google — that is why Connect did nothing, 8 Nov 2026). The link carries a key made from the
   session's CSRF token (never the token itself). ---- */
if (isset($_GET['connect'])) {
    $svc = (string)$_GET['connect'];
    try {
        if (!in_array($svc, ['zoho', 'google'], true)) throw new RuntimeException('Unknown service');
        if (!hash_equals(ib_connect_key(csrf_token(), $svc), (string)($_GET['k'] ?? ''))) throw new RuntimeException('The page was old — press Connect again');
        $c = ib_conn($db, $svc);
        if ($c['client_id'] === '' || !$c['has_box']) throw new RuntimeException('Save the client ID and secret first');
        $state = bin2hex(random_bytes(16));
        $_SESSION['ib_state'] = [$svc, $state, time()];
        header('Location: ' . ib_auth_url($c, $svc, $state)); exit;
    } catch (Throwable $e) { $flash($e->getMessage(), false); $go('v=set'); }
}

/* ---- live check (WhatsApp) ---- */
if (isset($_GET['fp'])) { header('Content-Type: application/json'); echo json_encode(['fp' => ib_wa_fp($db)]); exit; }

/* ---- actions ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ajax = ($_SERVER['HTTP_X_WN_AJAX'] ?? '') === '1';
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $back = (string)($_POST['back'] ?? 'v=set');
    if (!preg_match('/^[A-Za-z0-9=&_.%-]{0,300}$/', $back)) $back = '';
    try {
        switch ($a) {
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                $new = ib_settings_check($_POST);
                $st = module_states()['inbox'] ?? ['roles' => module_roles('inbox')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['inbox', 1, implode(',', $st['roles'] ?: module_roles('inbox')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'inbox'); $flash('Settings saved.'); break;
            case 'save_zoho':
                $dc = (string)($_POST['dc'] ?? ib_settings()['zoho_dc']); if (!isset(IB_ZOHO_DC[$dc])) $dc = ib_settings()['zoho_dc'];
                $cid = trim((string)($_POST['client_id'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9._-]{8,120}$/', $cid)) throw new RuntimeException('Client ID looks wrong — copy it from api-console.zoho');
                ib_put($db, 'zoho', ['client_id' => $cid, 'dc' => $dc], ['secret' => trim((string)($_POST['secret'] ?? ''))], [], $uid);
                ib_log($db, 'zoho', 'settings', $uid); $flash('Zoho details saved.'); break;
            case 'save_google':
                $cid = trim((string)($_POST['client_id'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9._-]{8,190}$/', $cid)) throw new RuntimeException('Client ID looks wrong — copy it from Google Cloud → Credentials');
                ib_put($db, 'google', ['client_id' => $cid], ['secret' => trim((string)($_POST['secret'] ?? ''))], [], $uid);
                ib_log($db, 'google', 'settings', $uid); $flash('Google details saved.'); break;
            case 'save_wa':
                $app = trim((string)($_POST['client_id'] ?? '')); $cfgId = trim((string)($_POST['config_id'] ?? ''));
                if (!preg_match('/^\d{5,30}$/', $app)) throw new RuntimeException('Meta app ID is a number — copy it from the app dashboard');
                if ($cfgId !== '' && !preg_match('/^\d{5,30}$/', $cfgId)) throw new RuntimeException('Configuration ID is a number — Facebook Login for Business → Configurations');
                $S = ib_secret($db, 'wa');
                $sec = ['app_secret' => trim((string)($_POST['secret'] ?? ''))];
                if (($S['verify'] ?? '') === '') { $sec['verify'] = ib_new_token(); $_SESSION['ib_verify_once'] = $sec['verify']; }
                ib_put($db, 'wa', ['client_id' => $app], $sec, ['config_id' => $cfgId], $uid);
                ib_log($db, 'wa', 'settings', $uid); $flash('WhatsApp app details saved.'); break;
            case 'new_verify':
                $vt = ib_new_token(); $_SESSION['ib_verify_once'] = $vt;
                ib_put($db, 'wa', [], ['verify' => $vt], [], $uid);
                ib_log($db, 'wa', 'new_verify', $uid); $flash('New verify token made — put it in the Meta app webhook again.'); break;
            case 'connect':
                $svc = (string)($_POST['svc'] ?? '');
                if (!in_array($svc, ['zoho', 'google'], true)) throw new RuntimeException('Unknown service');
                $c = ib_conn($db, $svc);
                if ($c['client_id'] === '' || !$c['has_box']) throw new RuntimeException('Save the client ID and secret first');
                $state = bin2hex(random_bytes(16));
                $_SESSION['ib_state'] = [$svc, $state, time()];
                header('Location: ' . ib_auth_url($c, $svc, $state)); exit;
            case 'disconnect':
                $svc = (string)($_POST['svc'] ?? '');
                if (!isset(IB_SVCS[$svc])) throw new RuntimeException('Unknown service');
                ib_disconnect($db, $svc, $uid); $flash(IB_SVCS[$svc] . ' disconnected. Nothing in the account was changed.'); break;
            case 'wa_token':
                $tok = trim((string)($_POST['token'] ?? ''));
                if (strlen($tok) < 40) throw new RuntimeException('Paste the full access token');
                $sync = ib_wa_link($db, $tok, trim((string)($_POST['waba'] ?? '')), trim((string)($_POST['phone'] ?? '')), $uid);
                $flash('WhatsApp connected.' . (in_array('asked', $sync, true) ? ' Meta was asked to share contacts and the last 180 days of chats.' : '')); break;
            case 'wa_es':
                $sync = ib_wa_finish($db, (string)($_POST['code'] ?? ''), (string)($_POST['waba'] ?? ''), (string)($_POST['phone'] ?? ''), $uid);
                $flash('WhatsApp connected.' . (in_array('asked', $sync, true) ? ' Meta was asked to share contacts and the last 180 days of chats.' : ''));
                if ($ajax) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
                break;
            case 'mail_reply':
                $src = (string)($_POST['src'] ?? ''); $text = trim((string)($_POST['text'] ?? ''));
                if ($text === '') throw new RuntimeException('Type the reply');
                if ($src === 'zoho') {
                    $to = ib_addr((string)($_POST['to'] ?? ''));
                    if ($to === '') throw new RuntimeException('No address to reply to');
                    ib_zoho_reply($db, (string)($_POST['id'] ?? ''), $to, (string)($_POST['subject'] ?? ''), $text, $uid);
                } elseif ($src === 'google') ib_gmail_reply($db, (string)($_POST['id'] ?? ''), $text, $uid);
                else throw new RuntimeException('Unknown mailbox');
                $flash('Reply sent.'); break;
            case 'cliq_post':
                $text = trim((string)($_POST['text'] ?? ''));
                if ($text === '') throw new RuntimeException('Type a message');
                ib_cliq_post($db, (string)($_POST['chat'] ?? ''), mb_substr($text, 0, 4000), $uid); $flash('Sent.'); break;
            case 'wa_send':
                ib_wa_send($db, (string)($_POST['peer'] ?? ''), (string)($_POST['text'] ?? ''), $uid); $flash('Sent.'); break;
            default: throw new RuntimeException('Unknown action');
        }
    } catch (Throwable $e) {
        if ($ajax) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => $e->getMessage()]); exit; }
        $flash($e->getMessage(), false);
    }
    $go($back);
}

/* ---- state ---- */
$Z = ib_conn($db, 'zoho'); $G = ib_conn($db, 'google'); $W = ib_conn($db, 'wa');
if ($V === '') $V = $Z['connected'] || $G['connected'] ? 'mail' : ($W['connected'] ? 'wa' : 'set');
$csrf = csrf_token();
$t = fn($ts) => $ts > 0 ? (date('Y-m-d', $ts) === date('Y-m-d') ? date('H:i', $ts) : date('j M', $ts)) : '';

/* Settings view carries the Facebook SDK for Embedded Signup: widen the policy for this view only. */
if ($V === 'set' && $W['client_id'] !== '' && ($W['info']['config_id'] ?? '') !== '' && !headers_sent()) {
    header('Content-Security-Policy: ' . str_replace(
        ["script-src 'self' 'unsafe-inline'", "connect-src 'self'", "frame-src 'self'", "img-src 'self'"],
        ["script-src 'self' 'unsafe-inline' https://connect.facebook.net", "connect-src 'self' https://*.facebook.com https://graph.facebook.com",
         "frame-src 'self' https://*.facebook.com", "img-src 'self' https://*.facebook.com https://*.fbcdn.net"], WN_CSP));
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
}

$h = fn($s) => h($s);
$tab = function (string $v, string $label, string $icon, int $n = 0) use ($V, $h) {
    return '<a class="ib-tab' . ($V === $v ? ' on' : '') . '" href="index.php?v=' . $v . '">' . $h($label)
         . ($n > 0 ? ' <b class="ib-n">' . $n . '</b>' : '') . '</a>';
};
$waUnread = 0;
try { $q = $db->prepare("SELECT COUNT(*) FROM ib_wa_msg WHERE uid = ? AND dir = 'in' AND seen = 0"); $q->execute([$uid]); $waUnread = (int)$q->fetchColumn(); } catch (Throwable $e) {}

ui_head('Inbox', $u, 'inbox');
?>
<style>
.ib-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:10px}

.ib-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}
.ib-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border:1px solid var(--p-line,#E1E4EA);border-radius:999px;background:var(--p-surface,#fff);color:var(--p-ink,#101A33);text-decoration:none;font-weight:600;font-size:14px}
.ib-tab.on{background:var(--p-navy,#13265C);border-color:var(--p-navy,#13265C);color:var(--p-on-navy,#fff)}
.ib-n{background:#F26B1D;color:#fff;border-radius:999px;padding:0 7px;font-size:12px}
.ib-flash{padding:10px 14px;border-radius:10px;margin:8px 0;border:1px solid var(--p-ok,#1E7A4C);background:var(--p-ok-bg,#E8F5EE)}
.ib-flash.bad{border-color:var(--p-bad,#B42318);background:var(--p-bad-bg,#FDECEA)}
.ib-split{display:grid;grid-template-columns:minmax(260px,380px) 1fr;gap:14px;min-height:calc(100vh - 160px)}
@media (max-width:860px){.ib-split{grid-template-columns:1fr}.ib-split.has-open .ib-list{display:none}}
.ib-card{background:var(--p-surface,#fff);border:1px solid var(--p-line,#E1E4EA);border-radius:12px;overflow:hidden}
.ib-list{display:flex;flex-direction:column;max-height:calc(100vh - 150px);overflow:auto}
.ib-row{display:block;padding:10px 14px;border-bottom:1px solid var(--p-line,#E1E4EA);color:var(--p-ink,#101A33);text-decoration:none}
.ib-row:hover{background:var(--p-navy-tint,#EEF1F8)}.ib-row.on{background:var(--p-navy-tint,#EEF1F8);box-shadow:inset 3px 0 var(--p-navy,#13265C)}
.ib-row .t{display:flex;justify-content:space-between;gap:8px;font-weight:600;font-size:14px}
.ib-row .t span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ib-row .t time{font-weight:400;color:var(--p-muted,#5B6478);font-size:12px;flex:none}
.ib-row .s{font-size:13px;color:var(--p-ink-2,#36405A);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ib-row.unread .t span:first-child::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:#F26B1D;margin-right:6px}
.ib-read{display:flex;flex-direction:column;min-height:420px}
.ib-read header{padding:12px 16px;border-bottom:1px solid var(--p-line,#E1E4EA)}
.ib-read header h2{margin:0 0 4px;font-size:17px}.ib-read header p{margin:0;font-size:13px;color:var(--p-muted,#5B6478)}
.ib-read iframe{flex:1;border:0;width:100%;min-height:360px;background:#fff}
.ib-compose{display:flex;gap:8px;padding:10px;border-top:1px solid var(--p-line,#E1E4EA);align-items:flex-end}
.ib-compose textarea{flex:1;min-height:44px;max-height:200px;resize:vertical;border:1px solid var(--p-line-2,#CDD2DC);border-radius:10px;padding:9px 11px;font:inherit;background:var(--p-surface,#fff);color:var(--p-ink,#101A33)}
.ib-btn{display:inline-block;text-decoration:none;border:0;background:var(--p-navy,#13265C);color:var(--p-on-navy,#fff);border-radius:999px;padding:9px 16px;font-weight:600;cursor:pointer;font:inherit}
.ib-btn.ghost{background:transparent;color:var(--p-navy,#13265C);border:1px solid var(--p-line-2,#CDD2DC)}
.ib-empty{padding:40px 20px;text-align:center;color:var(--p-muted,#5B6478)}
.ib-src{display:flex;gap:6px;padding:10px;border-bottom:1px solid var(--p-line,#E1E4EA)}
.ib-chat{flex:1;overflow:auto;padding:14px;display:flex;flex-direction:column;gap:6px;background:var(--p-ground,#F3F4F7);max-height:calc(100vh - 290px)}
.ib-b{max-width:72%;padding:8px 11px;border-radius:12px;font-size:14px;white-space:pre-wrap;word-wrap:break-word;background:var(--p-surface,#fff);border:1px solid var(--p-line,#E1E4EA);align-self:flex-start}
.ib-b.out{align-self:flex-end;background:var(--p-navy,#13265C);color:var(--p-on-navy,#fff);border-color:var(--p-navy,#13265C)}
.ib-b small{display:block;font-size:11px;opacity:.7;margin-top:3px}
.ib-note{padding:9px 14px;font-size:13px;color:var(--p-ink-2,#36405A);background:var(--p-warn-bg,#FFF6E5);border-top:1px solid var(--p-line,#E1E4EA)}
.ib-set{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}
.ib-set .ib-card{padding:16px}.ib-set h2{margin:0 0 4px;font-size:17px;display:flex;align-items:center;gap:8px}
.ib-set label{display:block;font-size:13px;font-weight:600;margin:10px 0 4px}
.ib-set input,.ib-set select{width:100%;box-sizing:border-box;height:38px;border:1px solid var(--p-line-2,#CDD2DC);border-radius:8px;padding:0 10px;font:inherit;background:var(--p-surface,#fff);color:var(--p-ink,#101A33)}
.ib-set .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.ib-st{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:2px 9px;border-radius:999px;background:var(--p-line,#E1E4EA)}
.ib-st.ok{background:var(--p-ok-bg,#E8F5EE);color:var(--p-ok,#1E7A4C)}
.ib-copy{font-family:var(--p-mono,monospace);font-size:12px;background:var(--p-ground,#F3F4F7);border:1px solid var(--p-line,#E1E4EA);border-radius:8px;padding:6px 8px;word-break:break-all;margin:4px 0}
.ib-err{color:var(--p-bad,#B42318);font-size:13px;margin-top:8px}
details.ib-more{margin-top:12px}details.ib-more summary{cursor:pointer;font-weight:600;font-size:13px}
</style>
<div class="ib-head">
  <?php if (is_stage()): ?><p class="note">Staging copy: reading works, nothing is sent from here.</p><?php endif; ?>
  <nav class="ib-tabs">
    <?= $tab('mail', 'Mail', 'envelope') ?><?= $tab('cliq', 'Zoho Cliq', 'chat-square-text') ?><?= $tab('wa', 'WhatsApp', 'whatsapp', $waUnread) ?><?= $tab('set', 'Settings', 'gear') ?>
  </nav>
</div>

<?php
$notOn = function (string $what) use ($h) {
    echo '<div class="ib-card ib-empty"><p>' . $h($what) . ' is not connected yet.</p><a class="ib-btn" href="index.php?v=set">Open Settings</a></div>';
};

/* ================= Mail ================= */
if ($V === 'mail'):
    $src = (string)($_GET['src'] ?? '');
    if (!in_array($src, ['zoho', 'google'], true) || !($src === 'zoho' ? $Z['connected'] : $G['connected'])) $src = $Z['connected'] ? 'zoho' : ($G['connected'] ? 'google' : '');
    if ($src === '') { $notOn('Company mail'); }
    else {
        $id = (string)($_GET['m'] ?? ''); $fd = (string)($_GET['f'] ?? '');
        $list = []; $msg = null; $err = '';
        try { $list = $src === 'zoho' ? ib_zoho_list($db) : ib_gmail_list($db); } catch (Throwable $e) { $err = $e->getMessage(); ib_err($db, $src, $err); }
        if ($id !== '') { try { $msg = $src === 'zoho' ? ib_zoho_read($db, $fd, $id) : ib_gmail_read($db, $id); } catch (Throwable $e) { $err = $e->getMessage(); } }
        $acct = $src === 'zoho' ? $Z : $G; ?>
<div class="ib-split<?= $msg ? ' has-open' : '' ?>">
  <section class="ib-card ib-list" aria-label="Messages">
    <div class="ib-src">
      <?php if ($Z['connected']): ?><a class="ib-tab<?= $src === 'zoho' ? ' on' : '' ?>" href="index.php?v=mail&amp;src=zoho">Zoho</a><?php endif; ?>
      <?php if ($G['connected']): ?><a class="ib-tab<?= $src === 'google' ? ' on' : '' ?>" href="index.php?v=mail&amp;src=google">Gmail</a><?php endif; ?>
      <span style="margin-left:auto;align-self:center;font-size:12px;color:var(--p-muted)"><?= $h($acct['info']['email'] ?? '') ?></span>
    </div>
    <?php if ($err !== ''): ?><div class="ib-err" style="padding:10px 14px"><?= $h($err) ?></div><?php endif; ?>
    <?php if (!$list && $err === ''): ?><div class="ib-empty">Nothing in the inbox.</div><?php endif; ?>
    <?php foreach ($list as $m): ?>
      <a class="ib-row<?= $m['unread'] ? ' unread' : '' ?><?= $m['id'] === $id ? ' on' : '' ?>" href="index.php?v=mail&amp;src=<?= $src ?>&amp;m=<?= rawurlencode($m['id']) ?>&amp;f=<?= rawurlencode($m['folder']) ?>">
        <span class="t"><span><?= $h($m['from'] ?: $m['addr']) ?></span><time><?= $t($m['at']) ?></time></span>
        <span class="s"><b><?= $h($m['subject'] ?: '(no subject)') ?></b> · <?= $h($m['summary']) ?></span>
      </a>
    <?php endforeach; ?>
  </section>
  <section class="ib-card ib-read" aria-label="Message">
    <?php if (!$msg): ?><div class="ib-empty"><p>Pick a message.</p></div>
    <?php else: ?>
      <header><a class="ib-btn ghost" href="index.php?v=mail&amp;src=<?= $src ?>" style="float:right;padding:5px 12px">Back</a>
        <h2><?= $h($msg['subject'] ?: '(no subject)') ?></h2><p><?= $h($msg['from']) ?><?= $msg['at'] ? ' · ' . $h(date('j M Y, H:i', $msg['at'])) : '' ?></p></header>
      <iframe title="Message" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="<?= $h(ib_mail_doc($msg['html'])) ?>"></iframe>
      <form class="ib-compose" method="post">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="mail_reply">
        <input type="hidden" name="src" value="<?= $src ?>"><input type="hidden" name="id" value="<?= $h($msg['id']) ?>">
        <input type="hidden" name="to" value="<?= $h($msg['from']) ?>"><input type="hidden" name="subject" value="<?= $h($msg['subject']) ?>">
        <input type="hidden" name="back" value="<?= $h('v=mail&src=' . $src . '&m=' . rawurlencode($msg['id']) . '&f=' . rawurlencode($msg['folder'])) ?>">
        <textarea name="text" placeholder="Reply to <?= $h(ib_addr($msg['from']) ?: $msg['from']) ?>" required></textarea>
        <button class="ib-btn">Send</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php }

/* ================= Cliq ================= */
elseif ($V === 'cliq'):
    if (!$Z['connected']) { $notOn('Zoho Cliq'); }
    else {
        $chat = (string)($_GET['c'] ?? ''); $chats = []; $msgs = []; $err = ''; $name = '';
        try { $chats = ib_cliq_chats($db); } catch (Throwable $e) { $err = $e->getMessage(); }
        foreach ($chats as $c) if ($c['id'] === $chat) $name = $c['name'];
        if ($chat !== '') { try { $msgs = ib_cliq_messages($db, $chat); } catch (Throwable $e) { $err = $e->getMessage(); } } ?>
<div class="ib-split<?= $chat !== '' ? ' has-open' : '' ?>">
  <section class="ib-card ib-list" aria-label="Chats">
    <?php if ($err !== ''): ?><div class="ib-err" style="padding:10px 14px"><?= $h($err) ?></div><?php endif; ?>
    <?php if (!$chats && $err === ''): ?><div class="ib-empty">No chats.</div><?php endif; ?>
    <?php foreach ($chats as $c): ?>
      <a class="ib-row<?= $c['id'] === $chat ? ' on' : '' ?>" href="index.php?v=cliq&amp;c=<?= rawurlencode($c['id']) ?>">
        <span class="t"><span><?= $h($c['name']) ?></span><time><?= $t($c['at']) ?></time></span><span class="s"><?= $h($c['last']) ?></span></a>
    <?php endforeach; ?>
  </section>
  <section class="ib-card ib-read" aria-label="Chat">
    <?php if ($chat === ''): ?><div class="ib-empty"><p>Pick a chat.</p></div>
    <?php else: ?>
      <header><a class="ib-btn ghost" href="index.php?v=cliq" style="float:right;padding:5px 12px">Back</a><h2><?= $h($name ?: 'Chat') ?></h2></header>
      <div class="ib-chat" id="ibChat"><?php foreach ($msgs as $m): ?>
        <div class="ib-b"><b><?= $h($m['who']) ?></b><br><?= $h($m['text']) ?><small><?= $m['at'] ? $h(date('j M, H:i', $m['at'])) : '' ?></small></div>
      <?php endforeach; ?><?php if (!$msgs): ?><div class="ib-empty">No messages.</div><?php endif; ?></div>
      <form class="ib-compose" method="post">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="cliq_post"><input type="hidden" name="chat" value="<?= $h($chat) ?>">
        <input type="hidden" name="back" value="<?= $h('v=cliq&c=' . rawurlencode($chat)) ?>">
        <textarea name="text" placeholder="Message" required></textarea><button class="ib-btn">Send</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php }

/* ================= WhatsApp ================= */
elseif ($V === 'wa'):
    $chats = ib_wa_chats($db);
    if (!$W['connected'] && !$chats) { $notOn('WhatsApp Business'); }
    else {
        $peer = ib_wa_num((string)($_GET['p'] ?? '')); $thread = $peer !== '' ? ib_wa_thread($db, $peer) : [];
        $pname = ''; foreach ($chats as $c) if ($c['peer'] === $peer) $pname = $c['name'];
        $open = $thread && ib_wa_open($thread); ?>
<div class="ib-split<?= $peer !== '' ? ' has-open' : '' ?>">
  <section class="ib-card ib-list" aria-label="Chats">
    <div class="ib-src"><span style="font-size:12px;color:var(--p-muted)"><?= $h(trim(($W['info']['name'] ?? '') . ' ' . ($W['info']['number'] ?? ''))) ?></span></div>
    <?php if (!$chats): ?><div class="ib-empty">No messages yet. New ones arrive here as customers write.</div><?php endif; ?>
    <?php foreach ($chats as $c): ?>
      <a class="ib-row<?= $c['unread'] ? ' unread' : '' ?><?= $c['peer'] === $peer ? ' on' : '' ?>" href="index.php?v=wa&amp;p=<?= $h($c['peer']) ?>">
        <span class="t"><span><?= $h($c['name'] !== '' ? $c['name'] . ' · +' . $c['peer'] : '+' . $c['peer']) ?></span><time><?= $t($c['last']) ?></time></span>
        <span class="s"><?= $h($c['body']) ?></span></a>
    <?php endforeach; ?>
  </section>
  <section class="ib-card ib-read" aria-label="Chat">
    <?php if ($peer === ''): ?><div class="ib-empty"><p>Pick a chat.</p></div>
    <?php else: ?>
      <header><a class="ib-btn ghost" href="index.php?v=wa" style="float:right;padding:5px 12px">Back</a>
        <h2><?= $h($pname !== '' ? $pname : '+' . $peer) ?></h2><p>+<?= $h($peer) ?></p></header>
      <div class="ib-chat" id="ibChat"><?php foreach ($thread as $m): ?>
        <div class="ib-b<?= $m['dir'] === 'out' ? ' out' : '' ?>"><?= $h($m['body']) ?><small><?= $h(date('j M, H:i', (int)$m['ts'])) ?><?= $m['dir'] === 'out' && $m['src'] === 'app' ? ' · from the app' : '' ?><?= $m['dir'] === 'out' && $m['status'] !== '' ? ' · ' . $h($m['status']) : '' ?></small></div>
      <?php endforeach; ?></div>
      <?php if (!$W['connected']): ?><div class="ib-note">WhatsApp is not connected — replies are off.</div>
      <?php elseif (!$open): ?><div class="ib-note">More than 24 hours since this customer last wrote. Meta allows only a template now — reply from the WhatsApp Business app.</div>
      <?php else: ?>
      <form class="ib-compose" method="post">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="wa_send"><input type="hidden" name="peer" value="<?= $h($peer) ?>">
        <input type="hidden" name="back" value="<?= $h('v=wa&p=' . $peer) ?>">
        <textarea name="text" maxlength="4096" placeholder="Message" required></textarea><button class="ib-btn">Send</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<script>
(function(){var fp=null,busy=false;function tick(){if(document.hidden||busy)return;var ta=document.querySelector(".ib-compose textarea");if(ta&&ta.value.trim())return;busy=true;
fetch("index.php?fp=1",{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(fp!==null&&j.fp!==fp)location.reload();fp=j.fp}).catch(function(){}).then(function(){busy=false})}
tick();setInterval(tick,15000);document.addEventListener("visibilitychange",tick);})();
</script>
<?php }

/* ================= Settings ================= */
else:
    $st = fn(array $c) => $c['connected'] ? '<span class="ib-st ok">Connected</span>' : '<span class="ib-st">Off</span>';
    $WS = ib_secret($db, 'wa');
    $vOnce = (string)($_SESSION['ib_verify_once'] ?? ''); unset($_SESSION['ib_verify_once']); ?>
<div class="ib-set">
  <?php if ($isAdmin): $IS = ib_settings(); ?>
  <section class="ib-card">
    <h2>Module settings</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="settings">
      <label for="scc">WhatsApp: country calling code for numbers without one</label><input id="scc" name="wa_cc" value="<?= $h($IS['wa_cc']) ?>" inputmode="numeric" maxlength="4" placeholder="blank = leave numbers as they come">
      <label for="sdc">Default Zoho region</label><select id="sdc" name="zoho_dc"><?php foreach (IB_ZOHO_DC as $k => $l): ?><option value="<?= $h($k) ?>"<?= $IS['zoho_dc'] === $k ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
      <div class="row"><button class="ib-btn ghost">Save</button></div>
    </form>
    <p class="ib-more">Who may use the Inbox is set on Admin → Modules. Every login connects only its own accounts; nobody sees another login's mail or chats.</p>
  </section>
  <?php endif; ?>
  <section class="ib-card">
    <h2>Zoho Mail &amp; Cliq <?= $st($Z) ?></h2>
    <?php if ($Z['connected']): ?><p><?= $h($Z['info']['email'] ?? '') ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="save_zoho">
      <label for="zdc">Zoho region</label><select id="zdc" name="dc"><?php foreach (IB_ZOHO_DC as $k => $l): ?><option value="<?= $h($k) ?>"<?= $Z['dc'] === $k ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
      <label for="zci">Client ID</label><input id="zci" name="client_id" value="<?= $h($Z['client_id']) ?>" autocomplete="off">
      <label for="zcs">Client secret</label><input id="zcs" name="secret" type="password" autocomplete="new-password" placeholder="<?= $Z['has_box'] ? 'Saved · leave blank to keep' : '' ?>">
      <div class="row"><button class="ib-btn ghost">Save</button></div>
    </form>
    <label>Redirect URI</label><div class="ib-copy"><?= $h(ib_redirect_uri('zoho')) ?></div>
    <div class="row">
      <?php if ($Z['client_id'] === '' || !$Z['has_box']): ?><button class="ib-btn" type="button" disabled>Connect Zoho</button><?php else: ?><a class="ib-btn" href="index.php?connect=zoho&amp;k=<?= $h(ib_connect_key($csrf, 'zoho')) ?>"><?= $Z['connected'] ? 'Connect again' : 'Connect Zoho' ?></a><?php endif; ?>
      <?php if ($Z['connected']): ?><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="disconnect"><input type="hidden" name="svc" value="zoho"><button class="ib-btn ghost">Disconnect</button></form><?php endif; ?>
    </div>
    <?php if ($Z['last_err'] !== ''): ?><div class="ib-err"><?= $h($Z['last_err']) ?></div><?php endif; ?>
    <details class="ib-more"><summary>Where to get these</summary><p>api-console.zoho.<your region> → Add client → Server-based Applications. Homepage: this site. Authorized redirect URI: the address above. Copy the Client ID and Client Secret here, Save, then Connect Zoho and sign in with the company mailbox.</p></details>
  </section>

  <section class="ib-card">
    <h2>Gmail <?= $st($G) ?></h2>
    <?php if ($G['connected']): ?><p><?= $h($G['info']['email'] ?? '') ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="save_google">
      <label for="gci">Client ID</label><input id="gci" name="client_id" value="<?= $h($G['client_id']) ?>" autocomplete="off">
      <label for="gcs">Client secret</label><input id="gcs" name="secret" type="password" autocomplete="new-password" placeholder="<?= $G['has_box'] ? 'Saved · leave blank to keep' : '' ?>">
      <div class="row"><button class="ib-btn ghost">Save</button></div>
    </form>
    <label>Redirect URI</label><div class="ib-copy"><?= $h(ib_redirect_uri('google')) ?></div>
    <div class="row">
      <?php if ($G['client_id'] === '' || !$G['has_box']): ?><button class="ib-btn" type="button" disabled>Connect Gmail</button><?php else: ?><a class="ib-btn" href="index.php?connect=google&amp;k=<?= $h(ib_connect_key($csrf, 'google')) ?>"><?= $G['connected'] ? 'Connect again' : 'Connect Gmail' ?></a><?php endif; ?>
      <?php if ($G['connected']): ?><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="disconnect"><input type="hidden" name="svc" value="google"><button class="ib-btn ghost">Disconnect</button></form><?php endif; ?>
    </div>
    <?php if ($G['last_err'] !== ''): ?><div class="ib-err"><?= $h($G['last_err']) ?></div><?php endif; ?>
    <details class="ib-more"><summary>Where to get these</summary><p>Google Cloud console → APIs &amp; Services: turn on the Gmail API; OAuth consent screen: Internal; Credentials → OAuth client ID → Web application with the redirect URI above. Copy the Client ID and secret here, Save, then Connect Gmail with the company account.</p></details>
  </section>

  <section class="ib-card">
    <h2>WhatsApp Business <?= $st($W) ?></h2>
    <?php if ($W['connected']): ?><p><?= $h(trim(($W['info']['name'] ?? '') . ' · ' . ($W['info']['number'] ?? ''), ' ·')) ?><?= !empty($W['info']['on_app']) ? ' · also on the Business app' : '' ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="save_wa">
      <label for="wai">Meta app ID</label><input id="wai" name="client_id" value="<?= $h($W['client_id']) ?>" inputmode="numeric" autocomplete="off">
      <label for="was">App secret</label><input id="was" name="secret" type="password" autocomplete="new-password" placeholder="<?= ($WS['app_secret'] ?? '') !== '' ? 'Saved · leave blank to keep' : '' ?>">
      <label for="wac">Embedded Signup configuration ID</label><input id="wac" name="config_id" value="<?= $h($W['info']['config_id'] ?? '') ?>" inputmode="numeric" autocomplete="off">
      <div class="row"><button class="ib-btn ghost">Save</button></div>
    </form>
    <?php if (($WS['verify'] ?? '') !== ''): ?>
      <label>Webhook callback URL</label><div class="ib-copy"><?= $h(ib_hook_url()) ?></div>
      <label>Verify token</label><div class="ib-copy"><?= $vOnce !== '' ? $h($vOnce) . '</div><p style="font-size:12px;margin:0">Shown this once — copy it into the Meta app now.</p>' : 'Saved · ends …' . $h(substr((string)$WS['verify'], -4)) . '</div>' ?>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="new_verify"><button class="ib-btn ghost">New verify token</button></form>
    <?php endif; ?>
    <div class="row">
      <?php if ($W['client_id'] !== '' && ($W['info']['config_id'] ?? '') !== '' && ($WS['app_secret'] ?? '') !== ''): ?>
        <button class="ib-btn" type="button" id="waEs" data-app="<?= $h($W['client_id']) ?>" data-cfg="<?= $h($W['info']['config_id']) ?>" data-ver="<?= $h(IB_META_VER) ?>"><?= $W['connected'] ? 'Connect again' : 'Connect my WhatsApp Business app' ?></button>
      <?php endif; ?>
      <?php if ($W['connected']): ?><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="disconnect"><input type="hidden" name="svc" value="wa"><button class="ib-btn ghost">Disconnect</button></form><?php endif; ?>
    </div>
    <div class="ib-err" id="waErr"><?= $h($W['last_err']) ?></div>
    <details class="ib-more"><summary>Where to get these</summary><p>developers.facebook.com → your Business app → add WhatsApp and Facebook Login for Business. Configurations → create one for WhatsApp Embedded Signup and copy its ID. App settings → Basic: App ID and App secret. WhatsApp → Configuration → Webhook: the callback URL and verify token above; subscribe to messages, smb_message_echoes, history, smb_app_state_sync and account_update. Then press Connect, choose “Connect your existing WhatsApp Business app”, and scan the QR code with the Business app on the phone. Chats keep working on the phone.</p></details>
    <details class="ib-more"><summary>Connect with a token instead</summary>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="wa_token">
        <label for="wat">System user access token</label><input id="wat" name="token" type="password" autocomplete="off">
        <label for="waw">WhatsApp Business account ID</label><input id="waw" name="waba" inputmode="numeric" autocomplete="off">
        <label for="wap">Phone number ID</label><input id="wap" name="phone" inputmode="numeric" autocomplete="off">
        <div class="row"><button class="ib-btn ghost">Connect</button></div>
      </form>
    </details>
  </section>
</div>
<?php if ($W['client_id'] !== '' && ($W['info']['config_id'] ?? '') !== ''): ?>
<script>
(function(){
  var b=document.getElementById("waEs"),err=document.getElementById("waErr");if(!b)return;
  var info=null,code=null,sent=false;
  function send(){if(sent||!info||!code)return;sent=true;b.disabled=true;b.textContent="Connecting…";
    var f=new FormData();f.append("a","wa_es");f.append("csrf",<?= json_encode($csrf) ?>);f.append("code",code);f.append("waba",info.waba_id||"");f.append("phone",info.phone_number_id||"");
    fetch("index.php",{method:"POST",body:f,credentials:"same-origin",headers:{"X-WN-Ajax":"1"}}).then(function(r){return r.json()}).then(function(j){if(j.ok)location.href="index.php?v=wa";else{err.textContent=j.error||"Not connected";b.disabled=false;b.textContent="Try again";sent=false}})
    .catch(function(){err.textContent="The server did not answer";b.disabled=false;sent=false})}
  window.addEventListener("message",function(ev){var host="";try{host=new URL(ev.origin).hostname}catch(e){}if(!/(^|\.)facebook\.com$/.test(host))return;
    var d;try{d=typeof ev.data==="string"?JSON.parse(ev.data):ev.data}catch(e){return}if(!d||d.type!=="WA_EMBEDDED_SIGNUP")return;
    if(/^FINISH/.test(d.event||"")){info=d.data||{};send()}else if(d.event==="CANCEL"){err.textContent="Not finished"+(d.data&&d.data.current_step?" ("+d.data.current_step+")":"")}else if(d.event==="ERROR"){err.textContent=(d.data&&d.data.error_message)||"Meta reported an error"}});
  window.fbAsyncInit=function(){FB.init({appId:b.dataset.app,autoLogAppEvents:true,xfbml:false,version:b.dataset.ver})};
  b.addEventListener("click",function(){if(!window.FB){err.textContent="Facebook did not load — check the connection and try again";return}err.textContent="";
    FB.login(function(r){if(r&&r.authResponse&&r.authResponse.code){code=r.authResponse.code;send()}else if(!info){err.textContent="Not finished"}},
      {config_id:b.dataset.cfg,response_type:"code",override_default_response_type:true,extras:{setup:{},featureType:"whatsapp_business_app_onboarding",sessionInfoVersion:"3"}})});
})();
</script>
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
<?php endif; ?>
<?php endif; ?>
<script>(function(){var c=document.getElementById("ibChat");if(c)c.scrollTop=c.scrollHeight;})();</script>
<?php ui_foot();
