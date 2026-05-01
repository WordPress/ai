<?php
/**
 * Image crop suggestion experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Suggest_Image_Crops;

use WordPress\AI\Abilities\Image\Suggest_Image_Crops as Suggest_Image_Crops_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image crop suggestion experiment.
 *
 * Provides AI-generated focal point and crop window suggestions for images.
 *
 * @since x.x.x
 */
class Suggest_Image_Crops extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'suggest-image-crops';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Image Crop Suggestions', 'ai' ),
			'description' => __( 'Suggests a focal point and crop windows for images using AI vision models. Requires an AI connector that includes support for vision-based image analysis models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the Suggest_Image_Crops Ability.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . self::get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Suggest_Image_Crops_Ability::class,
			),
		);
	}
}
