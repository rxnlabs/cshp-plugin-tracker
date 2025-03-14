<?php

namespace Cshp\Plugin\Tracker;

use Yoast\WPTestUtils\BrainMonkey\TestCase;
use Mockery;
use Brain\Monkey;
use Brain\Monkey\Functions;

if ( isUnitTest() ) {
	return;
}

beforeEach(
	function () {
		parent::setUp();
		Monkey\setUp();

		// Mock the Utilities class
		$this->mockUtilities = Mockery::mock( Utilities::class );
		// Stub the str_ends_with method since we use this in the is_authorized_user trait method
		$this->mockUtilities->shouldReceive( 'str_ends_with' )->zeroOrMoreTimes()->andReturn( true );
		// Mock the Logger class
		$this->mockLogger = Mockery::mock( Logger::class );
		// Mock the Premium_List class
		$this->mockPremiumList = Mockery::mock( Premium_List::class );
		// Mock the main Plugin_Tracker class
		$this->mockPluginTracker = Mockery::mock( Plugin_Tracker::class );
		// Initialize the Admin class with mocked dependencies
		$this->admin = new Admin(
			$this->mockUtilities,
			$this->mockLogger,
			$this->mockPremiumList
		);

		$this->admin->set_plugin_tracker( $this->mockPluginTracker );
	}
);

afterEach(
	function () {
		parent::tearDown();
		Monkey\tearDown();
	}
);

test(
	'it loads a new hook to admin_menu to add a new options page [Hook: admin_menu, Callback: add_options_admin_menu()]',
	function () {
		$this->admin->admin_hooks();
		wp_set_current_user( 1 );
		set_current_screen( 'options-general.php' );
		$hook_priority = has_action( 'admin_menu', array( $this->admin, 'add_options_admin_menu' ) );
		expect( $hook_priority )->toBeInt();
	}
);

test(
	'it creates a new options subpage page with the page slug cshp-plugin-tracker [Function: add_options_admin_menu()]',
	function () {
		$this->admin->admin_hooks();
		// Set the current user
		wp_set_current_user( 1 );
		// Set the current screen to be  the general options page
		set_current_screen( 'options-general.php' );
		global $submenu;
		$this->admin->add_options_admin_menu();
		$found = false;

		// Verify if $submenu exists and is an array.
		if ( isset( $submenu ) && is_array( $submenu ) ) {
			foreach ( $submenu as $key => $menu_items ) {
				foreach ( $menu_items as $item ) {
					// Check if the capability is 'manage_options' and the slug matches 'cshp-plugin-tracker'.
					if (
					isset( $item[1] ) && 'manage_options' === $item[1] &&
					isset( $item[2] ) && 'cshp-plugin-tracker' === $item[2]
					) {
						$found = true;
						break 2; // Exit both loops as the condition is met.
					}
				}
			}
		}

		expect( $found )->toBeTrue();
	}
);

test(
	'it loads settings link callback in admin_init hook [Hook: admin_init, Callback: add_settings_link()]',
	function () {
		$this->admin->admin_hooks();
		set_current_screen( 'plugins.php' );
		$hook_priority = has_action( 'admin_init', array( $this->admin, 'add_settings_link' ) );
		expect( $hook_priority )->toBeInt();
	}
);

test(
	'it loads registered settings callback in admin_init hook [Hook: admin_init, Callback: register_options_admin_settings()]',
	function () {
		$this->admin->admin_hooks();
		set_current_screen( 'plugins.php' );
		$hook_priority = has_action( 'admin_init', array( $this->admin, 'register_options_admin_settings' ) );
		expect( $hook_priority )->toBeInt();
	}
);

test(
	'it registers a plugin setting for the cshp_plugin_tracker_token option [Function: register_options_admin_settings()]',
	function () {
		$this->admin->admin_hooks();
		$this->admin->register_options_admin_settings();
		$registered_settings = get_registered_settings();
		expect( $registered_settings )->toHaveKey( 'cshp_plugin_tracker_token' );
	}
);

