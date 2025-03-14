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
		// Mock the Admin class
		$this->mockAdmin = Mockery::mock( Admin::class );
		// Initialize the Archive class with mocked dependencies
		$this->archive = new Archive(
			$this->mockUtilities,
			$this->mockAdmin,
		);

		// Stub WordPress functions used by the file being tested
		Functions\stubs(
			array(
				'get_post_meta' => function ( $post_id, $meta_key = '', $single = true ) {
					$post_meta_return = array(
						'cshp_plugin_tracker_zip' => '/fake/path/here/plugins.zip',
						'cshp_plugin_tracker_archived_plugins' => '{"gravityforms": "1.2.3", "advanced-custom-fields-pro": "1.2.3"}',
					);

					if ( isset( $post_meta_return[ $meta_key ] ) ) {
						return $post_meta_return[ $meta_key ];
					}

					return false;
				},
				'apply_filters' => function ( $filter, $default_value ) {
					return $default_value;
				},
				'get_post_type' => function ( $post_id = null ) {
					return 'cshp_pt_zip';
				},
				'is_wp_error'   => function ( $error ) {
					return false;
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
	'should be archive class [Function: __construct()]',
	function () {
		expect( $this->archive )->toBeInstanceOf( Archive::class );
	}
);

it(
	'should return the Archive post type slug as string [Function: get_archive_post_type()]',
	function () {
		expect( $this->archive->get_archive_post_type() )->toBestring();
	}
);

it(
	'should return the Archive post type taxonomy as string [Function: get_archive_taxonomy_slug()]',
	function () {
		expect( $this->archive->get_archive_taxonomy_slug() )->toBestring();
	}
);

it(
	'should return the path to the premium plugins zip file [Function: get_archive_zip_file()]',
	function () {
		$this->mockUtilities->shouldReceive( 'does_file_exists' )->once()->andReturn( true );
		expect( $this->archive->get_archive_zip_file( 1 ) )->toContain( 'plugins.zip' );
	}
);

it(
	'should get the post archive posts by the plugins it contains [Function: get_archive_post_by_contents()]',
	function () {
		$wp_post_post_result_1                          = Mockery::mock( \WP_Post::class );
		$wp_post_post_result_2                          = Mockery::mock( \WP_Post::class );
		$GLOBALS['OVERRIDE_WP_POST_QUERY_CLASS_RESULT'] = array( $wp_post_post_result_1, $wp_post_post_result_2 );
		$result = $this->archive->get_archive_post_by_contents( array( 'gravityforms', 'advanced-custom-fields-pro' ) );
		expect( $result )->toBeArray()->and( $result[0] )->toBeInstanceOf( \WP_Post::class );
	}
);

it(
	'should get the post archive posts that has the post title of the zip passed to the function [Function: get_archive_post_by_zip_filename()]',
	function () {
		expect( $this->archive->get_archive_post_by_zip_filename( 'premium-plugins.zip' ) )->toBeInstanceOf( \WP_Post::class );
	}
);

it(
	'should get the zip file path of the latest version of the post archive post by the plugins it contains [Function: get_archive_zip_file_by_contents()]',
	function () {
		$this->mockUtilities->shouldReceive( 'does_file_exists' )->once()->andReturn( true );
		$wp_post_post_result_1                          = Mockery::mock( \WP_Post::class );
		$wp_post_post_result_1->ID                      = 1;
		$wp_post_post_result_2                          = Mockery::mock( \WP_Post::class );
		$GLOBALS['OVERRIDE_WP_POST_QUERY_CLASS_RESULT'] = array( $wp_post_post_result_1, $wp_post_post_result_2 );
		$result = $this->archive->get_archive_zip_file_by_contents( array( 'gravityforms', 'advanced-custom-fields-pro' ) );
		expect( $result )->toContain( 'plugins.zip' );
	}
);

it(
	'should get the name and version of the plugins saved to a zip archive [Function: get_premium_plugin_zip_file_contents()]',
	function () {
		$wp_post_post_result                            = Mockery::mock( \WP_Post::class );
		$wp_post_post_result->ID                        = 1;
		$GLOBALS['OVERRIDE_WP_POST_QUERY_CLASS_RESULT'] = array( $wp_post_post_result );
		// test all the options we can pass to the method to get the same result
		$result_1 = $this->archive->get_premium_plugin_zip_file_contents( 1 );
		$result_2 = $this->archive->get_premium_plugin_zip_file_contents( 'premium-plugins.zip' );
		$result_3 = $this->archive->get_premium_plugin_zip_file_contents( $wp_post_post_result );
		expect( $result_1 )->toHaveKey( 'gravityforms' )->and( $result_2 )->toHaveKey( 'gravityforms' )->and( $result_3 )->toHaveKey( 'gravityforms' );
	}
);

it(
	'should list that our saved archive post is old based on newer versions of plugins being installed than were saved [Function: is_archive_zip_old()]',
	function () {
		$wp_post_post_result                            = Mockery::mock( \WP_Post::class );
		$wp_post_post_result->ID                        = 1;
		$GLOBALS['OVERRIDE_WP_POST_QUERY_CLASS_RESULT'] = array( $wp_post_post_result );
		expect( $this->archive->is_archive_zip_old( $wp_post_post_result ) )->toBeTrue();
	}
);
