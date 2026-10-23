<?php
// Pure transport fixtures: no production calendar, credentials or booking mutations.
require __DIR__ . '/php-google-calendar-lifecycle.php';

ready(); $options['mbs_campsite_mode'] = true;
$want = actual_ops()[0]['want']; $manual = $want;
unset($manual['extendedProperties']); $manual['id'] = 'estate'; $manual['summary'] = 'Frostbite';
$manual['etag'] = 'estate-version'; $manual['location'] = 'Another area';
$ops = actual_ops([$manual]);
check($ops[0]['action'] === 'create', 'native campsite overlap has independent event identity');
$queue = [['code'=>200,'body'=>json_encode(['kind'=>'calendar#events','items'=>[$manual]])]];
invoke('execute', $ops);
check(count($requests) === 2 && $requests[1][1]['method'] === 'POST', 'interval rechecked and separate event inserted despite unrelated overlap');
check(json_decode($requests[1][1]['body'],true)['id'] !== 'estate', 'never adopts or overwrites overlapping estate event');
check(actual_ops([$manual, $want])[0]['action'] === 'matched', 'repeated sync reuses stable native booking identity');

foreach (['venue','import'] as $guard) {
    ready(); $options['mbs_campsite_mode'] = $guard !== 'venue';
    if ($guard === 'import') $wpdb->rows[0]->custom_fields = json_encode(['_mbs_legacy'=>['booking_id'=>1]]);
    check(actual_ops([$manual])[0]['action'] === 'review', $guard.' overlap still requires explicit provenance review');
}
ready(); $options['mbs_campsite_mode'] = true; $ops = actual_ops();
$duplicate = $ops[0]['want']; $duplicate['etag'] = 'already-created';
$queue = [['code'=>200,'body'=>json_encode(['kind'=>'calendar#events','items'=>[$duplicate]])]];
try { invoke('execute', $ops); check(false, 'same booking late insert'); }
catch (RuntimeException $e) { check(count($requests) === 1, 'late same-booking event never duplicated'); }

$event = lifecycle_ready(); $event['etag'] = 'decorated'; $event['location'] = 'whole site'; $event['description'] = 'Estate information'; $event['colorId'] = '10';
$ops = lifecycle_plan([$event]);
check($ops[0]['action'] === 'refresh_link', 'Google-only decoration queues local comparison refresh, not relinking');
$queue = [['code'=>200,'body'=>json_encode($event)]];
invoke('execute', $ops);
check(count($requests) === 1 && $requests[0][1]['method'] === 'GET', 'decoration repair makes no Google write');
$link = reset($options['mbs_google_calendar']['links']);
check($link['origin'] === 'mgf' && $link['id'] === $event['id'], 'repair preserves managed origin and event ID');
check(lifecycle_plan([$event])[0]['action'] === 'matched', 'decoration refresh removes false review');

$event = lifecycle_ready(); $event['location'] = 'whole site'; $event['etag'] = 'decorated'; $wpdb->rows[0]->start_time = '18:00:00';
$ops = lifecycle_plan([$event]); $queue = [['code'=>200,'body'=>json_encode($event)]]; invoke('execute', $ops);
$ops = lifecycle_plan([$event]); check($ops[0]['action'] === 'managed_update', 'simultaneous website schedule edit is not lost after decoration refresh');
$requests = []; lifecycle_reply($event, $ops[0]['want']); invoke('execute', $ops);
check(!isset(json_decode($requests[2][1]['body'],true)['location']), 'later lifecycle PATCH still preserves Google location');

foreach (['summary','time','transparency','owner','guest'] as $guard) {
    $event = lifecycle_ready(); $event['location'] = 'whole site'; $event['etag'] = 'changed';
    if ($guard === 'summary') $event['summary'] = 'Independent title';
    if ($guard === 'time') $event['start']['dateTime'] = '2026-10-24T17:00:00+01:00';
    if ($guard === 'transparency') $event['transparency'] = 'transparent';
    if ($guard === 'owner') $event['extendedProperties']['private']['mgf_site'] = 'foreign';
    if ($guard === 'guest') $event['attendees'] = [['email'=>'fixture@example.invalid']];
    $ops = lifecycle_plan([$event]); invoke('execute', $ops);
    check(!in_array($ops[0]['action'], ['refresh_link','managed_update'], true) && !$requests, 'never accepts '.$guard.' edit as harmless decoration');
}
$event = lifecycle_ready(); $event['location'] = 'whole site'; $event['etag'] = 'decorated'; $ops = lifecycle_plan([$event]);
$event['summary'] = 'Changed after preview'; $queue = [['code'=>200,'body'=>json_encode($event)]];
$before = $options['mbs_google_calendar']['links'];
try { invoke('execute', $ops); check(false, 'concurrent change'); }
catch (RuntimeException $e) { check($before === $options['mbs_google_calendar']['links'] && count($requests) === 1, 'fresh Google change blocks stale comparison repair'); }
$event = lifecycle_ready(); $event['location'] = 'whole site'; $event['etag'] = 'decorated'; invoke('execute', lifecycle_plan([$event]), [], false);
check(!$requests, 'reference-only save cannot run automatic refresh');
echo "Independent campsite and decoration safeguards: $assertions total assertions passed.\n";
