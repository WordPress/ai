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
use function get_option;
use function is_string;
use function register_setting;
use function rest_sanitize_boolean;
use function sanitize_text_field;
use function sprintf;
use function wp_enqueue_script_module;
use function wp_kses_post;
use function wp_register_script_module;

/**
 * Registers additional AI providers with the WP AI Client registry.
 *
 * This experiment does not ship providers itself—instead it exposes filters
 * so contributors can list provider classes that should only load when the
 * experiment is enabled.
 */
class Extended_Providers extends Abstract_Experiment {

	/**
	 * Default provider classes to register.
	 *
	 * @var array<int, class-string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- False positive with ::class array.
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

	/**
	 * Field name for provider selection setting.
	 *
	 * @var string
	 */
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
	 * Provider metadata for connectors integration.
	 *
	 * Maps provider ID => [ label, description, helpUrl, helpLabel ].
	 *
	 * @var array<string, array{label: string, description: string, helpUrl: string, helpLabel: string}>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- False positive with array constant.
	private const CONNECTOR_META = array(
		'cloudflare'  => array(
			'label'       => 'Cloudflare Workers AI',
			'description' => "Run AI models on Cloudflare\u{2019}s global edge network.",
			'helpUrl'     => 'https://dash.cloudflare.com/',
			'helpLabel'   => 'dash.cloudflare.com',
		),
		'cohere'      => array(
			'label'       => 'Cohere',
			'description' => 'Enterprise-grade language models for text generation and embeddings.',
			'helpUrl'     => 'https://dashboard.cohere.com/',
			'helpLabel'   => 'dashboard.cohere.com',
		),
		'deepseek'    => array(
			'label'       => 'DeepSeek',
			'description' => 'Advanced reasoning and code generation with DeepSeek models.',
			'helpUrl'     => 'https://platform.deepseek.com/',
			'helpLabel'   => 'platform.deepseek.com',
		),
		'fal'         => array(
			'label'       => 'Fal.ai',
			'description' => 'Fast image generation and media models.',
			'helpUrl'     => 'https://fal.ai/dashboard/',
			'helpLabel'   => 'fal.ai',
		),
		'grok'        => array(
			'label'       => 'Grok (xAI)',
			'description' => "Text generation with xAI\u{2019}s Grok models.",
			'helpUrl'     => 'https://console.x.ai/',
			'helpLabel'   => 'console.x.ai',
		),
		'groq'        => array(
			'label'       => 'Groq',
			'description' => 'Ultra-fast inference for open-source language models.',
			'helpUrl'     => 'https://console.groq.com/',
			'helpLabel'   => 'console.groq.com',
		),
		'huggingface' => array(
			'label'       => 'Hugging Face',
			'description' => 'Access thousands of open-source models via the Inference API.',
			'helpUrl'     => 'https://huggingface.co/settings/tokens',
			'helpLabel'   => 'huggingface.co',
		),
		'ollama'      => array(
			'label'       => 'Ollama',
			'description' => 'Run large language models locally on your own hardware.',
			'helpUrl'     => 'https://ollama.com/',
			'helpLabel'   => 'ollama.com',
			'type'        => 'endpoint',
		),
		'openrouter'  => array(
			'label'       => 'OpenRouter',
			'description' => 'Unified API gateway for hundreds of AI models.',
			'helpUrl'     => 'https://openrouter.ai/keys',
			'helpLabel'   => 'openrouter.ai',
		),
	);

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// Register providers immediately so they're available when
		// the WP AI Client collects provider metadata for the credentials screen.
		$this->register_providers();

