<?php
// Attendance rules (lib/attendance.php) — synthetic data only.
require_once WN_ROOT . '/lib/attendance.php';
$set = ATT_DEFAULTS;
$P = fn($d, $no, $type, $t) => ['day' => $d, 'no' => $no, 'type' => $type, 'time' => $t, 'geofence' => 'inside', 'remarks' => null, 'selfie_id' => 'x', 'distance_m' => 0];
t('AT01 distance (1 km north is ~1000 m)', abs(att_distance(10.0, 20.0, 10.009, 20.0) - 1000) < 5);
$brs = [['id' => 1, 'lat' => 10.0, 'lng' => 20.0, 'radius_m' => 200], ['id' => 2, 'lat' => null, 'lng' => null, 'radius_m' => null]];
t('AT02 geofence: inside, outside, no offices', att_geofence(10.0005, 20.0, $brs)['geofence'] === 'inside' && att_geofence(10.01, 20.0, $brs)['geofence'] === 'outside' && att_geofence(1, 1, [])['geofence'] === 'none');
$tz = new DateTimeZone('UTC');
t('AT03 flexible: a 01:20 punch belongs to the day before as 25:20', att_work_day(true, new DateTimeImmutable('2026-11-10 01:20:00', $tz), 4) === ['2026-11-09', '25:20:00'] && att_work_day(false, new DateTimeImmutable('2026-11-10 01:20:00', $tz), 4)[0] === '2026-11-10');
t('AT04 times past midnight shown with (+1)', att_hm('25:20:00') === '01:20 (+1)' && att_hm('09:05:00') === '09:05');
t('AT05 punches alternate and stop at the limit', att_next([], 6) === 'IN' && att_next([$P('d', 1, 'IN', '09:00')], 6) === 'OUT' && att_next(array_fill(0, 6, $P('d', 1, 'OUT', '1')), 6) === '');
$ps = [$P('2026-11-02', 1, 'IN', '09:00:00'), $P('2026-11-02', 2, 'OUT', '13:00:00'), $P('2026-11-02', 3, 'IN', '14:00:00'), $P('2026-11-02', 4, 'OUT', '18:30:00'),
       $P('2026-11-03', 1, 'IN', '09:00:00'), $P('2026-11-03', 2, 'OUT', '13:30:00'), $P('2026-11-04', 1, 'IN', '09:00:00'), $P('2026-11-08', 1, 'IN', '10:00:00'), $P('2026-11-08', 2, 'OUT', '12:00:00')];
$days = att_person_days($ps, ['2026-11-06' => 'Test Holiday'], 'Sun', '2026-11-02', '2026-11-10', '2026-11-10', $set);
t('AT06 present with a break taken off the hours', $days['2026-11-02']['status'] === 'Present' && $days['2026-11-02']['hours'] === 8.5 && $days['2026-11-02']['away_min'] === 60);
t('AT07 half day, missed punch, absent', $days['2026-11-03']['status'] === 'Half day' && $days['2026-11-04']['status'] === 'Missed punch' && $days['2026-11-05']['status'] === 'Absent');
t('AT08 holiday, weekly off worked, today not in yet', $days['2026-11-06']['status'] === 'Holiday' && $days['2026-11-08']['status'] === 'Weekly off · worked' && $days['2026-11-10']['status'] === 'Not in yet');
$d2 = att_person_days([], [], 'Sun', '2026-11-01', '2026-11-30', '2026-11-10', $set, '2026-11-05', '2026-11-20');
t('AT09 days before joining and after the last day are left out', array_key_first($d2) === '2026-11-05' && array_key_last($d2) === '2026-11-20');
$s = att_summary($days);
t('AT10 summary counts', $s['Present'] === 1 && $s['Absent'] === 3 && $s['Holiday'] === 1 && $s['Missed punch'] === 1);
[$c, $e] = att_settings_check(['accuracy_m' => 3, 'max_punches' => 5, 'full_day_hours' => 4, 'half_day_hours' => 4, 'see_all_roles' => ['CEO', 'NOPE']], ['CEO', 'SUPERADMIN']);
t('AT11 settings checked; Super Admin always sees everyone', count($e) === 3 && in_array('SUPERADMIN', $c['see_all_roles'], true) && !in_array('NOPE', $c['see_all_roles'], true));
try { att_decode_selfie('data:image/png;base64,AAAA'); $bad = false; } catch (RuntimeException $x) { $bad = true; }
t('AT12 selfie must be a camera JPEG', $bad);
