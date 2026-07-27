<?php
/**
 * Shared image-mismatch scanner for admin bar, CLI, and Abilities API.
 *
 * Case A — legacy src with a matching post-attached filename.
 * Case B — block id missing/invalid or attachment filename ≠ src filename.
 * Case A takes precedence when both apply (matches the editor toolbar).
 *
 * @package PRC\Platform\Attachments_Inspector
 */

declare(strict_types=1);

namespace PRC\Platform\Attachments_Inspector;

use WP_HTML_Tag_Processor;

/**
 * Image mismatch scanner.
 */
class Image_Mismatch_Scanner {

	/**
	 * Default posts per batch for report scanning.
	 *
	 * @var int
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Hard max batch size for ability/CLI safety.
	 *
	 * @var int
	 */
	public const MAX_BATCH_SIZE = 50;

	/**
	 * Scan a single post for Case A / Case B image mismatches.
	 *
	 * @param int $post_id Post ID.
	 * @return array{
	 *   post_id: int,
	 *   title: string,
	 *   permalink: string,
	 *   edit_link: string,
	 *   image_blocks: int,
	 *   case_a: int,
	 *   case_b: int,
	 *   mismatched_files: string[],
	 *   mismatches: array<int, array<string, mixed>>
	 * }|array{} Empty array when the post does not exist.
	 */
	public function scan_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks       = parse_blocks( (string) $post->post_content );
		$image_blocks = $this->collect_image_blocks( $blocks );

		$attachment_filenames = $this->get_post_attachment_filenames( $post_id );
		$mismatches           = array();
		$case_a               = 0;
		$case_b               = 0;
		$mismatched_files     = array();

		foreach ( $image_blocks as $index => $block ) {
			$attrs      = $block['attrs'] ?? array();
			$inner_html = implode( '', $block['innerContent'] ?? array() );
			$src        = $this->extract_image_src( $attrs, $inner_html );
			$id         = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;

			if ( ! $src ) {
				continue;
			}

			$src_filename        = $this->normalize_filename( $src );
			$attachment_filename = '';
			$case                = null;

			// Case A: legacy src + matching filename among post attachments.
			if ( $this->is_legacy_image_url( $src ) && in_array( $src_filename, $attachment_filenames, true ) ) {
				$case = 'A';
				++$case_a;
			} elseif ( $this->is_id_src_filename_mismatch( $src, $id, $attachment_filename ) ) {
				// Case B: id missing/invalid or attachment filename ≠ src filename.
				$case = 'B';
				++$case_b;
			}

			if ( null === $case ) {
				continue;
			}

			if ( $src_filename ) {
				$mismatched_files[] = $src_filename;
			}

			$mismatches[] = array(
				'block_index'         => $index,
				'src'                 => $src,
				'id'                  => $id > 0 ? $id : null,
				'case'                => $case,
				'src_filename'        => $src_filename,
				'attachment_filename' => $attachment_filename,
			);
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		if ( ! is_string( $edit_link ) || '' === $edit_link ) {
			$edit_link = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}

		if ( empty( $mismatches ) ) {
			return array(
				'post_id'          => $post_id,
				'title'            => get_the_title( $post ),
				'permalink'        => get_permalink( $post_id ) ?: '',
				'edit_link'        => $edit_link,
				'image_blocks'     => count( $image_blocks ),
				'case_a'           => 0,
				'case_b'           => 0,
				'mismatched_files' => array(),
				'mismatches'       => array(),
			);
		}

		return array(
			'post_id'          => $post_id,
			'title'            => get_the_title( $post ),
			'permalink'        => get_permalink( $post_id ) ?: '',
			'edit_link'        => $edit_link,
			'image_blocks'     => count( $image_blocks ),
			'case_a'           => $case_a,
			'case_b'           => $case_b,
			'mismatched_files' => array_values( array_unique( $mismatched_files ) ),
			'mismatches'       => $mismatches,
		);
	}

