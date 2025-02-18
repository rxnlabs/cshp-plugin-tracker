<?php
/**
 * Functions used to update this plugin from an external website.
 */
declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Updater {
	use Share;

	/**
	 * URL used for updating private plugins created by Cornershop Creative.
	 *
	 * @var string
	 */
	private $update_url = 'https://plugins.cornershopcreative.com';
	/**
	 * Utility instance providing various helper methods and functionalities.
	 *
	 * @var Utility
	 */
	private $utilities;

	/**
	 * Constructor method to initialize the class with the required utilities.
	 *
	 * @param Utilities $utilities The utilities instance required for class operations.
	 *
	 * @return void
	 */
	public function __construct( Utilities $utilities ) {
		$this->utilities = $utilities;
	}

	/**
	 * Register hooks used by the methods.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'plugin_update_checker' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clean_old_options' ), 10, 2 );
		add_filter( 'http_request_reject_unsafe_urls', array( $this, 'add_plugin_update_url_to_safe_url_list' ), 99, 2 );
	}

	/**
	 * Get the URL where we can get the plugin updates since this plugin is not hosted on wordpress.org.
	 *
	 * @return string URL to ping to get plugin updates.
	 */
	public function get_plugin_update_url() {
		return $this->update_url;
	}

	/**
	 * Initialize a way for the plugin to be updated since it will not be hosted on wordpress.org.
	 *
	 * @return void
	 */
	public function plugin_update_checker() {
		$update_url       = sprintf( '%s/wp-json/cshp-plugin-updater/%s', $this->get_plugin_update_url(), $this->get_this_plugin_folder_name() );
		$plugin_file_path = $this->utilities->get_plugin_file_full_path( sprintf( '%s/%s.php', $this->get_this_plugin_folder_name(), $this->get_plugin_slug() ) );

		// make sure the update will not be blocked before trying to update it
		if ( ! $this->utilities->is_external_domain_blocked( $update_url ) && class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				$update_url,
				$plugin_file_path,
				$this->get_this_plugin_folder_name()
			);
		}
	}

	/**
	 * Add this plugin's update url to the list of safe URLs that can be used with remote requests.
	 *
	 * Useful if calling wp_safe_remote_get. Ensures that the plugin update URL is considered a safe URL to help avoid forced redirections.
	 *
	 * @param bool   $default_return_value False if the URL is not considered safe, true if the URL is considered safe. By default, all URLs are considered safe.
	 * @param string $url External URL that is being requested.
	 *
	 * @return bool True if the external URL is considered safe, False, if the external URL is not considered safe.
	 */
	public function add_plugin_update_url_to_safe_url_list( $default_return_value, $url ) {
		if ( $this->get_plugin_update_url() === $url ) {
			return true;
		}

		return $default_return_value;
	}

	/**
	 * Clean up old options from this plugin for version 1.0.32 and earlier.
	 *
	 * @param \WP_Upgrader $wp_upgrader WordPress upgrader class with context.
	 * @param array        $upgrade_data Additional information about what was updated.
	 *
	 * @return void
	 */
	public function clean_old_options( $wp_upgrader, $upgrade_data ) {
		$is_maybe_bulk_upgrade = false;

		if ( isset( $upgrade_data['bulk'] ) && true === $upgrade_data['bulk'] ) {
			$is_maybe_bulk_upgrade = true;
		}

		if ( isset( $upgrade_data['type'] ) && 'plugin' === $upgrade_data['type'] && isset( $upgrade_data['plugins'] ) ) {
			foreach ( $upgrade_data['plugins'] as $plugin ) {
				// Check to ensure it's my plugin
				if ( $plugin === $this->get_this_plugin_folder_name() ) {
					$find_old_zip_plugin_file = get_option( 'cshp_plugin_tracker_plugin_zip' );

					// clear out the old plugins zip file that was stored in this option
					if ( ! empty( $find_old_zip_plugin_file ) ) {
						$old_plugin_zip_file_path = sprintf( '%s/%s', $this->create_plugin_uploads_folder(), $find_old_zip_plugin_file );

						if ( file_exists( $old_plugin_zip_file_path ) ) {
							wp_delete_file( $old_plugin_zip_file_path );
						}
					}

					// since we can create multiple zip files with different plugins in them instead of a single zip file, we should delete these old options
					delete_option( 'cshp_plugin_tracker_plugin_zip' );
					delete_option( 'cshp_plugin_tracker_plugin_zip_contents' );
				}
			}
		}//end if
	}
}
