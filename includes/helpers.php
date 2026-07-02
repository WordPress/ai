<?php
/**
 * Helper functions for the AI plugin.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use Throwable;
use WordPress\AI\Services\AI_Service;
use WordPress\AI\Services\Guidelines;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Purposely using return instead of exit here.
 *
 * This file is loaded via the composer files directive.
 * When tools like PHPCS and PHPStan run, they include
 * our composer autoloader and that will then load this file,
 * causing the script to exit and not function properly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Normalizes the content by cleaning it and removing unwanted HTML tags.
 *
 * @since 0.1.0
 *
 * @param string $content The content to normalize.
 * @return string The normalized content.
 */
function normalize_content( string $content ): string {
	/**
	 * Hook to filter content before cleaning it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_content The post content.
	 *
	 * @return string The filtered Post content.
	 */
	$content = (string) apply_filters( 'wpai_pre_normalize_content', $content );

	// Strip HTML entities.
	$content = preg_replace( '/&#?[a-z0-9]{2,8};/i', '', $content ) ?? $content;

	// Replace HTML linebreaks with newlines.
	$content = preg_replace( '#<br\s?/?>#', "\n\n", $content ) ?? $content;

	// Remove linebreaks but replace with spaces to avoid sentences running together.
	$content = str_replace( array( "\r", "\n" ), ' ', (string) $content );

	// Strip all HTML tags.
	$content = wp_strip_all_tags( (string) $content );

	// Remove unrendered shortcode tags.
	$content = preg_replace( '#\[.+\](.+)\[/.+\]#', '$1', $content ) ?? $content;

	/**
	 * Filters the normalized content to allow for additional cleanup.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The normalized content.
	 *
	 * @return string The filtered normalized content.
	 */
	$content = (string) apply_filters( 'wpai_normalize_content', (string) $content );

	return trim( $content );
}

/**
 * Counts characters excluding whitespace, with Unicode support.
 *
 * This approximately mirrors @wordpress/wordcount's
 * `characters_excluding_spaces` strategy used in the editor.
 *
 * @since 1.1.0
 *
 * @param string $text The text to count characters in.
 * @return int The number of non-whitespace characters.
 */
function count_characters_excluding_spaces( string $text ): int {
	if ( empty( $text ) ) {
		return 0;
	}

	// Strip all HTML tags including comments.
	$text = wp_strip_all_tags( $text );

	// Normalize NBSP entities to whitespace.
	$text = preg_replace( '/&nbsp;|&#160;/i', ' ', $text ) ?? $text;

	// Transpose HTML entities to countable characters.
	$text = preg_replace( '/&\S+?;/u', 'a', $text ) ?? $text;

	/*
	 * Count non-whitespace code points using a class that mirrors JavaScript's
	 * \s semantics, so full-width CJK spaces and similar separators match the
	 * editor's @wordpress/wordcount result.
	 */
	$whitespace = '\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
	$count      = preg_match_all( sprintf( '/[^%s]/u', $whitespace ), $text );

	return is_int( $count ) ? $count : 0;
}

/**
 * Returns the context for the given post ID.
 *
 * @since 0.1.0
 *
 * @param int $post_id The ID of the post to get the context for.
 * @return array<string, string> The context for the given post ID.
 */
