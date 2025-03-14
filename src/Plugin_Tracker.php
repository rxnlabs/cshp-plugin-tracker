<?php
/**
 * Main plugin file that does most of the heavy lifting.
 */
declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

// exit if not loading in WordPress context but don't exit if running our PHPUnit tests
if ( ! defined( 'ABSPATH' ) && ! defined( 'CSHP_PHPUNIT_TESTS_RUNNING' ) ) {
	exit;
}

/**
 * Main plugin class that connects everything together and handles the core functions of the plugin, keeping track of the plugins and themes installed on the site.
 *
 */
class Plugin_Tracker {
	use Share;

	/**
	 * Utility instance providing various helper methods and functionalities.
	 *
	 * @var Utility
	 */
	public $utilities;
	/**
	 * Logger instance used for logging application events and messages.
	 *
	 * @var Logger
	 */
	public $logger;
	/**
	 * Archive instance responsible for managing the plugin and theme zips.
	 *
	 * @var Archive
	 */
	public $archive;
	/**
	 * Updater instance handles the update process for the application by managing version checks and applying updates.
	 *
	 * @var Updater
	 */
	public $updater;

	/**
	 * List of known premium plugins and themes.
	 *
	 * @var Premium_List
	 */
	public $premium_list;
	/**
	 * Admin instance responsible for managing the admin settings and interface.
	 *
	 * @var Admin
	 */
	public $admin;

	public function __construct( Utilities $utilities, Updater $updater, Logger $logger, Archive $archive, Premium_List $premium_list, Admin $admin ) {
		$this->utilities    = $utilities;
		$this->logger       = $logger;
		$this->archive      = $archive;
		$this->updater      = $updater;
		$this->premium_list = $premium_list;
		$this->admin        = $admin;
	}

	/**
	 * Create the composer.json file to track the plugins and themes when the plugin is activated.
	 *
	 * @param string $main_plugin_file Main plugin file full path.
	 *
	 * @return void
	 */
	public function register_activation_hook( $main_plugin_file ) {
		// register_activation_hook requires either a static method or a global funcion
		// but you can use an anonymous function as well
		register_activation_hook(
			$main_plugin_file,
			function () {
				$this->activation_hook();
			}
		);
	}

	/**
	 * Delete the plugin archives, plugin logs, etc.. when this plugin is uninstalled.
	 *
	 * @param string $main_plugin_file Main plugin file full path.
	 *
	 * @return void
	 */
	public function register_uninstall_hook( $main_plugin_file ) {
		// register_uninstall_hook also requires a static method or global function but I cannot use
		// the anonymous function trick due to closures not being able to be serialized.
		// Throws the error Serialization of 'Closure' is not allowed. This is a PHP error, not a WordPress specific error.
		register_uninstall_hook( $main_plugin_file, array( __CLASS__, 'deactivation_hook' ) );
	}

	/**
	 * Register plugin activation hook
	 *
	 * @return void
	 */
	public function activation_hook() {
		// flush rewrite rules so the custom rewrite endpoint is created.
		flush_rewrite_rules();
		// create a folder in the uploads directory to hold the plugin files.
		$this->create_plugin_uploads_folder();
		// trigger cron job to save a composer.json file to keep track of the installed plugins.
		// don't create the file immediately in case there are a lot of plugins installed, which could be a lot of external HTTP requests.
		$this->update_plugin_tracker_file_post_bulk_update();
	}

	/**
	 * Register plugin deactivation hook.
	 *
	 * Make this a static method so it can run when the plugin is deactivated since PHP cannot run serialized closures.
	 *
	 * @return void
	 */
	public static function deactivation_hook() {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			return;
		}

		$folder_path = sprintf( '%s/cshp-plugin-tracker', wp_upload_dir()['basedir'] );

		// delete this plugin's directory that is in the uploads directory so we don't leave any files behind.
		try {
			$wp_filesystem->delete( $folder_path, true );
			$post_types_delete = array( 'cshp_pt_log', 'cshp_pt_zip' );
			$taxonomies_delete = array( 'cshp_pt_log_type', 'cshp_pt_zip_content' );

			// phpcs:disable
			$query = new \WP_Query(
				array(
					'post_type'      => $post_types_delete,
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'fields'         => 'ids',
				)
			);
			// phpcs:enable

			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}

			foreach ( $taxonomies_delete as $taxonomy ) {
				$query = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				);

