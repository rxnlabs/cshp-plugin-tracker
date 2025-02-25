<?php
/*
Plugin Name: Cornershop Plugin Tracker
Plugin URI: https://cornershopcreative.com/
Description: Keep track of the current versions of themes and plugins installed on a WordPress site. This plugin should <strong>ALWAYS be Active</strong> unless you are having an issue where this plugin is the problem. If you are having issues with this plugin, please contact Cornershop Creative's support. If you are no longer a client of Cornershop Creative, this plugin is no longer required. You can deactivate and delete this plugin.
Version: 1.2.0
Text Domain: cshp-pt
Author: Cornershop Creative, De'Yonté Wilkinson
Author URI: https://cornershopcreative.com/
License: None
Requires PHP: 7.3.0
 * Disclaimer: This plugin is a closed-source proprietary software product.
 * It is not released under the GPL and is subject to copyright laws.
 * Redistribution, modification, or reverse engineering is strictly prohibited.
 * This plugin is provided "as is," without warranty of any kind, and comes with no support.
*/
namespace Cshp\Plugin\Tracker;

// exit if not loading in WordPress context but don't exit if running our PHPUnit tests
if ( ! defined( 'ABSPATH' ) && ! defined( 'CSHP_PHPUNIT_TESTS_RUNNING' ) ) {
	exit;
}

