<?php
// Email & 2-step rules (lib/mail.php, lib/two_step.php) — synthetic data only.
require_once WN_ROOT . '/lib/two_step.php';
t('ML01 addresses: valid, no header injection, masked', mail_valid('hr@example.com') && !mail_valid("a@example.com\r\nBcc: x@example.com") && !mail_valid('a@b') && mail_mask('neha@example.com') === 'n•••@example.com');
t('ML02 cc list tidied and checked', mail_cc_list('A@example.com, bad, a@example.com;b@example.com') === ['a@example.com', 'b@example.com']);
t('ML03 service order: first, fallback, only what is set up', mail_order_for(['primary' => 'google', 'fallback' => true], ['google' => true, 'smtp' => true]) === ['google', 'smtp']
    && mail_order_for(['primary' => 'smtp', 'fallback' => false], ['google' => true, 'smtp' => true]) === ['smtp'] && mail_order_for(['primary' => 'google', 'fallback' => true], ['google' => false, 'smtp' => true]) === ['smtp']
    && mail_order_for(['primary' => 'smtp', 'fallback' => true], ['google' => false, 'smtp' => false]) === []);
t('ML04 retry waits double up to 160 minutes', mail_backoff(1) === 300 && mail_backoff(3) === 1200 && mail_backoff(9) === 9600);
$raw = mail_mime('hr@example.com', 'TEST Co', ['to' => 'neha@example.com', 'subject' => "Hello\r\nBcc: evil@example.com", 'text' => 'Hi', 'html' => '<p>Hi</p>', 'cc' => 'x@example.com', 'auto' => true], 'seed');
t('ML05 message: headers cannot be split; text + HTML; cc; auto-submitted', !preg_match('/^Bcc:/m', $raw) && str_contains($raw, 'multipart/alternative') && str_contains($raw, 'Cc: <x@example.com>') && str_contains($raw, 'Auto-Submitted'));
$bad = false; try { mail_mime('hr@example.com', 'x', ['to' => 'nope', 'subject' => 's', 'text' => 't']); } catch (InvalidArgumentException $e) { $bad = true; }
t('ML06 a bad recipient is refused', $bad);
t('ML07 HTML version escapes text and links https only', str_contains(mail_html("a <b>\n\nsee https://example.com/x", 'H', 'TEST Co'), '&lt;b&gt;') && str_contains(mail_html('https://example.com/x', '', 'T'), '<a href="https://example.com/x">'));
$cfgWas = $GLOBALS['WN_CFG']; $GLOBALS['WN_CFG'] = (is_array($cfgWas) ? $cfgWas : []) + ['mail_from' => 'hr@example.com'];
$sent = []; $res = mail_send_now(['to' => 'neha@example.com', 'subject' => 'S', 'text' => 'T'], ['google' => function () { throw new RuntimeException('down'); }, 'smtp' => function ($f, $to, $r) use (&$sent) { $sent[] = $to; return 'ok-1'; }]);
$GLOBALS['WN_CFG'] = $cfgWas;
t('ML08 falls back to the next service and reports the first failure', $res['service'] === 'smtp' && $res['id'] === 'ok-1' && $sent === ['neha@example.com'] && str_contains($res['errors'][0], 'down'));
$k = str_repeat('k', 64); $ph = 'hash-1'; $now = 1_800_000_000;
$c = tfa_trust_sign(7, $ph, $now - 86400, str_repeat('a', 16), $k);
t('TF01 trusted browser: right login and password, within the days', tfa_trust_ok($c, 7, $ph, $k, 30, $now) && !tfa_trust_ok($c, 8, $ph, $k, 30, $now) && !tfa_trust_ok($c, 7, 'hash-2', $k, 30, $now)
    && !tfa_trust_ok($c, 7, $ph, $k, 30, $now + 31 * 86400) && !tfa_trust_ok($c, 7, $ph, $k, 0, $now) && !tfa_trust_ok($c . 'x', 7, $ph, $k, 30, $now));
t('TF02 decision order', tfa_decide_from(true, true, true, false, false, true) === ['no', 'emergency'] && tfa_decide_from(false, false, true, false, false, true) === ['no', 'off']
    && tfa_decide_from(false, true, false, false, false, true) === ['no', 'nomail'] && tfa_decide_from(false, true, true, true, false, true) === ['no', 'login']
    && tfa_decide_from(false, true, true, false, true, true) === ['no', 'trusted'] && tfa_decide_from(false, true, true, false, false, false) === ['noaddr', 'noaddr']
    && tfa_decide_from(false, true, true, false, false, true) === ['code', 'code']);
t('TF03 code hash bound to the challenge and the login', tfa_code_hash(1, 7, '123456', $k) !== tfa_code_hash(2, 7, '123456', $k) && tfa_code_hash(1, 7, '123456', $k) !== tfa_code_hash(1, 8, '123456', $k));
$s = mail_settings_check(['primary' => 'google', 'trust_days' => '400', 'tfa' => '1']);
t('TF04 settings checked', $s === ['primary' => 'google', 'fallback' => false, 'tfa' => true, 'trust_days' => 90]);
