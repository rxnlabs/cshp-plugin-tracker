<?php
/**
 * Features related to backing up the premium plugins to the Cornershop Plugins Recovery website and reinstalling the backups to a staging website.
 */
declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

// exit if not loading in WordPress context but don't exit if running our PHPUnit tests
if ( ! defined( 'ABSPATH' ) && ! defined( 'CSHP_PHPUNIT_TESTS_RUNNING' ) ) {
	exit;
}

class Backup {
	use Share;

	/**
	 * Plugin_Tracker instance responsible for tracking and managing plugin activities or status.
	 *
	 * @var Plugin_Tracker
	 */
	private $plugin_tracker;
	/**
	 * Utility instance providing various helper methods and functionalities.
	 *
	 * @var Utility
	 */
	private $utilities;
	/**
	 * Updater instance handles the update process for the application by managing version checks and applying updates.
	 *
	 * @var Updater
	 */
	private $updater;
	/**
	 * Admin instance responsible for managing the admin settings and interface.
	 *
	 * @var Admin
	 */
	private $admin;
	/**
	 * Logger instance used for logging application events and messages.
	 *
	 * @var Logger
	 */
	private $logger;


	/**
	 * Constructor method for initializing the class with required dependencies.
	 *
	 * @param Utilities $utilities Instance of the Utilities class to provide utility functions.
	 * @param Updater $updater Instance of the Updater class to handle update processes.
	 * @param Admin $admin Instance of the Admin class to manage admin-related functionality.
	 * @param Logger $logger Instance of the Logger class to handle logging operations.
	 *
	 * @return void
	 */
	public function __construct( Utilities $utilities, Updater $updater, Admin $admin, Logger $logger ) {
		$this->utilities = $utilities;
		$this->updater   = $updater;
		$this->admin     = $admin;
		$this->logger    = $logger;
	}

	/**
	 * Create a setter method to prevent circular logic with dependency injection since the Backup class uses methods of the Plugin_Tracker class but Plugin_Tracker depends on the admin class for DI.
	 *
	 * @param  Plugin_Tracker $plugin_tracker
	 *
	 * @return void
	 */
	public function set_plugin_tracker( Plugin_Tracker $plugin_tracker ) {
		$this->plugin_tracker = $plugin_tracker;
	}

	/**
	 * Retrieves the plugin tracker instance.
	 *
	 * @return Plugin_Tracker The instance of the plugin tracker.
	 * @throws \Exception If the plugin tracker class is not set.
	 */
	public function get_plugin_tracker() {
		if ( ! $this->plugin_tracker instanceof Plugin_Tracker ) {
			throw new \Exception( 'Plugin Tracker class not set' );
		}

		return $this->plugin_tracker;
	}

	/**
	 * Registers the actions and filter hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'cshp_pt_regenerate_composer_daily', array( $this, 'backup_premium_plugins_cron' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_result' ), 99, 3 );
		add_filter( 'plugin_install_action_links', array( $this, 'alter_plugin_install_activation_links' ), 99, 2 );
		add_action( 'plugins_loaded', array( $this, 'fix_plugin_activation_hook_old_scaffold' ), 1 );
		add_filter( 'upgrader_post_install', array( $this, 'post_cpr_plugin_install' ), 1, 3 );
	}

	/**
	 * Backup the zip of the premium plugins during a cron job.
	 *
	 * @return void
	 */
	public function backup_premium_plugins_cron() {
		$this->backup_premium_plugins();
	}