		// Add extended provider models to the preferred models list so that
		// experiments like title generation can use them.
		add_filter( 'ai_experiments_preferred_models_for_text_generation', array( $this, 'add_extended_model_preferences' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Always pass keys to the registry so text generation works,
		// regardless of whether the Connectors screen exists.
		$this->apply_endpoint_provider_urls();
		add_action( 'init', array( $this, 'maybe_pass_keys_to_registry' ), 22 );

		// Connectors-specific UI and settings only on WP 7.0+.
		if ( ! $this->is_connectors_supported() ) {
			return;
		}

		$this->register_extra_connector_settings();

		// Register API key settings AFTER core's init:20
		// so we only fill in what core didn't handle (beta2 vs trunk differences).
		add_action( 'init', array( $this, 'maybe_register_api_key_settings' ), 21 );

		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_connectors_script' ) );
	}

	/**
	 * Checks whether the WP 7.0 Connectors screen is available.
	 *
	 * @return bool
	 */
	private function is_connectors_supported(): bool {
		// Trunk uses _wp_connectors_get_connector_settings; beta2 uses _wp_connectors_get_provider_settings.
		return function_exists( '_wp_connectors_get_connector_settings' )
			|| function_exists( '_wp_connectors_get_provider_settings' );
	}

	/**
	 * Well-known text generation models for each extended provider.
	 *
	 * These are added to the preferred models list so that AI experiments
	 * (title generation, summarization, etc.) can use extended providers.
	 *
	 * @var array<string, list<string>>
	 */
	private const TEXT_GENERATION_MODELS = array(
		'cohere'      => array( 'command-r-08-2024', 'command-a-reasoning-08-2025', 'command-r7b-12-2024' ),
		'deepseek'    => array( 'deepseek-chat', 'deepseek-reasoner' ),
		'grok'        => array( 'grok-2', 'grok-3-mini' ),
		'groq'        => array( 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant' ),
		'huggingface' => array( 'meta-llama/Llama-3.3-70B-Instruct' ),
		'openrouter'  => array( 'openai/gpt-4o-mini', 'anthropic/claude-3.5-haiku' ),
	);

	/**
	 * Adds extended provider models to the preferred text generation models list.
	 *
	 * @param array<int, array{string, string}> $models The current preferred models.
	 * @return array<int, array{string, string}> The filtered preferred models.
	 */
	public function add_extended_model_preferences( array $models ): array {
		$enabled_ids = $this->get_enabled_provider_ids();

		foreach ( self::TEXT_GENERATION_MODELS as $provider_id => $model_ids ) {
			if ( ! in_array( $provider_id, $enabled_ids, true ) ) {
				continue;
			}

			foreach ( $model_ids as $model_id ) {
				$models[] = array( $provider_id, $model_id );
			}
		}

		return $models;
	}

	/**
	 * Returns the provider IDs that are currently enabled in the experiment.
	 *
	 * @return string[]
	 */
	private function get_enabled_provider_ids(): array {
		$provider_classes = $this->filter_enabled_provider_classes(
			$this->get_provider_classes()
		);

		$ids = array();
		foreach ( $provider_classes as $class_name ) {
			if ( ! class_exists( $class_name ) || ! method_exists( $class_name, 'metadata' ) ) {
				continue;
			}

			try {
				$ids[] = $class_name::metadata()->getId();
			} catch ( \Throwable $t ) {
				continue;
			}
		}

		return $ids;
	}

	/**
	 * Registers non-standard connector settings that core doesn't handle.
	 *
	 * Core's `_wp_register_default_connector_settings()` (init:20) auto-registers
	 * `connectors_ai_{id}_api_key` settings, mask filters, and key passing for all
	 * registered providers. We only register settings core doesn't know about:
	 * - Cloudflare Account ID (extra field alongside the API key)
	 * - Ollama endpoint URL (endpoint-based, no API key)
	 */
	private function register_extra_connector_settings(): void {
		$enabled_ids = $this->get_enabled_provider_ids();

		// Cloudflare needs an Account ID in addition to the API key that core handles.
		if ( in_array( 'cloudflare', $enabled_ids, true ) ) {
			register_setting(
				'connectors',
				'ai_cloudflare_account_id',
				array(
					'type'              => 'string',
					'label'             => __( 'Cloudflare Account ID', 'ai' ),
					'description'       => __( 'Cloudflare account ID for Workers AI API requests.', 'ai' ),
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		// Ollama is endpoint-based (local provider, no API key).
		if ( in_array( 'ollama', $enabled_ids, true ) ) {
			register_setting(
				'connectors',
				'ai_ollama_endpoint',
				array(
					'type'              => 'string',
					'label'             => __( 'Ollama Endpoint URL', 'ai' ),
					'description'       => __( 'Endpoint URL for the Ollama provider.', 'ai' ),
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_url',
				)
			);
		}
	}

	/**
	 * Applies endpoint URLs for providers that use a custom base URL instead of API keys.
	 */
	private function apply_endpoint_provider_urls(): void {
		foreach ( $this->get_enabled_provider_ids() as $provider_id ) {
			$meta = self::CONNECTOR_META[ $provider_id ] ?? array();
			$type = $meta['type'] ?? 'api_key';

			if ( 'endpoint' !== $type ) {
				continue;
			}

			$endpoint = (string) get_option( "ai_{$provider_id}_endpoint", '' );
			if ( '' === $endpoint ) {
				continue;
			}

			add_filter(
				"ai_{$provider_id}_base_url",
				static function () use ( $endpoint ): string {
					return rtrim( $endpoint, '/' ) . '/api';
				}
			);
		}
	}

	/**
	 * Registers API key settings for extended providers that core didn't handle.
	 *
	 * Runs at init:21 (after core's init:20) so we can check which settings
	 * core already registered and only fill in the gaps. This avoids duplicate
	 * mask filters that would break key retrieval.
	 */
	public function maybe_register_api_key_settings(): void {
		foreach ( $this->get_enabled_provider_ids() as $provider_id ) {
			$meta = self::CONNECTOR_META[ $provider_id ] ?? array();
			$type = $meta['type'] ?? 'api_key';

			if ( 'endpoint' === $type ) {
				continue;
			}

			$setting_name = "connectors_ai_{$provider_id}_api_key";

			// Skip if core already registered this setting (trunk behavior).
			$registered = get_registered_settings();
			if ( isset( $registered[ $setting_name ] ) ) {
				continue;
			}

			register_setting(
				'connectors',
				$setting_name,
				array(
					'type'              => 'string',
					'label'             => sprintf(
						/* translators: %s: AI provider name. */
						__( '%s API Key', 'ai' ),
						$meta['label'] ?? ucwords( $provider_id )
					),
					'description'       => sprintf(
						/* translators: %s: AI provider name. */
						__( 'API key for the %s AI provider.', 'ai' ),
						$meta['label'] ?? ucwords( $provider_id )
					),
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => static function ( $value ): string {
						return sanitize_text_field( (string) $value );
					},
				)
			);

			// Add mask filter (only one instance since we checked core didn't register).
			if ( function_exists( '_wp_connectors_mask_api_key' ) ) {
				add_filter( "option_{$setting_name}", '_wp_connectors_mask_api_key' );
			}
		}
	}

	/**
	 * Passes stored API keys to the AI client registry for providers that core didn't handle.
	 *
	 * Runs at init:22 (after core's init:20 and our settings registration at init:21).
	 */
	public function maybe_pass_keys_to_registry(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		try {
			$registry = AiClient::defaultRegistry();
		} catch ( \Throwable $t ) {
			return;
		}

		foreach ( $this->get_enabled_provider_ids() as $provider_id ) {
			$meta = self::CONNECTOR_META[ $provider_id ] ?? array();
			$type = $meta['type'] ?? 'api_key';

			if ( 'endpoint' === $type ) {
				continue;
			}

			// Skip if already configured (core handled it at init:20).
			if ( $registry->hasProvider( $provider_id ) ) {
				try {
					if ( $registry->isProviderConfigured( $provider_id ) ) {
						continue;
					}
				} catch ( \Throwable $t ) {
					// isProviderConfigured may throw; continue to try setting key.
				}
			}

			$setting_name = "connectors_ai_{$provider_id}_api_key";

			// Read unmasked value.
			if ( function_exists( '_wp_connectors_get_real_api_key' ) && function_exists( '_wp_connectors_mask_api_key' ) ) {
				$api_key = _wp_connectors_get_real_api_key( $setting_name, '_wp_connectors_mask_api_key' );
			} else {
				$api_key = (string) get_option( $setting_name, '' );
			}

			if ( '' === $api_key || ! $registry->hasProvider( $provider_id ) ) {
				continue;
			}

			try {
				$registry->setProviderRequestAuthentication(
					$provider_id,
					new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
				);
			} catch ( \Throwable $t ) {
				continue;
			}
		}
	}

	/**
	 * Enqueues the connectors script module on the Connectors admin page.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 */
	public function maybe_enqueue_connectors_script( string $hook_suffix ): void {
		if ( 'settings_page_connectors-wp-admin' !== $hook_suffix ) {
			return;
		}

		if ( ! function_exists( 'wp_register_script_module' ) ) {
			return;
		}

		$script_path = AI_EXPERIMENTS_DIR . 'assets/js/connectors-extended.js';
		if ( ! file_exists( $script_path ) ) {
			return;
		}

		$module_id = 'ai-experiments/connectors-extended';
		$deps      = array(
			array(
				'id'     => '@wordpress/connectors',
				'import' => 'static',
			),
		);

		wp_register_script_module(
			$module_id,
			AI_EXPERIMENTS_PLUGIN_URL . 'assets/js/connectors-extended.js',
			$deps,
			(string) filemtime( $script_path )
		);

		wp_enqueue_script_module( $module_id );

		// Pass provider data to JS.
		$provider_data = array();
		foreach ( $this->get_enabled_provider_ids() as $provider_id ) {
			if ( ! isset( self::CONNECTOR_META[ $provider_id ] ) ) {
				continue;
			}

			$meta = self::CONNECTOR_META[ $provider_id ];
			$type = $meta['type'] ?? 'api_key';

			$entry = array(
				'id'          => $provider_id,
				'label'       => $meta['label'],
				'description' => $meta['description'],
				'helpUrl'     => $meta['helpUrl'],
				'helpLabel'   => $meta['helpLabel'],
				'type'        => $type,
			);

			if ( 'endpoint' === $type ) {
				$entry['settingName'] = "ai_{$provider_id}_endpoint";
			} else {
				$entry['settingName'] = "connectors_ai_{$provider_id}_api_key";
			}

			$provider_data[] = $entry;
		}

		// Output data as a global before the module loads.
		add_action(
			'admin_print_footer_scripts',
			static function () use ( $provider_data ): void {
				printf(
					'<script>window.wpAiExtendedConnectors = %s;</script>',
					wp_json_encode( $provider_data )
				);
			},
			1
		);
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
					esc_html(
						sprintf(
							/* translators: %s: provider class name. */
							__( 'Extended Providers experiment could not load "%s". Make sure the class is autoloadable.', 'ai' ),
							$class_name
						)
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
					esc_html(
						sprintf(
							/* translators: 1: provider class, 2: error message. */
							__( 'Failed to register provider "%1$s": %2$s', 'ai' ),
							$class_name,
							$t->getMessage()
						)
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
				$field_id   = $option_name . '-' . md5( $class_name );
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
					static function ( $class_name ) {
						return is_string( $class_name ) ? trim( $class_name ) : '';
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
				static function ( string $class_name ) use ( $selection ): bool {
					return ! isset( $selection[ $class_name ] ) || true === $selection[ $class_name ];
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
				// Fallback to class name below.
				unset( $t );
			}
		}

		return $class_name;
	}
}
