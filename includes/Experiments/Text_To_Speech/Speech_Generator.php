<?php
/**
 * Speech generator service for text to speech.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use Throwable;
use WP_Error;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

use function WordPress\AI\get_default_request_timeout;
use function WordPress\AI\get_feature_developer_model_config;
use function WordPress\AI\get_preferred_speech_models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates speech audio for a single chunk of text.
 *
 * @since x.x.x
 */
class Speech_Generator {

	/**
	 * Default provider request timeout, in seconds, for one chunk.
	 *
	 * @since x.x.x
	 * @var int
	 */
	public const DEFAULT_REQUEST_TIMEOUT = 120;

	/**
	 * Generates audio for a single chunk of text.
	 *
	 * @since x.x.x
	 *
	 * @param string $text      The chunk text.
	 * @param string $voice     The voice to use, or empty string for the provider
	 *                          default.
	 * @param string $mime_type The audio MIME type to request, such as
	 *                          'audio/mpeg' when chunks must be joined, or empty
	 *                          string for the provider default.
	 * @return array{data: string, mime_type: string, provider_metadata: array<string, mixed>, model_metadata: array<string, mixed>}|\WP_Error
	 *         Base64 audio data plus metadata, or a WP_Error.
	 */
	public function generate_chunk( string $text, string $voice = '', string $mime_type = '' ) {
		/**
		 * Short-circuits text to speech generation for a single chunk.
		 *
		 * Return an array with a base64 `data` key (and optional `mime_type`,
		 * default 'audio/mpeg', which must be an `audio/*` type) to skip
		 * calling the AI client entirely, or a
		 * WP_Error to fail generation. This is the integration point for
		 * third-party TTS services and for tests, and works even when no
		 * connected AI provider supports text to speech (pair it with the
		 * `wpai_has_text_to_speech_support` filter).
		 *
		 * @since x.x.x
		 *
		 * @param array{data: string, mime_type?: string}|\WP_Error|null $pre       The short-circuit value. Default null.
		 * @param string                                                 $text      The chunk text.
		 * @param string                                                 $voice     The resolved voice, or empty string.
		 * @param string                                                 $mime_type The MIME type the audio must have, such as
		 *                                                                          'audio/mpeg' for a post generated in several
		 *                                                                          chunks, or empty string for any audio type.
		 */
		$pre = apply_filters( 'wpai_tts_pre_generate_chunk', null, $text, $voice, $mime_type );

		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		if ( is_array( $pre ) && isset( $pre['data'] ) ) {
			return $this->ensure_audio(
				array(
					'data'              => (string) $pre['data'],
					'mime_type'         => isset( $pre['mime_type'] ) ? (string) $pre['mime_type'] : 'audio/mpeg',
					'provider_metadata' => array(),
					'model_metadata'    => array(),
				)
			);
		}

		try {
			$prompt_builder = $this->build_prompt( $text, $voice, $mime_type );

			if ( ! $prompt_builder->is_supported_for_text_to_speech_conversion() ) {
				return $this->unsupported_error( $mime_type );
			}

			$result = $prompt_builder->convert_text_to_speech_result();

			if ( is_wp_error( $result ) ) {
				return $this->maybe_voice_required_error( $result->get_error_message() ) ?? $result;
			}

			$file = $result->toAudioFile();
			$data = (string) ( $file->getBase64Data() ?? '' );

			if ( '' === $data ) {
				return new WP_Error(
					'no_audio_data',
					esc_html__( 'The provider did not return inline audio data.', 'ai' )
				);
			}

			$provider_metadata = $result->getProviderMetadata()->toArray();
			$model_metadata    = $result->getModelMetadata()->toArray();

			// Remove data we don't care about.
			unset( $provider_metadata[ ProviderMetadata::KEY_CREDENTIALS_URL ] );
			unset( $model_metadata[ ModelMetadata::KEY_SUPPORTED_OPTIONS ] );
			unset( $model_metadata[ ModelMetadata::KEY_SUPPORTED_CAPABILITIES ] );

			return $this->ensure_audio(
				array(
					'data'              => $data,
					'mime_type'         => (string) $file->getMimeType(),
					'provider_metadata' => $provider_metadata,
					'model_metadata'    => $model_metadata,
				)
			);
		} catch ( Throwable $t ) {
			return $this->maybe_voice_required_error( $t->getMessage() )
				?? new WP_Error( 'tts_failed', $t->getMessage() );
		}
	}

