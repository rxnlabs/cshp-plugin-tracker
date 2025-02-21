<?php
/**
 * WP CLI commands for the plugin
 */
declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

// exit if not loading in WordPress context but don't exit if running our PHPUnit tests
if ( ! defined( 'ABSPATH' ) && ! defined( 'CSHP_PHPUNIT_TESTS_RUNNING' ) ) {
	exit;
} elseif ( ! defined( '\WP_CLI' ) || ! \WP_CLI ) {
	return;
}

/**
 * Commands for the Cornershop Plugin Tracker plugin.
 */
class WP_CLI {
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
	 * Backup instance that handles backing up plugins to the Cornershop Plugin Recovery repo.
	 *
	 * @var Backup
	 */
	private $backup;

	/**
	 * Constructor method for initializing the class.
	 *
	 * @param Plugin_Tracker $plugin_tracker Instance of the Plugin_Tracker.
	 * @param Utilities $utilities Instance of the Utilities class.
	 * @param Backup $backup Instance of the Backup class.
	 *
	 * @return void
	 */
	public function __construct( Plugin_Tracker $plugin_tracker, Utilities $utilities, Backup $backup ) {
		$this->plugin_tracker = $plugin_tracker;
		$this->utilities      = $utilities;
		$this->backup         = $backup;
	}

	/**
	 * Registers the WP-CLI commands for this plugin.
	 *
	 * @return void
	 */
	public function commands() {
		\WP_CLI::add_command( 'cshp-pt generate', array( $this, 'command_generate' ) );
		\WP_CLI::add_command( 'cshp-pt plugin-zip', array( $this, 'command_plugin_zip' ) );
		\WP_CLI::add_command( 'cshp-pt theme-zip', array( $this, 'command_theme_zip' ) );
		\WP_CLI::add_command( 'cshp-pt plugin-install', array( $this, 'command_plugin_install' ) );
		\WP_CLI::add_command( 'cshp-pt theme-install', array( $this, 'command_theme_install' ) );
		\WP_CLI::add_command( 'cshp-pt backup-plugin', array( $this, 'backup_premium_plugins_zip' ) );
	}

	/**
	 * Register the hooks that hook into other WP-CLI commands so that we can call our plugin commands after those commands are invoked.
	 *
	 * @return void
	 */
	public function hooks() {
		\WP_CLI::add_hook( 'after_invoke:core update', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:core download', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:plugin install', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:plugin uninstall', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:plugin delete', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:plugin update', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:theme install', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:theme uninstall', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:theme delete', array( $this, 'wp_cli_post_update' ) );
		\WP_CLI::add_hook( 'after_invoke:theme update', array( $this, 'wp_cli_post_update' ) );
	}

	/**
	 * Update the installed plugins and themes tracker file after a plugin or theme is installed, updated, or deleted
	 * with WP CLI.
	 *
	 * @return void
	 */
	public function wp_cli_post_update() {
		\WP_CLI::runcommand( 'cshp-pt generate' );
	}

	/**
	 * Create the installed plugins and themes composer.json and README.md tracker files.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Whether to actually generate the plugin tracker file.
	 */
	public function command_generate( $args, $assoc_args ) {
		global $dry_run;
		$dry_run = false;

		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = true;
		}

