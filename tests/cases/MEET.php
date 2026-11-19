<?php
// Video meeting rules (lib/meet.php) — synthetic data only.
require_once WN_ROOT . '/lib/meet.php';
$mc = meet_code_make();
t('MEET01 codes: made as abc-defg-hjk without i, l, o; read from a pasted code or link', preg_match('/^[a-hjkmnp-z]{3}-[a-hjkmnp-z]{4}-[a-hjkmnp-z]{3}$/', $mc) === 1
    && meet_code_parse(' ABC-DEFG-HJK ') === 'abc-defg-hjk' && meet_code_parse('https://example.com/modules/meet/index.php?r=abc-defg-hjk#minutes') === 'abc-defg-hjk' && meet_code_parse('abc-defg') === '');
$room = ['ended_at' => null, 'locked' => 0, 'open_join' => 0];
$meErr = function (callable $f): string { try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };
t('MEET02 joining: invited / rung / host / left come in; a stranger waits; open meetings let everyone in', meet_join_decision(['role' => 'member', 'state' => 'invited'], $room, 1, 12) === 'in'
    && meet_join_decision(['role' => 'host', 'state' => 'invited'], $room, 0, 12) === 'in' && meet_join_decision(null, $room, 1, 12) === 'waiting'
    && meet_join_decision(null, ['open_join' => 1] + $room, 1, 12) === 'in' && meet_join_decision(['role' => 'denied', 'state' => 'declined'], $room, 1, 12) === 'waiting');
t('MEET03 refused: ended, removed, locked (except the host), full', $meErr(fn() => meet_join_decision(null, ['ended_at' => 1] + $room, 0, 12)) !== ''
    && $meErr(fn() => meet_join_decision(['role' => 'member', 'state' => 'removed'], $room, 0, 12)) !== '' && $meErr(fn() => meet_join_decision(['role' => 'member', 'state' => 'invited'], ['locked' => 1] + $room, 0, 12)) !== ''
    && meet_join_decision(['role' => 'host', 'state' => 'left'], ['locked' => 1] + $room, 0, 12) === 'in' && $meErr(fn() => meet_join_decision(['role' => 'member', 'state' => 'invited'], $room, 12, 12)) !== ''
    && meet_join_decision(['role' => 'member', 'state' => 'in'], $room, 12, 12) === 'in');
t('MEET04 only host / co-host in the room moderate', meet_is_mod(['state' => 'in', 'role' => 'cohost']) && !meet_is_mod(['state' => 'left', 'role' => 'host']) && !meet_is_mod(['state' => 'in', 'role' => 'member']) && !meet_is_mod(null));
t('MEET05 messages: chat trimmed, reactions from the list, transcript refused while muted', meet_say_text('chat', "  TEST hello\x07 ", true) === 'TEST hello' && meet_say_text('react', '👍', true) === '👍'
    && $meErr(fn() => meet_say_text('react', 'x', true)) !== '' && $meErr(fn() => meet_say_text('cap', 'TEST', false)) !== '' && $meErr(fn() => meet_say_text('chat', '   ', true)) !== '' && $meErr(fn() => meet_say_text('zzz', 'x', true)) !== '');
t('MEET06 connection messages: offer / answer or candidates only, size capped', meet_signal_ok(['sdp' => ['type' => 'offer', 'sdp' => 'v=0']]) && meet_signal_ok(['cands' => []]) && !meet_signal_ok(['x' => 1]) && !meet_signal_ok('sdp')
    && !meet_signal_ok(['sdp' => str_repeat('a', MEET_SIGNAL_BYTES)]));
$mm = meet_minutes_auto([['name' => 'TEST A', 'text' => 'We agreed to ship the TEST panel. I will send the drawing by Friday. Who checks the wiring?'], ['name' => 'TEST B', 'text' => 'Panel wiring checked twice. Panel ready.']], 2, 30, "TEST agenda\nsecond");
t('MEET07 minutes by rules: decisions, action items with due, open questions, topics, agenda first line', count($mm['decisions']) === 1 && $mm['actions'][0]['owner'] === 'TEST A' && stripos($mm['actions'][0]['due'], 'friday') !== false
    && count($mm['questions']) === 1 && str_contains($mm['summary'], 'panel') && str_contains($mm['summary'], 'Agenda: TEST agenda') && !str_contains($mm['summary'], 'second'));
$txt = meet_minutes_text($mm + ['title' => 'TEST meet', 'agenda' => '', 'host' => 'TEST A', 'start' => 0, 'end' => 1800, 'minutesLong' => 30, 'attendees' => [['name' => 'TEST A', 'joined' => 0, 'left' => null]], 'chatCount' => 0, 'capCount' => 2], 'TEST Org');
t('MEET08 minutes as text', str_contains($txt, 'MINUTES OF MEETING — TEST meet') && str_contains($txt, 'TEST Org') && str_contains($txt, 'ACTION ITEMS') && str_contains($txt, '2 transcript lines'));
[$so, $se] = meet_settings_check(['max_in' => 99, 'ring_secs' => 1, 'langs' => "en-GB English\nbad line\nhi-IN Hindi", 'stun' => "stun:stun.example.com:3478\nhttp://evil.example.com\nturn:t.example.com"]);
t('MEET09 settings: limits, languages read as "code Name", only stun / turn addresses', !$se && $so['max_in'] === 16 && $so['ring_secs'] === 15 && meet_langs($so) === ['en-GB' => 'English', 'hi-IN' => 'Hindi']
    && $so['stun'] === "stun:stun.example.com:3478\nturn:t.example.com" && count(meet_settings_check(['langs' => 'x'])[1]) === 1);
$js = file_get_contents(WN_ROOT . '/assets/meet.js');
t('MEET10 the page script calls only this site (api.php) and the configured ICE list', !preg_match('/stun:|https?:\/\/(?!wa\.me)[a-z]/', $js) && str_contains($js, 'B.ice'));
