<?php
/*
Plugin Name: Cornershop Premium Plugins
Plugin URI: https://cornershopcreative.com/
Description: Try to load the main plugin files from the premium plugins that were installed on client's site and shipped as part of the Cornershop Plugin Tracker Zip file.
Version: 1.0.1
Text Domain: cshp-pt-premium
Author: Cornershop Creative
Author URI: https://cornershopcreative.com/
License: GPLv2 or later
Requires PHP: 7.0
*/
namespace Cshp\pt\premium;

/**
 * On plugin activation, move all the stored premium plugin folders to the plugins directory and delete this plugin.
 *
 * @return void
 */
function activation_hook() {
	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;
	$premium_plugins_install_path = get_plugin_folders_path() . get_this_plugin_folder();
	$error_messages = [];
	$new_plugins_installed = [];

	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $premium_plugins_install_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::SELF_FIRST
	);
	$iterator->setMaxDepth( 0 );

	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			$new_plugin_folder_path = sprintf( '%s/%s', $premium_plugins_install_path, basename( $file->getRealpath() ) );
			$premium_plugin_folder_name = basename( $file->getRealpath() );
			$new_plugin_install_path = get_plugin_folders_path() . $premium_plugin_folder_name;
			try {

				// skip the directory if it already exists
				if ( is_dir( $new_plugin_install_path ) ) {
					continue;
				}

				$result = $wp_filesystem->move( $file->getRealpath(), $new_plugin_install_path, true );

				if ( is_dir( $new_plugin_install_path ) || true === $result ) {
					$new_plugins_installed[] = $premium_plugin_folder_name;
				} else {
					throw new \TypeError( __( 'Could not move plugin folder', 'cshp_premium_plugin' ) );
				}
			} catch ( \TypeError $error ) {
				$error_messages[] = esc_html__( sprintf( 'Could not move the premium plugin folder %s to the WordPress plugins folder. Please make sure the plugins folder is readable and writeable. Your Filsystem may be in FTP mode.', basename( $file->getRealpath() ) ), 'cshp_premium_plugin' );
			}
		}//end if
	}//end foreach

	// ideally we would activate the premium plugins next but the way that WordPress does
	// plugin activations is that it sandboxes the plugin so it doesn't bring the site down
	// if there is an issue activating a plugin, so you cannot activate additional plugins
	// when a different plugin is activated, so we have to do it later.

}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activation_hook' );

