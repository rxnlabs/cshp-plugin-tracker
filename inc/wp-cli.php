<?php
/**
 * WP CLI commands for the plugin
 */

namespace Cshp\pt\WP_CLI;

use \Cshp\pt as Plugin_Tracker;
use function Cshp\pt\get_plugin_file_full_path;
use function Cshp\pt\get_plugin_folders_path;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
} elseif ( ! defined( '\WP_CLI' ) || ! \WP_CLI ) {
	return;
}

/**
 * Create the installed plugins and themes composer.json and README.md tracker files.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Whether to actually generate the plugin tracker file.
 */
function command_generate( $args, $assoc_args ) {
	global $dry_run;
	$dry_run = false;

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	$file_save_location = sprintf( '%s/composer.json', Plugin_Tracker\create_plugin_uploads_folder() );
	if ( false === $dry_run ) {
		$result            = Plugin_Tracker\create_plugin_tracker_file();
		$read_file         = Plugin_Tracker\read_tracker_file();
		$default_save_path = sprintf( '%s/cshp-plugin-tracker/composer.json', wp_upload_dir()['basedir'] );

		if ( ! empty( $read_file ) && empty( $result ) ) {
			\WP_CLI::success( sprintf( esc_html__( 'Successfully generated plugins and themes composer.json file at file path %s.', Plugin_Tracker\get_textdomain() ), $file_save_location ) );
		} else {
			if ( file_exists( $default_save_path ) ) {
				\WP_CLI::log( sprintf( esc_html__( 'There is already a composer.json saved at %s. Please check the file permissions to make sure that the file can be has write access.', Plugin_Tracker\get_textdomain() ), $default_save_path ) );
			}

			\WP_CLI::error( sprintf( esc_html__( 'Error generating plugins and themes composer.json file at location %1$s. %2$s', Plugin_Tracker\get_textdomain() ), $file_save_location, $result ) );
		}
	} elseif ( true === $dry_run ) {
		\WP_CLI::success( sprintf( esc_html__( 'Plugins and themes composer.json file would be saved at file path %1$s. %2$s%3$s', Plugin_Tracker\get_textdomain() ), $file_save_location, PHP_EOL, wp_json_encode( Plugin_Tracker\generate_composer_array(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) );
	}
}
\WP_CLI::add_command( 'cshp-pt generate', __NAMESPACE__ . '\command_generate' );

/**
 * Create the zip of the installed premium plugins.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Whether to actually generate the plugin zip file.
 */
function command_plugin_zip( $args, $assoc_args ) {
	global $dry_run;
	$dry_run = false;

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	\WP_CLI::log( esc_html__( 'Preparing to zip plugins...', Plugin_Tracker\get_textdomain() ) );

	if ( false === $dry_run ) {
		$result = Plugin_Tracker\zip_premium_plugins_include();
		if ( Plugin_Tracker\get_premium_plugin_zip_file() === $result ) {
			\WP_CLI::success( sprintf( esc_html__( 'Successfully generated premium plugins .zip at file path %s.', Plugin_Tracker\get_textdomain() ), Plugin_Tracker\get_premium_plugin_zip_file() ) );
		} else {
			\WP_CLI::error( esc_html( $result ) );
		}
	} elseif ( true === $dry_run ) {
		\WP_CLI::log( esc_html__( 'You cannot dry run generating a premium plugins .zip file.', Plugin_Tracker\get_textdomain() ) );

		if ( class_exists( '\ZipArchive' ) ) {
			\WP_CLI::log( esc_html__( 'The ZipArchive PHP extension is installed, so you hopefully should be able to generate the premium plugins .zip file without issues.', Plugin_Tracker\get_textdomain() ) );
		} else {
			\WP_CLI::error( esc_html__( 'The ZipArchive PHP extension is not installed or cannot be detected, so you will not be able to generate a zip of the premium plugins using this plugin. You should be able to make an archive of the premium plugins using the Zip or Tar command line tool.', Plugin_Tracker\get_textdomain() ) );
		}
	}
}
\WP_CLI::add_command( 'cshp-pt plugin-zip', __NAMESPACE__ . '\command_plugin_zip' );

/**
 * Create the zip of the installed premium themes.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Whether to actually generate the theme zip file.
 */
function command_theme_zip( $args, $assoc_args ) {
	global $dry_run;
	$dry_run = false;

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	\WP_CLI::log( esc_html__( 'Preparing to zip themes...', Plugin_Tracker\get_textdomain() ) );

	if ( false === $dry_run ) {
		$result = Plugin_Tracker\zip_premium_themes();
		if ( Plugin_Tracker\get_premium_theme_zip_file() === $result ) {
			\WP_CLI::success( sprintf( esc_html__( 'Successfully generated premium themes .zip at file path %s.', Plugin_Tracker\get_textdomain() ), Plugin_Tracker\get_premium_theme_zip_file() ) );
		} else {
			\WP_CLI::error( esc_html( $result ) );
		}
	} elseif ( true === $dry_run ) {
		\WP_CLI::log( esc_html__( 'You cannot dry run generating a premium themes .zip file.', Plugin_Tracker\get_textdomain() ) );

		if ( class_exists( '\ZipArchive' ) ) {
			\WP_CLI::log( esc_html__( 'The ZipArchive PHP extension is installed, so you hopefully should be able to generate the premium themes .zip file without issues.', Plugin_Tracker\get_textdomain() ) );
		} else {
			\WP_CLI::error( esc_html__( 'The ZipArchive PHP extension is not installed or cannot be detected, so you will not be able to generate a zip of the premium themes using this plugin. You should be able to make an archive of the premium themes using the Zip or Tar command line tool.', Plugin_Tracker\get_textdomain() ) );
		}
	}
}
\WP_CLI::add_command( 'cshp-pt theme-zip', __NAMESPACE__ . '\command_theme_zip' );

/**
 * Install the wordpress.org plugins that are activated on this site or install the premium plugins from a separate site based on passing the URL to the premium plugins zip file.
 *
 * [<zip_path|premium_plugin_download_url>]
 * : Path to the premium plugins zip file or URL to download the premium plugins zip file
 *
 * [<premium_plugin_folder_name>...]
 * : One or more premium plugins to install separated by a space. Use if you want to install only some of the premium plugins but not all of the premium plugins.
 *
 * ## OPTIONS
 * [--site-key]
 * : Pass the site key that is used to download the premium plugins from a website without having to generate a token on the live website.
 *
 * [--force]
 * : Overwrite the current version of a premium plugin if the plugin is already installed
 *
 * [--dry-run]
 * : Whether to actually install the plugins.
 */
function command_plugin_install( $args, $assoc_args ) {
	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;
	global $dry_run;
	$dry_run               = false;
	$force                 = false;
	$premium_install       = false;
	$specific_premium_plugins = [];
	$global_params_string  = '';
	$global_params_array   = [];
	$passed_config_options = \WP_CLI::get_config();

	if ( isset( $args[0] ) && ! empty( $args[0] ) ) {
		$premium_install = $args[0];
	}

	foreach ( $args as $index => $addition_positional_arguments ) {
		if ( 0 === $index || empty( trim( $addition_positional_arguments ) ) ) {
			continue;
		}

		$specific_premium_plugins[] = $addition_positional_arguments;
	}

	// if we have passed specific plugins that we want to install, pass those plugins as arguments to pass to the live site so it only zips the passed plugins
	if ( ! empty( $premium_install ) && ! empty( $specific_premium_plugins ) ) {
		$premium_install = add_query_arg( [ 'plugins' => $specific_premium_plugins ], $premium_install );
	}

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	if ( isset( $assoc_args['force'] ) ) {
		$force = true;
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

	if ( ! empty( $premium_install ) ) {
		$zip_premium_plugins_folder_path = '';
		$command                         = sprintf( 'plugin install %s', $premium_install );
		\WP_CLI::log( sprintf( esc_html__( 'Preparing to install the premium plugins from %s ...', Plugin_Tracker\get_textdomain() ), $premium_install ) );
		$return = \WP_CLI::runcommand(
			$command,
			array_merge(
				$global_params_array,
				[
					'return'     => true,
					'exit_error' => false,
				]
			)
		);

		if ( ! empty( $return ) && is_string( $return ) && false !== strpos( strtolower( $return ), 'plugin installed successfully' ) ) {
			// search for cshp-premium-plugin.php file in the plugin directory and skip the one found in this plugin folder
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( get_plugin_folders_path(), \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setMaxDepth( 1 );

			foreach ( $iterator as $info ) {
				if ( 'cshp-premium-plugins.php' === $info->getFilename() && false === strpos( $info->getRealPath(), Plugin_Tracker\get_this_plugin_folder() ) ) {
					$zip_premium_plugins_folder_path = dirname( $info->getRealPath() );
					break;
				}
			}
		} else {
			\WP_CLI::error( $return );
		}

		$plugins_downloaded_list      = [];
		$premium_plugins_install_path = $zip_premium_plugins_folder_path;

		if ( ! is_dir( $premium_plugins_install_path ) ) {
			\WP_CLI::error( esc_html__( sprintf( 'Could not find the unzipped premium plugin folder in the WordPress plugins folder %s. The downloaded premium plugins would be in this folder. Please make sure that the plugins folder is readable and writeable.', Plugin_Tracker\get_plugin_folders_path() ), Plugin_Tracker\get_textdomain() ) );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $premium_plugins_install_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$iterator->setMaxDepth( 0 );

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				$new_plugin_folder_path = sprintf( '%s/%s', Plugin_Tracker\get_plugin_folders_path(), basename( $file->getRealpath() ) );
				try {
					$premium_plugin_folder_name = basename( $file->getRealpath() );

					if ( is_dir( $new_plugin_folder_path ) && false === $force ) {
						\WP_CLI::log( esc_html__( sprintf( 'The plugin %s is already installed. If you want to overwrite the currently installed version with the version from the premium plugins file, pass the --force flag to the command.', basename( $file->getRealpath() ) ), Plugin_Tracker\get_textdomain() ) );
						$plugins_downloaded_list[] = basename( $premium_plugin_folder_name );
						continue;
					}

					$result = $wp_filesystem->move( $file->getRealpath(), $new_plugin_folder_path, $force );

					if ( true === $result ) {
						$plugins_downloaded_list[] = basename( $premium_plugin_folder_name );
					} else {
						throw new \TypeError( __( 'Could not move plugin folder', Plugin_Tracker\get_textdomain() ) );
					}
				} catch ( \TypeError $error ) {
					\WP_CLI::log( esc_html__( sprintf( 'Could not move the premium plugin folder %s to the WordPress plugins folder. Please make sure the plugins folder is readable and writeable. Your Filesystem may be in FTP mode.', basename( $file->getRealpath() ) ), Plugin_Tracker\get_textdomain() ) );
				}
			}//end if
		}//end foreach

		try {
			$result = $wp_filesystem->delete( $premium_plugins_install_path, true, 'd' );
			if ( true !== $result ) {
				throw new \TypeError( __( 'Could not move plugin folder', Plugin_Tracker\get_textdomain() ) );
			}
		} catch ( \TypeError $error ) {
			\WP_CLI::log( esc_html__( sprintf( 'Could not delete the premium plugin zip folder %s from the WordPress plugins folder. Please make sure the plugins folder is readable and writeable. Your Filesystem may be in FTP mode.', $premium_plugins_install_path ), Plugin_Tracker\get_textdomain() ) );
		}

		// Bulk activate the newly installed premium plugins
		$command = sprintf( 'plugin activate %s', implode( ' ', $plugins_downloaded_list ) );
		\WP_CLI::runcommand( $command, array_merge( $global_params_array ) );

		\WP_CLI::success( esc_html__( sprintf( 'Successfully installed and activated the premium plugins %s', implode( ', ', $plugins_downloaded_list ) ), Plugin_Tracker\get_textdomain() ) );
	}//end if

	// handle installing the wordpress.org plugins
	if ( false === $premium_install ) {
		if ( is_wp_error( Plugin_Tracker\create_plugin_uploads_folder() ) ) {
			\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker uploads folder. Please make sure the file exists and check file permissions to make sure the uploads folder is readable and writeable.', Plugin_Tracker\get_textdomain() ) );
			return;
		}

		$find_plugin_tracker_file = Plugin_Tracker\read_tracker_file();

		if ( empty( $find_plugin_tracker_file ) ) {
			\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker composer.json file. Please make sure the file exists in the uploads folder and check file permissions to make sure the uploads folder is readable and writeable.', Plugin_Tracker\get_textdomain() ) );
			return;
		}

		$plugin_install_command = Plugin_Tracker\generate_plugins_wp_cli_install_command( $find_plugin_tracker_file, 'command' );

		if ( is_array( $plugin_install_command ) && ! empty( $plugin_install_command ) ) {
			if ( false === $dry_run ) {
				foreach ( $plugin_install_command as $run_command ) {
					// remove the first instance of "wp " from the command so that we don't have WP_CLI try to call itself
					$run_command                              = substr_replace( $run_command, '', 0, strlen( 'wp ' ) );
					$command_with_passed_global_config_params = sprintf( '%s %s', $run_command, $global_params_string );
					\WP_CLI::log( sprintf( esc_html__( 'Running the command wp %s', Plugin_Tracker\get_textdomain() ), $command_with_passed_global_config_params ) );
					\WP_CLI::runcommand( $command_with_passed_global_config_params );
				}//end foreach
			} elseif ( true === $dry_run ) {
				$plugin_install_command = Plugin_Tracker\generate_plugins_wp_cli_install_command( Plugin_Tracker\generate_composer_installed_plugins(), 'raw' );

				\WP_CLI::log( sprintf( esc_html__( 'wordpress.org plugins would be installed by running the command(s): %s', Plugin_Tracker\get_textdomain() ), $plugin_install_command ) );
			}//end if
		} else {
			\WP_CLI::log( esc_html__( 'No wordpress.org plugins were found on this website', Plugin_Tracker\get_textdomain() ) );
		}//end if
	}//end if
}
\WP_CLI::add_command( 'cshp-pt plugin-install', __NAMESPACE__ . '\command_plugin_install' );

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
function command_theme_install( $args, $assoc_args ) {
	require_once ABSPATH . '/wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;
	global $dry_run;
	$dry_run               = false;
	$force                 = false;
	$premium_install       = false;
	$global_params_string  = '';
	$global_params_array   = [];
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
		\WP_CLI::log( sprintf( esc_html__( 'Preparing to install the premium themes from %s ...', Plugin_Tracker\get_textdomain() ), $premium_install ) );
		$return = \WP_CLI::runcommand(
			$command,
			array_merge(
				$global_params_array,
				[
					'return'     => true,
					'exit_error' => false,
				]
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
				if ( 'cshp-premium-themes.css' === $info->getFilename() && false === strpos( $info->getRealPath(), Plugin_Tracker\get_this_plugin_folder() ) ) {
					$zip_premium_themes_folder_path = dirname( $info->getRealPath() );
					break;
				}
			}
		} else {
			\WP_CLI::error( $return );
		}

		$themes_downloaded_list      = [];
		$premium_themes_install_path = $zip_premium_themes_folder_path;

		if ( ! is_dir( $premium_themes_install_path ) ) {
			\WP_CLI::error( esc_html__( sprintf( 'Could not find the unzipped premium theme folder in the WordPress themes folder %s. The downloaded premium themes would be in this folder. Please make sure that the themes folder is readable and writeable.', get_theme_root() ) ), Plugin_Tracker\get_textdomain() );
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
						\WP_CLI::log( esc_html__( sprintf( 'The theme %s is already installed. If you want to overwrite the currently installed version with the version from the premium themes file, pass the --force flag to the command.', basename( $file->getRealpath() ) ), Plugin_Tracker\get_textdomain() ) );
						$themes_downloaded_list[] = basename( $premium_theme_folder_name );
						continue;
					}

					$result = $wp_filesystem->move( $file->getRealpath(), $new_theme_folder_path, $force );

					if ( true === $result ) {
						$themes_downloaded_list[] = basename( $premium_theme_folder_name );
					} else {
						throw new \TypeError( __( 'Could not move theme folder', Plugin_Tracker\get_textdomain() ) );
					}
				} catch ( \TypeError $error ) {
					\WP_CLI::log( esc_html__( sprintf( 'Could not move the premium theme folder %s to the WordPress themes folder. Please make sure the themes folder is readable and writeable. Your Filesystem may be in FTP mode.', basename( $file->getRealpath() ) ), Plugin_Tracker\get_textdomain() ) );
				}
			}//end if
		}//end foreach

		try {
			$result = $wp_filesystem->delete( $premium_themes_install_path, true, 'd' );
			if ( true !== $result ) {
				throw new \TypeError( __( 'Could not move theme folder', Plugin_Tracker\get_textdomain() ) );
			}
		} catch ( \TypeError $error ) {
			\WP_CLI::log( esc_html__( sprintf( 'Could not delete the premium theme zip folder %s from the WordPress themes folder. Please make sure the themes folder is readable and writeable. Your Filesystem may be in FTP mode.', $premium_themes_install_path ), Plugin_Tracker\get_textdomain() ) );
		}

		// Bulk activate the newly installed premium themes
		$command = sprintf( 'theme activate %s', implode( ' ', $themes_downloaded_list ) );
		\WP_CLI::runcommand( $command, array_merge( $global_params_array ) );

		\WP_CLI::success( esc_html__( sprintf( 'Successfully installed and activated the premium themes %s', implode( ', ', $themes_downloaded_list ) ), Plugin_Tracker\get_textdomain() ) );
	}//end if

	// handle installing the wordpress.org themes
	if ( false === $premium_install ) {
		if ( is_wp_error( Plugin_Tracker\create_plugin_uploads_folder() ) ) {
			\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker uploads folder. Please make sure the file exists and check file permissions to make sure the uploads folder is readable and writeable.', Plugin_Tracker\get_textdomain() ) );
			return;
		}

		$find_plugin_tracker_file = Plugin_Tracker\read_tracker_file();

		if ( empty( $find_plugin_tracker_file ) ) {
			\WP_CLI::error( esc_html__( 'Could not find or create the plugin tracker composer.json file. Please make sure the file exists in the uploads folder and check file permissions to make sure the uploads folder is readable and writeable.', Plugin_Tracker\get_textdomain() ) );
			return;
		}

		$theme_install_command = Plugin_Tracker\generate_themes_wp_cli_install_command( $find_plugin_tracker_file, 'command' );

		if ( is_array( $theme_install_command ) && ! empty( $theme_install_command ) ) {
			if ( false === $dry_run ) {
				foreach ( $theme_install_command as $run_command ) {
					// remove the first instance of "wp " from the command so that we don't have WP_CLI try to call itself
					$run_command                              = substr_replace( $run_command, '', 0, strlen( 'wp ' ) );
					$command_with_passed_global_config_params = sprintf( '%s %s', $run_command, $global_params_string );
					\WP_CLI::log( sprintf( esc_html__( 'Running the command wp %s', Plugin_Tracker\get_textdomain() ), $command_with_passed_global_config_params ) );
					\WP_CLI::runcommand( $command_with_passed_global_config_params );
				}//end foreach
			} elseif ( true === $dry_run ) {
				$theme_install_command = Plugin_Tracker\generate_themes_wp_cli_install_command( Plugin_Tracker\generate_composer_installed_themes(), 'raw' );

				\WP_CLI::log( sprintf( esc_html__( 'wordpress.org themes would be installed by running the command(s): %s', Plugin_Tracker\get_textdomain() ), $theme_install_command ) );
			}//end if
		} else {
			\WP_CLI::log( esc_html__( 'No wordpress.org themes were found on this website', Plugin_Tracker\get_textdomain() ) );
		}//end if
	}//end if
}
\WP_CLI::add_command( 'cshp-pt theme-install', __NAMESPACE__ . '\command_theme_install' );

