<?php
/**
 * Image mismatch scan ability for the WP Abilities API / MCP.
 *
 * @package PRC\Platform\Attachments_Inspector
 */

declare(strict_types=1);

namespace PRC\Platform\Attachments_Inspector;

/**
 * Registers prc-attachments-inspector/scan-image-mismatches.
 */
class Ability {

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public static string $ability_name = 'prc-attachments-inspector/scan-image-mismatches';

	/**
	 * Shared scanner.
	 *
	 * @var Image_Mismatch_Scanner
	 */
	private Image_Mismatch_Scanner $scanner;

	/**
	 * Constructor.
	 *
	 * @param object                      $loader  Plugin loader.
	 * @param Image_Mismatch_Scanner|null $scanner Shared scanner.
	 */
	public function __construct( $loader, ?Image_Mismatch_Scanner $scanner = null ) {
		$this->scanner = $scanner ?? new Image_Mismatch_Scanner();
		$loader->add_action( 'wp_abilities_api_init', $this, 'register_ability' );
	}

	/**
	 * Register the scan ability.
	 *
	 * @hook wp_abilities_api_init
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::$ability_name,
			array(
				'label'               => __( 'Scan image mismatches', 'prc-attachments-inspector' ),
				'description'         => __( 'Scans a batch of published posts for core/image mismatches. Case A: legacy src with a matching post-attached filename. Case B: attachment id is present but invalid, or attachment filename does not match src filename. A missing id is not a mismatch. Returns only affected posts plus a resume cursor.', 'prc-attachments-inspector' ),
				'category'            => Ability_Categories::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'cursor'     => array(
							'type'        => 'integer',
							'description' => 'Exclusive lower-bound post ID. Resume with last_id from the previous response. Default 0.',
							'minimum'     => 0,
							'default'     => 0,
						),
						'batch_size' => array(
							'type'        => 'integer',
							'description' => 'Posts to scan per call (max 50). Default 50.',
							'minimum'     => 1,
							'maximum'     => Image_Mismatch_Scanner::MAX_BATCH_SIZE,
							'default'     => Image_Mismatch_Scanner::DEFAULT_BATCH_SIZE,
						),
						'post_type'  => array(
							'type'        => 'string',
							'description' => 'Post type to scan. Default post.',
							'default'     => 'post',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'results' => array(
							'type'        => 'array',
							'description' => 'Affected posts only.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'post_id'          => array( 'type' => 'integer' ),
									'title'            => array( 'type' => 'string' ),
									'permalink'        => array( 'type' => 'string' ),
									'edit_link'        => array( 'type' => 'string' ),
									'image_blocks'     => array( 'type' => 'integer' ),
									'case_a'           => array( 'type' => 'integer' ),
									'case_b'           => array( 'type' => 'integer' ),
									'mismatched_files' => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
							),
						),
						'last_id' => array(
							'type'        => 'integer',
							'description' => 'Highest post ID scanned in this batch. Pass as cursor for the next call.',
						),
						'scanned' => array(
							'type'        => 'integer',
							'description' => 'Number of candidate posts scanned in this batch.',
						),
						'done'    => array(
							'type'        => 'boolean',
							'description' => 'True when no further candidate posts remain after last_id.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_scan' ),
				'meta'                => array(
					'annotations'  => array(
						'instructions' => 'Call repeatedly with cursor=last_id until done=true. Each call scans up to batch_size published posts that contain core/image blocks. results only includes posts with Case A and/or Case B mismatches. Requires edit_posts.',
						'readonly'     => true,
						'destructive'  => false,
						'idempotent'   => true,
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_scan( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Execute one batch scan.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$cursor     = isset( $input['cursor'] ) ? absint( $input['cursor'] ) : 0;
		$batch_size = isset( $input['batch_size'] )
			? absint( $input['batch_size'] )
			: Image_Mismatch_Scanner::DEFAULT_BATCH_SIZE;
		$post_type  = isset( $input['post_type'] )
			? sanitize_key( (string) $input['post_type'] )
			: 'post';

		if ( '' === $post_type ) {
			$post_type = 'post';
		}

		return $this->scanner->scan_batch( $cursor, $batch_size, $post_type );
	}
}
