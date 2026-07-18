<?php
function dbDelta( $sql ) {
    global $mbs_test_dbdelta_calls, $mbs_test_created_tables;
    if ( preg_match( '/CREATE TABLE(?: IF NOT EXISTS)?\s+([^\s(]+)/i', $sql, $match ) ) {
        $table = trim( $match[1], '`' );
        $mbs_test_dbdelta_calls[ $table ] = ( $mbs_test_dbdelta_calls[ $table ] ?? 0 ) + 1;
        $mbs_test_created_tables[ $table ] = true;
    }
}
