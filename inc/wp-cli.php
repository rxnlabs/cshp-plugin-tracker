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
 * Run actions for the Cornershop Plugin Tracker plugin using WP CLI
 *
 * ## OPTIONS
 *
 * <command>
 * : The command to perform. Available commands are:
 * - "generate" command will generate the installed plugins and themes tracker file.
 * - "plugin-zip" command will generate the zip of the installed premium plugins.
 * - "theme-zip" command will generate the zip of the installed premium themes.
 *
 * [--dry-run]
 * : Whether to actually generate the plugin tracker file.
 */
function wp_cli_commands( $args, $assoc_args ) {
	global $dry_run;
	$dry_run = false;

	if ( isset( $assoc_args['dry-run'] ) ) {
		$dry_run = true;
	}

	$command          = strtolower( $args[0] );
	$allowed_commands = [ 'generate', 'theme-zip', 'plugin-zip' ];

	if ( ! in_array( $command, $allowed_commands, true ) ) {
		\WP_CLI::error( sprintf( __( '"%s" is not a registered command of this plugin. Please try a different command.', Plugin_Tracker\get_textdomain() ), esc_html( $command ) ) );
	}

	if ( 'generate' === $command ) {
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

	if ( 'plugin-zip' === $command ) {
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

	if ( 'theme-zip' === $command ) {
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
}
\WP_CLI::add_command( 'cshp-pt', __NAMESPACE__ . '\wp_cli_commands' );

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
