<?php
/**
 * Contextual tagging experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Contextual_Tagging;

use WordPress\AI\Abilities\Contextual_Tagging\Contextual_Tagging as Contextual_Tagging_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contextual tagging experiment.
 *
 * Provides AI-powered suggestions for post taxonomies
 * based on a comprehensive analysis of the post content.
 *
 * @since 0.6.0
 */
class Contextual_Tagging extends Abstract_Experiment {

	/**
	 * The default taxonomy strategy.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	public const STRATEGY_EXISTING_ONLY = 'existing_only';

	/**
	 * The strategy that allows new term suggestions.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	public const STRATEGY_ALLOW_NEW = 'allow_new';

	/**
	 * The default maximum number of suggestions.
	 *
	 * @since 0.6.0
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_SUGGESTIONS = 5;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.6.0
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'contextual-tagging',
			'label'       => __( 'Contextual Tagging', 'ai' ),
			'description' => __( 'AI-powered suggestions for post tags and categories based on content analysis.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.6.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.6.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Contextual_Tagging_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.6.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in new post and edit post screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Load the assets only if the post type supports editor and is not an attachment.
		if (
			! $screen ||
			! post_type_supports( $screen->post_type, 'editor' ) ||
			in_array( $screen->post_type, array( 'attachment' ), true )
		) {
			return;
		}

		Asset_Loader::enqueue_script( 'contextual_tagging', 'experiments/contextual-tagging' );
		Asset_Loader::enqueue_style( 'contextual_tagging', 'experiments/contextual-tagging' );
		Asset_Loader::localize_script(
			'contextual_tagging',
			'ContextualTaggingData',
			array(
				'enabled'        => $this->is_enabled(),
				'strategy'       => $this->get_strategy(),
				'maxSuggestions' => $this->get_max_suggestions(),
			)
		);
	}

	/**
	 * Registers experiment-specific settings.
	 *
	 * @since 0.6.0
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'strategy' ),
			array(
				'type'              => 'string',
				'default'           => self::STRATEGY_EXISTING_ONLY,
				'sanitize_callback' => array( $this, 'sanitize_strategy' ),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'max_suggestions' ),
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_MAX_SUGGESTIONS,
				'sanitize_callback' => array( $this, 'sanitize_max_suggestions' ),
			)
		);
	}

	/**
	 * Renders experiment-specific settings fields.
	 *
	 * @since 0.6.0
	 */
	public function render_settings_fields(): void {
		$strategy_option        = $this->get_field_option_name( 'strategy' );
		$max_suggestions_option = $this->get_field_option_name( 'max_suggestions' );
		$current_strategy       = $this->get_strategy();
		$current_max            = $this->get_max_suggestions();
		?>
		<fieldset class="ai-experiment-settings-fieldset">
			<legend class="screen-reader-text"><?php esc_html_e( 'Contextual Tagging Settings', 'ai' ); ?></legend>
			<p>
				<label for="<?php echo esc_attr( $strategy_option ); ?>">
					<?php esc_html_e( 'Taxonomy strategy:', 'ai' ); ?>
				</label>
				<select
					id="<?php echo esc_attr( $strategy_option ); ?>"
					name="<?php echo esc_attr( $strategy_option ); ?>"
				>
					<option value="<?php echo esc_attr( self::STRATEGY_EXISTING_ONLY ); ?>" <?php selected( $current_strategy, self::STRATEGY_EXISTING_ONLY ); ?>>
						<?php esc_html_e( 'Only suggest existing terms', 'ai' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::STRATEGY_ALLOW_NEW ); ?>" <?php selected( $current_strategy, self::STRATEGY_ALLOW_NEW ); ?>>
						<?php esc_html_e( 'Suggest new terms based on context', 'ai' ); ?>
					</option>
				</select>
			</p>
			<p>
				<label for="<?php echo esc_attr( $max_suggestions_option ); ?>">
					<?php esc_html_e( 'Maximum suggestions:', 'ai' ); ?>
				</label>
				<input
					type="number"
					id="<?php echo esc_attr( $max_suggestions_option ); ?>"
					name="<?php echo esc_attr( $max_suggestions_option ); ?>"
					value="<?php echo esc_attr( (string) $current_max ); ?>"
					min="1"
					max="10"
					step="1"
				/>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Sanitizes the strategy setting.
	 *
	 * @since 0.6.0
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string The sanitized strategy value.
	 */
	public function sanitize_strategy( $value ): string {
		$valid = array( self::STRATEGY_EXISTING_ONLY, self::STRATEGY_ALLOW_NEW );

		return in_array( $value, $valid, true ) ? $value : self::STRATEGY_EXISTING_ONLY;
	}

	/**
	 * Sanitizes the max suggestions setting.
	 *
	 * @since 0.6.0
	 *
	 * @param mixed $value The value to sanitize.
	 * @return int The sanitized max suggestions value.
	 */
	public function sanitize_max_suggestions( $value ): int {
		$value = absint( $value );

		return max( 1, min( 10, $value ) );
	}

	/**
	 * Gets the strategy to use for contextual tagging.
	 *
	 * @since 0.6.0
	 *
	 * @return string The strategy to use.
	 */
	public function get_strategy(): string {
		$strategy = get_option( $this->get_field_option_name( 'strategy' ), self::STRATEGY_EXISTING_ONLY );

		/**
		 * Filters the strategy to use for contextual tagging.
		 *
		 * @since 0.6.0
		 *
		 * @param string $strategy The strategy to use.
		 * @return string The filtered strategy.
		 */
		$strategy = apply_filters( 'ai_contextual_tagging_strategy', $strategy );

		// Return the sanitized strategy value.
		return $this->sanitize_strategy( $strategy );
	}

	/**
	 * Gets the maximum number of suggestions to generate for contextual tagging.
	 *
	 * @since 0.6.0
	 *
	 * @return int The maximum number of suggestions to generate.
	 */
	public function get_max_suggestions(): int {
		$max_suggestions = (int) get_option( $this->get_field_option_name( 'max_suggestions' ), self::DEFAULT_MAX_SUGGESTIONS );

		/**
		 * Filters the maximum number of suggestions to generate for contextual tagging.
		 *
		 * @since 0.6.0
		 *
		 * @param int $max_suggestions The maximum number of suggestions to generate.
		 * @return int The filtered max suggestions.
		 */
		return apply_filters( 'ai_contextual_tagging_max_suggestions', $max_suggestions );
	}
}
