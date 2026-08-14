<?php
/**
 * PRC Attachments Inspector
 *
 * @package PRC\Platform\Attachments_Inspector
 */

namespace PRC\Platform\Attachments_Inspector;

use WP_Error;
use WP_REST_Request;

/**
 * The attachment report class.
 */
class Attachment_Report {

	/**
	 * The handle for the attachment report editor asset.
	 *
	 * @var string
	 */
	public static $handle = 'prc-platform-attachment-report';

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $loader       The loader object.
	 */
	public function __construct( $loader ) {
		$this->init( $loader );
	}

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $loader       The loader object.
	 */
	public function init( $loader = null ) {
		if ( null !== $loader ) {
			$loader->add_filter( 'query_vars', $this, 'register_query_vars' );
			$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_frontend_assets' );
			$loader->add_filter( 'the_content', $this, 'add_report_to_content' );
			$loader->add_action( 'admin_enqueue_scripts', $this, 'register_assets' );
			$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_dataviews_assets', 25 );
			$loader->add_action( 'rest_api_init', $this, 'register_endpoint' );
		}
	}

	/**
	 * Load the attachments report UI on shared DataViews admin lists.
	 *
	 * @param string $hook_suffix Admin hook.
	 */
	public function enqueue_dataviews_assets( $hook_suffix ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! wp_script_is( 'prc-wp-admin-dataview', 'enqueued' ) ) {
			return;
		}

		// Priority-10 register_assets usually already registered this handle.
		// wp_register_script() returns false when present, so do not treat that
		// as failure — just ensure the shell dependency and enqueue.
		$registered = $this->register_assets();
		if ( is_wp_error( $registered ) ) {
			return;
		}

		$script = wp_scripts()->query( self::$handle );
		if ( is_object( $script ) && ! in_array( 'prc-wp-admin-dataview', $script->deps, true ) ) {
			$script->deps[] = 'prc-wp-admin-dataview';
		}

