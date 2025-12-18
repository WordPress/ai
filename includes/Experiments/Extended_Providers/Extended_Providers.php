<?php
/**
 * Extended Providers experiment implementation.
 *
 * @package WordPress\AI\Experiments\Extended_Providers
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Extended_Providers;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Providers\Cloudflare\CloudflareWorkersAiProvider;
use WordPress\AI\Providers\Cohere\CohereProvider;
use WordPress\AI\Providers\DeepSeek\DeepSeekProvider;
use WordPress\AI\Providers\FalAi\FalAiProvider;
use WordPress\AI\Providers\Grok\GrokProvider;
use WordPress\AI\Providers\Groq\GroqProvider;
use WordPress\AI\Providers\HuggingFace\HuggingFaceProvider;
use WordPress\AI\Providers\Ollama\OllamaProvider;
use WordPress\AI\Providers\OpenRouter\OpenRouterProvider;
use WordPress\AI\Settings\Settings_Registration;
use WordPress\AiClient\AiClient;

use function __;
use function _doing_it_wrong;
use function admin_url;
use function apply_filters;
use function class_exists;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function get_option;
use function is_string;
use function register_setting;
use function rest_sanitize_boolean;
use function sprintf;
use function wp_kses_post;

/**
 * Registers additional AI providers with the WP AI Client registry.
 *
 * This experiment does not ship providers itself—instead it exposes filters
 * so contributors can list provider classes that should only load when the
 * experiment is enabled.
 */
