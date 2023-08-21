<?php
/*
Plugin Name: Cornershop Premium Plugins
Plugin URI: https://cornershopcreative.com/
Description: Try to load the main plugin files from the premium plugins that were installed on client's site and shipped as part of the Cornershop Plugin Tracker Zip file.
Version: 1.0.0
Text Domain: cshp-pt-premium
Author: Cornershop Creative
Author URI: https://cornershopcreative.com/
License: GPLv2 or later
Requires PHP: 7.0
*/
namespace Cshp\pt\premium;

/**
 * Load the main plugin file from all the premium plugins that are in this folder.
 *
 * WARNING: Could lead to fatal errors. Media Deduper Pro assumes that the plugin is installed in the
 * root /plugins folder.It creates a constant named MDD_PRO_PATH and MDD_PRO_INCLUDES_DIR that both assume that the
 * plugin main file is installed in the root /plugins folder.
 *
 * @return void
 */
function load_premium_plugins() {
	$current_folder = basename( __DIR__ );
	$active_plugins = get_option( 'active_plugins' );
	$network_plugins = [];
	$plugin_files_to_load = [];
	if ( is_multisite() ) {
		$network_plugins = get_site_option( 'active_sitewide_plugins' );
		$active_plugins = array_merge( $active_plugins, $network_plugins );
	}

	/**
	 * Load the main PHP file
	 */
	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( __DIR__, \RecursiveDirectoryIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::SELF_FIRST );
	$iterator->setMaxDepth( 0 );

	foreach( $iterator as $file ) {
		if ( $file->isDir() ) {
			$plugin_relative_path = sprintf( '/%s/%s', $current_folder, basename( $file->getRealpath() ) );
			$find_main_plugin_file = get_plugins( $plugin_relative_path );

			// if we have found the main plugin file of the premium plugin, then add it to the list of active plugins
			if ( ! empty( $find_main_plugin_file ) ) {
				//
				$plugin_file_name = array_keys( $find_main_plugin_file )[0];
				$plugin_file_without_current_folder = str_replace( $current_folder . '/', '', $plugin_file_name );

				// if the plugin is not already active, then add to the list
				if ( ! in_array( $plugin_file_name, $active_plugins, true ) ) {
					$plugin_files_to_load[] = sprintf( '%s/%s/%s', __DIR__, basename( $file->getRealpath() ), $plugin_file_name );
				}
			}
		}
	}

	if ( ! empty( $plugin_files_to_load ) ) {
		foreach ( $plugin_files_to_load as $plugin_file_path ) {
			include_once $plugin_file_path;
		}
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\load_premium_plugins' );