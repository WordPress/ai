<?php
/**
 * Helper functions for the AI plugin.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI_Client\AI_Client;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

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
	 * @hook ai_pre_normalize_content
	 *
	 * @param string $post_content The post content.
	 *
	 * @return string The filtered Post content.
	 */
	$content = (string) apply_filters( 'ai_pre_normalize_content', $content );

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
	 * @hook ai_normalize_content
	 *
	 * @param string $content The normalized content.
	 *
	 * @return string The filtered normalized content.
	 */
	$content = (string) apply_filters( 'ai_normalize_content', (string) $content );

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
	$post    = get_post( $post_id );
	$context = array();

	// If the post doesn't exist, return early.
	if ( ! $post ) {
		return $context;
	}

	/**
	 * TODO: Might be interesting to add simple Abilities for the following,
	 * just as a way to demonstrate a different approach to registering Abilities,
	 * how to call Abilities via PHP and how multiple Abilities can be used together.
	 *
	 * Example: Get post content Ability; get post author Ability; get post terms Ability.
	 */

	if ( $post->post_content ) {
		$context['content'] = normalize_content( (string) apply_filters( 'the_content', $post->post_content ) );
	}

	if ( $post->post_title ) {
		$context['current_title'] = $post->post_title;
	}

	if ( $post->post_name ) {
		$context['slug'] = $post->post_name;
	}

	$author = get_user_by( 'ID', $post->post_author );
	if ( $author ) {
		$context['author'] = $author->display_name;
	}

	if ( $post->post_type ) {
		$context['content_type'] = $post->post_type;
	}

	if ( $post->post_excerpt ) {
		$context['excerpt'] = $post->post_excerpt;
	}

	$categories = get_the_terms( $post_id, 'category' );
	if ( $categories && ! is_wp_error( $categories ) ) {
		$context['categories'] = implode( ', ', wp_list_pluck( $categories, 'name' ) );
	}

	$tags = get_the_terms( $post_id, 'post_tag' );
	if ( $tags && ! is_wp_error( $tags ) ) {
		$context['tags'] = implode( ', ', wp_list_pluck( $tags, 'name' ) );
	}

	return $context;
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
			'claude-haiku-4-5',
		),
		array(
			'google',
			'gemini-2.5-flash',
		),
		array(
			'openai',
			'gpt-4o-mini',
		),
		array(
			'openai',
			'gpt-4.1',
		),
	);

	/**
	 * Filters the preferred models.
	 *
	 * @since 0.1.0
	 * @hook ai_preferred_models
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred models.
	 * @return array<int, array{string, string}> The filtered preferred models.
	 */
	return (array) apply_filters( 'ai_preferred_models', $preferred_models );
}

/**
 * Get a prompt builder.
 *
 * @since 0.1.0
 *
 * @param string|null $prompt The prompt to send.
 * @param array<string, mixed> $options The options to send.
 * @return \WordPress\AI_Client\Builders\Prompt_Builder The prompt builder.
 */
function get_prompt_builder( $prompt = null, array $options = array() ) {
	// Default arguments.
	$args = wp_parse_args(
		$options,
		array(
			'model'    => null,
			'provider' => null,
		),
	);

	unset( $options['model'], $options['provider'] );

	$model_config   = process_model_config( $options );
	$prompt_builder = AI_Client::prompt_with_wp_error( $prompt );
	$prompt_builder = $prompt_builder->using_model_config( $model_config );

	if ( ! empty( $args['provider'] ) ) {
		$prompt_builder = $prompt_builder->using_provider( $args['provider'] );

		// Set the model.
		if ( ! empty( $args['model'] ) ) {
			$registry            = AiClient::defaultRegistry();
			$provider_class_name = $registry->getProviderClassName( $args['provider'] );
			$prompt_builder      = $prompt_builder->using_model( $provider_class_name::model( $args['model'] ) );
		}
	}

	// Set our preferred models if no model is specified.
	if ( empty( $args['model'] ) ) {
		$prompt_builder = $prompt_builder->using_model_preference( ...get_preferred_models() );
	}

	return $prompt_builder;
}

/**
 * Process the model config.
 *
 * @since 0.1.0
 *
 * @param array<string, mixed> $options The options to add to the model config.
 * @return \WordPress\AiClient\Providers\Models\DTO\ModelConfig
 */
function process_model_config( array $options = array() ): ModelConfig {
	$schema       = ModelConfig::getJsonSchema()['properties'];
	$model_config = array();

	foreach ( $options as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue;
		}

		$property_schema = $schema[ $key ];
		$type            = $property_schema['type'] ?? null;

		$processed_value = (string) $value;

		if ( 'array' === $type || 'object' === $type ) {
			$processed_value = (array) $value;
		} elseif ( 'integer' === $type ) {
			$processed_value = (int) $value;
		} elseif ( 'number' === $type ) {
			$processed_value = (float) $value;
		} elseif ( 'boolean' === $type ) {
			$processed_value = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			if ( null === $processed_value ) {
				continue;
			}
		}

		$model_config[ $key ] = $processed_value;
	}

	// @phpstan-ignore-next-line - fromArray() validates the array shape at runtime.
	return ModelConfig::fromArray( $model_config );
}
