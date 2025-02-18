<?php
/**
 * Utility functions that are used throughout the plugin and/or debug functions that are used during development.
 */
declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility functions not specific to this plugin. Used through Cshp plugins.
 */
class Utilities {
	use Share;

	public function __construct() {}

	/**
	 * Get the full path to a wp-content/plugins folder.
	 *
	 * WordPress has no built-in way to get the full path to a plugins folder.
	 *
	 * @return string Absolute path to a wp-content/plugins folder.
	 */
	public function get_plugin_folders_path() {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		// have to use WP_PLUGIN_DIR even though we should not use the constant directly
		$plugin_directory = WP_PLUGIN_DIR;

		// add a try catch in case the WordPress site is in FTP mode
		// $wp_filesytem->wp_plugins_dir throws errors on FTP FS https://github.com/pods-framework/pods/issues/6242
		try {
			if ( ! empty( $wp_filesystem ) &&
				is_callable( array( $wp_filesystem, 'wp_plugins_dir' ) ) &&
				! empty( $wp_filesystem->wp_plugins_dir() ) ) {
				$plugin_directory = $wp_filesystem->wp_plugins_dir();
			}
		} catch ( \TypeError $error ) {
		}

		return $plugin_directory;
	}

	/**
	 * Get the full path to a plugin file
	 *
	 * WordPress has no built-in way to get the full path to a plugin's main file when getting a list of plugins from the site.
	 *
	 * @param string $plugin_folder_name_and_main_file Plugin folder and main file (e.g. cshp-plugin-tracker/cshp-plugin-tracker.php).
	 *
	 * @return string Absolute path to a plugin file.
	 */
	public function get_plugin_file_full_path( $plugin_folder_name_and_main_file ) {
		// remove the directory separator slash at the end of the plugin folder since we add the director separator explicitly
		$clean_plugin_folder_path = rtrim( $this->get_plugin_folders_path(), DIRECTORY_SEPARATOR );
		return sprintf( '%s%s%s', $clean_plugin_folder_path, DIRECTORY_SEPARATOR, $plugin_folder_name_and_main_file );
	}

	/**
	 * Remove the folder path to the WP_CONTENT_DIR folder from a file path and return the relative path to the file.
	 *
	 * Given a full file path and that file path is in the wp-content/ folder, remove the path to the wp-content/ folder
	 * and return the file path relative to the wp-content/ folder.
	 *
	 * @param string $path Full file path to the file that lives somewhere in the wp-content/ folder.
	 * @param string $remove_additional_subfolder If the file lives in the plugins, themes, or uploads folder, remove that
	 * file path as well.
	 *
	 * @return string Relative path to the file in the wp-content/ folder or full path to the file if the file is not
	 * in the wp-content/ folder.
	 */
	public function get_wp_content_relative_file_path( $path, $remove_additional_subfolder = '' ) {
		$wp_content_path = WP_CONTENT_DIR;

		if ( 'plugins' === $remove_additional_subfolder || 'themes' === $remove_additional_subfolder || 'uploads' === $remove_additional_subfolder ) {
			$wp_content_path = sprintf( '%s/%s', $wp_content_path, $remove_additional_subfolder );
		}

		$position = stripos( $path, $wp_content_path );

		if ( false !== $position ) {
			return substr_replace( $path, '', $position, strlen( $wp_content_path ) );
		}

		return $path;
	}

	/**
	 * Get the URL to a file relative to the root folder of the plugin
	 *
	 * @param string $file File to load.
	 *
	 * @return string URL to the file in the plugin.
	 */
	public function get_plugin_file_uri( $file ) {
		$file = ltrim( $file, '/' );

		$url = null;
		if ( empty( $file ) ) {
			$url = plugin_dir_url( $this->get_this_plugin_file() );
		} elseif ( file_exists( plugin_dir_path( $this->get_this_plugin_file() ) . '/' . $file ) ) {
			$url = plugin_dir_url( $this->get_this_plugin_file() ) . $file;
		}

		return $url;
	}

	/**
	 * Check if the external URL domain is blocked by the WP_HTTP_BLOCK_EXTERNAL constant.
	 *
	 * Only checks if the site is blocked by WordPress, not of the site is actually reachable by the site.
	 *
	 * @param string $url URL to check.
	 *
	 * @return bool True if the domain is blocked. False if the domain is not explicitly blocked by WordPress.
	 */
	public function is_external_domain_blocked( $url ) {
		$is_url_blocked = false;

		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === WP_HTTP_BLOCK_EXTERNAL ) {
			$url_parts      = wp_parse_url( $url );
			$url_domain     = sprintf( '%s://%s', $url_parts['scheme'], $url_parts['host'] );
			$check_host     = new \WP_Http();
			$is_url_blocked = $check_host->block_request( $url_domain );
		}