class Extended_Providers extends Abstract_Experiment {
	private const DEFAULT_PROVIDER_CLASSES = array(
		CloudflareWorkersAiProvider::class,
		CohereProvider::class,
		DeepSeekProvider::class,
		FalAiProvider::class,
		GrokProvider::class,
		GroqProvider::class,
		HuggingFaceProvider::class,
		OllamaProvider::class,
		OpenRouterProvider::class,
	);
	private const FIELD_PROVIDERS = 'providers';

	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'extended-providers',
			'label'       => __( 'Extended Providers', 'ai' ),
			'description' => __( 'Registers additional AI providers for experimentation without affecting the core set.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_providers' ), 20 );
	}

	/**
	 * Registers any provider classes supplied via filters.
	 */
	public function register_providers(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$provider_classes = $this->filter_enabled_provider_classes(
			$this->get_provider_classes()
		);

		if ( empty( $provider_classes ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		foreach ( $provider_classes as $class_name ) {
			if ( ! is_string( $class_name ) || '' === $class_name ) {
				continue;
			}

			if ( ! class_exists( $class_name ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: provider class name. */
						__( 'Extended Providers experiment could not load "%s". Make sure the class is autoloadable.', 'ai' ),
						esc_html( $class_name )
					),
					'0.1.0'
				);
				continue;
			}

			if ( $registry->hasProvider( $class_name ) ) {
				continue;
			}

			try {
				$registry->registerProvider( $class_name );
			} catch ( \Throwable $t ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: provider class, 2: error message. */
						__( 'Failed to register provider "%1$s": %2$s', 'ai' ),
						esc_html( $class_name ),
						esc_html( $t->getMessage() )
					),
					'0.1.0'
				);
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_provider_selection_option_name(),
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_provider_selection' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function render_settings_fields(): void {
		$provider_classes = $this->get_provider_classes();

		if ( empty( $provider_classes ) ) {
			echo '<p class="description">' . esc_html__( 'No providers are currently registered for this experiment.', 'ai' ) . '</p>';
			return;
		}

		$selection   = $this->get_provider_selection();
		$option_name = $this->get_provider_selection_option_name();
		?>
		<div class="ai-experiment-settings">
			<p class="description">
				<?php
				echo wp_kses_post(
					__( 'Toggle which providers the Extended Providers experiment should register. Disabled providers remain available on the AI Credentials screen but are not loaded into the runtime registry.', 'ai' )
				);
				?>
			</p>
			<?php foreach ( $provider_classes as $class_name ) : ?>
				<?php
				$field_id = $option_name . '-' . md5( $class_name );
				$is_checked = $this->is_provider_selected( $class_name, $selection );
				?>
				<div class="ai-experiment-settings__control components-toggle-control">
					<input type="hidden" name="<?php echo esc_attr( "{$option_name}[{$class_name}]" ); ?>" value="0" />
					<input
						type="checkbox"
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( "{$option_name}[{$class_name}]" ); ?>"
						value="1"
						<?php checked( $is_checked ); ?>
					/>
					<label for="<?php echo esc_attr( $field_id ); ?>">
						<strong><?php echo esc_html( $this->get_provider_label( $class_name ) ); ?></strong>
						<br />
						<code><?php echo esc_html( $class_name ); ?></code>
					</label>
				</div>
			<?php endforeach; ?>
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
				'label' => __( 'Credentials', 'ai' ),
				'url'   => admin_url( 'options-general.php?page=wp-ai-client' ),
				'type'  => 'dashboard',
			),
		);
	}

	/**
	 * Returns the provider class list after filters have been applied.
	 *
	 * @return array<int, string>
	 */
	private function get_provider_classes(): array {
		$defaults = apply_filters( 'ai_extended_provider_default_classes', self::DEFAULT_PROVIDER_CLASSES );

		/**
		 * Filters the provider class list registered by the Extended Providers experiment.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string>                    $classes    Provider class names.
		 * @param \WordPress\AI\Abstracts\Abstract_Experiment $experiment Experiment instance.
		 */
		$providers = apply_filters( 'ai_extended_provider_classes', (array) $defaults, $this );

		return array_values(
			array_filter(
				array_map(
					static function ( $class ) {
						return is_string( $class ) ? trim( $class ) : '';
					},
					(array) $providers
				)
			)
		);
	}

	/**
	 * Filters provider classes based on the admin selection.
	 *
	 * @param array<int, string> $provider_classes Provider classes.
	 *
	 * @return array<int, string>
	 */
	private function filter_enabled_provider_classes( array $provider_classes ): array {
		$selection = $this->get_provider_selection();

		if ( empty( $selection ) ) {
			return $provider_classes;
		}

		return array_values(
			array_filter(
				$provider_classes,
				static function ( string $class ) use ( $selection ): bool {
					return ! isset( $selection[ $class ] ) || true === $selection[ $class ];
				}
			)
		);
	}

	/**
	 * Gets the stored provider selection map.
	 *
	 * @return array<string, bool>
	 */
	private function get_provider_selection(): array {
		$selection = get_option( $this->get_provider_selection_option_name(), array() );

		if ( ! is_array( $selection ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $selection as $class => $enabled ) {
			if ( ! is_string( $class ) || '' === $class ) {
				continue;
			}

			$sanitized[ $class ] = (bool) $enabled;
		}

		return $sanitized;
	}

	/**
	 * Determines if a provider should be registered.
	 *
	 * @param string               $class_name Provider class name.
	 * @param array<string, bool>  $selection  Selection map.
	 *
	 * @return bool
	 */
	private function is_provider_selected( string $class_name, array $selection ): bool {
		if ( empty( $selection ) ) {
			return true;
		}

		return $selection[ $class_name ] ?? true;
	}

	/**
	 * Returns the option name that stores provider selection.
	 */
	private function get_provider_selection_option_name(): string {
		return $this->get_field_option_name( self::FIELD_PROVIDERS );
	}

	/**
	 * Sanitizes the provider selection payload from the settings form.
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return array<string, bool>
	 */
	public function sanitize_provider_selection( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $value as $class => $enabled ) {
			if ( ! is_string( $class ) || '' === $class ) {
				continue;
			}

			$sanitized[ $class ] = rest_sanitize_boolean( $enabled );
		}

		return $sanitized;
	}

	/**
	 * Returns a human-friendly label for a provider class.
	 *
	 * @param string $class_name Provider class name.
	 *
	 * @return string
	 */
	private function get_provider_label( string $class_name ): string {
		if ( class_exists( $class_name ) && method_exists( $class_name, 'metadata' ) ) {
			try {
				/** @var \WordPress\AiClient\Providers\DTO\ProviderMetadata $metadata */
				$metadata = $class_name::metadata();
				return $metadata->getName();
			} catch ( \Throwable $t ) {
				// Fallback below.
			}
		}

		return $class_name;
	}
}
