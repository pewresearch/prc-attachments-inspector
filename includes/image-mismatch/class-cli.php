<?php
/**
 * WP-CLI command: image mismatch report.
 *
 * @package PRC\Platform\Attachments_Inspector
 */

declare(strict_types=1);

namespace PRC\Platform\Attachments_Inspector;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

/**
 * Report posts with Case A / Case B image mismatches.
 *
 * ## EXAMPLES
 *
 *     wp prc attachments-inspector mismatch-report
 *     wp prc attachments-inspector mismatch-report --start-id=39700 --limit=100 --format=csv
 *
 * @when after_wp_load
 */
class CLI extends \WPCOM_VIP_CLI_Command {

	/**
	 * Scanner instance.
	 *
	 * @var Image_Mismatch_Scanner
	 */
	private Image_Mismatch_Scanner $scanner;

	/**
	 * Constructor.
	 *
	 * @param Image_Mismatch_Scanner|null $scanner Shared scanner.
	 */
	public function __construct( ?Image_Mismatch_Scanner $scanner = null ) {
		parent::__construct();
		$this->scanner = $scanner ?? new Image_Mismatch_Scanner();
	}

	/**
	 * Scan published posts for image mismatches and print a report.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_type>]
	 * : Post type to scan. Default: post
	 *
	 * [--batch-size=<n>]
	 * : Posts per batch (max 50). Default: 50
	 *
	 * [--start-id=<id>]
	 * : Resume from posts with ID greater than this value. Default: 0
	 *
	 * [--limit=<n>]
	 * : Max posts to scan (0 = all). Default: 0
	 *
	 * [--format=<format>]
	 * : Output format: table, csv, json, count. Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc attachments-inspector mismatch-report --format=csv
	 *     wp prc attachments-inspector mismatch-report --start-id=39700 --limit=100
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function mismatch_report( $args, $assoc_args ): void {
		$post_type  = isset( $assoc_args['post_type'] ) ? sanitize_key( (string) $assoc_args['post_type'] ) : 'post';
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : Image_Mismatch_Scanner::DEFAULT_BATCH_SIZE;
		$start_id   = isset( $assoc_args['start-id'] ) ? absint( $assoc_args['start-id'] ) : 0;
		$limit      = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		$format     = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( '' === $post_type ) {
			$post_type = 'post';
		}

		$batch_size = max( 1, min( Image_Mismatch_Scanner::MAX_BATCH_SIZE, $batch_size ) );
		$allowed_formats = array( 'table', 'csv', 'json', 'count' );
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			\WP_CLI::error( 'Invalid --format. Use table, csv, json, or count.' );
		}

		$this->start_bulk_operation();

		$cursor       = $start_id;
		$scanned_total = 0;
		$affected     = array();
		$done         = false;

		\WP_CLI::log(
			sprintf(
				'Scanning post_type=%s batch_size=%d start_id=%d limit=%s',
				$post_type,
				$batch_size,
				$start_id,
				$limit > 0 ? (string) $limit : 'all'
			)
		);

		do {
			$remaining = $limit > 0 ? max( 0, $limit - $scanned_total ) : $batch_size;
			if ( $limit > 0 && 0 === $remaining ) {
				$done = true;
				break;
			}

			$this_batch = $limit > 0 ? min( $batch_size, $remaining ) : $batch_size;
			$batch      = $this->scanner->scan_batch( $cursor, $this_batch, $post_type );

			$scanned_total += (int) $batch['scanned'];
			$cursor         = (int) $batch['last_id'];
			$done           = (bool) $batch['done'];

			foreach ( $batch['results'] as $row ) {
				$affected[] = array(
					'post_id'          => $row['post_id'],
					'title'            => $row['title'],
					'case_a'           => $row['case_a'],
					'case_b'           => $row['case_b'],
					'image_blocks'     => $row['image_blocks'],
					'mismatched_files' => is_array( $row['mismatched_files'] )
						? implode( '; ', $row['mismatched_files'] )
						: '',
					'edit_link'        => $row['edit_link'],
					'permalink'        => $row['permalink'],
				);
			}

			\WP_CLI::log(
				sprintf(
					'Batch complete: scanned=%d affected_so_far=%d last_id=%d done=%s',
					$batch['scanned'],
					count( $affected ),
					$cursor,
					$done ? 'yes' : 'no'
				)
			);

			$this->vip_inmemory_cleanup();
			sleep( 1 );
		} while ( ! $done );

		$this->end_bulk_operation();

		\WP_CLI::log(
			sprintf(
				'Scan finished. scanned=%d affected=%d last_id=%d (resume with --start-id=%d)',
				$scanned_total,
				count( $affected ),
				$cursor,
				$cursor
			)
		);

		if ( 'count' === $format ) {
			\WP_CLI::print_value( count( $affected ), array( 'format' => 'json' ) );
			return;
		}

		if ( empty( $affected ) ) {
			\WP_CLI::success( 'No mismatched posts found.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$affected,
			array(
				'post_id',
				'title',
				'case_a',
				'case_b',
				'image_blocks',
				'mismatched_files',
				'edit_link',
				'permalink',
			)
		);
	}
}
