<?php
declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

// exit if not loading in WordPress context but don't exit if running our PHPUnit tests
if ( ! defined( 'ABSPATH' ) && ! defined( 'CSHP_PHPUNIT_TESTS_RUNNING' ) ) {
	exit;
}

/**
 * Archive class to create a custom post type to store and manage the plugin and theme zip files that are generated.
 */
class Archive {
	use Share;

	/**
	 * Stores the slug for the archive custom post type associated with zip content.
	 *
	 * @var string
	 */
	private $archive_post_type_slug = 'cshp_pt_zip';
	/**
	 * Holds the slug for the archive taxonomy related to the zip content custom post type.
	 *
	 * @var string
	 */
	private $archive_taxonomy_slug = 'cshp_pt_zip_content';
	/**
	 * Utility instance providing various helper methods and functionalities.
	 *
	 * @var Utility
	 */
	private $utilities;

	/**
	 * Admin instance responsible for managing the admin settings and interface.
	 *
	 * @var Admin
	 */
	private $admin;

	/**
	 * Constructor for the class.
	 *
	 * @param  Utilities $utilities  An instance of the Utilities class.
	 *
	 * @return void
	 */
	public function __construct( Utilities $utilities, Admin $admin ) {
		$this->utilities = $utilities;
		$this->admin     = $admin;
	}

	public function hooks() {
		add_action( 'init', array( $this, 'create_archive_post_type' ) );
		add_action( 'wp_insert_post', array( $this, 'limit_archive_post_type' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'delete_archive_file' ), 10, 2 );
	}

	/**
	 * Get the post type that will be used for managing the premium plugin and theme zip files.
	 *
	 * @return string Name of the post type used for tracking plugin and theme zip files.
	 */
	public function get_archive_post_type() {
		return $this->archive_post_type_slug;
	}

	/**
	 * Get the taxonomy that will be used for tracking the contents of a zip file.
	 *
	 * @return string Taxonomy used for tracking the contents of a zip file.
	 */
	public function get_archive_taxonomy_slug() {
		return $this->archive_taxonomy_slug;
	}

