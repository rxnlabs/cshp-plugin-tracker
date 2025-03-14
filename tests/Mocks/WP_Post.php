<?php
/**
 * Mock the WP_Post class from WordPress if it does not exist.
 */

// don't load this class when doing Integration testing.
if ( isset( $GLOBALS['argv'] ) && in_array( '--group=integration', $GLOBALS['argv'], true ) ) {
	return;
}

if ( ! class_exists( '\WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_title;
		public $post_content;
		public $post_excerpt;
		public $post_status;
		public $post_type;
		public $post_author;
		public $post_date;

		/**
		 * Constructor to initialize mock WP_Post object.
		 *
		 * @param array $properties Key-value pairs of post properties.
		 */
		public function __construct( array $properties = array() ) {
			foreach ( $properties as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}

		/**
		 * Simulate get_post_type function for WP_Post.
		 *
		 * @return string The post type.
		 */
		public function post_type() {
			return $this->post_type ?? 'post';
		}

		/**
		 * Simulate WordPress's get_the_title for WP_Post.
		 *
		 * @return string The post title.
		 */
		public function get_the_title() {
			return $this->post_title;
		}

		/**
		 * Create a mock WP_Post object from a standard post-like object.
		 *
		 * @param object $post_object A standard object representing a post.
		 * @return WP_Post
		 */
		public static function get_instance_from_object( $post_object ) {
			$post_array = (array) $post_object;
			return new self( $post_array );
		}
	}
}