		wp_enqueue_script( self::$handle );
		wp_enqueue_style( self::$handle );
	}

	/**
	 * Register the query vars.
	 *
	 * @since    1.0.0
	 * @param    array $vars The query vars.
	 * @return   array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'attachmentsReport';
		return $vars;
	}

	/**
	 * Register the assets.
	 *
	 * @since    1.0.0
	 */
	public function register_assets() {
		// Already registered (e.g. priority-10 admin_enqueue + later callers).
		if ( wp_script_is( self::$handle, 'registered' ) && wp_style_is( self::$handle, 'registered' ) ) {
			return true;
		}

		$asset_file = include plugin_dir_path( __FILE__ ) . 'build/index.asset.php';
		$asset_slug = self::$handle;
		$script_src = plugin_dir_url( __FILE__ ) . 'build/index.js';
		$style_src  = plugin_dir_url( __FILE__ ) . 'build/style-index.css';

		$script_dependencies = array_merge(
			$asset_file['dependencies'],
			array( 'media-editor' )
		);

		$script = wp_register_script(
			$asset_slug,
			$script_src,
			$script_dependencies,
			$asset_file['version'],
			true
		);

		$style = wp_register_style(
			$asset_slug,
			$style_src,
			array( 'wp-components' ),
			$asset_file['version']
		);

		if ( ! $script || ! $style ) {
			return new WP_Error( self::$handle, 'Failed to register all assets' );
		}

		return true;
	}

	/**
	 * Enqueue the block plugin assets.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_block_plugin_assets() {
		$registered = $this->register_assets();
		if ( is_admin() && ! is_wp_error( $registered ) ) {
			wp_enqueue_script( self::$handle );
			wp_enqueue_style( self::$handle );
		}
	}

	/**
	 * Enqueue the frontend assets.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_frontend_assets() {
		$registered = $this->register_assets();
		if ( ! is_admin() && ! is_wp_error( $registered ) && get_query_var(
			'attachmentsReport',
			false
		) ) {
			wp_enqueue_script( self::$handle );
			wp_enqueue_style( self::$handle );
		}
	}

	/**
	 * Add the attachment report to the content.
	 *
	 * @since    1.0.0
	 * @param    string $post_content The post content.
	 * @return   string
	 */
	public function add_report_to_content( $post_content ) {
		if ( get_query_var( 'attachmentsReport', false ) ) {
			$post_id      = get_the_ID();
			$post_type    = get_post_type( $post_id );
			$post_content = '<div id="js-prc-attachments-report-frontend" data-postType="' . $post_type . '" data-postId="' . $post_id . '"></div>';
		}
		return $post_content;
	}

	/**
	 * Adds the attachment report endpoint to the REST API
	 *
	 * @hook rest_api_init
	 */
	/**
	 * @hook rest_api_init
	 */
	public function register_endpoint() {
		register_rest_route(
			'prc-api/v3',
			'/attachments-report/get/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_attachments_restfully' ),
				'args'                => array(
					'mime_type'        => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_string( $param );
						},
						'default'           => 'all',
					),
					'include_children' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_bool( $param );
						},
						'default'           => true,
					),
				),
				'permission_callback' => function () {
					return true;
				},
			)
		);
	}

	/**
	 * Returns an array of attachments, including their caption, description, and alt text for a given post object.
	 *
	 * @param mixed $post_id
	 * @param mixed $mime_type
	 * @return array
	 */
	public function get_attachments_by_post_id( $post_id, $mime_type = 'image' ) {
		$attachments = get_attached_media( 'all' === $mime_type ? '' : $mime_type, $post_id );
		if ( empty( $attachments ) ) {
			return array();
		}

		$new_attachments = array();
		foreach ( $attachments as $attachment ) {
			if ( ! str_starts_with( $attachment->post_mime_type, 'image/' ) ) {
				continue;
			}

			$meta   = get_post_meta( $attachment->ID, '_wp_attachment_metadata', true );
			$width  = 0;
			$height = 0;
			if ( ! empty( $meta ) ) {
				$width  = array_key_exists( 'width', $meta ) ? $meta['width'] : null;
				$height = array_key_exists( 'height', $meta ) ? $meta['height'] : null;
			}
			$new_attachments[] = array(
				'id'           => $attachment->ID,
				'type'         => 'image',
				'title'        => $attachment->post_title,
				'caption'      => $attachment->post_excerpt,
				'description'  => $attachment->post_content,
				'alt'          => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'mimeType'     => $attachment->post_mime_type,
				'url'          => wp_get_attachment_url( $attachment->ID ),
				'thumbnailUrl' => wp_get_attachment_thumb_url( $attachment->ID ),
				'squareUrl'    => wp_get_attachment_image_url( $attachment->ID, 'square' ),
				'width'        => $width,
				'height'       => $height,
				'owner'        => $post_id,
			);
		}
		return $new_attachments;
	}

	/**
	 * Returns an array of attachments, including their caption, description, and alt text for a given post object.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return array
	 */
	public function get_attachments_restfully( WP_REST_Request $request ) {
		$post_id          = $request->get_param( 'post_id' );
		$mime_type        = $request->get_param( 'mime_type' );
		$include_children = $request->get_param( 'include_children' );

		$post_id = (int) $post_id;
		// Is this a parent post or child post, if so change the post_id to the parent post.
		$post_parent = wp_get_post_parent_id( $post_id );
		if ( 0 !== $post_parent && false !== $post_parent ) {
			$post_id = $post_parent;
		}

		$attachments = $this->get_attachments_by_post_id( $post_id, $mime_type );

		if ( $include_children ) {
			$this_post_post_type = get_post_type( $post_id );
			$children            = get_children(
				array(
					'post_parent' => $post_id,
					'post_type'   => $this_post_post_type,
					'numberposts' => 25, // HARD LIMIT of 25 children
					'post_status' => array( 'publish', 'draft' ),
				) 
			);
			$child_attachments   = array();
			foreach ( $children as $child ) {
				$child_attachments[] = $this->get_attachments_by_post_id( $child->ID, $mime_type );
			}
			$attachments = array_merge( $attachments, ...$child_attachments );
		}

		/**
		 * Allow other plugins to append report items (e.g. chart-builder charts).
		 *
		 * @param array  $attachments Report items for the post.
		 * @param int    $post_id     Resolved parent post ID.
		 * @param string $mime_type   Requested mime type filter.
		 */
		$attachments = apply_filters(
			'prc_attachments_report_items',
			$attachments,
			$post_id,
			$mime_type
		);

		return array(
			'postTitle'   => get_the_title( $post_id ),
			'attachments' => $attachments,
		);
	}
}
