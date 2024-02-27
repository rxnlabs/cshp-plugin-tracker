<?php
/**
 * Features related to backing up the premium plugins to the Cornershop Plugins Recovery website and reinstalling the backups to a staging website.
 */
namespace Cshp\pt;

/**
 * Backup the zip of the premium plugins during a cron job.
 *
 * @return void
 */
function backup_premium_plugins_cron() {
	backup_premium_plugins();
}
add_action( 'cshp_pt_regenerate_composer_daily', __NAMESPACE__ . '\backup_premium_plugins_cron' );

/**
 * Back up the premium plugins zip to a dedicated backup retrieval site.
 *
 * Useful for retrieving the old versions of premium plugins that are on sites if these plugins are not in Git
 * and the plugin sites don't let you download old versions (e.g. Gravity Forms)
 *
 * @return bool|string True if the premium zip file was backed up successfully. Error message if the zip could not be backed up.
 */
function backup_premium_plugins() {
	$return_message                    = '';
	$plugin_content_compare            = [];
	$generated_zip_file_during_command = false;
	$error_zip_file                    = false;
	$backup_url                        = sprintf( '%s/wp-json/cshp-plugin-backup/backup', get_plugin_update_url() );
	$plugin_content                    = generate_composer_installed_plugins();
	$excluded_plugins                  = get_excluded_plugins();

	if ( ! function_exists( '\curl_init' ) || is_development_mode() ) {

		$return_message = esc_html__( 'Can only backup plugins on sites with: cURL installed, in production mode, and not in a known development environment.', get_textdomain() );

		// flag if the site is in production mode but the Kinsta dev environment constant is set
		if ( defined( 'KINSTA_DEV_ENV' ) &&
		     true === KINSTA_DEV_ENV &&
		     'production' === wp_get_environment_type() ) {
			$return_message = esc_html__( 'Error backing up plugin zip: Website is in production mode but the KINSTA_DEV_ENV flag is on. Turn off the KINSTA_DEV_ENV flag.', get_textdomain() );
			log_request( 'plugin_zip_backup_error', $return_message );
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
		if ( $plugin_folder_name === get_this_plugin_folder() || in_array( $plugin_folder_name, $excluded_plugins, true ) ) {
			continue;
		}

		$plugin_content_compare[ $plugin_folder_name ] = $version;
	}

	ksort( $plugin_content_compare );

	if ( is_external_domain_blocked( $backup_url ) ) {
		$return_message = esc_html__( 'Plugin zip backup site is blocked by WP_HTTP_BLOCK_EXTERNAL', get_textdomain() );
		log_request( 'plugin_zip_backup_error', $return_message );
		return $return_message;
	}

	// If this combination of premium plugins have already been backed up before,
	if ( has_premium_plugins_version_backed_up( $plugin_content_compare ) ) {
		$return_message = esc_html__( 'This version of the premium plugins have already been backed up. You can only backup a unique combination of plugins.', get_textdomain() );
		log_request( 'plugin_zip_backup_error', $return_message );
		return $return_message;
	}

	// If the premium plugins have already been backed up today, don't try to back up again.
	if ( has_premium_plugins_backed_up_today() ) {
		$return_message = esc_html__( 'Premium plugins already backed up today. Can only back up once a day.', get_textdomain() );
		log_request( 'plugin_zip_backup_error', $return_message );
		return $return_message;
	}

	if ( maybe_update_tracker_file() || ! does_zip_exists( get_premium_plugin_zip_file() ) ) {
		$result = zip_premium_plugins_include();

		if ( ! does_zip_exists( $result ) ) {
			$error_zip_file = true;
			$return_message = esc_html__( 'Could not create a backup due to  error zipping premium plugins. Check plugin log.', get_textdomain() );
			log_request( 'plugin_zip_backup_error', $return_message );
		}
	}

	if ( ! $error_zip_file && ! empty( get_premium_plugin_zip_file() ) ) {
		$premium_plugin_zip_file = get_premium_plugin_zip_file();
		$archive_zip_file_name = basename( $premium_plugin_zip_file );
		$plugin_content       = wp_json_encode( get_premium_plugin_zip_file_contents( $archive_zip_file_name ) );
		$home_url             = home_url( '/' );
		$domain               = wp_parse_url( $home_url );
		$zip_file_upload_name = sprintf( '%s.zip', sanitize_title( sprintf( '%s/%s', $domain['host'], $domain['path'] ) ) );
		$curl_file            = new \CurlFile( $premium_plugin_zip_file );
		$curl_file->setPostFilename( $zip_file_upload_name );
		$form_fields = [
			'domain'                  => $home_url,
			'premium_plugin_zip_file' => $curl_file,
			'plugins'                 => $plugin_content,
		];

		// Use an anonymous function and pass the local variable that we want to post to that function since
		// the http_api_curl hook does not pass the data that we actually want to POST with the hook
		$file_upload_request = function( $handle_or_parameters, $request = '', $url = '' ) use ( $form_fields ) {
			update_wp_http_request( $handle_or_parameters, $form_fields );
		};
		// handle cURL requests if we have cURL installed
		add_action( 'http_api_curl', $file_upload_request, 10 );
		// handle fsockopen requests if we don't have cURL installed
		add_action( 'requests-fsockopen.before_send', $file_upload_request, 10, 3 );

		$request = wp_remote_post(
			$backup_url,
			[
				'body'    => $form_fields,
				'headers' => [ 'content-type' => 'multipart/form-data' ],
				'timeout' => 12,
			]
		);

		if ( 200 === wp_remote_retrieve_response_code( $request ) ||
		     201 === wp_remote_retrieve_response_code( $request ) ||
		     202 === wp_remote_retrieve_response_code( $request ) ) {
			$return_message = sprintf( esc_html__( 'Successfully backed up plugin zip %s', get_textdomain() ), $archive_zip_file_name );
			$log_post       = log_request( 'plugin_zip_backup_complete', $return_message );

			if ( ! empty( $log_post ) && ! is_wp_error( $log_post ) ) {
				update_post_meta( $log_post, '_plugins_backed_up', $plugin_content );
			}
		} else {
			$result = wp_remote_retrieve_body( $request );
			// If we received ane empty error message from the server, then we may be blocked
			if ( empty( $result ) ) {
				$result = __( 'Empty error message returned from back up site. This site may be blocked by the backup site\'s server.', get_textdomain() );
			}

			$return_message = sprintf( esc_html__( 'Error backing up plugin zip %1$s: %2$s', get_textdomain() ), basename( get_premium_plugin_zip_file() ), $result );
			log_request( 'plugin_zip_backup_error', $return_message );
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
function update_wp_http_request( &$handle_or_parameters, $form_body_arguments = '', $url = '' ) {
	if ( function_exists( '\curl_init' ) && function_exists( '\curl_exec' ) ) {
		foreach ( $form_body_arguments as $value ) {
			// Only do this if we are using PHP 5.5+ CURLFile file to upload a file
			if ( 'object' === gettype( $value ) && $value instanceof \CURLFile ) {
				/*
				Use the request body as an array to force cURL make a requests using 'multipart/form-data'
				as the Content-type header instead of WP's default habit of converting the request to
				a string using http_build_query function
				*/
				curl_setopt( $handle_or_parameters, CURLOPT_POSTFIELDS, $form_body_arguments );
				break;
			}
		}
	} elseif ( function_exists( '\fsockopen' ) ) {
		// UNTESTED SINCE I HAVE cURL INSTALLED AND CANNOT TEST THIS
		$form_fields = [];
		$form_files  = [];
		foreach ( $form_body_arguments as $name => $value ) {
			if ( file_exists( $value ) ) {
				// Not great for large files since it dumps into memory but works well for small files
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
function has_premium_plugins_backed_up_today() {
	$today                        = current_datetime();
	$check_plugins_uploaded_today = new \WP_Query(
		[
			'post_type'              => get_log_post_type(),
			'post_status'            => 'private',
			'tax_query'              => [
				[
					'taxonomy' => get_log_taxonomy(),
					'field'    => 'slug',
					'terms'    => 'plugin_zip_backup_complete',
				],
			],
			'date_query'             => [
				[
					'year'  => $today->format( 'Y' ),
					'month' => $today->format( 'm' ),
					'day'   => $today->format( 'd' ),
				],
			],
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'fields'                 => 'ids',
		]
	);

	wp_reset_query();

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
function has_premium_plugins_version_backed_up( $premium_plugins_list ) {
	if ( is_array( $premium_plugins_list ) || is_object( $premium_plugins_list ) ) {
		$premium_plugins_list = wp_json_encode( $premium_plugins_list );
	}

	$check_plugins_backed_up_before = new \WP_Query(
		[
			'post_type'              => get_log_post_type(),
			'post_status'            => 'private',
			'tax_query'              => [
				[
					'taxonomy' => get_log_taxonomy(),
					'field'    => 'slug',
					'terms'    => 'plugin_zip_backup_complete',
				],
			],
			'meta_query'             => [
				[
					'key'     => '_plugins_backed_up',
					'value'   => $premium_plugins_list,
					'compare' => '=',
				],
			],
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'fields'                 => 'ids',
		]
	);

	wp_reset_query();

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
function plugin_result( $response, $action, $args ) {
	$keywords = '';
	if ( ! is_cornershop_user() && ! is_wp_cli_environment() ) {
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
		$page = $args->page ?? 1;
		$results_per_page = $args->per_page ?? 10;
		$url = add_query_arg( [ 's' => $keywords, 'paged' => $page, 'posts_per_page' => $results_per_page ], sprintf( '%s/wp-json/cshp-plugin-backup/search', get_plugin_update_url() ) );
		$request = wp_safe_remote_get( $url, [
				'timeout' => 12,
			]
		);

		//var_dump_error_log(wp_remote_retrieve_body( $request ));
		if ( 200 === wp_remote_retrieve_response_code( $request ) ) {
			$result = json_decode( wp_remote_retrieve_body( $request ), true );
			// build a response in the exact way that the WordPress plugins page expects it
			$build_response = new \stdClass();
			$build_response->info = [
				'page' => $args->page,
				'pages' => $result['pages'],
				'results' => $result['result_count'],
			];

			$build_response->plugins = $result['result'];
			$response = $build_response;
		}
	} elseif ( 'plugin_information' === $action && str_starts_with( $keywords, 'cpr-cornershop-plugin_recovery-' ) ) {
		$url = add_query_arg( [ 'slug' => $keywords ], sprintf( '%s/wp-json/cshp-plugin-backup/download', get_plugin_update_url() ) );
		$request = wp_safe_remote_get( $url, [
				'timeout' => 12,
			]
		);

		if ( 200 === wp_remote_retrieve_response_code( $request ) ) {
			$result = json_decode( wp_remote_retrieve_body( $request ) );
			$response = $result->result;
		}
	}

	return $response;
}
add_filter( 'plugins_api', __NAMESPACE__ . '\plugin_result', 99, 3 );

/**
 * @param array $action_links List of activation links that would be displayed
 * @param $plugin
 *
 * @return mixed
 */
function alter_plugin_install_activation_links( $action_links, $plugin ) {
	$is_cpr_plugin = false;

	foreach ( $action_links as $link ) {
		if ( is_string( $link ) && str_contains( $link, 'cpr-cornershop-plugin_recovery-' ) ) {
			$is_cpr_plugin = true;
			break;
		}
	}

	if ( $is_cpr_plugin ) {
		$action_links[] = sprintf( '<p>%1$s <a href="%2$s" rel="noopener">%3$s</a></p>', __( 'Activate this plugin on the plugins listing page <strong>after Installing</strong> this plugin. Due to how plugin installs work, you cannot activate this plugin on this screen. The plugin will be named <strong>Cornershop Premium Plugins</strong>', get_textdomain() ), esc_url( admin_url('plugins.php' ) ), __( 'Go to Plugins listing page', get_textdomain() ) );
	}

	return $action_links;
}
add_filter( 'plugin_install_action_links', __NAMESPACE__ . '\alter_plugin_install_activation_links', 99, 2 );

/**
 * Add a fix for when the old versions of the premium plugins backup zip scaffold plugin is activated on a site.
 *
 * The old scaffold plugin file attempts to load the plugin files instead of moving the plugin folders to the main plugin folder. This technique did not work with all plugins, so the new scaffold file is better since it moves the plugin folders.
 *
 * @return void
 */
function fix_plugin_activation_hook_old_scaffold() {
	$current_folder = basename( __DIR__ );
	$active_plugins = get_option( 'active_plugins' );
	$network_plugins = [];
	$plugin_files_to_load = [];
	if ( is_multisite() ) {
		$network_plugins = get_site_option( 'active_sitewide_plugins' );
		$active_plugins = array_merge( $active_plugins, $network_plugins );
	}

	// look for the function that existed in the old version of the scaffold premium plugins file
	if ( function_exists( '\Cshp\pt\premium\load_premium_plugins' ) ) {
		// load the current scaffold file so we can run its functions that automatically moves all the premium plugins to the main plugins folder and deletes itself
		require_once __DIR__ . '/scaffold/cshp-premium-plugins.php';

		foreach ( $active_plugins as $active_plugin ) {
			if ( str_contains( $active_plugin, 'cshp-premium-plugins.php' ) ) {
				\Cshp\pt\premium\activation_hook( dirname( get_plugin_file_full_path( $active_plugin ) ) );
				\Cshp\pt\premium\post_activate( dirname( get_plugin_file_full_path( $active_plugin ) ) );
				break;
			}
		}

		remove_action( 'plugins_loaded', '\Cshp\pt\premium\load_premium_plugins' );
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\fix_plugin_activation_hook_old_scaffold', 1 );

/**
 * Track when a CPR plugin archive is installed on a website and copy the new scaffold plugin file to that plugin, so that
 * when that plugin is activated, it does not throw a fatal error.
 *
 * The old scaffold plugin file attempts to load the plugin files instead of moving the plugin folders to the main plugin folder. This technique did not work with all plugins, so the new scaffold file is better since it moves the plugin folders.
 *
 * @param bool $installation_response True if the package (theme, plugin, or languages) were installed correctly.
 * @param array $hook_extra More information about what package was installed.
 * @param array $result Result of the package installation containing the destination folder path and name.
 *
 * @return bool The installation package response.
 */
function post_cpr_plugin_install( $installation_response, $hook_extra, $result ) {
	if ( true === $installation_response && 'plugin' === $hook_extra['type'] && 'install' === $hook_extra['action'] ) {
		if ( isset( $result['source_files'] ) && in_array( 'cshp-premium-plugins.php', $result['source_files'], true ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
			global $wp_filesystem;

			$plugin_tracker_folder = get_plugin_file_full_path( get_this_plugin_folder() );

			if ( is_dir( $plugin_tracker_folder ) ) {
				try {
					// move the scaffold premium plugin that is contained in this current version of plugin tracker and move it to the downloaded plugin archive and change the name to an underscore file.
					$wp_filesystem->copy( $plugin_tracker_folder . '/scaffold/cshp-premium-plugins.php', $result['destination'] . '/cshp-premium-plugins.php', true );
					$wp_filesystem->copy( $plugin_tracker_folder . '/scaffold/cshp-premium-plugins.php', $result['remote_destination'] . '/cshp-premium-plugins.php', true );

				}  catch ( \TypeError $error ) {} // if the plugin cannot be moved due to permissions or space or some other reason, catch it to avoid fatal error
			}
		}
	}

	return $installation_response;
}
add_filter( 'upgrader_post_install', __NAMESPACE__ . '\post_cpr_plugin_install', 1, 3 );
