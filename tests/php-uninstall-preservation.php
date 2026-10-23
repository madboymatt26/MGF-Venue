<?php
define('WP_UNINSTALL_PLUGIN',true);
$wpdb=new class {function __call($name,$args){throw new RuntimeException('Default uninstall must not touch the database.');}};
require __DIR__.'/../wp-plugin/mathlin-booking/uninstall.php';
echo "UNINSTALL_PRESERVES_DATA_OK\n";
