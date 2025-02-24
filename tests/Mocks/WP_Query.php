<?php
/**
 * Mock the WP_Query class from WordPress if it does not exist.
 */

// don't load this class when doing Integration testing.
if ( isset( $GLOBALS['argv'] ) && in_array( '--group=integration', $GLOBALS['argv'], true ) ) {
	return;
}

if ( ! class_exists( '\WP_Query' ) ) {
	class WP_Query {
		public $query_vars = array();
		public $posts      = array();
		public $post_count = 0;

		public function __construct( $query = '' ) {
			$this->init_query( $query );

			// used during our unit test to override the posts results of a WP_Query
			if ( isset( $GLOBALS['OVERRIDE_WP_POST_QUERY_CLASS_RESULT'] ) ) {
				$this->posts = $GLOBALS['OVERRIDE_WP_POST_QUERY_CLASS_RESULT'];
			}
		}

		// Simulate the setting of query variables
		private function init_query( $query ) {
			if ( is_array( $query ) ) {
				$this->query_vars = $query;
			} else {
				$this->query_vars = array();
			}
		}

		// Simulate fetching posts
		public function get_posts() {
			// Return posts data
			$results = array(
				(object) array(
					'ID'         => 1,
					'post_title' => 'Hello World',
					'post_type'  => 'post',
				),
				(object) array(
					'ID'         => 2,
					'post_title' => 'Sample Page',
					'post_type'  => 'page',
				),
			);

			if ( ! isset( $GLOBALS['OVERRIDE_WP_POST_QUERY_CLASS_RESULT'] ) ) {
				$this->posts = $results;
			}

			$this->post_count = count( $this->posts );

			// if we have mocked a WP_Post class, then return the posts as a Post object
			if ( class_exists( '\WP_Post' ) ) {
				$this->posts = array_map(
					function ( $post ) {
						return new \WP_Post( $post );
					}
				);
			}

			// if we only want to return post ids, then just get the post ids
			if ( $this->query_vars['fields'] == 'ids' ) {
				$this->posts = array_map(
					function ( $post ) {
						return $post->ID;
					}
				);
			}

			return $this->posts;
		}

		// Simulate have_posts functionality (used in loops)
		public function have_posts() {
			return ! empty( $this->posts );
		}

		// Simulate the_post functionality for iterating posts
		public function the_post() {
			return array_shift( $this->posts );
		}
	}
}
