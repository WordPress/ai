<?php
/**
 * Content translation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Translation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Content_Translation\Content_Translation as Content_Translation_Experiment;
use WordPress\AI\Experiments\Content_Translation\Languages;

use function WordPress\AI\count_characters_excluding_spaces;
use function WordPress\AI\get_min_content_length;

/**
 * Content Translation WordPress Ability.
 *
 * @since x.x.x
 */
class Content_Translation extends Abstract_Ability {

	/**
	 * The default minimum content length for translation.
	 * One word (~5 characters) is the minimum length for translation.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const DEFAULT_MIN_CONTENT_LENGTH = 5;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'         => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'The ID of the post to translate content for.', 'ai' ),
				),
				'content'         => array(
					'type'        => 'string',
					'description' => esc_html__( 'The block content to translate.', 'ai' ),
				),
				'target_language' => array(
					'type'              => 'string',
					'enum'              => Languages::get_codes(),
					'default'           => Languages::get_default_target_language(),
					'sanitize_callback' => 'sanitize_key',
					'description'       => esc_html__( 'The target language for translation.', 'ai' ),
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
			'description' => esc_html__( 'The translated content.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		// Default arguments for the translation process.
		$args = wp_parse_args(
			$input,
			array(
				'post_id'         => null,
				'content'         => null,
				'target_language' => Languages::get_default_target_language(),
			)
		);

		// Skip normalization of content to retain HTML tags.
		$content = $args['content'] ?? '';

		if ( empty( $content ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'No content provided for translation.', 'ai' )
			);
		}

		$min_content_length = get_min_content_length(
			'content-translation',
			self::DEFAULT_MIN_CONTENT_LENGTH
		);

		if ( count_characters_excluding_spaces( $content ) < $min_content_length ) {
			return new WP_Error(
				'content_too_short',
				sprintf(
					/* translators: %d: minimum number of characters required for translation */
					esc_html__( 'A minimum of %d characters is required for translation.', 'ai' ),
					$min_content_length
				)
			);
		}

		$prompt = sprintf( '<content>%s</content>', $content );

		// Validate the target language.
		$target_language = sanitize_key( (string) $args['target_language'] );
		$language        = Languages::get_language_name( $target_language );
		if ( null === $language ) {
			return new WP_Error(
				'invalid_target_language',
				esc_html__( 'The specified target language is not supported for translation.', 'ai' )
			);
		}

		$prompt = $this->filter_prompt( $prompt, $language );
		$result = $this->generate_translated_content( $prompt, $language );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No translated content was generated.', 'ai' )
			);
		}

		return wp_kses_post( $result );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		// Ensure the user has permission to edit the post if a post ID is provided.
		if ( isset( $args['post_id'] ) ) {
			$post_id = absint( $args['post_id'] );
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					sprintf(
					/* translators: %d: post ID */
						esc_html__( 'Post with ID %d not found.', 'ai' ),
						$post_id
					)
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_permissions',
					esc_html__( 'You do not have permission to edit this post.', 'ai' )
				);
			}

			// Ensure the post type is allowed in REST endpoints.
			$post_type_obj = get_post_type_object( $post->post_type );

			if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				esc_html__( 'You do not have permission to translate content.', 'ai' )
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
	 * Generates translated content using the AI Client.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to use for the content translation.
	 * @param string $language The target language for the translation.
	 * @return string|\WP_Error The translated content, or a WP_Error if there was an error.
	 */
	protected function generate_translated_content( string $prompt, string $language ) {
		$builder = $this->get_prompt_builder( $prompt, $language );

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		return $builder->generate_text();
	}

	/**
	 * Returns a prompt builder for content translation.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to build.
	 * @param string $language The target language.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error if there isn't a model that supports text generation.
	 */
	private function get_prompt_builder( string $prompt, string $language ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction(
				$this->get_system_instruction(
					'system-instruction.php',
					array(
						'target_language' => $language,
					)
				)
			);

		$prompt_builder = $this->filter_prompt_builder( $prompt_builder, Content_Translation_Experiment::class, array(), $prompt, $language );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Content translation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}
