<?php
/**
 * Type Ahead experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Type_Ahead;

use WordPress\AI\Abilities\Type_Ahead\Type_Ahead as Type_Ahead_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Type Ahead experiment.
 *
 * @since x.x.x
 */
class Type_Ahead extends Abstract_Feature {

	/**
	 * Default settings.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'mode'       => 'smart',
		'delay'      => 500,
		'confidence' => 70,
		'max_words'  => 20,
		'headings'   => false,
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'type-ahead';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Type-ahead Text', 'ai' ),
			'description' => __( 'Ghost text suggestions while writing paragraphs in the block editor. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the type-ahead ability.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Type_Ahead_Ability::class,
			)
		);
	}

	/**
	 * Enqueues and localizes the editor assets.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'type_ahead', 'experiments/type-ahead' );
		Asset_Loader::enqueue_style( 'type_ahead', 'experiments/type-ahead' );

		$settings = $this->get_settings();

		Asset_Loader::localize_script(
			'type_ahead',
			'TypeAheadData',
			array(
				'enabled'        => $this->is_enabled(),
				'completionMode' => $settings['mode'],
				'triggerDelay'   => (int) $settings['delay'],
				'confidence'     => (float) $settings['confidence'] / 100,
				'maxWords'       => (int) $settings['max_words'],
				'showHeadings'   => (bool) $settings['headings'],
			)
		);
	}

	/**
	 * Returns the saved settings merged with defaults.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$settings = array();

		foreach ( self::DEFAULTS as $key => $value ) {
			$settings[ $key ] = get_option( $this->get_field_option_name( $key ), $value );
		}

		/**
		 * Filters the type-ahead settings.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, mixed> $settings The type-ahead settings.
		 */
		return apply_filters( 'wpai_feature_' . $this->get_id() . '_settings', $settings );
	}
}