	/**
	 * Register a post type for tracking the generated zip files.
	 *
	 * @return void
	 */
	public function create_archive_post_type() {
		register_post_type(
			$this->get_archive_post_type(),
			array(
				'labels'                => array(
					'name'                  => __( 'Plugin Archives', $this->get_text_domain() ),
					'singular_name'         => __( 'Plugin Archive', $this->get_text_domain() ),
					'all_items'             => __( 'All Plugin Archives', $this->get_text_domain() ),
					'archives'              => __( 'Plugin Archive Archives', $this->get_text_domain() ),
					'attributes'            => __( 'Plugin Archive Attributes', $this->get_text_domain() ),
					'insert_into_item'      => __( 'Insert into Plugin Archive', $this->get_text_domain() ),
					'uploaded_to_this_item' => __( 'Uploaded to this Plugin Archive', $this->get_text_domain() ),
					'featured_image'        => _x( 'Featured Image', 'plugin-archive', $this->get_text_domain() ),
					'set_featured_image'    => _x( 'Set featured image', 'plugin-archive', $this->get_text_domain() ),
					'remove_featured_image' => _x( 'Remove featured image', 'plugin-archive', $this->get_text_domain() ),
					'use_featured_image'    => _x( 'Use as featured image', 'plugin-archive', $this->get_text_domain() ),
					'filter_items_list'     => __( 'Filter Plugin Archives list', $this->get_text_domain() ),
					'items_list_navigation' => __( 'Plugin Archives list navigation', $this->get_text_domain() ),
					'items_list'            => __( 'Plugin Archives list', $this->get_text_domain() ),
					'new_item'              => __( 'New Plugin Archive', $this->get_text_domain() ),
					'add_new'               => __( 'Add New', $this->get_text_domain() ),
					'add_new_item'          => __( 'Add New Plugin Archive', $this->get_text_domain() ),
					'edit_item'             => __( 'Edit Plugin Archive', $this->get_text_domain() ),
					'view_item'             => __( 'View Plugin Archive', $this->get_text_domain() ),
					'view_items'            => __( 'View Plugin Archives', $this->get_text_domain() ),
					'search_items'          => __( 'Search Plugin Archives', $this->get_text_domain() ),
					'not_found'             => __( 'No Plugin Archives found', $this->get_text_domain() ),
					'not_found_in_trash'    => __( 'No Plugin Archives found in trash', $this->get_text_domain() ),
					'parent_item_colon'     => __( 'Parent Plugin Archive:', $this->get_text_domain() ),
					'menu_name'             => __( 'Plugin Archives', $this->get_text_domain() ),
				),
				'public'                => false,
				'hierarchical'          => false,
				'show_ui'               => $this->admin->is_debug_mode_enabled(),
				'show_in_nav_menus'     => $this->admin->is_debug_mode_enabled(),
				'supports'              => array( 'title', 'editor', 'custom-fields', 'author' ),
				'has_archive'           => false,
				'rewrite'               => true,
				'query_var'             => true,
				'menu_position'         => null,
				'menu_icon'             => 'dashicons-analytics',
				'taxonomy'              => array( $this->get_archive_taxonomy_slug() ),
				'show_in_rest'          => $this->is_authorized_user(),
				'rest_base'             => $this->get_archive_post_type(),
				'rest_controller_class' => 'WP_REST_Posts_Controller',
			)
		);

		register_taxonomy(
			$this->get_archive_taxonomy_slug(),
			array( $this->get_archive_post_type() ),
			array(
				'labels'                => array(
					'name'                       => __( 'Archive Types', $this->get_text_domain() ),
					'singular_name'              => _x( 'Archive Type', 'taxonomy general name', $this->get_text_domain() ),
					'search_items'               => __( 'Search Archive Types', $this->get_text_domain() ),
					'popular_items'              => __( 'Popular Archive Types', $this->get_text_domain() ),
					'all_items'                  => __( 'All Archive Types', $this->get_text_domain() ),
					'parent_item'                => __( 'Parent Archive Type', $this->get_text_domain() ),
					'parent_item_colon'          => __( 'Parent Archive Type:', $this->get_text_domain() ),
					'edit_item'                  => __( 'Edit Archive Type', $this->get_text_domain() ),
					'update_item'                => __( 'Update Archive Type', $this->get_text_domain() ),
					'view_item'                  => __( 'View Archive Type', $this->get_text_domain() ),
					'add_new_item'               => __( 'Add New Archive Type', $this->get_text_domain() ),
					'new_item_name'              => __( 'New Archive Type', $this->get_text_domain() ),
					'separate_items_with_commas' => __( 'Separate Archive Types with commas', $this->get_text_domain() ),
					'add_or_remove_items'        => __( 'Add or remove Archive Types', $this->get_text_domain() ),
					'choose_from_most_used'      => __( 'Choose from the most used Archive Types', $this->get_text_domain() ),
					'not_found'                  => __( 'No Archive Types found.', $this->get_text_domain() ),
					'no_terms'                   => __( 'No Archive Types', $this->get_text_domain() ),
					'menu_name'                  => __( 'Archive Types', $this->get_text_domain() ),
					'items_list_navigation'      => __( 'Archive Types list navigation', $this->get_text_domain() ),
					'items_list'                 => __( 'Archive Types list', $this->get_text_domain() ),
					'most_used'                  => _x( 'Most Used', 'plugin-archive', $this->get_text_domain() ),
					'back_to_items'              => __( '&larr; Back to Archive Types', $this->get_text_domain() ),
				),
				'hierarchical'          => false,
				'public'                => false,
				'show_in_nav_menus'     => $this->admin->is_debug_mode_enabled(),
				'show_ui'               => $this->admin->is_debug_mode_enabled(),
				'show_admin_column'     => true,
				'query_var'             => true,
				'rewrite'               => true,
				'capabilities'          => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'manage_options',
				),
				'show_in_rest'          => $this->is_authorized_user(),
				'rest_base'             => $this->get_archive_taxonomy_slug(),
				'rest_controller_class' => 'WP_REST_Terms_Controller',
			)
		);
	}

	/**
	 * Get the path to the zip archive related to a generated zip of premium plugins.
	 *
	 * @param int|string $post_id ID of the premium plugins post.
	 *
	 * @return string|null Path to the zip of the premium plugins. Null if no zip exists.
	 */
	public function get_archive_zip_file( $post_id ) {
		if ( $this->get_archive_post_type() !== get_post_type( $post_id ) ) {
			return;
		}

		$zip_file_name = get_post_meta( $post_id, 'cshp_plugin_tracker_zip', true );

		if ( ! empty( $zip_file_name ) ) {
			$zip_path = sprintf( '%s/%s', $this->create_plugin_uploads_folder(), $zip_file_name );
			if ( $this->utilities->does_file_exists( $zip_path ) ) {
				return $zip_path;
			}
		}
	}

	/**
	 * Get the saved archived posts based on which plugins are included in that zip file.
	 *
	 * @param array $archived_plugins List of plugins to find an archive for.
	 *
	 * @return array|null Posts that have data for the zip archive of premium plugins.
	 */
	public function get_archive_post_by_contents( $archived_plugins ) {
		$query = new \WP_Query(
			array(
				'post_type'              => $this->get_archive_post_type(),
				'posts_per_page'         => 25,
				'post_status'            => 'private',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => $this->get_archive_taxonomy_slug(),
						'field'    => 'slug',
						'terms'    => $archived_plugins,
						'operator' => 'AND',
						// make sure we ae finding the posts that have all of these exact terms.
					),
				),
			)
		);

		if ( ! is_wp_error( $query ) && $query->have_posts() ) {
			return $query->posts;
		}
	}

	/**
	 * Get the saved archived post based on the name of the zip file.
	 *
	 * @param string $archive_zip_file_name Filename of the archive zip file (e.g. plugin-archive.zip)
	 *
	 * @return int|null Post that has the name of the zip file saved to it.
	 */
	public function get_archive_post_by_zip_filename( $archive_zip_file_name ) {
		$query = new \WP_Query(
			array(
				'post_type'              => $this->get_archive_post_type(),
				'posts_per_page'         => 1,
				'post_status'            => 'private',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => 'cshp_plugin_tracker_zip',
						'value'   => $archive_zip_file_name,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! is_wp_error( $query ) && $query->have_posts() ) {
			return $query->posts[0];
		}
	}

	/**
	 * Get the latest version of the saved archived zip file based on which plugins are included in that zip file.
	 *
	 * @param array $archived_plugins List  of plugins to find an archive for.
	 *
	 * @return string|void|null Path to the saved zip archive with the plugins saved.
	 */
	public function get_archive_zip_file_by_contents( $archived_plugins ) {
		$posts = $this->get_archive_post_by_contents( $archived_plugins );

		if ( ! empty( $posts ) ) {
			return $this->get_archive_zip_file( $posts[0]->ID );
		}
	}

	/**
	 * Get the list of plugin folders and plugin versions that were saved to the last generated premium plugins zip file.
	 *
	 * @param string $archive_zip_file_name_or_archive_zip_post Name of the zip file, post ID of the archive zip post object or the archive post object.
	 *
	 * @return false|array|null Name of plugin folders and versions that were saved to the last generated premium plugins
	 * zip file.
	 */
	public function get_premium_plugin_zip_file_contents( $archive_zip_file_name_or_archive_zip_post = '' ) {
		$plugin_zip_contents = array();

		if ( $archive_zip_file_name_or_archive_zip_post instanceof \WP_Post ) {
			$archive_zip_post = $archive_zip_file_name_or_archive_zip_post;
		} elseif ( is_int( $archive_zip_file_name_or_archive_zip_post ) ) {
			$archive_zip_post = new \WP_Query(
				array(
					'post_type'              => $this->get_archive_post_type(),
					'p'                      => $archive_zip_file_name_or_archive_zip_post,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'posts_per_page'         => 1,
				)
			);

			if ( ! is_wp_error( $archive_zip_post ) && $archive_zip_post->have_posts() ) {
				$archive_zip_post = $archive_zip_post->posts[0];
			}
		} elseif ( ! empty( $archive_zip_file_name_or_archive_zip_post ) && is_string( $archive_zip_file_name_or_archive_zip_post ) ) {
			$archive_zip_post = $this->get_archive_post_by_zip_filename( $archive_zip_file_name_or_archive_zip_post );
		}

		if ( ! empty( $archive_zip_post ) ) {
			$plugin_zip_contents = get_post_meta( $archive_zip_post->ID, 'cshp_plugin_tracker_archived_plugins', true );
			$plugin_zip_contents = json_decode( $plugin_zip_contents, true );
		}

		return $plugin_zip_contents;
	}

	/**
	 * Check if the zipped up plugins in an archive zip are old by comparing the version numbers of the zip plugins with the version numbers of the current plugins.
	 *
	 * @param int|\WP_Post $archive_post
	 *
	 * @return bool True if the archive is old and should be deleted. False if the archive is up to date.
	 */
	public function is_archive_zip_old( $archive_post ) {
		$plugins        = $this->get_premium_plugin_zip_file_contents( $archive_post );
		$is_archive_old = false;

		if ( ! empty( $plugins ) && is_array( $plugins ) ) {
			foreach ( $plugins as $plugin => $version ) {
				$plugin_data = get_plugins( '/' . $plugin );
				// if any saved plugin version is not the same as the current plugin version, the archive is old.

				if ( ! empty( $plugin_data ) ) {
					// traverse the plugin data since the key that is returned is the plugin file name
					$plugin_data = $plugin_data[ array_key_first( $plugin_data ) ];

					if ( ! empty( $plugin_data['Version'] ) && $plugin_data['Version'] !== $version ) {
						$is_archive_old = true;
						break;
					}
				}
			}
		}

		return $is_archive_old;
	}

	/**
	 * Limit the number of zip archive post types that can exists on the site.
	 *
	 * This will help to prevent the zip archive post type from eating up too much space since we should rarely need to audit
	 * the posts.
	 *
	 * @param int      $post_id ID of the post that was just added.
	 * @param \WP_Post $post Post object of the post that was just added.
	 * @param bool     $update Whether the post was being updated.
	 *
	 * @return void
	 */
	public function limit_archive_post_type( $post_id, $post, $update ) {
		if ( $this->get_archive_post_type() !== get_post_type( $post ) ) {
			return;
		}

		$posts_count = wp_count_posts( $this->get_archive_post_type() );

		if ( is_object( $posts_count ) && isset( $posts_count->private ) && 25 < absint( $posts_count->private ) ) {
			$query = new \WP_Query(
				array(
					'post_type'              => $this->get_archive_post_type(),
					'posts_per_page'         => 11,
					'offset'                 => 11,
					'post_status'            => 'private',
					'order'                  => 'ASC',
					'orderby'                => 'date',
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			if ( ! is_wp_error( $query ) && ! empty( $query->posts ) ) {
				foreach ( $query->posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}

			$query->reset_postdata();
		}//end if
	}

	/**
	 * Delete the zip archive of premium plugin file when the corresponding post is deleted.
	 *
	 * @param  int      $post_id  ID of the post that is deleted.
	 * @param  \WP_Post $post Post that was just deleted.
	 *
	 * @return void
	 */
	public function delete_archive_file( $post_id, $post ) {
		if ( $this->get_archive_post_type() !== get_post_type( $post ) ) {
			return;
		}

		wp_delete_file( $this->get_archive_zip_file( $post_id ) );
	}

	/**
	 * Delete the old versions of archived posts and archive files when they are out of date versus the currently installed versions.
	 *
	 * This occurs during a cron job.
	 *
	 * @return void
	 */
	public function delete_old_archive_posts() {
		$plugins            = get_plugins();
		$plugin_folder_data = array();
		foreach ( $plugins as $plugin_folder_file => $plugin_data ) {
			$plugin_folder_name                        = $this->extract_plugin_folder_name_by_plugin_file_name( $plugin_folder_file );
			$plugin_folder_data[ $plugin_folder_name ] = $plugin_data;
		}

		$archive_posts = new \WP_Query(
			array(
				'post_type'              => $this->get_archive_post_type(),
				'post_status'            => 'private',
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		if ( $archive_posts->have_posts() ) {
			foreach ( $archive_posts->posts as $post_id ) {
				$saved_plugin_data = get_post_meta( $post_id, 'cshp_plugin_tracker_archived_plugins', true );
				$should_delete     = false;

				try {
					// throw an exception if we cannot decode the JSON data and
					// set a maximum depth of 2 since we know our plugins are only stored as folder name + version
					$saved_plugin_data = json_decode( $saved_plugin_data, true, 2, JSON_THROW_ON_ERROR );

					if ( empty( $saved_plugin_data ) ) {
						$should_delete = true;
					} else {
						foreach ( $saved_plugin_data as $plugin_folder_name => $version ) {
							// if an archive has a plugin that is no longer installed, delete it.
							if ( ! isset( $plugin_folder_data[ $plugin_folder_name ] ) ) {
								$should_delete = true;
								break;
							}

							// if the plugin version that is archived is not the same version that is currently installed, delete it.
							if ( $version !== $plugin_folder_data[ $plugin_folder_name ]['Version'] ) {
								$should_delete = true;
								break;
							}
						}
					}
				} catch ( \Exception $e ) { //phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// if we cannot determine what plugins are in this zip based off of a funky or unsaved
					$should_delete = true;
				}

				$should_delete && wp_delete_post( $post_id, true );
			}//end foreach
		}//end if
	}
}
