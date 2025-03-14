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
		$this->mockUtilities->shouldReceive( 'does_file_exists' )->zeroOrMoreTimes()->andReturn( true );
		// Mock the Admin class
		$this->mockAdmin = Mockery::mock( Admin::class );
		// Initialize the Archive class with mocked dependencies
		$this->archive = new Archive(
			$this->mockUtilities,
			$this->mockAdmin,
		);
	}
);

afterEach(
	function () {
		parent::tearDown();
		Monkey\tearDown();
	}
);

test(
	'it hooks into the init action to create a new post archive post type [Hook: init, Callback: create_archive_post_type()]',
	function () {
		$this->archive->hooks();
		$hook_priority = has_action( 'init', array( $this->archive, 'create_archive_post_type' ) );
		expect( $hook_priority )->toBeInt();
	}
);

test(
	'it creates a new post type with the post type slug cshp_pt_zip and a new taxonomy named cshp_pt_zip_content [Function: create_archive_post_type()]',
	function () {
		$this->mockAdmin->shouldReceive( 'is_debug_mode_enabled' )->zeroOrMoreTimes()->andReturn( false );
		$this->mockUtilities->shouldReceive( 'str_ends_with' )->zeroOrMoreTimes()->andReturn( true );
		$this->archive->hooks();
		$this->archive->create_archive_post_type();
		$post_type = get_post_type_object( $this->archive->get_archive_post_type() );
		$taxonomy  = get_taxonomy( $this->archive->get_archive_taxonomy_slug() );
		expect( $post_type )->toBeInstanceOf( \WP_Post_Type::class )->toHaveProperty( 'name', $this->archive->get_archive_post_type() )->and( $taxonomy )->toBeInstanceOf( \WP_Taxonomy::class )->toHaveProperty( 'name', $this->archive->get_archive_taxonomy_slug() );
	}
);

test(
	'it should have a callback registered for the hook wp_insert_post that will limit the number of archive posts that can exists and adding 26 posts should show that the post count is less than 26 [Hook: wp_insert_post, Callback: limit_archive_post_type()]',
	function () {
		$this->archive->hooks();
		$hook_priority = has_action( 'wp_insert_post', array( $this->archive, 'limit_archive_post_type' ) );

		for ( $i = 0; $i < 26; $i++ ) {
			wp_insert_post(
				array(
					'title'       => 'Test Archive Post ' . $i,
					'post_type'   => $this->archive->get_archive_post_type(),
					'post_status' => 'private',
				)
			);
		}

		$posts = new \WP_Query(
			array(
				'post_type'      => $this->archive->get_archive_post_type(),
				'post_status'    => 'private',
				'posts_per_page' => 27,
				'fields'         => 'ids',
			)
		);

		expect( $hook_priority )->toBeInt()->and( $posts->post_count )->toBeLessThan( 26 );
	}
);

it(
	'it should delete the plugin archive zip file when the archive post is deleted [Hook: before_delete_post, Callback: delete_archive_file()]',
	function () {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		$test_file_path = '';
		$this->archive->hooks();
		$folder_path = sprintf( '%s/cshp-plugin-tracker', wp_upload_dir()['basedir'] );
		$this->archive->create_plugin_uploads_folder();

		if ( is_dir( $folder_path ) ) {
			$test           = $wp_filesystem->copy( 'tests/Files/plugin-backup-test-text-file.txt', $folder_path . '/plugin-backup-test-text-file.txt', true );
			$test_file_path = $folder_path . '/plugin-backup-test-text-file.txt';
		}

		$post_id = wp_insert_post(
			array(
				'title'       => 'Test Archive Post',
				'post_type'   => $this->archive->get_archive_post_type(),
				'post_status' => 'private',
			)
		);

		update_post_meta( $post_id, 'cshp_plugin_tracker_zip', 'plugin-backup-test-text-file.txt' );

		if ( file_exists( $test_file_path ) && is_file( $test_file_path ) ) {
			$path = realpath( $test_file_path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$file_path_content = file_get_contents( $path );
			// this will throw a warning in the test due to using plugin using the function wp_delete_file and that function using unlink
			wp_delete_post( $post_id, true );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$new_file_content = file_get_contents( $test_file_path );
			expect( $file_path_content )->not()->toBeEmpty()->and( $new_file_content )->toBeEmpty();
		} else {
			$this->fail( 'Could not find the plugin archive test file in the uploads folder, so could not delete it when the archive file when the archive post was deleted.' );
		}
	}
);

test(
	'it should delete old archive posts where the plugin versions installed are different than the plugin versions that the archive post has stored [Function: delete_old_archive_posts()]',
	function () {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		$test_plugin_path = WP_PLUGIN_DIR . '/fake-test-plugin';

		try {
			if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
				if ( ! is_dir( $test_plugin_path ) ) {
					$test = $wp_filesystem->mkdir( $test_plugin_path );
					if ( ! $test ) {
						throw new \Exception( 'Could not copy fake test plugin to the plugins folder.' );
					}
				}

				// copy the fake test plugin main file to the fake plugin directory if the fake plugin directory could be created
				$wp_filesystem->copy( 'tests/Files/fake-test-plugin.php', $test_plugin_path . '/fake-test-plugin.php', true );
			}

			$post_id_old_plugins = wp_insert_post(
				array(
					'title'       => 'Test Archive Post Older Plugins',
					'post_type'   => $this->archive->get_archive_post_type(),
					'post_status' => 'private',
				)
			);

			if ( empty( $post_id_old_plugins ) || is_wp_error( $post_id_old_plugins ) ) {
				throw new \Exception( 'Could not create old test archive post.' );
			}

			update_post_meta(
				$post_id_old_plugins,
				'cshp_plugin_tracker_archived_plugins',
				wp_slash(
					wp_json_encode(
						array(
							'fake-test-plugin' => '0.0.1',
						)
					)
				)
			);

			$post_id_new_plugins = wp_insert_post(
				array(
					'title'       => 'Test Archive Post Newer Plugins',
					'post_type'   => $this->archive->get_archive_post_type(),
					'post_status' => 'private',
				)
			);

			if ( empty( $post_id_new_plugins ) || is_wp_error( $post_id_new_plugins ) ) {
				throw new \Exception( 'Could not create new test archive post.' );
			}

			update_post_meta(
				$post_id_new_plugins,
				'cshp_plugin_tracker_archived_plugins',
				wp_slash(
					wp_json_encode(
						array(
							'fake-test-plugin' => '1.0.0',
						)
					)
				)
			);

			$this->archive->delete_old_archive_posts();
			expect( get_post( $post_id_old_plugins ) )->toBeNull()->and( get_post( $post_id_new_plugins ) )->toBeInstanceOf( \WP_Post::class );
		} catch ( \Exception $e ) {
			$this->fail( $e->getMessage() );
		}
	}
);
