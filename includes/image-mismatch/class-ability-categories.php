<?php
/**
 * Attachments Inspector ability category registration.
 *
 * @package PRC\Platform\Attachments_Inspector
 */

declare(strict_types=1);

namespace PRC\Platform\Attachments_Inspector;

/**
 * Registers the ability category for attachments-inspector tools.
 */
class Ability_Categories {

	/**
	 * Ability category slug.
	 */
	public const CATEGORY = 'attachments-inspector';

	/**
	 * Constructor.
	 *
	 * @param object $loader Plugin loader.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'wp_abilities_api_categories_init', $this, 'register_categories' );
	}

	/**
	 * Register the Attachments Inspector ability category.
	 *
	 * @hook wp_abilities_api_categories_init
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Attachments Inspector', 'prc-attachments-inspector' ),
				'description' => __( 'Abilities for inspecting and reporting image attachment mismatches.', 'prc-attachments-inspector' ),
			)
		);
	}
}
