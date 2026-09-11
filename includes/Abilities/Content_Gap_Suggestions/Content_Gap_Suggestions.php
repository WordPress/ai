<?php
/**
 * Content Gap Suggestions WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Gap_Suggestions;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Content_Gap_Suggestions\Content_Gap_Suggestions as Content_Gap_Suggestions_Experiment;
use WordPress\AI\Stats\Anonymizer;
use WordPress\AI\Stats\Stats_Provider_Registry;

/**
 * Content Gap Suggestions WordPress Ability.
 *
 * Fetches search patterns from the active Stats_Provider, anonymizes them,
 * and asks the AI model for post topic suggestions. Raw stats data never
 * leaves this method - only anonymized, aggregated patterns are sent to
 * the AI provider.
 *
 * @since x.x.x
 */
class Content_Gap_Suggestions extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'limit' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10,
					'description' => esc_html__( 'Maximum number of suggestions to return.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title'   => array( 'type' => 'string' ),
							'outline' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$args  = wp_parse_args( is_array( $input ) ? $input : array(), array( 'limit' => 5 ) );
		$limit = max( 1, min( 10, absint( $args['limit'] ) ) );

		$registry = new Stats_Provider_Registry();
		$provider = $registry->get_active_provider();

		if ( ! $provider ) {
			return new WP_Error(
				'no_stats_provider',
				esc_html__( 'No connected analytics plugin was found. Content Gap Suggestions currently requires Jetpack Stats.', 'ai' )
			);
		}

		$queries = $provider->get_search_queries(
			array(
				'limit' => 50,
				'days'  => 30,
			)
		);

		if ( is_wp_error( $queries ) ) {
			return $queries;
		}

		$patterns = Anonymizer::anonymize( $queries, array( 'limit' => 20 ) );

		if ( empty( $patterns ) ) {
			return array( 'suggestions' => array() );
		}

		$result = $this->generate_suggestions( $patterns, $limit );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'suggestions' => $result );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate content suggestions.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generates post topic suggestions from anonymized patterns.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array{pattern: string, count: int}> $patterns Anonymized query patterns.
	 * @param int                                             $limit    Maximum number of suggestions to return.
	 * @return array<int, array{title: string, outline: string}>|\WP_Error Suggestions, or a WP_Error on failure.
	 */
	private function generate_suggestions( array $patterns, int $limit ) {
		$lines = array();

		foreach ( $patterns as $pattern ) {
			$lines[] = sprintf( '- %s (seen %d times)', $pattern['pattern'], $pattern['count'] );
		}

		$prompt = "<search-patterns>\n" . implode( "\n", $lines ) . "\n</search-patterns>";
		$prompt = $this->filter_prompt( $prompt, $patterns );

		$prompt_builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$result = $prompt_builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->parse_suggestions( $result, $limit );
	}

	/**
	 * Gets a prompt builder configured for structured suggestion output.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to send to the model.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->as_json_response( $this->suggestions_schema() );

		$prompt_builder = $this->filter_prompt_builder( $prompt_builder, Content_Gap_Suggestions_Experiment::class, array(), $prompt );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Content gap suggestion generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}

	/**
	 * Returns the JSON schema for structured output from the AI model.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The JSON schema for structured output.
	 */
	private function suggestions_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title'   => array( 'type' => 'string' ),
							'outline' => array( 'type' => 'string' ),
						),
						'required'   => array( 'title', 'outline' ),
					),
				),
			),
			'required'   => array( 'suggestions' ),
		);
	}

	/**
	 * Parses and sanitizes the AI response into suggestions.
	 *
	 * @since x.x.x
	 *
	 * @param string $response The raw AI response.
	 * @param int    $limit    Maximum number of suggestions to return.
	 * @return array<int, array{title: string, outline: string}>|\WP_Error Parsed suggestions, or a WP_Error on failure.
	 */
	private function parse_suggestions( string $response, int $limit ) {
		$decoded = json_decode( $response, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['suggestions'] ) || ! is_array( $decoded['suggestions'] ) ) {
			return new WP_Error(
				'invalid_response',
				esc_html__( 'Could not parse AI response as valid suggestions.', 'ai' )
			);
		}

		$suggestions = array();

		foreach ( $decoded['suggestions'] as $suggestion ) {
			if ( ! is_array( $suggestion ) || empty( $suggestion['title'] ) || empty( $suggestion['outline'] ) ) {
				continue;
			}

			$suggestions[] = array(
				'title'   => sanitize_text_field( (string) $suggestion['title'] ),
				'outline' => sanitize_textarea_field( (string) $suggestion['outline'] ),
			);

			if ( count( $suggestions ) >= $limit ) {
				break;
			}
		}

		return $suggestions;
	}
}