		$file_save_location = sprintf( '%s/composer.json', $this->plugin_tracker->create_plugin_uploads_folder() );
		if ( false === $dry_run ) {
			$result            = $this->plugin_tracker->create_plugin_tracker_file();
			$read_file         = $this->plugin_tracker->read_tracker_file();
			$default_save_path = sprintf( '%s/cshp-plugin-tracker/composer.json', wp_upload_dir()['basedir'] );

			if ( ! empty( $read_file ) && empty( $result ) ) {
				\WP_CLI::success( sprintf( esc_html__( 'Successfully generated plugins and themes composer.json file at file path %s.', $this->plugin_tracker->get_text_domain() ), $file_save_location ) );
			} else {
				if ( file_exists( $default_save_path ) ) {
					\WP_CLI::log( sprintf( esc_html__( 'There is already a composer.json saved at %s. Please check the file permissions to make sure that the file can be has write access.', $this->plugin_tracker->get_text_domain() ), $default_save_path ) );
				}

				\WP_CLI::error( sprintf( esc_html__( 'Error generating plugins and themes composer.json file at location %1$s. %2$s', $this->plugin_tracker->get_text_domain() ), $file_save_location, $result ) );
			}
		} elseif ( true === $dry_run ) {
			\WP_CLI::success( sprintf( esc_html__( 'Plugins and themes composer.json file would be saved at file path %1$s. %2$s%3$s', $this->plugin_tracker->get_text_domain() ), $file_save_location, PHP_EOL, wp_json_encode( $this->plugin_tracker->generate_composer_array(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) );
		}
	}

	/**
	 * Create the zip of the installed premium plugins.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Whether to actually generate the plugin zip file.
	 */
	public function command_plugin_zip( $args, $assoc_args ) {
		global $dry_run;
		$dry_run = false;

		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = true;
		}

		\WP_CLI::log( esc_html__( 'Preparing to zip plugins...', $this->plugin_tracker->get_text_domain() ) );

		if ( false === $dry_run ) {
			$result = $this->plugin_tracker->zip_premium_plugins_include();

			if ( $this->plugin_tracker->get_premium_plugin_zip_file() === $result ) {
				\WP_CLI::success( sprintf( esc_html__( 'Successfully generated premium plugins .zip at file path %s.', $this->plugin_tracker->get_text_domain() ), $this->plugin_tracker->get_premium_plugin_zip_file() ) );
			} else {
				\WP_CLI::error( esc_html( $result ) );
			}
		} elseif ( true === $dry_run ) {
			\WP_CLI::log( esc_html__( 'You cannot dry run generating a premium plugins .zip file.', $this->plugin_tracker->get_text_domain() ) );

			if ( class_exists( '\ZipArchive' ) ) {
				\WP_CLI::log( esc_html__( 'The ZipArchive PHP extension is installed, so you hopefully should be able to generate the premium plugins .zip file without issues.', $this->plugin_tracker->get_text_domain() ) );
			} else {
				\WP_CLI::error( esc_html__( 'The ZipArchive PHP extension is not installed or cannot be detected, so you will not be able to generate a zip of the premium plugins using this plugin. You should be able to make an archive of the premium plugins using the Zip or Tar command line tool.', $this->plugin_tracker->get_text_domain() ) );
			}
		}
	}

	/**
	 * Create the zip of the installed premium themes.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Whether to actually generate the theme zip file.
	 */
	public function command_theme_zip( $args, $assoc_args ) {
		global $dry_run;
		$dry_run = false;

		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = true;
		}

		\WP_CLI::log( esc_html__( 'Preparing to zip themes...', $this->plugin_tracker->get_text_domain() ) );

