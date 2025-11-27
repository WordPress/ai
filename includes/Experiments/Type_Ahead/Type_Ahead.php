<?php
/**
 * Type Ahead experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Type_Ahead;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Abilities\Type_Ahead\Type_Ahead as Type_Ahead_Ability;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Settings\Settings_Registration;

use function esc_html__;

/**
 * Registers the editor type-ahead experience.
 */
class Type_Ahead extends Abstract_Experiment {
	private const OPTION_MODE       = 'ai_experiment_type_ahead_mode';
	private const OPTION_DELAY      = 'ai_experiment_type_ahead_delay';
	private const OPTION_CONFIDENCE = 'ai_experiment_type_ahead_confidence';
	private const OPTION_HEADINGS   = 'ai_experiment_type_ahead_headings';
	private const OPTION_MAX_WORDS  = 'ai_experiment_type_ahead_max_words';

	private const DEFAULTS = array(
		'mode'       => 'smart',
		'delay'      => 500,
		'confidence' => 70,
		'headings'   => false,
		'max_words'  => 20,
	);

	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'type-ahead',
			'label'       => esc_html__( 'Type-ahead Text', 'ai' ),
			'description' => esc_html__( 'Ghost text suggestions while writing paragraphs in the block editor.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the type-ahead ability.
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
	 * Enqueues editor assets.
	 *
	 * Assets are always enqueued so the JavaScript can provide appropriate
	 * feedback when the experiment is disabled. The enabled state is passed
	 * to the script which handles the conditional activation.
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
				'showHeadings'   => (bool) $settings['headings'],
				'maxWords'       => (int) $settings['max_words'],
				'abilityName'    => 'ai/' . $this->get_id(),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_MODE,
			array(
				'type'              => 'string',
				'default'           => self::DEFAULTS['mode'],
				'sanitize_callback' => array( $this, 'sanitize_mode' ),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_DELAY,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULTS['delay'],
				'sanitize_callback' => array( $this, 'sanitize_delay' ),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_CONFIDENCE,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULTS['confidence'],
				'sanitize_callback' => array( $this, 'sanitize_confidence' ),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_HEADINGS,
			array(
				'type'              => 'boolean',
				'default'           => self::DEFAULTS['headings'],
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_MAX_WORDS,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULTS['max_words'],
				'sanitize_callback' => array( $this, 'sanitize_max_words' ),
			)
		);
	}

	/**
	 * Renders settings controls on the Experiments screen.
	 */
	public function render_settings_fields(): void {
		$settings = $this->get_settings();
		?>
		<div class="ai-experiment-settings">
			<label for="<?php echo esc_attr( self::OPTION_MODE ); ?>" class="ai-experiment-settings__label">
				<?php esc_html_e( 'Completion mode', 'ai' ); ?>
			</label>
			<select name="<?php echo esc_attr( self::OPTION_MODE ); ?>" id="<?php echo esc_attr( self::OPTION_MODE ); ?>">
				<?php foreach ( array( 'word', 'sentence', 'paragraph', 'smart' ) as $mode ) : ?>
					<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $settings['mode'], $mode ); ?>>
						<?php echo esc_html( ucfirst( $mode ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="<?php echo esc_attr( self::OPTION_DELAY ); ?>" class="ai-experiment-settings__label">
				<?php esc_html_e( 'Trigger delay (ms)', 'ai' ); ?>
			</label>
			<input
				type="number"
				min="200"
				max="2000"
				step="50"
				id="<?php echo esc_attr( self::OPTION_DELAY ); ?>"
				name="<?php echo esc_attr( self::OPTION_DELAY ); ?>"
				value="<?php echo esc_attr( (string) $settings['delay'] ); ?>"
			/>

			<label for="<?php echo esc_attr( self::OPTION_CONFIDENCE ); ?>" class="ai-experiment-settings__label">
				<?php esc_html_e( 'Minimum confidence (%)', 'ai' ); ?>
			</label>
			<input
				type="number"
				min="0"
				max="100"
				step="5"
				id="<?php echo esc_attr( self::OPTION_CONFIDENCE ); ?>"
				name="<?php echo esc_attr( self::OPTION_CONFIDENCE ); ?>"
				value="<?php echo esc_attr( (string) $settings['confidence'] ); ?>"
			/>

			<label for="<?php echo esc_attr( self::OPTION_MAX_WORDS ); ?>" class="ai-experiment-settings__label">
				<?php esc_html_e( 'Max words per suggestion', 'ai' ); ?>
			</label>
			<input
				type="number"
				min="1"
				max="50"
				step="1"
				id="<?php echo esc_attr( self::OPTION_MAX_WORDS ); ?>"
				name="<?php echo esc_attr( self::OPTION_MAX_WORDS ); ?>"
				value="<?php echo esc_attr( (string) $settings['max_words'] ); ?>"
			/>

			<label class="components-toggle-control" for="<?php echo esc_attr( self::OPTION_HEADINGS ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( self::OPTION_HEADINGS ); ?>"
					name="<?php echo esc_attr( self::OPTION_HEADINGS ); ?>"
					value="1"
					<?php checked( (bool) $settings['headings'] ); ?>
				/>
				<span><?php esc_html_e( 'Enable in headings', 'ai' ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Returns the saved settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		return array(
			'mode'       => get_option( self::OPTION_MODE, self::DEFAULTS['mode'] ),
			'delay'      => (int) get_option( self::OPTION_DELAY, self::DEFAULTS['delay'] ),
			'confidence' => (int) get_option( self::OPTION_CONFIDENCE, self::DEFAULTS['confidence'] ),
			'headings'   => (bool) get_option( self::OPTION_HEADINGS, self::DEFAULTS['headings'] ),
			'max_words'  => (int) get_option( self::OPTION_MAX_WORDS, self::DEFAULTS['max_words'] ),
		);
	}

	/**
	 * Sanitizes the completion mode.
	 */
	public function sanitize_mode( $mode ): string {
		$mode = is_string( $mode ) ? strtolower( $mode ) : '';

		return in_array( $mode, array( 'word', 'sentence', 'paragraph', 'smart' ), true ) ? $mode : self::DEFAULTS['mode'];
	}

	/**
	 * Sanitizes the delay field.
	 */
	public function sanitize_delay( $value ): int {
		$value = (int) $value;

		return max( 200, min( 2000, $value ) );
	}

	/**
	 * Sanitizes the confidence field.
	 */
	public function sanitize_confidence( $value ): int {
		$value = (int) $value;

		return max( 0, min( 100, $value ) );
	}

	/**
	 * Sanitizes the max words field.
	 */
	public function sanitize_max_words( $value ): int {
		$value = (int) $value;

		return max( 1, min( 50, $value ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_settings(): bool {
		return true;
	}
}