	/**
	 * Back up the premium plugins zip to a dedicated backup retrieval site.
	 *
	 * Useful for retrieving the old versions of premium plugins that are on sites if these plugins are not in Git
	 * and the plugin sites don't let you download old versions (e.g. Gravity Forms)
	 *
	 * @return bool|string True if the premium zip file was backed up successfully. Error message if the zip could not be backed up.
	 */
	public function backup_premium_plugins() {
		$plugin_tracker                    = $this->get_plugin_tracker();
		$return_message                    = '';
		$plugin_content_compare            = array();
		$generated_zip_file_during_command = false;
		$error_zip_file                    = false;
		$backup_url                        = sprintf( '%s/wp-json/cshp-plugin-backup/backup', $this->updater->get_plugin_update_url() );
		$plugin_content                    = $plugin_tracker->generate_composer_installed_plugins();
		$excluded_plugins                  = $this->admin->get_excluded_plugins();

		if ( ! function_exists( '\curl_init' ) || $this->utilities->is_development_mode() ) {

			$return_message = esc_html__( 'Can only backup plugins on sites with: cURL installed, in production mode, and not in a known development environment.', $this->get_text_domain() );

			// flag if the site is in production mode but the Kinsta dev environment constant is set
			if ( defined( 'KINSTA_DEV_ENV' ) &&
				true === KINSTA_DEV_ENV &&
				'production' === wp_get_environment_type() ) {
				$return_message = esc_html__( 'Error backing up plugin zip: Website is in production mode but the KINSTA_DEV_ENV flag is on. Turn off the KINSTA_DEV_ENV flag.', $this->get_text_domain() );
				$this->logger->log_request( 'plugin_zip_backup_error', $return_message );
			}

			return $return_message;
		}

		foreach ( $plugin_content as $plugin_key => $version ) {
			if ( false === strpos( $plugin_key, 'premium-plugin' ) ) {
				continue;
			}

			$plugin_folder_name = str_replace( 'premium-plugin/', '', $plugin_key );

			// prevent this plugin from being included in the premium plugins zip file
			// exclude plugins that we explicitly don't want to download
			// if the plugin should not download when we create the zip, don't back it up
			if ( $plugin_folder_name === $this->get_this_plugin_folder_name() || in_array( $plugin_folder_name, $excluded_plugins, true ) ) {
				continue;
			}

			$plugin_content_compare[ $plugin_folder_name ] = $version;
		}

		ksort( $plugin_content_compare );

		if ( $this->utilities->is_external_domain_blocked( $backup_url ) ) {
			$return_message = esc_html__( 'Plugin zip backup site is blocked by WP_HTTP_BLOCK_EXTERNAL', $this->get_text_domain() );
			$this->logger->log_request( 'plugin_zip_backup_error', $return_message );
			return $return_message;
		}

		// If this combination of premium plugins have already been backed up before,
		if ( $this->has_premium_plugins_version_backed_up( $plugin_content_compare ) ) {
			$return_message = esc_html__( 'This version of the premium plugins have already been backed up. You can only backup a unique combination of plugins.', $this->get_text_domain() );
			$this->logger->log_request( 'plugin_zip_backup_error', $return_message );
			return $return_message;
		}

		// If the premium plugins have already been backed up today, don't try to back up again.
		if ( $this->has_premium_plugins_backed_up_today() ) {
			$return_message = esc_html__( 'Premium plugins already backed up today. Can only back up once a day.', $this->get_text_domain() );
			$this->logger->log_request( 'plugin_zip_backup_error', $return_message );
			return $return_message;
		}

		if ( $plugin_tracker->maybe_update_tracker_file() || ! $this->utilities->does_file_exists( $plugin_tracker->get_premium_plugin_zip_file() ) ) {
			$result = $plugin_tracker->zip_premium_plugins_include();

			if ( ! $this->utilities->does_file_exists( $result ) ) {
				$error_zip_file = true;
				$return_message = esc_html__( 'Could not create a backup due to  error zipping premium plugins. Check plugin log.', $this->get_text_domain() );
				$this->logger->log_request( 'plugin_zip_backup_error', $return_message );
			}
		}

		if ( ! $error_zip_file && ! empty( $plugin_tracker->get_premium_plugin_zip_file() ) ) {
			$premium_plugin_zip_file = $plugin_tracker->get_premium_plugin_zip_file();
			$archive_zip_file_name   = basename( $premium_plugin_zip_file );
			$plugin_content          = wp_json_encode( $plugin_tracker->archive->get_premium_plugin_zip_file_contents( $archive_zip_file_name ) );
			$home_url                = home_url( '/' );
			$domain                  = wp_parse_url( $home_url );
			$zip_file_upload_name    = sprintf( '%s.zip', sanitize_title( sprintf( '%s/%s', $domain['host'], $domain['path'] ) ) );
			$curl_file               = new \CurlFile( $premium_plugin_zip_file );
			$curl_file->setPostFilename( $zip_file_upload_name );
			$form_fields = array(
				'domain'                  => $home_url,
				'premium_plugin_zip_file' => $curl_file,
				'plugins'                 => $plugin_content,
				'site_key'                => $this->admin->get_site_key(),
			);

			// Use an anonymous function and pass the local variable that we want to post to that function since
			// the http_api_curl hook does not pass the data that we actually want to POST with the hook
			$file_upload_request = function ( $handle_or_parameters, $request = '', $url = '' ) use ( $form_fields ) {
				update_wp_http_request( $handle_or_parameters, $form_fields );
			};
			// handle cURL requests if we have cURL installed
			add_action( 'http_api_curl', $file_upload_request, 10 );
			// handle fsockopen requests if we don't have cURL installed
			add_action( 'requests-fsockopen.before_send', $file_upload_request, 10, 3 );

			$request = wp_remote_post(
				$backup_url,
				array(
					'body'    => $form_fields,
					'headers' => array( 'content-type' => 'multipart/form-data' ),
					'timeout' => 12,
				)
			);

			if ( 200 === wp_remote_retrieve_response_code( $request ) ||
				201 === wp_remote_retrieve_response_code( $request ) ||
				202 === wp_remote_retrieve_response_code( $request ) ) {
				// add the name of the zip that was backed up.
				$return_message = sprintf( esc_html__( 'Successfully backed up plugin zip %s', $this->get_text_domain() ), $archive_zip_file_name );
				$log_post       = $this->logger->log_request( 'plugin_zip_backup_complete', $return_message );

				if ( ! empty( $log_post ) && ! is_wp_error( $log_post ) ) {
					update_post_meta( $log_post, '_plugins_backed_up', $plugin_content );
				}
			} else {
				$result = wp_remote_retrieve_body( $request );
				// If we received ane empty error message from the server, then we may be blocked.
				if ( empty( $result ) ) {
					$result = __( 'Empty error message returned from back up site. This site may be blocked by the backup site\'s server.', $this->get_text_domain() );
				}

				$return_message = sprintf( esc_html__( 'Error backing up plugin zip %1$s: %2$s', $this->get_text_domain() ), basename( $plugin_tracker->get_premium_plugin_zip_file() ), $result );
				$this->logger->log_request( 'plugin_zip_backup_error', $return_message );
			}//end if
		}//end if

		return ! empty( $return_message ) ? $return_message : true;
	}