if ( ! function_exists( '\get_plugins' ) ||
	! function_exists( '\get_plugin_data' ) ||
	! function_exists( '\plugin_dir_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/**
 * Create a Trait to share common functions and settings among classes that enhance the plugin.
 *
 * Provides utility methods for managing plugin information, creating plugin-specific directories,
 * and checking user authorization based on email addresses.
 */
trait Share {
	/**
	 * The text domain used for this plugin.
	 * @var string
	 */
	private $text_domain = 'cshp-pt';
	/**
	 * The plugin slug that is used to identify this plugin.
	 * @var string
	 */
	private $plugin_slug = 'cshp-plugin-tracker';

	/**
	 * Get the plugin's textdomain so that we don't have to remember when defining strings for display.
	 *
	 * @return string Text domain for plugin.
	 */
	public function get_text_domain() {
		$this_plugin = get_plugin_data( __FILE__, false );
		return $this_plugin['TextDomain'] ?? $this->text_domain;
	}

	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

	/**
	 * Get the plugin's version from the Plugin info docblock so that we don't have to update this in multiple places
	 * when the version number is updated.
	 *
	 * @return int Version of the plugin.
	 */
	public function get_version() {
		$this_plugin = get_plugin_data( __FILE__, false );
		return $this_plugin['Version'] ?? '1.0.0';
	}

	/**
	 * Retrieve the folder path of the current plugin.
	 *
	 * @return string The directory path of the plugin.
	 */
	public function get_this_plugin_folder_path() {
		return __DIR__;
	}

	/**
	 * Get the name of this plugin folder
	 *
	 * @return string Plugin folder name of this plugin.
	 */
	public function get_this_plugin_folder_name() {
		return basename( __DIR__ );
	}

	/**
	 * Get the main plugin file.
	 *
	 * @return string Path to the main plugin file. This needs to be placed directly in the main plugin file.
	 */
	public function get_this_plugin_file() {
		return __FILE__;
	}

	/**
	 * Create a folder in the uploads directory so that all the files we create are contained.
	 *
	 * @return string|\WP_Error Path to the uploads folder of the plugin, WordPress error if the plugin uploads
	 * folder cannot be created.
	 */
	public function create_plugin_uploads_folder() {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			return;
		}

		$folder_path = sprintf( '%s/cshp-plugin-tracker', wp_upload_dir()['basedir'] );

		if ( ! is_dir( $folder_path ) ) {
			$test = $wp_filesystem->mkdir( $folder_path );

			if ( ! empty( $test ) && ! is_wp_error( $test ) ) {
				return $folder_path;
			}
		}

		return $folder_path;
	}

	/**
	 * Determine if the currently logged-in user has an authorized email address
	 *
	 * @return bool True if the user has an authorized email address. False if the user is not using a authorized email.
	 */
	public function is_authorized_user() {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$user = wp_get_current_user();
			// Cornershop user?
			if ( $this->utilities->str_ends_with( $user->user_email, '@cshp.co' ) || $this->utilities->str_ends_with( $user->user_email, '@cornershopcreative.com.co' ) || $this->utilities->str_ends_with( $user->user_email, '@deyonte.com' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the name of a plugin's folder name using the key that get_plugins() returns.
	 *
	 * The get_plugins() function returns the plugin information key by the plugin folder name + the plugin's main file.
	 * We need to get the name of the plugin's folder or just the main file if the plugin sits at the root of the
	 * plugins folder as a single file.
	 *
	 * @param string $plugin_folder_file_name The plugin's folder name and main plugin file.
	 *
	 * @return string Name of the plugin's folder without the main plugin file or the name of just the main plugin file.
	 */
	public function extract_plugin_folder_name_by_plugin_file_name( $plugin_folder_file_name ) {
		$plugin_folder_name = dirname( $plugin_folder_file_name );

		// if we could not extract the plugin directory name from the key,
		// assume that the plugin is a single file installed at the plugins folder root
		// (e.g. hello.php for the hello dolly plugin)
		if ( empty( $plugin_folder_name ) || '.' === $plugin_folder_name ) {
			$plugin_folder_name = $plugin_folder_file_name;
		}

		return $plugin_folder_name;
	}
}

// load the libraries installed with composer
require_once __DIR__ . '/vendor/autoload.php';

// don't run this initialization code when running Unit testing
if ( ! defined( 'CSHP_PHPUNIT_TESTS_RUNNING' ) ) {
	// use a dependency injection container to load our dependencies instead of explicitly adding calling each classes constructor to load the dependencies
	$cshp_plugin_tracker_container = new \League\Container\Container();
	$cshp_plugin_tracker_container->delegate(
		new \League\Container\ReflectionContainer()
	);

	// Load the utilities class that handles methods that are used throughout the plugin
	// and all Cornershop plugins. These methods are not specific to this plugin only.
	$cshp_plugin_tracker_utilities = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\Utilities::class );

	// Main class responsible for the core functionality.
	// Call methods of the main class to load hooks.
	$cshp_plugin_tracker = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\Plugin_Tracker::class );
	$cshp_plugin_tracker->load_non_composer_libraries();
	$cshp_plugin_tracker->register_activation_hook( __FILE__ );
	$cshp_plugin_tracker->register_uninstall_hook( __FILE__ );
	$cshp_plugin_tracker->hooks();

	// Handle updating the plugin from a private repo since this plugin will not be hosted on wordpress.org
	$cshp_plugin_tracker_updater = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\Updater::class );
	$cshp_plugin_tracker_updater->hooks();

	// load WP-CLI commands only if we detect we are in a WP-CLI environment.
	if ( $cshp_plugin_tracker_utilities->is_wp_cli_environment() ) {
		$cshp_plugin_tracker_wp_cli = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\WP_CLI::class );
		$cshp_plugin_tracker_wp_cli->hooks();
		$cshp_plugin_tracker_wp_cli->commands();
	}

	// Load admin class that creates settings in the backend.
	// Call methods of the admin class to load hooks.
	$cshp_plugin_tracker_admin = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\Admin::class );
	$cshp_plugin_tracker_admin->set_plugin_tracker( $cshp_plugin_tracker );
	try {
		$cshp_plugin_tracker_admin->admin_hooks();
	} catch ( \Exception $e ) {
		$error_message = $e->getMessage();
	}

	// Load the class the handles logging interactions with this plugin, how many times plugins are downloaded, what themes are downloaded
	$cshp_plugin_tracker_logger = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\Logger::class );
	$cshp_plugin_tracker_logger->hooks();
	$cshp_plugin_tracker_logger->set_admin_instance( $cshp_plugin_tracker_admin );

	// Load the class that stores the zip archives that are generated.
	$cshp_plugin_tracker_archive = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\Archive::class );
	$cshp_plugin_tracker_archive->hooks();

	// Load the class handles backing up the premium plugins to the plugin recovery site.
	$cshp_plugin_tracker_backup = $cshp_plugin_tracker_container->get( \Cshp\Plugin\Tracker\Backup::class );
	$cshp_plugin_tracker_backup->set_plugin_tracker( $cshp_plugin_tracker );
	try {
		$cshp_plugin_tracker_backup->hooks();
	} catch ( \Exception $e ) {
		$error_message = $e->getMessage();
	}
}
