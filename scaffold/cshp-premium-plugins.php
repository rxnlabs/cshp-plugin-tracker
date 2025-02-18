<?php
/*
Plugin Name: Cornershop Premium Plugins
Plugin URI: https://cornershopcreative.com/
Description: Try to load the main plugin files from the premium plugins that were installed on client's site and shipped as part of the Cornershop Plugin Tracker Zip file.
Version: 1.1.0
Text Domain: cshp-pt-premium
Author: Cornershop Creative, De'Yonté Wilkinson
Author URI: https://cornershopcreative.com/
License: GPLv2 or later
Requires PHP: 7.0
*/
namespace Cshp\Plugin\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class the handles moving all the premium plugins from the unzip premium plugins zip archive folder to the root of the plugins.
 */
class Premium_Plugins {
	/**
	 * Activate tegistration hooks.
	 */
	public function __construct() {
		register_activation_hook(
			__FILE__,
			function () {
				$this->activation_hook();
			}
		);
	}

	/**
	 * Do the post activation hooks so that on page reload, folders are moved and other
	 * cleanup actions.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'post_activate' ) );
	}

	/**
	 * Do the post activation hooks so that on page reload, folders are moved and other
	 * cleanup actions.
	 *
	 * @return void
	 */
	public function admin_hooks() {
		add_action( 'admin_init', array( $this, 'post_activate' ) );
	}

	/**
	 * On plugin activation, move all the stored premium plugin folders to the plugins directory and delete this plugin.
	 *
	 * @param string $override_premium_plugin_install_path Update the path to the premium plugins folder.
	 *
	 * @return void
	 */
	public function activation_hook( $override_premium_plugin_install_path = '' ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		if ( ! empty( $override_premium_plugin_install_path ) && is_dir( $override_premium_plugin_install_path ) ) {
			$premium_plugins_install_path = $override_premium_plugin_install_path;
		} else {
			$premium_plugins_install_path = $this->get_plugin_folders_path() . $this->get_this_plugin_folder();
		}

		$error_messages        = array();
		$new_plugins_installed = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $premium_plugins_install_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$iterator->setMaxDepth( 0 );

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				$new_plugin_folder_path     = sprintf( '%s/%s', $premium_plugins_install_path, basename( $file->getRealpath() ) );
				$premium_plugin_folder_name = basename( $file->getRealpath() );
				$new_plugin_install_path    = $this->get_plugin_folders_path() . $premium_plugin_folder_name;
				try {

					// by default, this plugin will overwrite the currently installed plugin with whatever version of the plugin is included in this backup
					if ( is_dir( $new_plugin_install_path ) ) {
						$wp_filesystem->delete( $new_plugin_install_path, true, 'd' );
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

	/**
	 * After activation when the plugins page is reloaded, delete this plugin folder so it uninstalls itself.
	 *
	 * This method ensures that premium plugins are detected, activated, or skipped
	 * as necessary after the activation process of the current plugin. It also ensures
	 * cleanup of temporary files and handles related error messages.
	 *
	 * @param  string  $override_premium_plugin_install_path  Optional custom path to locate premium plugins for activation.
	 *
	 * @return void
	 */
	public function post_activate( $override_premium_plugin_install_path = '' ) {
		// don't fire the activation hook if we are in the plugin sandbox
		if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
			return;
		}

		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		$active_plugins = $this->get_active_plugins();
		if ( ! empty( $override_premium_plugin_install_path ) && is_dir( $override_premium_plugin_install_path ) ) {
			$premium_plugins_install_path          = $override_premium_plugin_install_path;
			$premium_plugins_install_relative_path = str_replace( $this->get_plugin_folders_path(), '', $premium_plugins_install_path );
		} else {
			$premium_plugins_install_path          = $this->get_plugin_folders_path() . $this->get_this_plugin_folder();
			$premium_plugins_install_relative_path = $this->get_this_plugin_folder() . '/' . $this->get_this_plugin_file();
		}

		$composer_file_path           = sprintf( '%s/composer.json', $premium_plugins_install_path );
		$composer_file                = wp_json_file_decode( $composer_file_path, array( 'associative' => true ) );
		$premium_plugins_to_activate  = array();
		$subfolders_in_this_directory = array();
		$error_message                = '';
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
					false === strpos( $plugin_file_name, $this->get_this_plugin_folder() ) ) {
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
		foreach ( glob( $premium_plugins_install_path . '/*', GLOB_ONLYDIR | GLOB_NOSORT ) as $dir ) {
			// don't count this plugin's directory as a subfolder
			if ( $premium_plugins_install_path === $dir ) {
				continue;
			}

			$subfolders_in_this_directory[] = basename( $dir );
		}

		if ( ! empty( $plugin_files_to_load ) ) {
			activate_plugins( $plugin_files_to_load, '' );
		}

		deactivate_plugins( $premium_plugins_install_relative_path, true );

		try {
			$result = $wp_filesystem->delete( $premium_plugins_install_path, true, 'd' );
			if ( true !== $result ) {
				throw new \TypeError( __( 'Could not move plugin folder', 'cshp_premium_plugin' ) );
			}
		} catch ( \TypeError $error ) {
			$error_message = esc_html__( sprintf( 'Could not delete the premium plugin zip folder %s from the WordPress plugins folder. Please make sure the plugins folder is readable and writeable. Your Filesystem may be in FTP mode.', $premium_plugins_install_path ), 'cshp_premium_plugin' );
		}

		if ( ! empty( $subfolders_in_this_directory ) ) {
			update_option( 'cshp_pt_premium_skipped_plugins', wp_json_encode( $subfolders_in_this_directory ) );
		}

		if ( ! empty( $error_message ) ) {
			add_action(
				'admin_notices',
				function () use ( $error_message ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					printf( '<div class="notice notice-error is-dismissible">%s</div>', esc_html( $error_message ) );
				}
			);
		}
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
	 * Get the name of this plugin folder
	 *
	 * @return string Plugin folder name of this plugin.
	 */
	public function get_this_plugin_folder() {
		return basename( __DIR__ );
	}

	/**
	 * Get the name of this plugin file.
	 *
	 * @return string Plugin file name with the full path to the file
	 */
	public function get_this_plugin_file() {
		return basename( __FILE__ );
	}
}

$cshp_premium_plugins = new \Cshp\Plugin\Tracker\Premium_Plugins();
$cshp_premium_plugins->hooks();
$cshp_premium_plugins->admin_hooks();
