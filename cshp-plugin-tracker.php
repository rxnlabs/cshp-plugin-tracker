<?php
/*
Plugin Name: Cornershop Plugin Tracker
Plugin URI: https://cornershopcreative.com/
Description: Keep track of the current versions of themes and plugins installed on a WordPress site.
Version: 1.0.0
Text Domain: cshp-pt
Author: Cornershop Creative
Author URI: https://cornershopcreative.com/
License: GPLv2 or later
Requires PHP: 5.6.20
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
// include file with plugins agency licenses
require_once 'inc/license.php';

// load the WP CLI commands
if ( is_wp_cli_action() ) {
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
		if ( is_wp_cli_action() || true === $is_maybe_bulk_upgrade ) {
			update_plugin_tracker_file_post_bulk_update();
		} elseif ( should_real_time_update() ) {
			create_plugin_tracker_file();
		}
	}
}
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\flush_composer_post_update', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a plugin is activated.
 *
 * @param string $plugin Path to the main plugin file that was activated.
 * @param bool   $network_wide Whether the plugin was enabled on all sites in .a multisite.
 *
 * @return void
 */
function flush_composer_post_activate_plugin( $plugin, $network_wide ) {
	if ( is_wp_cli_action() ) {
		update_plugin_tracker_file_post_bulk_update();
	} else {
		should_real_time_update() && create_plugin_tracker_file();
	}
}
add_action( 'activated_plugin', __NAMESPACE__ . '\flush_composer_post_activate_plugin', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a theme is activated.
 *
 * @param string    $old_theme_name Name of the old theme.
 * @param \WP_Theme $old_theme Theme object of the old theme.
 *
 * @return void
 */
function flush_composer_post_theme_switch( $old_theme_name, $old_theme ) {
	if ( is_wp_cli_action() ) {
		update_plugin_tracker_file_post_bulk_update();
	} else {
		should_real_time_update() && create_plugin_tracker_file();
	}
}
add_action( 'after_switch_theme', __NAMESPACE__ . '\flush_composer_post_theme_switch', 10, 2 );

/**
 * Flush the uploads directory composer.json file after a plugin is uninstalled
 *
 * @param string $plugin_relative_file Path to the plugin file relative to the plugins directory.
 * @param array  $uninstallable_plugins Uninstallable plugins.
 *
 * @return void
 */
function flush_composer_plugin_uninstall( $plugin_relative_file, $uninstallable_plugins ) {
	$file = plugin_basename( $plugin_relative_file );

	if ( is_wp_cli_action() ) {
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
 * Flush the uploads directory composer.json file after a theme is deleted
 *
 * @param string $stylesheet Name of the theme stylesheet that was just deleted.
 *
 * @return void
 */
function flush_composer_theme_delete( $stylesheet ) {
	if ( is_wp_cli_action() ) {
		update_plugin_tracker_file_post_bulk_update();
	} else {
		should_real_time_update() && create_plugin_tracker_file();
	}
}
add_action( 'delete_theme', __NAMESPACE__ . '\flush_composer_plugin_uninstall', 10, 1 );

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
 * Initialize a way for the plugin to be updated since it will not be hosted on wordpress.org
 *
 * @return void
 */
function plugin_update_checker() {
	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$update_url = sprintf( 'https://plugins.demo.cshp.co/wp-json/cshp-plugin-updater/%s', get_this_plugin_folder() );
		\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$update_url,
			__FILE__,
			get_this_plugin_folder()
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\plugin_update_checker' );

/*
 * Try to determine if an action is being run via WP CLI
 *
 * @return bool True if WP CLI is running. False if WP CLI is not running or cannot be detected.
 */
function is_wp_cli_action() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
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
			'show_in_rest'          => is_user_logged_in() ?: false,
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
			'show_in_nav_menus'     => false,
			'show_ui'               => false,
			'show_admin_column'     => true,
			'query_var'             => true,
			'rewrite'               => true,
			'capabilities'          => [
				'manage_terms' => 'edit_posts',
				'edit_terms'   => 'edit_posts',
				'delete_terms' => 'edit_posts',
				'assign_terms' => 'edit_posts',
			],
			'show_in_rest'          => is_user_logged_in() ?: false,
			'rest_base'             => get_log_taxonomy(),
			'rest_controller_class' => 'WP_REST_Terms_Controller',
		]
	);

	generate_default_terms();
}
add_action( 'init', __NAMESPACE__ . '\create_log_post_type' );

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
	if ( get_log_post_type() !== get_post_type( $post ) || 200 < wp_count_posts( get_log_post_type() ) ) {
		return;
	}

	$query = new \WP_Query(
		[
			'post_type'              => get_log_post_type(),
			'posts_per_page'         => 10,
			'offset'                 => 10,
			'order'                  => 'DESC',
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
}
add_action( 'wp_insert_post', __NAMESPACE__ . '\limit_log_post_type', 10, 3 );

/**
 * Get the post type that will be used for a log
 *
 * @return string Name of the post type used for logging actions.
 */
function get_log_post_type() {
	return 'cshp_pt_log';
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
 * Get the path to the missing plugin download zip file
 *
 * @return false|mixed|null Path of the zip file on the server or empty of no zip file.
 */
function get_missing_plugin_zip_file() {
	return get_option( 'cshp_plugin_tracker_plugin_zip' );
}

/**
 * Get the path to the missing theme download zip file
 *
 * @return false|mixed|null Path of the zip file on the server or empty if no zip file.
 */
function get_missing_theme_zip_file() {
	return get_option( 'cshp_plugin_tracker_theme_zip' );
}

/**
 * Get the token that is used for downloading the missing plugins and themes
 *
 * @return false|mixed|null Token or empty if no token.
 */
function get_stored_token() {
	return get_option( 'cshp_plugin_tracker_token' );
}

/**
 * Get the list of plugins that should be excluded when zipping the premium plugins
 *
 * @return false|mixed|null Array of plugins to exclude based on the plugin folder name.
 */
function get_excluded_plugins() {
	$list = get_option( 'cshp_plugin_tracker_exclude_plugins', [] );
	// always exclude this plugin from being included in the list of plugins to zip
	$list[] = get_this_plugin_folder();

	return $list;
}

/**
 * Get the list of themes that should be excluded when zipping the premium themes
 *
 * @return false|mixed|null Array of themes to exclude based on the theme folder name.
 */
function get_excluded_themes() {
	return get_option( 'cshp_plugin_tracker_exclude_themes', [] );
}

/**
 * Determine if the composer.json file should update in real-time or if the file should update during daily cron job
 *
 * @return bool True if the file should udpate in real-time. False if the file should update during cron job.
 */
function should_real_time_update() {
	$option = get_option( 'cshp_plugin_tracker_live_change_tracking', 'yes' );
	if ( 'no' !== $option || empty( $option ) ) {
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
function generate_default_terms() {
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
	];

	if ( ! wp_count_terms( get_log_taxonomy() ) ) {
		foreach ( $terms as $slug => $name ) {
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

	if ( ! empty( $plugins ) ) {
		$composer['require'] = array_merge( $composer['require'], $plugins );
	}

	if ( ! empty( $themes ) ) {
		$composer['require'] = array_merge( $composer['require'], $themes );
	}

	$composer['require']['cshp/wp'] = get_current_wordpress_version();
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
	return preg_replace(
		'/^\s+/m',
		'',
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
			generate_plugins_zip_command( $plugins )
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
 * Determine of the plugin is available on wordpress.org
 *
 * @param string     $plugin_slug WordPress plugin slug.
 * @param int|string $version Plugin version to search for.
 */
function is_plugin_available( $plugin_slug, $version = '' ) {
	// if external requests are blocked to wordpress.org, assume that all themes are premium themes
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
 * Determine of the theme is available on wordpress.org
 *
 * @param string     $theme_slug WordPress theme slug.
 * @param int|string $version Version of the theme.
 *
 * @return bool True if the theme is available, false if not available.
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

	if ( ! file_exists( $plugin_path_file ) ) {
		$plugin_path_file = get_plugin_file_full_path( $plugin_path_file );
	}

	if ( file_exists( $plugin_path_file ) && is_file( $plugin_path_file ) ) {
		$plugin_data        = get_plugin_data( $plugin_path_file, false, false );
		$plugin_folder_name = basename( dirname( $plugin_path_file ) );
		$version_check      = isset( $data['Version'] ) ? $data['Version'] : '';

		if ( is_update_disabled( $plugin_data ) || ! is_plugin_available( $plugin_folder_name, $version_check ) ) {
			return true;
		}
	}

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
	$is_wordpress_org_blocked = false;

	if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === WP_HTTP_BLOCK_EXTERNAL ) {
		$check_host               = new \WP_Http();
		$is_wordpress_org_blocked = $check_host->block_request( 'https://api.wordpress.org' );
	}

	return $is_wordpress_org_blocked;
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
		} catch ( \TypeError $error ) {
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
	$composer      = generate_composer_array();
	$composer_file = read_plugins_file();

	// only save the composer.json file if the requirements have changed from the last time the file was saved
	if ( ! empty( $composer_file ) &&
		 isset( $composer['require'] ) &&
		 isset( $composer_file['require'] ) &&
		 ! empty( array_diff_assoc( $composer['require'], $composer_file['require'] ) ) ) {
		create_plugin_tracker_file();
	} elseif ( empty( $composer_file ) ) {
		create_plugin_tracker_file();
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
	if ( function_exists( '\as_schedule_cron_action' ) && ! as_has_scheduled_action( 'cshp_pt_regenerate_composer_post_bulk_update', [], 'cshp_pt' ) ) {
		// schedule action scheduler to run once a day
		as_schedule_cron_action( strtotime( 'now' ), $one_minute_later_cron_expression, 'cshp_pt_regenerate_composer_post_bulk_update', [], 'cshp_pt' );
	} elseif ( ! wp_next_scheduled( 'cshp_pt_regenerate_composer_post_bulk_update' ) ) {
		wp_schedule_event( $one_minute_later->getTimestamp(), 'daily', 'cshp_pt_regenerate_composer_post_bulk_update' );
	}
}

/**
 * Read the data from the saved plugin composer.json file
 *
 * @return array|null Key-value based array of the composer.json file, empty array if no composer.json file, or null.
 */
function read_plugins_file() {
	if ( is_wp_error( create_plugin_uploads_folder() ) || empty( create_plugin_uploads_folder() ) ) {
		return;
	}

	$plugins_file = sprintf( '%s/composer.json', create_plugin_uploads_folder() );

	if ( ! file_exists( $plugins_file ) ) {
		return;
	}

	return wp_json_file_decode( $plugins_file );
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
function create_zip( $zip_path, $folder_paths = [] ) {
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

				$relative_path      = substr( $file->getRealPath(), strlen( $folder_path ) + 1 );
				$file_relative_path = $folder_name . '/' . $relative_path;
				// Add current file to archive
				$zip->addFile( $file->getRealPath(), $file_relative_path );
			}
		}
		return $zip_path;
	} else {
		return __( 'Error: Zip cannot be created', get_textdomain() );
	}

}

/**
 * Zip up the plugins that are not available on wordpress.org
 *
 * @return string Path to the premium plugins zip file or error message if the zip file cannot be created.
 */
function zip_missing_plugins() {
	$plugins             = get_active_plugins();
	$excluded_plugins    = get_excluded_plugins();
	$message             = '';
	$zip_include_plugins = [];

	if ( class_exists( '\ZipArchive' ) ) {
		// if we are using WP CLI, allow the zip of plugins to be generated multiple times
		if ( ! is_wp_cli_action() ) {
			if ( does_zip_exists( get_missing_plugin_zip_file() ) && ! is_plugin_zip_old() ) {
				log_request( 'plugin_zip_download' );
				return get_missing_plugin_zip_file();
			} else {
				log_request( 'plugin_zip_download', __( 'Attempt download but plugin zip does not exists or has not been generated lately. Generate zip.', get_textdomain() ) );
			}
		}

		if ( ! is_wp_error( create_plugin_uploads_folder() ) && ! empty( create_plugin_uploads_folder() ) ) {
			$zip_path = sprintf( '%s/plugins-%s.zip', create_plugin_uploads_folder(), wp_generate_uuid4() );
			log_request( 'plugin_zip_start' );
			foreach ( $plugins as $plugin ) {
				$plugin_folder_name      = dirname( $plugin );
				$plugin_folder_path_file = get_plugin_file_full_path( $plugin );
				$plugin_folder_path      = get_plugin_file_full_path( dirname( $plugin ) );

				// prevent this plugin from being included in the premium plugins zip file
				// exclude plugins that we explicitly don't want to download
				if ( $plugin_folder_name === get_this_plugin_folder() || in_array( $plugin_folder_name, $excluded_plugins, true ) ) {
					continue;
				}

				// if the plugin has disabled updates, include it in the list of premium plugins
				// only zip up plugins that are not available on WordPress.org
				if ( in_array( $plugin_folder_name, premium_plugins_list(), true ) || is_premium_plugin( $plugin_folder_path_file ) ) {
					$zip_include_plugins[] = $plugin_folder_path;
				}
			}//end foreach

			if ( ! empty( $zip_include_plugins ) ) {
				$zip_result = create_zip( $zip_path, $zip_include_plugins );
				if ( file_exists( $zip_result ) ) {
					save_plugin_zip_file( $zip_path );
					log_request( 'plugin_zip_create_complete' );
					log_request( 'plugin_zip_download' );
					return get_missing_plugin_zip_file();
				} else {
					$message = $zip_result;
					log_request( 'plugin_zip_error', $message );
				}
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
 * @return string Path to the premium themes zip file or error message if the zip file cannot be created.
 */
function zip_missing_themes() {
	$themes             = get_active_themes();
	$excluded_themes    = get_excluded_themes();
	$zip_include_themes = [];
	$message            = '';
	if ( class_exists( '\ZipArchive' ) ) {

		// if we are using WP CLI, allow the zip of themes to be generated multiple times
		if ( ! is_wp_cli_action() ) {
			if ( does_zip_exists( get_missing_theme_zip_file() ) && ! is_theme_zip_old() ) {
				log_request( 'theme_zip_download' );
				return get_missing_theme_zip_file();
			} else {
				log_request( 'themes_zip_download', __( 'Attempt download but theme zip does not exists or has not been generated. Generate zip.', get_textdomain() ) );

			}
		}

		if ( ! is_wp_error( create_plugin_uploads_folder() ) && ! empty( create_plugin_uploads_folder() ) ) {
			$zip_path = sprintf( '%s/themes-%s.zip', create_plugin_uploads_folder(), wp_generate_uuid4() );
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
			}//end foreach

			if ( ! empty( $zip_include_themes ) ) {
				$zip_result = create_zip( $zip_path, $zip_include_themes );
				if ( file_exists( $zip_result ) ) {
					save_theme_zip_file( $zip_path );
					log_request( 'theme_zip_create_complete' );
					log_request( 'theme_zip_download' );
					return get_missing_theme_zip_file();
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
	// use the native PHP functions instead of the WP Filesystem API method $wp_filesystem->exists
	// $wp_filesytem->exists throws errors on FTP FS https://github.com/pods-framework/pods/issues/6242
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
			'methods'  => \WP_REST_Server::READABLE,
			'callback' => __NAMESPACE__ . '\download_plugin_zip_rest',
		],
	];

	register_rest_route( 'cshp-plugin-tracker', '/plugin/download', $route_args );

	$route_args = [
		[
			'methods'  => \WP_REST_Server::READABLE,
			'callback' => __NAMESPACE__ . '\download_theme_zip_rest',
		],
	];

	register_rest_route( 'cshp-plugin-tracker', '/theme/download', $route_args );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\add_rest_api_endpoint' );

/**
 * REST endpoint for downloading the premium plugins
 *
 * @param \WP_REST_Request $request WP REST API request.
 *
 * @return void|\WP_REST_Response Zip file for download or JSON response when there is an error.
 */
function download_plugin_zip_rest( $request ) {
	$passed_token = trim( sanitize_text_field( $request->get_param( 'token' ) ) );
	$stored_token = get_stored_token();

	if ( empty( $passed_token ) ) {
		log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
		return new \WP_REST_Response(
			[
				'error' => __( 'No token passed to endpoint. You must pass a token for this request', get_textdomain() ),
			],
			403
		);
	}

	if ( empty( $stored_token ) || $passed_token !== $stored_token ) {
		log_request( 'token_verify_fail' );
		return new \WP_REST_Response(
			[
				'error' => __( 'Token is not authorized', get_textdomain() ),
			],
			403
		);
	}

	$zip_file_result = zip_missing_plugins();
	if ( $zip_file_result === get_missing_plugin_zip_file() && does_zip_exists( get_missing_plugin_zip_file() ) ) {
		send_missing_plugins_zip_for_download();
	}

	return new \WP_REST_Response(
		[
			'error'   => $zip_file_result,
			'message' => __( 'Plugin zip file does not exist or cannot be generated', get_textdomain() ),
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
	$passed_token = trim( sanitize_text_field( $request->get_param( 'token' ) ) );
	$stored_token = get_stored_token();

	if ( empty( $passed_token ) ) {
		log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
		return new \WP_REST_Response(
			[
				'error' => __( 'No token passed to endpoint. You must pass a token for this request', get_textdomain() ),
			],
			403
		);
	}

	if ( empty( $stored_token ) || $passed_token !== $stored_token ) {
		log_request( 'token_verify_fail' );
		return new \WP_REST_Response(
			[
				'error' => __( 'Token is not authorized', get_textdomain() ),
			],
			403
		);
	}

	$zip_file_result = zip_missing_themes();

	if ( $zip_file_result === get_missing_theme_zip_file() && does_zip_exists( get_missing_theme_zip_file() ) ) {
		send_missing_themes_zip_for_download();
	}

	return new \WP_REST_Response(
		[
			'error'   => $zip_file_result,
			'message' => __( 'Theme zip file does not exist or cannot be generated', get_textdomain() ),
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

		$passed_token = trim( sanitize_text_field( $_GET['token'] ) );
		$stored_token = get_stored_token();

		if ( empty( $passed_token ) ) {
			http_response_code( 403 );
			log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
			echo __( 'No token passed to endpoint. You must pass a token for this request', get_textdomain() );
			exit;
		}

		if ( empty( $stored_token ) || $passed_token !== $stored_token ) {
			http_response_code( 403 );
			log_request( 'token_verify_fail' );
			echo __( 'Token is not authorized', get_textdomain() );
			exit;
		}

		$zip_file_result = zip_missing_plugins();

		if ( $zip_file_result === get_missing_plugin_zip_file() && does_zip_exists( get_missing_plugin_zip_file() ) ) {
			send_missing_plugins_zip_for_download();
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

		$passed_token = trim( sanitize_text_field( $_GET['token'] ) );
		$stored_token = get_stored_token();

		if ( empty( $passed_token ) ) {
			http_response_code( 403 );
			log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', get_textdomain() ), get_request_ip_address() ) );
			echo __( 'No token passed to endpoint. You must pass a token for this request', get_textdomain() );
			exit;
		}

		if ( empty( $stored_token ) || $passed_token !== $stored_token ) {
			http_response_code( 403 );
			log_request( 'token_verify_fail' );
			echo __( 'Token is not authorized', get_textdomain() );
			exit;
		}

		$zip_file_result = zip_missing_themes();

		if ( $zip_file_result === get_missing_theme_zip_file() && does_zip_exists( get_missing_theme_zip_file() ) ) {
			send_missing_themes_zip_for_download();
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
function send_missing_plugins_zip_for_download() {
	http_response_code( 302 );
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
	header( 'Cache-Control: post-check=0, pre-check=0', false );
	header( 'Pragma: no-cache' );
	wp_safe_redirect( home_url( sprintf( '/%s', str_replace( ABSPATH, '', get_missing_plugin_zip_file() ) ) ) );
	exit;
}

/**
 * Redirect the browser to the premium themes Zip file so the download can initiate
 *
 * @return void
 */
function send_missing_themes_zip_for_download() {
	http_response_code( 302 );
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
	header( 'Cache-Control: post-check=0, pre-check=0', false );
	header( 'Pragma: no-cache' );
	wp_safe_redirect( home_url( sprintf( '/%s', str_replace( ABSPATH, '', get_missing_theme_zip_file() ) ) ) );
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

	if ( ! is_wp_error( $plugin_zip_create_complete_query ) &&
		 ! is_wp_error( $file_create_query ) &&
		 $plugin_zip_create_complete_query->have_posts() &&
		 $file_create_query->have_posts() ) {
		$plugin_zip_create_complete_date = get_post_datetime( $plugin_zip_create_complete_query->posts[0] );
		$file_create_date                = get_post_datetime( $file_create_query->posts[0] );

		// if the plugin zip file was created after the last time the composer.json file was updated, then the plugin zip file is not old
		if ( $plugin_zip_create_complete_date > $file_create_date ) {
			return false;
		}
	}

	return true;
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
 *
 * @return void
 */
function log_request( $type, $message = '' ) {
	global $wp;
	$get_clean = [];

	if ( ! empty( $_GET ) ) {
		foreach ( $_GET as $key => $get ) {
			$get_clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $get );
		}
	}

	$request_url   = add_query_arg( array_merge( $get_clean, $wp->query_vars ), home_url( $wp->request ) );
	$allowed_types = get_allowed_log_types();
	$title         = '';
	$content       = '';
	$term_object   = null;

	if ( ! empty( $message ) ) {
		$content = $message;
	}

	if ( ! is_wp_error( $allowed_types ) ) {
		foreach ( $allowed_types as $allowed_type ) {
			if ( $type !== $allowed_type->slug ) {
				continue;
			}

			$term_object = $allowed_type;
			$title       = $term_object->name;

			if ( false !== strpos( $type, 'token_' ) ) {
				$title = sprintf( '%s %s', $title, get_stored_token() );
			} elseif ( false !== strpos( $type, 'plugin_' ) ) {
				$title = sprintf( '%s %s', $title, get_missing_plugin_zip_file() );
			} elseif ( false !== strpos( $type, 'theme_' ) ) {
				$title = sprintf( '%s %s', $title, get_missing_theme_zip_file() );
			}

			break;
		}
	}

	if ( is_user_logged_in() ) {
		$title = sprintf( __( '%1$s by %2$s', get_textdomain() ), $title, wp_get_current_user()->user_login );
	}

	if ( false !== strpos( $type, 'download' ) ) {
		$content = sprintf( __( 'Downloaded by IP address %s', get_textdomain() ), sanitize_text_field( get_request_ip_address() ) );
	} elseif ( false !== strpos( $type, 'create' ) ) {
		$content = sprintf( __( 'Generated by IP address %s', get_textdomain() ), sanitize_text_field( get_request_ip_address() ) );
	} elseif ( false !== strpos( $type, 'delete' ) ) {
		$content = sprintf( __( 'Deleted by IP address %s', get_textdomain() ), sanitize_text_field( get_request_ip_address() ) );
	} elseif ( false !== strpos( $type, 'verify_fail' ) ) {
		$content = sprintf( __( 'Verification failed by IP address %s', get_textdomain() ), sanitize_text_field( get_request_ip_address() ) );
	}

	if ( ! empty( $title ) && ! empty( $term_object ) ) {
		$result_post_id = wp_insert_post(
			[
				'post_type'    => get_log_post_type(),
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'private',
				'post_author'  => is_user_logged_in() ? get_current_user_id() : 0,
				'tax_input'    => [
					$term_object->taxonomy => [ $term_object->slug ],
				],
				'meta_input'   => [
					'ip_address' => get_request_ip_address(),
					'url'        => $request_url,
				],
			]
		);

		// sometimes the term won't be added to the post on insert due to permissions of the logged-in user
		// https://wordpress.stackexchange.com/questions/210229/tax-input-not-working-wp-insert-post
		if ( ! is_wp_error( $result_post_id ) && ! empty( $result_post_id ) && ! has_term( $term_object->slug, $term_object->taxonomy, $result_post_id ) ) {
			wp_add_object_terms( $result_post_id, $term_object->term_id, $term_object->taxonomy );
		}
	}//end if
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
 * Save the new path of the plugin zip file and delete the old zip file
 *
 * @param string $zip_file_path Path to the zip file to save.
 *
 * @return void
 */
function save_plugin_zip_file( $zip_file_path ) {
	$previous_zip = get_missing_plugin_zip_file();

	if ( ! empty( $previous_zip ) ) {
		wp_delete_file( $previous_zip );
	}

	if ( does_zip_exists( $zip_file_path ) ) {
		update_option( 'cshp_plugin_tracker_plugin_zip', $zip_file_path );
	} else {
		update_option( 'cshp_plugin_tracker_plugin_zip', '' );
	}
}

/**
 * Save the new path of the theme zip file and delete the old zip file
 *
 * @param string $zip_file_path Path to the zip file to save.
 *
 * @return void
 */
function save_theme_zip_file( $zip_file_path ) {
	$previous_zip = get_missing_theme_zip_file();

	if ( ! empty( $previous_zip ) ) {
		unlink( $previous_zip );
	}

	if ( does_zip_exists( $zip_file_path ) ) {
		update_option( 'cshp_plugin_tracker_theme_zip', $zip_file_path );
	} else {
		update_option( 'cshp_plugin_tracker_theme_zip', '' );
	}
}

/**
 * Add options page to manage the plugin settings
 *
 * @return void
 */
function add_options_admin_menu() {
	add_options_page(
		__( 'Cornershop Plugin Tracker' ),
		__( 'Cornershop Plugin Tracker' ),
		'manage_options',
		'cshp-plugin-tracker',
		__NAMESPACE__ . '\admin_page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\add_options_admin_menu' );

function add_settings_link() {
	$filter_name = sprintf( 'plugin_action_links_%s', plugin_basename( __FILE__ ) );
	add_filter(
		$filter_name,
		function ( $links ) {
			$new_links = [
				sprintf(
					'<a title="%s" href="%s">%s</a>',
					esc_attr__( 'Cornershop Plugin Tracker settings page', get_textdomain() ),
					esc_url( menu_page_url( 'cshp-plugin-tracker', false ) ),
					esc_html__( 'Settings', get_textdomain() )
				),
			];

			return array_merge( $links, $new_links );
		},
		10
	);
}
add_filter( 'admin_init', __NAMESPACE__ . '\add_settings_link' );

/**
 * Register settings for the plugin
 *
 * @return void
 */
function register_options_admin_settings() {
	register_setting(
		'cshp_plugin_tracker',
		'cshp_plugin_tracker_token',
		function( $input ) {
			$token = trim( sanitize_text_field( $input ) );
			if ( $token !== get_stored_token() && ! empty( $token ) ) {
				log_request( 'token_delete' );
				log_request( 'token_create' );
			} elseif ( empty( $token ) ) {
				log_request( 'token_delete' );
			}
			return $token;
		}
	);

	register_setting(
		'cshp_plugin_tracker',
		'cshp_plugin_tracker_exclude_plugins',
		function( $input ) {
			$clean_input = [];

			foreach ( $input as $plugin_folder ) {
				$test = sanitize_text_field( $plugin_folder );

				if ( does_zip_exists( get_plugin_file_full_path( $test ) ) ) {
					$clean_input[] = $test;
				}
			}

			return $clean_input;
		}
	);

	register_setting(
		'cshp_plugin_tracker',
		'cshp_plugin_tracker_exclude_themes',
		function( $input ) {
			$clean_input = [];

			foreach ( $input as $theme_folder ) {
				$test = sanitize_text_field( $theme_folder );

				if ( does_zip_exists( get_plugin_file_full_path( $test ) ) ) {
					$clean_input[] = $test;
				}
			}

			return $clean_input;
		}
	);

	register_setting(
		'cshp_plugin_tracker',
		'cshp_plugin_tracker_live_change_tracking',
		function( $input ) {
			$real_time_update = trim( sanitize_text_field( $input ) );
			if ( 'no' !== $real_time_update || empty( $real_time_update ) ) {
				$real_time_update = 'yes';
			} elseif ( 'no' === $real_time_update ) {
				$real_time_update = 'no';
			}
			return $real_time_update;
		}
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\register_options_admin_settings' );

/**
 * Create a settings page for the plugin
 *
 * @return void
 */
function admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$default_tab = null;
	$tab         = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : $default_tab;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( get_admin_page_title() ); ?></h1>
		<nav class="nav-tab-wrapper">
			<a href="?page=cshp-plugin-tracker" class="nav-tab <?php echo ( null === $tab ? esc_attr( 'nav-tab-active' ) : '' ); ?>"><?php esc_html_e( 'Settings', get_textdomain() ); ?></a>
			<a href="?page=cshp-plugin-tracker&tab=log" class="nav-tab <?php echo ( 'log' === $tab ? esc_attr( 'nav-tab-active' ) : '' ); ?>"><?php esc_html_e( 'Log', get_textdomain() ); ?></a>
			<a href="?page=cshp-plugin-tracker&tab=documentation" class="nav-tab <?php echo ( 'documentation' === $tab ? esc_attr( 'nav-tab-active' ) : '' ); ?>"><?php esc_html_e( 'Documentation', get_textdomain() ); ?></a>
		</nav>
		<div class="tab-content">
			<?php
			switch ( $tab ) {
				case 'log':
						admin_page_log_tab();
					break;
				case 'documentation':
						admin_page_wp_documentation();
					break;
				case 'settings':
				default:
						admin_page_settings_tab();
			}
			?>
		</div>
	</div>

	<?php
}

/**
 * Output the settings tab for the plugin
 *
 * @return void
 */
function admin_page_settings_tab() {
	$active_plugins   = get_active_plugins();
	$active_themes    = get_active_themes();
	$plugin_list      = '';
	$themes_list      = '';
	$excluded_plugins = get_excluded_plugins();
	$excluded_themes  = get_excluded_themes();
	sort( $active_plugins );
	sort( $active_themes );

	foreach ( $active_plugins as $plugin ) {
		$plugin_data        = get_plugin_data( get_plugin_file_full_path( $plugin ), false, false );
		$version_check      = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';
		$plugin_folder_name = dirname( $plugin );

		if ( $plugin_folder_name === get_this_plugin_folder() ) {
			continue;
		}

		// if the plugin has disabled updates, include it in the list of premium plugins
		// only try to exclude plugins that are not available on WordPress.org
		if ( ! is_premium_plugin( $plugin ) ) {
			continue;
		}

		$plugin_list .= sprintf(
			'<li>
            <input type="checkbox" name="cshp_plugin_tracker_exclude_plugins[]" id="%1$s" value="%1$s" %2$s>
            <label for="%1$s">%3$s</label>
            </li>',
			esc_attr( dirname( $plugin ) ),
			checked( in_array( dirname( $plugin ), $excluded_plugins, true ), true, false ),
			$plugin_data['Name']
		);
	}//end foreach

	if ( empty( $plugin_list ) ) {
		$plugin_list = __( 'No premium plugins installed or detected. Plugins installed match the name and versions of plugins available on wordpress.org.', get_textdomain() );
	}

	foreach ( $active_themes as $theme_folder_name ) {
		$theme = wp_get_theme( $theme_folder_name );

		// only try to exclude themes that are not available on WordPress.org
		if ( $theme->exists() && is_theme_available( $theme_folder_name, $theme->get( 'Version' ) ) ) {
			continue;
		}

		$themes_list .= sprintf(
			'<li>
            <input type="checkbox" name="cshp_plugin_tracker_exclude_themes[]" id="%1$s" value="%1$s" %2$s>
            <label for="%1$s">%3$s</label>
            </li>',
			esc_attr( $theme_folder_name ),
			checked( in_array( dirname( $theme_folder_name ), $excluded_themes, true ), true, false ),
			$theme->Name
		);
	}

	if ( empty( $themes_list ) ) {
		$themes_list = __( 'No premium themes installed or detected. Themes installed match the name and versions of themes available on wordpress.org.', get_textdomain() );
	}
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'cshp_plugin_tracker' ); ?>
		<?php do_settings_sections( 'cshp_plugin_tracker' ); ?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="cshp-plugin-file-generation"><?php esc_html_e( 'Generate plugin tracker file in real-time', get_textdomain() ); ?></label></th>
					<td>
						<p><?php esc_html_e( 'By default, the composer.json file will be updated on plugin, theme, and WordPress core updates, as well as plugin and theme activations.', get_textdomain() ); ?></p>
						<p><?php esc_html_e( 'Since the update happens in real time, this can slow down plugin and theme activations. Disable real-time updates if you notice a slowdown installing plugins or themes.', get_textdomain() ); ?></p>
						<div>
							<input type="radio" name="cshp_plugin_tracker_live_change_tracking" id="cshp-yes-live-track" value="yes" <?php checked( should_real_time_update() ); ?>>
							<label for="cshp-yes-live-track"><?php esc_html_e( 'Yes, update the file in real-time. (NOTE: file still needs to be manually committed after it updates)', get_textdomain() ); ?></label>
						</div>
						<div>
							<input type="radio" name="cshp_plugin_tracker_live_change_tracking" value="no" id="cshp-no-live-track" <?php checked( ! should_real_time_update() ); ?>>
							<label for="cshp-no-live-track"><?php esc_html_e( 'No, update the file during cron job. (NOTE: file still needs to be manually committed after it updates)', get_textdomain() ); ?></label>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-token"><?php esc_html_e( 'Access Token', get_textdomain() ); ?></label></th>
					<td>
						<input readonly type="text" name="cshp_plugin_tracker_token" id="cshp-token" class="regular-text" value="<?php echo esc_attr( get_stored_token() ); ?>">
						<button type="button" id="cshp-generate-key" class="button hide-if-no-js"><?php esc_html_e( 'Generate New Token', get_textdomain() ); ?></button>
						<button type="button" id="cshp-delete-key" class="button hide-if-no-js"><?php esc_html_e( 'Delete Token', get_textdomain() ); ?></button>
						<button type="button" id="cshp-copy-token" data-copy="cshp_plugin_tracker_token" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<p class="cshp-pt-warning">
							<?php esc_html_e( 'WARNING: Generating a new token will delete the old token. Any request using the old token will stop working, so be sure to update the token in any tools that are using the old token.', get_textdomain() ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-active-plugin-api-endpoint"><?php esc_html_e( 'API Endpoint to download Active plugins that are not available on wordpress.org', get_textdomain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_api_endpoint" id="cshp-active-plugin-api-endpoint" class="large-text" value="<?php echo esc_attr( get_api_active_plugin_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-api-endpoint" data-copy="cshp_plugin_tracker_api_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-rewrite-endpoint"><?php esc_html_e( 'Alternative endpoint to download Active plugins that are not available on wordpress.org (if WP REST API is disabled)', get_textdomain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_rewrite_endpoint" id="cshp-rewrite-endpoint" class="large-text" value="<?php echo esc_attr( get_rewrite_active_plugin_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-rewrite-endpoint" data-copy="cshp_plugin_tracker_rewrite_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-active-plugin-theme-api-endpoint"><?php esc_html_e( 'API Endpoint to download Active theme that is not available on wordpress.org', get_textdomain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_theme_api_endpoint" id="cshp-active-plugin-theme-api-endpoint" class="large-text" value="<?php echo esc_attr( get_api_theme_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-api-endpoint" data-copy="cshp_plugin_tracker_theme_api_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-rewrite-theme-endpoint"><?php esc_html_e( 'Alternative endpoint to download Active theme that is not available on wordpress.org (if WP REST API is disabled)', get_textdomain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_rewrite_theme_endpoint" id="cshp-rewrite-theme-endpoint" class="large-text" value="<?php echo esc_attr( get_rewrite_theme_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-rewrite-endpoint" data-copy="cshp_plugin_tracker_rewrite_theme_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-plugin-exclude"><?php esc_html_e( 'Exclude plugins from being added to the generated Zip file', get_textdomain() ); ?></label></th>
					<td>
						<ul>
							<?php echo $plugin_list; ?>
						</ul>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-theme-exclude"><?php esc_html_e( 'Exclude themes from being added to the generated Zip file', get_textdomain() ); ?></label></th>
					<td>
						<ul>
							<?php echo $themes_list; ?>
						</ul>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( __( 'Save Settings', get_textdomain() ) ); ?>
	</form>
	<?php
}

/**
 * Output the log page
 *
 * @return void
 */
function admin_page_log_tab() {
	$table_html = sprintf( '<tr><td colspan="4">%s</td></tr>', __( 'No entries found', get_textdomain() ) );
	$query      = new \WP_Query(
		[
			'post_type'      => get_log_post_type(),
			'posts_per_page' => 200,
			'post_status'    => 'private',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		]
	);

	if ( $query->have_posts() ) {
		$table_html = '';
		foreach ( $query->posts as $post ) {
			$table_html .= sprintf(
				'<tr>
                <td>%1$s</td>
                <td>%2$s</td>
                <td>%3$s</td>
                <td>%4$s</td>
                <td>%5$s</td>
            </tr>',
				esc_html( get_the_title( $post ) ),
				esc_html( wp_strip_all_tags( get_post_field( 'post_content', $post ) ) ),
				esc_html( get_the_date( 'm/d/Y h:i:s a', $post ) ),
				esc_html( get_the_author_meta( 'user_nicename', $post->post_author ) ),
				get_post_meta( $post->ID, 'url', true )
			);
		}
	}
	?>
	<table class="table wp-list-table widefat" id="cshpt-log">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title', get_textdomain() ); ?></th>
				<th><?php esc_html_e( 'Content', get_textdomain() ); ?></th>
				<th data-type="date" data-format="MM/DD/YYYY"><?php esc_html_e( 'Date', get_textdomain() ); ?></th>
				<th><?php esc_html_e( 'Author', get_textdomain() ); ?></th>
				<th><?php esc_html_e( 'URL', get_textdomain() ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php echo $table_html; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Admin page to show WP site data that PMs add to the Standard WP documentation sheet handed to clients after site builds.
 *
 * @return void
 */
function admin_page_wp_documentation() {
	?>

	<h2><?php esc_html_e( 'Navigation Menus', get_textdomain() ); ?></h2>
	<?php echo generate_menus_list_documentation(); ?>
	<h2><?php esc_html_e( 'Gravity Forms', get_textdomain() ); ?></h2>
	<?php echo generate_gravityforms_active_documentation(); ?>
	<h2><?php esc_html_e( 'Active Plugins', get_textdomain() ); ?></h2>
	<?php echo generate_plugins_active_documentation(); ?>

	<?php
}

/**
 * Add an admin notice on the plugin page if external requests to wordpress.org are blocked
 *
 * @return void
 */
function admin_notice() {
	if ( ! empty( get_current_screen() ) &&
		 'settings_page_cshp-plugin-tracker' === get_current_screen()->id &&
		 is_wordpress_org_external_request_blocked() &&
		 current_user_can( 'manage_options' ) ) {
		echo sprintf( '<div class="notice notice-error is-dismissible cshp-pt-notice"><p>%s</p></div>', esc_html__( 'External requests to wordpress.org are being blocked. When generating the plugin tracker file, all themes and plugins will be considered premium. Unblock requests to wordpress.org to fix this. Update the PHP constant "WP_ACCESSIBLE_HOSTS" to include exception for *.wordpress.org', get_textdomain() ) );
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\admin_notice', 10 );
/**
 * Enqueue styles and scripts for the plugin
 *
 * @return void
 */
function admin_enqueue() {
	if ( ! empty( get_current_screen() ) && 'settings_page_cshp-plugin-tracker' === get_current_screen()->id ) {
		wp_enqueue_script( 'simple-datatables', get_plugin_file_uri( '/assets/vendor/simple-datatables/js/simple-datatables.min.js' ), [], '7.1.2', true );
		wp_enqueue_style( 'simple-datatables', get_plugin_file_uri( '/assets/vendor/simple-datatables/css/simple-datatables.min.css' ), [], '7.1.2', true );
		wp_enqueue_script( 'cshp-plugin-tracker', get_plugin_file_uri( '/assets/js/admin.js' ), [ 'simple-datatables', 'wp-api-fetch' ], get_version(), true );
		wp_enqueue_style( 'cshp-plugin-tracker', get_plugin_file_uri( '/assets/css/admin.css' ), [], get_version() );

		wp_localize_script(
			'cshp-plugin-tracker',
			'cshp_pt',
			[
				'tab' => isset( $_GET['tab'] ) ? esc_attr( $_GET['tab'] ) : '',
			]
		);
	}
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\admin_enqueue', 9999 );

/**
 * Generate the JSON for the composer array template without any of the specific requires for this site
 *
 * @return array Formatted array for use in a composer.json file.
 */
function generate_composer_template() {
	$relative_directory = basename( dirname( plugin_dir_path( __DIR__ ), 2 ) );
	$composer           = [
		'name'         => sprintf( '%s/wordpress', sanitize_key( get_bloginfo( 'name' ) ) ),
		'description'  => sprintf( __( 'Installed plugins and themes for the WordPress install %s', get_textdomain() ), home_url() ),
		'type'         => 'project',
		'repositories' => [
			'0' => [
				'type' => 'composer',
				'url'  => 'https://wpackagist.org',
				'only' => [
					'wpackagist-plugin/*',
					'wpackagist-theme/*',
				],
			],
			'1' => [
				'type'    => 'package',
				'package' => [
					'name'    => 'cshp/premium-plugins',
					'type'    => 'wordpress-plugin',
					'version' => '1.0',
					'dist'    => [
						'url'  => get_api_active_plugin_downloads_endpoint(),
						'type' => 'zip',
					],
				],
			],
		],
		'require'      => [ 'cshp/premium-plugins' => '^1.0' ],
		'extra'        => [
			'installer-paths' => [
				sprintf( '%s/plugins/${name}', $relative_directory ) => [
					'type:wordpress-plugin',
				],
				sprintf( '%s/themes/${name}', $relative_directory ) => [
					'type:wordpress-theme',
				],
			],
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
        ### Premium Themes%1$s
        %3$s',
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
 * @param array $composer_json_required Composer JSON array of themes and plugins.
 *
 * @return string Markdown for the installing the themes with WP CLI.
 */
function generate_themes_wp_cli_install_command( $composer_json_required ) {
	$data    = [];
	$install = '';

	foreach ( $composer_json_required as $theme_name => $version ) {
		$clean_name = str_replace( 'premium-theme/', '', str_replace( 'wpackagist-theme/', '', $theme_name ) );

		if ( false !== strpos( $theme_name, 'wpackagist' ) ) {
			$data[] = sprintf( 'wp theme install %s --version="%s"', $clean_name, $version );
		}
	}

	if ( ! empty( $data ) ) {
		$install = sprintf( '`%s`', implode( ' && ', $data ) );
	} else {
		$install = __( 'No public themes installed.', get_textdomain() );
	}

	return sprintf(
		'## WP-CLI Command to Install Themes%1$s%2$s%1$s',
		PHP_EOL,
		$install
	);
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
        ### Premium Plugins%1$s
        %3$s',
		PHP_EOL,
		$public_markdown,
		$premium_markdown
	);
}

/**
 * Generating the markdown for showing the how to install the public plugins using WP CLI
 *
 * @param array $composer_json_required Composer JSON array of themes and plugins.
 *
 * @return string Markdown for the installing the plugins with WP CLI.
 */
function generate_plugins_wp_cli_install_command( $composer_json_required ) {
	$data    = [];
	$install = '';

	foreach ( $composer_json_required as $plugin_name => $version ) {
		$clean_name = str_replace( 'premium-plugin/', '', str_replace( 'wpackagist-plugin/', '', $plugin_name ) );

		if ( false !== strpos( $plugin_name, 'wpackagist' ) ) {
			$data[] = sprintf( 'wp plugin install %s --version="%s"', $clean_name, $version );
		}
	}

	if ( ! empty( $data ) ) {
		$install = sprintf( '`%s`', implode( ' && ', $data ) );
	} else {
		$install = __( 'No public plugins installed.', get_textdomain() );
	}

	return sprintf(
		'## WP-CLI Command to Install Plugins%1$s%2$s%1$s',
		PHP_EOL,
		$install
	);
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
	} elseif ( does_zip_exists( plugin_dir_path( __FILE__ ) . '/' . $file ) ) {
		$url = plugin_dir_url( __FILE__ ) . $file;
	}

	return $url;
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

	return sprintf( '%s%s%s', $plugin_directory, DIRECTORY_SEPARATOR, $plugin_folder_name_and_main_file );
}

/**
 * Store a list of plugins that we know are premium plugins (or custom plugins that we developed), so we don't have
 * to ping the WordPress API
 *
 * @return array List of premium plugins using the plugin folder name.
 */
function premium_plugins_list() {
	return array_merge(
		[
			'advanced-custom-fields-pro',
			'backupbuddy',
			'blocksy-companion-pro',
			'bulk-actions-pro-for-gravity-forms',
			'cshp-kinsta',
			'cshp-plugin-updater',
			'cshp-support',
			'donation-for-woocommerce',
			'elementor-pro',
			'essential-addons-elementor',
			'events-calendar-pro',
			'event-tickets-plus',
			'facetwp',
			'facetwp-cache',
			'facetwp-conditional-logic',
			'facetwp-hierarchy-select',
			'facetwp-i18n',
			'facetwp-map-facet',
			'facetwp-range-list',
			'facetwp-time-since',
			'facetwp-submit',
			'gf-bulk-add-fields',
			'gf-collapsible-sections',
			'gf-color-picker',
			'gf-image-choices',
			'gf-salesforce-crm-perks-pro',
			'gf-tooltips',
			'gp-multi-page-navigation',
			'gravityforms',
			'gravityformsactivecampaign',
			'gravityformsauthorizenet',
			'gravityformsconstantcontact',
			'gravityformsmailchimp',
			'gravityformspaypal',
			'gravityformspaypalpaymentspro',
			'gravityformspolls',
			'gravityformsppcp',
			'gravityformsrecaptcha',
			'gravityformsstripe',
			'gravityformstwilio',
			'gravityformsuserregistration',
			'gravityformszapier',
			'gravityperks',
			'gravityview',
			'memberpress',
			'media-deduper-pro',
			'restrict-content-pro',
			'rcp-group-accounts',
			'rcp-per-level-emails',
			'searchwp',
			'searchwp-custom-results-order',
			'searchwp-redirects',
			'searchwp-related',
			'sitepress-multilingual-cms',
			'stackable-ultimate-gutenberg-blocks-premium',
			'sugar-calendar',
			'the-events-calendar-filterbar',
			'woocommerce-bookings',
			'woocommerce-memberships',
			'woocommerce-product-bundles',
			'woocommerce-subscriptions',
			'wordpress-seo-premium',
			'wpai-acf-add-on',
			'wp-all-export-pro',
			'wp-all-import-pro',
			'wpbot-pro',
			'wp-rocket',
		],
		[ get_this_plugin_folder() ]
	);
}

/**
 * Store a list of themes that we know are premium themes (or custom themes that we developed), so we don't have
 * to ping the WordPress API
 *
 * @return array List of themes plugins using the theme folder name.
 */
function premium_themes_list() {
	return [
		'bjork',
		'blocksy-child',
		'crate',
		'crate-child',
		'impacto-patronus',
		'impacto-patronus-child',
		'jupiter',
		'jupiter-child',
		'jupiterx',
		'jupiterx-child',
		'lekker',
		'lekker-child',
		'minerva',
		'phlox-pro',
		'phlox-pro-child',
		'thegem',
		'thegem-child',
		'thepascal',
		'thepascal-child',
	];
}
