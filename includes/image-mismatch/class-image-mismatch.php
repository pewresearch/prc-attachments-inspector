<?php
/**
 * PRC Attachments Inspector – Image Mismatch
 *
 * Surfaces image mismatches via:
 *
 *  1. A "Fix Mismatch" toolbar button in the block editor (via editor JS asset).
 *     - Case A: legacy image URL with a matching post-attached filename.
 *     - Case B: block id missing/invalid or attachment filename ≠ src filename;
 *       find in media library or import from URL (editor-only).
 *  2. A frontend admin bar alert for Case A and Case B, linking to the post editor.
 *
 * Detection is shared via Image_Mismatch_Scanner (also used by CLI + Ability).
 *
 * @package PRC\Platform\Attachments_Inspector
 */

declare(strict_types=1);

namespace PRC\Platform\Attachments_Inspector;

use WP_Error;
use WP_Admin_Bar;
use WP_REST_Request;

/**
 * Image Mismatch module.
 */
class Image_Mismatch {

	/**
	 * Script/style handle for the editor asset.
	 *
	 * @var string
	 */
	public static string $handle = 'prc-platform-image-mismatch';

	/**
	 * Max attachments returned by the find endpoint.
	 *
	 * @var int
	 */
	private const FIND_RESULT_LIMIT = 20;

	/**
	 * Shared scanner instance.
	 *
	 * @var Image_Mismatch_Scanner
	 */
	private Image_Mismatch_Scanner $scanner;

	/**
	 * Constructor.
	 *
	 * @param object                      $loader  Plugin loader instance.
	 * @param Image_Mismatch_Scanner|null $scanner Shared scanner (created when null).
	 */
	public function __construct( $loader, ?Image_Mismatch_Scanner $scanner = null ) {
		$this->scanner = $scanner ?? new Image_Mismatch_Scanner();
		$this->init( $loader );
	}

