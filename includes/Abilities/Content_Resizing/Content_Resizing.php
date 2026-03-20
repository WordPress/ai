<?php
/**
 * Content resizing WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Resizing;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

/**
 * Content resizing WordPress Ability.
 *
 * @since x.x.x
 */
class Content_Resizing extends Abstract_Ability {

	/**
	 * The default action.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected const ACTION_DEFAULT = 'rephrase';

	/**
	 * The minimum word count for the shorten action.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected const SHORTEN_MIN_WORDS = 10;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The block content to resize.', 'ai' ),
				),
				'action'  => array(
					'type'        => 'enum',
					'enum'        => array( 'shorten', 'expand', 'rephrase' ),
					'default'     => self::ACTION_DEFAULT,
					'description' => esc_html__( 'The resizing action to perform.', 'ai' ),
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
			'type'        => 'string',
			'description' => esc_html__( 'The resized content.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'content' => null,
				'action'  => self::ACTION_DEFAULT,
			),
		);

		$content = normalize_content( $args['content'] ?? '' );

		// If we have no content, return an error.
		if ( empty( $content ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to resize.', 'ai' )
			);
		}

		// Validate minimum word count for the shorten action.
		if ( 'shorten' === $args['action'] && str_word_count( wp_strip_all_tags( $content ) ) < self::SHORTEN_MIN_WORDS ) {
			return new WP_Error(
				'content_too_short',
				esc_html__( 'Text is too short to shorten further.', 'ai' )
			);
		}

		// Generate the resized content.
		$result = $this->generate_resized_content( $content, $args['action'] );

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If we have no results, return an error.
		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No resized content was generated.', 'ai' )
			);
		}

		// Return the resized content in the format the Ability expects.
		return sanitize_text_field( trim( $result ) );
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
				esc_html__( 'You do not have permission to resize content.', 'ai' )
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
	 * Generates resized content using the AI client.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The content to resize.
	 * @param string $action  The resizing action to perform.
	 * @return string|\WP_Error The resized content, or a WP_Error if there was an error.
	 */
	protected function generate_resized_content( string $content, string $action ) {
		$content = '<content>' . $content . '</content>';

		return wp_ai_client_prompt( $content )
			->using_system_instruction( $this->get_system_instruction( 'system-instruction.php', array( 'action' => $action ) ) )
			->using_temperature( 0.7 )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->generate_text();
	}
}