	/**
	 * Checks that a connected model can produce audio with the given voice and MIME type.
	 *
	 * @since x.x.x
	 *
	 * @param string $voice     The voice to use, or empty string for the provider default.
	 * @param string $mime_type The audio MIME type to require, or empty string for any.
	 * @return true|\WP_Error True when supported, or a WP_Error explaining why not.
	 */
	public function check_support( string $voice = '', string $mime_type = '' ) {
		if ( has_filter( 'wpai_tts_pre_generate_chunk' ) ) {
			return true;
		}

		try {
			if ( ! $this->build_prompt( 'Check.', $voice, '' )->is_supported_for_text_to_speech_conversion() ) {
				return $this->unsupported_error( '' );
			}

			if ( '' === $mime_type || $this->build_prompt( 'Check.', $voice, $mime_type )->is_supported_for_text_to_speech_conversion() ) {
				return true;
			}
		} catch ( Throwable $t ) {
			return new WP_Error( 'tts_failed', $t->getMessage() );
		}

		return $this->unsupported_error( $mime_type );
	}

	/**
	 * Builds the prompt for a chunk with the feature's provider and model
	 * preferences applied.
	 *
	 * @since x.x.x
	 *
	 * @param string $text      The chunk text.
	 * @param string $voice     The voice to use, or empty string for the provider default.
	 * @param string $mime_type The audio MIME type to request, or empty string for the provider default.
	 * @return \WP_AI_Client_Prompt_Builder The prompt builder.
	 */
	private function build_prompt( string $text, string $voice, string $mime_type ) {
		$request_options = new RequestOptions();
		$request_options->setTimeout(
			get_default_request_timeout( Job_Manager::FEATURE_ID, self::DEFAULT_REQUEST_TIMEOUT )
		);

		$prompt_builder = wp_ai_client_prompt( $text )
			->using_request_options( $request_options )
			->as_output_file_type( FileTypeEnum::inline() );

		if ( '' !== $voice ) {
			$prompt_builder = $prompt_builder->as_output_speech_voice( $voice );
		}

		// Requesting a MIME type also limits model matching.
		if ( '' !== $mime_type ) {
			$prompt_builder = $prompt_builder->as_output_mime_type( $mime_type );
		}

		$config = get_feature_developer_model_config( Job_Manager::FEATURE_ID );

		if ( ! empty( $config['provider'] ) && ! empty( $config['model'] ) ) {
			$prompt_builder->using_model(
				AiClient::defaultRegistry()->getProviderModel( $config['provider'], $config['model'] )
			);
		} else {
			if ( ! empty( $config['provider'] ) ) {
				$prompt_builder->using_provider( $config['provider'] );
			}

			$prompt_builder->using_model_preference( ...get_preferred_speech_models() );
		}

		return $prompt_builder;
	}

	/**
	 * Returns the error for a prompt no connected model can handle.
	 *
	 * @since x.x.x
	 *
	 * @param string $mime_type The audio MIME type that was required, or empty string.
	 * @return \WP_Error The error.
	 */
	private function unsupported_error( string $mime_type ): WP_Error {
		if ( Audio_Combiner::MIME_TYPE === $mime_type ) {
			return new WP_Error(
				'tts_mp3_unsupported',
				esc_html__( 'This post is long enough that its audio is generated in several parts, which can only be joined as MP3, and the connected provider cannot produce MP3 audio. Connect a provider that supports MP3 output, or shorten the post.', 'ai' )
			);
		}

		return new WP_Error(
			'unsupported_model',
			esc_html__( 'Audio generation failed. Please ensure you have a connected provider that supports text to speech.', 'ai' )
		);
	}

	/**
	 * Rejects a result whose MIME type is not audio.
	 *
	 * @since x.x.x
	 *
	 * @param array{data: string, mime_type: string, provider_metadata: array<string, mixed>, model_metadata: array<string, mixed>} $result The generated chunk.
	 * @return array{data: string, mime_type: string, provider_metadata: array<string, mixed>, model_metadata: array<string, mixed>}|\WP_Error
	 *         The result unchanged, or a WP_Error when it is not audio.
	 */
	private function ensure_audio( array $result ) {
		if ( 0 === strpos( strtolower( $result['mime_type'] ), 'audio/' ) ) {
			return $result;
		}

		return new WP_Error(
			'tts_invalid_audio_type',
			esc_html__( 'The provider returned data that is not audio.', 'ai' )
		);
	}

	/**
	 * Maps a provider error about a missing voice to an actionable message.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The provider error message.
	 * @return \WP_Error|null A voice-specific error, or null when the message is about something else.
	 */
	private function maybe_voice_required_error( string $message ): ?WP_Error {
		if ( false === stripos( $message, 'outputSpeechVoice' ) ) {
			return null;
		}

		// Distinguish "no voice was given" from "the given voice was rejected".
		if ( false === stripos( $message, 'required' ) && false === stripos( $message, 'missing' ) ) {
			return null;
		}

		return new WP_Error(
			'tts_voice_required',
			sprintf(
				/* translators: %s: the original error message reported by the AI provider. */
				esc_html__( 'This provider requires a voice. Choose one in the Text to Speech settings, under Voice. Provider error: %s', 'ai' ),
				$message
			)
		);
	}
}
