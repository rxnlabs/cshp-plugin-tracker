<?php
/**
 * Functions for displaying the admin settings page
 *
 * @package PluginTracker
 */

declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

// exit if not loading in WordPress context but don't exit if running our PHPUnit tests
if ( ! defined( 'ABSPATH' ) && ! defined( 'CSHP_PHPUNIT_TESTS_RUNNING' ) ) {
	exit;
}


/**
 * Class Admin
 *
 * Handles the settings and pages related to displaying the plugin in the admin panel.
 */
class Admin {
	use Share;

	/**
	 * Utility instance providing various helper methods and functionalities.
	 *
	 * @var Utility
	 */
	private $utilities;
	/**
	 * Logger instance used for logging application events and messages.
	 *
	 * @var Logger
	 */
	private $logger;
	/**
	 * Premium list of plugins and themes instance.
	 *
	 * @var Premium_List
	 */
	private $premium_list;
	/**
	 * Instance responsible for tracking and managing plugin activities or status.
	 *
	 * @var Plugin_Tracker
	 */
	private $plugin_tracker;

	/**
	 * Constructor method to initialize required dependencies.
	 *
	 * @param  Utilities    $utilities  Utility class instance for handling common operations.
	 * @param  Logger       $logger  Logger class instance for handling logging functionality.
	 * @param  Premium_List $premium_list  Premium list class instance to manage premium features.
	 *
	 * @return void
	 */
	public function __construct( Utilities $utilities, Logger $logger, Premium_List $premium_list ) {
		$this->utilities    = $utilities;
		$this->logger       = $logger;
		$this->premium_list = $premium_list;
	}