/**
 * Backup the premium plugins zip to the Cornershop plugin distributor site, which backs up to S3.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Whether to actually backup the plugins zip.
 */
function backup_premium_plugins_zip( $args, $assoc_args ) {
	global $dry_run;
	$dry_run = false;

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	$plugin_content         = Plugin_Tracker\generate_composer_installed_plugins();
	$excluded_plugins       = Plugin_Tracker\get_excluded_plugins();
	$plugin_content_compare = [];
	$plugins_backed_up      = '';

	foreach ( $plugin_content as $plugin_key => $version ) {
		if ( false === strpos( $plugin_key, 'premium-plugin' ) ) {
			continue;
		}

		$plugin_folder_name = str_replace( 'premium-plugin/', '', $plugin_key );

		// prevent this plugin from being included in the premium plugins zip file
		// exclude plugins that we explicitly don't want to download
		// if the plugin should not download when we create the zip, don't back it up
		if ( $plugin_folder_name === Plugin_Tracker\get_this_plugin_folder() || in_array( $plugin_folder_name, $excluded_plugins, true ) ) {
			continue;
		}

		$plugin_content_compare[] = $plugin_folder_name;
	}

	asort( $plugin_content_compare );
	$plugins_backed_up = implode( ', ', $plugin_content_compare );

	if ( true === $dry_run ) {
		\WP_CLI::log( sprintf( esc_html__( 'These plugins would be backed up: %s', Plugin_Tracker\get_textdomain() ), $plugins_backed_up ) );
	} else {
		$result = Plugin_Tracker\backup_premium_plugins();
		if ( true === $result ) {
			\WP_CLI::success( sprintf( esc_html__( 'Successfully backed up the premium plugins zip file to the backup server. The plugins backed up are: %s', Plugin_Tracker\get_textdomain() ), $plugins_backed_up ) );
		} else {
			\WP_CLI::error( esc_html( $result ) );
		}
	}

}
\WP_CLI::add_command( 'cshp-pt backup-plugin', __NAMESPACE__ . '\backup_premium_plugins_zip' );

/**
 * Update the installed plugins and themes tracker file after a plugin or theme is installed, updated, or deleted
 * with WP CLI.
 *
 * @return void
 */
function wp_cli_post_update() {
	\WP_CLI::runcommand( 'cshp-pt generate' );
}
\WP_CLI::add_hook( 'after_invoke:core update', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:core download', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:plugin install', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:plugin uninstall', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:plugin delete', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:plugin update', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:theme install', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:theme uninstall', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:theme delete', __NAMESPACE__ . '\wp_cli_post_update' );
\WP_CLI::add_hook( 'after_invoke:theme update', __NAMESPACE__ . '\wp_cli_post_update' );
