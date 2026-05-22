<?php
/**
 * PRC Attachments Inspector – Image Mismatch
 *
 * Detects legacy image blocks (assets.pewresearch.org or /sites/N/ where N ≠ 20)
 * and surfaces:
 *
 *  1. A "Fix Mismatch" toolbar button in the block editor (via editor JS asset).
 *  2. A frontend admin bar alert linking to the post editor.
 *
 * @package PRC\Platform\Attachments_Inspector
 */

declare(strict_types=1);

namespace PRC\Platform\Attachments_Inspector;

use WP_Error;
use WP_Admin_Bar;
use WP_HTML_Tag_Processor;

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
	 * Constructor.
	 *
	 * @param object $loader Plugin loader instance.
	 */
	public function __construct( $loader ) {
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
	// URL detection helpers (PHP twin of detect-mismatch.js)
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Determine whether a URL points to a legacy image location.
	 *
	 * Legacy = assets.pewresearch.org host, OR /sites/N/ path where N ≠ 20.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function is_legacy_image_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		if ( 'assets.pewresearch.org' === $parsed['host'] ) {
			return true;
		}

		$path = $parsed['path'] ?? '';
		if ( preg_match( '#/sites/(\d+)/#', $path, $matches ) && '20' !== $matches[1] ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize a URL or filename to a bare lowercase filename, stripping
	 * WordPress thumbnail resize suffixes ("-WxH") before the extension.
	 *
	 * @param string $url_or_filename
	 * @return string
	 */
	private function normalize_filename( string $url_or_filename ): string {
		if ( empty( $url_or_filename ) ) {
			return '';
		}

		// Strip query string.
		$url_or_filename = strtok( $url_or_filename, '?' );

		// Take basename.
		$filename = basename( $url_or_filename );

		// Strip WP resize suffix: -WxH before the extension.
		$filename = preg_replace( '/-\d+x\d+(\.[^.]+)$/', '$1', $filename );

		return strtolower( $filename );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Block scanning
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract the image src from a core/image block.
	 *
	 * Prefers `attrs['url']` (set during block save); falls back to parsing the
	 * serialized HTML for the <img src> attribute via WP_HTML_Tag_Processor.
	 *
	 * @param array  $attrs         Block attributes.
	 * @param string $inner_html    Serialized block HTML.
	 * @return string|null
	 */
	private function extract_image_src( array $attrs, string $inner_html ): ?string {
		if ( ! empty( $attrs['url'] ) ) {
			return $attrs['url'];
		}

		// Fallback: parse inner HTML.
		$processor = new WP_HTML_Tag_Processor( $inner_html );
		if ( $processor->next_tag( 'img' ) ) {
			return $processor->get_attribute( 'src' );
		}

		return null;
	}

	/**
	 * Recursively walk parsed blocks and return all core/image blocks.
	 *
	 * @param array $blocks
	 * @return array
	 */
	private function collect_image_blocks( array $blocks ): array {
		$image_blocks = array();
		foreach ( $blocks as $block ) {
			if ( 'core/image' === $block['blockName'] ) {
				$image_blocks[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$image_blocks = array_merge(
					$image_blocks,
					$this->collect_image_blocks( $block['innerBlocks'] )
				);
			}
		}
		return $image_blocks;
	}

	/**
	 * Build (or fetch from transient) the list of legacy-but-matchable filenames
	 * for a given post.
	 *
	 * Cache key includes the post's modified time so it busts automatically
	 * on save without manual invalidation.
	 *
	 * @param int $post_id
	 * @return array Array of mismatched filenames, empty if none.
	 */
	private function get_mismatch_filenames( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$cache_key = 'prc_img_mismatch_' . $post_id . '_' . md5( $post->post_modified_gmt );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch post attachments (images only) and build normalized filename index.
		$raw_attachments = get_attached_media( 'image', $post_id );
		$attachment_filenames = array();
		foreach ( $raw_attachments as $attachment ) {
			$file = get_attached_file( $attachment->ID );
			if ( $file ) {
				$attachment_filenames[] = $this->normalize_filename( $file );
			}
		}

		if ( empty( $attachment_filenames ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		// Scan the post content for legacy image blocks with a matching filename.
		$blocks       = parse_blocks( $post->post_content );
		$image_blocks = $this->collect_image_blocks( $blocks );
		$mismatches   = array();

		foreach ( $image_blocks as $block ) {
			$attrs      = $block['attrs'] ?? array();
			$inner_html = implode( '', $block['innerContent'] );
			$src        = $this->extract_image_src( $attrs, $inner_html );

			if ( ! $src || ! $this->is_legacy_image_url( $src ) ) {
				continue;
			}

			$normalized_src = $this->normalize_filename( $src );
			if ( in_array( $normalized_src, $attachment_filenames, true ) ) {
				$mismatches[] = $normalized_src;
			}
		}

		$mismatches = array_unique( $mismatches );
		set_transient( $cache_key, $mismatches, HOUR_IN_SECONDS );

		return $mismatches;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Admin bar node
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Add an admin bar alert when the current singular post has legacy image
	 * mismatches that can be fixed.
	 *
	 * Conditions:
	 *  - Singular front-end view (not admin).
	 *  - Admin bar is showing.
	 *  - Current user can edit the post.
	 *
	 * @hook admin_bar_menu (priority 100)
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
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

		$mismatches = $this->get_mismatch_filenames( $post_id );

		if ( empty( $mismatches ) ) {
			return;
		}

		$count    = count( $mismatches );
		$title    = sprintf(
			/* translators: %d: number of mismatched images */
			_n(
				'<span class="ab-icon dashicons dashicons-warning"></span> %d Image Mismatch',
				'<span class="ab-icon dashicons dashicons-warning"></span> %d Image Mismatches',
				$count,
				'prc-attachments-inspector'
			),
			$count
		);
		$filenames = implode( ', ', $mismatches );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'prc-image-mismatch',
				'title' => $title,
				'href'  => get_edit_post_link( $post_id ),
				'meta'  => array(
					'title' => sprintf(
						/* translators: %s: comma-separated filenames */
						__( 'Legacy images with matching attachments: %s', 'prc-attachments-inspector' ),
						$filenames
					),
				),
			)
		);
	}
}
