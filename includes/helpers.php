<?php
/**
 * Helper functions for the AI plugin.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Settings\Settings_Registration;

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
	$content = (string) apply_filters( 'ai_experiments_pre_normalize_content', $content );

	// Strip HTML entities.
	$content = preg_replace( '/&#?[a-z0-9]{2,8};/i', '', $content );

	// Replace HTML linebreaks with newlines.
	$content = preg_replace( '#<br\s?/?>#', "\n\n", (string) $content );

	// Strip all HTML tags.
	$content = wp_strip_all_tags( (string) $content );

	// Remove unrendered shortcode tags.
	$content = preg_replace( '#\[.+\](.+)\[/.+\]#', '$1', $content );

	/**
	 * Filters the normalized content to allow for additional cleanup.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The normalized content.
	 *
	 * @return string The filtered normalized content.
	 */
	$content = (string) apply_filters( 'ai_experiments_normalize_content', (string) $content );

	return trim( $content );
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

			if ( isset( $context['title'] ) ) {
				$context['current_title'] = $context['title'];
				unset( $context['title'] );
			}

			if ( isset( $context['type'] ) ) {
				$context['content_type'] = $context['type'];
				unset( $context['type'] );
			}
		}
	}

	// Get the post terms using the get-terms ability.
	$terms_ability = wp_get_ability( 'ai/get-post-terms' );
	if ( $terms_ability ) {
		$terms = $terms_ability->execute( array( 'post_id' => $post_id ) );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$grouped_terms = array();

			foreach ( $terms as $term ) {
				$grouped_terms[ $term->taxonomy ][] = $term->name;
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
 * Extends HTTP timeout for OpenAI requests.
 *
 * The default WordPress timeout (5 seconds) is too short for some AI
 * completions and results in cURL error 28. Increase it to 20 seconds
 * whenever we call known AI REST endpoints, guarding against direct CLI
 * execution where WordPress functions are unavailable.
 *
 * @since 0.1.0
 *
 * @param array<string,mixed> $args HTTP request args.
 * @param string              $url  Request URL.
 * @return array<string,mixed>
 */
if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'http_request_args',
		static function ( array $args, string $url ): array {
			$ai_hosts = array(
				'api.openai.com',
				'api.anthropic.com',
				'generativelanguage.googleapis.com',
			);

			foreach ( $ai_hosts as $host ) {
				if ( false !== strpos( $url, $host ) ) {
					$args['timeout'] = max( (float) ( $args['timeout'] ?? 5 ), 20 );
					break;
				}
			}

			return $args;
		},
		10,
		2
	);
}

/**
 * Returns the preferred models.
 *
 * @since 0.1.0
 *
 * @return array<int, array{string, string}> The preferred models.
 */
function get_preferred_models(): array {
	$preferred_models = array(
		array(
			'anthropic',
			'claude-3-5-sonnet-20240620',
		),
		array(
			'anthropic',
			'claude-3-haiku-20240307',
		),
		array(
			'openai',
			'gpt-4o-mini',
		),
		array(
			'openai',
			'gpt-4o',
		),
		array(
			'google',
			'gemini-1.5-flash',
		),
	);

	/**
	 * Filters the preferred models.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred models.
	 * @return array<int, array{string, string}> The filtered preferred models.
	 */
	$preferred_models = (array) apply_filters( 'ai_experiments_preferred_models', $preferred_models );

	$grouped = array(
		'anthropic' => array(),
		'openai'    => array(),
		'google'    => array(),
		'other'     => array(),
	);

	foreach ( $preferred_models as $model ) {
		if ( ! is_array( $model ) || count( $model ) < 2 ) {
			continue;
		}

		$provider = strtolower( (string) $model[0] );

		if ( isset( $grouped[ $provider ] ) ) {
			$grouped[ $provider ][] = $model;
			continue;
		}

		$grouped['other'][] = $model;
	}

	return array_merge(
		$grouped['anthropic'],
		$grouped['openai'],
		$grouped['google'],
		$grouped['other']
	);
}

/**
 * Returns the preferred image models.
 *
 * @since x.x.x
 *
 * @return array<int, array{string, string}> The preferred image models.
 */