function post_activate() {
	// don't fire the activation hook if we are in the plugin sandbox
	if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
		return;
	}

	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;
	$active_plugins = get_active_plugins();
	$premium_plugins_install_path = get_plugin_folders_path() . get_this_plugin_folder();
	$premium_plugins_install_relative_path = get_this_plugin_folder() . '/' . get_this_plugin_file();
	$composer_file_path = sprintf( '%s/composer.json', $premium_plugins_install_path );
	$composer_file = wp_json_file_decode( $composer_file_path, [ 'associative' => true ] );
	$premium_plugins_to_activate = [];
	$subfolders_in_this_directory = [];
	$error_message = '';
	wp_cache_delete( 'plugins', 'plugins' );
	$all_plugins_by_file_name = array_keys( get_plugins() );

	if ( ! empty( $composer_file ) ) {
		foreach ( $composer_file['require'] as $plugin_name => $version ) {
			$clean_plugin_folder_name = str_replace( 'premium-plugin/', '', str_replace( 'wpackagist-plugin/', '', $plugin_name ) );

			if ( false !== strpos( $plugin_name, 'premium-plugin' ) ) {
				$premium_plugins_to_activate[] = $clean_plugin_folder_name;
			}
		}
	}

	// make sure items are sorted so we can match the plugins by their folder name
	// when determining which plugins to activate
	sort( $premium_plugins_to_activate );
	sort( $all_plugins_by_file_name );

	foreach ( $premium_plugins_to_activate as $plugin_folder_name ) {
		$find_main_plugin_file = '';
		// find the main plugin file using the name of the plugin folder
		foreach ( $all_plugins_by_file_name as $index => $plugin_file_name ) {
			// make sure that we aren't trying to activate plugins that are still in this folder.
			// this can occur if we are installing the plugin through WP admin by uploading the plugin
			// and then immediately activating the plugin after successful plugin installation
			if ( false !== strpos( $plugin_file_name, $plugin_folder_name ) &&
			     false === strpos( $plugin_file_name, get_this_plugin_folder() ) ) {
				$find_main_plugin_file = $plugin_file_name;
				break;
			}
		}

		// if we have found the main plugin file of the premium plugin, then add it to the list of plugins to activate
		if ( ! empty( $find_main_plugin_file ) && ! in_array( $plugin_file_name, $active_plugins, true ) ) {
			$plugin_files_to_load[] = $plugin_file_name;
		}
	}

	// check to see if there are any premium plugins remaining in this premium plugin folder
	foreach ( glob( $premium_plugins_install_path.'/*', GLOB_ONLYDIR|GLOB_NOSORT ) as $dir ) {
		// don't count this plugin's directory as a subfolder
		if ( $premium_plugins_install_path === $dir ) {
			continue;
		}

		$subfolders_in_this_directory[] = basename( $dir );
	}

	if ( ! empty( $plugin_files_to_load ) ) {
		activate_plugins( $plugin_files_to_load, '' );
	}

	// if there are no premium plugins left in this folder (i.e. all have been moved up to the main plugin folder), then delete this plugin
	if ( empty( $subfolders_in_this_directory ) ) {
		deactivate_plugins( $premium_plugins_install_relative_path, true );
		try {
			$result = $wp_filesystem->delete( $premium_plugins_install_path , true, 'd' );
			if ( true !== $result ) {
				throw new \TypeError( __( 'Could not move plugin folder', 'cshp_premium_plugin' ) );
			}
		} catch ( \TypeError $error ) {
			$error_message = esc_html__( sprintf( 'Could not delete the premium plugin zip folder %s from the WordPress plugins folder. Please make sure the plugins folder is readable and writeable. Your Filesystem may be in FTP mode.', $premium_plugins_install_path ), 'cshp_premium_plugin' );
		}
	} else {
		$error_message = sprintf( __( 'Could not copy the following plugins from the Cornershop Premium Plugins folder to the WordPress plugins folder: %s. Please make sure the plugins folder is readable and writeable. Your Filesystem may be in FTP mode.', 'cshp_premium_plugin' ), implode( ', ', $subfolders_in_this_directory ) );
	}

	// display the error to the user if the plugin cannot delete itself or if there are some premium plugins that are still subfolders
	if ( ! empty( $error_message ) ) {
		add_action( 'admin_notices', function() use ( $error_message ) {
			echo sprintf( '<div class="notice notice-error is-dismissible">%s</div>', $error_message );
		} );
	}
}
add_action( 'init', __NAMESPACE__ . '\post_activate' );
add_action( 'admin_init', __NAMESPACE__ . '\post_activate' );

/**
 * Get the list of active plugins on the site
 *
 * @return array List of plugin activation files (e.g. plugin-folder/plugin-main-file.php).
 */
function get_active_plugins() {
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
 * Get the full path to a wp-content/plugins folder.
 *
 * WordPress has no built-in way to get the full path to a plugins folder.
 *
 * @return string Absolute path to a wp-content/plugins folder.
 */
function get_plugin_folders_path() {
	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;
	// have to use WP_PLUGIN_DIR even though we should not use the constant directly
	$plugin_directory = WP_PLUGIN_DIR;

	// add a try catch in case the WordPress site is in FTP mode
	// $wp_filesytem->wp_plugins_dir throws errors on FTP FS https://github.com/pods-framework/pods/issues/6242
	try {
		if ( ! empty( $wp_filesystem ) &&
		     is_callable( [ $wp_filesystem, 'wp_plugins_dir' ] ) &&
		     ! empty( $wp_filesystem->wp_plugins_dir() ) ) {
			$plugin_directory = $wp_filesystem->wp_plugins_dir();
		}
	} catch ( \TypeError $error ) {
	}

	return $plugin_directory;
}

/**
 * Get the name of this plugin folder
 *
 * @return string Plugin folder name of this plugin.
 */
function get_this_plugin_folder() {
	return basename( dirname( __FILE__ ) );
}

/**
 * Get the name of this plugin file.
 *
 * @return string Plugin file name with the full path to the file
 */
function get_this_plugin_file() {
	return basename( __FILE__ );
}