	/**
	 * Create a setter method to prevent circular logic with dependency injection since the Admin class uses methods of the Plugin_Tracker class but Plugin_Tracker depends on the admin class for DI.
	 *
	 * @param  Plugin_Tracker $plugin_tracker Instance of the Plugin_Tracker class so we can use methods of the class without having a hard, circular dependency.
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
	 * Register admin-related hooks and filters.
	 *
	 * @return void
	 */
	public function admin_hooks() {
		add_action( 'admin_menu', array( $this, 'add_options_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'add_settings_link' ) );
		add_action( 'admin_init', array( $this, 'register_options_admin_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ), 99 );
	}

	/**
	 * Add options page to manage the plugin settings
	 *
	 * @return void
	 */
	public function add_options_admin_menu() {
		if ( ! $this->is_authorized_user() ) {
			return;
		}

		add_options_page(
			__( 'Cornershop Plugin Tracker' ),
			__( 'Cornershop Plugin Tracker' ),
			'manage_options',
			'cshp-plugin-tracker',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Add a link to the plugin settings page on the plugins table view list
	 *
	 * @return void
	 */
	public function add_settings_link() {
		$filter_name = sprintf( 'plugin_action_links_%s/cshp-plugin-tracker.php', $this->get_this_plugin_folder_name() );

		if ( ! $this->is_authorized_user() ) {
			return;
		}

		add_filter(
			$filter_name,
			function ( $links ) {
				$new_links = array(
					'settings' =>
						sprintf(
							'<a title="%s" href="%s">%s</a>',
							esc_attr__( 'Cornershop Plugin Tracker settings page', $this->get_text_domain() ),
							esc_url( menu_page_url( 'cshp-plugin-tracker', false ) ),
							esc_html__( 'Settings', $this->get_text_domain() )
						),
				);

				return array_merge( $links, $new_links );
			},
			999
		);
	}

	/**
	 * Register settings for the plugin
	 *
	 * @return void
	 */
	public function register_options_admin_settings() {
		register_setting(
			'cshp_plugin_tracker',
			'cshp_plugin_tracker_token',
			function ( $input ) {
				$token = trim( sanitize_text_field( $input ) );
				if ( $token !== $this->get_stored_token() && ! empty( $token ) ) {
					$this->logger->log_request( 'token_delete' );
					$this->logger->log_request( 'token_create' );
				} elseif ( empty( $token ) ) {
					$this->logger->log_request( 'token_delete' );
				}
				return $token;
			}
		);

		register_setting(
			'cshp_plugin_tracker',
			'cshp_plugin_tracker_exclude_plugins',
			function ( $input ) {
				$clean_input = array();

				if ( ! empty( $input ) && is_array( $input ) ) {
					foreach ( $input as $plugin_folder ) {
						$test = sanitize_text_field( $plugin_folder );

						if ( $this->utilities->does_file_exists( $this->utilities->get_plugin_file_full_path( $test ) ) ) {
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
			function ( $input ) {
				$clean_input = array();

				if ( ! empty( $clean_input ) ) {
					foreach ( $input as $theme_folder ) {
						$test = sanitize_text_field( $theme_folder );

						if ( $this->utilities->does_file_exists( $this->utilities->get_plugin_file_full_path( $test ) ) ) {
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
			function ( $input ) {
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
			function ( $input ) {
				return trim( sanitize_text_field( $input ) );
			}
		);

		register_setting(
			'cshp_plugin_tracker',
			'cshp_plugin_tracker_debug_on',
			function ( $input ) {
				if ( ! empty( $input ) ) {
					$debug = true;
				} else {
					$debug = false;
				}

				return $debug;
			}
		);
	}

	/**
	 * Get the path to the premium theme download zip file.
	 *
	 * @return false|string|null Path of the zip file on the server or empty if no zip file.
	 */
	public function get_premium_theme_zip_file() {
		return get_option( 'cshp_plugin_tracker_theme_zip' );
	}

	/**
	 * Get the list of theme folders and theme versions that were saved to the last generated premium themes zip file.
	 *
	 * @return false|array|null Name of theme folders and versions that were saved to the last generated premium themes
	 * zip file.
	 */
	public function get_premium_theme_zip_file_contents() {
		return get_option( 'cshp_plugin_tracker_theme_zip_contents', array() );
	}

	/**
	 * Get the token that is used for downloading the missing plugins and themes.
	 *
	 * @return false|string|null Token or empty if no token.
	 */
	public function get_stored_token() {
		return get_option( 'cshp_plugin_tracker_token' );
	}

	/**
	 * Get the list of plugins that should be excluded when zipping the premium plugins
	 *
	 * @return false|array|null Array of plugins to exclude based on the plugin folder name.
	 */
	public function get_excluded_plugins() {
		$list = get_option( 'cshp_plugin_tracker_exclude_plugins', array() );
		// always exclude this plugin from being included in the list of plugins to zip.
		$list = array_merge( $list, $this->premium_list->exclude_plugins_list() );

		// allow custom code to filter the plugins that are excluded.
		$list = apply_filters( 'cshp_pt_exclude_plugins', $list );

		return $list;
	}

	/**
	 * Get the list of themes that should be excluded when zipping the premium themes
	 *
	 * @return false|array|null Array of themes to exclude based on the theme folder name.
	 */
	public function get_excluded_themes() {
		return get_option( 'cshp_plugin_tracker_exclude_themes', array() );
	}

	/**
	 * Get the key that is used to reference this website on the Cornershop Plugin Recovery website. This key is generated in the CPR website.
	 *
	 * @return false|string|null Site key that is used to reference this site on CPR.
	 */
	public function get_site_key() {
		return get_option( 'cshp_plugin_tracker_cpr_site_key', '' );
	}

	/**
	 * Determine if the composer.json file should update in real-time or if the file should update during daily cron job
	 *
	 * @return bool False if the file should update during cron job. True if the file should update in real-time. Default is false.
	 */
	public function should_real_time_update() {
		$option = get_option( 'cshp_plugin_tracker_live_change_tracking', 'yes' );
		if ( 'yes' === $option ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieve the debug mode setting for this plugin.
	 *
	 * @return bool True if debug mode is enabled, otherwise false.
	 */
	public function is_debug_mode_enabled() {
		$option = get_option( 'cshp_plugin_tracker_debug_on', 0 );
		if ( 1 === $option || '1' === $option ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the WP REST API URL for downloading the missing plugins zip file
	 *
	 * @return string URL for downloading missing plugin zip files using the WP REST API.
	 */
	public function get_api_active_plugin_downloads_endpoint() {
		return add_query_arg(
			array( 'token' => $this->get_stored_token() ),
			get_rest_url( null, '/cshp-plugin-tracker/plugin/download' )
		);
	}

	/**
	 * Get the Rewrite URL for downloading the missing plugins zip file (if the WP REST API is disabled)
	 *
	 * @return string URL for downloading missing plugin zip files using the rewrite url.
	 */
	public function get_rewrite_active_plugin_downloads_endpoint() {
		return add_query_arg(
			array( 'token' => $this->get_stored_token() ),
			home_url( '/cshp-plugin-tracker/plugin/download' )
		);
	}

	/**
	 * Get the WP REST API URL for downloading the missing themes zip file
	 *
	 * @return string URL for downloading missing themes zip files using the WP REST API.
	 */
	public function get_api_theme_downloads_endpoint() {
		return add_query_arg(
			array( 'token' => $this->get_stored_token() ),
			get_rest_url( null, '/cshp-plugin-tracker/theme/download' )
		);
	}

	/**
	 * Get the Rewrite URL for downloading the missing themes zip file (if the WP REST API is disabled)
	 *
	 * @return string URL for downloading missing themes zip files using the rewrite url.
	 */
	public function get_rewrite_theme_downloads_endpoint() {
		return add_query_arg(
			array( 'token' => $this->get_stored_token() ),
			home_url( '/cshp-plugin-tracker/theme/download' )
		);
	}

	/**
	 * Create a settings page for the plugin
	 *
	 * @return void
	 */
	public function admin_page() {
		if ( ! $this->is_authorized_user() ) {
			return;
		}

		$default_tab = null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : $default_tab;
		?>
		<div class="wrap cshp-plugin-tracker-wrap">
			<h1><?php esc_html_e( 'Cornershop Plugin Tracker' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="?page=cshp-plugin-tracker" class="nav-tab <?php echo ( null === $tab ? esc_attr( 'nav-tab-active' ) : '' ); ?>"><?php esc_html_e( 'Settings', $this->get_text_domain() ); ?></a>
				<a href="?page=cshp-plugin-tracker&tab=log" class="nav-tab <?php echo ( 'log' === $tab ? esc_attr( 'nav-tab-active' ) : '' ); ?>"><?php esc_html_e( 'Log', $this->get_text_domain() ); ?></a>
				<a href="?page=cshp-plugin-tracker&tab=documentation" class="nav-tab <?php echo ( 'documentation' === $tab ? esc_attr( 'nav-tab-active' ) : '' ); ?>"><?php esc_html_e( 'Documentation', $this->get_text_domain() ); ?></a>
			</nav>
			<div class="tab-content">
				<?php
				switch ( $tab ) {
					case 'log':
						$this->admin_page_log_tab();
						break;
					case 'documentation':
						$this->admin_page_wp_documentation();
						break;
					case 'settings':
					default:
						$this->admin_page_settings_tab();
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
	public function admin_page_settings_tab() {
		$plugin_tracker   = $this->get_plugin_tracker();
		$active_plugins   = $this->utilities->get_active_plugins();
		$active_themes    = $this->utilities->get_active_themes();
		$plugin_list      = '';
		$themes_list      = '';
		$excluded_plugins = $this->get_excluded_plugins();
		$excluded_themes  = $this->get_excluded_themes();
		sort( $active_plugins );
		sort( $active_themes );

		foreach ( $active_plugins as $plugin ) {
			$plugin_data        = get_plugin_data( $this->utilities->get_plugin_file_full_path( $plugin ), false, false );
			$plugin_folder_name = dirname( $plugin );

			if ( $plugin_folder_name === $this->get_this_plugin_folder_name() ) {
				continue;
			}

			// if the plugin has disabled updates, include it in the list of premium plugins
			// only try to exclude plugins that are not available on WordPress.org.
			if ( ! $plugin_tracker->is_premium_plugin( $plugin ) ) {
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
			$plugin_list = __( 'No premium plugins installed or detected. Plugins installed match the name and versions of plugins available on wordpress.org.', $this->get_text_domain() );
		}

		foreach ( $active_themes as $theme_folder_name ) {
			$theme = wp_get_theme( $theme_folder_name );

			// only try to exclude themes that are not available on WordPress.org.
			if ( $theme->exists() && $plugin_tracker->is_theme_available( $theme_folder_name, $theme->get( 'Version' ) ) ) {
				continue;
			}

			$themes_list .= sprintf(
				'<li>
            <input type="checkbox" name="cshp_plugin_tracker_exclude_themes[]" id="%1$s" value="%1$s" %2$s>
            <label for="%1$s">%3$s</label>
            </li>',
				esc_attr( $theme_folder_name ),
				checked( in_array( dirname( $theme_folder_name ), $excluded_themes, true ), true, false ),
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$theme->Name
			);
		}

		if ( empty( $themes_list ) ) {
			$themes_list = __( 'No premium themes installed or detected. Themes installed match the name and versions of themes available on wordpress.org.', $this->get_text_domain() );
		}
        // phpcs:disable
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'cshp_plugin_tracker' ); ?>
			<?php do_settings_sections( 'cshp_plugin_tracker' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row"><label for="cshp-plugin-file-generation"><?php esc_html_e( 'Generate plugin tracker file in real-time', $this->get_text_domain() ); ?></label></th>
					<td>
						<p><?php esc_html_e( 'By default, the composer.json file will be updated on plugin, theme, and WordPress core updates, as well as plugin and theme activations.', $this->get_text_domain() ); ?></p>
						<p><?php esc_html_e( 'Since the update happens in real time, this can slow down plugin and theme activations. Disable real-time updates if you notice a slowdown installing plugins or themes.', $this->get_text_domain() ); ?></p>
						<div>
							<input type="radio" name="cshp_plugin_tracker_live_change_tracking" id="cshp-yes-live-track" value="yes" <?php checked( $this->should_real_time_update() ); ?>>
							<label for="cshp-yes-live-track"><?php esc_html_e( 'Yes, update the file in real-time. (NOTE: file still needs to be manually committed after it updates)', $this->get_text_domain() ); ?></label>
						</div>
						<div>
							<input type="radio" name="cshp_plugin_tracker_live_change_tracking" value="no" id="cshp-no-live-track" <?php checked( ! $this->should_real_time_update() ); ?>>
							<label for="cshp-no-live-track"><?php esc_html_e( 'No, update the file during cron job. (NOTE: file still needs to be manually committed after it updates)', $this->get_text_domain() ); ?></label>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-cpr-site-key"><?php esc_html_e( 'Cornershop Plugin Recovery Site Key', $this->get_text_domain() ); ?></label></th>
					<td>
						<p><?php esc_html_e( 'This is the site key that is assigned to this website on Cornershop Plugin Recovery. This key is used to verify that a website is allowed to backup their premium plugins to CPR. This key is generated on the CPR website. Go to that website to generate a site key and add it to this field. NOTE: This key is not needed for the Cornershop Plugin Tracker to work and perform its main duties.', $this->get_text_domain() ); ?></p>
						<input type="text" name="cshp_plugin_tracker_cpr_site_key" id="cshp-cpr-site-key" class="regular-text" value="<?php echo esc_attr( $this->get_site_key() ); ?>">
						<button type="button" id="cshp-copy-cpr-site-key" data-copy="cshp_plugin_tracker_cpr_site_key" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-token"><?php esc_html_e( 'Access Token', $this->get_text_domain() ); ?></label></th>
					<td>
						<input readonly type="text" name="cshp_plugin_tracker_token" id="cshp-token" class="regular-text" value="<?php echo esc_attr( $this->get_stored_token() ); ?>">
						<button type="button" id="cshp-generate-key" class="button hide-if-no-js"><?php esc_html_e( 'Generate New Token', $this->get_text_domain() ); ?></button>
						<button type="button" id="cshp-delete-key" class="button hide-if-no-js"><?php esc_html_e( 'Delete Token', $this->get_text_domain() ); ?></button>
						<button type="button" id="cshp-copy-token" data-copy="cshp_plugin_tracker_token" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<p class="cshp-pt-warning">
							<?php esc_html_e( 'WARNING: Generating a new token will delete the old token. Any request using the old token will stop working, so be sure to update the token in any tools that are using the old token.', $this->get_text_domain() ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-active-plugin-api-endpoint"><?php esc_html_e( 'API Endpoint to download Active plugins that are not available on wordpress.org', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_api_endpoint" id="cshp-active-plugin-api-endpoint" class="large-text" value="<?php echo esc_attr( $this->get_api_active_plugin_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-api-endpoint" data-copy="cshp_plugin_tracker_api_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-rewrite-endpoint"><?php esc_html_e( 'Alternative endpoint to download Active plugins that are not available on wordpress.org (if WP REST API is disabled)', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_rewrite_endpoint" id="cshp-rewrite-endpoint" class="large-text" value="<?php echo esc_attr( $this->get_rewrite_active_plugin_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-rewrite-endpoint" data-copy="cshp_plugin_tracker_rewrite_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-active-plugin-theme-api-endpoint"><?php esc_html_e( 'API Endpoint to download Active theme that is not available on wordpress.org', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_theme_api_endpoint" id="cshp-active-plugin-theme-api-endpoint" class="large-text" value="<?php echo esc_attr( $this->get_api_theme_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-api-endpoint" data-copy="cshp_plugin_tracker_theme_api_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-rewrite-theme-endpoint"><?php esc_html_e( 'Alternative endpoint to download Active theme that is not available on wordpress.org (if WP REST API is disabled)', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" name="cshp_plugin_tracker_rewrite_theme_endpoint" id="cshp-rewrite-theme-endpoint" class="large-text" value="<?php echo esc_attr( $this->get_rewrite_theme_downloads_endpoint() ); ?>">
						<button type="button" id="cshp-copy-rewrite-endpoint" data-copy="cshp_plugin_tracker_rewrite_theme_endpoint" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-plugin-exclude"><?php esc_html_e( 'Exclude plugins from being added to the generated Zip file', $this->get_text_domain() ); ?></label></th>
					<td>
						<ul>
							<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $plugin_list;
							?>
						</ul>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-theme-exclude"><?php esc_html_e( 'Exclude themes from being added to the generated Zip file', $this->get_text_domain() ); ?></label></th>
					<td>
						<ul>
							<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $themes_list;
							?>
						</ul>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-wp-cli-plugin-install-command"><?php esc_html_e( 'WP CLI command to install wordpress.org plugins', $this->get_text_domain() ); ?></label></th>
					<td>
						<textarea id="cshp-wp-cli-plugin-install-command" disabled class="large-text"><?php echo $plugin_tracker->generate_plugins_wp_cli_install_command( $plugin_tracker->generate_composer_installed_plugins(), 'raw' ); ?></textarea>
						<button type="button" id="cshp-wp-cli-plugins-command" data-copy="cshp-wp-cli-plugin-install-command" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-wp-cli-premium-plugins-download"><?php esc_html_e( 'WP CLI command to install premium plugins', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" id="cshp-wp-cli-premium-plugins-download" class="large-text" value="<?php echo esc_attr( $plugin_tracker->generate_premium_plugins_wp_cli_install_command( 'raw' ) ); ?>"/>
						<button type="button" id="cshp-tracker-wp-cli-premium-plugins-download" data-copy="cshp-wp-cli-premium-plugins-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-wp-cli-theme-install-command"><?php esc_html_e( 'WP CLI command to install wordpress.org themes', $this->get_text_domain() ); ?></label></th>
					<td>
						<textarea id="cshp-wp-cli-theme-install-command" disabled class="large-text"><?php echo $plugin_tracker->generate_themes_wp_cli_install_command( $plugin_tracker->generate_composer_installed_themes(), 'raw' ); ?></textarea>
						<button type="button" id="cshp-wp-cli-themes-command" data-copy="cshp-wp-cli-plugin-install-command" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-wp-cli-premium-themes-download"><?php esc_html_e( 'WP CLI command to install premium themes', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" id="cshp-wp-cli-premium-themes-download" class="large-text" value="<?php echo esc_attr( $plugin_tracker->generate_premium_themes_wp_cli_install_command( 'raw' ) ); ?>"/>
						<button type="button" id="cshp-tracker-wp-cli-premium-themes-download" data-copy="cshp-wp-cli-premium-themes-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-wget-premium-plugins-download"><?php esc_html_e( 'Wget command to download premium plugins', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" id="cshp-wget-premium-plugins-download" class="large-text" value="<?php echo esc_attr( $plugin_tracker->generate_wget_plugins_download_command( 'raw' ) ); ?>"/>
						<button type="button" id="cshp-tracker-wget-premium-plugins-download" data-copy="cshp-wget-premium-plugins-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-wget-premium-themes-download"><?php esc_html_e( 'Wget command to download premium themes', $this->get_text_domain() ); ?></label></th>
					<td>
						<input disabled type="text" id="cshp-wget-premium-themes-download" class="large-text" value="<?php echo esc_attr( $plugin_tracker->generate_wget_themes_download_command( 'raw' ) ); ?>"/>
						<button type="button" id="cshp-tracker-wget-premium-themes-download" data-copy="cshp-wget-premium-themes-download" class="button hide-if-no-js copy-button"><?php esc_html_e( 'Copy', $this->get_text_domain() ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cshp-debug-settings"><?php esc_html_e( 'Debug settings. Show the debug post types to help troubleshoot issues.', $this->get_text_domain() ); ?></label></th>
					<td>
						<input type="checkbox" id="cshp-debug-settings" name="cshp_plugin_tracker_debug_on" value="1" <?php checked( $this->is_debug_mode_enabled(), true ); ?> />
					</td>
				</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Save Settings', $this->get_text_domain() ) ); ?>
		</form>
		<?php
        // phpcs:enable
	}

	/**
	 * Output the log page.
	 *
	 * @return void
	 */
	public function admin_page_log_tab() {
		$table_html = sprintf( '<tr><td colspan="4">%s</td></tr>', __( 'No entries found', $this->get_text_domain() ) );
		$query      = new \WP_Query(
			array(
				'post_type'      => $this->logger->get_log_post_type(),
                // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'posts_per_page' => 200,
				'post_status'    => 'private',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
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
		// phpcs:disable
		?>
		<table class="table wp-list-table widefat" id="cshpt-log">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Title', $this->get_text_domain() ); ?></th>
				<th><?php esc_html_e( 'Content', $this->get_text_domain() ); ?></th>
				<th data-type="date" data-format="MM/DD/YYYY"><?php esc_html_e( 'Date', $this->get_text_domain() ); ?></th>
				<th><?php esc_html_e( 'Author', $this->get_text_domain() ); ?></th>
				<th><?php esc_html_e( 'URL', $this->get_text_domain() ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php echo $table_html; ?>
			</tbody>
		</table>
		<?php
        // phpcs:enable
	}

	/**
	 * Admin page to show WP site data that PMs add to the Standard WP documentation sheet handed to clients after site builds.
	 *
	 * @return void
	 */
	public function admin_page_wp_documentation() {
		?>

		<h2><?php esc_html_e( 'Navigation Menus', $this->get_text_domain() ); ?></h2>
		<?php
		    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->generate_menus_list_documentation();
		?>
		<h2><?php esc_html_e( 'Gravity Forms', $this->get_text_domain() ); ?></h2>
		<?php
		    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->generate_gravityforms_active_documentation();
		?>
		<h2><?php esc_html_e( 'Active Plugins', $this->get_text_domain() ); ?></h2>
		<?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->generate_plugins_active_documentation();
		?>

		<?php
	}

	/**
	 * Add an admin notice on the plugin page if external requests to wordpress.org are blocked
	 *
	 * @return void
	 */
	public function admin_notice() {
		$allowed_screen_ids = array( 'settings_page_cshp-plugin-tracker', 'options-generalphppagecshp-plugin-tracker' );

		if ( ! empty( get_current_screen() ) && in_array( get_current_screen()->id, $allowed_screen_ids, true ) && $this->utilities->is_wordpress_org_external_request_blocked() && $this->is_authorized_user() ) {
			printf( '<div class="notice notice-error is-dismissible cshp-pt-notice"><p>%s</p></div>', esc_html__( 'External requests to wordpress.org are being blocked. When generating the plugin tracker file, all themes and plugins will be considered premium. Unblock requests to wordpress.org to fix this. Update the PHP constant "WP_ACCESSIBLE_HOSTS" to include exception for *.wordpress.org', $this->get_text_domain() ) );
		}
	}

	/**
	 * Enqueue styles and scripts for the plugin
	 *
	 * @return void
	 */
	public function admin_enqueue() {
		$screen_ids = array( 'settings_page_cshp-plugin-tracker', 'plugin-install', 'options-generalphppagecshp-plugin-tracker' );
		if ( ! empty( get_current_screen() ) && in_array( get_current_screen()->id, $screen_ids, true ) ) {
			wp_enqueue_script( 'simple-datatables', $this->utilities->get_plugin_file_uri( '/build/vendor/simple-datatables/js/simple-datatables.min.js' ), array(), '7.1.2', true );
			wp_enqueue_style( 'simple-datatables', $this->utilities->get_plugin_file_uri( '/build/vendor/simple-datatables/css/simple-datatables.min.css' ), array(), '7.1.2', true );
			wp_enqueue_script( 'cshp-plugin-tracker', $this->utilities->get_plugin_file_uri( '/build/js/admin.js' ), array( 'simple-datatables', 'wp-api-fetch' ), $this->get_version(), true );
			wp_enqueue_style( 'cshp-plugin-tracker', $this->utilities->get_plugin_file_uri( '/build/css/admin.css' ), array(), $this->get_version() );

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$tab = isset( $_GET['tab'] ) ? esc_attr( $_GET['tab'] ) : '';

			wp_localize_script(
				'cshp-plugin-tracker',
				'cshp_pt',
				array(
					'tab' => $tab,
				)
			);
		}
	}

	/**
	 * Generate a list of active Menus so that PMs can add this to the WP documentation doc
	 *
	 * @return string List of menus for the site.
	 */
	public function generate_menus_list_documentation() {
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
	 * Generate a list of active Gravity Forms so that PMs can add this to the WP documentation doc
	 *
	 * @return string List of Gravity Forms on the site.
	 */
	public function generate_gravityforms_active_documentation() {
		if ( ! class_exists( '\GFAPI' ) || ! is_callable( array( '\GFAPI', 'get_forms' ) ) ) {
			return __( 'The Gravity Forms plugin is not active or no active forms can be detected.', $this->get_text_domain() );
		}

		$forms = \GFAPI::get_forms( true, false, 'title' );
		$html  = '';
		foreach ( $forms as $form ) {
			$html .= sprintf( '<li>%s</li>', rgar( $form, 'title' ) );
		}

		return sprintf( '<ol>%s</ol>', $html );
	}

	/**
	 * Generating a table for showing the active plugins so that PMs can add this to the WP Documentation doc
	 *
	 * @return string HTML listing of active plugins.
	 */
	public function generate_plugins_active_documentation() {
		$active_plugins = $this->utilities->get_active_plugins();
		$sort_order     = array();

		$thead = sprintf(
			'<tr><th>%s</th><th>%s</th></tr>',
			__( 'Plugin', $this->get_text_domain() ),
			__( 'Notes', $this->get_text_domain() )
		);
		$tbody = '';

		foreach ( $active_plugins as $plugin ) {
			// exclude this plugin from the list.
			if ( __FILE__ === $this->utilities->get_plugin_file_full_path( $plugin ) ) {
				continue;
			}

			$data         = get_plugin_data( $this->utilities->get_plugin_file_full_path( $plugin ), false, true );
			$sort_order[] = array(
				'name'        => $data['Name'],
				'description' => $data['Description'],
			);
		}

		// sort plugin table by the plugin name.
		$key_values = array_column( $sort_order, 'name' );
		array_multisort( $key_values, SORT_ASC, $sort_order );

		foreach ( $sort_order as $order ) {
			$tbody .= sprintf( '<tr><td>%s</td><td>%s</td></tr>', wp_strip_all_tags( $order['name'] ), wp_strip_all_tags( $order['description'] ) );
		}

		return sprintf( '<table border="1" class="table wp-list-table widefat" id="cshp-active-plugins"><thead>%s</thead><tbody>%s</tbody></table>', $thead, $tbody );
	}
}