function get_preferred_image_models(): array {
	$preferred_models = array(
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
			'gpt-image-1',
		),
		array(
			'openai',
			'dall-e-3',
		),
	);

	/**
	 * Filters the preferred image models.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred image models.
	 * @return array<int, array{string, string}> The filtered preferred image models.
	 */
	return (array) apply_filters( 'ai_experiments_preferred_image_models', $preferred_models );
}

/**
 * Checks if we have AI credentials set.
 *
 * @since 0.1.0
 *
 * @return bool True if we have AI credentials, false otherwise.
 */
function has_ai_credentials(): bool {
	$credentials = get_option( 'wp_ai_client_provider_credentials', array() );

	// If there are no credentials, return false.
	if ( ! is_array( $credentials ) || empty( $credentials ) ) {
		return false;
	}

	// If all of the AI keys are empty, return false; otherwise, return true.
	return ! empty(
		array_filter(
			$credentials,
			static function ( $api_key ): bool {
				return is_string( $api_key ) && '' !== $api_key;
			}
		)
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
	$valid = apply_filters( 'ai_experiments_pre_has_valid_credentials_check', null );
	if ( null !== $valid ) {
		return (bool) $valid;
	}

	return true;
}

/**
 * Checks if a specific experiment is enabled via global + per-experiment toggles.
 *
 * Mirrors {@see Abstract_Experiment::is_enabled()} so infrastructure that runs
 * before experiments register can honor user settings.
 *
 * @since 0.1.0
 *
 * @param string $experiment_id Experiment identifier (e.g. 'ai-request-logging').
 * @return bool
 */
function is_experiment_enabled( string $experiment_id ): bool {
	$global_enabled = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
	if ( ! $global_enabled ) {
		return false;
	}

	$experiment_enabled = (bool) get_option( "ai_experiment_{$experiment_id}_enabled", false );

	/**
	 * Filters the enabled status for a specific experiment.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $experiment_enabled Default enabled state from the option.
	 */
	$is_enabled = (bool) apply_filters( "ai_experiment_{$experiment_id}_enabled", $experiment_enabled );

	if ( ! has_valid_ai_credentials() ) {
		return false;
	}

	return $is_enabled;
}

/**
 * Get the shared AI request log manager instance.
 *
 * @since 0.1.0
 *
 * @return AI_Request_Log_Manager|null
 */
function get_request_log_manager(): ?AI_Request_Log_Manager {
	static $log_manager = null;

	if ( null === $log_manager && class_exists( AI_Request_Log_Manager::class ) ) {
		$log_manager = new AI_Request_Log_Manager();
	}

	return $log_manager;
}

/**
 * Returns the AI icon SVG markup for inline use.
 *
 * Reads the icon from assets/images/ai-icon.svg and adds width/height attributes.
 *
 * @since 0.1.0
 *
 * @param string $width  Optional. Width of the icon. Default '1em'.
 * @param string $height Optional. Height of the icon. Default '1em'.
 * @return string The SVG markup for the AI icon.
 */
function get_ai_icon_svg( string $width = '1em', string $height = '1em' ): string {
	static $svg_content = null;

	if ( null === $svg_content ) {
		$svg_path    = dirname( __DIR__ ) . '/assets/images/ai-icon.svg';
		$svg_content = file_exists( $svg_path ) ? file_get_contents( $svg_path ) : '';
	}

	if ( empty( $svg_content ) ) {
		return '';
	}

	// Add width and height attributes, and fill="currentColor" for theme compatibility.
	return preg_replace(
		'/<svg\b/',
		sprintf( '<svg width="%s" height="%s" fill="currentColor"', esc_attr( $width ), esc_attr( $height ) ),
		$svg_content,
		1
	);
}

/**
 * Returns the AI icon as a base64 data URI for use in admin menu icons.
 *
 * @since 0.1.0
 *
 * @return string The base64-encoded data URI for the AI icon.
 */
function get_ai_icon_data_uri(): string {
	static $data_uri = null;

	if ( null === $data_uri ) {
		$svg_path = dirname( __DIR__ ) . '/assets/images/ai-icon.svg';

		if ( file_exists( $svg_path ) ) {
			$svg_content = file_get_contents( $svg_path );
			// Replace currentColor with a neutral color for admin menu compatibility.
			$svg_content = str_replace( 'fill="currentColor"', 'fill="black"', $svg_content );
			$data_uri    = 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
		} else {
			$data_uri = '';
		}
	}

	return $data_uri;
}
