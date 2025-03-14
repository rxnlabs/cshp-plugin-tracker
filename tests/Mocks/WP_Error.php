<?php
/**
 * Mock the WP_Error class from WordPress if it does not exist.
 */

// don't load this class when doing Integration testing.
if ( isset( $GLOBALS['argv'] ) && in_array( '--group=integration', $GLOBALS['argv'], true ) ) {
	return;
}

if ( ! class_exists( '\WP_Error' ) ) {
	class WP_Error {
		private $errors     = array();
		private $error_data = array();

		// Add an error
		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		// Retrieve all error messages
		public function get_error_messages( $code = '' ) {
			if ( empty( $code ) ) {
				$all_messages = array();
				foreach ( $this->errors as $error_messages ) {
					$all_messages = array_merge( $all_messages, $error_messages );
				}
				return $all_messages;
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
		}

		// Check if errors exist
		public function has_errors() {
			return ! empty( $this->errors );
		}

		// Retrieve specific error data
		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				return $this->error_data;
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}
