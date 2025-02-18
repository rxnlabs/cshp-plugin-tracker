<?php
/**
 * Create a post type to store when someone or something downloads the Plugin and Theme archive zip files.
 *
 * Keep track of when a zip file archive is downloaded so we can audit it to make sure only legitimate downloads are taking place.
 */
declare( strict_types=1 );
namespace Cshp\Plugin\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class responsible for logging plugin tracker activities using a custom post type and taxonomy.
 */
class Logger {
	use Share;

	/**
	 * Taxonomy of the custom post type.
	 * @var string
	 */
	private $taxonomy_slug = 'cshp_pt_log_type';
	/**
	 * Slug of the custom post type.
	 * @var string
	 */
	private $post_type = 'cshp_pt_log';
	/**
	 * Utility instance providing various helper methods and functionalities.
	 *
	 * @var Utility
	 */
	private $utilities;
	/**
	 * Updater instance handles the update process for the application by managing version checks and applying updates.
	 *
	 * @var Updater
	 */
	private $updater;

	/**
	 * Admin instance responsible for managing the admin settings and interface.
	 *
	 * @var Admin
	 */
	private $admin;
	public function __construct( Utilities $utilities, Updater $updater ) {
		$this->utilities = $utilities;
		$this->updater   = $updater;
	}

	/**
	 * Get the taxonomy that will be used for a log
	 *
	 * @return string Taxonomy used for logging actions of the log post type.
	 */
	public function get_log_taxonomy() {
		return $this->taxonomy_slug;
	}

	/**
	 * Get the post type that will be used for a log
	 *
	 * @return string Name of the post type used for logging actions.
	 */
	public function get_log_post_type() {
		return $this->post_type;
	}

	public function hooks() {
		add_action( 'init', array( $this, 'create_log_post_type' ) );
		add_action( 'wp_insert_post', array( $this, 'limit_log_post_type' ), 10, 3 );
	}

