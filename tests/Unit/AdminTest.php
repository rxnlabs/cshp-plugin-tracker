<?php

namespace Cshp\Plugin\Tracker;

use Yoast\WPTestUtils\BrainMonkey\TestCase;
use Mockery;
use Brain\Monkey;
use Brain\Monkey\Functions;

beforeEach(
	function () {
		parent::setUp();
		Monkey\setUp();
		// Mock the Utilities class
		$this->mockUtilities = Mockery::mock( Utilities::class );
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

		// Stub WordPress functions used by the file being tested
		Functions\stubs(
			array(
				'get_option'    => function ( $option, $default_value = false ) {
					$plugin_tracker_options_return_type = array(
						'cshp_plugin_tracker_exclude_plugins' => array( 'cshp-plugin-tracker', 'gravityforms' ),
						'cshp_plugin_tracker_exclude_themes' => array( 'crate', 'basket' ),
						'cshp_plugin_tracker_theme_zip_contents' => array(),
						'cshp_plugin_tracker_token'        => '1234567890',
						'cshp_plugin_tracker_theme_zip'    => '/fake/path/here.zip',
						'cshp_plugin_tracker_cpr_site_key' => '1234567890',
						'cshp_plugin_tracker_live_change_tracking' => 'yes',
						'cshp_plugin_tracker_debug_on'     => 0,
					);

					if ( isset( $plugin_tracker_options_return_type[ $option ] ) ) {
						return $plugin_tracker_options_return_type[ $option ];
					}

					return $default_value;
				},
				'apply_filters' => function ( $filter, $default_value ) {
					return $default_value;
				},
			)
		);
	}
);

afterEach(
	function () {
		parent::tearDown();
		Monkey\tearDown();
	}
);

it(
	'should be admin class [Function: __construct()]',
	function () {
		expect( $this->admin )->toBeInstanceOf( Admin::class );
	}
);

it(
	'should be plugin tracker class [Function: get_plugin_tracker()]',
	function () {
		expect( $this->admin->get_plugin_tracker() )->toBeInstanceOf( Plugin_Tracker::class );
	}
);

it(
	'should list gravityforms as excluded plugin [Function: get_excluded_plugins()]',
	function () {
		$this->mockPremiumList->shouldReceive( 'exclude_plugins_list' )->zeroOrMoreTimes()->andReturn( array() );
		expect( $this->admin->get_excluded_plugins() )->toContain( 'gravityforms' );
	}
);

it(
	'should list crate as excluded theme [Function: get_excluded_themes()]',
	function () {
		expect( $this->admin->get_excluded_themes() )->toContain( 'crate' );
	}
);

it(
	'should get Cornershop Plugin Recovery site key as string [Function: get_site_key()]',
	function () {
		expect( $this->admin->get_site_key() )->toBeString();
	}
);

it(
	'should have real time composer updates enabled [Function: should_real_time_update()]',
	function () {
		expect( $this->admin->should_real_time_update() )->toBeTrue();
	}
);

it(
	'should have debug mode off [Function: is_debug_mode_enabled()]',
	function () {
		expect( $this->admin->is_debug_mode_enabled() )->toBeFalse();
	}
);

it(
	'should have plugin zip rest api endpoint append token [Function: get_api_active_plugin_downloads_endpoint()]',
	function () {
		expect( $this->admin->get_api_active_plugin_downloads_endpoint() )->toContain( '?token=' );
	}
);

it(
	'should have plugin zip rewrite endpoint append token [Function: get_rewrite_active_plugin_downloads_endpoint()]',
	function () {
		expect( $this->admin->get_rewrite_active_plugin_downloads_endpoint() )->toContain( '?token=' );
	}
);

it(
	'should have theme zip rest api endpoint append token [Function: get_api_theme_downloads_endpoint()]',
	function () {
		expect( $this->admin->get_api_theme_downloads_endpoint() )->toContain( '?token=' );
	}
);

it(
	'should have theme zip rewrite endpoint append token [Function: get_rewrite_theme_downloads_endpoint()]',
	function () {
		expect( $this->admin->get_rewrite_theme_downloads_endpoint() )->toContain( '?token=' );
	}
);
