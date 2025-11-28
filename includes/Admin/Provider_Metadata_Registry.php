<?php
/**
 * Shared provider metadata registry for admin UIs.
 *
 * @package WordPress\AI\Admin
 */

namespace WordPress\AI\Admin;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

use function __;
use function esc_html__;
use function get_option;
use function get_transient;
use function is_array;
use function is_string;
use function sprintf;
use function trim;
use function wp_json_encode;
use function set_transient;
use function md5;

/**
 * Provides a single source of truth for provider metadata and branding.
 */
class Provider_Metadata_Registry {
	/**
	 * Cache TTL for provider model metadata.
	 */
	private const MODEL_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Returns structured metadata for all registered providers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_metadata(): array {
		$registry  = AiClient::defaultRegistry();
		$providers = array();
		$overrides = self::get_branding_overrides();
		$credentials = get_option( 'wp_ai_client_provider_credentials', array() );

		foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
			$class_name = $registry->getProviderClassName( $provider_id );

			if ( ! method_exists( $class_name, 'metadata' ) ) {
				continue;
			}

			/** @var ProviderMetadata $metadata */
			$metadata = $class_name::metadata();
			$brand    = $overrides[ $metadata->getId() ] ?? array();

			$providers[ $metadata->getId() ] = array(
				'id'              => $metadata->getId(),
				'name'            => $metadata->getName(),
				'type'            => $metadata->getType()->value,
				'icon'            => $brand['icon'] ?? $metadata->getId(),
				'initials'        => $brand['initials'] ?? self::get_initials( $metadata->getName() ),
				'color'           => $brand['color'] ?? '#1d2327',
				'url'             => $brand['url'] ?? '',
				'tooltip'         => $brand['tooltip'] ?? '',
				'keepDescription' => ! empty( $brand['keepDescription'] ),
				'isConfigured'    => self::has_credentials( $metadata->getId(), $credentials ),
				'models'          => self::get_models_for_provider( $class_name, $metadata->getId(), $credentials ),
			);
		}