	/**
	 * Update the WP cURL and fsockopen requests to make them work with file uploads.
	 *
	 * @param resource|array $handle_or_parameters cURL handle or fsockopen parameters. This is passed by reference.
	 * @param array          $form_body_arguments Form data to POST to the remote service.
	 * @param string         $url The URL that the cURL or fsockopen request is being made to.
	 *
	 * @return void
	 */
	public function update_wp_http_request( &$handle_or_parameters, $form_body_arguments = '', $url = '' ) {
		if ( function_exists( '\curl_init' ) && function_exists( '\curl_exec' ) ) {
			foreach ( $form_body_arguments as $value ) {
				// Only do this if we are using PHP 5.5+ CURLFile file to upload a file
				if ( 'object' === gettype( $value ) && $value instanceof \CURLFile ) {
					/*
					Use the request body as an array to force cURL make a requests using 'multipart/form-data'
					as the Content-type header instead of WP's default habit of converting the request to
					a string using http_build_query function
					*/
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
					curl_setopt( $handle_or_parameters, CURLOPT_POSTFIELDS, $form_body_arguments );
					break;
				}
			}
		} elseif ( function_exists( '\fsockopen' ) ) {
			// UNTESTED SINCE I HAVE cURL INSTALLED AND CANNOT TEST THIS
			$form_fields = array();
			$form_files  = array();
			foreach ( $form_body_arguments as $name => $value ) {
				if ( file_exists( $value ) ) {
					// Not great for large files since it dumps into memory but works well for small files
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$form_files[ $name ] = file_get_contents( $value );
				} else {
					$form_fields[ $name ] = $value;
				}
			}

			/**
			 * Convert form fields arrays to a string that fsockopen requests can understand
			 *
			 * @see https://gist.github.com/maxivak/18fcac476a2f4ea02e5f80b303811d5f
			 */
			function build_data_files( $boundary, $fields, $files ) {
				$data = '';
				$eol  = "\r\n";

				$delimiter = '-------------' . $boundary;

				foreach ( $fields as $name => $content ) {
					$data .= '--' . $delimiter . $eol
							. 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol
							. $content . $eol;
				}

				foreach ( $files as $name => $content ) {
					$data .= '--' . $delimiter . $eol
							. 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $name . '"' . $eol
							// . 'Content-Type: image/png'.$eol
							. 'Content-Transfer-Encoding: binary' . $eol;

					$data .= $eol;
					$data .= $content . $eol;
				}
				$data .= '--' . $delimiter . '--' . $eol;

				return $data;
			}
			$boundary             = uniqid();
			$handle_or_parameters = build_data_files( $boundary, $form_fields, $form_files );
		}//end if
	}

