<?php

if ( ! class_exists( 'MBS_Audit_Assertions', false ) ) {
    final class MBS_Audit_Assertions {
        private static $current;
        private $failures = array();

        public static function current() {
            if ( ! self::$current ) self::$current = new self();
            return self::$current;
        }

        public function run( $name, $callback ) {
            try {
                $callback();
                echo "OK: {$name}.\n";
            } catch ( Throwable $error ) {
                $this->failures[] = $name . ': ' . $error->getMessage();
                fwrite( STDERR, "AUDIT REGRESSION: {$name}: {$error->getMessage()}\n" );
            }
        }

        public function check( $condition, $message ) {
            if ( ! $condition ) {
                $this->failures[] = $message;
                fwrite( STDERR, "AUDIT REGRESSION: {$message}\n" );
            }
        }

        public static function assert_that( $condition, $message ) {
            if ( ! $condition ) throw new RuntimeException( $message );
        }

        public function finish( $success_message ) {
            if ( $this->failures ) {
                throw new RuntimeException(
                    count( $this->failures ) . " adversarial regression(s) failed:\n- " . implode( "\n- ", $this->failures )
                );
            }
            echo 'OK: ' . $success_message . ".\n";
        }
    }
}