test(
	'it renders settings page for plugin [Function: admin_page()]',
	function () {
		// Stub dependency injected external class methods used by this class.
		$this->mockUtilities->shouldReceive( 'get_active_plugins' )->zeroOrMoreTimes()->andReturn( array( 'cshp-plugin-tracker/cshp-plugin-tracker.php' ) );
		$this->mockUtilities->shouldReceive( 'get_active_themes' )->zeroOrMoreTimes()->andReturn( array( 'crate' ) );
		$this->mockUtilities->shouldReceive( 'get_excluded_plugins' )->zeroOrMoreTimes()->andReturn( array( 'cshp-plugin-tracker' ) );
		$this->mockUtilities->shouldReceive( 'get_plugin_file_full_path' )->zeroOrMoreTimes()->andReturn( ABSPATH . 'wp-content/plugins/hello.php' );
		$this->mockPremiumList->shouldReceive( 'exclude_plugins_list' )->zeroOrMoreTimes()->andReturn( array() );
		$this->mockPluginTracker->shouldReceive( 'generate_plugins_wp_cli_install_command' )->zeroOrMoreTimes()->andReturn( 'fake-command' );
		$this->mockPluginTracker->shouldReceive( 'generate_premium_plugins_wp_cli_install_command' )->zeroOrMoreTimes()->andReturn( 'fake-command' );
		$this->mockPluginTracker->shouldReceive( 'generate_themes_wp_cli_install_command' )->zeroOrMoreTimes()->andReturn( 'fake-command' );
		$this->mockPluginTracker->shouldReceive( 'generate_premium_themes_wp_cli_install_command' )->zeroOrMoreTimes()->andReturn( 'fake-command' );
		$this->mockPluginTracker->shouldReceive( 'generate_wget_plugins_download_command' )->zeroOrMoreTimes()->andReturn( 'fake-command' );
		$this->mockPluginTracker->shouldReceive( 'generate_wget_themes_download_command' )->zeroOrMoreTimes()->andReturn( 'fake-command' );
		$this->mockPluginTracker->shouldReceive( 'generate_composer_installed_plugins' )->zeroOrMoreTimes()->andReturn( array( 'fake-plugin' ) );
		$this->mockPluginTracker->shouldReceive( 'generate_composer_installed_themes' )->zeroOrMoreTimes()->andReturn( array( 'fake-theme' ) );

		$this->admin->admin_hooks();
		do_action( 'admin_menu' );
		wp_set_current_user( 1 );
		// Set the current screen to be the plugin options screen
		set_current_screen( 'options-general.php?page=cshp-plugin-tracker' );
		ob_start();
		$this->admin->admin_page();
		$output = ob_get_clean();
		expect( $output )->toContain( 'cshp-plugin-tracker-wrap' );
	}
);

test(
	'it adds action to display admin notice when wordpress.org requests are blocked [Hook: admin_notices, Callback: admin_notices()]',
	function () {
		$this->admin->admin_hooks();
		$hook_priority = has_action( 'admin_notices', array( $this->admin, 'admin_notice' ) );
		expect( $hook_priority )->toBeInt();
	}
);

test(
	'it shows notice when external requests to wordpress.org are blocked [Function: admin_notices()]',
	function () {
		$this->admin->admin_hooks();
		$this->mockUtilities->shouldReceive( 'is_wordpress_org_external_request_blocked' )->zeroOrMoreTimes()->andReturn( true );
		wp_set_current_user( 1 );
		// Set the current screen to be the plugin options screen
		set_current_screen( 'options-general.php?page=cshp-plugin-tracker' );
		ob_start();
		$this->admin->admin_notice();
		$output = ob_get_clean();
		expect( $output )->toContain( 'cshp-pt-notice' );
	}
);

test(
	'it verifies admin scripts are set to enqueue for hook admin_enqueue_scripts [Hook: admin_enqueue_scripts, Callback: admin_enqueue_scripts()]',
	function () {
		$this->admin->admin_hooks();
		wp_set_current_user( 1 );
		// Set the current screen to be  the general options page
		set_current_screen( 'options-general.php' );
		$hook_priority = has_action( 'admin_enqueue_scripts', array( $this->admin, 'admin_enqueue' ) );
		expect( $hook_priority )->toBeInt();
	}
);

test(
	'it enqueues the plugin tracker admin scripts on plugin settings page only [Function: admin_enqueue_scripts()]',
	function () {
		$this->admin->admin_hooks();
		wp_set_current_user( 1 );
		// Set the current screen to be  the general options page
		set_current_screen( 'options-general.php' );
		do_action( 'admin_enqueue_scripts' );
		$scripts = wp_scripts()->queue;
		expect( $scripts )->not->toContain( 'cshp-plugin-tracker-admin' );
	}
);
