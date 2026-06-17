<?php
/**
 * Content trnslation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Translation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Content_Translation\Content_Translation as Content_Translation_Experiment;

use function WordPress\AI\count_words;

class Content_Translation extends Abstract_Ability {

	/**
	 * The minimum word count for translation.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected const MIN_WORDS = 1;

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
					'type'        => 'integer',
					'description' => esc_html__( 'The ID of the post to translate content for.', 'ai' ),
				),
				'content'         => array(
					'type'        => 'string',
					'description' => esc_html__( 'The block content to translate.', 'ai' ),
				),
				'target_language' => array(
					'type'        => 'string',
					'enum'        => Languages::get_codes(),
					'default'     => Languages::get_default_target_language(),
					'description' => esc_html__( 'The target language for translation.', 'ai' ),
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

		if ( count_words( wp_strip_all_tags( $content ) ) < self::MIN_WORDS ) {
			return new WP_Error(
				'content_too_short',
				sprintf(
					/* translators: %d: minimum number of words required for translation */
					esc_html__( 'A minimum of %d words is required for translation.', 'ai' ),
					self::MIN_WORDS
				)
			);
		}

		// Validate the target language.
		$target_language = sanitize_key( (string) $args['target_language'] );
		if ( ! Languages::is_supported( $target_language ) ) {
			return new WP_Error(
				'invalid_target_language',
				esc_html__( 'The specified target language is not supported for translation.', 'ai' )
			);
		}

		$language = Languages::get_language_name( $target_language );

		$prompt = sprintf( '<content>%s</content>', $content );

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
	 * @param string $target_language The target language for the translation.
	 * @return string|\WP_Error The translated content, or a WP_Error if there was an error.
	 */
	protected function generate_translated_content( string $prompt, string $target_language ) {
		$builder = $this->get_prompt_builder( $prompt, $target_language );

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
	 * @param string $target_language The target language.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error if there isn't a model that supports text generation.
	 */
	private function get_prompt_builder( string $prompt, string $target_language ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction(
				$this->get_system_instruction(
					'system-instruction.php',
					array(
						'target_language' => $target_language,
					)
				)
			)
			->using_temperature( 0.7 );

		$prompt_builder = $this->set_provider_model_preference(
			$prompt_builder,
			Content_Translation_Experiment::class
		);

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Content translation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}
