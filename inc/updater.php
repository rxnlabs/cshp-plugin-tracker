<?php
/**
 * Functions used to update this plugin from an external website.
 */

namespace Cshp\pt;

/**
 * Initialize a way for the plugin to be updated since it will not be hosted on wordpress.org.
 *
 * @return void
 */
function plugin_update_checker() {
	$update_url       = sprintf( '%s/wp-json/cshp-plugin-updater/%s', get_plugin_update_url(), get_this_plugin_folder() );
	$plugin_file_path = get_plugin_file_full_path( sprintf( '%s/%s.php', get_this_plugin_folder(), get_this_plugin_slug() ) );

	// make sure the update will not be blocked before trying to update it
	if ( ! is_external_domain_blocked( $update_url ) && class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$update_url,
			$plugin_file_path,
			get_this_plugin_folder()
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\plugin_update_checker' );

/**
 * Get the URL where we can get the plugin updates since this plugin is not hosted on wordpress.org.
 *
 * @return string URL to ping to get plugin updates.
 */
function get_plugin_update_url() {
	return 'https://plugins.cornershopcreative.com';
}

/**
 * Clean up old options from this plugin for version 1.0.32 and earlier.
 *
 * @param \WP_Upgrader $wp_upgrader WordPress upgrader class with context.
 * @param array        $upgrade_data Additional information about what was updated.
 *
 * @return void
 */
function clean_old_options( $wp_upgrader, $upgrade_data ) {
	$is_maybe_bulk_upgrade = false;

	if ( isset( $upgrade_data['bulk'] ) && true === $upgrade_data['bulk'] ) {
		$is_maybe_bulk_upgrade = true;
	}

	if ( isset( $upgrade_data['type'] ) && 'plugin' === $upgrade_data['type'] && isset( $upgrade_data['plugins'] ) ) {
		foreach ( $upgrade_data['plugins'] as $plugin ) {
			// Check to ensure it's my plugin
			if ( $plugin == get_this_plugin_folder() ) {
				$find_old_zip_plugin_file = get_option( 'cshp_plugin_tracker_plugin_zip' );

				// clear out the old plugins zip file that was stored in this option
				if ( ! empty( $find_old_zip_plugin_file ) ) {
					$old_plugin_zip_file_path = sprintf( '%s/%s', create_plugin_uploads_folder(), $find_old_zip_plugin_file );

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
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\clean_old_options', 10, 2 );
