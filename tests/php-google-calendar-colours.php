<?php
// No live Google or WordPress writes. Reuse the protected projection fixtures.
require __DIR__ . '/php-google-calendar-unit.php';
$rules = array( 'colours' => array( 'enabled' => true, 'estate' => '10', 'scout' => '9' ) );
function coloured( $changes, $rules, $campsite = true ) {
    return MBS_Google_Calendar::project( array( booking( $changes ) ), true, 'site', 'calendar', wp_timezone(), '2026-10-18', $rules, $campsite );
}
function colour_event( $projection ) { return reset( $projection['desired'] ); }
ok( colour_event( coloured( array( 'organisation' => 'HP Business' ), $rules ) )['colorId'] === '10', 'legacy estate group is green' );
ok( colour_event( coloured( array( 'organisation' => ' hp BUSINESS ' ), $rules ) )['colorId'] === '10', 'exact case-insensitive trimmed group' );
ok( colour_event( coloured( array( 'organisation' => 'Estate' ), $rules ) )['colorId'] === '10', 'coordinator estate default' );
ok( colour_event( coloured( array( 'organisation' => 'Other', 'notes' => "Estate reservation entered by staff.\nPrivate details" ), $rules ) )['colorId'] === '10', 'explicit staff estate with custom group' );
ok( colour_event( coloured( array( 'organisation' => '1st Combs', 'scout_use' => false ), $rules ) )['colorId'] === '9', 'ordinary campsite Scout not internal Scout night' );
ok( colour_event( coloured( array( 'organisation' => 'HP Business Scouts' ), $rules ) )['colorId'] === '9', 'no fuzzy estate name matching' );
ok( colour_event( coloured( array( 'status' => 'pending' ), $rules ) )['status'] === 'tentative', 'pending stays provisional' );
ok( ! isset( colour_event( coloured( array(), array() ) )['colorId'] ), 'feature off by default' );
ok( ! isset( colour_event( coloured( array(), $rules, false ) )['colorId'] ), 'paid venue default unchanged' );
ok( colour_event( coloured( array( 'scout_use' => true ), $rules, false ) )['colorId'] === '9', 'internal Scout use can be blue' );
$custom = $rules; $custom['colours']['estate_groups'] = "Estate team\nHP Business"; $custom['colours']['estate'] = '2';
ok( colour_event( coloured( array( 'organisation' => 'Estate team' ), $custom ) )['colorId'] === '2', 'configurable palette and exact groups' );
$default = $rules; $default['colours']['scout'] = '';
ok( ! isset( colour_event( coloured( array(), $default ) )['colorId'] ), 'inherit calendar default omits colour field' );
$invalid = $rules; $invalid['colours']['estate'] = '99';
try { MBS_Google_Calendar::colour_settings( $invalid, true ); ok( false, 'invalid ID' ); } catch ( RuntimeException $e ) { ok( true, 'invalid ID rejected' ); }
ok( ! isset( colour_event( coloured( array( 'organisation' => 'Estate' ), $invalid ) )['colorId'] ), 'corrupt provider ID never exported' );
$before = projection( array( booking() ) ); $old = colour_event( $before ); $old['id'] = 'manual'; $old['etag'] = 'keep'; $old['colorId'] = '7'; unset( $old['extendedProperties'] );
$after = coloured( array(), $rules ); $key = array_key_first( $after['desired'] );
$source_hash = new ReflectionMethod( MBS_Google_Calendar::class, 'source_hash' ); if ( PHP_VERSION_ID < 80100 ) $source_hash->setAccessible( true );
$event_hash = new ReflectionMethod( MBS_Google_Calendar::class, 'event_hash' ); if ( PHP_VERSION_ID < 80100 ) $event_hash->setAccessible( true );
$links = array( $key => array( 'id' => 'manual', 'source_hash' => hash( 'sha256', json_encode( colour_event( $before ) ) ), 'google_hash' => $event_hash->invoke( null, $old ), 'until' => '2026-10-25' ) );
$plan = MBS_Google_Calendar::plan( $after, array( $old ), 'site', '2026-10-18', $links );
ok( $plan[0]['action'] === 'matched' && $plan[0]['event']['colorId'] === '7', 'new rule leaves old protected link and colour unchanged' );
ok( colour_event( $before )['id'] === colour_event( $after )['id'], 'new colour does not change stable Google event ID' );
ok( $source_hash->invoke( null, colour_event( $before ) ) === $source_hash->invoke( null, colour_event( $after ) ), 'colour preferences excluded from protected booking hash' );
echo "Google colours: $count total assertions passed.\n";
