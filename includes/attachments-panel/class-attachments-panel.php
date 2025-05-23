<?php
/**
 * PRC Attachments Inspector
 *
 * @package PRC\Platform\Attachments_Inspector
 */

namespace PRC\Platform\Attachments_Inspector;

use WP_Error;
use WP_REST_Request;
use WP_Query;

/**
 * The attachments panel class.
 */
class Attachments_Panel {

	/**
	 * The handle for the attachments panel editor asset.
	 *
	 * @var string
	 */
	public static $handle = 'prc-platform-attachments-panel';

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
			$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_block_plugin_assets' );
			$loader->add_filter( 'prc_api_endpoints', $this, 'register_endpoint' );
		}
	}

	/**
	 * Register the assets for the attachments panel.
	 *
	 * @since    1.0.0
	 * @return   bool|WP_Error
	 */
	public function register_assets() {
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
		global $current_screen;
		if ( $current_screen->base === 'site-editor' ) {
			return;
		}
		$registered = $this->register_assets();
		if ( is_admin() && ! is_wp_error( $registered ) ) {
			wp_enqueue_script( self::$handle );
			wp_enqueue_style( self::$handle );
		}
	}

	/**
	 * Register the endpoint for the attachments panel.
	 *
	 * @since    1.0.0
	 * @param    array $endpoints The endpoints.
	 * @return   array
	 */
	public function register_endpoint( $endpoints ) {
		array_push(
			$endpoints,
			array(
				'route'               => '/attachments-panel/get/(?P<post_id>\d+)',
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_attachments_restfully' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		return $endpoints;
	}

	/**
	 * Get the attachments by post ID.
	 *
	 * @since    1.0.0
	 * @param    int $post_id The post ID.
	 * @return   array
	 */
	public function get_attachments_restfully( $request ) {
		$post_id = $request->get_param( 'post_id' );
		return $this->get_attachments_by_post_id( $post_id );
	}

	/**
	 * Get the attachments by post ID.
	 *
	 * @since    1.0.0
	 * @param    int $post_id The post ID.
	 * @return   array
	 */
	public function get_attachments_by_post_id( $post_id ) {
		$media_assets      = array();
		$attachments_query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
				'posts_per_page' => 50,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);

		if ( $attachments_query->have_posts() ) {
			while ( $attachments_query->have_posts() ) {
				$attachments_query->the_post();
				$media_assets[] = array(
					'id'             => get_the_ID(),
					'title'          => get_the_title(),
					'type'           => get_post_mime_type(),
					'filename'       => basename( get_attached_file( get_the_ID() ) ),
					'editLink'       => get_edit_post_link( get_the_ID() ),
					'attachmentLink' => get_attachment_link( get_the_ID() ),
					'url'            => wp_get_attachment_image_src( get_the_ID(), 'large' )[0], // Why large? Because we don't need the absolute raw image for our preview purposes.
					'alt'            => get_post_meta( get_the_ID(), '_wp_attachment_image_alt', true ),
					'caption'        => get_the_content(),
				);
			}
		}

		return $media_assets;
	}
}