function get_post_context( int $post_id ): array {
	$context = array();

	// Get the post details using the get-post-details ability.
	$details_ability = wp_get_ability( 'ai/get-post-details' );
	if ( $details_ability ) {
		$details = $details_ability->execute( array( 'post_id' => $post_id ) );

		if ( is_array( $details ) ) {
			$context = array_merge( $context, $details );

			if ( isset( $context['content'] ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				$context['content'] = normalize_content( (string) apply_filters( 'the_content', $context['content'] ) );
			}

			if ( isset( $context['type'] ) ) {
				$context['content_type'] = $context['type'];
				unset( $context['type'] );
			}

			// Remove any empty context values.
			$context = array_filter( $context );
		}
	}

	// Get the post terms using the get-terms ability.
	$terms_ability = wp_get_ability( 'ai/get-post-terms' );
	if ( $terms_ability ) {
		$terms = $terms_ability->execute( array( 'post_id' => $post_id ) );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$grouped_terms = array();

			foreach ( $terms as $term ) {
				$taxonomy = $term['taxonomy'] ?? '';
				$name     = $term['name'] ?? '';

				if ( '' === $taxonomy || '' === $name ) {
					continue;
				}

				$grouped_terms[ $taxonomy ][] = $name;
			}

			$context = array_merge(
				$context,
				array_map(
					static fn( array $term_names ): string => implode( ', ', $term_names ),
					$grouped_terms
				)
			);
		}
	}

	return $context;
}

/**
 * Returns the preferred models for text generation.
 *
 * @since 0.2.1
 *
 * @return array<int, array{string, string}> The preferred models for text generation.
 */
function get_preferred_models_for_text_generation(): array {
	$preferred_models = array(
		array(
			'anthropic',
			'claude-sonnet-4-6',
		),
		array(
			'google',
			'gemini-3-flash-preview',
		),
		array(
			'google',
			'gemini-2.5-flash',
		),
		array(
			'openai',
			'gpt-5.4-mini',
		),
		array(
			'openai',
			'gpt-4.1-mini',
		),
	);

	/**
	 * Filters the preferred models for text generation.
	 *
	 * @since 0.2.1
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred models for text generation.
	 * @return array<int, array{string, string}> The filtered preferred models.
	 */
	return (array) apply_filters( 'wpai_preferred_text_models', $preferred_models );
}

/**
 * Gets the AI Service instance.
 *
 * Provides a convenient way to access the AI Service for performing AI operations.
 *
 * Example usage:
 * ```php
 * $service = WordPress\AI\get_ai_service();
 *
 * // Check if text generation is supported before generating
 * $builder = $service->create_textgen_prompt( 'Summarize this article...' );
 * if ( ! $builder->is_supported_for_text_generation() ) {
 *     return new WP_Error( 'ai_unsupported', 'No AI provider supports text generation.' );
 * }
 * $text = $builder->generate_text();
 *
 * // With options array
 * $text = $service->create_textgen_prompt( 'Translate to French: Hello', array(
 *     'system_instruction' => 'You are a translator.',
 *     'temperature'        => 0.3,
 * ) )->generate_text();
 *
 * // Chain additional SDK methods
 * $titles = $service->create_textgen_prompt( 'Generate titles for: My blog post' )
 *     ->using_candidate_count( 5 )
 *     ->generate_texts();
 * ```
 *
 * @since 0.2.1
 *
 * @return \WordPress\AI\Services\AI_Service The AI Service instance.
 */
function get_ai_service(): AI_Service {
	return AI_Service::get_instance();
}

/**
 * Returns the preferred image models.
 *
 * @since 0.2.0
 *
 * @return array<int, array{string, string}> The preferred image models.
 */
function get_preferred_image_models(): array {
	$preferred_models = array(
		array(
			'google',
			'gemini-3.1-flash-image-preview',
		),
		array(
			'google',
			'gemini-3-pro-image-preview',
		),
		array(
			'google',
			'gemini-2.5-flash-image',
		),
		array(
			'google',
			'imagen-4.0-generate-001',
		),
		array(
			'openai',
			'gpt-image-2',
		),
		array(
			'openai',
			'gpt-image-1.5',
		),
	);

	/**
	 * Filters the preferred image models.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred image models.
	 * @return array<int, array{string, string}> The filtered preferred image models.
	 */
	return (array) apply_filters( 'wpai_preferred_image_models', $preferred_models );
}

/**
 * Returns the preferred vision models.
 *
 * @since 0.3.0
 *
 * @return array<int, array{string, string}> The preferred vision models.
 */