	/**
	 * Register all hooks.
	 *
	 * @param object|null $loader Plugin loader.
	 */
	public function init( $loader = null ): void {
		if ( null === $loader ) {
			return;
		}
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_editor_assets' );
		$loader->add_action( 'admin_bar_menu', $this, 'add_admin_bar_node', 100 );
		$loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Editor asset registration
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Register the editor JS + CSS assets.
	 *
	 * @return bool|WP_Error
	 */
	public function register_assets(): bool|WP_Error {
		$asset_file = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return new WP_Error(
				self::$handle,
				sprintf(
					'Asset file not found: %s — run `npm run build` for prc-attachments-inspector.',
					$asset_file
				)
			);
		}

		$asset    = include $asset_file;
		$base_url = plugin_dir_url( __FILE__ );

		$registered_script = wp_register_script(
			self::$handle,
			$base_url . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$registered_style = wp_register_style(
			self::$handle,
			$base_url . 'build/style-index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		if ( ! $registered_script || ! $registered_style ) {
			return new WP_Error( self::$handle, 'Failed to register image-mismatch assets.' );
		}

		return true;
	}

	/**
	 * Enqueue editor assets.
	 *
	 * Skips the site editor (FSE) context — mismatches only exist in classic post content.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public function enqueue_editor_assets(): void {
		global $current_screen;
		if ( isset( $current_screen->base ) && 'site-editor' === $current_screen->base ) {
			return;
		}

		$registered = $this->register_assets();
		if ( ! is_wp_error( $registered ) ) {
			wp_enqueue_script( self::$handle );
			wp_enqueue_style( self::$handle );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// REST routes
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Register find + import REST routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'prc-api/v3',
			'/image-mismatch/find',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_find_attachments' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
				'args'                => array(
					'post_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'url'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'filename' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'prc-api/v3',
			'/image-mismatch/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_import_from_url' ),
				'permission_callback' => array( $this, 'rest_import_permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'url'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Permission check for mismatch find endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function rest_permission_check( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to edit posts.', 'prc-attachments-inspector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden_post',
				__( 'Sorry, you are not allowed to edit this post.', 'prc-attachments-inspector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Permission check for mismatch import endpoint.
	 *
	 * Requires upload_files in addition to post edit caps — sideload creates
	 * a media library attachment, matching core POST /wp/v2/media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function rest_import_permission_check( WP_REST_Request $request ) {
		$can_edit = $this->rest_permission_check( $request );
		if ( true !== $can_edit ) {
			return $can_edit;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				__( 'Sorry, you are not allowed to upload files.', 'prc-attachments-inspector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Find media-library attachments matching a URL or filename.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_find_attachments( WP_REST_Request $request ) {
		$url      = (string) $request->get_param( 'url' );
		$filename = (string) $request->get_param( 'filename' );

		$normalized = '';
		if ( ! empty( $filename ) ) {
			$normalized = $this->scanner->normalize_filename( $filename );
		} elseif ( ! empty( $url ) ) {
			$normalized = $this->scanner->normalize_filename( $url );
		}

		if ( empty( $normalized ) ) {
			return new WP_Error(
				'missing_lookup',
				__( 'A url or filename is required.', 'prc-attachments-inspector' ),
				array( 'status' => 400 )
			);
		}

		$matches = array();
		$seen    = array();

		// Prefer exact URL → attachment resolution when a URL is provided.
		if ( ! empty( $url ) ) {
			$by_url = (int) attachment_url_to_postid( $url );
			if ( $by_url > 0 && wp_attachment_is_image( $by_url ) ) {
				$payload = $this->format_attachment_payload( $by_url );
				// Require the same basename match used by the filename query path.
				if (
					$payload &&
					$this->scanner->normalize_filename( $payload['filename'] ) === $normalized
				) {
					$matches[]       = $payload;
					$seen[ $by_url ] = true;
				}
			}
		}

		foreach ( $this->query_attachments_by_filename( $normalized ) as $attachment_id ) {
			if ( isset( $seen[ $attachment_id ] ) ) {
				continue;
			}
			$payload = $this->format_attachment_payload( $attachment_id );
			if ( ! $payload ) {
				continue;
			}
			// Double-check with the same normalizer (handles -WxH / case).
			if ( $this->scanner->normalize_filename( $payload['filename'] ) !== $normalized ) {
				continue;
			}
			$matches[]               = $payload;
			$seen[ $attachment_id ] = true;
			if ( count( $matches ) >= self::FIND_RESULT_LIMIT ) {
				break;
			}
		}

		return rest_ensure_response(
			array(
				'filename' => $normalized,
				'matches'  => $matches,
			)
		);
	}

	/**
	 * Download an image from a URL and sideload it into the media library,
	 * parented to the given post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_import_from_url( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$url     = (string) $request->get_param( 'url' );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error(
				'invalid_post',
				__( 'Invalid post ID.', 'prc-attachments-inspector' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $url ) || ! $this->is_allowed_import_url( $url ) ) {
			return new WP_Error(
				'invalid_import_url',
				__( 'URL host is not allowed for import.', 'prc-attachments-inspector' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Re-check the allowlist on every HTTP hop; download_url follows redirects.
		$allowlist_guard = function ( $preempt, $args, $request_url ) {
			if ( ! $this->is_allowed_import_url( (string) $request_url ) ) {
				return new WP_Error(
					'invalid_import_url',
					__( 'URL host is not allowed for import.', 'prc-attachments-inspector' ),
					array( 'status' => 400 )
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $allowlist_guard, 10, 3 );
		try {
			$tmp = download_url( $url, 30 );
		} finally {
			remove_filter( 'pre_http_request', $allowlist_guard, 10 );
		}

		if ( is_wp_error( $tmp ) ) {
			$error_code = $tmp->get_error_code();
			$status     = 'invalid_import_url' === $error_code ? 400 : 502;
			return new WP_Error(
				$error_code ? $error_code : 'download_failed',
				$tmp->get_error_message(),
				array( 'status' => $status )
			);
		}

		$filename = $this->scanner->normalize_filename( $url );
		if ( empty( $filename ) ) {
			$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}
		if ( empty( $filename ) ) {
			$filename = 'imported-image.jpg';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'sideload_failed',
				$attachment_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$payload = $this->format_attachment_payload( (int) $attachment_id );
		if ( ! $payload ) {
			return new WP_Error(
				'import_payload_failed',
				__( 'Imported attachment but failed to format response.', 'prc-attachments-inspector' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'attachment' => $payload,
			)
		);
	}

	/**
	 * Query attachment IDs whose attached file basename matches.
	 *
	 * @param string $normalized_filename Lowercase basename without resize suffix.
	 * @return int[]
	 */
	private function query_attachments_by_filename( string $normalized_filename ): array {
		global $wpdb;

		if ( empty( $normalized_filename ) ) {
			return array();
		}

		// Basename boundary: exact path or ".../filename" (not a shared suffix).
		$like = '%/' . $wpdb->esc_like( $normalized_filename );

		// Also match WP big-image "-scaled" attached files for the same basename.
		$scaled_filename = (string) preg_replace( '/(\.[^.]+)$/', '-scaled$1', $normalized_filename );
		$like_scaled     = '%/' . $wpdb->esc_like( $scaled_filename );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type LIKE %s
					AND pm.meta_key = '_wp_attached_file'
					AND (
						pm.meta_value LIKE %s
						OR pm.meta_value = %s
						OR pm.meta_value LIKE %s
						OR pm.meta_value = %s
					)
				ORDER BY p.ID DESC
				LIMIT %d",
				'image/%',
				$like,
				$normalized_filename,
				$like_scaled,
				$scaled_filename,
				self::FIND_RESULT_LIMIT * 3
			)
		);

