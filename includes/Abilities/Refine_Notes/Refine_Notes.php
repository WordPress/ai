<?php
/**
 * Refine from Notes WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Refine_Notes;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refine from Notes WordPress Ability.
 *
 * Receives block content and active notes, then returns the revised block content.
 *
 * @since 0.8.0
 */
class Refine_Notes extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'block_type'    => array(
					'type'        => 'string',
					'description' => esc_html__( 'The block type, e.g. core/paragraph, core/heading.', 'ai' ),
				),
				'block_content' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The content of the block to refine.', 'ai' ),
				),
				'notes'         => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => esc_html__( 'The feedback Notes to apply to the block.', 'ai' ),
				),
				'context'       => array(
					'type'        => 'string',
					'description' => esc_html__( 'Optional surrounding content for context.', 'ai' ),
				),
				'post_id'       => array(
					'type'        => 'integer',
					'description' => esc_html__( 'ID of the post being modified.', 'ai' ),
				),
			),
			'required'   => array( 'block_type', 'block_content', 'notes' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'string',
			'description' => esc_html__( 'The updated block content after applying feedback.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return string|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'block_type'    => '',
				'block_content' => '',
				'notes'         => array(),
				'context'       => '',
				'post_id'       => null,
			)
		);

		if ( empty( $args['block_content'] ) ) {
			return new WP_Error(
				'block_content_required',
				esc_html__( 'Block content is required to perform refinement.', 'ai' )
			);
		}

		/** @var list<string> $notes */
		$notes = array_values(
			array_filter(
				is_array( $args['notes'] ) ? $args['notes'] : array(),
				'is_string'
			)
		);

		if ( empty( $notes ) ) {
			return new WP_Error(
				'notes_required',
				esc_html__( 'At least one note is required to perform refinement.', 'ai' )
			);
		}

		$result = $this->generate_refinement( $args['block_type'], $args['block_content'], $notes, $args['context'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
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
					esc_html__( 'You do not have permission to run AI refinements on this post.', 'ai' )
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
				esc_html__( 'You do not have permission to run AI refinements.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generates refined content for a single block based on notes.
	 *
	 * @since 0.8.0
	 *
	 * @param string $block_type The block type identifier.
	 * @param string $block_content The plain-text block content.
	 * @param list<string> $notes Editorial feedback notes to apply.
	 * @param string $context Optional context to improve refinement relevance.
	 * @return string|\WP_Error Refined text or WP_Error.
	 */
	protected function generate_refinement(
		string $block_type,
		string $block_content,
		array $notes,
		string $context
	) {
		$prompt         = $this->create_prompt( $block_type, $block_content, $notes, $context );
		$prompt_builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$raw = $prompt_builder->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( empty( $raw ) ) {
			return $block_content;
		}

		return (string) $raw;
	}

	/**
	 * Gets a prompt builder for generating refinement.
	 *
	 * @since 0.8.0
	 *
	 * @param string $prompt The prompt to generate refinement from.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_model_preference( ...get_preferred_models_for_text_generation() );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Refinement generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}

	/**
	 * Creates the prompt for the refinement.
	 *
	 * @since 0.8.0
	 *
	 * @param string $block_type The block type identifier.
	 * @param string $block_content The plain-text block content.
	 * @param list<string> $notes Feedback notes.
	 * @param string $context Optional context.
	 * @return string The generated prompt.
	 */
	private function create_prompt( string $block_type, string $block_content, array $notes, string $context ): string {
		$prompt_parts = array();

		$prompt_parts[] = '<block-type>' . sanitize_text_field( $block_type ) . '</block-type>';
		$prompt_parts[] = '<block-content>' . wp_kses_post( $block_content ) . '</block-content>';

		if ( ! empty( $notes ) ) {
			$prompt_parts[] = '<notes>' . implode( "\n\n", array_map( 'sanitize_text_field', $notes ) ) . '</notes>';
		}

		if ( $context ) {
			$prompt_parts[] = '<context>' . normalize_content( $context ) . '</context>';
		}

		return implode( "\n", $prompt_parts );
	}
}
