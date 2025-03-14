<?php
/**
 * Stub functions for WordPress testing.
 *
 * This file contains functions that are regularly stubbed as part
 * of the testing process.
 */

// don't load these functions when doing Integration testing.
if ( isset( $GLOBALS['argv'] ) && in_array( '--group=integration', $GLOBALS['argv'], true ) ) {
	return;
}

if ( ! function_exists( '\get_plugins' ) ) {
	/**
	 * Stub for get_plugins.
	 *
	 * @param string $plugin_file Optional. Plugin file to retrieve.
	 * @return array Simulated list of plugins with their data.
	 */
	function get_plugins( $plugin_file = '' ) {
		$result = array(
			'gravityforms/gravityforms.php'      => array(
				'Name'    => 'Gravity Forms',
				'Version' => '3.2.1',
			),
			'advanced-custom-fields-pro/acf.php' => array(
				'Name'    => 'Advanced Custom Fields Pro',
				'Version' => '3.2.1',
			),
		);

		foreach ( $result as $key => $value ) {
			// Add variations of plugin key names to simulate expected behaviors.
			$just_plugin_folder                 = strstr( $key, '/', true );
			$result[ $just_plugin_folder ]      = $value;
			$result[ '/' . $just_plugin_folder ] = $value;
		}

		if ( ! empty( $plugin_file ) && isset( $result[ $plugin_file ] ) ) {
			return array( $plugin_file => $result[ $plugin_file ] );
		}

		return $result;
	}
}

if ( ! function_exists( '\get_plugin_data' ) ) {
	/**
	 * Stub for get_plugin_data.
	 *
	 * @param string $plugin_file Path to the plugin file.
	 * @return array Mock plugin data for testing purposes.
	 */
	function get_plugin_data( $plugin_file ) {
		return array(
			'Name'        => 'Fake Plugin',
			'PluginURI'   => 'https://example.com/fake-plugin',
			'Version'     => '1.0.0',
			'Description' => 'This is a fake stub for testing purposes.',
			'Author'      => 'Fake Fake',
			'AuthorURI'   => 'https://example.com',
			'TextDomain'  => 'fake-plugin',
		);
	}
}

if ( ! function_exists( '\plugin_dir_path' ) ) {
	/**
	 * Stub for plugin_dir_path.
	 *
	 * @param string $file The __FILE__ magic constant or path input.
	 * @return string Mock plugin directory path.
	 */
	function plugin_dir_path( $file ) {
		return '/path/to/plugin/directory/';
	}
}

if ( ! function_exists( '\get_rest_url' ) ) {
	/**
	 * Stub for get_rest_url.
	 *
	 * @param int|null $blog_id Blog ID (ignored in this stub).
	 * @param string   $path    REST API path.
	 * @param string   $scheme  REST API scheme (ignored in this stub).
	 * @return string Mock REST API URL.
	 */
	function get_rest_url( $blog_id = null, $path = '/', $scheme = '' ) {
		return 'http://example.com/wp-json/cshp/v1/' . $path;
	}
}

if ( ! function_exists( '\add_query_arg' ) ) {
	/**
	 * Stub for add_query_arg.
	 *
	 * @param array|string $args The query arguments.
	 * @param string       $url  The base URL.
	 * @return string The URL with query arguments appended.
	 */
	function add_query_arg( ...$args ) {
		$query_args   = is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] );
		$url          = $args[ count( $args ) - 1 ];
		$query_string = http_build_query( $query_args );

		return $url . '?' . $query_string;
	}
}

if ( ! function_exists( '\home_url' ) ) {
	/**
	 * Stub for home_url.
	 *
	 * @param string $path   Path to append to home URL.
	 * @param string $scheme Requested URL scheme.
	 * @return string Simulated home URL.
	 */
	function home_url( $path = '', $scheme = 'http' ) {
		$base = 'https://example.com';

		if ( '' !== $path && 0 !== strpos( $path, '/' ) ) {
			$path = '/' . $path;
		}

		return $base . $path;
	}
}

if ( ! function_exists( '\query_posts' ) ) {
	/**
	 * Stub for query_posts.
	 *
	 * @param array|string $query_vars Query parameters.
	 * @return WP_Query Mock WP_Query instance.
	 */
	function query_posts( $query_vars ) {
		return new WP_Query( $query_vars );
	}
}

if ( ! function_exists( '\get_posts' ) ) {
	/**
	 * Stub for get_posts.
	 *
	 * @param array $args Array of query arguments.
	 * @return array List of simulated posts.
	 */
	function get_posts( $args ) {
		$query = new WP_Query( $args );

		return $query->get_posts();
	}
}

if ( ! function_exists( '\wp_upload_dir' ) ) {
	/**
	 * Stub for wp_upload_dir.
	 *
	 * @return array Mock WordPress upload directory details.
	 */
	function wp_upload_dir() {
		return array(
			'basedir' => WP_CONTENT_DIR,
			'baseurl' => 'http://example.com/wp-content',
			'subdir'  => '/uploads/cshp',
			'path'    => WP_CONTENT_DIR . '/uploads/cshp',
			'url'     => 'http://example.com/wp-content/uploads/cshp',
		);
	}
}

if ( ! function_exists( '\__' ) ) {
	/**
	 * Stub the __ function.
	 *
	 * @param string $string_to_translate The text to be translated.
	 * @param string $domain Optional. The text domain for translation.
	 *
	 * @return string The translated string if available, otherwise the original string.
	 */
	function __ ( $string_to_translate, $domain = '' ) {
		return $string_to_translate;
	}
}