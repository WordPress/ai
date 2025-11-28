<?php
/**
 * Writing Assistant experiment implementation.
 *
 * @package WordPress\AI\Experiments\Writing_Assistant
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Writing_Assistant;

use WordPress\AI\Abilities\Writing_Assistant\Writing_Suggestions;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Settings\Settings_Registration;

use function esc_attr;
use function esc_html__;
use function esc_html_e;

/**
 * Registers the Writing Assistant sidebar and ability.
 */
class Writing_Assistant extends Abstract_Experiment {
	private const OPTION_TIMER_DEFAULT   = 'ai_experiment_writing_assistant_timer';
	private const OPTION_WORD_TRIGGER    = 'ai_experiment_writing_assistant_word_trigger';
	private const DEFAULT_TIMER_SECONDS  = 1500;
	private const DEFAULT_WORD_THRESHOLD = 75;

	/**
	 * Suggestion type icons shared with the client.
	 *
	 * @var array<string, string>
	 */
	private const SUGGESTION_TYPE_ICONS = array(
		'readability'   => 'book-alt',
		'seo'           => 'search',
		'internal-link' => 'admin-links',
		'fact-check'    => 'yes',
		'structure'     => 'media-document',
		'tone'          => 'format-status',
		'grammar'       => 'editor-spellcheck',
	);

	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'writing-assistant',
			'label'       => esc_html__( 'Writing Assistant', 'ai' ),
			'description' => esc_html__( 'Stream AI-powered suggestions in the editor sidebar with session timers and category filters.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the Writing Assistant ability.
	 */
	public function register_ability(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Writing_Suggestions::class,
			)
		);
	}

	/**
	 * Enqueues editor assets and localizes configuration.
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'writing_assistant', 'experiments/writing-assistant' );
		Asset_Loader::enqueue_style( 'writing_assistant', 'experiments/style-writing-assistant' );

		$settings = $this->get_settings();

		Asset_Loader::localize_script(
			'writing_assistant',
			'WritingAssistantData',
			array(
				'enabled'         => $this->is_enabled(),
				'ability'         => 'ai/' . $this->get_id(),
				'timer'           => array(
					'defaultSeconds' => $settings['default_timer'],
					'presets'        => array( 300, 900, 1500, 0 ),
				),
				'wordTrigger'     => $settings['word_trigger'],
				'suggestionTypes' => $this->get_suggestion_type_config(),
			)
		);
	}

	/**
	 * Registers experiment settings.
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_TIMER_DEFAULT,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_TIMER_SECONDS,
				'sanitize_callback' => array( $this, 'sanitize_timer' ),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_WORD_TRIGGER,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_WORD_THRESHOLD,
				'sanitize_callback' => array( $this, 'sanitize_word_trigger' ),
			)
		);
	}

	/**
	 * Renders additional settings fields on the Experiments screen.
	 */
	public function render_settings_fields(): void {
		$settings = $this->get_settings();
		?>
		<div class="ai-experiment-settings">
			<label for="<?php echo esc_attr( self::OPTION_TIMER_DEFAULT ); ?>" class="ai-experiment-settings__label">
				<?php esc_html_e( 'Default session timer (seconds)', 'ai' ); ?>
			</label>
			<input
				type="number"
				min="0"
				max="7200"
				step="60"
				id="<?php echo esc_attr( self::OPTION_TIMER_DEFAULT ); ?>"
				name="<?php echo esc_attr( self::OPTION_TIMER_DEFAULT ); ?>"
				value="<?php echo esc_attr( (string) $settings['default_timer'] ); ?>"
			/>

			<label for="<?php echo esc_attr( self::OPTION_WORD_TRIGGER ); ?>" class="ai-experiment-settings__label">
				<?php esc_html_e( 'Auto-suggestion word delta', 'ai' ); ?>
			</label>
			<input
				type="number"
				min="25"
				max="300"
				step="5"
				id="<?php echo esc_attr( self::OPTION_WORD_TRIGGER ); ?>"
				name="<?php echo esc_attr( self::OPTION_WORD_TRIGGER ); ?>"
				value="<?php echo esc_attr( (string) $settings['word_trigger'] ); ?>"
			/>
				<p class="description">
					<?php esc_html_e( 'Suggestions refresh automatically whenever you add this many new words during an active session.', 'ai' ); ?>
				</p>
		</div>
		<?php
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_settings(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_entry_points(): array {
		return array(
			array(
				'label' => __( 'Try', 'ai' ),
				'url'   => admin_url( 'post-new.php' ),
				'type'  => 'try',
			),
		);
	}

	/**
	 * Sanitizes the timer option.
	 *
	 * @param mixed $value Raw option value.
	 * @return int
	 */
	public function sanitize_timer( $value ): int {
		$value = (int) $value;

		if ( $value < 0 ) {
			return 0;
		}

		return min( 7200, $value );
	}

	/**
	 * Sanitizes the word trigger option.
	 *
	 * @param mixed $value Raw option value.
	 * @return int
	 */
	public function sanitize_word_trigger( $value ): int {
		$value = (int) $value;

		return max( 25, min( 300, $value ) );
	}

	/**
	 * Gets the saved settings with defaults.
	 *
	 * @return array{default_timer: int, word_trigger: int}
	 */
	private function get_settings(): array {
		return array(
			'default_timer' => (int) get_option( self::OPTION_TIMER_DEFAULT, self::DEFAULT_TIMER_SECONDS ),
			'word_trigger'  => (int) get_option( self::OPTION_WORD_TRIGGER, self::DEFAULT_WORD_THRESHOLD ),
		);
	}

	/**
	 * Returns suggestion type metadata for the editor UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_suggestion_type_config(): array {
		$config = array();

		foreach ( self::SUGGESTION_TYPE_ICONS as $slug => $icon ) {
			$config[] = array(
				'slug'        => $slug,
				'label'       => $this->get_type_label( $slug ),
				'description' => $this->get_type_description( $slug ),
				'icon'        => $icon,
			);
		}

		return $config;
	}

	/**
	 * Gets the translated label for a suggestion type.
	 *
	 * @param string $slug Suggestion type slug.
	 * @return string
	 */
	private function get_type_label( string $slug ): string {
		switch ( $slug ) {
			case 'readability':
				return esc_html__( 'Readability', 'ai' );
			case 'seo':
				return esc_html__( 'SEO', 'ai' );
			case 'internal-link':
				return esc_html__( 'Internal links', 'ai' );
			case 'fact-check':
				return esc_html__( 'Fact check', 'ai' );
			case 'structure':
				return esc_html__( 'Structure', 'ai' );
			case 'tone':
				return esc_html__( 'Tone', 'ai' );
			case 'grammar':
				return esc_html__( 'Grammar', 'ai' );
			default:
				return esc_html__( 'Suggestion', 'ai' );
		}
	}

	/**
	 * Gets the translated description for a suggestion type.
	 *
	 * @param string $slug Suggestion type slug.
	 * @return string
	 */
	private function get_type_description( string $slug ): string {
		switch ( $slug ) {
			case 'readability':
				return esc_html__( 'Sentence complexity, passive voice, paragraph length.', 'ai' );
			case 'seo':
				return esc_html__( 'Keyword placement, search intent, and metadata reminders.', 'ai' );
			case 'internal-link':
				return esc_html__( 'Linking opportunities to cornerstone content.', 'ai' );
			case 'fact-check':
				return esc_html__( 'Calls out claims that may need verification or sources.', 'ai' );
			case 'structure':
				return esc_html__( 'Outline, missing sections, and flow improvements.', 'ai' );
			case 'tone':
				return esc_html__( 'Voice consistency, formality, and audience alignment.', 'ai' );
			case 'grammar':
				return esc_html__( 'Spelling, punctuation, and style suggestions.', 'ai' );
			default:
				return esc_html__( 'Contextual writing guidance.', 'ai' );
		}
	}
}
