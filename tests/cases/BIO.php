<?php
// Biometric machines rules (lib/biometric.php) — synthetic data only.
require_once WN_ROOT . '/lib/biometric.php';
require_once WN_ROOT . '/lib/attendance.php';
$tz = new DateTimeZone('Asia/Kolkata');
t('BIO01 punch times: local, ISO with offset / Z, day-first, Unix; nonsense refused', bio_when('2026-11-19 09:31', $tz) === '2026-11-19 09:31:00' && bio_when('2026-11-19T04:01:05Z', $tz) === '2026-11-19 09:31:05'
    && bio_when('19/11/2026 09:31', $tz) === '2026-11-19 09:31:00' && bio_when('2026-11-19T09:31:05+05:30', $tz) === '2026-11-19 09:31:05' && bio_when('1763524865', new DateTimeZone('UTC')) === '2025-11-19 04:01:05'
    && bio_when('2026-02-30 09:00', $tz) === null && bio_when('soon', $tz) === null);
$src = ['id' => 1, 'code_prefix' => 'TST', 'code_map' => json_encode(['7' => 'TSTX7']), 'serial' => 'SN1', 'allowed_ips' => "192.0.2.0/24\n2001:db8::1"];
t('BIO02 machine ID → code: the source\'s line, else prefix + ID (not doubled)', bio_code($src, '7') === 'TSTX7' && bio_code($src, '12') === 'TST12' && bio_code($src, 'tst12') === 'TST12' && bio_code($src, '') === '');
t('BIO03 allowed addresses: IPv4 range, IPv6 exact; none set = any', bio_ip_ok($src, '192.0.2.77') && !bio_ip_ok($src, '198.51.100.1') && bio_ip_ok($src, '2001:db8::1') && bio_ip_ok(['allowed_ips' => ''], '203.0.113.9') && bio_parse_ips('bad, 192.0.2.1/40, 10.0.0.1') === ['10.0.0.1']);
t('BIO04 ADMS attendance lines read; key bodies as JSON or lines', bio_attlog("12\t2026-11-19 09:31:05\t0\t1\n\n\tx\n13\t2026-11-19 09:32:00") === [['code' => '12', 'time' => '2026-11-19 09:31:05'], ['code' => '13', 'time' => '2026-11-19 09:32:00']]
    && count(bio_parse_body('{"punches":[{"code":"1","time":"x"}]}')) === 1 && count(bio_parse_body("code,time\nA1,2026-11-19 09:00")) === 1 && bio_parse_body('nope', 'application/json') === null && bio_parse_body('') === null);
$now = new DateTimeImmutable('2026-11-19 10:00:00', $tz);
[$ok, $rej] = bio_check_rows($src, [['code' => '12', 'time' => '2026-11-19 09:00'], ['code' => '12', 'time' => '2026-08-01 09:00'], ['code' => '12', 'time' => '2026-11-19 10:30'], ['code' => '', 'time' => '2026-11-19 09:00'], ['code' => '12', 'time' => 'x'], 'junk'], $now, $tz);
t('BIO05 rows checked: too old, in the future, no code, bad time, junk refused with the reason', count($ok) === 1 && $ok[0][0] === 'TST12' && count($rej) === 5 && str_contains($rej[0][1], 'older') && str_contains($rej[1][1], 'future'));
$m = bio_merge_day([], ['09:02:00', '13:00:00', '18:10:00'], '2026-11-19', 'TEST gate');
t('BIO06 machine-only day: first is in, last is out', count($m) === 2 && $m[0]['type'] === 'IN' && $m[0]['time'] === '09:02:00' && $m[1]['type'] === 'OUT' && $m[1]['time'] === '18:10:00' && $m[0]['selfie_id'] === '');
$app = [['day' => '2026-11-19', 'time' => '09:20:00', 'type' => 'IN', 'no' => 1, 'selfie_id' => 'a'], ['day' => '2026-11-19', 'time' => '17:00:00', 'type' => 'OUT', 'no' => 2, 'selfie_id' => 'b']];
$m2 = bio_merge_day($app, ['09:05:00', '16:00:00'], '2026-11-19', 'TEST gate');
t('BIO07 with phone punches: an earlier machine in replaces the in; a later phone out is kept', $m2[0]['time'] === '09:05:00' && $m2[0]['device'] === 'machine' && $m2[1]['time'] === '17:00:00' && $m2[1]['selfie_id'] === 'b');
$m3 = bio_merge_day([$app[0]], ['18:30:00'], '2026-11-19', 'TEST gate');
t('BIO08 a phone check-in with a later machine punch gets that as the check-out', count($m3) === 2 && $m3[1]['type'] === 'OUT' && $m3[1]['time'] === '18:30:00' && (int)$m3[1]['no'] === 2);
$days = att_person_days(bio_merge_day([], ['09:00:00', '18:00:00'], '2026-11-19', 'x'), [], '', '2026-11-19', '2026-11-19', '2026-11-20', ATT_DEFAULTS);
t('BIO09 the register reads machine days as worked', $days['2026-11-19']['status'] === 'Present' && $days['2026-11-19']['hours'] === 9.0);
t('BIO10 options sent to a machine ask for attendance lines only; keys hashed', str_contains(bio_adms_options('SN1'), 'TransFlag=TransData AttLog') && strlen(bio_key_hash('k')) === 64 && str_starts_with(bio_key_new(), 'wnbio_'));
