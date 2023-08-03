<?php
/**
 * WP CLI commands for the plugin
 */

namespace Cshp\pt\WP_CLI;

use \Cshp\pt as Plugin_Tracker;

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
		$read_file         = Plugin_Tracker\read_plugins_file();
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

	if ( false === $dry_run ) {
		$result = Plugin_Tracker\zip_missing_plugins();
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

	if ( false === $dry_run ) {
		$result = Plugin_Tracker\zip_missing_themes();
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
 * Install the wordpress.org plugins that are activated on this site.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Whether to actually install the plugins.
 */
function command_plugin_install( $args, $assoc_args ) {
	global $dry_run;
	$dry_run = false;
	$global_params = '';
	$passed_config_options = \WP_CLI::get_config();

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	$plugin_install_command = Plugin_Tracker\generate_plugins_wp_cli_install_command( Plugin_Tracker\generate_composer_installed_plugins(), 'command' );

	if ( is_array( $plugin_install_command ) && ! empty( $plugin_install_command ) ) {
		if ( false === $dry_run ) {
			foreach ( $plugin_install_command as $run_command ) {
				// remove the first instance of "wp " from the command so that we don't have WP_CLI try to call itself
				$run_command = substr_replace( $run_command, '', 0, strlen( 'wp ' ) );
				// grab the global parameters that were already passed to the "cshp-pt plugin install" command such as
				// --path=<path>, --url=<url> so we can pass those same parameters to the "plugin install" command
				if ( isset( $passed_config_options['path'] ) && ! empty( $passed_config_options['path'] ) ) {
					$global_params .= sprintf( '--path="%s" ', $passed_config_options['path'] );
				}

				if ( isset( $passed_config_options['url'] ) && ! empty( $passed_config_options['url'] ) ) {
					$global_params .= sprintf( '--url="%s" ', $passed_config_options['url'] );
				}

				if ( isset( $passed_config_options['http'] ) && ! empty( $passed_config_options['http'] ) ) {
					$global_params .= sprintf( '--http="%s" ', $passed_config_options['http'] );
				}

				if ( isset( $passed_config_options['skip-packages'] ) && ! empty( $passed_config_options['skip-packages'] ) ) {
					$global_params .= "--skip-packages=true ";
				}

				$command_with_passed_global_config_params = sprintf( '%s %s', $run_command, $global_params );
				\WP_CLI::log( sprintf( esc_html__( 'Running the command wp %s', Plugin_Tracker\get_textdomain() ), $command_with_passed_global_config_params ) );
				\WP_CLI::runcommand( $command_with_passed_global_config_params );
			}
		} elseif ( true === $dry_run ) {
			$plugin_install_command = Plugin_Tracker\generate_plugins_wp_cli_install_command( Plugin_Tracker\generate_composer_installed_plugins(), 'raw' );

			\WP_CLI::log( sprintf( esc_html__( 'wordpress.org plugins would be installed by running the command(s): %s', Plugin_Tracker\get_textdomain() ), $plugin_install_command ) );
		}
	} else {
		\WP_CLI::log( esc_html__( 'No wordpress.org plugins were found on this website', Plugin_Tracker\get_textdomain() ) );
	}
}
\WP_CLI::add_command( 'cshp-pt plugin-install', __NAMESPACE__ . '\command_plugin_install' );

/**
 * Install the wordpress.org themes that are activated on this site.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Whether to actually install the themes.
 */
function command_theme_install( $args, $assoc_args ) {
	global $dry_run;
	$dry_run = false;
	$global_params = '';
	$passed_config_options = \WP_CLI::get_config();

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	$plugin_install_command = Plugin_Tracker\generate_themes_wp_cli_install_command( Plugin_Tracker\generate_composer_installed_themes(), 'command' );

	if ( is_array( $plugin_install_command ) && ! empty( $plugin_install_command ) ) {
		if ( false === $dry_run ) {
			foreach ( $plugin_install_command as $run_command ) {
				// remove the first instance of "wp " from the command so that we don't have WP_CLI try to call itself
				$run_command = substr_replace( $run_command, '', 0, strlen( 'wp ' ) );
				// grab the global parameters that were already passed to the "cshp-pt theme install" command such as
				// --path=<path>, --url=<url> so we can pass those same parameters to the "theme install" command
				if ( isset( $passed_config_options['path'] ) && ! empty( $passed_config_options['path'] ) ) {
					$global_params .= sprintf( '--path="%s" ', $passed_config_options['path'] );
				}

				if ( isset( $passed_config_options['url'] ) && ! empty( $passed_config_options['url'] ) ) {
					$global_params .= sprintf( '--url="%s" ', $passed_config_options['url'] );
				}

				if ( isset( $passed_config_options['http'] ) && ! empty( $passed_config_options['http'] ) ) {
					$global_params .= sprintf( '--http="%s" ', $passed_config_options['http'] );
				}

				if ( isset( $passed_config_options['skip-packages'] ) && ! empty( $passed_config_options['skip-packages'] ) ) {
					$global_params .= "--skip-packages=true ";
				}

				$command_with_passed_global_config_params = sprintf( '%s %s', $run_command, $global_params );
				\WP_CLI::log( sprintf( esc_html__( 'Running the command wp %s', Plugin_Tracker\get_textdomain() ), $command_with_passed_global_config_params ) );
				\WP_CLI::runcommand( $command_with_passed_global_config_params );
			}
		} elseif ( true === $dry_run ) {
			$plugin_install_command = Plugin_Tracker\generate_themes_wp_cli_install_command( Plugin_Tracker\generate_composer_installed_themes(), 'raw' );

			\WP_CLI::log( sprintf( esc_html__( 'wordpress.org themes would be installed by running the command(s): %s', Plugin_Tracker\get_textdomain() ), $plugin_install_command ) );
		}
	} else {
		\WP_CLI::log( esc_html__( 'No wordpress.org themes were found on this website', Plugin_Tracker\get_textdomain() ) );
	}
}
\WP_CLI::add_command( 'cshp-pt theme-install', __NAMESPACE__ . '\command_theme_install' );

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