		if ( false === $dry_run ) {
			$result = $this->plugin_tracker->zip_premium_themes();
			if ( $this->plugin_tracker->admin->get_premium_theme_zip_file() === $result ) {
				\WP_CLI::success( sprintf( esc_html__( 'Successfully generated premium themes .zip at file path %s.', $this->plugin_tracker->get_text_domain() ), $this->plugin_tracker->admin->get_premium_theme_zip_file() ) );
			} else {
				\WP_CLI::error( esc_html( $result ) );
			}
		} elseif ( true === $dry_run ) {
			\WP_CLI::log( esc_html__( 'You cannot dry run generating a premium themes .zip file.', $this->plugin_tracker->get_text_domain() ) );

			if ( class_exists( '\ZipArchive' ) ) {
				\WP_CLI::log( esc_html__( 'The ZipArchive PHP extension is installed, so you hopefully should be able to generate the premium themes .zip file without issues.', $this->plugin_tracker->get_text_domain() ) );
			} else {
				\WP_CLI::error( esc_html__( 'The ZipArchive PHP extension is not installed or cannot be detected, so you will not be able to generate a zip of the premium themes using this plugin. You should be able to make an archive of the premium themes using the Zip or Tar command line tool.', $this->plugin_tracker->get_text_domain() ) );
			}
		}
	}

	/**
	 * Install the wordpress.org plugins that are activated on this site or install the premium plugins from a separate site based on passing the URL to the premium plugins zip file.
	 *
	 * [<zip_path|premium_plugin_download_url>]
	 * : Path to the premium plugins zip file or URL to download the premium plugins zip file
	 *
	 * [<premium_plugin_folder_name>...]
	 * : One or more premium plugins to install separated by a space. Use if you want to install only some of the premium plugins but not all the premium plugins.
	 *
	 * ## OPTIONS
	 *
	 * [--bypass]
	 * : Is the IP address we are running this command from is whitelisted for Cornershop Plugin Recovery or are we using a site key. If valid, then download the current premium plugins from the source website without needing to generate a token on that source website first.
	 *
	 * [--not-exists]
	 * : Only download the premium plugins that are NOT on this website, regardless of the version. Useful if you don't really care about the version of a plugin that you are using on this website, as long plugin exist. More efficient than downloading all the premium plugins.
	 *
	 * [--diff]
	 * : Only download the premium plugins where the version that is on the live website is different than the version that is on current website. WARNING: this will overwrite the current plugins if a plugin on the current website is more up-to-date than the version on the live website. The version from the live website will overwrite the current version. More efficient than downloading all the premium plugins.
	 *
	 * [--no-overwrite]
	 * : Don't overwrite the current version of a premium plugin if the plugin is already installed. Useful if the version currently installed is newer than the version being downloaded.
	 *
	 * [--dry-run]
	 * : Whether to actually install the plugins.
	 */
	public function command_plugin_install( $args, $assoc_args ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		global $dry_run;
		$dry_run                   = false;
		$no_overwrite              = false;
		$premium_install_url       = false;
		$premium_install_url_parts = array();
		$is_valid_premium_url      = false;
		$specific_premium_plugins  = array();
		$global_params_string      = '';
		$global_params_array       = array();
		$passed_config_options     = \WP_CLI::get_config();
		$find_plugin_tracker_file  = $this->plugin_tracker->read_tracker_file();

		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = true;
		}

		if ( empty( $args[0] ) ) {
			$premium_install_url = $this->plugin_tracker->get_domain_from_site_key();
		}

		// check if we are installing the premium plugins by passing a website URL and that we are not installing straight from a .zip file
		if ( ! empty( $args[0] ) ) {
			$premium_install_url = $args[0];
		}

		if ( ! empty( $premium_install_url ) ) {
			$premium_install_url_parts = wp_parse_url( $premium_install_url );
		}

		foreach ( $args as $index => $addition_positional_arguments ) {
			if ( 0 === $index || empty( trim( $addition_positional_arguments ) ) ) {
				continue;
			}

			$specific_premium_plugins[] = $addition_positional_arguments;
		}

		// add the CPR query string to the website URL that will prompt it try to identify if we are whitelisted on CPR
		if ( ! empty( $premium_install_url_parts ) && ! empty( $premium_install_url_parts['scheme'] ) && ! empty( $premium_install_url_parts['host'] ) && ! str_ends_with( $premium_install_url_parts['path'] ?? '', '.zip' ) ) {
			$is_valid_premium_url = true;
			$query_strings        = array();

			if ( ! empty( $premium_install_url_parts['query'] ) ) {
				wp_parse_str( $premium_install_url_parts['query'], $query_strings );
			}

			if ( ! isset( $query_strings['token'] ) && ! isset( $query_strings['bypass'] ) ) {
				$premium_install_url = add_query_arg( array( 'cshp_pt_cpr' => true ), $premium_install_url );
			} elseif ( isset( $assoc_args['bypass'] ) ) {
				$bypass              = ! empty( $assoc_args['bypass'] ) ? $assoc_args['bypass'] : '';
				$premium_install_url = add_query_arg( array( 'cshp_pt_cpr' => $bypass ), $premium_install_url );
			}

			// if we have passed specific plugins that we want to install, pass those plugins as arguments to pass to the live site so it only zips the passed plugins
			$premium_install_url = add_query_arg( array( 'cshp_pt_plugins' => $specific_premium_plugins ), $premium_install_url );

			if ( isset( $assoc_args['diff'] ) && isset( $assoc_args['not-exists'] ) ) {
				\WP_CLI::error( __( 'Cannot pass both flags --diff and --not-exists. You can only do one.', $this->plugin_tracker->get_text_domain() ) );
			}

			if ( isset( $assoc_args['diff'] ) || isset( $assoc_args['not-exists'] ) ) {
				$plugins           = get_plugins();
				$installed_plugins = array();
				foreach ( $plugins as $plugin_file => $data ) {
					$version                             = isset( $data['Version'] ) && ! empty( $data['Version'] ) ? $data['Version'] : '*';
					$plugin_folder                       = wp_basename( $plugin_file );
					$installed_plugins[ $plugin_folder ] = $version;
				}

				$premium_install_url = add_query_arg( array( 'cshp_pt_plugins' => $installed_plugins ), $premium_install_url );

				if ( $assoc_args['diff'] ) {
					$premium_install_url = add_query_arg( array( 'cshp_pt_diff' => '' ), $premium_install_url );
				} elseif ( $assoc_args['not-exists'] ) {
					$premium_install_url = add_query_arg( array( 'cshp_pt_not_exists' => '' ), $premium_install_url );
				}
			}

			$premium_install_url = add_query_arg( array( 'cshp_pt_echo' => true ), $premium_install_url );
		}//end if

		if ( isset( $assoc_args['no-overwrite'] ) ) {
			$no_overwrite = true;
		}

		// grab the global parameters that were already passed to the "cshp-pt plugin install" command such as
		// --path=<path>, --url=<url> so we can pass those same parameters to the "plugin install" command
		if ( isset( $passed_config_options['path'] ) && ! empty( $passed_config_options['path'] ) ) {
			$global_params_array['path'] = $passed_config_options['path'];
			$global_params_string       .= sprintf( '--path="%s" ', $passed_config_options['path'] );
		}

		if ( isset( $passed_config_options['url'] ) && ! empty( $passed_config_options['url'] ) ) {
			$global_params_array['url'] = $passed_config_options['url'];
			$global_params_string      .= sprintf( '--url="%s" ', $passed_config_options['url'] );
		}

		if ( isset( $passed_config_options['http'] ) && ! empty( $passed_config_options['http'] ) ) {
			$global_params_array['http'] = $passed_config_options['http'];
			$global_params_string       .= sprintf( '--http="%s" ', $passed_config_options['http'] );
		}

		if ( isset( $passed_config_options['skip-packages'] ) && ! empty( $passed_config_options['skip-packages'] ) ) {
			$global_params_array['skip-packages'] = true;
			$global_params_string                .= '--skip-packages=true ';
		}

		$global_params_array['skip-plugins'] = 'cshp-plugin-tracker';
		$global_params_string               .= '--skip-plugins=cshp-plugin-tracker ';

		if ( $is_valid_premium_url && ! empty( $premium_install_url ) ) {
			$zip_premium_plugins_folder_path = '';
			$command                         = sprintf( 'plugin install %s', $premium_install_url );
			\WP_CLI::log( sprintf( esc_html__( 'Preparing to install the premium plugins from %s ...', $this->plugin_tracker->get_text_domain() ), $premium_install_url ) );
			$return = \WP_CLI::runcommand(
				$command,
				array_merge(
					$global_params_array,
					array(
						'return'     => true,
						'exit_error' => false,
					)
				)
			);

			if ( ! empty( $return ) && is_string( $return ) && false !== strpos( strtolower( $return ), 'plugin installed successfully' ) ) {
				// search for cshp-premium-plugin.php file in the plugin directory and skip the one found in this plugin folder
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $this->utilities->get_plugin_folders_path(), \RecursiveDirectoryIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::SELF_FIRST
				);
				$iterator->setMaxDepth( 1 );

				foreach ( $iterator as $info ) {
					if ( 'cshp-premium-plugins.php' === $info->getFilename() && false === strpos( $info->getRealPath(), $this->plugin_tracker->get_this_plugin_folder_name() ) ) {
						$zip_premium_plugins_folder_path = dirname( $info->getRealPath() );
						break;
					}
				}
			} else {
				\WP_CLI::error( sprintf( __( 'Could not download install plugins due to error: %s', $this->plugin_tracker->get_text_domain() ), $return ) );
			}

			$plugins_downloaded_list      = array();
			$premium_plugins_install_path = $zip_premium_plugins_folder_path;

			if ( ! is_dir( $premium_plugins_install_path ) ) {
				\WP_CLI::error( esc_html__( sprintf( 'Could not find the unzipped premium plugin folder in the WordPress plugins folder %s. The downloaded premium plugins would be in this folder. Please make sure that the plugins folder is readable and writeable.', $this->utilities->get_plugin_folders_path() ), $this->plugin_tracker->get_text_domain() ) );
				return;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $premium_plugins_install_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setMaxDepth( 0 );

			foreach ( $iterator as $file ) {
				if ( $file->isDir() ) {
					$new_plugin_folder_path = sprintf( '%s/%s', $this->utilities->get_plugin_folders_path(), basename( $file->getRealpath() ) );
					try {
						$premium_plugin_folder_name = basename( $file->getRealpath() );

						if ( is_dir( $new_plugin_folder_path ) && true === $no_overwrite ) {
							\WP_CLI::log( esc_html__( sprintf( 'The plugin %s is already installed. If you want to overwrite the currently installed version with the version from the premium plugins file, pass the --force flag to the command.', basename( $file->getRealpath() ) ), $this->plugin_tracker->get_text_domain() ) );
							$plugins_downloaded_list[] = basename( $premium_plugin_folder_name );
							continue;
						}

						$result = $wp_filesystem->move( $file->getRealpath(), $new_plugin_folder_path, true );

						if ( true === $result ) {
							$plugins_downloaded_list[] = basename( $premium_plugin_folder_name );
						} else {
							throw new \TypeError( __( 'Could not move plugin folder', $this->plugin_tracker->get_text_domain() ) );
						}
					} catch ( \TypeError $error ) {
						\WP_CLI::log( esc_html__( sprintf( 'Could not move the premium plugin folder %s to the WordPress plugins folder. Please make sure the plugins folder is readable and writeable. Your Filesystem may be in FTP mode.', basename( $file->getRealpath() ) ), $this->plugin_tracker->get_text_domain() ) );
					}
				}//end if
			}//end foreach

			try {
				$result = $wp_filesystem->delete( $premium_plugins_install_path, true, 'd' );
				if ( true !== $result ) {
					throw new \TypeError( __( 'Could not move plugin folder', $this->plugin_tracker->get_text_domain() ) );
				}
			} catch ( \TypeError $error ) {
				\WP_CLI::log( esc_html__( sprintf( 'Could not delete the premium plugin zip folder %s from the WordPress plugins folder. Please make sure the plugins folder is readable and writeable. Your Filesystem may be in FTP mode.', $premium_plugins_install_path ), $this->plugin_tracker->get_text_domain() ) );
			}

			// Bulk activate the newly installed premium plugins
			$command = sprintf( 'plugin activate %s', implode( ' ', $plugins_downloaded_list ) );
			\WP_CLI::runcommand( $command, array_merge( $global_params_array ) );

			\WP_CLI::success( esc_html__( sprintf( 'Successfully installed and activated the premium plugins %s', implode( ', ', $plugins_downloaded_list ) ), $this->plugin_tracker->get_text_domain() ) );
		}//end if

		// handle installing the wordpress.org plugins
		if ( ! empty( $find_plugin_tracker_file ) ) {
			if ( is_wp_error( $this->plugin_tracker->create_plugin_uploads_folder() ) ) {
				\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker uploads folder. Please make sure the file exists and check file permissions to make sure the uploads folder is readable and writeable.', $this->plugin_tracker->get_text_domain() ) );
				return;
			}

			if ( empty( $find_plugin_tracker_file ) ) {
				\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker composer.json file. Please make sure the file exists in the uploads folder and check file permissions to make sure the uploads folder is readable and writeable.', $this->plugin_tracker->get_text_domain() ) );
				return;
			}

			$plugin_install_command = $this->plugin_tracker->generate_plugins_wp_cli_install_command( $find_plugin_tracker_file, 'command' );

			if ( is_array( $plugin_install_command ) && ! empty( $plugin_install_command ) ) {
				if ( false === $dry_run ) {
					foreach ( $plugin_install_command as $run_command ) {
						// remove the first instance of "wp " from the command so that we don't have WP_CLI try to call itself
						$run_command                              = substr_replace( $run_command, '', 0, strlen( 'wp ' ) );
						$command_with_passed_global_config_params = sprintf( '%s %s', $run_command, $global_params_string );
						\WP_CLI::log( sprintf( esc_html__( 'Running the command wp %s', $this->plugin_tracker->get_text_domain() ), $command_with_passed_global_config_params ) );
						\WP_CLI::runcommand( $command_with_passed_global_config_params );
					}//end foreach
				} elseif ( true === $dry_run ) {
					$plugin_install_command = $this->plugin_tracker->generate_plugins_wp_cli_install_command( $this->plugin_tracker->generate_composer_installed_plugins(), 'raw' );

					\WP_CLI::log( sprintf( esc_html__( 'wordpress.org plugins would be installed by running the command(s): %s', $this->plugin_tracker->get_text_domain() ), $plugin_install_command ) );
				}//end if
			} else {
				\WP_CLI::log( esc_html__( 'No wordpress.org plugins were found on this website', $this->plugin_tracker->get_text_domain() ) );
			}//end if
		}//end if
	}

	/**
	 * Install the wordpress.org themes that are activated on this site.
	 *
	 * [<zip_path|premium_theme_download_url>]
	 * : Path to the premium themes zip file or URL to download the premium themes zip file
	 *
	 * ## OPTIONS
	 *
	 * [--site-key]
	 * : Pass the site key that is used to download the premium plugins from a website without having to generate a token on the live website.
	 *
	 * [--force]
	 * : Overwrite the current version of a premium theme if the theme is already installed
	 *
	 * [--dry-run]
	 * : Whether to actually install the themes.
	 */
	public function command_theme_install( $args, $assoc_args ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		global $dry_run;
		$dry_run               = false;
		$force                 = false;
		$premium_install       = false;
		$global_params_string  = '';
		$global_params_array   = array();
		$passed_config_options = \WP_CLI::get_config();

		if ( isset( $args[0] ) && ! empty( $args[0] ) ) {
			$premium_install = $args[0];
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = true;
		}

		if ( isset( $assoc_args['force'] ) ) {
			$force = true;
		}

		// grab the global parameters that were already passed to the "cshp-pt theme install" command such as
		// --path=<path>, --url=<url> so we can pass those same parameters to the "theme install" command
		if ( isset( $passed_config_options['path'] ) && ! empty( $passed_config_options['path'] ) ) {
			$global_params_array['path'] = $passed_config_options['path'];
			$global_params_string       .= sprintf( '--path="%s" ', $passed_config_options['path'] );
		}

		if ( isset( $passed_config_options['url'] ) && ! empty( $passed_config_options['url'] ) ) {
			$global_params_array['url'] = $passed_config_options['url'];
			$global_params_string      .= sprintf( '--url="%s" ', $passed_config_options['url'] );
		}

		if ( isset( $passed_config_options['http'] ) && ! empty( $passed_config_options['http'] ) ) {
			$global_params_array['http'] = $passed_config_options['http'];
			$global_params_string       .= sprintf( '--http="%s" ', $passed_config_options['http'] );
		}

		if ( isset( $passed_config_options['skip-packages'] ) && ! empty( $passed_config_options['skip-packages'] ) ) {
			$global_params_array['skip-packages'] = true;
			$global_params_string                .= '--skip-packages=true ';
		}

		$global_params_array['skip-plugins'] = 'cshp-plugin-tracker';
		$global_params_string               .= '--skip-plugins=cshp-plugin-tracker ';

		if ( ! empty( $premium_install ) ) {
			$zip_premium_themes_folder_path = '';
			$command                        = sprintf( 'theme install %s', $premium_install );
			\WP_CLI::log( sprintf( esc_html__( 'Preparing to install the premium themes from %s ...', $this->plugin_tracker->get_text_domain() ), $premium_install ) );
			$return = \WP_CLI::runcommand(
				$command,
				array_merge(
					$global_params_array,
					array(
						'return'     => true,
						'exit_error' => false,
					)
				)
			);

			if ( ! empty( $return ) && is_string( $return ) && false !== strpos( strtolower( $return ), 'theme installed successfully' ) ) {
				// search for cshp-premium-themes.css file in the theme directory and skip the one found in this plugin folder
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( get_theme_root(), \RecursiveDirectoryIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::SELF_FIRST
				);
				$iterator->setMaxDepth( 1 );

				foreach ( $iterator as $info ) {
					if ( 'cshp-premium-themes.css' === $info->getFilename() && false === strpos( $info->getRealPath(), $this->plugin_tracker->get_this_plugin_folder_name() ) ) {
						$zip_premium_themes_folder_path = dirname( $info->getRealPath() );
						break;
					}
				}
			} else {
				\WP_CLI::error( $return );
			}

			$themes_downloaded_list      = array();
			$premium_themes_install_path = $zip_premium_themes_folder_path;

			if ( ! is_dir( $premium_themes_install_path ) ) {
				\WP_CLI::error( esc_html__( sprintf( 'Could not find the unzipped premium theme folder in the WordPress themes folder %s. The downloaded premium themes would be in this folder. Please make sure that the themes folder is readable and writeable.', get_theme_root() ) ), $this->plugin_tracker->get_text_domain() );
				return;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $premium_themes_install_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setMaxDepth( 0 );

			foreach ( $iterator as $file ) {
				if ( $file->isDir() ) {
					$new_theme_folder_path = sprintf( '%s/%s', get_theme_root(), basename( $file->getRealpath() ) );
					try {
						$premium_theme_folder_name = basename( $file->getRealpath() );

						if ( is_dir( $new_theme_folder_path ) && false === $force ) {
							\WP_CLI::log( esc_html__( sprintf( 'The theme %s is already installed. If you want to overwrite the currently installed version with the version from the premium themes file, pass the --force flag to the command.', basename( $file->getRealpath() ) ), $this->plugin_tracker->get_text_domain() ) );
							$themes_downloaded_list[] = basename( $premium_theme_folder_name );
							continue;
						}

						$result = $wp_filesystem->move( $file->getRealpath(), $new_theme_folder_path, $force );

						if ( true === $result ) {
							$themes_downloaded_list[] = basename( $premium_theme_folder_name );
						} else {
							throw new \TypeError( __( 'Could not move theme folder', $this->plugin_tracker->get_text_domain() ) );
						}
					} catch ( \TypeError $error ) {
						\WP_CLI::log( esc_html__( sprintf( 'Could not move the premium theme folder %s to the WordPress themes folder. Please make sure the themes folder is readable and writeable. Your Filesystem may be in FTP mode.', basename( $file->getRealpath() ) ), $this->plugin_tracker->get_text_domain() ) );
					}
				}//end if
			}//end foreach

			try {
				$result = $wp_filesystem->delete( $premium_themes_install_path, true, 'd' );
				if ( true !== $result ) {
					throw new \TypeError( __( 'Could not move theme folder', $this->plugin_tracker->get_text_domain() ) );
				}
			} catch ( \TypeError $error ) {
				\WP_CLI::log( esc_html__( sprintf( 'Could not delete the premium theme zip folder %s from the WordPress themes folder. Please make sure the themes folder is readable and writeable. Your Filesystem may be in FTP mode.', $premium_themes_install_path ), $this->plugin_tracker->get_text_domain() ) );
			}

			// Bulk activate the newly installed premium themes
			$command = sprintf( 'theme activate %s', implode( ' ', $themes_downloaded_list ) );
			\WP_CLI::runcommand( $command, array_merge( $global_params_array ) );

			\WP_CLI::success( esc_html__( sprintf( 'Successfully installed and activated the premium themes %s', implode( ', ', $themes_downloaded_list ) ), $this->plugin_tracker->get_text_domain() ) );
		}//end if

		// handle installing the wordpress.org themes
		if ( false === $premium_install ) {
			if ( is_wp_error( $this->plugin_tracker->create_plugin_uploads_folder() ) ) {
				\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker uploads folder. Please make sure the file exists and check file permissions to make sure the uploads folder is readable and writeable.', $this->plugin_tracker->get_text_domain() ) );
				return;
			}

			$find_plugin_tracker_file = $this->plugin_tracker->read_tracker_file();

			if ( empty( $find_plugin_tracker_file ) ) {
				\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker composer.json file. Please make sure the file exists in the uploads folder and check file permissions to make sure the uploads folder is readable and writeable.', $this->plugin_tracker->get_text_domain() ) );
				return;
			}

			$theme_install_command = $this->plugin_tracker->generate_themes_wp_cli_install_command( $find_plugin_tracker_file, 'command' );

			if ( is_array( $theme_install_command ) && ! empty( $theme_install_command ) ) {
				if ( false === $dry_run ) {
					foreach ( $theme_install_command as $run_command ) {
						// remove the first instance of "wp " from the command so that we don't have WP_CLI try to call itself
						$run_command                              = substr_replace( $run_command, '', 0, strlen( 'wp ' ) );
						$command_with_passed_global_config_params = sprintf( '%s %s', $run_command, $global_params_string );
						\WP_CLI::log( sprintf( esc_html__( 'Running the command wp %s', $this->plugin_tracker->get_text_domain() ), $command_with_passed_global_config_params ) );
						\WP_CLI::runcommand( $command_with_passed_global_config_params );
					}//end foreach
				} elseif ( true === $dry_run ) {
					$theme_install_command = $this->plugin_tracker->generate_themes_wp_cli_install_command( $this->plugin_tracker->generate_composer_installed_themes(), 'raw' );

					\WP_CLI::log( sprintf( esc_html__( 'wordpress.org themes would be installed by running the command(s): %s', $this->plugin_tracker->get_text_domain() ), $theme_install_command ) );
				}//end if
			} else {
				\WP_CLI::log( esc_html__( 'No wordpress.org themes were found on this website', $this->plugin_tracker->get_text_domain() ) );
			}//end if
		}//end if
	}

	/**
	 * Backup the premium plugins zip to the Cornershop plugin distributor site, which backs up to S3.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Whether to actually backup the plugins zip.
	 */
	public function backup_premium_plugins_zip( $args, $assoc_args ) {
		global $dry_run;
		$dry_run = false;

		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = true;
		}

		$plugin_content         = $this->plugin_tracker->generate_composer_installed_plugins();
		$excluded_plugins       = $this->admin->get_excluded_plugins();
		$plugin_content_compare = array();
		$plugins_backed_up      = '';

		foreach ( $plugin_content as $plugin_key => $version ) {
			if ( false === strpos( $plugin_key, 'premium-plugin' ) ) {
				continue;
			}

			$plugin_folder_name = str_replace( 'premium-plugin/', '', $plugin_key );

			// prevent this plugin from being included in the premium plugins zip file
			// exclude plugins that we explicitly don't want to download
			// if the plugin should not download when we create the zip, don't back it up
			if ( $plugin_folder_name === $this->plugin_tracker->get_this_plugin_folder_name() || in_array( $plugin_folder_name, $excluded_plugins, true ) ) {
				continue;
			}

			$plugin_content_compare[] = $plugin_folder_name;
		}

		asort( $plugin_content_compare );
		$plugins_backed_up = implode( ', ', $plugin_content_compare );

		if ( true === $dry_run ) {
			\WP_CLI::log( sprintf( esc_html__( 'These plugins would be backed up: %s', $this->plugin_tracker->get_text_domain() ), $plugins_backed_up ) );
		} else {
			$result = $this->backup->backup_premium_plugins();
			if ( true === $result ) {
				\WP_CLI::success( sprintf( esc_html__( 'Successfully backed up the premium plugins zip file to the backup server. The plugins backed up are: %s', $this->plugin_tracker->get_text_domain() ), $plugins_backed_up ) );
			} else {
				\WP_CLI::error( esc_html( $result ) );
			}
		}
	}
}
