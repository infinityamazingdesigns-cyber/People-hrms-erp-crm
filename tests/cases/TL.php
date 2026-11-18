<?php
// Team chat rules (lib/talk.php) — synthetic data only.
require_once WN_ROOT . '/lib/talk.php';
$set = TALK_DEFAULTS;
$lead = talk_rights_for(['MANAGER'], $set); $emp = talk_rights_for(['EMPLOYEE'], $set); $sa = talk_rights_for(['SUPERADMIN'], $set);
t('TL01 rights from roles: leads, manage, delete', $lead === ['lead' => true, 'manage' => false, 'delete' => false] && $emp['lead'] === false && $sa === ['lead' => true, 'manage' => true, 'delete' => true]
    && talk_rights_for(['HRMANAGER'], $set)['delete'] === false);
t('TL02 direct chats by policy: two-way for all, employees to leads only, leads only start', talk_dm_allowed('two', $emp, $emp) && !talk_dm_allowed('lead', $emp, $emp) && talk_dm_allowed('lead', $emp, $lead)
    && !talk_dm_allowed('oneway', $emp, $lead) && talk_dm_allowed('oneway', $lead, $emp));
$g = ['kind' => 'group', 'mode' => 'one']; $d = ['kind' => 'dm', 'mode' => 'two'];
t('TL03 sending: members only; one-way groups by admins; a leaver cannot be messaged; reply rules', talk_block($g, false, true, 'two', $emp, $emp, true, false) !== '' && talk_block($g, true, false, 'two', $emp, $emp, true, false) !== ''
    && talk_block($g, true, true, 'two', $emp, $emp, true, false) === '' && talk_block($d, true, false, 'two', $emp, $emp, false, false) !== ''
    && talk_block($d, true, false, 'oneway', $emp, $lead, true, true) === '' && talk_block($d, true, false, 'oneway', $emp, $emp, true, false) !== '' && talk_block($d, true, false, 'lead', $emp, $lead, true, false) === '');
t('TL04 ticks: sent, delivered to all, read by all; read shown as delivered when receipts are off', talk_tick(10, [[5, 9], [12, 12]], true) === 'sent' && talk_tick(10, [[5, 10], [12, 12]], true) === 'delivered'
    && talk_tick(10, [[10, 10], [11, 11]], true) === 'read' && talk_tick(10, [[10, 10]], false) === 'delivered' && talk_tick(10, [], true) === 'sent');
t('TL05 files by their own bytes; Office only as a real zip with the right ending; text only when clean', talk_file_type("\xFF\xD8\xFFx", 'a.exe') === ['image/jpeg', true] && talk_file_type('%PDF-1.4', 'a') === ['application/pdf', false]
    && talk_file_type("PK\x03\x04x", 'a.docx')[0] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' && talk_file_type("PK\x03\x04x", 'a.zip') === null
    && talk_file_type("a,b\n1,2", 'x.csv') === ['text/csv', false] && talk_file_type("MZ\x00\x01", 'x.txt') === null && talk_file_type('<script>', 'x.html') === null);
[$o, $e] = talk_settings_check(['dm' => 'nope', 'create' => 'all', 'files' => '1', 'manage_roles' => ['HRMANAGER'], 'delete_roles' => []], ['HRMANAGER' => 'HR', 'SUPERADMIN' => 'SA']);
t('TL06 settings: unknown policy falls back, roles checked', $o['dm'] === 'two' && $o['create'] === 'all' && $o['files'] && !$o['receipts'] && count($e) === 1);
