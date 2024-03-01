<?php
/**
 * Functions for displaying the admin settings page
 */
namespace Cshp\pt;

/**
 * Add options page to manage the plugin settings
 *
 * @return void
 */
function add_options_admin_menu() {
	if ( ! is_cornershop_user() ) {
		return;
	}

	add_options_page(
		__( 'Cornershop Plugin Tracker' ),
		__( 'Cornershop Plugin Tracker' ),
		'manage_options',
		'cshp-plugin-tracker',
		__NAMESPACE__ . '\admin_page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\add_options_admin_menu' );

/**
 * Add a link to the plugin settings page on the plugins table view list
 *
 * @return void
 */
function add_settings_link() {
	$filter_name = sprintf( 'plugin_action_links_%s/cshp-plugin-tracker.php', get_this_plugin_folder() );

	if ( ! is_cornershop_user() ) {
		return;
	}

	add_filter(
		$filter_name,
		function ( $links ) {
			$new_links = [ 'settings' =>
               sprintf(
                   '<a title="%s" href="%s">%s</a>',
                   esc_attr__( 'Cornershop Plugin Tracker settings page', get_textdomain() ),
                   esc_url( menu_page_url( 'cshp-plugin-tracker', false ) ),
                   esc_html__( 'Settings', get_textdomain() )
               ),
			];

			return array_merge( $links, $new_links );
		},
		999
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

            if ( ! empty( $input ) && is_array( $input ) ) {
	            foreach ( $input as $plugin_folder ) {
		            $test = sanitize_text_field( $plugin_folder );

		            if ( does_zip_exists( get_plugin_file_full_path( $test ) ) ) {
			            $clean_input[] = $test;
		            }
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

            if ( ! empty( $clean_input ) ) {
	            foreach ( $input as $theme_folder ) {
		            $test = sanitize_text_field( $theme_folder );

		            if ( does_zip_exists( get_plugin_file_full_path( $test ) ) ) {
			            $clean_input[] = $test;
		            }
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

	register_setting(
		'cshp_plugin_tracker',
		'cshp_plugin_tracker_cpr_site_key',
		function( $input ) {
			return trim( sanitize_text_field( $input ) );
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
	if ( ! is_cornershop_user() ) {
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
                <th scope="row"><label for="cshp-cpr-site-key"><?php esc_html_e( 'Cornershop Plugin Recovery Site Key', get_textdomain() ); ?></label></th>
                <td>
                    <p><?php esc_html_e( 'This is the site key that is assigned to this website on Cornershop Plugin Recovery. This key is used to verify that a website is allowed to backup their premium plugins to CPR. This key is generated on the CPR website. Go to that website to generate a site key and add it to this field. NOTE: This key is not needed for the Cornershop Plugin Tracker to work and perform its main duties.', get_textdomain() ); ?></p>
                    <input type="text" name="cshp_plugin_tracker_cpr_site_key" id="cshp-cpr-site-key" class="regular-text" value="<?php echo esc_attr( get_site_key() ); ?>">
                    <button type="button" id="cshp-copy-cpr-site-key" data-copy="cshp_plugin_tracker_cpr_site_key" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
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
			<tr>
				<th scope="row"><label for="cshp-wp-cli-plugin-install-command"><?php esc_html_e( 'WP CLI command to install wordpress.org plugins', get_textdomain() ); ?></label></th>
				<td>
					<textarea id="cshp-wp-cli-plugin-install-command" disabled class="regular-text"><?php echo generate_plugins_wp_cli_install_command( generate_composer_installed_plugins(), 'raw' ); ?></textarea>
					<button type="button" id="cshp-wp-cli-plugins-command" data-copy="cshp-wp-cli-plugin-install-command" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cshp-wp-cli-premium-plugins-download"><?php esc_html_e( 'WP CLI command to install premium plugins', get_textdomain() ); ?></label></th>
				<td>
					<input disabled type="text" id="cshp-wp-cli-premium-plugins-download" class="large-text" value="<?php echo esc_attr( generate_premium_plugins_wp_cli_install_command( 'raw' ) ); ?>"/>
					<button type="button" id="cshp-tracker-wp-cli-premium-plugins-download" data-copy="cshp-wp-cli-premium-plugins-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cshp-wp-cli-theme-install-command"><?php esc_html_e( 'WP CLI command to install wordpress.org themes', get_textdomain() ); ?></label></th>
				<td>
					<textarea id="cshp-wp-cli-theme-install-command" disabled class="regular-text"><?php echo generate_themes_wp_cli_install_command( generate_composer_installed_themes(), 'raw' ); ?></textarea>
					<button type="button" id="cshp-wp-cli-themes-command" data-copy="cshp-wp-cli-plugin-install-command" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cshp-wp-cli-premium-themes-download"><?php esc_html_e( 'WP CLI command to install premium themes', get_textdomain() ); ?></label></th>
				<td>
					<input disabled type="text" id="cshp-wp-cli-premium-themes-download" class="large-text" value="<?php echo esc_attr( generate_premium_themes_wp_cli_install_command( 'raw' ) ); ?>"/>
					<button type="button" id="cshp-tracker-wp-cli-premium-themes-download" data-copy="cshp-wp-cli-premium-themes-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cshp-wget-premium-plugins-download"><?php esc_html_e( 'Wget command to download premium plugins', get_textdomain() ); ?></label></th>
				<td>
					<input disabled type="text" id="cshp-wget-premium-plugins-download" class="large-text" value="<?php echo esc_attr( generate_wget_plugins_download_command( 'raw' ) ); ?>"/>
					<button type="button" id="cshp-tracker-wget-premium-plugins-download" data-copy="cshp-wget-premium-plugins-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cshp-wget-premium-themes-download"><?php esc_html_e( 'Wget command to download premium themes', get_textdomain() ); ?></label></th>
				<td>
					<input disabled type="text" id="cshp-wget-premium-themes-download" class="large-text" value="<?php echo esc_attr( generate_wget_themes_download_command( 'raw' ) ); ?>"/>
					<button type="button" id="cshp-tracker-wget-premium-themes-download" data-copy="cshp-wget-premium-themes-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', get_textdomain() ); ?></button>
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
	     is_cornershop_user() ) {
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
	$screen_ids = [ 'settings_page_cshp-plugin-tracker', 'plugin-install' ];
	if ( ! empty( get_current_screen() ) && in_array( get_current_screen()->id, $screen_ids, true ) ) {
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
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\admin_enqueue', 99 );