	/**
	 * Create a setter method to prevent circular logic with dependency injection since the Admin class uses methods of the Logger class but Logger class depends on the admin class for DI.
	 *
	 * @param  Admin $admin Instance of the Admin class so we can use methods of the class without having a hard, circular dependency.
	 *
	 * @return void
	 */
	public function set_admin_instance( Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Retrieves the admin class instance.
	 *
	 * @return Admin The instance of the admin class.
	 * @throws \Exception If the plugin tracker class is not set.
	 */
	public function get_admin_instance() {
		if ( ! $this->admin instanceof Admin ) {
			throw new \Exception( 'Admin class not set' );
		}

		return $this->admin;
	}

	/**
	 * Register a post type for logging plugin tracker things.
	 */
	public function create_log_post_type() {
		$admin_instance = $this->get_admin_instance();
		register_post_type(
			$this->get_log_post_type(),
			array(
				'labels'                => array(
					'name'                  => __( 'Plugin Logs', $this->get_text_domain() ),
					'singular_name'         => __( 'Plugin Log', $this->get_text_domain() ),
					'all_items'             => __( 'All Plugin Logs', $this->get_text_domain() ),
					'archives'              => __( 'Plugin Log Archives', $this->get_text_domain() ),
					'attributes'            => __( 'Plugin Log Attributes', $this->get_text_domain() ),
					'insert_into_item'      => __( 'Insert into Plugin Log', $this->get_text_domain() ),
					'uploaded_to_this_item' => __( 'Uploaded to this Plugin Log', $this->get_text_domain() ),
					'featured_image'        => _x( 'Featured Image', 'cshp_pt_log', $this->get_text_domain() ),
					'set_featured_image'    => _x( 'Set featured image', 'cshp_pt_log', $this->get_text_domain() ),
					'remove_featured_image' => _x( 'Remove featured image', 'cshp_pt_log', $this->get_text_domain() ),
					'use_featured_image'    => _x( 'Use as featured image', 'cshp_pt_log', $this->get_text_domain() ),
					'filter_items_list'     => __( 'Filter Plugin Logs list', $this->get_text_domain() ),
					'items_list_navigation' => __( 'Plugin Logs list navigation', $this->get_text_domain() ),
					'items_list'            => __( 'Plugin Logs list', $this->get_text_domain() ),
					'new_item'              => __( 'New Plugin Log', $this->get_text_domain() ),
					'add_new'               => __( 'Add New', $this->get_text_domain() ),
					'add_new_item'          => __( 'Add New Plugin Log', $this->get_text_domain() ),
					'edit_item'             => __( 'Edit Plugin Log', $this->get_text_domain() ),
					'view_item'             => __( 'View Plugin Log', $this->get_text_domain() ),
					'view_items'            => __( 'View Plugin Logs', $this->get_text_domain() ),
					'search_items'          => __( 'Search Plugin Logs', $this->get_text_domain() ),
					'not_found'             => __( 'No Plugin Logs found', $this->get_text_domain() ),
					'not_found_in_trash'    => __( 'No Plugin Logs found in trash', $this->get_text_domain() ),
					'parent_item_colon'     => __( 'Parent Plugin Log:', $this->get_text_domain() ),
					'menu_name'             => __( 'Plugin Logs', $this->get_text_domain() ),
				),
				'public'                => false,
				'hierarchical'          => false,
				'show_ui'               => $admin_instance->is_debug_mode_enabled(),
				'show_in_nav_menus'     => $admin_instance->is_debug_mode_enabled(),
				'supports'              => array( 'title', 'editor', 'custom-fields', 'author' ),
				'has_archive'           => false,
				'rewrite'               => true,
				'query_var'             => true,
				'menu_position'         => null,
				'menu_icon'             => 'dashicons-analytics',
				'taxonomy'              => array( $this->get_log_taxonomy() ),
				'show_in_rest'          => $this->is_authorized_user(),
				'rest_base'             => $this->get_log_post_type(),
				'rest_controller_class' => 'WP_REST_Posts_Controller',
			)
		);

		register_taxonomy(
			$this->get_log_taxonomy(),
			array( $this->get_log_post_type() ),
			array(
				'labels'                => array(
					'name'                       => __( 'Log Types', $this->get_text_domain() ),
					'singular_name'              => _x( 'Log Type', 'taxonomy general name', $this->get_text_domain() ),
					'search_items'               => __( 'Search Log Types', $this->get_text_domain() ),
					'popular_items'              => __( 'Popular Log Types', $this->get_text_domain() ),
					'all_items'                  => __( 'All Log Types', $this->get_text_domain() ),
					'parent_item'                => __( 'Parent Log Type', $this->get_text_domain() ),
					'parent_item_colon'          => __( 'Parent Log Type:', $this->get_text_domain() ),
					'edit_item'                  => __( 'Edit Log Type', $this->get_text_domain() ),
					'update_item'                => __( 'Update Log Type', $this->get_text_domain() ),
					'view_item'                  => __( 'View Log Type', $this->get_text_domain() ),
					'add_new_item'               => __( 'Add New Log Type', $this->get_text_domain() ),
					'new_item_name'              => __( 'New Log Type', $this->get_text_domain() ),
					'separate_items_with_commas' => __( 'Separate Log Types with commas', $this->get_text_domain() ),
					'add_or_remove_items'        => __( 'Add or remove Log Types', $this->get_text_domain() ),
					'choose_from_most_used'      => __( 'Choose from the most used Log Types', $this->get_text_domain() ),
					'not_found'                  => __( 'No Log Types found.', $this->get_text_domain() ),
					'no_terms'                   => __( 'No Log Types', $this->get_text_domain() ),
					'menu_name'                  => __( 'Log Types', $this->get_text_domain() ),
					'items_list_navigation'      => __( 'Log Types list navigation', $this->get_text_domain() ),
					'items_list'                 => __( 'Log Types list', $this->get_text_domain() ),
					'most_used'                  => _x( 'Most Used', 'cshp_pt_log_type', $this->get_text_domain() ),
					'back_to_items'              => __( '&larr; Back to Log Types', $this->get_text_domain() ),
				),
				'hierarchical'          => false,
				'public'                => false,
				'show_in_nav_menus'     => $admin_instance->is_debug_mode_enabled(),
				'show_ui'               => $admin_instance->is_debug_mode_enabled(),
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
				'rest_base'             => $this->get_log_taxonomy(),
				'rest_controller_class' => 'WP_REST_Terms_Controller',
			)
		);

		$this->generate_default_log_terms();
	}

	/**
	 * Generate a default list of terms for the types of actions to log
	 *
	 * @return void
	 */
	public function generate_default_log_terms() {
		$terms = array(
			'tracker_file_create'        => __( 'Tracker File Create', $this->get_text_domain() ),
			'tracker_file_error'         => __( 'Tracker File Generate Error', $this->get_text_domain() ),
			'token_create'               => __( 'Token Create', $this->get_text_domain() ),
			'token_delete'               => __( 'Token Delete', $this->get_text_domain() ),
			'token_verify_fail'          => __( 'Token Verify Fail', $this->get_text_domain() ),
			'plugin_zip_create_start'    => __( 'Plugin Zip Create Start ', $this->get_text_domain() ),
			'plugin_zip_create_complete' => __( 'Plugin Zip Create Complete ', $this->get_text_domain() ),
			'theme_zip_create_start'     => __( 'Theme Zip Create Start', $this->get_text_domain() ),
			'theme_zip_create_complete'  => __( 'Theme Zip Create Complete', $this->get_text_domain() ),
			'plugin_zip_delete'          => __( 'Plugin Zip Delete', $this->get_text_domain() ),
			'theme_zip_delete'           => __( 'Theme Zip Delete', $this->get_text_domain() ),
			'plugin_zip_download'        => __( 'Plugin Zip Download', $this->get_text_domain() ),
			'theme_zip_download'         => __( 'Theme Zip Download', $this->get_text_domain() ),
			'plugin_zip_error'           => __( 'Plugin Zip Generate Error', $this->get_text_domain() ),
			'theme_zip_error'            => __( 'Theme Zip Generate Error', $this->get_text_domain() ),
			'plugin_activate'            => __( 'Plugin Activate', $this->get_text_domain() ),
			'plugin_deactivate'          => __( 'Plugin Deactivate', $this->get_text_domain() ),
			'plugin_uninstall'           => __( 'Plugin Uninstall', $this->get_text_domain() ),
			'theme_activate'             => __( 'Theme Activate', $this->get_text_domain() ),
			'theme_deactivate'           => __( 'Theme Deactivate', $this->get_text_domain() ),
			'theme_uninstall'            => __( 'Theme Uninstall', $this->get_text_domain() ),
			'plugin_zip_backup_error'    => __( 'Plugin Zip Backup Error', $this->get_text_domain() ),
			'plugin_zip_backup_complete' => __( 'Plugin Zip Backup Complete', $this->get_text_domain() ),
		);

		$count_terms = wp_count_terms(
			array(
				'taxonomy'   => $this->get_log_taxonomy(),
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $count_terms ) && absint( $count_terms ) !== count( $terms ) ) {
			foreach ( $terms as $slug => $name ) {
				if ( term_exists( $slug, $this->get_log_taxonomy() ) ) {
					continue;
				}

				wp_insert_term(
					$name,
					$this->get_log_taxonomy(),
					array(
						'slug' => $slug,
					)
				);
			}
		}
	}

	/**
	 * Get a list of the allowed actions that can be taken with the zip files and tokens
	 *
	 * @return array List of terms in the log taxonomy.
	 */
	public function get_allowed_log_types() {
		return get_terms(
			array(
				'taxonomy'   => $this->get_log_taxonomy(),
				'hide_empty' => false,
			)
		);
	}

	/**
	 * Log the times when actions are taken related to the plugin like generating a zip, creating a token,
	 * downloading the zip, etc...
	 *
	 * @param string $type Type of message to log. Should be slug of a valid the log custom taxonomy term.
	 * @param string $message Custom message to override the default message.
	 *
	 * @return int Post ID of the log post
	 */
	public function log_request( $type, $title = '', $message = '' ) {
		global $wp;
		$result_post_id = 0;
		$get_clean      = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $_GET as $key => $get ) {
				$get_clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $get );
			}
		}

		$request_url = add_query_arg( array_merge( $get_clean, $wp->query_vars ), home_url( $wp->request ) );
		$term_object = get_term_by( 'slug', $type, $this->get_log_taxonomy() );

		if ( ! empty( $title ) && ! empty( $term_object ) ) {
			$result_post_id = wp_insert_post(
				array(
					'post_type'    => $this->get_log_post_type(),
					'post_title'   => $title,
					'post_content' => wp_kses_post( $message ),
					'post_status'  => 'private',
					'post_author'  => is_user_logged_in() ? get_current_user_id() : 0,
					'tax_input'    => array(
						$term_object->taxonomy => array( $term_object->slug ),
					),
					'meta_input'   => array(
						'ip_address'   => $this->get_request_ip_address(),
						'geo_location' => $this->get_request_geolocation(),
						'url'          => $request_url,
					),
				)
			);

			// sometimes the term won't be added to the post on insert due to permissions of the logged-in user
			// https://wordpress.stackexchange.com/questions/210229/tax-input-not-working-wp-insert-post
			if ( ! is_wp_error( $result_post_id ) && ! empty( $result_post_id ) && ! has_term( $term_object->slug, $term_object->taxonomy, $result_post_id ) ) {
				wp_add_object_terms( $result_post_id, $term_object->term_id, $term_object->taxonomy );
			}
		}//end if

		return $result_post_id;
	}

	/**
	 * Get the easily spoofed, unreliable IP address of users who request an endpoint
	 *
	 * @return mixed IP address of the user/server/computer that pinged the site.
	 */
	public function get_request_ip_address() {
		$ip = __( 'IP address not found in request', $this->get_text_domain() );

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			// check ip from share internet
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// to check ip is pass from proxy
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		return $ip;
	}

	/**
	 * Try to get the location of the current user so we can log who is doing actions like adding plugins and themes
	 *
	 * @return string Location of the current user or empty string if no location can be found
	 */
	public function get_request_geolocation() {
		$city         = '';
		$region       = '';
		$country      = '';
		$country_code = '';
		$postal_code  = '';
		$location     = '';
		$ip_address   = $this->get_request_ip_address();

		// test if Kinsta has Geo IP tool enabled for this site
		if ( isset( $_SERVER['GEOIP_COUNTRY_NAME'] ) && ! empty( $_SERVER['GEOIP_COUNTRY_NAME'] ) ) {
			$country = sanitize_text_field( $_SERVER['GEOIP_COUNTRY_NAME'] );
		}

		if ( isset( $_SERVER['GEOIP_COUNTRY_CODE'] ) && ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
			$country_code = sanitize_text_field( $_SERVER['GEOIP_COUNTRY_CODE'] );
		}

		if ( isset( $_SERVER['GEOIP_REGION'] ) && ! empty( $_SERVER['GEOIP_REGION'] ) ) {
			$region = sanitize_text_field( $_SERVER['GEOIP_REGION'] );
		}

		if ( isset( $_SERVER['GEOIP_CITY'] ) && ! empty( $_SERVER['GEOIP_CITY'] ) ) {
			$city = sanitize_text_field( $_SERVER['GEOIP_CITY'] );
		}

		if ( isset( $_SERVER['GEOIP_POSTAL_CODE'] ) && ! empty( $_SERVER['GEOIP_POSTAL_CODE'] ) ) {
			$postal_code = sanitize_text_field( $_SERVER['GEOIP_POSTAL_CODE'] );
		}

		if ( ! empty( $ip_address ) && ( empty( $country ) ) ) {
			$url           = sprintf( 'https://ipapi.co/%s/json', $ip_address );
			$location_info = wp_remote_get( $url );

			if ( ! is_wp_error( $location_info ) && 200 === wp_remote_retrieve_response_code( $location_info ) ) {
				$response = json_decode( wp_remote_retrieve_body( $location_info ), true );

				if ( is_null( $response ) ) {
					$response = array();
				}

				if ( isset( $response['country_name'] ) && ! empty( $response['country_name'] ) ) {
					$country = sanitize_text_field( $response['country_name'] );
				}

				if ( isset( $response['country_code'] ) && ! empty( $response['country_code'] ) ) {
					$country_code = sanitize_text_field( $response['country_code'] );
				}

				if ( isset( $response['region'] ) && ! empty( $response['region'] ) ) {
					$region = sanitize_text_field( $response['region'] );
				}

				if ( isset( $response['city'] ) && ! empty( $response['city'] ) ) {
					$city = sanitize_text_field( $response['city'] );
				}

				if ( isset( $response['postal'] ) && ! empty( $response['postal'] ) ) {
					$postal_code = sanitize_text_field( $response['postal'] );
				}
			}//end if

			if ( empty( $country ) ) {
				$url           = sprintf( 'https://get.geojs.io/v1/ip/geo/%s.json', $ip_address );
				$location_info = wp_remote_get( $url );

				if ( ! is_wp_error( $location_info ) && 200 === wp_remote_retrieve_response_code( $location_info ) ) {
					$response = json_decode( wp_remote_retrieve_body( $location_info ), true );

					if ( is_null( $response ) ) {
						$response = array();
					}

					if ( isset( $response['country'] ) && ! empty( $response['country'] ) ) {
						$country = sanitize_text_field( $response['country'] );
					}

					if ( isset( $response['country_code'] ) && ! empty( $response['country_code'] ) ) {
						$country_code = sanitize_text_field( $response['country_code'] );
					}

					if ( isset( $response['region'] ) && ! empty( $response['region'] ) ) {
						$region = sanitize_text_field( $response['region'] );
					}

					if ( isset( $response['city'] ) && ! empty( $response['city'] ) ) {
						$city = sanitize_text_field( $response['city'] );
					}
				}//end if
			}//end if
		}//end if

		if ( ! empty( $country ) ) {
			$location = sprintf( '%s, %s, %s', $city, $region, $country );

			if ( ! empty( $postal_code ) ) {
				$location = sprintf( '%s, %s', $location, $postal_code );
			}
		}

		return $location;
	}

	/**
	 * Limit the number of log post types that can exists on the site.
	 *
	 * This will help to prevent the log post type from eating up too much space since we should rarely need to audit
	 * the posts.
	 *
	 * @param int      $post_id ID of the post that was just added.
	 * @param \WP_Post $post Post object of the post that was just added.
	 * @param bool     $update Whether the post was being updated.
	 *
	 * @return void
	 */
	public function limit_log_post_type( $post_id, $post, $update ) {
		if ( $this->get_log_post_type() !== get_post_type( $post ) ) {
			return;
		}

		$posts_count = wp_count_posts( $this->get_log_post_type() );

		if ( is_object( $posts_count ) && isset( $posts_count->private ) && 200 < absint( $posts_count->private ) ) {
			$query = new \WP_Query(
				array(
					'post_type'              => $this->get_log_post_type(),
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
}