				if ( ! empty( $query ) ) {
					foreach ( $query as $term_id ) {
						wp_delete_term( $term_id, $taxonomies_delete );
					}
				}
			}
			// flush the cache
			wp_cache_flush();
		} catch ( \Exception $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}

	/**
	 * Store the main actions and filters used by this file.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'add_cron_job' ) );
		add_action( 'init', array( $this, 'add_rewrite_rules_endpoint' ) );
		add_action( 'upgrader_process_complete', array( $this, 'flush_composer_post_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_archived_zip_post_update' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'flush_composer_post_activate_plugin' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'flush_composer_post_deactivate_plugin' ), 10, 2 );
		add_action( 'pre_uninstall_plugin', array( $this, 'flush_composer_plugin_uninstall' ), 10, 2 );
		add_action( 'after_switch_theme', array( $this, 'flush_composer_post_theme_switch' ), 10, 2 );
		add_action( 'delete_theme', array( $this, 'flush_composer_theme_delete' ), 10, 1 );
		add_action( 'cshp_pt_regenerate_composer_daily', array( $this, 'create_plugin_tracker_file_cron' ) );
		add_action( 'cshp_pt_regenerate_composer_post_bulk_update', array( $this, 'create_plugin_tracker_file_cron' ) );
		add_filter( 'query_vars', array( $this, 'add_rewrite_query_vars' ) );
		add_action( 'rest_api_init', array( $this, 'add_rest_api_endpoint' ) );
		add_action( 'parse_request', array( $this, 'download_plugin_zip_rewrite' ), 9999 );
		add_action( 'parse_request', array( $this, 'download_theme_zip_rewrite' ), 9999 );
		add_action( 'init', array( $this, 'trigger_zip_download_with_site_key' ) );
	}

	/**
	 * Load the libraries that we did not install with Composer
	 *
	 * @return void
	 */
	public function load_non_composer_libraries() {
		$vendor_path = sprintf( '%s/non-composer-vendor', plugin_dir_path( __DIR__ ) );
		// explicitly include the Action Scheduler plugin instead of installing the library with Composer
		if ( ! function_exists( '\as_schedule_cron_action' ) ) {
			require_once sprintf( '%s/action-scheduler/action-scheduler.php', $vendor_path );
		}

		require_once sprintf( '%s/markdown-table.php', $vendor_path );
	}

	/**
	 * Flush the uploads directory composer.json file after WordPress core, themes, or plugins are updated.
	 *
	 * @param \WP_Upgrader $wp_upgrader WordPress upgrader class with context.
	 * @param array        $upgrade_data Additional information about what was updated.
	 *
	 * @return void
	 */
	public function flush_composer_post_update( $wp_upgrader, $upgrade_data ) {
		$is_maybe_bulk_upgrade = false;

		if ( isset( $upgrade_data['bulk'] ) && true === $upgrade_data['bulk'] ) {
			$is_maybe_bulk_upgrade = true;
		}

		if ( isset( $upgrade_data['type'] ) &&
			in_array( strtolower( $upgrade_data['type'] ), array( 'plugin', 'theme', 'core' ), true ) ) {
			// set a cron job if we are doing bulk upgrades of plugins or themes or if we are using WP CLI
			if ( $this->utilities->is_wp_cli_environment() || true === $is_maybe_bulk_upgrade ) {
				$this->update_plugin_tracker_file_post_bulk_update();
				$this->archive->delete_old_archive_posts();
			} elseif ( $this->admin->should_real_time_update() ) {
				$this->create_plugin_tracker_file();
				$this->archive->delete_old_archive_posts();
			}
		}
	}


	/**
	 * Flush the archived zip files and update the tracker file after an upgrade is performed.
	 *
	 * @param WP_Upgrader $wp_upgrader WP_Upgrader instance performing the upgrade.
	 * @param array $upgrade_data Array of upgrade data with details of the update being performed.
	 *
	 * @return void
	 */
	public function flush_archived_zip_post_update( $wp_upgrader, $upgrade_data ) {
		$is_maybe_bulk_upgrade = false;

		if ( isset( $upgrade_data['bulk'] ) && true === $upgrade_data['bulk'] ) {
			$is_maybe_bulk_upgrade = true;
		}

		if ( isset( $upgrade_data['type'] ) &&
			in_array( strtolower( $upgrade_data['type'] ), array( 'plugin', 'theme', 'core' ), true ) ) {
			// set a cron job if we are doing bulk upgrades of plugins or themes or if we are using WP CLI
			if ( $this->utilities->is_wp_cli_environment() || true === $is_maybe_bulk_upgrade ) {
				$this->update_plugin_tracker_file_post_bulk_update();
			} elseif ( $this->admin->should_real_time_update() ) {
				$this->create_plugin_tracker_file();
			}
		}
	}


	/**
	 * Flush the uploads directory composer.json file after a plugin is activated.
	 *
	 * @param string $plugin Path to the main plugin file that was activated (path is relative to the plugins directory).
	 * @param bool   $network_wide Whether the plugin was enabled on all sites in a multisite.
	 *
	 * @return void
	 */
	public function flush_composer_post_activate_plugin( $plugin, $network_wide ) {
		$plugin_data = get_plugin_data( $this->utilities->get_plugin_file_full_path( $plugin ), false );
		$plugin_name = '';

		if ( ! empty( $plugin_data ) ) {
			$plugin_name = $plugin_data['Name'];
		}

		$this->logger->log_request( 'plugin_activate', $plugin_name );

		if ( $this->utilities->is_wp_cli_environment() ) {
			$this->update_plugin_tracker_file_post_bulk_update();
		} elseif ( $this->admin->should_real_time_update() ) {
			$this->create_plugin_tracker_file();
		}
	}

	/**
	 * Flush the uploads directory composer.json file after a plugin is deactivated.
	 *
	 * @param string $plugin Path to the main plugin file that was deactivated (path is relative to the plugins directory).
	 * @param bool   $network_wide Whether the plugin was disabled on all sites in a multisite.
	 *
	 * @return void
	 */
	public function flush_composer_post_deactivate_plugin( $plugin, $network_wide ) {
		$plugin_data = get_plugin_data( $this->utilities->get_plugin_file_full_path( $plugin ), false );
		$plugin_name = '';

		if ( ! empty( $plugin_data ) ) {
			$plugin_name = $plugin_data['Name'];
		}

		$this->logger->log_request( 'plugin_deactivate', $plugin_name );

		if ( $this->utilities->is_wp_cli_environment() ) {
			$this->update_plugin_tracker_file_post_bulk_update();
		} elseif ( $this->admin->should_real_time_update() ) {
			$this->create_plugin_tracker_file();
		}
	}

	/**
	 * Flush the uploads directory composer.json file after a plugin is uninstalled
	 *
	 * @param string $plugin_relative_file Path to the plugin file relative to the plugins directory.
	 * @param array  $uninstallable_plugins Uninstallable plugins.
	 *
	 * @return void
	 */
	public function flush_composer_plugin_uninstall( $plugin_relative_file, $uninstallable_plugins ) {
		$file        = plugin_basename( $plugin_relative_file );
		$plugin_name = '';
		$plugin_data = get_plugin_data( $this->utilities->get_plugin_file_full_path( $plugin_relative_file ), false );

		if ( ! empty( $plugin_data ) ) {
			$plugin_name = $plugin_data['Name'];
		}

		$this->logger->log_request( 'plugin_uninstall', $plugin_name );

		if ( $this->utilities->is_wp_cli_environment() ) {
			$this->update_plugin_tracker_file_post_bulk_update();
		} elseif ( $this->admin->should_real_time_update() ) {
			// run this when the plugin is successfully uninstalled
			$action_name = sprintf( 'uninstall_%s', $file );
			add_action(
				$action_name,
				function () {
					$this->create_plugin_tracker_file();
				}
			);
		}
	}

	/**
	 * Flush the uploads directory composer.json file after a theme is activated.
	 *
	 * @param string    $old_theme_name Name of the old theme.
	 * @param \WP_Theme $old_theme Theme object of the old theme.
	 *
	 * @return void
	 */
	public function flush_composer_post_theme_switch( $old_theme_name, $old_theme ) {
		$current_theme = wp_get_theme();

		$this->logger->log_request( 'theme_deactivate', $old_theme_name );
		$this->logger->log_request( 'theme_activate', $current_theme->get( 'Name' ) );

		if ( $this->utilities->is_wp_cli_environment() ) {
			$this->update_plugin_tracker_file_post_bulk_update();
		} elseif ( $this->admin->should_real_time_update() ) {
			$this->create_plugin_tracker_file();
		}
	}

	/**
	 * Flush the uploads directory composer.json file after a theme is deleted
	 *
	 * @param string $stylesheet Name of the theme stylesheet that was just deleted.
	 *
	 * @return void
	 */
	public function flush_composer_theme_delete( $stylesheet ) {
		$theme      = wp_get_theme( $stylesheet );
		$theme_name = '';

		if ( ! empty( $theme ) ) {
			$theme_name = $theme->get( 'Name' );
		}

		$this->logger->log_request( 'theme_delete', $theme_name );

		if ( $this->utilities->is_wp_cli_environment() ) {
			$this->update_plugin_tracker_file_post_bulk_update();
		} elseif ( $this->admin->should_real_time_update() ) {
			$this->create_plugin_tracker_file();
		}
	}

	/**
	 * Add a cron job to regenerate the composer.json file
	 *
	 * Regenerate the composer.json file in case a new plugin is added that we did not catch or a public plugin or theme
	 * is now considered "premium"
	 *
	 * @return void
	 */
	public function add_cron_job() {
		if ( function_exists( '\as_schedule_cron_action' ) && ! as_has_scheduled_action( 'cshp_pt_regenerate_composer_daily', array(), 'cshp_pt' ) ) {
			// schedule action scheduler to run once a day
			as_schedule_cron_action( strtotime( 'now' ), '0 2 * * *', 'cshp_pt_regenerate_composer_daily', array(), 'cshp_pt' );
		} elseif ( ! wp_next_scheduled( 'cshp_pt_regenerate_composer_daily' ) ) {
			wp_schedule_event( strtotime( '02:00:00' ), 'daily', 'cshp_pt_regenerate_composer_daily' );
		}
	}

	/**
	 * Get the path to the premium plugin download zip file.
	 *
	 * @param bool $active_only Get the zip file with only plugins that are active.
	 *
	 * @return false|string|null Path of the zip file on the server or empty of no zip file.
	 */
	public function get_premium_plugin_zip_file( $active_only = true ) {
		$plugins         = $this->utilities->get_active_plugins();
		$plugins_folders = array();

		foreach ( $plugins as $plugin ) {
			$plugin_folder_name      = dirname( $plugin );
			$plugin_folder_path_file = $this->utilities->get_plugin_file_full_path( $plugin );

			// exclude plugins that we explicitly don't want to download
			if ( in_array( $plugin_folder_name, $this->admin->get_excluded_plugins(), true ) ) {
				continue;
			}

			// if the plugin has disabled updates, include it in the list of premium plugins
			// only zip up plugins that are not available on WordPress.org
			if ( in_array( $plugin_folder_name, $this->premium_list->premium_plugins_list(), true ) || $this->is_premium_plugin( $plugin_folder_path_file ) ) {
				$plugins_folders[] = $plugin_folder_name;
			}
		}//end foreach

		$archive_zip_file_name = $this->archive->get_archive_zip_file_by_contents( $plugins_folders );

		if ( ! empty( $archive_zip_file_name ) && $this->archive->is_archive_zip_old( wp_basename( $archive_zip_file_name ) ) ) {
			return;
		}

		// if what is returned is not a full file path, add the full file path
		if ( ! $this->utilities->does_file_exists( $archive_zip_file_name ) && ! empty( $archive_zip_file_name ) ) {
			$archive_zip_file_name = sprintf( '%s/%s', $this->create_plugin_uploads_folder(), $archive_zip_file_name );
		}

		return $archive_zip_file_name;
	}

	/**
	 * Get the current list of installed WordPress plugins
	 *
	 * @return array Composer'ized array with plugin scope and name as the key and version as the value.
	 */
	public function generate_composer_installed_plugins() {
		$plugins_data = get_plugins();
		$format_data  = array();

		if ( ! empty( $plugins_data ) ) {
			foreach ( $plugins_data as $plugin_file => $data ) {
				$version       = isset( $data['Version'] ) && ! empty( $data['Version'] ) ? $data['Version'] : '*';
				$plugin_folder = dirname( $plugin_file );
				$repo          = 'wpackagist-plugin';

				if ( in_array( $plugin_folder, $this->premium_list->premium_plugins_list(), true ) || $this->is_premium_plugin( $plugin_file ) ) {
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
	public function generate_composer_installed_themes() {
		$themes      = wp_get_themes();
		$format_data = array();

		if ( ! empty( $themes ) ) {
			foreach ( $themes as $theme ) {
				$theme_path_info   = explode( DIRECTORY_SEPARATOR, $theme->get_stylesheet_directory() );
				$theme_folder_name = end( $theme_path_info );
				$repo              = 'wpackagist-theme';

				if ( in_array( $theme_folder_name, $this->premium_list->premium_themes_list(), true ) ||
					! $this->is_theme_available( $theme_folder_name, $theme->get( 'Version' ) ) ) {
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
	public function generate_composer_array() {
		$composer = $this->generate_composer_template();
		$plugins  = $this->generate_composer_installed_plugins();
		$themes   = $this->generate_composer_installed_themes();

		$composer['require']['core/wp'] = $this->utilities->get_current_wordpress_version();

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
	public function generate_readme() {
		$themes  = $this->generate_composer_installed_themes();
		$plugins = $this->generate_composer_installed_plugins();

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
						$this->generate_wordpress_markdown(),
						PHP_EOL,
						$this->generate_wordpress_wp_cli_install_command(),
						$this->generate_themes_markdown( $themes ),
						$this->generate_plugins_markdown( $plugins ),
						$this->generate_themes_wp_cli_install_command( $themes ),
						$this->generate_plugins_wp_cli_install_command( $plugins ),
						$this->generate_themes_zip_command( $themes ),
						$this->generate_plugins_zip_command( $plugins ),
					)
				)
			)
		);
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
	public function is_plugin_available( $plugin_slug, $version = '' ) {
		// if external requests are blocked to wordpress.org, assume that all plugins are premium plugins
		if ( $this->utilities->is_wordpress_org_external_request_blocked() ) {
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
	public function is_theme_available( $theme_slug, $version = '' ) {
		// if external requests are blocked to wordpress.org, assume that all themes are premium themes
		if ( $this->utilities->is_wordpress_org_external_request_blocked() ) {
			return false;
		}

		$url     = 'https://api.wordpress.org/themes/info/1.1/';
		$version = trim( $version );

		$request = array(
			'slug'   => $theme_slug,
			'fields' => array( 'versions' => true ),
		);

		$body = array(
			'action'  => 'theme_information',
			'request' => $request,
		);

		$theme_info = wp_remote_post(
			$url,
			array(
				'body' => $body,
			)
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
	public function is_premium_plugin( $plugin_folder_name_or_main_file ) {
		$plugin_path_file = $plugin_folder_name_or_main_file;
		$composer_file    = $this->get_tracker_file();
		if ( ! $this->utilities->does_file_exists( $plugin_path_file ) ) {
			$plugin_path_file = $this->utilities->get_plugin_file_full_path( $plugin_path_file );
		}

		if ( $this->utilities->does_file_exists( $plugin_path_file ) && is_file( $plugin_path_file ) ) {
			$plugin_data        = get_plugin_data( $plugin_path_file, false, false );
			$version_check      = isset( $data['Version'] ) ? $data['Version'] : '';
			$plugin_folder_name = dirname( $plugin_path_file );

			// if we could not extract the plugin directory name from the key,
			// assume that the plugin is a single file installed at the plugins folder root
			// (e.g. hello.php for the hello dolly plugin)
			if ( empty( $plugin_folder_name ) || '.' === $plugin_folder_name ) {
				$plugin_folder_name = $plugin_path_file;
			} else {
				$plugin_folder_name = basename( $plugin_folder_name );
			}

			// check the composer.json file and check if the file has been modified within the past week. If a plugin was marked as being public within the past week, then it is more than likely still available on wordpress.org
			// this prevents us from pinging wasting resources pinging the wordpress.org API and acts like a cache
			if ( $this->utilities->does_file_exists( $composer_file ) && is_file( $composer_file ) && time() - filemtime( $composer_file ) < WEEK_IN_SECONDS ) {
				$composer_file_array = wp_json_file_decode( $composer_file, array( 'associative' => true ) );

				$check_is_public  = sprintf( 'wpackagist-plugin/%s', $plugin_folder_name );
				$check_is_premium = sprintf( 'premium-plugin/%s', $plugin_folder_name );
				if ( isset( $composer_file_array['require'][ $check_is_public ] ) ) {
					return false;
				} elseif ( isset( $composer_file_array['require'][ $check_is_premium ] ) ) {
					return true;
				}
			}

			if ( $this->is_update_disabled( $plugin_data ) || ! $this->is_plugin_available( $plugin_folder_name, $version_check ) ) {
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
	public function is_update_disabled( $plugin_data ) {
		$update_disabled = false;
		// if a plugin or theme has explicitly set a UPDATE URI header, then check if the value is a boolean
		// if the plugin doesn't have an UPDATE URI, treat it like a wordpress.org plugin
		if ( is_array( $plugin_data ) && isset( $plugin_data['UpdateURI'] ) && is_bool( $plugin_data['UpdateURI'] ) ) {
			$update_disabled = false === $plugin_data['UpdateURI'];
		}

		return $update_disabled;
	}

	/**
	 * Save the installed and active plugins to a composer.json file that we can use to track the installed plugins
	 *
	 * @return void|string
	 */
	public function create_plugin_tracker_file() {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			return;
		}

		$folder_path = $this->create_plugin_uploads_folder();
		$file_path   = sprintf( '%s/composer.json', $folder_path );
		$error       = __( 'Tracker File could not be created due to unknown error. Maybe disk space or permissions error when writing to the file. WordPress Filesystem may be in FTP mode and the FTP credentials have not been updated. Please fix before trying again.', $this->get_text_domain() );

		if ( ! is_wp_error( $folder_path ) && ! empty( $folder_path ) ) {
			$composer_json = wp_json_encode( $this->generate_composer_array(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

			try {
				$composer_json_uploads_file = $wp_filesystem->put_contents( $file_path, $composer_json );
				if ( ! empty( $composer_json_uploads_file ) && ! is_wp_error( $composer_json_uploads_file ) ) {
					$this->logger->log_request( 'tracker_file_create' );
					// create a README.md so this information is easier to find
					$readme      = sprintf( '%s/README.md', $folder_path );
					$readme_text = $this->generate_readme();

					if ( $readme_text ) {
						$wp_filesystem->put_contents( $readme, $readme_text );
					}

					return;
				}
			} catch ( \TypeError $type_error ) {
				$error = $type_error->getMessage();
			}

			$this->logger->log_request( 'tracker_file_error', $error );
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
	public function create_plugin_tracker_file_cron() {
		if ( $this->maybe_update_tracker_file() ) {
			$this->create_plugin_tracker_file();
			$this->archive->delete_old_archive_posts();
		}
	}

	/**
	 * Update the composer file one-minute after some action. Usually after bulk plugin updates or bulk plugin installs.
	 *
	 * Useful to prevent the composer file from being written to multiple times after each plugin is updated.
	 *
	 * @return void
	 */
	public function update_plugin_tracker_file_post_bulk_update() {
		$one_minute_later = new \DateTime();
		$one_minute_later->add( new \DateInterval( 'PT1M' ) );
		// generate a cron expression for Action Scheduler to run one minute after plugin upgrades.
		// get minute without leading zero to conform to Crontab specification
		$one_minute_later_cron_expression = sprintf( '%d %d * * *', (int) $one_minute_later->format( 'i' ), $one_minute_later->format( 'G' ) );
		if ( function_exists( '\as_schedule_cron_action' ) && ! \as_has_scheduled_action( 'cshp_pt_regenerate_composer_post_bulk_update', array(), 'cshp_pt' ) ) {
			// schedule action scheduler to run once a day
			\as_schedule_cron_action( strtotime( 'now' ), $one_minute_later_cron_expression, 'cshp_pt_regenerate_composer_post_bulk_update', array(), 'cshp_pt' );
		} elseif ( ! wp_next_scheduled( 'cshp_pt_regenerate_composer_post_bulk_update' ) ) {
			wp_schedule_event( $one_minute_later->getTimestamp(), 'daily', 'cshp_pt_regenerate_composer_post_bulk_update' );
		}
	}

	/**
	 * Read the data from the saved plugin composer.json file
	 *
	 * @return array|null Key-value based array of the composer.json file, empty array if no composer.json file, or null.
	 */
	public function read_tracker_file() {
		if ( empty( $this->get_tracker_file() ) ) {
			return;
		}

		return wp_json_file_decode( $this->get_tracker_file(), array( 'associative' => true ) );
	}

	/**
	 * Get the path to the plugin tracker's generated composer.json file with the plugins and themes installed.
	 *
	 * @return string Full file path to the plugin tracker's generated composer.json
	 */
	public function get_tracker_file() {
		if ( is_wp_error( $this->create_plugin_uploads_folder() ) || empty( $this->create_plugin_uploads_folder() ) ) {
			return;
		}

		$plugins_file = sprintf( '%s/composer.json', $this->create_plugin_uploads_folder() );

		if ( ! file_exists( $plugins_file ) ) {
			return;
		}

		return $plugins_file;
	}

	/**
	 * Iterate through a folder and add the files from the folder that will be added to the final zip file.
	 *
	 * @param array  $folder_paths Array of full folder paths to add to the zip file.
	 * @param array $additional_files Array of additional files outside of the iterated folders that should also be added to the zip.
	 *
	 * @return string Path of the saved zip file or an error message of the zip file cannot be created.
	 */
	public function compile_files_for_zip( $folder_paths = array(), $additional_files = array() ) {
		$files_to_zip = array();

		foreach ( $folder_paths as $folder_path ) {
			$rii         = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder_path ) );
			$folder_name = basename( $folder_path );
			foreach ( $rii as $file ) {
				if ( $file->isDir() ) {
					continue;
				}

				if ( $this->utilities->str_starts_with( $file->getRealPath(), sprintf( '%s/plugins', WP_CONTENT_DIR ) ) ) {
					$file_relative_path = ltrim( $this->utilities->get_wp_content_relative_file_path( $file->getRealPath(), 'plugins' ), '/' );
				} elseif ( $this->utilities->str_starts_with( $file->getRealPath(), sprintf( '%s/themes', WP_CONTENT_DIR ) ) ) {
					$file_relative_path = ltrim( $this->utilities->get_wp_content_relative_file_path( $file->getRealPath(), 'themes' ), '/' );
				} elseif ( $this->utilities->str_starts_with( $file->getRealPath(), sprintf( '%s/uploads', WP_CONTENT_DIR ) ) ) {
					$file_relative_path = ltrim( $this->utilities->get_wp_content_relative_file_path( $file->getRealPath(), 'uploads' ), '/' );
				} else {
					$relative_path      = substr( $file->getRealPath(), strlen( $folder_path ) );
					$file_relative_path = $folder_name . '/' . $relative_path;
				}

				$files_to_zip[] = array(
					'file_path' => $file->getRealPath(),
					'zip_path'  => $file_relative_path,
				);
			}
		}//end foreach

		if ( ! empty( $additional_files ) ) {
			foreach ( $additional_files as $file_to_add ) {
				if ( is_array( $file_to_add ) && isset( $file_to_add[0] ) && isset( $file_to_add[1] ) ) {
					$files_to_zip[] = array(
						'file_path' => $file_to_add[0],
						'zip_path'  => $file_to_add[1],
					);
				} else {
					$files_to_zip[] = array(
						'file_path' => $file_to_add,
						'zip_path'  => '',
					);
				}
			}
		}

		return $files_to_zip;
	}

	/**
	 * Iterate through a folder and add the files from the folder to the zip archive
	 *
	 * @param string $zip_path Path and file name of the zip file.
	 * @param array  $folder_paths Array of full folder paths to add to the zip file.
	 * @param array $additional_files Array of additional files outside of the iterated folders that should also be added to the zip.
	 *
	 * @return string Path of the saved zip file or an error message of the zip file cannot be created.
	 */
	public function create_zip( $zip_path, $folder_paths = array(), $additional_files = array() ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			// fallback to WordPress's PclZip class
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}

		if ( ! class_exists( '\ZipArchive' ) && ! class_exists( '\PclZip' ) ) {
			return __( 'Error: Neither ZipArchive nor PclZip classes are available. The ZipArchive PHP module needs to be installed by your web host or the PclZip class needs to be included by WordPress.', $this->get_text_domain() );
		}

		$files_to_zip = $this->compile_files_for_zip( $folder_paths, $additional_files );

		if ( class_exists( '\ZipArchive' ) ) {
			$zip  = new \ZipArchive();
			$flag = \ZipArchive::CREATE;
			if ( $this->utilities->does_file_exists( $zip_path ) ) {
				$flag = \ZipArchive::OVERWRITE;
			}

			if ( $zip->open( $zip_path, $flag ) ) {
				foreach ( $files_to_zip as $file_to_zip ) {
					if ( ! empty( $file_to_zip['file_path'] ) && ! empty( $file_to_zip['zip_path'] ) ) {
						$zip->addFile( $file_to_zip['file_path'], $file_to_zip['zip_path'] );
					} elseif ( ! empty( $file_to_zip['file_path'] ) ) {
						$zip->addFile( $file_to_zip['file_path'] );
					}
				}
				$zip->close();
				// wp_zip_file_is_valid does not work since the zip does not get created until PHP execution has finished.
				// we just have to trust that it exists based on the zip being able to be open.
				// wp_zip_file_is_valid( $zip_path );
				return $zip_path;
			}
		} elseif ( class_exists( '\PclZip' ) ) {
			$pclzip = new \PclZip( $zip_path );

			$archive_files = array();
			foreach ( $files_to_zip as $file_to_zip ) {
				if ( ! empty( $file_to_zip['file_path'] ) && ! empty( $file_to_zip['zip_path'] ) ) {
					$archive_files[] = array(
						PCLZIP_ATT_FILE_NAME           => $file_to_zip['file_path'],
						PCLZIP_ATT_FILE_NEW_SHORT_NAME => $file_to_zip['zip_path'],
					);
				} elseif ( ! empty( $file_to_zip['file_path'] ) ) {
					$archive_files[] = $file_to_zip['file_path'];
				}
			}

			$result = $pclzip->create( $archive_files, PCLZIP_OPT_REMOVE_ALL_PATH );
			if ( 0 === $result ) {
				// If the zip file could not be created, return the error message.
				return __( 'Error: The zip file could not be created using PclZip. ' . $pclzip->errorInfo( true ), $this->get_text_domain() );
			}

			return $zip_path;
		}

		return __( 'Error: The zip file could not be created', $this->get_text_domain() );
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
	public function zip_premium_plugins_include( $only_include_plugins = array(), $include_all_plugins = false, $zip_name = '' ) {
		$included_plugins            = $this->utilities->get_active_plugins();
		$message                     = '';
		$zip_include_plugins         = array();
		$plugin_composer_data_list   = array();
		$saved_plugins_list          = array();
		$plugin_zip_contents         = array();
		$premium_plugin_folder_names = array();
		// remove the active plugin file name and just get the name of the plugin folder
		$active_plugin_folder_names = array_map( 'dirname', $included_plugins );

		// if we want all plugins, then get all plugins
		if ( true === $include_all_plugins && empty( $only_include_plugins ) ) {
			$included_plugins = array_keys( get_plugins() );
		} elseif ( ! empty( $only_include_plugins ) ) {
			$included_plugins = $only_include_plugins;
		}

		foreach ( $this->generate_composer_installed_plugins() as $plugin_folder_name => $plugin_version ) {
			$clean_folder_name = str_replace( array( 'premium-plugin/', 'wpackagist-plugin/' ), '', $plugin_folder_name );

			if ( str_contains( $plugin_folder_name, 'premium-plugin' ) ) {
				$premium_plugin_folder_names[] = $clean_folder_name;
			}

			$plugin_composer_data_list[ $clean_folder_name ] = $plugin_version;
		}

		$active_premium_plugin_folder_names = array_intersect( $premium_plugin_folder_names, $active_plugin_folder_names );
		// remove the excluded plugins if they are active
		$active_premium_plugin_folder_names = array_diff( $active_premium_plugin_folder_names, $this->admin->get_excluded_plugins() );
		$find_archive_file                  = $this->archive->get_archive_zip_file_by_contents( $active_premium_plugin_folder_names );

		if ( class_exists( '\ZipArchive' ) || class_exists( '\PclZip' ) ) {
			if ( $this->utilities->does_file_exists( $find_archive_file ) && ! $this->archive->is_archive_zip_old( wp_basename( $find_archive_file ) ) ) {
				$this->logger->log_request( 'plugin_zip_download', '', $find_archive_file );
				return $find_archive_file;
			} else {
				$this->logger->log_request( 'plugin_zip_download', __( 'Attempt download but plugin zip does not exists or has not been generated lately. Generate zip.', $this->get_text_domain() ) );
			}

			if ( ! is_wp_error( $this->create_plugin_uploads_folder() ) && ! empty( $this->create_plugin_uploads_folder() ) ) {
				$generate_zip_name = sprintf( 'plugins-%s.zip', wp_generate_uuid4() );

				if ( empty( $zip_name ) || ! is_string( $zip_name ) ) {
					$zip_name = $generate_zip_name;
				}

				if ( '.zip' !== substr( $zip_name, -4 ) ) {
					$zip_name .= '.zip';
				}

				$zip_path = sprintf( '%s/%s', $this->create_plugin_uploads_folder(), $zip_name );
				$this->logger->log_request( 'plugin_zip_start' );
				foreach ( $included_plugins as $plugin ) {
					$plugin_folder_name      = dirname( $plugin );
					$plugin_folder_path_file = $this->utilities->get_plugin_file_full_path( $plugin );
					$plugin_folder_path      = $this->utilities->get_plugin_file_full_path( dirname( $plugin ) );

					// exclude plugins that we explicitly don't want to download
					if ( in_array( $plugin_folder_name, $this->admin->get_excluded_plugins(), true ) ) {
						continue;
					}

					// if the plugin has disabled updates, include it in the list of premium plugins
					// only zip up plugins that are not available on WordPress.org
					if ( in_array( $plugin_folder_name, $this->premium_list->premium_plugins_list(), true ) || $this->is_premium_plugin( $plugin_folder_path_file ) ) {
						$zip_include_plugins[] = $plugin_folder_path;
						$saved_plugins_list[]  = $plugin_folder_name;
					}
				}//end foreach

				if ( empty( $zip_include_plugins ) ) {
					$message = __( 'No premium plugins are active on the site. If there are premium plugins, they may be excluded from downloading by the plugin settings.', $this->get_text_domain() );
				}

				if ( ! empty( $zip_include_plugins ) ) {
					$additional_files = array();
					// include a main plugin file in the zip so we can install the premium plugins
					$plugin_file = sprintf( '%s/scaffold/cshp-premium-plugins.php', $this->get_this_plugin_folder_path() );
					if ( file_exists( $plugin_file ) ) {
						// add the plugin file to the root of the zip file rather than as the entire path of the file
						$additional_files[] = array( $plugin_file, wp_basename( $plugin_file ) );
					}

					// include a main plugin file in the zip so we can install the premium plugins
					$plugin_index_file = sprintf( '%s/scaffold/index.php', $this->get_this_plugin_folder_path() );
					if ( file_exists( $plugin_index_file ) ) {
						// add the plugin file to the root of the zip file rather than as the entire path of the file
						$additional_files[] = array( $plugin_index_file, wp_basename( $plugin_index_file ) );
					}

					// include the generated composer.json file that keeps track of the plugins installed, so we can activate the premium plugins after they are installed.
					if ( is_file( $this->get_tracker_file() ) ) {
						$additional_files[] = array( $this->get_tracker_file(), wp_basename( $this->get_tracker_file() ) );
					}

					// if we have a list of plugins that we want zipped up, check again to make sure that a zip of these plugins does not already exist
					$plugins_to_zip    = array_map( 'wp_basename', $zip_include_plugins );
					$find_archive_file = $this->archive->get_archive_zip_file_by_contents( $plugins_to_zip );

					if ( $this->utilities->does_file_exists( $find_archive_file ) && ! $this->archive->is_archive_zip_old( wp_basename( $find_archive_file ) ) ) {
						$this->logger->log_request( 'plugin_zip_download', '', $find_archive_file );
						return $find_archive_file;
					}

					$zip_result = $this->create_zip( $zip_path, $zip_include_plugins, $additional_files );
					if ( $this->utilities->does_file_exists( $zip_result ) ) {
						// generate the data about the saved zip contents with the name of the plugins included and the versions of the plugins included
						foreach ( $saved_plugins_list as $plugin_folder ) {
							if ( isset( $plugin_composer_data_list[ $plugin_folder ] ) ) {
								$plugin_zip_contents[ $plugin_folder ] = $plugin_composer_data_list[ $plugin_folder ];
							}
						}

						ksort( $plugin_zip_contents );

						$this->save_plugin_archive_zip_file( $zip_path, $plugin_zip_contents );
						$this->logger->log_request( 'plugin_zip_create_complete' );
						$this->logger->log_request( 'plugin_zip_download' );
						return $zip_result;
					} else {
						$message = $zip_result;
						$this->logger->log_request( 'plugin_zip_error', $message );
					}//end if
				}//end if
			} else {
				$message = __( 'Error: Neither ZipArchive nor PclZip classes are available. The ZipArchive PHP module needs to be installed by your web host or the PclZip class needs to be included by WordPress.', $this->get_text_domain() );
			}//end if
		} else {
			$message = __( 'Error: Neither ZipArchive nor PclZip classes are available. The ZipArchive PHP module needs to be installed by your web host or the PclZip class needs to be included by WordPress.', $this->get_text_domain() );
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
	public function zip_premium_themes( $zip_name = '' ) {
		$themes                   = $this->utilities->get_active_themes();
		$excluded_themes          = $this->admin->get_excluded_themes();
		$zip_include_themes       = array();
		$message                  = '';
		$theme_composer_data_list = array();
		$saved_themes_list        = array();
		$theme_zip_contents       = array();

		foreach ( $this->generate_composer_installed_themes() as $theme_folder_name => $theme_version ) {
			$clean_folder_name                              = str_replace( array( 'premium-theme/', 'wpackagist-theme/' ), '', $theme_folder_name );
			$theme_composer_data_list[ $clean_folder_name ] = $theme_version;
		}

		if ( class_exists( '\ZipArchive' ) ) {
			// if we are using WP CLI, allow the zip of themes to be generated multiple times
			if ( ! $this->utilities->is_wp_cli_environment() ) {
				if ( $this->utilities->does_file_exists( $this->admin->get_premium_theme_zip_file() ) && ! $this->is_theme_zip_old() ) {
					$this->logger->log_request( 'theme_zip_download' );
					return $this->admin->get_premium_theme_zip_file();
				} else {
					$this->logger->log_request( 'themes_zip_download', __( 'Attempt download but theme zip does not exists or has not been generated. Generate zip.', $this->get_text_domain() ) );

				}
			}

			if ( ! is_wp_error( $this->create_plugin_uploads_folder() ) && ! empty( $this->create_plugin_uploads_folder() ) ) {
				$generate_zip_name = sprintf( 'themes-%s.zip', wp_generate_uuid4() );

				if ( empty( $zip_name ) || ! is_string( $zip_name ) ) {
					$zip_name = $generate_zip_name;
				}

				if ( '.zip' !== substr( $zip_name, -4 ) ) {
					$zip_name .= '.zip';
				}

				$zip_path = sprintf( '%s/%s', $this->create_plugin_uploads_folder(), $zip_name );
				$this->logger->log_request( 'theme_zip_start' );
				foreach ( $themes as $theme_folder_name ) {
					$theme = wp_get_theme( $theme_folder_name );

					// only zip up themes that are not available on WordPress.org
					if ( ! $theme->exists() ||
						in_array( $theme_folder_name, $excluded_themes, true ) ||
						$this->is_theme_available( $theme_folder_name, $theme->get( 'Version' ) ) ) {
						continue;
					}

					$zip_include_themes[] = $theme->get_stylesheet_directory();
					$theme_zip_contents[] = $theme_folder_name;
				}//end foreach

				if ( empty( $zip_include_themes ) ) {
					$message = __( 'No premium themes are active on the site. If there are premium themes, they may be excluded from downloading by the plugin settings.', $this->get_text_domain() );
				}

				if ( ! empty( $zip_include_themes ) ) {
					$additional_files = array();
					// include a main style.css file in the zip so we can install the premium themes
					$style_css_file = sprintf( '%s/scaffold/style.css', $this->get_this_plugin_folder_path() );
					if ( file_exists( $style_css_file ) ) {
						// add the style.css file to the root of the zip file rather than as the entire path of the file
						$additional_files[] = array( $style_css_file, basename( $style_css_file ) );
					}

					// include a main index.php file in the zip so we can install the premium themes
					$index_php_file = sprintf( '%s/scaffold/index.php', $this->get_this_plugin_folder_path() );
					if ( file_exists( $index_php_file ) ) {
						// add the index.php file to the root of the zip file rather than as the entire path of the file
						$additional_files[] = array( $index_php_file, basename( $index_php_file ) );
					}

					// include another CSS file in the zip so we can identify the premium themes folder
					// based on a unique file name
					$premium_themes_css_file = sprintf( '%s/scaffold/cshp-premium-themes.css', $this->get_this_plugin_folder_path() );
					if ( file_exists( $premium_themes_css_file ) ) {
						// add the style.css file to the root of the zip file rather than as the entire path of the file
						$additional_files[] = array( $premium_themes_css_file, basename( $premium_themes_css_file ) );
					}

					$zip_result = $this->create_zip( $zip_path, $zip_include_themes, $additional_files );
					if ( file_exists( $zip_result ) ) {
						// generate the composer tracker file just in case it has not been updated since before the tracker
						// file was created
						$this->create_plugin_tracker_file();
						$this->save_theme_zip_file( $zip_path );
						foreach ( $saved_themes_list as $theme_folder ) {
							if ( isset( $theme_composer_data_list[ $theme_folder ] ) ) {
								$theme_zip_contents[ $theme_folder ] = $theme_composer_data_list[ $theme_folder ];
							}
						}

						$this->save_theme_zip_file_contents( $theme_zip_contents );
						$this->logger->log_request( 'theme_zip_create_complete' );
						$this->logger->log_request( 'theme_zip_download' );
						return $this->admin->get_premium_theme_zip_file();
					} else {
						$message = $zip_result;
						$this->logger->log_request( 'theme_zip_error', $zip_result );
					}
				}//end if
			}//end if
		} else {
			$message = __( 'Error: Ziparchive PHP class does not exist or the PHP zip module is not installed', $this->get_text_domain() );
			$this->logger->log_request( 'theme_zip_error', $message );
		}//end if

		return $message;
	}

	/**
	 * Add rewrite rules to enable the downloading of the missing plugins as a zip file
	 *
	 * @return void
	 */
	public function add_rewrite_rules_endpoint() {
		add_rewrite_rule( '^cshp-plugin-tracker/plugin/download?$', 'index.php?cshp_plugin_tracker=plugin&cshp_plugin_tracker_action=download', 'top' );
		add_rewrite_rule( '^cshp-plugin-tracker/theme/download?$', 'index.php?cshp_plugin_tracker=theme&cshp_plugin_tracker_action=download', 'top' );
	}

	/**
	 * Add query arguments for the rewrite rule that enable users to download missing plugins
	 *
	 * @param array $vars Current list of query vars.
	 *
	 * @return array List of built-in and custom query vars.
	 */
	public function add_rewrite_query_vars( $vars ) {
		return array_merge(
			array(
				'cshp_plugin_tracker',
				'cshp_plugin_tracker_action',
				'cshp_pt_cpr',
				'cshp_pt_plugins',
			),
			$vars
		);
	}

	/**
	 * Add a REST API endpoint for downloading the missing plugins
	 *
	 * @return void
	 */
	public function add_rest_api_endpoint() {
		$route_args = array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_plugin_zip_rest' ),
				'permission_callback' => '__return_true',
			),
		);

		register_rest_route( 'cshp-plugin-tracker', '/plugin/download', $route_args );

		$route_args = array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_theme_zip_rest' ),
				'permission_callback' => '__return_true',
			),
		);

		register_rest_route( 'cshp-plugin-tracker', '/theme/download', $route_args );
	}

	/**
	 * The token to verify.
	 *
	 * @param string $passed_token The token that is used to verify that premium plugins can be downloaded from this website.
	 *
	 * @return bool True if the token was verified. False if the token could not be verified.
	 */
	public function is_token_verify( $passed_token ) {
		$stored_token = $this->admin->get_stored_token();

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
	public function is_ip_address_verify() {
		$url = sprintf( '%s/wp-json/cshp-plugin-backup/verify/ip-address', $this->updater->get_plugin_update_url() );

		$request = wp_safe_remote_head(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'cpr-ip-address' => $this->logger->get_request_ip_address(),
				),
			)
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
	public function is_authorized( $token = '' ) {
		return ! empty( $token ) ? $this->is_token_verify( $token ) : $this->is_ip_address_verify();
	}

	/**
	 * Get the domain that corresponds to the site key. The site key should be stored in the generated composer.json file
	 * and is used to automatically download the premium plugins without having to specify the domain or generate a token,
	 *
	 * @param string $site_key The generated site key on Cornershop Plugin Recovery.
	 *
	 * @return string The domain that a site key corresponds to.
	 */
	public function get_domain_from_site_key( $site_key = '' ) {
		$composer_file = $this->get_tracker_file();
		if ( empty( $site_key ) ) {
			if ( ! empty( $this->admin->get_site_key() ) ) {
				$site_key = $this->admin->get_site_key();
			} elseif ( ! empty( $composer_file ) && file_exists( $composer_file ) ) {
				$composer_file = wp_json_file_decode( $composer_file, array( 'associative' => true ) );

				if ( ! empty( $composer_file['extra'] ) && ! empty( $composer_file['extra']['cpr-site-key'] ) ) {
					$site_key = $composer_file['extra']['cpr-site-key'];
				}
			}
		}

		if ( ! empty( $site_key ) ) {
			$url     = sprintf( '%s/wp-json/cshp-plugin-backup/verify/site-key', $this->updater->get_plugin_update_url() );
			$url     = add_query_arg( array( 'cpr_site_key' => $site_key ), $url );
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
	}

	/**
	 * REST endpoint for downloading the premium plugins
	 *
	 * @param \WP_REST_Request $request WP REST API request.
	 *
	 * @return void|\WP_REST_Response Zip file for download or JSON response when there is an error.
	 */
	public function download_plugin_zip_rest( $request ) {
		$passed_token         = trim( sanitize_text_field( $request->get_param( 'token' ) ) );
		$try_whitelist_bypass = $request->has_param( 'bypass' );
		$diff_only            = $request->has_param( 'diff' );
		$not_exists           = $request->has_param( 'not_exists' );
		// determine if the file url should just returned as a string rather than redirecting to the zip for download
		$echo = $request->has_param( 'echo' );

		$skip_plugins    = array();
		$zip_all_plugins = true === boolval( sanitize_text_field( $request->get_param( 'include_all_plugins' ) ) );
		$plugins         = $request->get_param( 'plugins' );
		$clean_plugins   = array();

		if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
			$this->logger->log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', $this->get_text_domain() ), $this->logger->get_request_ip_address() ) );
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() ),
				),
				403
			);
		}

		if ( ! $this->is_authorized( $passed_token ) ) {
			$this->logger->log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', $this->get_text_domain() ) );
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() ),
				),
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
				function ( $plugin_folders ) use ( $clean_plugins ) {
					return array_merge( $plugin_folders, $clean_plugins );
				},
				10
			);
			$clean_plugins = array();
		} elseif ( $diff_only && ! empty( $skip_plugins ) ) {
			// exclude the plugins where the version number passed in the request matches the version installed on this website
			add_filter(
				'cshp_pt_exclude_plugins',
				function ( $plugin_folders ) use ( $skip_plugins ) {
					return array_merge( $plugin_folders, $skip_plugins );
				},
				10
			);
			$clean_plugins = array();
		}

		$zip_file_result = $this->zip_premium_plugins_include( $clean_plugins, $zip_all_plugins );

		if ( $this->utilities->does_file_exists( $zip_file_result ) ) {
			// if the user just wanted the URL to the zip file, then output that. Normally this would come from a WP CLI command
			if ( true === $echo ) {
				$zip_file_url = home_url( sprintf( '/%s', str_replace( ABSPATH, '', $zip_file_result ) ) );
				return new \WP_REST_Response(
					array(
						'error'   => true,
						'message' => esc_html__( 'Successfully generated zip file of premium plugins' ),
						'result'  => array(
							'url' => $zip_file_url,
						),
					),
					200
				);
			} else {
				$this->send_premium_plugins_zip_for_download( $zip_file_result );
			}
		}

		$message = __( 'Plugin zip file does not exist or cannot be generated', $this->get_text_domain() );

		if ( is_string( $zip_file_result ) && ! empty( $zip_file_result ) ) {
			$message = $zip_file_result;
		}

		return new \WP_REST_Response(
			array(
				'error'   => true,
				'message' => esc_html( $message ),
			),
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
	public function download_theme_zip_rest( $request ) {
		$passed_token         = trim( sanitize_text_field( $request->get_param( 'token' ) ) );
		$try_whitelist_bypass = $request->has_param( 'bypass' );

		if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
			$this->logger->log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', $this->get_text_domain() ), $this->logger->get_request_ip_address() ) );
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() ),
				),
				403
			);
		}

		if ( ! $this->is_authorized( $passed_token ) ) {
			$this->logger->log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', $this->get_text_domain() ) );
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => esc_html__( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() ),
				),
				403
			);
		}

		$zip_file_result = $this->zip_premium_themes();

		if ( $zip_file_result === $this->admin->get_premium_theme_zip_file() && $this->utilities->does_file_exists( $this->admin->get_premium_theme_zip_file() ) ) {
			$this->send_premium_themes_zip_for_download();
		}

		$message = __( 'Theme zip file does not exist or cannot be generated', $this->get_text_domain() );

		if ( is_string( $zip_file_result ) && ! empty( $zip_file_result ) ) {
			$message = $zip_file_result;
		}

		return new \WP_REST_Response(
			array(
				'error'   => true,
				'message' => esc_html( $message ),
			),
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
	public function download_plugin_zip_rewrite( &$query ) {
		if ( isset( $query->query_vars['cshp_plugin_tracker'] )
			&& 'plugin' === $query->query_vars['cshp_plugin_tracker']
			&& 'download' === $query->query_vars['cshp_plugin_tracker_action'] ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$passed_token = trim( sanitize_text_field( $_GET['token'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$try_whitelist_bypass = isset( $_GET['bypass'] );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$plugins       = $_GET['cshp_pt_plugins'];
			$clean_plugins = array();

			if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
				$this->logger->log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', $this->get_text_domain() ), $this->logger->get_request_ip_address() ) );
				http_response_code( 403 );
				esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() );
				exit;
			}

			if ( ! $this->is_authorized( $passed_token ) ) {
				$this->logger->log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', $this->get_text_domain() ) );
				http_response_code( 403 );
				esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() );
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
				$zip_file_result = $this->zip_premium_plugins_include( $clean_plugins );
			} else {
				$zip_file_result = $this->zip_premium_plugins_include();
			}

			if ( $this->utilities->does_file_exists( $zip_file_result ) ) {
				$this->send_premium_plugins_zip_for_download( $zip_file_result );
			}

			http_response_code( 410 );
			echo esc_html( $zip_file_result );
			exit;
		}//end if
	}

	/**
	 * REST endpoint for downloading the premium themes
	 *
	 * @param \WP_Query $query Current WordPress query.
	 *
	 * @return void Zip file for download or print error when something goes wrong.
	 */
	public function download_theme_zip_rewrite( &$query ) {
		if ( isset( $query->query_vars['cshp_plugin_tracker'] )
			&& 'theme' === $query->query_vars['cshp_plugin_tracker']
			&& 'download' === $query->query_vars['cshp_plugin_tracker_action'] ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$passed_token = trim( sanitize_text_field( $_GET['token'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$try_whitelist_bypass = isset( $_GET['bypass'] );

			if ( empty( $passed_token ) && ! $try_whitelist_bypass ) {
				$this->logger->log_request( 'token_verify_fail', sprintf( __( 'No token passed by IP address %s', $this->get_text_domain() ), $this->logger->get_request_ip_address() ) );
				http_response_code( 403 );
				esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() );
				exit;
			}

			if ( ! $this->is_authorized( $passed_token ) ) {
				$this->logger->log_request( 'token_verify_fail', __( 'Token passed is not authorized or the user is attempting a whitelist bypass and the IP address is not whitelisted', $this->get_text_domain() ) );
				http_response_code( 403 );
				esc_html_e( 'Token is not authorized. You must pass a token for this request or your IP address needs to be whitelisted', $this->get_text_domain() );
				exit;
			}

			$zip_file_result = $this->zip_premium_themes();

			if ( $zip_file_result === $this->admin->get_premium_theme_zip_file() && $this->utilities->does_file_exists( $this->admin->get_premium_theme_zip_file() ) ) {
				$this->send_premium_themes_zip_for_download();
			}

			http_response_code( 410 );
			echo esc_html( $zip_file_result );
			exit;
		}//end if
	}

	/**
	 * Redirect the browser to the premium plugins Zip file so the download can initiate
	 *
	 * @return void
	 */
	public function send_premium_plugins_zip_for_download( $zip_file_path = '' ) {
		if ( empty( $zip_file_path ) ) {
			$zip_file_path = $this->get_premium_plugin_zip_file();
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
	public function send_premium_themes_zip_for_download() {
		http_response_code( 302 );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Cache-Control: post-check=0, pre-check=0', false );
		header( 'Pragma: no-cache' );
		header( 'Content-Disposition: attachment; filename="premium-themes.zip"' );
		wp_safe_redirect( home_url( sprintf( '/%s', str_replace( ABSPATH, '', $this->admin->get_premium_theme_zip_file() ) ) ) );
		exit;
	}

	/**
	 * Test if the plugin zip is older than the last time the plugin tracker file was created
	 *
	 * Used as flag to determine if we should regenerate the plugins zip file
	 *
	 * @return bool True if the plugin zip file is older than the last time the composer.json file was created.
	 */
	public function is_plugin_zip_old() {
		$is_plugin_zip_old                = true;
		$now                              = current_datetime();
		$plugin_zip_create_complete_query = new \WP_Query(
			array(
				'post_type'              => $this->logger->get_log_post_type(),
				'posts_per_page'         => 1,
				'post_status'            => 'private',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => $this->logger->get_log_taxonomy(),
						'field'    => 'slug',
						'terms'    => 'plugin_zip_create_complete',
					),
				),
			)
		);

		$file_create_query = new \WP_Query(
			array(
				'post_type'              => $this->logger->get_log_post_type(),
				'posts_per_page'         => 1,
				'post_status'            => 'private',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => $this->logger->get_log_taxonomy(),
						'field'    => 'slug',
						'terms'    => 'tracker_file_create',
					),
				),
			)
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
			} elseif ( $this->utilities->is_wordpress_org_external_request_blocked() && $now->format( 'Y-m-d' ) === $plugin_zip_create_complete_date_time->format( 'Y-m-d' ) ) {
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
	public function is_theme_zip_old() {
		$theme_zip_create_complete_query = new \WP_Query(
			array(
				'post_type'              => $this->logger->get_log_post_type(),
				'posts_per_page'         => 1,
				'post_status'            => 'private',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => $this->logger->get_log_taxonomy(),
						'field'    => 'slug',
						'terms'    => 'theme_zip_create_complete',
					),
				),
			)
		);

		$file_create_query = new \WP_Query(
			array(
				'post_type'              => $this->logger->get_log_post_type(),
				'posts_per_page'         => 1,
				'post_status'            => 'private',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => $this->logger->get_log_taxonomy(),
						'field'    => 'slug',
						'terms'    => 'tracker_file_create',
					),
				),
			)
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
	 * Save the name of the premium plugins zip file that is saved with only some plugins.
	 *
	 * @param string $zip_file_path Path to the zip file to save.
	 * @param array  $zip_file_contents Array of plugins that are included in this zip.
	 *
	 * @return void
	 */
	public function save_plugin_archive_zip_file( $zip_file_path, $zip_file_contents ) {
		global $wp;
		$get_clean          = array();
		$result_post_id     = 0;
		$saved_plugins      = array();
		$saved_plugin_terms = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $_GET as $key => $get ) {
				$get_clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $get );
			}
		}

		foreach ( $zip_file_contents as $plugin_folder => $plugin_details ) {
			if ( term_exists( $plugin_folder, $this->archive->get_archive_taxonomy_slug() ) ) {
				$find_term = get_term_by( 'slug', $plugin_folder, $this->archive->get_archive_taxonomy_slug() );

				if ( ! is_wp_error( $find_term ) ) {
					$saved_plugin_terms[] = $find_term->term_id;
				}

				continue;
			}

			$new_term = wp_insert_term(
				$plugin_folder,
				$this->archive->get_archive_taxonomy_slug(),
				array(
					'slug' => $plugin_folder,
				)
			);

			if ( ! is_wp_error( $new_term ) ) {
				$saved_plugin_terms[] = $new_term->term_id;
			}
		}//end foreach

		$format_list = array();

		foreach ( $zip_file_contents as $plugin_folder_name => $plugin_data ) {
			$saved_plugins[] = $plugin_folder_name;
			$format_list[]   = sprintf( '<li>%s</li>', $plugin_folder_name );
		}

		$content = sprintf( '<p>%s</p><ul>%s</ul>', __( 'Archived plugins:', $this->get_text_domain() ), implode( '', $format_list ) );

		$request_url = add_query_arg( array_merge( $get_clean, $wp->query_vars ), home_url( $wp->request ) );

		$result_post_id = wp_insert_post(
			array(
				'post_type'    => $this->archive->get_archive_post_type(),
				'post_title'   => __( 'Generate plugin zip file', $this->get_text_domain() ),
				'post_content' => $content,
				'post_status'  => 'private',
				'tax_input'    => array(
					$this->archive->get_archive_taxonomy_slug() => array( $saved_plugins ),
				),
				'meta_input'   => array(
					'ip_address'                           => $this->logger->get_request_ip_address(),
					'geo_location'                         => $this->logger->get_request_geolocation(),
					'url'                                  => $request_url,
					'cshp_plugin_tracker_zip'              => basename( $zip_file_path ),
					'cshp_plugin_tracker_archived_plugins' => wp_slash( wp_json_encode( $zip_file_contents ) ),
				),
			)
		);

		// sometimes the term won't be added to the post on insert due to permissions of the logged-in user
		// https://wordpress.stackexchange.com/questions/210229/tax-input-not-working-wp-insert-post
		if ( ! is_wp_error( $result_post_id ) && ! empty( $result_post_id ) && ! has_term( $saved_plugins[0], $this->archive->get_archive_taxonomy_slug(), $result_post_id ) ) {
			wp_add_object_terms( $result_post_id, $saved_plugins, $this->archive->get_archive_taxonomy_slug() );
		}

		// after programatically adding the post to  the term, clear the saved cache
		foreach ( $saved_plugin_terms as $term_id ) {
			clean_term_cache( $term_id, $this->archive->get_archive_taxonomy_slug() );
		}
	}

	/**
	 * Save the new path of the theme zip file and delete the old zip file
	 *
	 * @param string $zip_file_path Path to the zip file to save.
	 *
	 * @return void
	 */
	public function save_theme_zip_file( $zip_file_path = '' ) {
		$previous_zip = $this->admin->get_premium_theme_zip_file();

		if ( ! empty( $previous_zip ) ) {
			wp_delete_file( $previous_zip );
		}

		if ( $this->utilities->does_file_exists( $zip_file_path ) ) {
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
	public function save_theme_zip_file_contents( $theme_content_data = '' ) {
		if ( $this->utilities->does_file_exists( $this->admin->get_premium_theme_zip_file() ) ) {
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
	public function generate_composer_template() {
		$relative_directory = basename( WP_CONTENT_DIR );
		$plugins_status     = $this->utilities->get_plugins_status();
		ksort( $plugins_status );
		$composer = array(
			'name'         => sprintf( '%s/wordpress', sanitize_key( get_bloginfo( 'name' ) ) ),
			'description'  => sprintf( __( 'Installed plugins and themes for the WordPress install %s', $this->get_text_domain() ), home_url() ),
			'type'         => 'project',
			'repositories' => array(
				'0' => array(
					'type' => 'composer',
					'url'  => 'https://wpackagist.org',
					'only' => array(
						'wpackagist-muplugin/*',
						'wpackagist-plugin/*',
						'wpackagist-theme/*',
					),
				),
				'1' => array(
					'type' => 'composer',
					'url'  => home_url( '/' ),
					'only' => array(
						'premium-plugin/*',
						'premium-theme/*',
						'core/wp',
					),
				),
			),
			'require'      => array(),
			'extra'        => array(
				'installer-paths' => array(
					sprintf( '%s/mu-plugins/{$name}/', $relative_directory ) => array(
						'type:wordpress-muplugin',
					),
					sprintf( '%s/plugins/{$name}/', $relative_directory ) => array(
						'type:wordpress-plugin',
					),
					sprintf( '%s/themes/{$name}/', $relative_directory ) => array(
						'type:wordpress-theme',
					),
				),
				'cpr-site-key'    => $this->admin->get_site_key(),
				'plugins_status'  => $plugins_status,
			),
		);

		return $composer;
	}

	/**
	 * Generate the WordPress Version markdown for the READE.md file
	 *
	 * @return string WordPress version in markdown.
	 */
	public function generate_wordpress_markdown() {
		return sprintf( '## WordPress Version%s- %s', PHP_EOL, $this->utilities->get_current_wordpress_version() );
	}

	/**
	 * Generating the markdown for showing the installed themes
	 *
	 * @param array $composer_json_required Composer JSON array of themes and plugins.
	 *
	 * @return string Markdown for the installed themes.
	 */
	public function generate_themes_markdown( $composer_json_required ) {
		$public                    = array();
		$premium                   = array();
		$public_markdown           = __( 'No public themes installed', $this->get_text_domain() );
		$premium_markdown          = __( 'No premium themes installed', $this->get_text_domain() );
		$themes_data               = wp_get_themes();
		$current_theme             = wp_get_theme();
		$current_theme_folder_name = '';
		$columns                   = array( 'Status', 'Name', 'Folder', 'Version', 'Theme URL/Author' );
		$public_table              = array();
		$premium_table             = array();

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
			$status            = __( 'Inactive', $this->get_text_domain() );

			if ( $theme_folder_name === $current_theme_folder_name ) {
				$status = __( 'Active', $this->get_text_domain() );
			}

			if ( in_array( $theme_folder_name, $public, true ) ) {
				$public_table[ $theme_folder_name ] = array(
					$status,
					$theme->get( 'Name' ),
					$theme_folder_name,
					$version,
					sprintf( '[Link](https://wordpress.org/themes/%s)', esc_html( $theme_folder_name ) ),
				);
			} elseif ( in_array( $theme_folder_name, $premium, true ) ) {
				$url = '';

				if ( ! empty( $theme->get( 'ThemeURI' ) ) ) {
					$url = sprintf( '[Link](%s)', esc_url( $theme->get( 'ThemeURI' ) ) );
				} elseif ( ! empty( $theme->get( 'AuthorURI' ) ) ) {
					$url = sprintf( '[Link](%s)', esc_url( $theme->get( 'AuthorURI' ) ) );
				} elseif ( ! empty( $theme->get( 'Author' ) ) ) {
					$url = esc_html( $theme->get( 'Author' ) );
				} else {
					$url = __( 'No author data found.', $this->get_text_domain() );
				}

				$premium_table[ $theme_folder_name ] = array(
					$status,
					$theme->get( 'Name' ),
					$theme_folder_name,
					$version,
					$url,
				);
			}//end if
		}//end foreach

		if ( ! empty( $public_table ) ) {
			ksort( $public_table );
			$table = new \TextTable( $columns, array() );
			// increase the max length of the data to account for long urls for theme homepages, otherwise the library
			// will cutoff of the url
			$table->maxlen = 300;
			$table->addData( $public_table );
			$public_markdown = $table->render();
		}

		if ( ! empty( $premium_table ) ) {
			ksort( $premium_table );
			$table = new \TextTable( $columns, array() );
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
	public function generate_wordpress_wp_cli_install_command() {
		return sprintf(
			'## WP-CLI Command to Install WordPress Core%s`wp core download --skip-content --version="%s" --force --path=.`',
			PHP_EOL,
			$this->utilities->get_current_wordpress_version()
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
	public function generate_themes_wp_cli_install_command( $composer_json_required, $context = 'markdown' ) {
		$data    = array();
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
				$data    = implode( ' && ', $data );
				$install = $data;
			}
		} else {
			$install = __( 'No public themes installed.', $this->get_text_domain() );
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
	public function generate_themes_zip_command( $composer_json_required ) {
		$data = array();

		foreach ( $composer_json_required as $theme_name => $version ) {
			$clean_name = str_replace( 'premium-theme/', '', str_replace( 'wpackagist-theme/', '', $theme_name ) );

			if ( false !== strpos( $theme_name, 'premium-theme' ) ) {
				$data[] = $clean_name;
			}
		}

		if ( ! empty( $data ) ) {
			$data = sprintf( '`zip -r premium-themes.zip %1$s`', implode( ' ', $data ) );
		} else {
			$data = __( 'No premium themes installed.', $this->get_text_domain() );
		}

		return sprintf(
			'## Command Line to Zip Themes%1$s%2$s%1$s%3$s',
			PHP_EOL,
			__( 'Use command to zip premium themes if the .zip file cannot be created or downloaded', $this->get_text_domain() ),
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
	public function generate_premium_themes_wp_cli_install_command( $context = 'markdown' ) {
		$command = sprintf( 'wp cshp-pt theme-install %s --force', esc_url( $this->admin->get_api_theme_downloads_endpoint() ) );

		if ( 'markdown' === $context ) {
			return sprintf(
				'## WP CLI command to downlaod and install Premium Themes %1$s%2$s%1$s`%3$s`',
				PHP_EOL,
				__( 'Use command to download and install the premium themes', $this->get_text_domain() ),
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
	public function generate_wget_plugins_download_command( $context = 'markdown' ) {
		$command = sprintf( 'wget --content-disposition --output-document=premium-plugins.zip %s', esc_url( $this->admin->get_api_active_plugin_downloads_endpoint() ) );

		if ( 'markdown' === $context ) {
			return sprintf(
				'## Command Line to Download Premium Plugins with wget%1$s%2$s%1$s`%3$s`',
				PHP_EOL,
				__( 'Use command to download the zipped premium plugins', $this->get_text_domain() ),
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
	public function generate_curl_plugins_download_command( $context = 'markdown' ) {
		$command = sprintf( 'curl -JLO %s', esc_url( $this->admin->get_api_active_plugin_downloads_endpoint() ) );

		if ( 'markdown' === $context ) {
			return sprintf(
				'## Command Line to Download Premium Plugins with cURL%1$s%2$s%1$s`%3$s`',
				PHP_EOL,
				__( 'Use command to download the zipped premium plugins', $this->get_text_domain() ),
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
	public function generate_wget_themes_download_command( $context = 'markdown' ) {
		$command = sprintf( 'wget --content-disposition --output-document=premium-themes.zip %s', esc_url( $this->admin->get_api_theme_downloads_endpoint() ) );

		if ( 'markdown' === $context ) {
			return sprintf(
				'## Command Line to Download Premium Themes with wget%1$s%2$s%1$s`%3$s`',
				PHP_EOL,
				__( 'Use command to download the zipped premium themes', $this->get_text_domain() ),
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
	public function generate_curl_themes_download_command( $context = 'markdown' ) {
		$command = sprintf( 'curl -JLO %s', esc_url( $this->admin->get_api_theme_downloads_endpoint() ) );

		if ( 'markdown' === $context ) {
			return sprintf(
				'## Command Line to Download Premium Themes with cURL%1$s%2$s%1$s`%3$s`',
				PHP_EOL,
				__( 'Use command to download the zipped premium themes', $this->get_text_domain() ),
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
	public function generate_plugins_markdown( $composer_json_required ) {
		$public           = array();
		$premium          = array();
		$public_markdown  = __( 'No public plugins installed', $this->get_text_domain() );
		$premium_markdown = __( 'No premium plugins installed', $this->get_text_domain() );
		$plugins_data     = get_plugins();
		$columns          = array( 'Status', 'Name', 'Folder', 'Version', 'Plugin URL/Author' );
		$public_table     = array();
		$premium_table    = array();
		$active_plugins   = $this->utilities->get_active_plugins();

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
			$status        = __( 'Inactive', $this->get_text_domain() );

			if ( in_array( $plugin_file, $active_plugins, true ) ) {
				$status = __( 'Active', $this->get_text_domain() );
			}

			if ( in_array( $plugin_folder, $public, true ) ) {
				$public_table[ $plugin_folder ] = array(
					$status,
					$data['Name'],
					$plugin_folder,
					$version,
					sprintf( '[Link](https://wordpress.org/plugins/%s)', esc_html( $plugin_folder ) ),
				);
			} elseif ( in_array( $plugin_folder, $premium, true ) ) {
				$url = '';

				if ( isset( $data['PluginURI'] ) && ! empty( $data['PluginURI'] ) ) {
					$url = sprintf( '[Link](%s)', esc_url( $data['PluginURI'] ) );
				} elseif ( isset( $data['AuthorURI'] ) && ! empty( $data['AuthorURI'] ) ) {
					$url = sprintf( '[Link](%s)', esc_url( $data['AuthorURI'] ) );
				} elseif ( isset( $data['Author'] ) && ! empty( $data['Author'] ) ) {
					$url = esc_html( $data['Author'] );
				} else {
					$url = __( 'No author data found.', $this->get_text_domain() );
				}

				$premium_table[ $plugin_folder ] = array(
					$status,
					$data['Name'],
					$plugin_folder,
					$version,
					$url,
				);
			}//end if
		}//end foreach

		if ( ! empty( $public_table ) ) {
			ksort( $public_table );
			$table = new \TextTable( $columns, array() );
			// increase the max length of the data to account for long urls for plugin homepages, otherwise the library
			// will cutoff of the url
			$table->maxlen = 300;
			$table->addData( $public_table );
			$public_markdown = $table->render();
		}

		if ( ! empty( $premium_table ) ) {
			ksort( $premium_table );
			$table = new \TextTable( $columns, array() );
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
	public function generate_plugins_wp_cli_install_command( $composer_json_required, $context = 'markdown' ) {
		$data    = array();
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
				$data    = implode( ' && ', $data );
				$install = $data;
			}
		} else {
			$install = __( 'No public plugins installed.', $this->get_text_domain() );
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
	public function generate_plugins_zip_command( $composer_json_required ) {
		$data = array();

		foreach ( $composer_json_required as $plugin_name => $version ) {
			$clean_name = str_replace( 'premium-plugin/', '', str_replace( 'wpackagist-plugin/', '', $plugin_name ) );

			if ( false !== strpos( $plugin_name, 'premium-plugin' ) ) {
				$data[] = $clean_name;
			}
		}

		if ( ! empty( $data ) ) {
			$data = sprintf( '`zip -r premium-plugins.zip %1$s`', implode( ' ', $data ) );
		} else {
			$data = __( 'No premium plugins installed.', $this->get_text_domain() );
		}

		return sprintf(
			'## Command Line to Zip Plugins%1$s%2$s%1$s%3$s',
			PHP_EOL,
			__( 'Use command to zip premium plugins if the .zip file cannot be created or downloaded', $this->get_text_domain() ),
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
	public function generate_premium_plugins_wp_cli_install_command( $context = 'markdown' ) {
		$command = sprintf( 'wp cshp-pt plugin-install %s --force', esc_url( $this->admin->get_api_active_plugin_downloads_endpoint() ) );

		if ( 'markdown' === $context ) {
			return sprintf(
				'## WP CLI command to downlaod and install Premium Plugins %1$s%2$s%1$s`%3$s`',
				PHP_EOL,
				__( 'Use command to download and install the premium plugins', $this->get_text_domain() ),
				$command
			);
		}

		return $command;
	}

	/**
	 * Determine if the tracker file should be regenerated based on if WP core, plugins, or themes have changed.
	 *
	 * @return bool True if the tracker file should update based on some change. False otherwise.
	 */
	public function maybe_update_tracker_file() {
		$composer      = $this->generate_composer_array();
		$composer_file = $this->read_tracker_file();

		// only save the composer.json file if the requirements have changed from the last time the file was saved
		if ( empty( $composer_file ) || ( isset( $composer['require'] ) &&
											isset( $composer_file['require'] ) &&
											! empty( array_diff_assoc( $composer['require'], $composer_file['require'] ) ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Redirect the user to the REST endpoint when they request to website using a special url parameter that will trigger the downloading of the plugins from the website.
	 *
	 * @return void
	 */
	public function trigger_zip_download_with_site_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['cshp_pt_cpr'] ) ) {
			// phpcs:disable
			$key           = sanitize_text_field( $_GET['cshp_pt_cpr'] );
			$type          = 'plugin';
			$plugins       = ! empty( $_GET['cshp_pt_plugins'] ) ? $_GET['cshp_pt_plugins'] : '';
			$diff          = isset( $_GET['cshp_pt_diff'] );
			$not_exists    = isset( $_GET['cshp_pt_not_exists'] );
			$echo_result   = isset( $_GET['cshp_pt_echo'] );
			// phpcs:enable
			$clean_plugins = array();

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

	/**
	 * Log a zip download or token creation request to the Log post type.
	 *
	 * Logs a request with specified type, message, and optional archive URL.
	 *
	 * @param string $type The type of the log entry (e.g., 'plugin_activate', 'plugin_deactivate', etc.).
	 * @param string $message Optional. Additional message or details for the log entry. Default is an empty string.
	 * @param string $archive_zip_url Optional. URL to an archive ZIP file, if applicable. Default is an empty string.
	 *
	 * @return bool True if the log entry was successfully recorded, false otherwise.
	 */
	public function log_request( $type, $message = '', $archive_zip_url = '' ) {
		$allowed_types          = $this->logger->get_allowed_log_types();
		$title                  = '';
		$content                = '';
		$term_object            = null;
		$use_message_for_title  = array( 'plugin_activate', 'plugin_deactivate', 'plugin_uninstall', 'theme_activate', 'theme_deactivate', 'theme_uninstall' );
		$user_geo_location_data = sprintf( __( 'IP Address: %1$s. Geolocation: %2$s', $this->get_text_domain() ), $this->logger->get_request_ip_address(), $this->logger->get_request_geolocation() );

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
					$title = sprintf( '%s %s', $title, $this->admin->get_stored_token() );
				} elseif ( false !== strpos( $type, 'plugin_zip' ) ) {
					$zip_file = $this->get_premium_plugin_zip_file();

					if ( ! empty( $archive_zip_url ) ) {
						$zip_file = $archive_zip_url;
					}

					$title = sprintf( '%s %s', $title, $zip_file );
				} elseif ( false !== strpos( $type, 'theme_zip' ) ) {
					$title = sprintf( '%s %s', $title, $this->admin->get_premium_theme_zip_file() );
				} elseif ( in_array( $type, $use_message_for_title, true ) ) {
					$title = sprintf( '%s %s', $title, $message );
				}

				break;
			}//end foreach
		}//end if

		if ( is_user_logged_in() ) {
			$title = sprintf( __( '%1$s by %2$s', $this->get_text_domain() ), $title, wp_get_current_user()->user_login );
		}

		if ( false !== strpos( $type, 'download' ) ) {
			$content = sprintf( __( 'Downloaded by %s', $this->get_text_domain() ), sanitize_text_field( $user_geo_location_data ) );
		} elseif ( false !== strpos( $type, 'create' ) ) {
			$content = sprintf( __( 'Generated by %s', $this->get_text_domain() ), sanitize_text_field( $user_geo_location_data ) );
		} elseif ( false !== strpos( $type, 'delete' ) ) {
			$content = sprintf( __( 'Deleted by %s', $this->get_text_domain() ), sanitize_text_field( $user_geo_location_data ) );
		} elseif ( false !== strpos( $type, 'verify_fail' ) ) {
			$content = sprintf( __( 'Verification failed by %s', $this->get_text_domain() ), sanitize_text_field( $user_geo_location_data ) );
		} elseif ( false !== strpos( $type, 'activate' ) ) {
			$content = sprintf( __( 'Activated by %s', $this->get_text_domain() ), sanitize_text_field( $user_geo_location_data ) );
		} elseif ( false !== strpos( $type, 'deactivate' ) ) {
			$content = sprintf( __( 'Deactivated by %s', $this->get_text_domain() ), sanitize_text_field( $user_geo_location_data ) );
		} elseif ( false !== strpos( $type, 'uninstall' ) ) {
			$content = sprintf( __( 'Uninstalled by %s', $this->get_text_domain() ), sanitize_text_field( $user_geo_location_data ) );
		}

		return $this->logger->log_request( $type, $title, $content );
	}
}