function get_preferred_vision_models(): array {
	$preferred_models = array(
		array(
			'anthropic',
			'claude-sonnet-4-6',
		),
		array(
			'google',
			'gemini-3-flash-preview',
		),
		array(
			'google',
			'gemini-2.5-flash',
		),
		array(
			'openai',
			'gpt-5.4-mini',
		),
		array(
			'openai',
			'gpt-4.1-mini',
		),
	);

	/**
	 * Filters the preferred vision models.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred vision models.
	 * @return array<int, array{string, string}> The filtered preferred vision models.
	 */
	return (array) apply_filters( 'wpai_preferred_vision_models', $preferred_models );
}

/**
 * Returns the developer-mode provider/model config saved for a feature.
 *
 * @since 0.9.0
 *
 * @param string $feature_id The feature ID (e.g. 'excerpt-generation').
 * @return array{provider: string, model: string} The saved provider and model, or empty strings if unset.
 */
function get_feature_developer_model_config( string $feature_id ): array {
	$option = get_option( "wpai_feature_{$feature_id}_field_developer", array() );
	return array(
		'provider' => is_array( $option ) ? ( $option['provider'] ?? '' ) : '',
		'model'    => is_array( $option ) ? ( $option['model'] ?? '' ) : '',
	);
}

/**
 * Retrieves guidelines, optionally filtered by category.
 *
 * @since 0.8.0
 *
 * @param string|null $category Optional. Guideline category to retrieve.
 * @return array<string, string>|null Keyed array of guidelines, or null when unavailable.
 */
function get_guidelines( ?string $category = null ): ?array {
	return Guidelines::get_instance()->get_guidelines( $category );
}

/**
 * Formats guidelines as an XML-tagged string for prompt injection.
 *
 * @since 0.8.0
 *
 * @param list<string> $categories Guideline category slugs to include.
 * @param string|null  $block_name Optional block name for block-specific guidelines.
 * @return string Formatted guidelines XML string, or empty string.
 */
function format_guidelines_for_prompt( array $categories, ?string $block_name = null ): string {
	return Guidelines::get_instance()->format_for_prompt( $categories, $block_name );
}

/**
 * Determines if a connector is configured.
 *
 * @since 1.0.1
 *
 * @param string $connector_id The connector ID.
 * @return bool True if the connector is configured, false otherwise.
 */
function is_connector_configured( string $connector_id ): bool {
	$registry = AiClient::defaultRegistry();
	return $registry->hasProvider( $connector_id ) && $registry->isProviderConfigured( $connector_id );
}

/**
 * Determines if a connector has authentication in place.
 *
 * This checks for API-key credentials by source only (environment variable,
 * PHP constant, or stored option) and does not make external API requests.
 *
 * @since 1.0.1
 *
 * @param string $connector_id The connector ID.
 * @return bool True if connector authentication is present, false otherwise.
 */
function has_connector_authentication( string $connector_id ): bool {
	if ( ! wp_is_connector_registered( $connector_id ) ) {
		return false;
	}

	$connector = wp_get_connector( $connector_id );
	if ( ! is_array( $connector ) ) {
		return false;
	}

	$auth = $connector['authentication'] ?? null;
	if ( ! is_array( $auth ) || ( $auth['method'] ?? '' ) !== 'api_key' ) {
		return false;
	}

	$setting_name = $auth['setting_name'] ?? '';
	if ( ! is_string( $setting_name ) || '' === $setting_name ) {
		return false;
	}

	return 'none' !== get_connector_api_key_source(
		$setting_name,
		$auth['env_var_name'] ?? '',
		$auth['constant_name'] ?? ''
	);
}

/**
 * Determines the source of a connector API key.
 *
 * Checks in order: environment variable, PHP constant, database option.
 *
 * @since 1.0.1
 *
 * @param string $setting_name  The option name for the API key.
 * @param string $env_var_name  Optional environment variable name.
 * @param string $constant_name Optional PHP constant name.
 * @return string The key source: 'env', 'constant', 'database', or 'none'.
 */