	/**
	 * Scan a batch of candidate posts after a cursor.
	 *
	 * @param int    $cursor     Exclusive lower bound post ID (scan IDs > cursor).
	 * @param int    $batch_size Posts per batch (capped at MAX_BATCH_SIZE).
	 * @param string $post_type  Post type to scan.
	 * @return array{
	 *   results: array<int, array<string, mixed>>,
	 *   last_id: int,
	 *   scanned: int,
	 *   done: bool
	 * }
	 */
	public function scan_batch( int $cursor = 0, int $batch_size = self::DEFAULT_BATCH_SIZE, string $post_type = 'post' ): array {
		global $wpdb;

		$batch_size = max( 1, min( self::MAX_BATCH_SIZE, $batch_size ) );
		$cursor     = max( 0, $cursor );
		$post_type  = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			$post_type = 'post';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s
					AND post_status = 'publish'
					AND ID > %d
					AND post_content LIKE %s
				ORDER BY ID ASC
				LIMIT %d",
				$post_type,
				$cursor,
				'%<!-- wp:image%',
				$batch_size
			)
		);

		$ids = array_map( 'intval', $ids ? $ids : array() );
		$results = array();
		$last_id = $cursor;

		foreach ( $ids as $post_id ) {
			$last_id = $post_id;
			$summary = $this->scan_post( $post_id );
			if ( empty( $summary ) ) {
				continue;
			}
			if ( ( $summary['case_a'] ?? 0 ) < 1 && ( $summary['case_b'] ?? 0 ) < 1 ) {
				continue;
			}
			// Slim row for report output (omit per-block details unless needed later).
			$results[] = array(
				'post_id'          => $summary['post_id'],
				'title'            => $summary['title'],
				'permalink'        => $summary['permalink'],
				'edit_link'        => $summary['edit_link'],
				'image_blocks'     => $summary['image_blocks'],
				'case_a'           => $summary['case_a'],
				'case_b'           => $summary['case_b'],
				'mismatched_files' => $summary['mismatched_files'],
			);
		}

		return array(
			'results' => $results,
			'last_id' => $last_id,
			'scanned' => count( $ids ),
			'done'    => count( $ids ) < $batch_size,
		);
	}

	/**
	 * Cached scan summary for the admin bar.
	 *
	 * Cache key includes post_modified_gmt so it invalidates on save.
	 *
	 * @param int $post_id Post ID.
	 * @return array{case_a: int, case_b: int, mismatched_files: string[]}
	 */
	public function get_cached_mismatch_summary( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'case_a'           => 0,
				'case_b'           => 0,
				'mismatched_files' => array(),
			);
		}

		$cache_key = 'prc_img_mismatch_' . $post_id . '_' . md5( $post->post_modified_gmt );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) && isset( $cached['case_a'], $cached['case_b'] ) ) {
			return array(
				'case_a'           => (int) $cached['case_a'],
				'case_b'           => (int) $cached['case_b'],
				'mismatched_files' => array_values( (array) ( $cached['mismatched_files'] ?? array() ) ),
			);
		}

		$summary = $this->scan_post( $post_id );
		$result  = array(
			'case_a'           => (int) ( $summary['case_a'] ?? 0 ),
			'case_b'           => (int) ( $summary['case_b'] ?? 0 ),
			'mismatched_files' => array_values( (array) ( $summary['mismatched_files'] ?? array() ) ),
		);

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Whether a URL points to a legacy image location.
	 *
	 * Legacy = PRC assets CDN host, OR /sites/N/ path where N ≠ 20.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	public function is_legacy_image_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		if ( base64_decode( 'YXNzZXRzLnBld3Jlc2VhcmNoLm9yZw==' ) === $parsed['host'] ) {
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
	 * WordPress thumbnail resize suffixes ("-WxH") and big-image "-scaled"
	 * suffixes before the extension.
	 *
	 * @param string $url_or_filename URL or filename.
	 * @return string
	 */
	public function normalize_filename( string $url_or_filename ): string {
		if ( empty( $url_or_filename ) ) {
			return '';
		}

		$url_or_filename = strtok( $url_or_filename, '?' );
		if ( false === $url_or_filename ) {
			return '';
		}

		$filename = basename( $url_or_filename );
		$filename = preg_replace( '/-\d+x\d+(\.[^.]+)$/', '$1', $filename );
		$filename = preg_replace( '/-scaled(\.[^.]+)$/', '$1', $filename );

		return strtolower( (string) $filename );
	}

	/**
	 * Case B: src filename does not match the attachment referenced by id.
	 *
	 * @param string $src                 Block image URL.
	 * @param int    $id                  Block attachment id (0 when missing).
	 * @param string $attachment_filename Out: normalized attachment filename when resolved.
	 * @return bool
	 */
	public function is_id_src_filename_mismatch( string $src, int $id, string &$attachment_filename = '' ): bool {
		$attachment_filename = '';

		if ( empty( $src ) ) {
			return false;
		}

		$src_filename = $this->normalize_filename( $src );
		if ( '' === $src_filename ) {
			return true;
		}

		if ( $id <= 0 ) {
			return true;
		}

		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return true;
		}

		$file = get_attached_file( $id );
		if ( ! $file ) {
			$source_url = wp_get_attachment_url( $id );
			$attachment_filename = $source_url ? $this->normalize_filename( $source_url ) : '';
		} else {
			$attachment_filename = $this->normalize_filename( $file );
		}

		if ( '' === $attachment_filename ) {
			return true;
		}

		return $src_filename !== $attachment_filename;
	}

	/**
	 * Extract the image src from a core/image block.
	 *
	 * @param array  $attrs      Block attributes.
	 * @param string $inner_html Serialized block HTML.
	 * @return string|null
	 */
	public function extract_image_src( array $attrs, string $inner_html ): ?string {
		if ( ! empty( $attrs['url'] ) ) {
			return $attrs['url'];
		}

		$processor = new WP_HTML_Tag_Processor( $inner_html );
		if ( $processor->next_tag( 'img' ) ) {
			$src = $processor->get_attribute( 'src' );
			return is_string( $src ) ? $src : null;
		}

		return null;
	}

	/**
	 * Recursively collect core/image blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	public function collect_image_blocks( array $blocks ): array {
		$image_blocks = array();
		foreach ( $blocks as $block ) {
			if ( 'core/image' === ( $block['blockName'] ?? null ) ) {
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
	 * Normalized filenames for image attachments parented to the post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function get_post_attachment_filenames( int $post_id ): array {
		$raw_attachments = get_attached_media( 'image', $post_id );
		$filenames       = array();

		foreach ( $raw_attachments as $attachment ) {
			$file = get_attached_file( $attachment->ID );
			if ( $file ) {
				$filenames[] = $this->normalize_filename( $file );
			}
		}

		return array_values( array_unique( array_filter( $filenames ) ) );
	}
}
