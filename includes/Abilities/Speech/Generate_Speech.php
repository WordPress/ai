<?php
/**
 * Speech generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Speech;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Text_To_Speech\Audio_Combiner;
use WordPress\AI\Experiments\Text_To_Speech\Content_Chunker;
use WordPress\AI\Experiments\Text_To_Speech\Job_Manager;
use WordPress\AI\Experiments\Text_To_Speech\Speech_Generator;
use WordPress\AI\Experiments\Text_To_Speech\Voice_Resolver;

use function WordPress\AI\normalize_content;

/**
 * Speech generation WordPress Ability.
 *
 * Synchronously generates speech audio from text or from a post's content,
 * chunking long input and combining the results into a single base64 audio
 * payload.
 *
 * @since x.x.x
 */
class Generate_Speech extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => esc_html__( 'The text to generate speech from. Takes precedence over post_id when both are provided.', 'ai' ),
				),
				'post_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'A post ID whose content will be used when no text is provided.', 'ai' ),
				),
				'voice'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Optional voice identifier. Defaults to the Text to Speech feature setting, then the first voice the provider declares, then the provider default.', 'ai' ),
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
				'audio' => array(
					'type'        => 'object',
					'description' => esc_html__( 'Generated audio data.', 'ai' ),
					'properties'  => array(
						'data'              => array(
							'type'        => 'string',
							'description' => esc_html__( 'The base64 encoded audio data.', 'ai' ),
						),
						'mime_type'         => array(
							'type'        => 'string',
							'description' => esc_html__( 'The MIME type of the audio.', 'ai' ),
						),
						'provider_metadata' => array(
							'type'        => 'object',
							'description' => esc_html__( 'Information about the provider that generated the audio.', 'ai' ),
						),
						'model_metadata'    => array(
							'type'        => 'object',
							'description' => esc_html__( 'Information about the model that generated the audio.', 'ai' ),
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
		$args = wp_parse_args(
			$input,
			array(
				'text'    => '',
				'post_id' => 0,
				'voice'   => null,
			),
		);

		$post_id = absint( $args['post_id'] );
		$text    = (string) $args['text'];

		if ( '' === trim( $text ) && $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$text = (string) apply_filters( 'the_content', $post->post_content );
		}

		$text = normalize_content( $text );

		if ( '' === $text ) {
			return new WP_Error(
				'no_content',
				esc_html__( 'Text or a post with content is required to generate speech.', 'ai' )
			);
		}

		$voice = null !== $args['voice'] && '' !== $args['voice']
			? (string) $args['voice']
			: (string) get_option( 'wpai_feature_' . Job_Manager::FEATURE_ID . '_field_voice', '' );

		// Resolve the default once, before the chunk loop, so every chunk matches.
		if ( '' === $voice ) {
			$voice = ( new Voice_Resolver() )->get_default_voice();
		}

		/** This filter is documented in includes/Experiments/Text_To_Speech/Job_Manager.php */
		$max_length = (int) apply_filters( 'wpai_tts_max_chunk_length', 4000, $post_id );

		$chunks = Content_Chunker::chunk( $text, $max_length );
		$total  = count( $chunks );

		$generator = new Speech_Generator();
		$combined  = '';
		$audio     = array(
			'data'              => '',
			'mime_type'         => '',
			'provider_metadata' => array(),
			'model_metadata'    => array(),
		);

		foreach ( $chunks as $index => $chunk ) {
			$result = $generator->generate_chunk( $chunk, $voice );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$bytes = base64_decode( $result['data'], true );

			if ( false === $bytes || '' === $bytes ) {
				return new WP_Error(
					'no_audio_data',
					esc_html__( 'The provider returned invalid audio data.', 'ai' )
				);
			}

			$is_first = 0 === $index;
			$is_last  = $index + 1 >= $total;

			if ( $is_first ) {
				$audio['mime_type']         = $result['mime_type'];
				$audio['provider_metadata'] = $result['provider_metadata'];
				$audio['model_metadata']    = $result['model_metadata'];
			} elseif ( $audio['mime_type'] !== $result['mime_type'] ) {
				return new WP_Error(
					'inconsistent_audio',
					esc_html__( 'The provider returned inconsistent audio formats across chunks.', 'ai' )
				);
			}

			if ( $total > 1 && 'audio/mpeg' !== $audio['mime_type'] ) {
				return new WP_Error(
					'unsupported_format',
					esc_html__( 'Combining audio chunks requires MP3 output, which the provider did not return.', 'ai' )
				);
			}

			$combined .= Audio_Combiner::prepare_chunk( $bytes, $is_first, $is_last );
		}

		$audio['data'] = base64_encode( $combined );

		return array(
			'audio' => $audio,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate speech.', 'ai' )
			);
		}

		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to generate speech for this post.', 'ai' )
				);
			}

			$post_type_obj = get_post_type_object( (string) get_post_type( $post_id ) );

			if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
				return false;
			}
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
}