		return $providers;
	}

	/**
	 * Builds a fallback initials string for providers without a brand override.
	 *
	 * @param string $name Provider display name.
	 * @return string
	 */
	private static function get_initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		if ( empty( $parts ) ) {
			return strtoupper( substr( $name, 0, 2 ) );
		}

		$initials = '';
		foreach ( $parts as $part ) {
			$initials .= strtoupper( substr( $part, 0, 1 ) );
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}

		return substr( $initials, 0, 2 );
	}

	/**
	 * Retrieves model metadata for a provider.
	 *
	 * @param string $provider_class Provider class name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_models_for_provider( string $provider_class, string $provider_id, array $credentials ): array {
		if ( ! method_exists( $provider_class, 'modelMetadataDirectory' ) ) {
			return array();
		}

		$cache_key = self::get_models_cache_key( $provider_id, $credentials[ $provider_id ] ?? '' );
		if ( $cache_key ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		try {
			$directory = $provider_class::modelMetadataDirectory();
			$metadata  = $directory->listModelMetadata();
		} catch ( \Throwable $error ) {
			return array();
		}

		$models = array();

		foreach ( $metadata as $model_metadata ) {
			if ( ! $model_metadata instanceof ModelMetadata ) {
				continue;
			}

			$models[] = array(
				'id'           => $model_metadata->getId(),
				'name'         => $model_metadata->getName(),
				'capabilities' => array_map(
					static function ( CapabilityEnum $capability ): string {
						return $capability->value;
					},
					$model_metadata->getSupportedCapabilities()
				),
			);
		}

		if ( $cache_key ) {
			set_transient( $cache_key, $models, self::MODEL_CACHE_TTL );
		}

		return $models;
	}

	/**
	 * Determines whether stored credentials exist for a provider.
	 *
	 * @param string               $provider_id Provider identifier.
	 * @param array<string, mixed> $credentials Raw credentials map.
	 * @return bool
	 */
	private static function has_credentials( string $provider_id, array $credentials ): bool {
		if ( 'ollama' === $provider_id ) {
			return true;
		}

		if ( ! isset( $credentials[ $provider_id ] ) ) {
			return false;
		}

		$value = $credentials[ $provider_id ];
		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}

		return is_string( $value ) && '' !== trim( $value );
	}

	/**
	 * Builds a cache key for provider models.
	 *
	 * @param string              $provider_id Provider identifier.
	 * @param string|array<mixed> $credential  Credential value.
	 * @return string|null
	 */
	private static function get_models_cache_key( string $provider_id, $credential ): ?string {
		if ( '' === $provider_id ) {
			return null;
		}

		if ( is_array( $credential ) ) {
			$credential = wp_json_encode( $credential );
		}

		return 'ai_provider_models_' . md5( $provider_id . '|' . (string) $credential );
	}

	/**
	 * Defines manual branding overrides per provider ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_branding_overrides(): array {
		$link_template = esc_html__( 'Create and manage your %s API keys in these account settings.', 'ai' );

		return array(
			'anthropic'     => array(
				'icon'     => 'anthropic',
				'initials' => 'An',
				'color'    => '#111111',
				'url'      => 'https://console.anthropic.com/settings/keys',
				'tooltip'  => sprintf( $link_template, 'Anthropic' ),
			),
			'cohere'        => array(
				'color'   => '#6f2cff',
				'url'     => 'https://dashboard.cohere.com/api-keys',
				'tooltip' => sprintf( $link_template, 'Cohere' ),
			),
			'cloudflare'    => array(
				'icon'    => 'cloudflare',
				'color'   => '#f3801a',
				'url'     => 'https://dash.cloudflare.com/profile/api-tokens',
				'tooltip' => sprintf( $link_template, 'Cloudflare Workers AI' ),
			),
			'deepseek'      => array(
				'icon'    => 'deepseek',
				'color'   => '#0f172a',
				'url'     => 'https://platform.deepseek.com/api_keys',
				'tooltip' => sprintf( $link_template, 'DeepSeek' ),
			),
			'fal'           => array(
				'icon'    => 'fal',
				'color'   => '#0ea5e9',
				'url'     => 'https://fal.ai/dashboard/keys',
				'tooltip' => sprintf( $link_template, 'Fal.ai' ),
			),
			'fal-ai'        => array(
				'icon'    => 'fal-ai',
				'color'   => '#0ea5e9',
				'url'     => 'https://fal.ai/dashboard/keys',
				'tooltip' => sprintf( $link_template, 'Fal.ai' ),
			),
			'grok'          => array(
				'icon'    => 'grok',
				'color'   => '#ff6f00',
				'url'     => 'https://console.x.ai/api-keys',
				'tooltip' => sprintf( $link_template, 'Grok' ),
			),
			'groq'          => array(
				'icon'    => 'groq',
				'color'   => '#f43f5e',
				'url'     => 'https://console.groq.com/keys',
				'tooltip' => sprintf( $link_template, 'Groq' ),
			),
			'google'        => array(
				'icon'    => 'google',
				'color'   => '#4285f4',
				'url'     => 'https://aistudio.google.com/app/api-keys',
				'tooltip' => sprintf( $link_template, 'Google' ),
			),
			'huggingface'   => array(
				'icon'    => 'huggingface',
				'color'   => '#ffbe3c',
				'url'     => 'https://huggingface.co/settings/tokens',
				'tooltip' => sprintf( $link_template, 'Hugging Face' ),
			),
			'openai'        => array(
				'icon'    => 'openai',
				'color'   => '#10a37f',
				'url'     => 'https://platform.openai.com/api-keys',
				'tooltip' => sprintf( $link_template, 'OpenAI' ),
			),
			'openrouter'    => array(
				'icon'    => 'openrouter',
				'color'   => '#0f172a',
				'url'     => 'https://openrouter.ai/settings/keys',
				'tooltip' => sprintf( $link_template, 'OpenRouter' ),
			),
			'ollama'        => array(
				'icon'            => 'ollama',
				'color'           => '#111111',
				'tooltip'         => esc_html__( 'Local Ollama instances at http://localhost:11434 do not require an API key. If you are calling https://ollama.com/api, create a key from your ollama.com account (for example via the dashboard or the `ollama signin` command) and paste it here.', 'ai' ),
				'keepDescription' => true,
			),
			'xai'           => array(
				'icon'    => 'xai',
				'color'   => '#000000',
				'url'     => 'https://console.x.ai/api-keys',
				'tooltip' => sprintf( $link_template, 'xAI' ),
			),
		);
	}
}