		return $is_url_blocked;
	}

	/**
	 * Detect if request to the wordpress.org api endpoints are blocked due to the constant WP_HTTP_BLOCK_EXTERNAL
	 * being set to true
	 *
	 * The plugin needs the ping https://api.wordpress.org to determine if a plugin is premium. If externa; request are blocked,
	 * all plugins are considered premium.
	 *
	 * @return bool
	 */
	public function is_wordpress_org_external_request_blocked() {
		return $this->is_external_domain_blocked( 'https://api.wordpress.org' );
	}

	/**
	 * Test if this website is in some development mode based on a list of flags.
	 *
	 * Detect if the site is in some known development state.
	 *
	 * @return bool True if the site is in some development mode. False if not in a known development mode.
	 */
	public function is_development_mode() {
		$home_url      = home_url( '/' );
		$domain        = wp_parse_url( $home_url, PHP_URL_HOST );
		$tlds_to_check = array(
			'cshp.co',
			'cshp.dev',
			'kinsta.cloud',
			'pantheonsite.io',
			'wpengine.com',
			'flywheelstaging.com',
			'flywheelsites.com',
			'dreamhosters.com',
			'lando.dev',
			'lando.site',
			'ddev.site',
			'localhost',
		);

		foreach ( $tlds_to_check as $tld ) {
			if ( function_exists( '\str_ends_with' ) ) {
				if ( str_ends_with( $domain, $tld ) ) {
					return true;
				}
			} elseif ( false !== strpos( $domain, $tld, -strlen( $tld ) ) ) {
				return true;
			}
		}

		if ( ( defined( 'KINSTA_DEV_ENV' ) && true === KINSTA_DEV_ENV ) ||
			'production' !== wp_get_environment_type() ||
			( function_exists( '\wp_get_development_mode' ) && ! empty( wp_get_development_mode() ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Try to determine if this plugin is being run via WP CLI
	 *
	 * @return bool True if WP CLI is running. False if WP CLI is not running or cannot be detected.
	 */
	public function is_wp_cli_environment() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the current WordPress version
	 *
	 * @return string WordPress version.
	 */
	public function get_current_wordpress_version() {
		global $wp_version;
		return $wp_version;
	}

	/**
	 * Get the list of active plugins on the site
	 *
	 * @return array List of plugin activation files (e.g. plugin-folder/plugin-main-file.php).
	 */
	public function get_active_plugins() {
		$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins' );

			if ( $network_plugins && is_array( $network_plugins ) ) {
				$network_plugins = array_keys( $network_plugins );
				$active_plugins  = array_merge( $active_plugins, $network_plugins );
			}
		}

		return $active_plugins;
	}

	/**
	 * Get the status of all plugins to determine if they are active or inactive.
	 *
	 * @return array List of plugins with the plugin folder as the key and the status as the value.
	 */
	public function get_plugins_status() {
		$plugins = array_keys( get_plugins() );
		$status  = array();

		foreach ( $plugins as $plugin_relative_file ) {
			$plugin_folder = dirname( $plugin_relative_file );
			if ( is_multisite() && is_plugin_active_for_network( $plugin_relative_file ) ) {
				$status[ $plugin_folder ] = 'network-active';
			} elseif ( is_plugin_active( $plugin_relative_file ) ) {
				$status[ $plugin_folder ] = 'active';
			} else {
				$status[ $plugin_folder ] = 'inactive';
			}
		}

		return $status;
	}

	/**
	 * Get the list of active themes on the site (current theme and parent theme)
	 *
	 * @return array List of theme folder name.
	 */
	public function get_active_themes() {
		$theme  = wp_get_theme();
		$themes = array();

		if ( ! empty( $theme ) ) {
			$parent_theme    = $theme->parent();
			$theme_path_info = explode( DIRECTORY_SEPARATOR, $theme->get_stylesheet_directory() );
			$themes[]        = end( $theme_path_info );

			if ( ! empty( $parent_theme ) ) {
				$parent_theme_path_info = explode( DIRECTORY_SEPARATOR, $parent_theme->get_template_directory() );
				$themes[]               = end( $parent_theme_path_info );
			}
		}

		return $themes;
	}

	/**
	 * Check if the plugin or theme exists at the path
	 *
	 * @param string $file_path File path to the zip.
	 *
	 * @return bool True if the file exists and false if the file does not exist.
	 */
	public function does_file_exists( $file_path ) {
		if ( empty( $file_path ) || ! is_string( $file_path ) ) {
			return false;
		}

		// use the native PHP functions instead of the WP Filesystem API method $wp_filesystem->exists
		// $wp_filesystem->exists throws errors on FTP FS https://github.com/pods-framework/pods/issues/6242
		return file_exists( $file_path );
	}

	/**
	 * var_dump to the error log. Useful to get more information about what is being outputted such as the type of variable. Useful to see the contents of an array or especially a boolean to see if the boolean is true or false.
	 *
	 * @param Object $object_analyze Variable to analyze.
	 *
	 * @return void
	 */
	public function var_dump_error_log( $object_analyze = null ) {
		// don't enable output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is already on.
		// error would be: "Fatal error:  ob_start(): Cannot use output buffering in output buffering display handlers".
		// don't end output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is used in output buffering context.
		// error would be "Fatal error: ob_end_clean(): Cannot use output buffering in output buffering display handlers".
		if ( empty( ob_get_level() ) || 0 === ob_get_level() ) {
			ob_start();
			// start buffer capture.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( $object_analyze );
			// dump the values
			$contents = ob_get_contents();
			// put the buffer into a variable.
			ob_end_clean();
		} else {
			$contents = $this->output_buffering_cast( $object_analyze );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $contents );
		// log contents of the result of var_dump( $object ).
	}

	/**
	 * print_r to the error long. Useful to get more information about what is being outputted to see things like the contents of an array.
	 *
	 * @param Object $object_analyze Variable to analyze.
	 *
	 * @return void.
	 */
	public function print_r_error_log( $object_analyze = null ) {
		if ( empty( ob_get_level() ) || 0 === ob_get_level() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$contents = print_r( $object_analyze, true );
		} else {
			$contents = $this->output_buffering_cast( $object_analyze );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $contents );
	}

	/**
	 * debug_backtrace() to the error long. Better than the regular debug_backtrace() since the output is better for printing. Useful if you need to backtrace when something went wrong with the code.
	 *
	 * @return void.
	 */
	public function debug_backtrace_error_log() {
		// don't enable output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is already on
		// error would be: "Fatal error:  ob_start(): Cannot use output buffering in output buffering display handlers"
		// don't end output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is used in output buffering context
		// error would be "Fatal error: ob_end_clean(): Cannot use output buffering in output buffering display handlers"
		if ( empty( ob_get_level() ) || 0 === ob_get_level() ) {
			ob_start();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_print_backtrace
			$contents = debug_print_backtrace();
			ob_end_clean();
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$contents = $this->var_dump_error_log( debug_backtrace() );
		}

		if ( is_null( $contents ) ) {
			$contents = '';
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $contents );
	}

	/**
	 * deal with situations where output buffering is turned on by some other code (maybe TranslatePress turned it on???) and we want to var_dump to the error.log. The bad news is that we really can't do this very well, so here are some things we can do to help, kinda.
	 *
	 * @param Object $object_analyze Variable to analyze.
	 *
	 * @return string A string we can use to wrote to the debug.log file instead of var_dump'ing.
	 */
	public function output_buffering_cast( $object_analyze ) {
		if ( is_string( $object_analyze ) ) {
			return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is a string:) ' . $object_analyze;
		} elseif ( is_numeric( $object_analyze ) ) {
			return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is something that is numeric:) ' . $object_analyze;
		} elseif ( is_array( $object_analyze ) ) {
			$json = wp_json_encode( $object_analyze );

			if ( empty( $json ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				$json = serialize( $object_analyze );
			}
			return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is a an array. We are converting it to a JSON string though for easier reading in the error_log:) ' . $json;
		} elseif ( is_object( $object_analyze ) ) {
			return '(NOTE: output buffering is on, so we cannot var_dump to the error log). This thing passed to the error_log function is a an array. We are converting it to a serialized string though for easier reading in the error_log:) ' . wp_json_encode( $object_analyze );
		} elseif ( is_null( $object_analyze ) ) {
			return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is a null. Returning an empty string)';
		} elseif ( empty( $object_analyze ) ) {
			return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is something that evaluates to empty returning an empty string)';
		} else {
			return '(NOTE: output buffering is on, so we cannot var_dump to the error log. We have no idea what this thing passed is and we cannot convert it to a string, so we are just returning nothing. Try turning off output buffering and try var_dump again)';
		}
	}

	/**
	 * Create a polyfill for str_ends_with in case we are not using PHP 8.
	 *
	 * @param string $haystack The string to search for the substring in.
	 * @param string $needle The substring to search for.
	 *
	 * @return bool True if the string ends in the substring. False if the substring does not end in the substring.
	 */
	public function str_ends_with( $haystack, $needle ) {
		if ( function_exists( '\str_ends_with' ) ) {
			return \str_ends_with( $haystack, $needle );
		}

		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	/**
	 * Create a polyfill for str_starts_with in case we are not using PHP 8.
	 *
	 * @param string $haystack The string to search for the substring in.
	 * @param string $needle The substring to search for.
	 *
	 * @return bool True if the string starts with the substring. False if the substring does not start with the substring.
	 */
	public function str_starts_with( $haystack, $needle ) {
		if ( function_exists( '\str_starts_with' ) ) {
			return \str_starts_with( $haystack, $needle );
		}

		return 0 === strpos( $haystack, $needle );
	}
}
