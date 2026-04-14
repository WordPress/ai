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
use WordPress\AI\Settings\Settings_Registration;
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
	private const DEFAULTS = array(
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
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
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
		Asset_Loader::enqueue_style( 'type_ahead', 'experiments/style-type-ahead' );

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
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'mode' ),
			array(
				'type'              => 'string',
				'default'           => self::DEFAULTS['mode'],
				'sanitize_callback' => array( $this, 'sanitize_mode' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'word', 'sentence', 'paragraph', 'smart' ),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'delay' ),
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULTS['delay'],
				'sanitize_callback' => array( $this, 'sanitize_delay' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 200,
						'maximum' => 2000,
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'confidence' ),
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULTS['confidence'],
				'sanitize_callback' => array( $this, 'sanitize_confidence' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'max_words' ),
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULTS['max_words'],
				'sanitize_callback' => array( $this, 'sanitize_max_words' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'headings' ),
			array(
				'type'              => 'boolean',
				'default'           => self::DEFAULTS['headings'],
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'       => 'mode',
				'label'    => __( 'Completion mode', 'ai' ),
				'type'     => 'text',
				'default'  => self::DEFAULTS['mode'],
				'elements' => array(
					array(
						'value' => 'word',
						'label' => __( 'Word', 'ai' ),
					),
					array(
						'value' => 'sentence',
						'label' => __( 'Sentence', 'ai' ),
					),
					array(
						'value' => 'paragraph',
						'label' => __( 'Paragraph', 'ai' ),
					),
					array(
						'value' => 'smart',
						'label' => __( 'Smart', 'ai' ),
					),
				),
			),
			array(
				'id'      => 'delay',
				'label'   => __( 'Trigger delay (ms)', 'ai' ),
				'type'    => 'integer',
				'default' => self::DEFAULTS['delay'],
				'isValid' => array(
					'min' => 200,
					'max' => 2000,
				),
			),
			array(
				'id'      => 'confidence',
				'label'   => __( 'Minimum confidence (%)', 'ai' ),
				'type'    => 'integer',
				'default' => self::DEFAULTS['confidence'],
				'isValid' => array(
					'min' => 0,
					'max' => 100,
				),
			),
			array(
				'id'      => 'max_words',
				'label'   => __( 'Max words per suggestion', 'ai' ),
				'type'    => 'integer',
				'default' => self::DEFAULTS['max_words'],
				'isValid' => array(
					'min' => 1,
					'max' => 50,
				),
			),
			array(
				'id'      => 'headings',
				'label'   => __( 'Enable in headings', 'ai' ),
				'type'    => 'boolean',
				'default' => self::DEFAULTS['headings'],
			),
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

		return $settings;
	}

	/**
	 * Sanitizes the completion mode.
	 *
	 * @since x.x.x
	 */
	public function sanitize_mode( $mode ): string {
		$mode = is_string( $mode ) ? strtolower( $mode ) : '';

		return in_array( $mode, array( 'word', 'sentence', 'paragraph', 'smart' ), true ) ? $mode : self::DEFAULTS['mode'];
	}

	/**
	 * Sanitizes the delay field.
	 *
	 * @since x.x.x
	 */
	public function sanitize_delay( $value ): int {
		$value = (int) $value;

		return max( 200, min( 2000, $value ) );
	}

	/**
	 * Sanitizes the confidence field.
	 *
	 * @since x.x.x
	 */
	public function sanitize_confidence( $value ): int {
		$value = (int) $value;

		return max( 0, min( 100, $value ) );
	}

	/**
	 * Sanitizes the max words field.
	 *
	 * @since x.x.x
	 */
	public function sanitize_max_words( $value ): int {
		$value = (int) $value;

		return max( 1, min( 50, $value ) );
	}
}
