<?php
require_once __DIR__ . '/audit-assertions.php';

$mode = isset( $args[0] ) ? (string) $args[0] : 'pass';
$assertions = MBS_Audit_Assertions::current();
$assertions->check( $mode !== 'fail', 'Controlled false assertion must terminate WP-CLI with a non-zero exit.' );
$assertions->finish( 'controlled assertion harness pass path succeeded' );