	/**
	 * Check if the premium plugins zip have already been backed up today.
	 *
	 * @return bool True if the premium plugins have been successfully backed up today. False if there was no successful backup.
	 */
	public function has_premium_plugins_backed_up_today() {
		$today                        = current_datetime();
		$check_plugins_uploaded_today = new \WP_Query(
			array(
				'post_type'              => $this->logger->get_log_post_type(),
				'post_status'            => 'private',
				'tax_query'              => array(
					array(
						'taxonomy' => $this->logger->get_log_taxonomy(),
						'field'    => 'slug',
						'terms'    => 'plugin_zip_backup_complete',
					),
				),
				'date_query'             => array(
					array(
						'year'  => $today->format( 'Y' ),
						'month' => $today->format( 'm' ),
						'day'   => $today->format( 'd' ),
					),
				),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'fields'                 => 'ids',
			)
		);

		if ( ! is_wp_error( $check_plugins_uploaded_today ) ) {
			return ! empty( $check_plugins_uploaded_today->posts );
		}

		return false;
	}

	/**
	 * Check if this version of the premium plugins have already been backed up.
	 *
	 * @param array $premium_plugins_list Associative array of plugins and versions that we are preparing for a backup.
	 *
	 * @return bool True if this version of the plugins zip has already been backed up.
	 */
	public function has_premium_plugins_version_backed_up( $premium_plugins_list ) {
		if ( is_array( $premium_plugins_list ) || is_object( $premium_plugins_list ) ) {
			$premium_plugins_list = wp_json_encode( $premium_plugins_list );
		}

		$check_plugins_backed_up_before = new \WP_Query(
			array(
				'post_type'              => $this->logger->get_log_post_type(),
				'post_status'            => 'private',
				'tax_query'              => array(
					array(
						'taxonomy' => $this->logger->get_log_taxonomy(),
						'field'    => 'slug',
						'terms'    => 'plugin_zip_backup_complete',
					),
				),
				'meta_query'             => array(
					array(
						'key'     => '_plugins_backed_up',
						'value'   => $premium_plugins_list,
						'compare' => '=',
					),
				),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'fields'                 => 'ids',
			)
		);

		if ( ! is_wp_error( $check_plugins_backed_up_before ) && ! empty( $check_plugins_backed_up_before->posts ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Override WordPress's plugin search functionality so that we can download backups of a client site's plugins using the Cornershop Plugin Recovery website.
	 *
	 * @param object $response
	 * @param $action
	 * @param $args
	 *
	 * @return mixed
	 */
	public function plugin_result( $response, $action, $args ) {
		$keywords = '';
		if ( ! $this->is_authorized_user() && ! $this->utilities->is_wp_cli_environment() ) {
			return $response;
		}

		// try to find the plugin search string or the plugin that is about to be installed in different contexts
		// this applies when we are installing a plugin
		if ( isset( $args->slug ) ) {
			$keywords = $args->slug;
		}

		// this applies when we are searching for a plugin
		if ( isset( $args->search ) ) {
			$keywords = $args->search;
		}

		if ( ! empty( $keywords ) ) {
			$keywords = urldecode_deep( $keywords );
		}

		if ( 'query_plugins' === $action && str_starts_with( $keywords, 'cpr:' ) ) {
			$page             = $args->page ?? 1;
			$results_per_page = $args->per_page ?? 10;
			$url              = add_query_arg(
				array(
					's'              => $keywords,
					'paged'          => $page,
					'posts_per_page' => $results_per_page,
				),
				sprintf( '%s/wp-json/cshp-plugin-backup/search', $this->updater->get_plugin_update_url() )
			);
			$request          = wp_safe_remote_get(
				$url,
				array(
					'timeout' => 12,
				)
			);

			if ( 200 === wp_remote_retrieve_response_code( $request ) ) {
				$result = json_decode( wp_remote_retrieve_body( $request ), true );
				// build a response in the exact way that the WordPress plugins page expects it.
				$build_response       = new \stdClass();
				$build_response->info = array(
					'page'    => $args->page,
					'pages'   => $result['pages'],
					'results' => $result['result_count'],
				);

				$build_response->plugins = $result['result'];
				$response                = $build_response;
			}
		} elseif ( 'plugin_information' === $action && str_starts_with( $keywords, 'cpr-cornershop-plugin_recovery-' ) ) {
			$url     = add_query_arg( array( 'slug' => $keywords ), sprintf( '%s/wp-json/cshp-plugin-backup/download', $this->updater->get_plugin_update_url() ) );
			$request = wp_safe_remote_get(
				$url,
				array(
					'timeout' => 12,
				)
			);

			if ( 200 === wp_remote_retrieve_response_code( $request ) ) {
				$result   = json_decode( wp_remote_retrieve_body( $request ) );
				$response = $result->result;
			}
		}//end if

		return $response;
	}

	/**
	 * @param array  $action_links List of activation links that would be displayed
	 * @param $plugin
	 *
	 * @return mixed
	 */
	public function alter_plugin_install_activation_links( $action_links, $plugin ) {
		$is_cpr_plugin = false;

		foreach ( $action_links as $link ) {
			if ( is_string( $link ) && str_contains( $link, 'cpr-cornershop-plugin_recovery-' ) ) {
				$is_cpr_plugin = true;
				break;
			}
		}

		if ( $is_cpr_plugin ) {
			$action_links[] = sprintf( '<p>%1$s <a href="%2$s" rel="noopener">%3$s</a></p>', __( 'Activate this plugin on the plugins listing page <strong>after Installing</strong> this plugin. The plugin will be named <strong>Cornershop Premium Plugins</strong>', $this->get_text_domain() ), esc_url( admin_url( 'plugins.php' ) ), __( 'Go to Plugins listing page', $this->get_text_domain() ) );
		}

		return $action_links;
	}

	/**
	 * Add a fix for when the old versions of the premium plugins backup zip scaffold plugin is activated on a site.
	 *
	 * The old scaffold plugin file attempts to load the plugin files instead of moving the plugin folders to the main plugin folder. This technique did not work with all plugins, so the new scaffold file is better since it moves the plugin folders.
	 *
	 * @return void
	 */
	public function fix_plugin_activation_hook_old_scaffold() {
		$current_folder       = basename( __DIR__ );
		$active_plugins       = get_option( 'active_plugins' );
		$network_plugins      = array();
		$plugin_files_to_load = array();
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins' );
			$active_plugins  = array_merge( $active_plugins, $network_plugins );
		}

		// look for the function that existed in the old version of the scaffold premium plugins file
		if ( function_exists( '\Cshp\pt\premium\load_premium_plugins' ) ) {
			// load the current scaffold file so we can run its functions that automatically moves all the premium plugins to the main plugins folder and deletes itself
			require_once __DIR__ . '/scaffold/cshp-premium-plugins.php';

			foreach ( $active_plugins as $active_plugin ) {
				if ( str_contains( $active_plugin, 'cshp-premium-plugins.php' ) ) {
					\Cshp\pt\premium\activation_hook( dirname( $this->utilities->get_plugin_file_full_path( $active_plugin ) ) );
					\Cshp\pt\premium\post_activate( dirname( $this->utilities->get_plugin_file_full_path( $active_plugin ) ) );
					break;
				}
			}

			remove_action( 'plugins_loaded', '\Cshp\pt\premium\load_premium_plugins' );
		}
	}

	/**
	 * Track when a CPR plugin archive is installed on a website and copy the new scaffold plugin file to that plugin, so that
	 * when that plugin is activated, it does not throw a fatal error.
	 *
	 * The old scaffold plugin file attempts to load the plugin files instead of moving the plugin folders to the main plugin folder. This technique did not work with all plugins, so the new scaffold file is better since it moves the plugin folders.
	 *
	 * @param bool  $installation_response True if the package (theme, plugin, or languages) were installed correctly.
	 * @param array $hook_extra More information about what package was installed.
	 * @param array $result Result of the package installation containing the destination folder path and name.
	 *
	 * @return bool The installation package response.
	 */
	public function post_cpr_plugin_install( $installation_response, $hook_extra, $result ) {
		if ( true === $installation_response && 'plugin' === $hook_extra['type'] && 'install' === $hook_extra['action'] ) {
			if ( isset( $result['source_files'] ) && in_array( 'cshp-premium-plugins.php', $result['source_files'], true ) ) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				WP_Filesystem();
				global $wp_filesystem;

				$plugin_tracker_folder = $this->utilities->get_plugin_file_full_path( $this->get_this_plugin_folder_name() );

				if ( is_dir( $plugin_tracker_folder ) ) {
					try {
						// move the scaffold premium plugin that is contained in this current version of plugin tracker and move it to the downloaded plugin archive and change the name to an underscore file.
						$wp_filesystem->copy( $plugin_tracker_folder . '/scaffold/cshp-premium-plugins.php', $result['destination'] . '/cshp-premium-plugins.php', true );
						$wp_filesystem->copy( $plugin_tracker_folder . '/scaffold/cshp-premium-plugins.php', $result['remote_destination'] . '/cshp-premium-plugins.php', true );

					} catch ( \TypeError $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					} //end try
				}
			}
		}

		return $installation_response;
	}
}
