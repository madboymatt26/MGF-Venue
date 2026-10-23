<?php
/** Disposable WordPress only. No live legacy plugin or customer data. */
if ( get_bloginfo( 'name' ) !== 'MGF Integration' || ! class_exists( 'MBS_Test_Gateway' ) ) {
    throw new RuntimeException( 'Legacy recovery test requires the isolated integration site.' );
}
global $wpdb;
$table = $wpdb->prefix . 'booking_guard_fixture';
$option = 'mbs_legacy_guard_fixture';
$enabled = new ReflectionProperty( 'MBS_Legacy_Read_Only', 'enabled' );
$prefix = new ReflectionProperty( 'MBS_Legacy_Read_Only', 'prefix' );
$enabled->setAccessible( true ); $prefix->setAccessible( true );
$before_enabled = $enabled->getValue(); $before_prefix = $prefix->getValue();
function legacy_recovery_assert( $value, $message ) {
    if ( ! $value ) throw new RuntimeException( $message );
}
legacy_recovery_assert( $wpdb->query( "CREATE TABLE {$table} (id INT PRIMARY KEY, marker INT NOT NULL)" ) !== false, 'Create synthetic legacy table before protection' );
$wpdb->insert( $table, array( 'id'=>1, 'marker'=>7 ) );
try {
    $enabled->setValue( null, true ); $prefix->setValue( null, $wpdb->prefix );
    add_filter( 'query', array( 'MBS_Legacy_Read_Only', 'guard_query' ), 999 );
    foreach ( array(
        "UPDATE {$table} SET marker=99 WHERE id=1",
        "DELETE FROM {$table} WHERE id=1",
        "INSERT INTO {$table} VALUES (2,99)",
        "ALTER TABLE {$table} ADD unsafe_column INT",
        "DROP TABLE {$table}",
    ) as $sql ) {
        $queries = $wpdb->num_queries;
        legacy_recovery_assert( $wpdb->query( $sql ) === false, 'Protected legacy write returns failure, not wp_die' );
        legacy_recovery_assert( $wpdb->num_queries === $queries, 'Protected SQL never reaches the database' );
        legacy_recovery_assert( (int) $wpdb->get_var( "SELECT marker FROM {$table} WHERE id=1" ) === 7, 'Reference data preserved and reads continue' );
        // Emulate the normal core option update following legacy lifecycle or
        // housekeeping queries. It must execute in the SAME request.
        update_option( $option, wp_generate_uuid4(), false );
        legacy_recovery_assert( (bool) get_option( $option ), 'Unrelated WordPress option operations continue' );
    }
    legacy_recovery_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) === 1, 'No legacy rows added/deleted' );
    legacy_recovery_assert( ! in_array( 'unsafe_column', $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ), true ), 'Legacy schema preserved' );
    echo "LEGACY_READ_ONLY_RECOVERY_OK: protected SQL rejected; request, reads and core options continue\n";
} finally {
    remove_filter( 'query', array( 'MBS_Legacy_Read_Only', 'guard_query' ), 999 );
    $enabled->setValue( null, $before_enabled ); $prefix->setValue( null, $before_prefix );
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    delete_option( $option );
}