function get_connector_api_key_source( string $setting_name, string $env_var_name = '', string $constant_name = '' ): string {
	if ( '' !== $env_var_name ) {
		$env_value = getenv( $env_var_name );
		if ( false !== $env_value && '' !== $env_value ) {
			return 'env';
		}
	}

	if ( '' !== $constant_name && defined( $constant_name ) ) {
		$const_value = constant( $constant_name );
		if ( is_string( $const_value ) && '' !== $const_value ) {
			return 'constant';
		}
	}

	$db_value = get_option( $setting_name, '' );
	if ( '' !== $db_value ) {
		return 'database';
	}

	return 'none';
}

/**
 * Checks if we have AI credentials set.
 *
 * @since 0.1.0
 *
 * @return bool True if we have AI credentials, false otherwise.
 */
function has_ai_credentials(): bool {
	$connectors      = get_ai_connectors();
	$has_credentials = false;

	foreach ( $connectors as $connector_id => $connector_data ) {
		$auth = $connector_data['authentication'];
		if ( 'api_key' !== $auth['method'] ) {
			continue;
		}

		if ( ! has_connector_authentication( $connector_id ) ) {
			continue;
		}

		$has_credentials = true;
		break;
	}

	/**
	 * Filters whether AI credentials are available.
	 *
	 * Allows third-party plugins to declare credential availability for
	 * connectors that do not rely on API key settings.
	 *
	 * @since 0.7.0
	 *
	 * @param bool  $has_credentials Whether AI credentials are available.
	 * @param array $connectors      The registered connectors.
	 */
	return (bool) apply_filters( 'wpai_has_ai_credentials', $has_credentials, $connectors );
}

/**
 * Checks whether any configured connector exposes an image-generation-capable model.
 *
 * @since 1.0.2
 *
 * @param bool $reset_cache Whether to bypass the static cache and recompute. Default false.
 * @return bool True if at least one connector supports image generation.
 */
function has_image_generation_support( bool $reset_cache = false ): bool {
	static $result = null;

	if ( ! $reset_cache && null !== $result ) {
		return $result;
	}

	$connectors  = array();
	$has_support = false;

	if ( class_exists( AiClient::class ) ) {
		$registry   = AiClient::defaultRegistry();
		$connectors = get_ai_connectors();

		foreach ( array_keys( $connectors ) as $connector_id ) {
			if ( ! has_connector_authentication( $connector_id ) ) {
				continue;
			}

			try {
				$provider_class = $registry->getProviderClassName( $connector_id );

				/** @var \WordPress\AiClient\Providers\Contracts\ProviderInterface $provider_class */
				$models = $provider_class::modelMetadataDirectory()->listModelMetadata();

				foreach ( $models as $model ) {
					foreach ( $model->getSupportedCapabilities() as $capability ) {
						if ( CapabilityEnum::IMAGE_GENERATION === $capability->value ) {
							$has_support = true;
							break 3;
						}
					}
				}
			} catch ( Throwable $e ) {
				continue;
			}
		}
	}

	/**
	 * Filters whether image generation is supported.
	 *
	 * Allows third-party plugins to declare image generation support for
	 * connectors that do not rely on API key settings (e.g. OAuth), without
	 * triggering a live API request.
	 *
	 * @since 1.1.0
	 *
	 * @param bool  $has_support Whether image generation is supported.
	 * @param array $connectors  The registered connectors.
	 */
	$result = (bool) apply_filters( 'wpai_has_image_generation_support', $has_support, $connectors );

	return $result;
}

/**
 * Returns provider availability data for script localization.
 *
 * @since 1.0.0
 *
 * @return array{hasProvider: bool, connectorsUrl: string} Provider availability data.
 */
