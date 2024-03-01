<?php
/*
Plugin Name: Cornershop Plugin Tracker
Plugin URI: https://cornershopcreative.com/
Description: Keep track of the current versions of themes and plugins installed on a WordPress site. This plugin should <strong>ALWAYS be Active</strong> unless you are having an issue where this plugin is the problem. If you are having issues with this plugin, please contact Cornershop Creative's support. If you are no longer a client of Cornershop Creative, this plugin is no longer required. You can deactivate and delete this plugin.
Version: 1.1.1
Text Domain: cshp-pt
Author: Cornershop Creative
Author URI: https://cornershopcreative.com/
License: GPLv2 or later
Requires PHP: 7.3.0
*/
namespace Cshp\pt;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

if ( ! function_exists( '\get_plugins' ) ||
	 ! function_exists( '\get_plugin_data' ) ||
	 ! function_exists( '\plugin_dir_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// load the libraries installed with composer
require 'composer-vendor/autoload.php';
// load libraries not installed with composer
load_non_composer_libraries();
// include file with functions needed to update this plugin
require_once 'inc/updater.php';
// include file with plugins agency licenses
require_once 'inc/license.php';
// include file with list of premium plugins and themes
require_once 'inc/premium-list.php';
// include the file that handles backing up the premium plugins
require_once 'inc/backup.php';
// include file with utility functions
require_once 'inc/utilities.php';
// include the admin UI for controlling the plugin settings
require_once 'inc/admin.php';

// load the WP CLI commands
if ( is_wp_cli_environment() ) {
	require_once 'inc/wp-cli.php';
}

/**
 * Load the libraries that we did not install with Composer
 *
 * @return void
 */
function load_non_composer_libraries() {
	$vendor_path = sprintf( '%s/non-composer-vendor', plugin_dir_path( __FILE__ ) );
	// explicitly include the Action Scheduler plugin instead of installing the library with Composer
	if ( ! function_exists( '\as_schedule_cron_action' ) ) {
		require_once sprintf( '%s/action-scheduler/action-scheduler.php', $vendor_path );
	}

	require_once sprintf( '%s/markdown-table.php', $vendor_path );
}

/**
 * Register plugin activation hook
 *
 * @return void
 */
function activation_hook() {
	// flush rewrite rules so the custom rewrite endpoint is created
	flush_rewrite_rules();
	// create a folder in the uploads directory to hold the plugin files
	create_plugin_uploads_folder();
	// trigger cron job to save a composer.json file to keep track of the installed plugins
	update_plugin_tracker_file_post_bulk_update();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activation_hook' );

/**
 * Register plugin deactivation hook.
 *
 * @return void
 */
function deactivation_hook() {
	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;

	if ( empty( $wp_filesystem ) ) {
		return;
	}

	// delete this plugin's directory that is in the uploads directory so we don't leave any files behind.
	if ( ! is_wp_error( create_plugin_uploads_folder() ) ) {
		$wp_filesystem->delete( create_plugin_uploads_folder(), true );
	}
}
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\deactivation_hook' );

/**
 * Flush the uploads directory composer.json file after WordPress core, themes, or plugins are updated.
 *
 * @param \WP_Upgrader $wp_upgrader WordPress upgrader class with context.
 * @param array        $upgrade_data Additional information about what was updated.
 *
 * @return void
 */
function flush_composer_post_update( $wp_upgrader, $upgrade_data ) {
	$is_maybe_bulk_upgrade = false;

	if ( isset( $upgrade_data['bulk'] ) && true === $upgrade_data['bulk'] ) {
		$is_maybe_bulk_upgrade = true;
	}

	if ( isset( $upgrade_data['type'] ) &&
		 in_array( strtolower( $upgrade_data['type'] ), [ 'plugin', 'theme', 'core' ], true ) ) {
		// set a cron job if we are doing bulk upgrades of plugins or themes or if we are using WP CLI
		if ( is_wp_cli_environment() || true === $is_maybe_bulk_upgrade ) {
			update_plugin_tracker_file_post_bulk_update();
			delete_old_archive_posts();
		} elseif ( should_real_time_update() ) {
			create_plugin_tracker_file();
			delete_old_archive_posts();
		}
	}
}
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\flush_composer_post_update', 10, 2 );

function flush_archived_zip_post_update( $wp_upgrader, $upgrade_data ) {
	$is_maybe_bulk_upgrade = false;

	if ( isset( $upgrade_data['bulk'] ) && true === $upgrade_data['bulk'] ) {
		$is_maybe_bulk_upgrade = true;
	}

	if ( isset( $upgrade_data['type'] ) &&
		 in_array( strtolower( $upgrade_data['type'] ), [ 'plugin', 'theme', 'core' ], true ) ) {
		// set a cron job if we are doing bulk upgrades of plugins or themes or if we are using WP CLI
		if ( is_wp_cli_environment() || true === $is_maybe_bulk_upgrade ) {
			update_plugin_tracker_file_post_bulk_update();
		} elseif ( should_real_time_update() ) {
			create_plugin_tracker_file();
		}
	}
}
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\flush_archived_zip_post_update', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a plugin is activated.
 *
 * @param string $plugin Path to the main plugin file that was activated (path is relative to the plugins directory).
 * @param bool   $network_wide Whether the plugin was enabled on all sites in a multisite.
 *
 * @return void
 */
function flush_composer_post_activate_plugin( $plugin, $network_wide ) {
	$plugin_data = get_plugin_data( get_plugin_file_full_path( $plugin ), false );
	$plugin_name = '';

	if ( ! empty( $plugin_data ) ) {
		$plugin_name = $plugin_data['Name'];
	}

	log_request( 'plugin_activate', $plugin_name );

	if ( is_wp_cli_environment() ) {
		update_plugin_tracker_file_post_bulk_update();
	} elseif ( should_real_time_update() ) {
		create_plugin_tracker_file();
	}
}
add_action( 'activated_plugin', __NAMESPACE__ . '\flush_composer_post_activate_plugin', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a plugin is deactivated.
 *
 * @param string $plugin Path to the main plugin file that was deactivated (path is relative to the plugins directory).
 * @param bool   $network_wide Whether the plugin was disabled on all sites in a multisite.
 *
 * @return void
 */
function flush_composer_post_deactivate_plugin( $plugin, $network_wide ) {
	$plugin_data = get_plugin_data( get_plugin_file_full_path( $plugin ), false );
	$plugin_name = '';

	if ( ! empty( $plugin_data ) ) {
		$plugin_name = $plugin_data['Name'];
	}

	log_request( 'plugin_deactivate', $plugin_name );

	if ( is_wp_cli_environment() ) {
		update_plugin_tracker_file_post_bulk_update();
	} elseif ( should_real_time_update() ) {
		create_plugin_tracker_file();
	}
}
add_action( 'deactivated_plugin', __NAMESPACE__ . '\flush_composer_post_deactivate_plugin', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a plugin is uninstalled
 *
 * @param string $plugin_relative_file Path to the plugin file relative to the plugins directory.
 * @param array  $uninstallable_plugins Uninstallable plugins.
 *
 * @return void
 */
function flush_composer_plugin_uninstall( $plugin_relative_file, $uninstallable_plugins ) {
	$file        = plugin_basename( $plugin_relative_file );
	$plugin_name = '';
	$plugin_data = get_plugin_data( get_plugin_file_full_path( $plugin_relative_file ), false );

	if ( ! empty( $plugin_data ) ) {
		$plugin_name = $plugin_data['Name'];
	}

	log_request( 'plugin_uninstall', $plugin_name );

	if ( is_wp_cli_environment() ) {
		update_plugin_tracker_file_post_bulk_update();
	} elseif ( should_real_time_update() ) {
		// run this when the plugin is successfully uninstalled
		$action_name = sprintf( 'uninstall_%s', $file );
		add_action(
			$action_name,
			function() {
				create_plugin_tracker_file();
			}
		);
	}
}
add_action( 'pre_uninstall_plugin', __NAMESPACE__ . '\flush_composer_plugin_uninstall', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a theme is activated.
 *
 * @param string    $old_theme_name Name of the old theme.
 * @param \WP_Theme $old_theme Theme object of the old theme.
 *
 * @return void
 */
function flush_composer_post_theme_switch( $old_theme_name, $old_theme ) {
	$current_theme = wp_get_theme();

	log_request( 'theme_deactivate', $old_theme_name );
	log_request( 'theme_activate', $current_theme->get( 'Name' ) );

	if ( is_wp_cli_environment() ) {
		update_plugin_tracker_file_post_bulk_update();
	} elseif ( should_real_time_update() ) {
		create_plugin_tracker_file();
	}
}
add_action( 'after_switch_theme', __NAMESPACE__ . '\flush_composer_post_theme_switch', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a theme is deleted
 *
 * @param string $stylesheet Name of the theme stylesheet that was just deleted.
 *
 * @return void
 */
function flush_composer_theme_delete( $stylesheet ) {
	$theme      = wp_get_theme( $stylesheet );
	$theme_name = '';

	if ( ! empty( $theme ) ) {
		$theme_name = $theme->get( 'Name' );
	}

	log_request( 'theme_delete', $theme_name );

	if ( is_wp_cli_environment() ) {
		update_plugin_tracker_file_post_bulk_update();
	} elseif ( should_real_time_update() ) {
		create_plugin_tracker_file();
	}
}
add_action( 'delete_theme', __NAMESPACE__ . '\flush_composer_theme_delete', 10, 1 );

/**
 * Add a cron job to regenerate the composer.json file
 *
 * Regenerate the composer.json file in case a new plugin is added that we did not catch or a public plugin or theme
 * is now considered "premium"
 *
 * @return void
 */
function add_cron_job() {
	if ( function_exists( '\as_schedule_cron_action' ) && ! as_has_scheduled_action( 'cshp_pt_regenerate_composer_daily', [], 'cshp_pt' ) ) {
		// schedule action scheduler to run once a day
		as_schedule_cron_action( strtotime( 'now' ), '0 2 * * *', 'cshp_pt_regenerate_composer_daily', [], 'cshp_pt' );
	} elseif ( ! wp_next_scheduled( 'cshp_pt_regenerate_composer_daily' ) ) {
		wp_schedule_event( strtotime( '02:00:00' ), 'daily', 'cshp_pt_regenerate_composer_daily' );
	}
}
add_action( 'init', __NAMESPACE__ . '\add_cron_job' );

/**
 * Try to determine if this plugin is being run via WP CLI
 *
 * @return bool True if WP CLI is running. False if WP CLI is not running or cannot be detected.
 */
function is_wp_cli_environment() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	return false;
}

/**
 * Determine if the currently logged-in user has a Cornershop Creative email address
 *
 * @return bool True if the user is a Cornershop employee. False if the user is not using a Cornershop email.
 */
function is_cornershop_user() {
	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		$user = wp_get_current_user();
		// Cornershop user?
		if ( '@cshp.co' === substr( $user->user_email, -8 ) || '@cornershopcreative.com' === substr( $user->user_email, -23 ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Register a post type for logging plugin tracker things.
 */
function create_log_post_type() {
	register_post_type(
		get_log_post_type(),
		[
			'labels'                => [
				'name'                  => __( 'Plugin Logs', get_textdomain() ),
				'singular_name'         => __( 'Plugin Log', get_textdomain() ),
				'all_items'             => __( 'All Plugin Logs', get_textdomain() ),
				'archives'              => __( 'Plugin Log Archives', get_textdomain() ),
				'attributes'            => __( 'Plugin Log Attributes', get_textdomain() ),
				'insert_into_item'      => __( 'Insert into Plugin Log', get_textdomain() ),
				'uploaded_to_this_item' => __( 'Uploaded to this Plugin Log', get_textdomain() ),
				'featured_image'        => _x( 'Featured Image', 'cshp_pt_log', get_textdomain() ),
				'set_featured_image'    => _x( 'Set featured image', 'cshp_pt_log', get_textdomain() ),
				'remove_featured_image' => _x( 'Remove featured image', 'cshp_pt_log', get_textdomain() ),
				'use_featured_image'    => _x( 'Use as featured image', 'cshp_pt_log', get_textdomain() ),
				'filter_items_list'     => __( 'Filter Plugin Logs list', get_textdomain() ),
				'items_list_navigation' => __( 'Plugin Logs list navigation', get_textdomain() ),
				'items_list'            => __( 'Plugin Logs list', get_textdomain() ),
				'new_item'              => __( 'New Plugin Log', get_textdomain() ),
				'add_new'               => __( 'Add New', get_textdomain() ),
				'add_new_item'          => __( 'Add New Plugin Log', get_textdomain() ),
				'edit_item'             => __( 'Edit Plugin Log', get_textdomain() ),
				'view_item'             => __( 'View Plugin Log', get_textdomain() ),
				'view_items'            => __( 'View Plugin Logs', get_textdomain() ),
				'search_items'          => __( 'Search Plugin Logs', get_textdomain() ),
				'not_found'             => __( 'No Plugin Logs found', get_textdomain() ),
				'not_found_in_trash'    => __( 'No Plugin Logs found in trash', get_textdomain() ),
				'parent_item_colon'     => __( 'Parent Plugin Log:', get_textdomain() ),
				'menu_name'             => __( 'Plugin Logs', get_textdomain() ),
			],
			'public'                => false,
			'hierarchical'          => false,
			'show_ui'               => false,
			'show_in_nav_menus'     => false,
			'supports'              => [ 'title', 'editor', 'custom-fields', 'author' ],
			'has_archive'           => false,
			'rewrite'               => true,
			'query_var'             => true,
			'menu_position'         => null,
			'menu_icon'             => 'dashicons-analytics',
			'taxonomy'              => [ get_log_taxonomy() ],
			'show_in_rest'          => is_cornershop_user(),
			'rest_base'             => get_log_post_type(),
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		]
	);

	register_taxonomy(
		get_log_taxonomy(),
		[ get_log_post_type() ],
		[
			'labels'                => [
				'name'                       => __( 'Log Types', get_textdomain() ),
				'singular_name'              => _x( 'Log Type', 'taxonomy general name', get_textdomain() ),
				'search_items'               => __( 'Search Log Types', get_textdomain() ),
				'popular_items'              => __( 'Popular Log Types', get_textdomain() ),
				'all_items'                  => __( 'All Log Types', get_textdomain() ),
				'parent_item'                => __( 'Parent Log Type', get_textdomain() ),
				'parent_item_colon'          => __( 'Parent Log Type:', get_textdomain() ),
				'edit_item'                  => __( 'Edit Log Type', get_textdomain() ),
				'update_item'                => __( 'Update Log Type', get_textdomain() ),
				'view_item'                  => __( 'View Log Type', get_textdomain() ),
				'add_new_item'               => __( 'Add New Log Type', get_textdomain() ),
				'new_item_name'              => __( 'New Log Type', get_textdomain() ),
				'separate_items_with_commas' => __( 'Separate Log Types with commas', get_textdomain() ),
				'add_or_remove_items'        => __( 'Add or remove Log Types', get_textdomain() ),
				'choose_from_most_used'      => __( 'Choose from the most used Log Types', get_textdomain() ),
				'not_found'                  => __( 'No Log Types found.', get_textdomain() ),
				'no_terms'                   => __( 'No Log Types', get_textdomain() ),
				'menu_name'                  => __( 'Log Types', get_textdomain() ),
				'items_list_navigation'      => __( 'Log Types list navigation', get_textdomain() ),
				'items_list'                 => __( 'Log Types list', get_textdomain() ),
				'most_used'                  => _x( 'Most Used', 'cshp_pt_log_type', get_textdomain() ),
				'back_to_items'              => __( '&larr; Back to Log Types', get_textdomain() ),
			],
			'hierarchical'          => false,
			'public'                => false,
			'show_in_nav_menus'     => true,
			'show_ui'               => true,
			'show_admin_column'     => true,
			'query_var'             => true,
			'rewrite'               => true,
			'capabilities'          => [
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'manage_options',
			],
			'show_in_rest'          => is_cornershop_user(),
			'rest_base'             => get_log_taxonomy(),
			'rest_controller_class' => 'WP_REST_Terms_Controller',
		]
	);

	generate_default_log_terms();
}
add_action( 'init', __NAMESPACE__ . '\create_log_post_type' );

/**
 * Register a post type for tracking the generated zip files.
 */
function create_archive_post_type() {
	register_post_type(
		get_archive_post_type(),
		[
			'labels'                => [
				'name'                  => __( 'Plugin Archives', get_textdomain() ),
				'singular_name'         => __( 'Plugin Archive', get_textdomain() ),
				'all_items'             => __( 'All Plugin Archives', get_textdomain() ),
				'archives'              => __( 'Plugin Archive Archives', get_textdomain() ),
				'attributes'            => __( 'Plugin Archive Attributes', get_textdomain() ),
				'insert_into_item'      => __( 'Insert into Plugin Archive', get_textdomain() ),
				'uploaded_to_this_item' => __( 'Uploaded to this Plugin Archive', get_textdomain() ),
				'featured_image'        => _x( 'Featured Image', 'plugin-archive', get_textdomain() ),
				'set_featured_image'    => _x( 'Set featured image', 'plugin-archive', get_textdomain() ),
				'remove_featured_image' => _x( 'Remove featured image', 'plugin-archive', get_textdomain() ),
				'use_featured_image'    => _x( 'Use as featured image', 'plugin-archive', get_textdomain() ),
				'filter_items_list'     => __( 'Filter Plugin Archives list', get_textdomain() ),
				'items_list_navigation' => __( 'Plugin Archives list navigation', get_textdomain() ),
				'items_list'            => __( 'Plugin Archives list', get_textdomain() ),
				'new_item'              => __( 'New Plugin Archive', get_textdomain() ),
				'add_new'               => __( 'Add New', get_textdomain() ),
				'add_new_item'          => __( 'Add New Plugin Archive', get_textdomain() ),
				'edit_item'             => __( 'Edit Plugin Archive', get_textdomain() ),
				'view_item'             => __( 'View Plugin Archive', get_textdomain() ),
				'view_items'            => __( 'View Plugin Archives', get_textdomain() ),
				'search_items'          => __( 'Search Plugin Archives', get_textdomain() ),
				'not_found'             => __( 'No Plugin Archives found', get_textdomain() ),
				'not_found_in_trash'    => __( 'No Plugin Archives found in trash', get_textdomain() ),
				'parent_item_colon'     => __( 'Parent Plugin Archive:', get_textdomain() ),
				'menu_name'             => __( 'Plugin Archives', get_textdomain() ),
			],
			'public'                => false,
			'hierarchical'          => false,
			'show_ui'               => true,
			'show_in_nav_menus'     => true,
			'supports'              => [ 'title', 'editor', 'custom-fields', 'author' ],
			'has_archive'           => false,
			'rewrite'               => true,
			'query_var'             => true,
			'menu_position'         => null,
			'menu_icon'             => 'dashicons-analytics',
			'taxonomy'              => [ get_archive_taxonomy() ],
			'show_in_rest'          => is_cornershop_user(),
			'rest_base'             => get_archive_post_type(),
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		]
	);

	register_taxonomy(
		get_archive_taxonomy(),
		[ get_archive_post_type() ],
		[
			'labels'                => [
				'name'                       => __( 'Archive Types', get_textdomain() ),
				'singular_name'              => _x( 'Archive Type', 'taxonomy general name', get_textdomain() ),
				'search_items'               => __( 'Search Archive Types', get_textdomain() ),
				'popular_items'              => __( 'Popular Archive Types', get_textdomain() ),
				'all_items'                  => __( 'All Archive Types', get_textdomain() ),
				'parent_item'                => __( 'Parent Archive Type', get_textdomain() ),
				'parent_item_colon'          => __( 'Parent Archive Type:', get_textdomain() ),
				'edit_item'                  => __( 'Edit Archive Type', get_textdomain() ),
				'update_item'                => __( 'Update Archive Type', get_textdomain() ),
				'view_item'                  => __( 'View Archive Type', get_textdomain() ),
				'add_new_item'               => __( 'Add New Archive Type', get_textdomain() ),
				'new_item_name'              => __( 'New Archive Type', get_textdomain() ),
				'separate_items_with_commas' => __( 'Separate Archive Types with commas', get_textdomain() ),
				'add_or_remove_items'        => __( 'Add or remove Archive Types', get_textdomain() ),
				'choose_from_most_used'      => __( 'Choose from the most used Archive Types', get_textdomain() ),
				'not_found'                  => __( 'No Archive Types found.', get_textdomain() ),
				'no_terms'                   => __( 'No Archive Types', get_textdomain() ),
				'menu_name'                  => __( 'Archive Types', get_textdomain() ),
				'items_list_navigation'      => __( 'Archive Types list navigation', get_textdomain() ),
				'items_list'                 => __( 'Archive Types list', get_textdomain() ),
				'most_used'                  => _x( 'Most Used', 'plugin-archive', get_textdomain() ),
				'back_to_items'              => __( '&larr; Back to Archive Types', get_textdomain() ),
			],
			'hierarchical'          => true,
			'public'                => false,
			'show_in_nav_menus'     => true,
			'show_ui'               => true,
			'show_admin_column'     => true,
			'query_var'             => true,
			'rewrite'               => true,
			'capabilities'          => [
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'manage_options',
			],
			'show_in_rest'          => is_cornershop_user(),
			'rest_base'             => get_archive_taxonomy(),
			'rest_controller_class' => 'WP_REST_Terms_Controller',
		]
	);

	generate_default_log_terms();
}
add_action( 'init', __NAMESPACE__ . '\create_archive_post_type' );

/**
 * Limit the number of log post types that can exists on the site.
 *
 * This will help to prevent the log post type from eating up too much space since we should rarely need to audit
 * the posts.
 *
 * @param int      $post_id ID of the post that was just added.
 * @param \WP_Post $post Post object of the post that was just added.
 * @param bool     $update Whether the post was being updated.
 *
 * @return void
 */
function limit_log_post_type( $post_id, $post, $update ) {
	if ( get_log_post_type() !== get_post_type( $post ) ) {
		return;
	}

	$posts_count = wp_count_posts( get_log_post_type() );

	if ( is_object( $posts_count ) && isset( $posts_count->private ) && 200 < absint( $posts_count->private ) ) {
		$query = new \WP_Query(
			[
				'post_type'              => get_log_post_type(),
				'posts_per_page'         => 11,
				'offset'                 => 11,
				'post_status'            => 'private',
				'order'                  => 'ASC',
				'orderby'                => 'date',
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		if ( ! is_wp_error( $query ) && ! empty( $query->posts ) ) {
			foreach ( $query->posts as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}

		$query->reset_postdata();
	}//end if
}
add_action( 'wp_insert_post', __NAMESPACE__ . '\limit_log_post_type', 10, 3 );

/**
 * Limit the number of zip archive post types that can exists on the site.
 *
 * This will help to prevent the zip archive post type from eating up too much space since we should rarely need to audit
 * the posts.
 *
 * @param int      $post_id ID of the post that was just added.
 * @param \WP_Post $post Post object of the post that was just added.
 * @param bool     $update Whether the post was being updated.
 *
 * @return void
 */
function limit_archive_post_type( $post_id, $post, $update ) {
	if ( get_archive_post_type() !== get_post_type( $post ) ) {
		return;
	}

	$posts_count = wp_count_posts( get_archive_post_type() );

	if ( is_object( $posts_count ) && isset( $posts_count->private ) && 25 < absint( $posts_count->private ) ) {
		$query = new \WP_Query(
			[
				'post_type'              => get_archive_post_type(),
				'posts_per_page'         => 11,
				'offset'                 => 11,
				'post_status'            => 'private',
				'order'                  => 'ASC',
				'orderby'                => 'date',
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		if ( ! is_wp_error( $query ) && ! empty( $query->posts ) ) {
			foreach ( $query->posts as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}

		$query->reset_postdata();
	}//end if
}
add_action( 'wp_insert_post', __NAMESPACE__ . '\limit_archive_post_type', 10, 3 );

/**
 * Delete the zip archive of premium plugin file when the corresponding post is deleted.
 *
 * @param  int      $post_id  ID of the post that is deleted.
 * @param  \WP_Post $post Post that was just deleted.
 *
 * @return void
 */
function delete_archive_file( $post_id, $post ) {
	if ( get_archive_post_type() !== get_post_type( $post ) ) {
		return;
	}

	wp_delete_file( get_archive_zip_file( $post_id ) );
}
add_action( 'before_delete_post', __NAMESPACE__ . '\delete_archive_file', 10, 2 );

/**
 * Get the post type that will be used for a log
 *
 * @return string Name of the post type used for logging actions.
 */
function get_log_post_type() {
	return 'cshp_pt_log';
}

/**
 * Get the post type that will be used for managing the premium plugin and theme zip files.
 *
 * @return string Name of the post type used for tracking plugin and theme zip files.
 */
function get_archive_post_type() {
	return 'cshp_pt_zip';
}

/**
 * Get the taxonomy that will be used for a log
 *
 * @return string Taxonomy used for logging actions of the log post type.
 */
function get_log_taxonomy() {
	return 'cshp_pt_log_type';
}

/**
 * Get the taxonomy that will be used for tracking the contents of a zip file.
 *
 * @return string Taxonomy used for tracking the contents of a zip file.
 */
function get_archive_taxonomy() {
	return 'cshp_pt_zip_content';
}

/**
 * Get the plugin's textdomain so that we don't have to remember when defining strings for display.
 *
 * @return string Text domain for plugin.
 */
function get_textdomain() {
	$this_plugin = get_plugin_data( __FILE__, false );
	return $this_plugin['TextDomain'] ?? 'cshp-pt';
}

/**
 * Get the plugin's version from the Plugin info docblock so that we don't have to update this in multiple places
 * when the version number is updated.
 *
 * @return int Version of the plugin.
 */
function get_version() {
	$this_plugin = get_plugin_data( __FILE__, false );
	return $this_plugin['Version'] ?? '1.0.0';
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
 * Get the name of this main plugin file
 *
 * @return string Name of plugin file without .php extension.
 */
function get_this_plugin_slug() {
	return basename( __FILE__, '.php' );
}

/**
 * Add this plugin's update url to the list of safe URLs that can be used with remote requests.
 *
 * Useful if calling wp_safe_remote_get. Ensures that the plugin update URL is considered a safe URL to help avoid forced redirections.
 *
 * @param bool   $return False if the URL is not considered safe, true if the URL is considered safe. By default, all URLs are considered safe.
 * @param string $url External URL that is being requested.
 *
 * @return bool True if the external URL is considered safe, False, if the external URL is not considered safe.
 */
function add_plugin_update_url_to_safe_url_list( $return, $url ) {
	if ( get_plugin_update_url() === $url ) {
		return true;
	}

	return $return;
}
add_filter( 'http_request_reject_unsafe_urls', __NAMESPACE__ . '\add_plugin_update_url_to_safe_url_list', 99, 2 );

/**
 * Get the path to the premium plugin download zip file.
 *
 * @param bool $active_only Get the zip file with only plugins that are active.
 *
 * @return false|string|null Path of the zip file on the server or empty of no zip file.
 */
function get_premium_plugin_zip_file( $active_only = true ) {
	$plugins         = get_active_plugins();
	$plugins_folders = [];

	foreach ( $plugins as $plugin ) {
		$plugin_folder_name      = dirname( $plugin );
		$plugin_folder_path_file = get_plugin_file_full_path( $plugin );

		// exclude plugins that we explicitly don't want to download
		if ( in_array( $plugin_folder_name, get_excluded_plugins(), true ) ) {
			continue;
		}

		// if the plugin has disabled updates, include it in the list of premium plugins
		// only zip up plugins that are not available on WordPress.org
		if ( in_array( $plugin_folder_name, premium_plugins_list(), true ) || is_premium_plugin( $plugin_folder_path_file ) ) {
			$plugins_folders[] = $plugin_folder_name;
		}
	}//end foreach

	$archive_zip_file_name = get_archive_zip_file_by_contents( $plugins_folders );

	if ( ! empty( $archive_zip_file_name ) && is_archive_zip_old( wp_basename( $archive_zip_file_name ) ) ) {
		return;
	}

	if ( ! empty( $archive_zip_file_name ) ) {
		$archive_zip_file_name = sprintf( '%s/%s', create_plugin_uploads_folder(), $archive_zip_file_name );
	}

	return $archive_zip_file_name;
}

/**
 * Get the path to the zip archive related to a generated zip of premium plugins.
 *
 * @param int|string $post_id ID of the premium plugins post.
 *
 * @return string|null Path to the zip of the premium plugins. Null if no zip exists.
 */
function get_archive_zip_file( $post_id ) {
	if ( get_archive_post_type() !== get_post_type( $post_id ) ) {
		return;
	}

	$zip_file_name = get_post_meta( $post_id, 'cshp_plugin_tracker_zip', true );

	if ( ! empty( $zip_file_name ) ) {
		$zip_path = sprintf( '%s/%s', create_plugin_uploads_folder(), $zip_file_name );
		if ( does_zip_exists( $zip_path ) ) {
			return $zip_path;
		}
	}

	return;
}

/**
 * Get the saved archived posts based on which plugins are included in that zip file.
 *
 * @param array $archived_plugins List of plugins to find an archive for.
 *
 * @return string|void|null Posts that have data for the zip archive of premium plugins.
 */
function get_archive_post_by_contents( $archived_plugins ) {
	$query = new \WP_Query(
		[
			'post_type'              => get_archive_post_type(),
			'posts_per_page'         => 25,
			'post_status'            => 'private',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'tax_query'              => [
				[
					'taxonomy' => get_archive_taxonomy(),
					'field'    => 'slug',
					'terms'    => $archived_plugins,
					'operator' => 'AND',
		// make sure we ae finding the posts that have all of these exact terms
				],
			],
		]
	);

	if ( ! is_wp_error( $query ) && $query->have_posts() ) {
		return $query->posts;
	}

	return;
}

/**
 * Get the saved archived post based on the name of the zip file.
 *
 * @param string $archive_zip_file_name Filename of the archive zip file (e.g. plugin-archive.zip)
 *
 * @return string|void|null Post that has the name of the zip file saved to it.
 */
function get_archive_post_by_zip_filename( $archive_zip_file_name ) {
	$query = new \WP_Query(
		[
			'post_type'              => get_archive_post_type(),
			'posts_per_page'         => 1,
			'post_status'            => 'private',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'meta_query'             => [
				[
					'key'     => 'cshp_plugin_tracker_zip',
					'value'   => $archive_zip_file_name,
					'compare' => '=',
				],
			],
		]
	);

	if ( ! is_wp_error( $query ) && $query->have_posts() ) {
		return $query->posts[0];
	}

	return;
}

/**
 * Get the latest version of the saved archived zip file based on which plugins are included in that zip file.
 *
 * @param array $archived_plugins List  of plugins to find an archive for.
 *
 * @return string|void|null Path to the saved zip archive with the plugins saved.
 */
function get_archive_zip_file_by_contents( $archived_plugins ) {
	$posts = get_archive_post_by_contents( $archived_plugins );

	if ( ! empty( $posts ) ) {
		return get_archive_zip_file( $posts[0]->ID );
	}

	return;
}

/**
 * Check if the zipped up plugins in an archive zip are old by comparing the version numbers of the zip plugins with the version numbers of the current plugins.
 *
 * @param int|\WP_Post $archive_post
 *
 * @return bool True if the archive is old and should be deleted. False if the archive is up to date.
 */
function is_archive_zip_old( $archive_post ) {
	$plugins        = get_premium_plugin_zip_file_contents( $archive_post );
	$is_archive_old = false;

	if ( ! empty( $plugins ) ) {
		$plugins = json_decode( $plugins, true );
		if ( ! empty( $plugins ) ) {
			foreach ( $plugins as $plugin => $version ) {
				$plugin_data = get_plugins( '/' . $plugin );
				// if any saved plugin version is not the same as the curret plugin version, the archive is old.

				if ( ! empty( $plugin_data ) ) {
					// traverse the plugin data since the key that is returned is the plugin file name
					$plugin_data = $plugin_data[ array_key_first( $plugin_data ) ];

					if ( ! empty( $plugin_data['Version'] ) && $plugin_data['Version'] !== $version ) {
						$is_archive_old = true;
						break;
					}
				}
			}
		}
	}

	return $is_archive_old;
}
/**
 * Get the path to the premium theme download zip file.
 *
 * @return false|string|null Path of the zip file on the server or empty if no zip file.
 */
function get_premium_theme_zip_file() {
	return get_option( 'cshp_plugin_tracker_theme_zip' );
}

/**
 * Get the list of plugin folders and plugin versions that were saved to the last generated premium plugins zip file.
 *
 * @param string $archive_zip_file_name_or_archive_zip_post Name of the zip file, post ID of the archive zip post object or the archive post object.
 *
 * @return false|array|null Name of plugin folders and versions that were saved to the last generated premium plugins
 * zip file.
 */
function get_premium_plugin_zip_file_contents( $archive_zip_file_name_or_archive_zip_post = '' ) {
	$plugin_zip_contents = [];

	if ( $archive_zip_file_name_or_archive_zip_post instanceof \WP_Post ) {
		$archive_zip_post = $archive_zip_file_name_or_archive_zip_post;
	} elseif ( is_int( $archive_zip_file_name_or_archive_zip_post ) ) {
		$archive_zip_post = new \WP_Query(
			[
				'post_type'              => get_archive_post_type(),
				'p'                      => $archive_zip_file_name_or_archive_zip_post,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'posts_per_page'         => 1,
			]
		);

		if ( ! is_wp_error( $archive_zip_post ) && $archive_zip_post->have_posts() ) {
			$archive_zip_post = $archive_zip_post[0];
		}
	} elseif ( ! empty( $archive_zip_file_name_or_archive_zip_post ) && is_string( $archive_zip_file_name_or_archive_zip_post ) ) {
		$archive_zip_post = get_archive_post_by_zip_filename( $archive_zip_file_name_or_archive_zip_post );
	}

	if ( ! empty( $archive_zip_post ) ) {
		$plugin_zip_contents = get_post_meta( $archive_zip_post->ID, 'cshp_plugin_tracker_archived_plugins', true );
	}

	return $plugin_zip_contents;
}

/**
 * Get the list of theme folders and theme versions that were saved to the last generated premium themes zip file.
 *
 * @return false|array|null Name of theme folders and versions that were saved to the last generated premium themes
 * zip file.
 */
function get_premium_theme_zip_file_contents() {
	return get_option( 'cshp_plugin_tracker_theme_zip_contents', [] );
}

/**
 * Get the token that is used for downloading the missing plugins and themes.
 *
 * @return false|string|null Token or empty if no token.
 */
function get_stored_token() {
	return get_option( 'cshp_plugin_tracker_token' );
}

/**
 * Get the list of plugins that should be excluded when zipping the premium plugins
 *
 * @return false|array|null Array of plugins to exclude based on the plugin folder name.
 */
function get_excluded_plugins() {
	$list = get_option( 'cshp_plugin_tracker_exclude_plugins', [] );
	// always exclude this plugin from being included in the list of plugins to zip
	$list = array_merge( $list, exclude_plugins_list() );

	// allow custom code to filter the plugins that are excluded
	$list = apply_filters( 'cshp_pt_exclude_plugins', $list );

	return $list;
}

/**
 * Get the list of themes that should be excluded when zipping the premium themes
 *
 * @return false|array|null Array of themes to exclude based on the theme folder name.
 */
function get_excluded_themes() {
	return get_option( 'cshp_plugin_tracker_exclude_themes', [] );
}

/**
 * Get the key that is used to reference this website on the Cornershop Plugin Recovery website. This key is generated in the CPR website.
 *
 * @return false|string|null Site key that is used to reference this site on CPR.
 */
function get_site_key() {
	return get_option( 'cshp_plugin_tracker_cpr_site_key', '' );
}

/**
 * Determine if the composer.json file should update in real-time or if the file should update during daily cron job
 *
 * @return bool False if the file should update during cron job. True if the file should update in real-time. Default is false.
 */
function should_real_time_update() {
	$option = get_option( 'cshp_plugin_tracker_live_change_tracking', 'no' );
	if ( 'yes' === $option ) {
		return true;
	}

	return false;
}

/**
 * Get the WP REST API URL for downloading the missing plugins zip file
 *
 * @return string URL for downloading missing plugin zip files using the WP REST API.
 */
function get_api_active_plugin_downloads_endpoint() {
	return add_query_arg(
		[ 'token' => get_stored_token() ],
		get_rest_url( null, '/cshp-plugin-tracker/plugin/download' )
	);
}

/**
 * Get the Rewrite URL for downloading the missing plugins zip file (if the WP REST API is disabled)
 *
 * @return string URL for downloading missing plugin zip files using the rewrite url.
 */
function get_rewrite_active_plugin_downloads_endpoint() {
	return add_query_arg(
		[ 'token' => get_stored_token() ],
		home_url( '/cshp-plugin-tracker/plugin/download' )
	);
}

/**
 * Get the WP REST API URL for downloading the missing themes zip file
 *
 * @return string URL for downloading missing themes zip files using the WP REST API.
 */
function get_api_theme_downloads_endpoint() {
	return add_query_arg(
		[ 'token' => get_stored_token() ],
		get_rest_url( null, '/cshp-plugin-tracker/theme/download' )
	);
}

/**
 * Get the Rewrite URL for downloading the missing themes zip file (if the WP REST API is disabled)
 *
 * @return string URL for downloading missing themes zip files using the rewrite url.
 */
function get_rewrite_theme_downloads_endpoint() {
	return add_query_arg(
		[ 'token' => get_stored_token() ],
		home_url( '/cshp-plugin-tracker/theme/download' )
	);
}

/**
 * Generate a default list of terms for the types of actions to log
 *
 * @return void
 */
function generate_default_log_terms() {
	$terms = [
		'tracker_file_create'        => __( 'Tracker File Create', get_textdomain() ),
		'tracker_file_error'         => __( 'Tracker File Generate Error', get_textdomain() ),
		'token_create'               => __( 'Token Create', get_textdomain() ),
		'token_delete'               => __( 'Token Delete', get_textdomain() ),
		'token_verify_fail'          => __( 'Token Verify Fail', get_textdomain() ),
		'plugin_zip_create_start'    => __( 'Plugin Zip Create Start ', get_textdomain() ),
		'plugin_zip_create_complete' => __( 'Plugin Zip Create Complete ', get_textdomain() ),
		'theme_zip_create_start'     => __( 'Theme Zip Create Start', get_textdomain() ),
		'theme_zip_create_complete'  => __( 'Theme Zip Create Complete', get_textdomain() ),
		'plugin_zip_delete'          => __( 'Plugin Zip Delete', get_textdomain() ),
		'theme_zip_delete'           => __( 'Theme Zip Delete', get_textdomain() ),
		'plugin_zip_download'        => __( 'Plugin Zip Download', get_textdomain() ),
		'theme_zip_download'         => __( 'Theme Zip Download', get_textdomain() ),
		'plugin_zip_error'           => __( 'Plugin Zip Generate Error', get_textdomain() ),
		'theme_zip_error'            => __( 'Theme Zip Generate Error', get_textdomain() ),
		'plugin_activate'            => __( 'Plugin Activate', get_textdomain() ),
		'plugin_deactivate'          => __( 'Plugin Deactivate', get_textdomain() ),
		'plugin_uninstall'           => __( 'Plugin Uninstall', get_textdomain() ),
		'theme_activate'             => __( 'Theme Activate', get_textdomain() ),
		'theme_deactivate'           => __( 'Theme Deactivate', get_textdomain() ),
		'theme_uninstall'            => __( 'Theme Uninstall', get_textdomain() ),
		'plugin_zip_backup_error'    => __( 'Plugin Zip Backup Error', get_textdomain() ),
		'plugin_zip_backup_complete' => __( 'Plugin Zip Backup Complete', get_textdomain() ),
	];

	$count_terms = wp_count_terms(
		[
			'taxonomy'   => get_log_taxonomy(),
			'hide_empty' => false,
		]
	);

	if ( ! is_wp_error( $count_terms ) && absint( $count_terms ) !== count( $terms ) ) {
		foreach ( $terms as $slug => $name ) {
			if ( term_exists( $slug, get_log_taxonomy() ) ) {
				continue;
			}

			wp_insert_term(
				$name,
				get_log_taxonomy(),
				[
					'slug' => $slug,
				]
			);
		}
	}
}

/**
 * Get a list of the allowed actions that can be taken with the zip files and tokens
 *
 * @return array List of terms in the log taxonomy.
 */
function get_allowed_log_types() {
	return get_terms(
		[
			'taxonomy'   => get_log_taxonomy(),
			'hide_empty' => false,
		]
	);
}

/**
 * Get the current list of installed WordPress plugins
 *
 * @return array Composer'ized array with plugin scope and name as the key and version as the value.
 */
function generate_composer_installed_plugins() {
	$plugins_data = get_plugins();
	$format_data  = [];

	if ( ! empty( $plugins_data ) ) {
		foreach ( $plugins_data as $plugin_file => $data ) {
			$version       = isset( $data['Version'] ) && ! empty( $data['Version'] ) ? $data['Version'] : '*';
			$plugin_folder = dirname( $plugin_file );
			$repo          = 'wpackagist-plugin';

			if ( in_array( $plugin_folder, premium_plugins_list(), true ) || is_premium_plugin( $plugin_file ) ) {
				$repo = 'premium-plugin';
			}

			$key                 = sprintf( '%s/%s', $repo, $plugin_folder );
			$format_data[ $key ] = $version;
		}
	}

	return $format_data;
}

/**
 * Get the current list of installed and active themes
 *
 * @return array Composer'ized array with theme scope and name as the key and version as the value.
 */
function generate_composer_installed_themes() {
	$themes      = wp_get_themes();
	$format_data = [];

	if ( ! empty( $themes ) ) {
		foreach ( $themes as $theme ) {
			$theme_path_info   = explode( DIRECTORY_SEPARATOR, $theme->get_stylesheet_directory() );
			$theme_folder_name = end( $theme_path_info );
			$repo              = 'wpackagist-theme';

			if ( in_array( $theme_folder_name, premium_themes_list(), true ) ||
				 ! is_theme_available( $theme_folder_name, $theme->get( 'Version' ) ) ) {
				$repo = 'premium-theme';
			}

			$key                 = sprintf( '%s/%s', $repo, $theme_folder_name );
			$format_data[ $key ] = ! empty( $theme->get( 'Version' ) ) ? $theme->get( 'Version' ) : '*';
		}//end foreach
	}//end if

	return $format_data;
}

/**
 * Generate the composer key-value array that is added to the composer.json file
 *
 * @return array Array with installed plugins and themes.
 */
function generate_composer_array() {
	$composer = generate_composer_template();
	$plugins  = generate_composer_installed_plugins();
	$themes   = generate_composer_installed_themes();

	$composer['require']['core/wp'] = get_current_wordpress_version();

	if ( ! empty( $plugins ) ) {
		$composer['require'] = array_merge( $composer['require'], $plugins );
	}

	if ( ! empty( $themes ) ) {
		$composer['require'] = array_merge( $composer['require'], $themes );
	}

	ksort( $composer['require'] );

	return $composer;
}

/**
 * Generate the text that will be added to the README.md file
 *
 * @return string List with WordPress version, plugins, and themes installed.
 */
function generate_readme() {
	$themes  = generate_composer_installed_themes();
	$plugins = generate_composer_installed_plugins();

	// replace leading spaces on each new line to format the README file correctly
	// works better than doing a preg_replace( '/^\s+/m', '', 'string' ) since preg_replace also wipes out
	// multiple lines breaks, which breaks the formatting of the README.md file
	return join(
		"\n",
		array_map(
			'trim',
			explode(
				"\n",
				sprintf(
					'
            %1$s
            %2$s
            %3$s
            %2$s
            %4$s
            %2$s
            %5$s
            %2$s
            %6$s
            %2$s
            %7$s
            %2$s
            %8$s
            %2$s
            %9$s',
					generate_wordpress_markdown(),
					PHP_EOL,
					generate_wordpress_wp_cli_install_command(),
					generate_themes_markdown( $themes ),
					generate_plugins_markdown( $plugins ),
					generate_themes_wp_cli_install_command( $themes ),
					generate_plugins_wp_cli_install_command( $plugins ),
					generate_themes_zip_command( $themes ),
					generate_plugins_zip_command( $plugins ),
				)
			)
		)
	);
}

/**
 * Get the current WordPress version
 *
 * @return string WordPress version.
 */
function get_current_wordpress_version() {
	global $wp_version;
	return $wp_version;
}

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
 * Get the status of all plugins to determine if they are active or inactive.
 *
 * @return array List of plugins with the plugin folder as the key and the status as the value.
 */
function get_plugins_status() {
	$plugins = array_keys( get_plugins() );
	$status  = [];

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
function get_active_themes() {
	$theme  = wp_get_theme();
	$themes = [];

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
 * Determine if the plugin is available on wordpress.org using the wordpress.org API.
 *
 * Note: If a plugin has been temporarily removed from wordpress.org, the plugin is now considered "premium".
 *
 * @param string     $plugin_slug WordPress plugin slug.
 * @param int|string $version Plugin version to search for.
 *
 * @return bool True if the plugin is available on wordpress.org. False if the plugin cannot be found
 * or is not available for download.
 */
function is_plugin_available( $plugin_slug, $version = '' ) {
	// if external requests are blocked to wordpress.org, assume that all plugins are premium plugins
	if ( is_wordpress_org_external_request_blocked() ) {
		return false;
	}

	$url = sprintf( 'https://api.wordpress.org/plugins/info/1.0/%s.json', $plugin_slug );

	if ( isset( $url ) ) {
		$plugin_info = wp_remote_post( $url );

		if ( ! is_wp_error( $plugin_info ) && 200 === wp_remote_retrieve_response_code( $plugin_info ) ) {
			$response = json_decode( wp_remote_retrieve_body( $plugin_info ), true );
			if ( empty( $response ) || ( isset( $response['error'] ) && ! empty( $response['error'] ) ) ) {
				return false;
			} else {

				if ( ! empty( $version ) && isset( $response['versions'] ) &&
					 ! isset( $response['versions'][ $version ] ) ) {
					return false;
				}

				return true;
			}
		}
	}

	return false;
}

/**
 * Determine if the theme is available on wordpress.org using the wordpress.org API.
 *
 *  * Note: If a theme has been temporarily removed from wordpress.org, the theme is now considered "premium".
 *
 * @param string     $theme_slug WordPress theme slug.
 * @param int|string $version Version of the theme.
 *
 * @return bool True if the theme is available on wordpress.org. False if the theme cannot be found
 * or is not available for download.
 */
function is_theme_available( $theme_slug, $version = '' ) {
	// if external requests are blocked to wordpress.org, assume that all themes are premium themes
	if ( is_wordpress_org_external_request_blocked() ) {
		return false;
	}

	$url     = 'https://api.wordpress.org/themes/info/1.1/';
	$version = trim( $version );

	$request = [
		'slug'   => $theme_slug,
		'fields' => [ 'versions' => true ],
	];

	$body = [
		'action'  => 'theme_information',
		'request' => $request,
	];

	$theme_info = wp_remote_post(
		$url,
		[
			'body' => $body,
		]
	);

	if ( ! is_wp_error( $theme_info ) && 200 === wp_remote_retrieve_response_code( $theme_info ) ) {
		$response = json_decode( wp_remote_retrieve_body( $theme_info ), true );
		if ( empty( $response ) || ( isset( $response['error'] ) && ! empty( $response['error'] ) ) ) {
			return false;
		} else {

			if ( ! empty( $version ) && isset( $response['versions'] ) && ! isset( $response['versions'][ $version ] ) ) {
				return false;
			}

			return true;
		}
	}

	return false;
}

/**
 * Determine if a plugin is a "premium" plugin based on if it has disabled updates or the plugin is not available
 * on wordpress.org
 *
 * @param string $plugin_folder_name_or_main_file Full path to the plugin and main plugin file or the plugin folder
 * and main plugin file.
 *
 * @return bool True if the plugin is considered a "premium" plugin. False if it's not a "premium" plugin.
 */
function is_premium_plugin( $plugin_folder_name_or_main_file ) {
	$plugin_path_file = $plugin_folder_name_or_main_file;
	$composer_file    = get_tracker_file();
	if ( ! file_exists( $plugin_path_file ) ) {
		$plugin_path_file = get_plugin_file_full_path( $plugin_path_file );
	}

	if ( file_exists( $plugin_path_file ) && is_file( $plugin_path_file ) ) {
		$plugin_data        = get_plugin_data( $plugin_path_file, false, false );
		$plugin_folder_name = basename( dirname( $plugin_path_file ) );
		$version_check      = isset( $data['Version'] ) ? $data['Version'] : '';

		// check the composer.json file and check if the file has been modified within the past week. If a plugin was marked as being public within the past week, then it is more than likely still available on wordpress.org
		// this prevents us from pinging wasting resources pinging the wordpress.org API and acts like a cache
		if ( is_file( $composer_file ) && time() - filemtime( $composer_file ) < WEEK_IN_SECONDS ) {
			$composer_file_array = wp_json_file_decode( $composer_file, [ 'associative' => true ] );

			$check_is_public  = sprintf( 'wpackagist-plugin/%s', $plugin_folder_name );
			$check_is_premium = sprintf( 'premium-plugin/%s', $plugin_folder_name );
			if ( isset( $composer_file_array['require'][ $check_is_public ] ) ) {
				return false;
			} elseif ( isset( $composer_file_array['require'][ $check_is_premium ] ) ) {
				return true;
			}
		}
		if ( is_update_disabled( $plugin_data ) || ! is_plugin_available( $plugin_folder_name, $version_check ) ) {
			return true;
		}
	}//end if

	return false;
}

/**
 * Check if a plugin or a theme has an UPDATE URI header, which may indicate if plugin is a premium plugin
 * or if the plugin should never be updated
 *
 * @param array $plugin_data Header from the plugin generated by calling plugin_data.
 *
 * @return bool True if the plugin has explicitly disabled updates.
 */
function is_update_disabled( $plugin_data ) {
	$update_disabled = false;
	// if a plugin or theme has explicitly set a UPDATE URI header, then check if the value is a boolean
	// if the plugin doesn't have an UPDATE URI, treat it like a wordpress.org plugin
	if ( is_array( $plugin_data ) && isset( $plugin_data['UpdateURI'] ) && is_bool( $plugin_data['UpdateURI'] ) ) {
		$update_disabled = false === $plugin_data['UpdateURI'];
	}

	return $update_disabled;
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
function is_wordpress_org_external_request_blocked() {
	return is_external_domain_blocked( 'https://api.wordpress.org' );
}

/**
 * Save the installed and active plugins to a composer.json file that we can use to track the installed plugins
 *
 * @return void
 */
function create_plugin_tracker_file() {
	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;

	if ( empty( $wp_filesystem ) ) {
		return;
	}

	$folder_path = create_plugin_uploads_folder();
	$file_path   = sprintf( '%s/composer.json', $folder_path );
	$error       = __( 'Tracker File could not be created due to unknown error. Maybe disk space or permissions error when writing to the file. WordPress Filesystem may be in FTP mode and the FTP credentials have not been updated. Please fix before trying again.', get_textdomain() );

	if ( ! is_wp_error( $folder_path ) && ! empty( $folder_path ) ) {
		$composer_json = wp_json_encode( generate_composer_array(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		try {
			$composer_json_uploads_file = $wp_filesystem->put_contents( $file_path, $composer_json );
			if ( ! empty( $composer_json_uploads_file ) && ! is_wp_error( $composer_json_uploads_file ) ) {
				log_request( 'tracker_file_create' );
				// create a README.md so this information is easier to find
				$readme      = sprintf( '%s/README.md', $folder_path );
				$readme_text = generate_readme();

				if ( $readme_text ) {
					$wp_filesystem->put_contents( $readme, $readme_text );
				}

				return;
			}
		} catch ( \TypeError $type_error ) {
			$error = $type_error->getMessage();
		}

		log_request( 'tracker_file_error', $error );
	}//end if

	return $error;
}

/**
 * Maybe regenerate the composer.json file during a cron job if our list of composer requirements changes
 *
 * This would execute if a previous public plugin or theme becomes "premium", if a plugin is uninstalled,
 * or removed from some reason.
 *
 * @return void
 */
function create_plugin_tracker_file_cron() {
	if ( maybe_update_tracker_file() ) {
		create_plugin_tracker_file();
		delete_old_archive_posts();
	}
}
add_action( 'cshp_pt_regenerate_composer_daily', __NAMESPACE__ . '\create_plugin_tracker_file_cron' );
add_action( 'cshp_pt_regenerate_composer_post_bulk_update', __NAMESPACE__ . '\create_plugin_tracker_file_cron' );

/**
 * Update the composer file one-minute after some action. Usually after bulk plugin updates or bulk plugin installs.
 *
 * Useful to prevent the composer file from being written to multiple times after each plugin is updated.
 *
 * @return void
 */
function update_plugin_tracker_file_post_bulk_update() {
	$one_minute_later = new \DateTime();
	$one_minute_later->add( new \DateInterval( 'PT1M' ) );
	// generate a cron expression for Action Scheduler to run one minute after plugin upgrades.
	// get minute without leading zero to conform to Crontab specification
	$one_minute_later_cron_expression = sprintf( '%d %d * * *', (int) $one_minute_later->format( 'i' ), $one_minute_later->format( 'G' ) );
	if ( function_exists( '\as_schedule_cron_action' ) && ! \as_has_scheduled_action( 'cshp_pt_regenerate_composer_post_bulk_update', [], 'cshp_pt' ) ) {
		// schedule action scheduler to run once a day
		\as_schedule_cron_action( strtotime( 'now' ), $one_minute_later_cron_expression, 'cshp_pt_regenerate_composer_post_bulk_update', [], 'cshp_pt' );
	} elseif ( ! wp_next_scheduled( 'cshp_pt_regenerate_composer_post_bulk_update' ) ) {
		wp_schedule_event( $one_minute_later->getTimestamp(), 'daily', 'cshp_pt_regenerate_composer_post_bulk_update' );
	}
}

/**
 * Read the data from the saved plugin composer.json file
 *
 * @return array|null Key-value based array of the composer.json file, empty array if no composer.json file, or null.
 */
function read_tracker_file() {
	if ( empty( get_tracker_file() ) ) {
		return;
	}

	return wp_json_file_decode( get_tracker_file(), [ 'associative' => true ] );
}

/**
 * Get the path to the plugin tracker's generated composer.json file with the plugins and themes installed.
 *
 * @return string Full file path to the plugin tracker's generated composer.json
 */
function get_tracker_file() {
	if ( is_wp_error( create_plugin_uploads_folder() ) || empty( create_plugin_uploads_folder() ) ) {
		return;
	}

	$plugins_file = sprintf( '%s/composer.json', create_plugin_uploads_folder() );

	if ( ! file_exists( $plugins_file ) ) {
		return;
	}

	return $plugins_file;
}

/**
 * Create a folder in the uploads directory so that all the files we create are contained.
 *
 * @return string|\WP_Error Path to the uploads folder of the plugin, WordPress error if the plugin uploads
 * folder cannot be created.
 */
function create_plugin_uploads_folder() {
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
		} else {
			log_request( 'tracker_file_error', __( 'Uploads folder for the plugin tracker could not be created due to unknown error. Maybe disk space or permissions error when creating the folder', get_textdomain() ) );
			return $test;
		}
	}

	return $folder_path;
}

/**
 * Iterate through a folder and add the files from the folder to the zip archive
 *
 * @param string $zip_path Path and file name of the zip file.
 * @param array  $folder_paths Array of full folder paths to add to the zip file.
 *
 * @return string Path of the saved zip file or an error message of the zip file cannot be created.
 */
function create_zip( $zip_path, $folder_paths = [], $additional_files = [] ) {
	if ( ! class_exists( '\ZipArchive' ) ) {
		return __( 'Error: Ziparchive PHP class does not exist or the PHP zip module is not installed', get_textdomain() );
	}

	$zip = new \ZipArchive();
	if ( $zip->open( $zip_path, true !== ( \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) ) {

		foreach ( $folder_paths as $folder_path ) {
			$rii         = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder_path ) );
			$folder_name = basename( $folder_path );
			foreach ( $rii as $file ) {
				if ( $file->isDir() ) {
					continue;
				}

				if ( 0 === stripos( $file->getRealPath(), sprintf( '%s/plugins', WP_CONTENT_DIR ) ) ) {
					$file_relative_path = ltrim( get_wp_content_relative_file_path( $file->getRealPath(), 'plugins' ), '/' );
				} elseif ( 0 === stripos( $file->getRealPath(), sprintf( '%s/themes', WP_CONTENT_DIR ) ) ) {
					$file_relative_path = ltrim( get_wp_content_relative_file_path( $file->getRealPath(), 'themes' ), '/' );
				} elseif ( 0 === stripos( $file->getRealPath(), sprintf( '%s/uploads', WP_CONTENT_DIR ) ) ) {
					$file_relative_path = ltrim( get_wp_content_relative_file_path( $file->getRealPath(), 'uploads' ), '/' );
				} else {
					$relative_path      = substr( $file->getRealPath(), strlen( $folder_path ) );
					$file_relative_path = $folder_name . '/' . $relative_path;
				}

				// Add current file to archive
				$zip->addFile( $file->getRealPath(), $file_relative_path );
			}
		}//end foreach

		if ( ! empty( $additional_files ) ) {
			foreach ( $additional_files as $file_to_add ) {
				if ( is_array( $file_to_add ) && isset( $file_to_add[0] ) && isset( $file_to_add[1] ) ) {
					$zip->addFile( $file_to_add[0], $file_to_add[1] );
				} else {
					$zip->addFile( $file_to_add );
				}
			}
		}

		return $zip_path;
	} else {
		return __( 'Error: Zip cannot be created', get_textdomain() );
	}//end if

}

/**
 * Zip up the specified plugins that are not available on wordpress.org
 *
 * @param  array  $only_include_plugins  List of premium plugins that should only be in the zip.
 * @param bool   $include_all_plugins Include all plugins regardless if the plugin is active. By default, the generated archive only includes plugins that are activated.
 * @param string $zip_name File name of the plugins .zip file.
 *
 * @return string Path to the premium plugins zip file or error message if the zip file cannot be created.
 */
function zip_premium_plugins_include( $only_include_plugins = [], $include_all_plugins = false, $zip_name = '' ) {
	$included_plugins            = get_active_plugins();
	$message                     = '';
	$zip_include_plugins         = [];
	$plugin_composer_data_list   = [];
	$saved_plugins_list          = [];
	$plugin_zip_contents         = [];
	$premium_plugin_folder_names = [];
	// remove the active plugin file name and just get the name of the plugin folder
	$active_plugin_folder_names = array_map( 'dirname', $included_plugins );

	// if we want all plugins, then get all plugins
	if ( true === $include_all_plugins && empty( $only_include_plugins ) ) {
		$included_plugins = array_keys( get_plugins() );
	} elseif ( ! empty( $only_include_plugins ) ) {
		$included_plugins = $only_include_plugins;
	}

	foreach ( generate_composer_installed_plugins() as $plugin_folder_name => $plugin_version ) {
		$clean_folder_name = str_replace( [ 'premium-plugin/', 'wpackagist-plugin/' ], '', $plugin_folder_name );

		if ( str_contains( $plugin_folder_name, 'premium-plugin' ) ) {
			$premium_plugin_folder_names[] = $clean_folder_name;
		}

		$plugin_composer_data_list[ $clean_folder_name ] = $plugin_version;
	}

	$active_premium_plugin_folder_names = array_intersect( $premium_plugin_folder_names, $active_plugin_folder_names );
	// remove the excluded plugins if they are active
	$active_premium_plugin_folder_names = array_diff( $active_premium_plugin_folder_names, get_excluded_plugins() );
	$find_archive_file                  = get_archive_zip_file_by_contents( $active_premium_plugin_folder_names );

	if ( class_exists( '\ZipArchive' ) ) {
		if ( does_zip_exists( $find_archive_file ) && ! is_archive_zip_old( wp_basename( $find_archive_file ) ) ) {
			log_request( 'plugin_zip_download', '', $find_archive_file );
			return $find_archive_file;
		} else {
			log_request( 'plugin_zip_download', __( 'Attempt download but plugin zip does not exists or has not been generated lately. Generate zip.', get_textdomain() ) );
		}

		if ( ! is_wp_error( create_plugin_uploads_folder() ) && ! empty( create_plugin_uploads_folder() ) ) {
			$generate_zip_name = sprintf( 'plugins-%s.zip', wp_generate_uuid4() );

			if ( empty( $zip_name ) || ! is_string( $zip_name ) ) {
				$zip_name = $generate_zip_name;
			}

			if ( '.zip' !== substr( $zip_name, -4 ) ) {
				$zip_name .= '.zip';
			}

			$zip_path = sprintf( '%s/%s', create_plugin_uploads_folder(), $zip_name );
			log_request( 'plugin_zip_start' );
			foreach ( $included_plugins as $plugin ) {
				$plugin_folder_name      = dirname( $plugin );
				$plugin_folder_path_file = get_plugin_file_full_path( $plugin );
				$plugin_folder_path      = get_plugin_file_full_path( dirname( $plugin ) );

				// exclude plugins that we explicitly don't want to download
				if ( in_array( $plugin_folder_name, get_excluded_plugins(), true ) ) {
					continue;
				}

				// if the plugin has disabled updates, include it in the list of premium plugins
				// only zip up plugins that are not available on WordPress.org
				if ( in_array( $plugin_folder_name, premium_plugins_list(), true ) || is_premium_plugin( $plugin_folder_path_file ) ) {
					$zip_include_plugins[] = $plugin_folder_path;
					$saved_plugins_list[]  = $plugin_folder_name;
				}
			}//end foreach

			if ( empty( $zip_include_plugins ) ) {
				$message = __( 'No premium plugins are active on the site. If there are premium plugins, they may be excluded from downloading by the plugin settings.', get_textdomain() );
			}

			if ( ! empty( $zip_include_plugins ) ) {
				$additional_files = [];
				// include a main plugin file in the zip so we can install the premium plugins
				$plugin_file = sprintf( '%s/scaffold/cshp-premium-plugins.php', __DIR__ );
				if ( file_exists( $plugin_file ) ) {
					// add the plugin file to the root of the zip file rather than as the entire path of the file
					$additional_files[] = [ $plugin_file, wp_basename( $plugin_file ) ];
				}

				// include the generated composer.json file that keeps track of the plugins installed, so we can activate the premium plugins after they are installed.
				if ( is_file( get_tracker_file() ) ) {
					$additional_files[] = [ get_tracker_file(), wp_basename( get_tracker_file() ) ];
				}

				// if we have a list of plugins that we want zipped up, check again to make sure that a zip of these plugins does not already exist
				$plugins_to_zip    = array_map( 'wp_basename', $zip_include_plugins );
				$find_archive_file = get_archive_zip_file_by_contents( $plugins_to_zip );

				if ( does_zip_exists( $find_archive_file ) && ! is_archive_zip_old( wp_basename( $find_archive_file ) ) ) {
					log_request( 'plugin_zip_download', '', $find_archive_file );
					return $find_archive_file;
				}

				$zip_result = create_zip( $zip_path, $zip_include_plugins, $additional_files );
				if ( does_zip_exists( $zip_result ) ) {
					// generate the data about the saved zip contents with the name of the plugins included and the versions of the plugins included
					foreach ( $saved_plugins_list as $plugin_folder ) {
						if ( isset( $plugin_composer_data_list[ $plugin_folder ] ) ) {
							$plugin_zip_contents[ $plugin_folder ] = $plugin_composer_data_list[ $plugin_folder ];
						}
					}

					ksort( $plugin_zip_contents );

					save_plugin_archive_zip_file( $zip_path, $plugin_zip_contents );
					log_request( 'plugin_zip_create_complete' );
					log_request( 'plugin_zip_download' );
					return $zip_result;
				} else {
					$message = $zip_result;
					log_request( 'plugin_zip_error', $message );
				}//end if
			}//end if
		} else {
			$message = __( 'Error: Ziparchive PHP class does not exist or the PHP zip module is not installed', get_textdomain() );
			log_request( 'plugin_zip_error', $message );
		}//end if
	} else {
		$message = __( 'Error: Ziparchive PHP class does not exist or the PHP zip module is not installed', get_textdomain() );
		log_request( 'plugin_zip_error', $message );
	}//end if

	return $message;
}

/**
 * Zip up the themes that are not available on wordpress.org
 *
 * @param string $zip_name File name of the themes .zip file.
 *
 * @return string Path to the premium themes zip file or error message if the zip file cannot be created.
 */
function zip_premium_themes( $zip_name = '' ) {
	$themes                   = get_active_themes();
	$excluded_themes          = get_excluded_themes();
	$zip_include_themes       = [];
	$message                  = '';
	$theme_composer_data_list = [];
	$saved_themes_list        = [];
	$theme_zip_contents       = [];

	foreach ( generate_composer_installed_themes() as $theme_folder_name => $theme_version ) {
		$clean_folder_name                              = str_replace( [ 'premium-theme/', 'wpackagist-theme/' ], '', $theme_folder_name );
		$theme_composer_data_list[ $clean_folder_name ] = $theme_version;
	}

	if ( class_exists( '\ZipArchive' ) ) {
		// if we are using WP CLI, allow the zip of themes to be generated multiple times
		if ( ! is_wp_cli_environment() ) {
			if ( does_zip_exists( get_premium_theme_zip_file() ) && ! is_theme_zip_old() ) {
				log_request( 'theme_zip_download' );
				return get_premium_theme_zip_file();
			} else {
				log_request( 'themes_zip_download', __( 'Attempt download but theme zip does not exists or has not been generated. Generate zip.', get_textdomain() ) );

			}
		}

		if ( ! is_wp_error( create_plugin_uploads_folder() ) && ! empty( create_plugin_uploads_folder() ) ) {
			$generate_zip_name = sprintf( 'themes-%s.zip', wp_generate_uuid4() );

			if ( empty( $zip_name ) || ! is_string( $zip_name ) ) {
				$zip_name = $generate_zip_name;
			}

			if ( '.zip' !== substr( $zip_name, -4 ) ) {
				$zip_name .= '.zip';
			}

			$zip_path = sprintf( '%s/%s', create_plugin_uploads_folder(), $zip_name );
			log_request( 'theme_zip_start' );
			foreach ( $themes as $theme_folder_name ) {
				$theme = wp_get_theme( $theme_folder_name );

				// only zip up themes that are not available on WordPress.org
				if ( ! $theme->exists() ||
					 in_array( $theme_folder_name, $excluded_themes, true ) ||
					 is_theme_available( $theme_folder_name, $theme->get( 'Version' ) ) ) {
					continue;
				}

				$zip_include_themes[] = $theme->get_stylesheet_directory();
				$theme_zip_contents[] = $theme_folder_name;
			}//end foreach

			if ( empty( $zip_include_themes ) ) {
				$message = __( 'No premium themes are active on the site. If there are premium themes, they may be excluded from downloading by the plugin settings.', get_textdomain() );
			}

			if ( ! empty( $zip_include_themes ) ) {
				$additional_files = [];
				// include a main style.css file in the zip so we can install the premium themes
				$style_css_file = sprintf( '%s/scaffold/style.css', __DIR__ );
				if ( file_exists( $style_css_file ) ) {
					// add the style.css file to the root of the zip file rather than as the entire path of the file
					$additional_files[] = [ $style_css_file, basename( $style_css_file ) ];
				}

				// include a main index.php file in the zip so we can install the premium themes
				$index_php_file = sprintf( '%s/scaffold/index.php', __DIR__ );
				if ( file_exists( $index_php_file ) ) {
					// add the index.php file to the root of the zip file rather than as the entire path of the file
					$additional_files[] = [ $index_php_file, basename( $index_php_file ) ];
				}

				// include another CSS file in the zip so we can identify the premium themes folder
				// based on a unique file name
				$premium_themes_css_file = sprintf( '%s/scaffold/1-cshp-premium-themes.css', __DIR__ );
				if ( file_exists( $premium_themes_css_file ) ) {
					// add the style.css file to the root of the zip file rather than as the entire path of the file
					$additional_files[] = [ $premium_themes_css_file, basename( $premium_themes_css_file ) ];
				}

				$zip_result = create_zip( $zip_path, $zip_include_themes, $additional_files );
				if ( file_exists( $zip_result ) ) {
					// generate the composer tracker file just in case it has not been updated since before the tracker
					// file was created
					create_plugin_tracker_file();
					save_theme_zip_file( $zip_path );
					foreach ( $saved_themes_list as $theme_folder ) {
						if ( isset( $theme_composer_data_list[ $theme_folder ] ) ) {
							$theme_zip_contents[ $theme_folder ] = $theme_composer_data_list[ $theme_folder ];
						}
					}

					save_theme_zip_file_contents( $theme_zip_contents );
					log_request( 'theme_zip_create_complete' );
					log_request( 'theme_zip_download' );
					return get_premium_theme_zip_file();
				} else {
					$message = $zip_result;
					log_request( 'theme_zip_error', $zip_result );
				}
			}//end if
		}//end if
	} else {
		$message = __( 'Error: Ziparchive PHP class does not exist or the PHP zip module is not installed', get_textdomain() );
		log_request( 'theme_zip_error', $message );
	}//end if

	return $message;
}

/**
 * Check if the plugin or theme zip exists at the path
 *
 * @param string $zip_file_path File path to the zip.
 *
 * @return bool True if the zip exists and false if the zip does not exist.
 */
function does_zip_exists( $zip_file_path ) {
	if ( empty( $zip_file_path ) || ! is_string( $zip_file_path ) ) {
		return false;
	}

	// use the native PHP functions instead of the WP Filesystem API method $wp_filesystem->exists
	// $wp_filesystem->exists throws errors on FTP FS https://github.com/pods-framework/pods/issues/6242
	return file_exists( $zip_file_path );
}

/**
 * Add rewrite rules to enable the downloading of the missing plugins as a zip file
 *
 * @return void
 */
function add_rewrite_rules_endpoint() {
	add_rewrite_rule( '^cshp-plugin-tracker/plugin/download?$', 'index.php?cshp_plugin_tracker=plugin&cshp_plugin_tracker_action=download', 'top' );
	add_rewrite_rule( '^cshp-plugin-tracker/theme/download?$', 'index.php?cshp_plugin_tracker=theme&cshp_plugin_tracker_action=download', 'top' );
}
add_action( 'init', __NAMESPACE__ . '\add_rewrite_rules_endpoint' );

/**
 * Add query arguments for the rewrite rule that enable users to download missing plugins
 *
 * @param array $vars Current list of query vars.
 *
 * @return array List of built-in and custom query vars.
 */
function add_rewrite_query_vars( $vars ) {
	return array_merge(
		[
			'cshp_plugin_tracker',
			'cshp_plugin_tracker_action',
			'cshp_pt_cpr',
			'cshp_pt_plugins',
		],
		$vars
	);
}
add_filter( 'query_vars', __NAMESPACE__ . '\add_rewrite_query_vars' );

/**
 * Add a REST API endpoint for downloading the missing plugins
 *
 * @return void
 */
function add_rest_api_endpoint() {
	$route_args = [
		[
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\download_plugin_zip_rest',
			'permission_callback' => '__return_true',
		],
	];

	register_rest_route( 'cshp-plugin-tracker', '/plugin/download', $route_args );

	$route_args = [
		[
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\download_theme_zip_rest',
			'permission_callback' => '__return_true',
		],
	];

	register_rest_route( 'cshp-plugin-tracker', '/theme/download', $route_args );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\add_rest_api_endpoint' );

/**
 * The token to verify.
 *
 * @param string $passed_token The token that is used to verify that premium plugins can be downloaded from this website.
 *
 * @return bool True if the token was verified. False if the token could not be verified.
 */
function is_token_verify( $passed_token ) {
	$stored_token = get_stored_token();

	if ( ! empty( $passed_token ) && ! empty( $stored_token ) && $passed_token === $stored_token ) {
		return true;
	}

	return false;
}

/**
 * Verify that the requesting site's IP address is whitelisted with the Cornershop Plugin Recovery website and therefore is authorized to download the plugins from this website.
 *
 * @return bool True if the IP address is verified. False otherwise.
 */
function is_ip_address_verify() {
	$url = sprintf( '%s/wp-json/cshp-plugin-backup/verify/ip-address', get_plugin_update_url() );

	$request = wp_safe_remote_head(
		$url,
		[
			'timeout' => 12,
			'headers' => [
				'cpr-ip-address' => get_request_ip_address(),
			],
		]
	);

	if ( 200 === wp_remote_retrieve_response_code( $request ) && true === boolval( wp_remote_retrieve_header( $request, 'cpr-ip-address-verified' ) ) ) {
		return true;
	}

	return false;
}

/**
 * Check if a user or program is allowed to generate a zip file that contains the premium plugins and premium theme from this website.
 *
 * @param string $token Stored token on this website that is used to verify that a zip file can be generated.
 *
 * @return bool True if the token is verified or if the IP address is whitelisted. False otherwise.
 */
function is_authorized( $token = '' ) {
	return ! empty( $token ) ? is_token_verify( $token ) : is_ip_address_verify();
}

/**
 * Get the domain that corresponds to the site key. The site key should be stored in the generated composer.json file
 * and is used to automatically download the premium plugins without having to specify the domain or generate a token,
 *
 * @param string $site_key The generated site key on Cornershop Plugin Recovery.
 *
 * @return string The domain that a site key corresponds to.
 */
function get_domain_from_site_key( $site_key = '' ) {
	$composer_file = get_tracker_file();
	if ( empty( $site_key ) ) {
		if ( ! empty( get_site_key() ) ) {
			$site_key = get_site_key();
		} elseif ( ! empty( $composer_file ) && file_exists( $composer_file ) ) {
			$composer_file = wp_json_file_decode( $composer_file, [ 'associative' => true ] );

			if ( ! empty( $composer_file['extra'] ) && ! empty( $composer_file['extra']['cpr-site-key'] ) ) {
				$site_key = $composer_file['extra']['cpr-site-key'];
			}
		}
	}

	if ( ! empty( $site_key ) ) {
		$url     = sprintf( '%s/wp-json/cshp-plugin-backup/verify/site-key', get_plugin_update_url() );
		$url     = add_query_arg( [ 'cpr_site_key' => $site_key ], $url );
		$request = wp_safe_remote_get( $url );

		if ( 200 === wp_remote_retrieve_response_code( $request ) ) {
			$body = json_decode( wp_remote_retrieve_body( $request ), true );

			if ( isset( $body['result'] ) && ! empty( $body['result']['domain'] ) ) {
				$domain       = $body['result']['domain'];
				$domain_parts = wp_parse_url( $body['result']['domain'] );
				// if there is no scheme, assume that the domain is using https
				if ( ! empty( $domain_parts ) && empty( $domain_parts['scheme'] ) && ( ! empty( $domain_parts['host'] ) || ! empty( $domain_parts['path'] ) ) ) {
					// sometimes the domain host is parsed as being the domain path if not http or https is specified at the beginning
					$domain = sprintf( 'https://%s', $domain );
				}

				return $domain;
			}
		}
	}//end if

	return;
}

/**
 * REST endpoint for downloading the premium plugins
 *
 * @param \WP_REST_Request $request WP REST API request.
 *
 * @return void|\WP_REST_Response Zip file for download or JSON response when there is an error.
 */
function download_plugin_zip_rest( $request ) {
	$passed_token         = trim( sanitize_text_field( $request->get_param( 'token' ) ) );
	$try_whitelist_bypass = $request->has_param( 'bypass' );
	$diff_only            = $request->has_param( 'diff' );
	$not_exists           = $request->has_param( 'not_exists' );
	// determine if the file url should just returned as a string rather than redirecting to the zip for download
	$echo = $request->has_param( 'echo' );

	$skip_plugins    = [];
	$zip_all_plugins = true === boolval( sanitize_text_field( $request->get_param( 'include_all_plugins' ) ) );
	$plugins         = $request->get_param( 'plugins' );
	$clean_plugins   = [];

	if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
		log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
		return new \WP_REST_Response(
			[
				'error'   => true,
				'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() ),
			],
			403
		);
	}

	if ( ! is_authorized( $passed_token ) ) {
		log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', get_textdomain() ) );
		return new \WP_REST_Response(
			[
				'error'   => true,
				'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() ),
			],
			403
		);
	}

	// if we passed plugins that we want archived, sanitize the passed query arguments
	if ( ! empty( $plugins ) ) {
		foreach ( $plugins as $plugin_name => $version ) {
			$plugin_name    = sanitize_text_field( $plugin_name );
			$plugin_version = sanitize_text_field( $version );

			if ( ! empty( $plugin_name ) ) {
				// if we passed the plugin version, then compare the passed plugin version to the one installed on this website.
				if ( $diff_only && ! empty( $plugin_version ) ) {
					$currently_installed_plugin = get_plugins( '/' . $plugin_name );

					// if the plugin was found, the information is indexed by the plugin file name
					if ( ! empty( $currently_installed_plugin ) ) {
						$plugin_file                = array_key_first( $currently_installed_plugin );
						$currently_installed_plugin = $currently_installed_plugin[ $plugin_file ];

						// if the passed version is the same as the live version, skip zipping this plugin
						if ( ! empty( $currently_installed_plugin['Version'] ) && $currently_installed_plugin['Version'] === $version ) {
							$skip_plugins[] = $plugin_name;
							continue;
						}
					}
				}

				$clean_plugins[] = $plugin_name;
			}
		}//end foreach
	}//end if

	// if we only want to download plugins that don't exist on the other website based on what was passed into this request, then exclude the plugins that were passed
	if ( $not_exists && ! empty( $clean_plugins ) ) {
		add_filter(
			'cshp_pt_exclude_plugins',
			function( $plugin_folders ) use ( $clean_plugins ) {
				return array_merge( $plugin_folders, $clean_plugins );
			},
			10
		);
		$clean_plugins = [];
	} elseif ( $diff_only && ! empty( $skip_plugins ) ) {
		// exclude the plugins where the version number passed in the request matches the version installed on this website
		add_filter(
			'cshp_pt_exclude_plugins',
			function( $plugin_folders ) use ( $skip_plugins ) {
				return array_merge( $plugin_folders, $skip_plugins );
			},
			10
		);
		$clean_plugins = [];
	}

	$zip_file_result = zip_premium_plugins_include( $clean_plugins, $zip_all_plugins );

	if ( does_zip_exists( $zip_file_result ) ) {
		// if the user just wanted the URL to the zip file, then output that. Normally this would come from a WP CLI command
		if ( $echo === true ) {
			$zip_file_url = home_url( sprintf( '/%s', str_replace( ABSPATH, '', $zip_file_result ) ) );
			return new \WP_REST_Response(
				[
					'error'   => true,
					'message' => esc_html__( 'Successfully generated zip file of premium plugins' ),
					'result'  => [
						'url' => $zip_file_url,
					],
				],
				200
			);
		} else {
			send_premium_plugins_zip_for_download( $zip_file_result );
		}
	}

	$message = __( 'Plugin zip file does not exist or cannot be generated', get_textdomain() );

	if ( is_string( $zip_file_result ) && ! empty( $zip_file_result ) ) {
		$message = $zip_file_result;
	}

	return new \WP_REST_Response(
		[
			'error'   => true,
			'message' => esc_html( $message ),
		],
		410
	);
}

/**
 * REST endpoint for downloading the premium themes
 *
 * @param \WP_REST_Request $request WP REST API request.
 *
 * @return void|\WP_REST_Response Zip file for download or JSON response when there is an error.
 */
function download_theme_zip_rest( $request ) {
	$passed_token         = trim( sanitize_text_field( $request->get_param( 'token' ) ) );
	$try_whitelist_bypass = $request->has_param( 'bypass' );

	if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
		log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
		return new \WP_REST_Response(
			[
				'error'   => true,
				'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() ),
			],
			403
		);
	}

	if ( ! is_authorized( $passed_token ) ) {
		log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', get_textdomain() ) );
		return new \WP_REST_Response(
			[
				'error'   => true,
				'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() ),
			],
			403
		);
	}

	$zip_file_result = zip_premium_themes();

	if ( $zip_file_result === get_premium_theme_zip_file() && does_zip_exists( get_premium_theme_zip_file() ) ) {
		send_premium_themes_zip_for_download();
	}

	$message = __( 'Theme zip file does not exist or cannot be generated', get_textdomain() );

	if ( is_string( $zip_file_result ) && ! empty( $zip_file_result ) ) {
		$message = $zip_file_result;
	}

	return new \WP_REST_Response(
		[
			'error'   => true,
			'message' => esc_html( $message ),
		],
		410
	);
}

/**
 * REST endpoint for downloading the premium plugins
 *
 * @param \WP_Query $query Current WordPress query.
 *
 * @return void Zip file for download or print error when something goes wrong.
 */
function download_plugin_zip_rewrite( &$query ) {
	if ( isset( $query->query_vars['cshp_plugin_tracker'] )
		 && 'plugin' === $query->query_vars['cshp_plugin_tracker']
		 && 'download' === $query->query_vars['cshp_plugin_tracker_action'] ) {

		$passed_token         = trim( sanitize_text_field( $_GET['token'] ) );
		$try_whitelist_bypass = isset( $_GET['bypass'] );
		$plugins              = $_GET['cshp_pt_plugins'];
		$clean_plugins        = [];

		if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
			log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
			http_response_code( 403 );
			esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() );
			exit;
		}

		if ( ! is_authorized( $passed_token ) ) {
			log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', get_textdomain() ) );
			http_response_code( 403 );
			esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() );
			exit;
		}

		// if we passed plugins that we want archived, sanitize the passed query arguments
		if ( ! empty( $plugins ) ) {
			foreach ( $plugins as $plugin ) {
				$clean_plugin = trim( sanitize_text_field( $plugin ) );
				if ( ! empty( $clean_plugin ) ) {
					$clean_plugins[] = $clean_plugin;
				}
			}
		}

		if ( ! empty( $clean_plugins ) ) {
			$zip_file_result = zip_premium_plugins_include( $clean_plugins );
		} else {
			$zip_file_result = zip_premium_plugins_include();
		}

		if ( does_zip_exists( $zip_file_result ) ) {
			send_premium_plugins_zip_for_download( $zip_file_result );
		}

		http_response_code( 410 );
		echo esc_html( $zip_file_result );
		exit;
	}//end if
}
add_action( 'parse_request', __NAMESPACE__ . '\download_plugin_zip_rewrite', 9999 );

/**
 * REST endpoint for downloading the premium themes
 *
 * @param \WP_Query $query Current WordPress query.
 *
 * @return void Zip file for download or print error when something goes wrong.
 */
function download_theme_zip_rewrite( &$query ) {
	if ( isset( $query->query_vars['cshp_plugin_tracker'] )
		 && 'theme' === $query->query_vars['cshp_plugin_tracker']
		 && 'download' === $query->query_vars['cshp_plugin_tracker_action'] ) {

		$passed_token         = trim( sanitize_text_field( $_GET['token'] ) );
		$try_whitelist_bypass = isset( $_GET['bypass'] );

		if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
			log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
			http_response_code( 403 );
			esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() );
			exit;
		}

		if ( ! is_authorized( $passed_token ) ) {
			log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', get_textdomain() ) );
			http_response_code( 403 );
			esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', get_textdomain() );
			exit;
		}

		$zip_file_result = zip_premium_themes();

		if ( $zip_file_result === get_premium_theme_zip_file() && does_zip_exists( get_premium_theme_zip_file() ) ) {
			send_premium_themes_zip_for_download();
		}

		http_response_code( 410 );
		echo esc_html( $zip_file_result );
		exit;
	}//end if
}
add_action( 'parse_request', __NAMESPACE__ . '\download_theme_zip_rewrite', 9999 );

/**
 * Redirect the browser to the premium plugins Zip file so the download can initiate
 *
 * @return void
 */
function send_premium_plugins_zip_for_download( $zip_file_path = '' ) {
	if ( empty( $zip_file_path ) ) {
		$zip_file_path = get_premium_plugin_zip_file();
	}

	http_response_code( 302 );
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
	header( 'Cache-Control: post-check=0, pre-check=0', false );
	header( 'Pragma: no-cache' );
	header( 'Content-Disposition: attachment; filename="premium-plugins.zip"' );
	wp_safe_redirect( home_url( sprintf( '/%s', str_replace( ABSPATH, '', $zip_file_path ) ) ) );
	exit;
}

/**
 * Redirect the browser to the premium themes Zip file so the download can initiate
 *
 * @return void
 */
function send_premium_themes_zip_for_download() {
	http_response_code( 302 );
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
	header( 'Cache-Control: post-check=0, pre-check=0', false );
	header( 'Pragma: no-cache' );
	header( 'Content-Disposition: attachment; filename="premium-themes.zip"' );
	wp_safe_redirect( home_url( sprintf( '/%s', str_replace( ABSPATH, '', get_premium_theme_zip_file() ) ) ) );
	exit;
}

/**
 * Test if the plugin zip is older than the last time the plugin tracker file was created
 *
 * Used as flag to determine if we should regenerate the plugins zip file
 *
 * @return bool True if the plugin zip file is older than the last time the composer.json file was created.
 */
function is_plugin_zip_old() {
	$is_plugin_zip_old                = true;
	$now                              = current_datetime();
	$plugin_zip_create_complete_query = new \WP_Query(
		[
			'post_type'              => get_log_post_type(),
			'posts_per_page'         => 1,
			'post_status'            => 'private',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'tax_query'              => [
				[
					'taxonomy' => get_log_taxonomy(),
					'field'    => 'slug',
					'terms'    => 'plugin_zip_create_complete',
				],
			],
		]
	);

	$file_create_query = new \WP_Query(
		[
			'post_type'              => get_log_post_type(),
			'posts_per_page'         => 1,
			'post_status'            => 'private',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'tax_query'              => [
				[
					'taxonomy' => get_log_taxonomy(),
					'field'    => 'slug',
					'terms'    => 'tracker_file_create',
				],
			],
		]
	);

	if ( ! is_wp_error( $plugin_zip_create_complete_query ) && ! is_wp_error( $file_create_query ) && $plugin_zip_create_complete_query->have_posts() ) {
		$plugin_zip_create_complete_date_time = get_post_datetime( $plugin_zip_create_complete_query->posts[0] );
		if ( $file_create_query->have_posts() ) {
			$file_create_date_time = get_post_datetime( $file_create_query->posts[0] );

			// if the plugin zip file was created after the last time the composer.json file was updated, then the plugin zip file is not old
			if ( $file_create_date_time < $plugin_zip_create_complete_date_time ) {
				$is_plugin_zip_old = false;
			}
		} elseif ( empty( $file_create_query->have_posts() ) ) {
			// if the plugin zip was generated but the composer file was not generated, then the plugin zip is not old
			// could occur if the cron job that generates the composer.json file has not run yet but we have generated the zip
			$is_plugin_zip_old = false;
		} elseif ( is_wordpress_org_external_request_blocked() && $now->format( 'Y-m-d' ) === $plugin_zip_create_complete_date_time->format( 'Y-m-d' ) ) {
			$is_plugin_zip_old = false;
		}
	}

	return $is_plugin_zip_old;
}

/**
 * Test if the themes zip is older than the last time the plugin tracker file was created
 *
 * Used as flag to determine if we should regenerate the themes zip file
 *
 * @return bool True if the themes zip file is older than the last time the composer.json file was created.
 */
function is_theme_zip_old() {
	$theme_zip_create_complete_query = new \WP_Query(
		[
			'post_type'              => get_log_post_type(),
			'posts_per_page'         => 1,
			'post_status'            => 'private',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'tax_query'              => [
				[
					'taxonomy' => get_log_taxonomy(),
					'field'    => 'slug',
					'terms'    => 'theme_zip_create_complete',
				],
			],
		]
	);

	$file_create_query = new \WP_Query(
		[
			'post_type'              => get_log_post_type(),
			'posts_per_page'         => 1,
			'post_status'            => 'private',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'tax_query'              => [
				[
					'taxonomy' => get_log_taxonomy(),
					'field'    => 'slug',
					'terms'    => 'tracker_file_create',
				],
			],
		]
	);

	if ( ! is_wp_error( $theme_zip_create_complete_query ) &&
		 ! is_wp_error( $file_create_query ) &&
		 $theme_zip_create_complete_query->have_posts() &&
		 $file_create_query->have_posts() ) {
		$theme_zip_create_complete_date = get_post_datetime( $theme_zip_create_complete_query->posts[0] );
		$file_create_date               = get_post_datetime( $file_create_query->posts[0] );

		// if the theme zip file was created after the last time the composer.json file was updated,
		// then the theme zip file is not old
		if ( $theme_zip_create_complete_date > $file_create_date ) {
			return false;
		}
	}

	return true;
}

/**
 * Log the times when actions are taken related to the plugin like generating a zip, creating a token,
 * downloading the zip, etc...
 *
 * @param string $type Type of message to log. Should be slug of the log custom taxonomy.
 * @param string $message Custom message to override the default message.
 * @param string $archive_zip_url URL to the archive zip that was downloaded
 *
 * @return int Post ID of the log post
 */
function log_request( $type, $message = '', $archive_zip_url = '' ) {
	global $wp;
	$get_clean      = [];
	$result_post_id = 0;

	if ( ! empty( $_GET ) ) {
		foreach ( $_GET as $key => $get ) {
			$get_clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $get );
		}
	}

	// repopulate the list of allowed terms in case we added more
	generate_default_log_terms();

	$request_url            = add_query_arg( array_merge( $get_clean, $wp->query_vars ), home_url( $wp->request ) );
	$allowed_types          = get_allowed_log_types();
	$title                  = '';
	$content                = '';
	$term_object            = null;
	$use_message_for_title  = [ 'plugin_activate', 'plugin_deactivate', 'plugin_uninstall', 'theme_activate', 'theme_deactivate', 'theme_uninstall' ];
	$user_geo_location_data = sprintf( __( 'IP Address: %1$s. Geolocation: %2$s', get_textdomain() ), get_request_ip_address(), get_request_geolocation() );

	if ( ! empty( $message ) ) {
		$content = $message;
	}

	if ( ! is_wp_error( $allowed_types ) ) {
		foreach ( $allowed_types as $allowed_type ) {
			if ( $type !== $allowed_type->slug ) {
				continue;
			}

			$term_object = $allowed_type;
			$title       = sprintf( '%s:', $term_object->name );

			if ( false !== strpos( $type, 'token_' ) ) {
				$title = sprintf( '%s %s', $title, get_stored_token() );
			} elseif ( false !== strpos( $type, 'plugin_zip' ) ) {
				$zip_file = get_premium_plugin_zip_file();

				if ( ! empty( $archive_zip_url ) ) {
					$zip_file = $archive_zip_url;
				}

				$title = sprintf( '%s %s', $title, $zip_file );
			} elseif ( false !== strpos( $type, 'theme_zip' ) ) {
				$title = sprintf( '%s %s', $title, get_premium_theme_zip_file() );
			} elseif ( in_array( $type, $use_message_for_title, true ) ) {
				$title = sprintf( '%s %s', $title, $message );
			}

			break;
		}//end foreach
	}//end if

	if ( is_user_logged_in() ) {
		$title = sprintf( __( '%1$s by %2$s', get_textdomain() ), $title, wp_get_current_user()->user_login );
	}

	if ( false !== strpos( $type, 'download' ) ) {
		$content = sprintf( __( 'Downloaded by %s', get_textdomain() ), sanitize_text_field( $user_geo_location_data ) );
	} elseif ( false !== strpos( $type, 'create' ) ) {
		$content = sprintf( __( 'Generated by %s', get_textdomain() ), sanitize_text_field( $user_geo_location_data ) );
	} elseif ( false !== strpos( $type, 'delete' ) ) {
		$content = sprintf( __( 'Deleted by %s', get_textdomain() ), sanitize_text_field( $user_geo_location_data ) );
	} elseif ( false !== strpos( $type, 'verify_fail' ) ) {
		$content = sprintf( __( 'Verification failed by %s', get_textdomain() ), sanitize_text_field( $user_geo_location_data ) );
	} elseif ( false !== strpos( $type, 'activate' ) ) {
		$content = sprintf( __( 'Activated by %s', get_textdomain() ), sanitize_text_field( $user_geo_location_data ) );
	} elseif ( false !== strpos( $type, 'deactivate' ) ) {
		$content = sprintf( __( 'Deactivated by %s', get_textdomain() ), sanitize_text_field( $user_geo_location_data ) );
	} elseif ( false !== strpos( $type, 'uninstall' ) ) {
		$content = sprintf( __( 'Uninstalled by %s', get_textdomain() ), sanitize_text_field( $user_geo_location_data ) );
	}

	if ( ! empty( $title ) && ! empty( $term_object ) ) {
		$result_post_id = wp_insert_post(
			[
				'post_type'    => get_log_post_type(),
				'post_title'   => $title,
				'post_content' => wp_kses_post( $content ),
				'post_status'  => 'private',
				'post_author'  => is_user_logged_in() ? get_current_user_id() : 0,
				'tax_input'    => [
					$term_object->taxonomy => [ $term_object->slug ],
				],
				'meta_input'   => [
					'ip_address'   => get_request_ip_address(),
					'geo_location' => get_request_geolocation(),
					'url'          => $request_url,
				],
			]
		);

		// sometimes the term won't be added to the post on insert due to permissions of the logged-in user
		// https://wordpress.stackexchange.com/questions/210229/tax-input-not-working-wp-insert-post
		if ( ! is_wp_error( $result_post_id ) && ! empty( $result_post_id ) && ! has_term( $term_object->slug, $term_object->taxonomy, $result_post_id ) ) {
			wp_add_object_terms( $result_post_id, $term_object->term_id, $term_object->taxonomy );
		}
	}//end if

	return $result_post_id;
}

/**
 * Get the easily spoofed, unreliable IP address of users who request an endpoint
 *
 * @return mixed IP address of the user/server/computer that pinged the site.
 */
function get_request_ip_address() {
	$ip = __( 'IP address not found in request', get_textdomain() );

	if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
		// check ip from share internet
		$ip = $_SERVER['HTTP_CLIENT_IP'];
	} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		// to check ip is pass from proxy
		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	} else {
		$ip = $_SERVER['REMOTE_ADDR'];
	}

	return $ip;
}

/**
 * Try to get the location of the current user so we can log who is doing actions like adding plugins and themes
 *
 * @return string Location of the current user or empty string if no location can be found
 */
function get_request_geolocation() {
	$city         = '';
	$region       = '';
	$country      = '';
	$country_code = '';
	$postal_code  = '';
	$location     = '';
	$ip_address   = get_request_ip_address();

	// test if Kinsta has Geo IP tool enabled for this site
	if ( isset( $_SERVER['GEOIP_COUNTRY_NAME'] ) && ! empty( $_SERVER['GEOIP_COUNTRY_NAME'] ) ) {
		$country = sanitize_text_field( $_SERVER['GEOIP_COUNTRY_NAME'] );
	}

	if ( isset( $_SERVER['GEOIP_COUNTRY_CODE'] ) && ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
		$country_code = sanitize_text_field( $_SERVER['GEOIP_COUNTRY_CODE'] );
	}

	if ( isset( $_SERVER['GEOIP_REGION'] ) && ! empty( $_SERVER['GEOIP_REGION'] ) ) {
		$region = sanitize_text_field( $_SERVER['GEOIP_REGION'] );
	}

	if ( isset( $_SERVER['GEOIP_CITY'] ) && ! empty( $_SERVER['GEOIP_CITY'] ) ) {
		$city = sanitize_text_field( $_SERVER['GEOIP_CITY'] );
	}

	if ( isset( $_SERVER['GEOIP_POSTAL_CODE'] ) && ! empty( $_SERVER['GEOIP_POSTAL_CODE'] ) ) {
		$postal_code = sanitize_text_field( $_SERVER['GEOIP_POSTAL_CODE'] );
	}

	if ( ! empty( $ip_address ) && ( empty( $country ) ) ) {
		$url           = sprintf( 'https://ipapi.co/%s/json', $ip_address );
		$location_info = wp_remote_get( $url );

		if ( ! is_wp_error( $location_info ) && 200 === wp_remote_retrieve_response_code( $location_info ) ) {
			$response = json_decode( wp_remote_retrieve_body( $location_info ), true );

			if ( is_null( $response ) ) {
				$response = [];
			}

			if ( isset( $response['country_name'] ) && ! empty( $response['country_name'] ) ) {
				$country = sanitize_text_field( $response['country_name'] );
			}

			if ( isset( $response['country_code'] ) && ! empty( $response['country_code'] ) ) {
				$country_code = sanitize_text_field( $response['country_code'] );
			}

			if ( isset( $response['region'] ) && ! empty( $response['region'] ) ) {
				$region = sanitize_text_field( $response['region'] );
			}

			if ( isset( $response['city'] ) && ! empty( $response['city'] ) ) {
				$city = sanitize_text_field( $response['city'] );
			}

			if ( isset( $response['postal'] ) && ! empty( $response['postal'] ) ) {
				$postal_code = sanitize_text_field( $response['postal'] );
			}
		}//end if

		if ( empty( $country ) ) {
			$url           = sprintf( 'https://get.geojs.io/v1/ip/geo/%s.json', $ip_address );
			$location_info = wp_remote_get( $url );

			if ( ! is_wp_error( $location_info ) && 200 === wp_remote_retrieve_response_code( $location_info ) ) {
				$response = json_decode( wp_remote_retrieve_body( $location_info ), true );

				if ( is_null( $response ) ) {
					$response = [];
				}

				if ( isset( $response['country'] ) && ! empty( $response['country'] ) ) {
					$country = sanitize_text_field( $response['country'] );
				}

				if ( isset( $response['country_code'] ) && ! empty( $response['country_code'] ) ) {
					$country_code = sanitize_text_field( $response['country_code'] );
				}

				if ( isset( $response['region'] ) && ! empty( $response['region'] ) ) {
					$region = sanitize_text_field( $response['region'] );
				}

				if ( isset( $response['city'] ) && ! empty( $response['city'] ) ) {
					$city = sanitize_text_field( $response['city'] );
				}
			}//end if
		}//end if
	}//end if

	if ( ! empty( $country ) ) {
		$location = sprintf( '%s, %s, %s', $city, $region, $country );

		if ( ! empty( $postal_code ) ) {
			$location = sprintf( '%s, %s', $location, $postal_code );
		}
	}

	return $location;
}

/**
 * Save the name of the premium plugins zip file that is saved with only some plugins.
 *
 * @param string $zip_file_path Path to the zip file to save.
 * @param array  $zip_file_contents Array of plugins that are included in this zip.
 *
 * @return void
 */
function save_plugin_archive_zip_file( $zip_file_path, $zip_file_contents ) {
	global $wp;
	$get_clean          = [];
	$result_post_id     = 0;
	$saved_plugins      = [];
	$saved_plugin_terms = [];

	if ( ! empty( $_GET ) ) {
		foreach ( $_GET as $key => $get ) {
			$get_clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $get );
		}
	}

	foreach ( $zip_file_contents as $plugin_folder => $plugin_details ) {
		if ( term_exists( $plugin_folder, get_archive_taxonomy() ) ) {
			$find_term = get_term_by( 'slug', $plugin_folder, get_archive_taxonomy() );

			if ( ! is_wp_error( $find_term ) ) {
				$saved_plugin_terms[] = $find_term->term_id;
			}

			continue;
		}

		$new_term = wp_insert_term(
			$plugin_folder,
			get_archive_taxonomy(),
			[
				'slug' => $plugin_folder,
			]
		);

		if ( ! is_wp_error( $new_term ) ) {
			$saved_plugin_terms[] = $new_term->term_id;
		}
	}//end foreach

	$format_list = [];

	foreach ( $zip_file_contents as $plugin_folder_name => $plugin_data ) {
		$saved_plugins[] = $plugin_folder_name;
		$format_list[]   = sprintf( '<li>%s</li>', $plugin_folder_name );
	}

	$content = sprintf( '<p>%s</p><ul>%s</ul>', __( 'Archived plugins:', get_textdomain() ), implode( '', $format_list ) );

	$request_url = add_query_arg( array_merge( $get_clean, $wp->query_vars ), home_url( $wp->request ) );

	$result_post_id = wp_insert_post(
		[
			'post_type'    => get_archive_post_type(),
			'post_title'   => __( 'Generate plugin zip file', get_textdomain() ),
			'post_content' => $content,
			'post_status'  => 'private',
			'tax_input'    => [
				get_archive_taxonomy() => [ $saved_plugins ],
			],
			'meta_input'   => [
				'ip_address'                           => get_request_ip_address(),
				'geo_location'                         => get_request_geolocation(),
				'url'                                  => $request_url,
				'cshp_plugin_tracker_zip'              => basename( $zip_file_path ),
				'cshp_plugin_tracker_archived_plugins' => wp_slash( wp_json_encode( $zip_file_contents ) ),
			],
		]
	);

	// sometimes the term won't be added to the post on insert due to permissions of the logged-in user
	// https://wordpress.stackexchange.com/questions/210229/tax-input-not-working-wp-insert-post
	if ( ! is_wp_error( $result_post_id ) && ! empty( $result_post_id ) && ! has_term( $saved_plugins[0], get_archive_taxonomy(), $result_post_id ) ) {
		wp_add_object_terms( $result_post_id, $saved_plugins, get_archive_taxonomy() );
	}

	// after programatically adding the post to  the term, clear the saved cache
	foreach ( $saved_plugin_terms as $term_id ) {
		clean_term_cache( $term_id, get_archive_taxonomy() );
	}
}

/**
 * Save the new path of the theme zip file and delete the old zip file
 *
 * @param string $zip_file_path Path to the zip file to save.
 *
 * @return void
 */
function save_theme_zip_file( $zip_file_path = '' ) {
	$previous_zip = get_premium_theme_zip_file();

	if ( ! empty( $previous_zip ) ) {
		wp_delete_file( $previous_zip );
	}

	if ( does_zip_exists( $zip_file_path ) ) {
		update_option( 'cshp_plugin_tracker_theme_zip', $zip_file_path );
	} else {
		update_option( 'cshp_plugin_tracker_theme_zip', '' );
	}
}

/**
 * Save the name and versions of the themes that were saved to the most recent premium themes zip file.
 *
 * @param array $theme_content_data Associative array with theme folder name as the key and the theme version as the
 * value.
 *
 * @return void
 */
function save_theme_zip_file_contents( $theme_content_data = '' ) {
	if ( does_zip_exists( get_premium_theme_zip_file() ) ) {
		update_option( 'cshp_plugin_tracker_theme_zip_contents', $theme_content_data );
	} else {
		update_option( 'cshp_plugin_tracker_theme_zip_contents', '' );
	}
}

/**
 * Generate the JSON for the composer array template without any of the specific requires for this site
 *
 * @return array Formatted array for use in a composer.json file.
 */
function generate_composer_template() {
	$relative_directory = basename( WP_CONTENT_DIR );
	$plugins_status     = get_plugins_status();
	ksort( $plugins_status );
	$composer = [
		'name'         => sprintf( '%s/wordpress', sanitize_key( get_bloginfo( 'name' ) ) ),
		'description'  => sprintf( __( 'Installed plugins and themes for the WordPress install %s', get_textdomain() ), home_url() ),
		'type'         => 'project',
		'repositories' => [
			'0' => [
				'type' => 'composer',
				'url'  => 'https://wpackagist.org',
				'only' => [
					'wpackagist-muplugin/*',
					'wpackagist-plugin/*',
					'wpackagist-theme/*',
				],
			],
			'1' => [
				'type' => 'composer',
				'url'  => home_url( '/' ),
				'only' => [
					'premium-plugin/*',
					'premium-theme/*',
					'core/wp',
				],
			],
		],
		'require'      => [],
		'extra'        => [
			'installer-paths' => [
				sprintf( '%s/mu-plugins/{$name}/', $relative_directory ) => [
					'type:wordpress-muplugin',
				],
				sprintf( '%s/plugins/{$name}/', $relative_directory ) => [
					'type:wordpress-plugin',
				],
				sprintf( '%s/themes/{$name}/', $relative_directory ) => [
					'type:wordpress-theme',
				],
			],
			'cpr-site-key'    => get_site_key(),
			'plugins_status'  => $plugins_status,
		],
	];

	return $composer;
}

/**
 * Generate the WordPress Version markdown for the READE.md file
 *
 * @return string WordPress version in markdown.
 */
function generate_wordpress_markdown() {
	return sprintf( '## WordPress Version%s- %s', PHP_EOL, get_current_wordpress_version() );
}

/**
 * Generating the markdown for showing the installed themes
 *
 * @param array $composer_json_required Composer JSON array of themes and plugins.
 *
 * @return string Markdown for the installed themes.
 */
function generate_themes_markdown( $composer_json_required ) {
	$public                    = [];
	$premium                   = [];
	$public_markdown           = __( 'No public themes installed', get_textdomain() );
	$premium_markdown          = __( 'No premium themes installed', get_textdomain() );
	$themes_data               = wp_get_themes();
	$current_theme             = wp_get_theme();
	$current_theme_folder_name = '';
	$columns                   = [ 'Status', 'Name', 'Folder', 'Version', 'Theme URL/Author' ];
	$public_table              = [];
	$premium_table             = [];

	if ( ! empty( $current_theme ) ) {
		$theme_path_info           = explode( DIRECTORY_SEPARATOR, $current_theme->get_stylesheet_directory() );
		$current_theme_folder_name = end( $theme_path_info );
	}

	foreach ( $composer_json_required as $theme_name => $version ) {
		$clean_name = str_replace( 'premium-theme/', '', str_replace( 'wpackagist-theme/', '', $theme_name ) );

		if ( false !== strpos( $theme_name, 'wpackagist' ) ) {
			$public[] = $clean_name;
		}

		if ( false !== strpos( $theme_name, 'premium-theme' ) ) {
			$premium[] = $clean_name;
		}
	}

	foreach ( $themes_data as $theme ) {
		$version           = ! empty( $theme->get( 'Version' ) ) ? $theme->get( 'Version' ) : '';
		$theme_path_info   = explode( DIRECTORY_SEPARATOR, $theme->get_stylesheet_directory() );
		$theme_folder_name = end( $theme_path_info );
		$status            = __( 'Inactive', get_textdomain() );

		if ( $theme_folder_name === $current_theme_folder_name ) {
			$status = __( 'Active', get_textdomain() );
		}

		if ( in_array( $theme_folder_name, $public, true ) ) {
			$public_table[ $theme_folder_name ] = [
				$status,
				$theme->get( 'Name' ),
				$theme_folder_name,
				$version,
				sprintf( '[Link](https://wordpress.org/themes/%s)', esc_html( $theme_folder_name ) ),
			];
		} elseif ( in_array( $theme_folder_name, $premium, true ) ) {
			$url = '';

			if ( ! empty( $theme->get( 'ThemeURI' ) ) ) {
				$url = sprintf( '[Link](%s)', esc_url( $theme->get( 'ThemeURI' ) ) );
			} elseif ( ! empty( $theme->get( 'AuthorURI' ) ) ) {
				$url = sprintf( '[Link](%s)', esc_url( $theme->get( 'AuthorURI' ) ) );
			} elseif ( ! empty( $theme->get( 'Author' ) ) ) {
				$url = esc_html( $theme->get( 'Author' ) );
			} else {
				$url = __( 'No author data found.', get_textdomain() );
			}

			$premium_table[ $theme_folder_name ] = [
				$status,
				$theme->get( 'Name' ),
				$theme_folder_name,
				$version,
				$url,
			];
		}//end if
	}//end foreach

	if ( ! empty( $public_table ) ) {
		ksort( $public_table );
		$table = new \TextTable( $columns, [] );
		// increase the max length of the data to account for long urls for theme homepages, otherwise the library
		// will cutoff of the url
		$table->maxlen = 300;
		$table->addData( $public_table );
		$public_markdown = $table->render();
	}

	if ( ! empty( $premium_table ) ) {
		ksort( $premium_table );
		$table = new \TextTable( $columns, [] );
		// increase the max length of the data to account for long urls for theme homepages, otherwise the library
		// will cutoff of the url
		$table->maxlen = 300;
		$table->addData( $premium_table );
		$premium_markdown = $table->render();
	}

	return sprintf(
		'## Themes Installed%1$s
        ### Wordpress.org Themes%1$s
        %2$s
        %1$s
        ### Premium Themes%1$s
        %3$s
        %1$s
        ',
		PHP_EOL,
		$public_markdown,
		$premium_markdown
	);
}

/**
 * Generating the markdown for showing the how to install the currently installed version of WordPress core
 *
 * @return string Markdown for the installing WordPress core with WP CLI.
 */
function generate_wordpress_wp_cli_install_command() {
	return sprintf(
		'## WP-CLI Command to Install WordPress Core%s`wp core download --skip-content --version="%s" --force --path=.`',
		PHP_EOL,
		get_current_wordpress_version()
	);
}

/**
 * Generating the markdown for showing the how to install the public themes using WP CLI
 *
 * @param array  $composer_json_required Composer JSON array of themes and plugins.
 * @param string $context The context that this command will be displayed. The default is markdown. Options are
 * command, raw, and markdown.
 *
 * @return string Markdown for the installing the themes with WP CLI.
 */
function generate_themes_wp_cli_install_command( $composer_json_required, $context = 'markdown' ) {
	$data    = [];
	$install = '';

	if ( is_array( $composer_json_required ) && isset( $composer_json_required['require'] ) ) {
		$composer_json_required = $composer_json_required['require'];
	}

	foreach ( $composer_json_required as $theme_name => $version ) {
		$clean_name = str_replace( 'premium-theme/', '', str_replace( 'wpackagist-theme/', '', $theme_name ) );

		if ( false !== strpos( $theme_name, 'wpackagist-theme' ) ) {
			$data[] = sprintf( 'wp theme install %s --version="%s" --force --skip-plugins --skip-themes', $clean_name, $version );
		}
	}

	if ( ! empty( $data ) ) {
		// return an array of WP_CLI commands that should be run
		if ( 'command' === $context ) {
			$install = $data;
		} else {
			$install = $data = implode( ' && ', $data );
		}
	} else {
		$install = __( 'No public themes installed.', get_textdomain() );
	}

	if ( 'markdown' === $context || empty( $context ) ) {
		$install = sprintf( '`%s`', $data );

		return sprintf(
			'## WP-CLI Command to Install Themes%1$s%2$s%1$s',
			PHP_EOL,
			$install
		);
	}

	return $install;
}

/**
 * Generating the markdown for showing how to zip premium installed themes
 *
 * @param array $composer_json_required Composer JSON array of themes and plugins.
 *
 * @return string Markdown for the zipping installed premium themes.
 */
function generate_themes_zip_command( $composer_json_required ) {
	$data = [];

	foreach ( $composer_json_required as $theme_name => $version ) {
		$clean_name = str_replace( 'premium-theme/', '', str_replace( 'wpackagist-theme/', '', $theme_name ) );

		if ( false !== strpos( $theme_name, 'premium-theme' ) ) {
			$data[] = $clean_name;
		}
	}

	if ( ! empty( $data ) ) {
		$data = sprintf( '`zip -r premium-themes.zip %1$s`', implode( ' ', $data ) );
	} else {
		$data = __( 'No premium themes installed.', get_textdomain() );
	}

	return sprintf(
		'## Command Line to Zip Themes%1$s%2$s%1$s%3$s',
		PHP_EOL,
		__( 'Use command to zip premium themes if the .zip file cannot be created or downloaded', get_textdomain() ),
		$data
	);
}

/**
 * Generating the markdown for showing the WP CLI command to download and install the premium themes.
 *
 * @param string $context The context that this command will be displayed. Default is Markdown.
 *
 * @return string Markdown for downloading and install the premium themes with WP CLI.
 */
function generate_premium_themes_wp_cli_install_command( $context = 'markdown' ) {
	$command = sprintf( 'wp cshp-pt theme-install %s --force', esc_url( get_api_theme_downloads_endpoint() ) );

	if ( 'markdown' === $context ) {
		return sprintf(
			'## WP CLI command to downlaod and install Premium Themes %1$s%2$s%1$s`%3$s`',
			PHP_EOL,
			__( 'Use command to download and install the premium themes', get_textdomain() ),
			$command
		);
	}

	return $command;
}

/**
 * Generating the markdown for showing the wget command to download the zip of the premium plugins.
 *
 * @param string $context The context that this command will be displayed. Default is Markdown.
 *
 * @return string Markdown for download the premium plugins zip file with wget.
 */
function generate_wget_plugins_download_command( $context = 'markdown' ) {
	$command = sprintf( 'wget --content-disposition --output-document=premium-plugins.zip %s', esc_url( get_api_active_plugin_downloads_endpoint() ) );

	if ( 'markdown' === $context ) {
		return sprintf(
			'## Command Line to Download Premium Plugins with wget%1$s%2$s%1$s`%3$s`',
			PHP_EOL,
			__( 'Use command to download the zipped premium plugins', get_textdomain() ),
			$command
		);
	}

	return $command;
}

/**
 * Generating the markdown for showing the cURL command to download the zip of the premium plugins.
 *
 * @param string $context The context that this command will be displayed. Default is Markdown.
 *
 * @return string Markdown for download the premium plugins zip file with cURL.
 */
function generate_curl_plugins_download_command( $context = 'markdown' ) {
	$command = sprintf( 'curl -JLO %s', esc_url( get_api_active_plugin_downloads_endpoint() ) );

	if ( 'markdown' === $context ) {
		return sprintf(
			'## Command Line to Download Premium Plugins with cURL%1$s%2$s%1$s`%3$s`',
			PHP_EOL,
			__( 'Use command to download the zipped premium plugins', get_textdomain() ),
			$command
		);
	}

	return $command;
}

/**
 * Generating the markdown for showing the wget command to download the zip of the premium themes.
 *
 * @param string $context The context that this command will be displayed. Default is Markdown.
 *
 * @return string Markdown for download the premium themes zip file with wget.
 */
function generate_wget_themes_download_command( $context = 'markdown' ) {
	$command = sprintf( 'wget --content-disposition --output-document=premium-themes.zip %s', esc_url( get_api_theme_downloads_endpoint() ) );

	if ( 'markdown' === $context ) {
		return sprintf(
			'## Command Line to Download Premium Themes with wget%1$s%2$s%1$s`%3$s`',
			PHP_EOL,
			__( 'Use command to download the zipped premium themes', get_textdomain() ),
			$command
		);
	}

	return $command;
}

/**
 * Generating the markdown for showing the cURL command to download the zip of the premium themes.
 *
 * @param string $context The context that this command will be displayed. Default is Markdown.
 *
 * @return string Markdown for download the premium themes zip file with cURL.
 */
function generate_curl_themes_download_command( $context = 'markdown' ) {
	$command = sprintf( 'curl -JLO %s', esc_url( get_api_theme_downloads_endpoint() ) );

	if ( 'markdown' === $context ) {
		return sprintf(
			'## Command Line to Download Premium Themes with cURL%1$s%2$s%1$s`%3$s`',
			PHP_EOL,
			__( 'Use command to download the zipped premium themes', get_textdomain() ),
			$command
		);
	}

	return $command;
}

/**
 * Generating the markdown for showing the installed plugins
 *
 * @param array $composer_json_required Composer JSON array of themes and plugins.
 *
 * @return string Markdown for the installed plugins.
 */
function generate_plugins_markdown( $composer_json_required ) {
	$public           = [];
	$premium          = [];
	$public_markdown  = __( 'No public plugins installed', get_textdomain() );
	$premium_markdown = __( 'No premium plugins installed', get_textdomain() );
	$plugins_data     = get_plugins();
	$columns          = [ 'Status', 'Name', 'Folder', 'Version', 'Plugin URL/Author' ];
	$public_table     = [];
	$premium_table    = [];
	$active_plugins   = get_active_plugins();

	foreach ( $composer_json_required as $plugin_name => $version ) {
		$clean_plugin_folder_name = str_replace( 'premium-plugin/', '', str_replace( 'wpackagist-plugin/', '', $plugin_name ) );

		if ( false !== strpos( $plugin_name, 'wpackagist' ) ) {
			$public[] = $clean_plugin_folder_name;
		}

		if ( false !== strpos( $plugin_name, 'premium-plugin' ) ) {
			$premium[] = $clean_plugin_folder_name;
		}
	}

	foreach ( $plugins_data as $plugin_file => $data ) {
		$version       = isset( $data['Version'] ) && ! empty( $data['Version'] ) ? $data['Version'] : '';
		$plugin_folder = dirname( $plugin_file );
		$status        = __( 'Inactive', get_textdomain() );

		if ( in_array( $plugin_file, $active_plugins, true ) ) {
			$status = __( 'Active', get_textdomain() );
		}

		if ( in_array( $plugin_folder, $public, true ) ) {
			$public_table[ $plugin_folder ] = [
				$status,
				$data['Name'],
				$plugin_folder,
				$version,
				sprintf( '[Link](https://wordpress.org/plugins/%s)', esc_html( $plugin_folder ) ),
			];
		} elseif ( in_array( $plugin_folder, $premium, true ) ) {
			$url = '';

			if ( isset( $data['PluginURI'] ) && ! empty( $data['PluginURI'] ) ) {
				$url = sprintf( '[Link](%s)', esc_url( $data['PluginURI'] ) );
			} elseif ( isset( $data['AuthorURI'] ) && ! empty( $data['AuthorURI'] ) ) {
				$url = sprintf( '[Link](%s)', esc_url( $data['AuthorURI'] ) );
			} elseif ( isset( $data['Author'] ) && ! empty( $data['Author'] ) ) {
				$url = esc_html( $data['Author'] );
			} else {
				$url = __( 'No author data found.', get_textdomain() );
			}

			$premium_table[ $plugin_folder ] = [
				$status,
				$data['Name'],
				$plugin_folder,
				$version,
				$url,
			];
		}//end if
	}//end foreach

	if ( ! empty( $public_table ) ) {
		ksort( $public_table );
		$table = new \TextTable( $columns, [] );
		// increase the max length of the data to account for long urls for plugin homepages, otherwise the library
		// will cutoff of the url
		$table->maxlen = 300;
		$table->addData( $public_table );
		$public_markdown = $table->render();
	}

	if ( ! empty( $premium_table ) ) {
		ksort( $premium_table );
		$table = new \TextTable( $columns, [] );
		// increase the max length of the data to account for long urls for plugin homepages, otherwise the library
		// will cutoff of the url
		$table->maxlen = 300;
		$table->addData( $premium_table );
		$premium_markdown = $table->render();
	}

	return sprintf(
		'## Plugins Installed%1$s
        ### Wordpress.org Plugins%1$s
        %2$s
        %1$s
        ### Premium Plugins%1$s
        %3$s
        %1$s
        ',
		PHP_EOL,
		$public_markdown,
		$premium_markdown
	);
}

/**
 * Generating the markdown for showing the how to install the public plugins using WP CLI
 *
 * @param array  $composer_json_required Composer JSON array of themes and plugins.
 * @param string $context The context that this command is used for. Default is for markdown. Options are
 * command, raw, and markdown.
 *
 * @return string Markdown for the installing the plugins with WP CLI.
 */
function generate_plugins_wp_cli_install_command( $composer_json_required, $context = 'markdown' ) {
	$data    = [];
	$install = '';

	if ( is_array( $composer_json_required ) && isset( $composer_json_required['require'] ) ) {
		$composer_json_required = $composer_json_required['require'];
	}

	foreach ( $composer_json_required as $plugin_name => $version ) {
		$clean_name = str_replace( 'premium-plugin/', '', str_replace( 'wpackagist-plugin/', '', $plugin_name ) );

		if ( false !== strpos( $plugin_name, 'wpackagist-plugin' ) ) {
			$data[] = sprintf( 'wp plugin install %s --version="%s" --force --skip-plugins --skip-themes', $clean_name, $version );
		}
	}

	if ( ! empty( $data ) ) {
		// return an array of WP_CLI commands that should be run
		if ( 'command' === $context ) {
			$install = $data;
		} else {
			$install = $data = implode( ' && ', $data );
		}
	} else {
		$install = __( 'No public plugins installed.', get_textdomain() );
	}

	if ( 'markdown' === $context || empty( $context ) ) {
		$install = sprintf( '`%s`', $data );

		return sprintf(
			'## WP-CLI Command to Install Plugins%1$s%2$s%1$s',
			PHP_EOL,
			$install
		);
	}

	return $install;
}

/**
 * Generating the markdown for showing how to zip premium installed plugins
 *
 * @param array $composer_json_required Composer JSON array of themes and plugins.
 *
 * @return string Markdown for the zipping installed premium plugins.
 */
function generate_plugins_zip_command( $composer_json_required ) {
	$data = [];

	foreach ( $composer_json_required as $plugin_name => $version ) {
		$clean_name = str_replace( 'premium-plugin/', '', str_replace( 'wpackagist-plugin/', '', $plugin_name ) );

		if ( false !== strpos( $plugin_name, 'premium-plugin' ) ) {
			$data[] = $clean_name;
		}
	}

	if ( ! empty( $data ) ) {
		$data = sprintf( '`zip -r premium-plugins.zip %1$s`', implode( ' ', $data ) );
	} else {
		$data = __( 'No premium plugins installed.', get_textdomain() );
	}

	return sprintf(
		'## Command Line to Zip Plugins%1$s%2$s%1$s%3$s',
		PHP_EOL,
		__( 'Use command to zip premium plugins if the .zip file cannot be created or downloaded', get_textdomain() ),
		$data
	);
}

/**
 * Generating the markdown for showing the WP CLI command to download and install the premium plugins.
 *
 * @param string $context The context that this command will be displayed. Default is Markdown.
 *
 * @return string Markdown for downloading and install the premium plugins with WP CLI.
 */
function generate_premium_plugins_wp_cli_install_command( $context = 'markdown' ) {
	$command = sprintf( 'wp cshp-pt plugin-install %s --force', esc_url( get_api_active_plugin_downloads_endpoint() ) );

	if ( 'markdown' === $context ) {
		return sprintf(
			'## WP CLI command to downlaod and install Premium Plugins %1$s%2$s%1$s`%3$s`',
			PHP_EOL,
			__( 'Use command to download and install the premium plugins', get_textdomain() ),
			$command
		);
	}

	return $command;
}

/**
 * Generating a table for showing the active plugins so that PMs can add this to the WP Documentation doc
 *
 * @return string HTML listing of active plugins.
 */
function generate_plugins_active_documentation() {
	$active_plugins = get_active_plugins();
	$sort_order     = [];

	$thead = sprintf(
		'<tr><th>%s</th><th>%s</th></tr>',
		__( 'Plugin', get_textdomain() ),
		__( 'Notes', get_textdomain() )
	);
	$tbody = '';

	foreach ( $active_plugins as $plugin ) {
		// exclude this plugin from the list
		if ( __FILE__ === get_plugin_file_full_path( $plugin ) ) {
			continue;
		}

		$data         = get_plugin_data( get_plugin_file_full_path( $plugin ), false, true );
		$sort_order[] = [
			'name'        => $data['Name'],
			'description' => $data['Description'],
		];
	}

	// sort plugin table by the plugin name
	$key_values = array_column( $sort_order, 'name' );
	array_multisort( $key_values, SORT_ASC, $sort_order );

	foreach ( $sort_order as $order ) {
		$tbody .= sprintf( '<tr><td>%s</td><td>%s</td></tr>', wp_strip_all_tags( $order['name'] ), wp_strip_all_tags( $order['description'] ) );
	}

	return sprintf( '<table border="1" class="table wp-list-table widefat" id="cshp-active-plugins"><thead>%s</thead><tbody>%s</tbody></table>', $thead, $tbody );
}

/**
 * Generate a list of active Gravity Forms so that PMs can add this to the WP documentation doc
 *
 * @return string List of Gravity Forms on the site.
 */
function generate_gravityforms_active_documentation() {
	if ( ! class_exists( '\GFAPI' ) || ! is_callable( [ '\GFAPI', 'get_forms' ] ) ) {
		return __( 'The Gravity Forms plugin is not active or no active forms can be detected.', get_textdomain() );
	}

	$forms = \GFAPI::get_forms( true, false, 'title' );
	$html  = '';
	foreach ( $forms as $form ) {
		$html .= sprintf( '<li>%s</li>', rgar( $form, 'title' ) );
	}

	return sprintf( '<ol>%s</ol>', $html );
}

/**
 * Generate a list of active Menus so that PMs can add this to the WP documentation doc
 *
 * @return string List of menus for the site.
 */
function generate_menus_list_documentation() {
	$menus = wp_get_nav_menus();
	$html  = '';

	if ( ! empty( $menus ) ) {
		foreach ( $menus as $menu ) {
			$html .= sprintf( '<li>%s</li>', esc_html( $menu->name ) );
		}
	}

	return sprintf( '<ol>%s</ol>', $html );
}

/**
 * Get the URL to a file relative to the root folder of the plugin
 *
 * @param string $file File to load.
 *
 * @return string URL to the file in the plugin.
 */
function get_plugin_file_uri( $file ) {
	$file = ltrim( $file, '/' );

	$url = null;
	if ( empty( $file ) ) {
		$url = plugin_dir_url( __FILE__ );
	} elseif ( file_exists( plugin_dir_path( __FILE__ ) . '/' . $file ) ) {
		$url = plugin_dir_url( __FILE__ ) . $file;
	}

	return $url;
}

/**
 * Determine if the tracker file should be regenerated based on if WP core, plugins, or themes have changed.
 *
 * @return bool True if the tracker file should update based on some change. False otherwise.
 */
function maybe_update_tracker_file() {
	$composer      = generate_composer_array();
	$composer_file = read_tracker_file();

	// only save the composer.json file if the requirements have changed from the last time the file was saved
	if ( empty( $composer_file ) || ( isset( $composer['require'] ) &&
		 isset( $composer_file['require'] ) &&
		 ! empty( array_diff_assoc( $composer['require'], $composer_file['require'] ) ) ) ) {
		return true;
	}

	return false;
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
function is_external_domain_blocked( $url ) {
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
 * Test if this website is in some development mode based on a list of flags.
 *
 * Detect if the site is in some known development state.
 *
 * @return bool True if the site is in some development mode. False if not in a known development mode.
 */
function is_development_mode() {
	$home_url      = home_url( '/' );
	$domain        = wp_parse_url( $home_url, PHP_URL_HOST );
	$tlds_to_check = [
		'cshp.co',
		'cshp.dev',
		'kinsta.cloud',
		'pantheonsite.io',
		'wpengine.com',
		'flywheelstaging.com',
		'flywheelsites.com',
		'dreamhosters.com',
	];

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
 * Delete the old versions of archived posts and archive files when they are out of date versus the currently installed versions.
 *
 * This occurs during a cron job.
 *
 * @return void
 */
function delete_old_archive_posts() {
	$plugins            = get_plugins();
	$plugin_folder_data = [];
	foreach ( $plugins as $plugin_folder_file => $plugin_data ) {
		$plugin_folder                        = dirname( $plugin_folder_file );
		$plugin_folder_data[ $plugin_folder ] = $plugin_data;
	}

	$archive_posts = new \WP_Query(
		[
			'post_type'              => get_archive_post_type(),
			'post_status'            => 'private',
			'posts_per_page'         => 100,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'fields'                 => 'ids',
		]
	);

	if ( $archive_posts->have_posts() ) {
		foreach ( $archive_posts->posts as $post_id ) {
			$saved_plugin_data = get_post_meta( $post_id, 'cshp_plugin_tracker_archived_plugins', true );

			try {
				$saved_plugin_data = json_decode( $saved_plugin_data, true );
				foreach ( $saved_plugin_data as $plugin_folder_name => $version ) {
					$should_delete = false;
					// if an archive has a plugin that is no longer installed, delete it
					if ( ! isset( $plugin_folder_data[ $plugin_folder_name ] ) ) {
						$should_delete = true;
					}

					// if the plugin version that is archived is not the same version that is currently installed, delete it
					if ( $version !== $plugin_folder_data[ $plugin_folder_name ]['Version'] ) {
						$should_delete = true;
					}

					$should_delete && wp_delete_post( $post_id, true );
				}
			} catch ( \Exception $e ) {
			}
		}//end foreach
	}//end if
}

/**
 * Redirect the user to the REST endpoint when they request to website using a special url parameter that will trigger the downloading of the plugins from the website.
 *
 * @return void
 */
function trigger_zip_download_with_site_key() {
	if ( isset( $_GET['cshp_pt_cpr'] ) ) {
		$key           = sanitize_text_field( $_GET['cshp_pt_cpr'] );
		$type          = 'plugin';
		$plugins       = ! empty( $_GET['cshp_pt_plugins'] ) ? $_GET['cshp_pt_plugins'] : '';
		$diff          = isset( $_GET['cshp_pt_diff'] );
		$not_exists    = isset( $_GET['cshp_pt_not_exists'] );
		$echo_result   = isset( $_GET['cshp_pt_echo'] );
		$clean_plugins = [];

		if ( 'theme' === $type ) {
			$request = new \WP_REST_Request( 'GET', '/cshp-plugin-tracker/theme/download' );
		} else {
			$request = new \WP_REST_Request( 'GET', '/cshp-plugin-tracker/plugin/download' );
		}

		// make sure that were not just passed a true or a 1 for the key
		if ( ! empty( $key ) && true !== filter_var( $key, FILTER_VALIDATE_BOOLEAN ) ) {
			$request->set_param( 'token', $key );
		} else {
			$request->set_param( 'bypass', '' );
		}

		if ( ! empty( $plugins ) ) {
			foreach ( $plugins as $plugin_name => $version ) {
				$plugin_name    = sanitize_text_field( $plugin_name );
				$plugin_version = sanitize_text_field( $version );

				if ( ! empty( $plugin_name ) ) {
					$clean_plugins[ $plugin_name ] = ! empty( $plugin_version ) ? $plugin_version : '';
				}
			}

			if ( ! empty( $clean_plugins ) ) {
				$request->set_param( 'plugins', $clean_plugins );
			}
		}

		if ( $diff ) {
			$request->set_param( 'diff', '' );
		} elseif ( $not_exists ) {
			$request->set_param( 'not_exists', '' );
		}

		if ( $echo_result ) {
			$request->set_param( 'echo', '' );
		}

		$url = add_query_arg( $request->get_query_params(), get_rest_url( null, $request->get_route() ) );

		if ( ! empty( $url ) ) {
			wp_safe_redirect( $url, 302 );
			exit;
		}
	}//end if
}
add_action( 'init', __NAMESPACE__ . '\trigger_zip_download_with_site_key' );

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
 * Get the full path to a plugin file
 *
 * WordPress has no built-in way to get the full path to a plugin's main file when getting a list of plugins from the site.
 *
 * @param string $plugin_folder_name_and_main_file Plugin folder and main file (e.g. cshp-plugin-tracker/cshp-plugin-tracker.php).
 *
 * @return string Absolute path to a plugin file.
 */
function get_plugin_file_full_path( $plugin_folder_name_and_main_file ) {
	// remove the directory separator slash at the end of the plugin folder since we add the director separator explicitly
	$clean_plugin_folder_path = rtrim( get_plugin_folders_path(), DIRECTORY_SEPARATOR );
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
function get_wp_content_relative_file_path( $path, $remove_additional_subfolder = '' ) {
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
