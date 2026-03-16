<?php
/**
 * AI Review Notes WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Review_Notes;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Review Notes WordPress Ability.
 *
 * Reviews a single block's content and returns suggestions for the specified
 * review types (Accessibility, Readability, Grammar, SEO, etc.).
 *
 * @since 0.4.0
 */
class Review_Notes extends Abstract_Ability {

	/**
	 * Review types supported by this Ability.
	 *
	 * @since 0.4.0
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition
	protected const SUPPORTED_REVIEW_TYPES = array( 'accessibility', 'readability', 'grammar', 'seo' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'block_type'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The block type, e.g. core/paragraph, core/heading.', 'ai' ),
				),
				'block_content'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The plain-text content of the block to review.', 'ai' ),
				),
				'context'        => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Surrounding content to improve review relevance.', 'ai' ),
				),
				'post_id'        => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'ID of the post being reviewed.', 'ai' ),
				),
				'existing_notes' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => esc_html__( 'Existing Note texts for this block from prior review runs, used to avoid repeating suggestions.', 'ai' ),
				),
				'review_types'   => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => self::SUPPORTED_REVIEW_TYPES,
					),
					'description' => esc_html__( 'Review types to perform.', 'ai' ),
				),
			),
			'required'   => array( 'block_type', 'block_content' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'suggestions' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Review suggestions for the block.', 'ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'review_type' => array(
								'type'        => 'string',
								'description' => esc_html__( 'The review type.', 'ai' ),
							),
							'text'        => array(
								'type'        => 'string',
								'description' => esc_html__( 'The suggestion text.', 'ai' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{suggestions: list<array{review_type: string, text: string}>}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'block_type'     => '',
				'block_content'  => '',
				'context'        => '',
				'post_id'        => null,
				'existing_notes' => array(),
				'review_types'   => self::SUPPORTED_REVIEW_TYPES,
			)
		);

		if ( empty( $args['block_content'] ) ) {
			return new WP_Error(
				'block_content_required',
				esc_html__( 'Block content is required to perform a review.', 'ai' )
			);
		}

		/** @var list<string> $review_types */
		$review_types = array_values(
			array_filter(
				is_array( $args['review_types'] ) ? $args['review_types'] : self::SUPPORTED_REVIEW_TYPES,
				'is_string'
			)
		);

		/** @var list<string> $existing_notes */
		$existing_notes = array_values(
			array_filter(
				is_array( $args['existing_notes'] ) ? $args['existing_notes'] : array(),
				'is_string'
			)
		);

		$result = $this->generate_review( $args['block_type'], $args['block_content'], $args['context'], $existing_notes, $review_types );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'suggestions' => $result );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : null;

		if ( $post_id ) {
			$post = get_post( $post_id );

			// Ensure the post exists.
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), absint( $post_id ) )
				);
			}

			// Ensure the user has permission to edit this particular post.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to run AI reviews on this post.', 'ai' )
				);
			}

			// Ensure the post type is allowed in REST endpoints.
			$post_type = get_post_type( $post_id );

			if ( ! $post_type ) {
				return false;
			}

			$post_type_obj = get_post_type_object( $post_type );

			if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			// Ensure the user has permission to edit posts in general.
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to run AI reviews.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Returns the JSON schema used for structured output generation.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string, mixed> JSON schema for an array of suggestions.
	 */
	protected function suggestions_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'review_type' => array( 'type' => 'string' ),
							'text'        => array( 'type' => 'string' ),
							'priority'    => array( 'type' => 'integer' ),
						),
						'required'             => array( 'review_type', 'text', 'priority' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'suggestions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Generates review suggestions for a single block.
	 *
	 * @since 0.4.0
	 *
	 * @param string $block_type The block type identifier.
	 * @param string $block_content The plain-text block content.
	 * @param string $context Optional context to improve review relevance.
	 * @param list<string> $existing_notes Prior Note texts to avoid repeating.
	 * @param list<string> $review_types Review types to perform.
	 * @return list<array{review_type: string, text: string}>|\WP_Error Suggestions array or WP_Error.
	 */
	protected function generate_review(
		string $block_type,
		string $block_content,
		string $context,
		array $existing_notes,
		array $review_types
	) {
		$prompt = $this->create_prompt( $block_type, $block_content, $context, $existing_notes, $review_types );

		$raw = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->as_json_response( $this->suggestions_schema() )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['suggestions'] ) || ! is_array( $decoded['suggestions'] ) ) {
			return array();
		}

		$existing_types = $this->get_existing_review_types_from_notes( $existing_notes );

		$suggestions = array();
		foreach ( $decoded['suggestions'] as $item ) {
			if (
				! is_array( $item ) ||
				empty( $item['review_type'] ) ||
				empty( $item['text'] ) ||
				! is_string( $item['review_type'] ) ||
				! is_string( $item['text'] )
			) {
				continue;
			}

			$review_type = sanitize_text_field( $item['review_type'] );
			$text        = sanitize_text_field( $item['text'] );
			$priority    = absint( $item['priority'] ?? 5 );

			// Skip if we already have a suggestion for this review type in existing Notes.
			if ( isset( $existing_types[ strtolower( $review_type ) ] ) ) {
				continue;
			}

			// Remove if priority is more than 2.
			if ( $priority > 2 ) {
				continue;
			}

			$suggestions[] = array(
				'review_type' => $review_type,
				'text'        => $text,
			);
		}

		return $suggestions;
	}

	/**
	 * Creates the prompt for the review.
	 *
	 * @since 0.4.0
	 *
	 * @param string $block_type The block type identifier.
	 * @param string $block_content The plain-text block content.
	 * @param string $context Optional context to improve review relevance.
	 * @param list<string> $existing_notes Prior note texts to avoid repeating.
	 * @param list<string> $review_types Review types to perform.
	 * @return string The generated prompt.
	 */
	private function create_prompt( string $block_type, string $block_content, string $context, array $existing_notes, array $review_types ): string {
		// Build the prompt.
		$prompt_parts = array();

		$prompt_parts[] = '<block-type>' . sanitize_text_field( $block_type ) . '</block-type>';
		$prompt_parts[] = '<block-content>' . normalize_content( $block_content ) . '</block-content>';

		if ( $context ) {
			$prompt_parts[] = '<additional-context>' . normalize_content( $context ) . '</additional-context>';
		}

		$prompt_parts[] = '<review-types>' . implode( ', ', $review_types ) . '</review-types>';

		if ( ! empty( $existing_notes ) ) {
			$prompt_parts[] = '<existing-notes>' . implode( "\n\n", array_map( 'sanitize_text_field', $existing_notes ) ) . '</existing-notes>';
		}

		return implode( "\n", $prompt_parts );
	}

	/**
	 * Extracts review types already present in existing Note texts.
	 *
	 * Notes use format [REVIEW_TYPE] text. Returns lowercase keys
	 * for case-insensitive comparison.
	 *
	 * @since 0.4.0
	 *
	 * @param list<string> $existing_notes Note content strings.
	 * @return array<string, true> Map of existing review types to true.
	 */
	private function get_existing_review_types_from_notes( array $existing_notes ): array {
		$types = array();

		foreach ( $existing_notes as $note ) {
			if ( ! preg_match_all( '/\[([^\]]+)\]/', (string) $note, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $type ) {
				$types[ strtolower( trim( $type ) ) ] = true;
			}
		}

		return $types;
	}
}