function get_provider_availability_data(): array {
	return array(
		'hasProvider'   => has_ai_credentials(),
		'connectorsUrl' => admin_url( 'options-connectors.php' ),
	);
}

/**
 * Checks if we have valid AI credentials.
 *
 * @since 0.1.0
 *
 * @return bool True if we have valid AI credentials, false otherwise.
 */
function has_valid_ai_credentials(): bool {
	// If we have no AI credentials, return false.
	if ( ! has_ai_credentials() ) {
		return false;
	}

	/**
	 * Filters whether valid AI credentials are available.
	 *
	 * Allows overriding the credentials check, useful for testing.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $has_valid_credentials Whether valid credentials are available. Return null to use default check.
	 * @return bool|null True if valid credentials are available, false otherwise, or null to use default check.
	 */
	$valid = apply_filters( 'wpai_pre_has_valid_credentials_check', null );
	if ( null !== $valid ) {
		return (bool) $valid;
	}

	// See if we have credentials that give us access to generate text.
	try {
		return wp_ai_client_prompt( 'Test' )->is_supported_for_text_generation();
	} catch ( Throwable $t ) {
		return false;
	}
}

/**
 * Returns the AI connectors.
 *
 * @since 0.9.0
 *
 * @param bool $active_only Whether to only return active connectors.
 * @return array<string, array<string, mixed>> The AI connectors.
 */
function get_ai_connectors( bool $active_only = true ): array {
	$connectors = array();

	foreach ( (array) wp_get_connectors() as $connector_id => $data ) {
		if ( ! is_string( $connector_id ) || ! is_array( $data ) ) {
			continue;
		}

		if ( ( $data['type'] ?? '' ) !== 'ai_provider' ) {
			continue;
		}

		if ( $active_only && ! is_connector_plugin_active( $data ) ) {
			continue;
		}

		$connectors[ $connector_id ] = $data;
	}

	return $connectors;
}

/**
 * Checks whether the connector's related plugin is currently active.
 *
 * If plugin metadata is not provided for a connector, it is treated as active.
 *
 * @since 0.9.0
 *
 * @param array<string, mixed> $connector_data Connector metadata.
 * @return bool True if the connector plugin is active or unknown, false if known inactive.
 */
function is_connector_plugin_active( array $connector_data ): bool {
	if ( empty( $connector_data['plugin'] ) || ! is_array( $connector_data['plugin'] ) ) {
		return true;
	}

	$plugin_file = '';

	if ( ! empty( $connector_data['plugin']['file'] ) && is_string( $connector_data['plugin']['file'] ) ) {
		$plugin_file = $connector_data['plugin']['file'];
	} elseif ( ! empty( $connector_data['plugin']['plugin_file'] ) && is_string( $connector_data['plugin']['plugin_file'] ) ) {
		$plugin_file = $connector_data['plugin']['plugin_file'];
	} elseif ( ! empty( $connector_data['plugin']['pluginFile'] ) && is_string( $connector_data['plugin']['pluginFile'] ) ) {
		$plugin_file = $connector_data['plugin']['pluginFile'];
	}

	if ( '' === $plugin_file ) {
		return true;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( $plugin_file ) ) {
		return true;
	}

	return is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file );
}

/**
 * Returns the minimum content length in characters required for a given feature.
 *
 * @since 1.1.0
 *
 * @param string $feature_id     The feature identifier (e.g. 'content-resizing', 'content-classification', 'summarization').
 * @param int    $content_length The default minimum content length in characters for the feature.
 * @return int The minimum content length in characters.
 */
function get_min_content_length( string $feature_id, int $content_length = 250 ): int {
	/**
	 * Filters the minimum content length required for a feature.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $content_length The minimum content length in characters for the feature.
	 * @param string $feature_id     The feature identifier.
	 */
	return (int) apply_filters( 'wpai_min_content_length', $content_length, $feature_id );
}