		return array_map( 'intval', $ids ? $ids : array() );
	}

	/**
	 * Shape an attachment for the editor (matches attachments-panel payload).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|null
	 */
	private function format_attachment_payload( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		$src  = wp_get_attachment_image_src( $attachment_id, 'large' );

		return array(
			'id'             => $attachment_id,
			'title'          => get_the_title( $attachment_id ),
			'type'           => get_post_mime_type( $attachment_id ),
			'filename'       => $file ? basename( $file ) : '',
			'editLink'       => get_edit_post_link( $attachment_id, 'raw' ),
			'attachmentLink' => get_attachment_link( $attachment_id ),
			'url'            => is_array( $src ) ? $src[0] : wp_get_attachment_url( $attachment_id ),
			'alt'            => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'        => (string) get_post_field( 'post_excerpt', $attachment_id ),
			'post_parent'    => (int) wp_get_post_parent_id( $attachment_id ),
		);
	}

	/**
	 * Whether a remote URL host is allowed for sideload import.
	 *
	 * @param string $url Remote image URL.
	 * @return bool
	 */
	private function is_allowed_import_url( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( (string) $parsed['host'] );

		$allowed_suffixes = array(
			base64_decode( 'cGV3cmVzZWFyY2gub3Jn' ),
			'wpvip.com',
			'vipdev.lndo.site',
			'lndo.site',
		);

		foreach ( $allowed_suffixes as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return true;
			}
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $site_host ) && strtolower( $site_host ) === $host ) {
			return true;
		}

		/**
		 * Filter allowed import URL hosts for image-mismatch sideload.
		 *
		 * @param bool   $allowed Whether the host is allowed.
		 * @param string $host    Lowercased host.
		 * @param string $url     Full URL.
		 */
		return (bool) apply_filters( 'prc_attachments_inspector_allow_import_url', false, $host, $url );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Admin bar node
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Add an admin bar alert when the current singular post has Case A or Case B
	 * image mismatches.
	 *
	 * Conditions:
	 *  - Singular front-end view (not admin).
	 *  - Admin bar is showing.
	 *  - Current user can edit the post.
	 *
	 * @hook admin_bar_menu (priority 100)
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function add_admin_bar_node( WP_Admin_Bar $wp_admin_bar ): void {
		// Only on singular public front-end views.
		if ( is_admin() || ! is_singular() || ! is_admin_bar_showing() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$summary = $this->scanner->get_cached_mismatch_summary( $post_id );
		$count   = (int) $summary['case_a'] + (int) $summary['case_b'];

		if ( $count < 1 ) {
			return;
		}

		$title = sprintf(
			/* translators: %d: number of mismatched images */
			_n(
				'<span class="ab-icon dashicons dashicons-warning"></span> %d Image Mismatch',
				'<span class="ab-icon dashicons dashicons-warning"></span> %d Image Mismatches',
				$count,
				'prc-attachments-inspector'
			),
			$count
		);

		$filenames = implode( ', ', $summary['mismatched_files'] );
		$tooltip   = sprintf(
			/* translators: 1: Case A count, 2: Case B count, 3: comma-separated filenames */
			__( 'Case A: %1$d; Case B: %2$d. Files: %3$s', 'prc-attachments-inspector' ),
			(int) $summary['case_a'],
			(int) $summary['case_b'],
			$filenames ? $filenames : __( '(none)', 'prc-attachments-inspector' )
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'prc-image-mismatch',
				'title' => $title,
				'href'  => get_edit_post_link( $post_id ),
				'meta'  => array(
					'title' => $tooltip,
				),
			)
		);
	}
}
