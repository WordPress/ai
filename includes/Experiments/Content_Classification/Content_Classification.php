<?php
/**
 * Content classification experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Classification;

use WordPress\AI\Abilities\Content_Classification\Content_Classification as Content_Classification_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content classification experiment.
 *
 * Provides AI-powered suggestions for post taxonomies
 * based on a comprehensive analysis of the post content.
 *
 * @since x.x.x
 */
class Content_Classification extends Abstract_Feature {

	/**
	 * The default taxonomy strategy.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STRATEGY_EXISTING_ONLY = 'existing_only';

	/**
	 * The strategy that allows new term suggestions.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STRATEGY_ALLOW_NEW = 'allow_new';

	/**
	 * The default maximum number of suggestions.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_SUGGESTIONS = 5;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-classification';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Classification', 'ai' ),
			'description' => __( 'AI-powered suggestions for post tags and categories based on content analysis.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Content_Classification_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in new post and edit post screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Load the assets only if the post type supports categories or tags and is not an attachment.
		// Also check if the user can manage categories.
		if (
			! $screen ||
			! current_user_can( 'manage_categories' ) ||
			in_array( $screen->post_type, array( 'attachment' ), true ) ||
			(
				! is_object_in_taxonomy( $screen->post_type, 'category' ) &&
				! is_object_in_taxonomy( $screen->post_type, 'post_tag' )
			)
		) {
			return;
		}

		Asset_Loader::enqueue_script( 'content_classification', 'experiments/content-classification' );
		Asset_Loader::enqueue_style( 'content_classification', 'experiments/content-classification' );
		Asset_Loader::localize_script(
			'content_classification',
			'ContentClassificationData',
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
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function render_settings_fields(): void {
		$strategy_option        = $this->get_field_option_name( 'strategy' );
		$max_suggestions_option = $this->get_field_option_name( 'max_suggestions' );
		$current_strategy       = get_option( $this->get_field_option_name( 'strategy' ), self::STRATEGY_EXISTING_ONLY );
		$current_max            = get_option( $this->get_field_option_name( 'max_suggestions' ), self::DEFAULT_MAX_SUGGESTIONS );
		?>
		<fieldset class="ai-experiments__item-fields">
			<legend class="screen-reader-text"><?php esc_html_e( 'Content Classification Settings', 'ai' ); ?></legend>
			<table class="ai-experiments__settings-table" role="presentation">
				<tr>
					<td>
						<label for="<?php echo esc_attr( $strategy_option ); ?>">
							<?php esc_html_e( 'Taxonomy strategy:', 'ai' ); ?>
						</label>
					</td>
					<td>
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
					</td>
				</tr>
				<tr>
					<td>
						<label for="<?php echo esc_attr( $max_suggestions_option ); ?>">
							<?php esc_html_e( 'Maximum suggestions:', 'ai' ); ?>
						</label>
					</td>
					<td>
						<input
							type="number"
							id="<?php echo esc_attr( $max_suggestions_option ); ?>"
							name="<?php echo esc_attr( $max_suggestions_option ); ?>"
							value="<?php echo esc_attr( (string) $current_max ); ?>"
							min="1"
							max="10"
							step="1"
						/>
					</td>
				</tr>
			</table>
		</fieldset>
		<?php
	}

	/**
	 * Sanitizes the strategy setting.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @param mixed $value The value to sanitize.
	 * @return int The sanitized max suggestions value.
	 */
	public function sanitize_max_suggestions( $value ): int {
		$value = absint( $value );

		return max( 1, min( 10, $value ) );
	}

	/**
	 * Gets the strategy to use for content classification.
	 *
	 * @since x.x.x
	 *
	 * @return string The strategy to use.
	 */
	public function get_strategy(): string {
		$strategy = get_option( $this->get_field_option_name( 'strategy' ), self::STRATEGY_EXISTING_ONLY );

		/**
		 * Filters the strategy to use for content classification.
		 *
		 * @since x.x.x
		 *
		 * @param string $strategy The strategy to use.
		 * @return string The filtered strategy.
		 */
		$strategy = apply_filters( 'wpai_content_classification_strategy', $strategy );

		// Return the sanitized strategy value.
		return $this->sanitize_strategy( $strategy );
	}

	/**
	 * Gets the maximum number of suggestions to generate for content classification.
	 *
	 * @since x.x.x
	 *
	 * @return int The maximum number of suggestions to generate.
	 */
	public function get_max_suggestions(): int {
		$max_suggestions = (int) get_option( $this->get_field_option_name( 'max_suggestions' ), self::DEFAULT_MAX_SUGGESTIONS );

		/**
		 * Filters the maximum number of suggestions to generate for content classification.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_suggestions The maximum number of suggestions to generate.
		 * @return int The filtered max suggestions.
		 */
		return $this->sanitize_max_suggestions(
			apply_filters( 'wpai_content_classification_max_suggestions', $max_suggestions )
		);
	}
}
