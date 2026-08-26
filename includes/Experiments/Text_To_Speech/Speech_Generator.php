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
	 * Generates audio for a single chunk of text.
	 *
	 * @since x.x.x
	 *
	 * @param string $text  The chunk text.
	 * @param string $voice The voice to use, or empty string for the provider
	 *                      default.
	 * @return array{data: string, mime_type: string, provider_metadata: array<string, mixed>, model_metadata: array<string, mixed>}|\WP_Error
	 *         Base64 audio data plus metadata, or a WP_Error.
	 */
	public function generate_chunk( string $text, string $voice = '' ) {
		/**
		 * Short-circuits text to speech generation for a single chunk.
		 *
		 * Return an array with a base64 `data` key (and optional `mime_type`,
		 * default 'audio/mpeg') to skip calling the AI client entirely, or a
		 * WP_Error to fail generation. This is the integration point for
		 * third-party TTS services and for tests, and works even when no
		 * connected AI provider supports text to speech (pair it with the
		 * `wpai_has_text_to_speech_support` filter).
		 *
		 * @since x.x.x
		 *
		 * @param array{data: string, mime_type?: string}|\WP_Error|null $pre   The short-circuit value. Default null.
		 * @param string                                                 $text  The chunk text.
		 * @param string                                                 $voice The resolved voice, or empty string.
		 */
		$pre = apply_filters( 'wpai_tts_pre_generate_chunk', null, $text, $voice );

		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		if ( is_array( $pre ) && isset( $pre['data'] ) ) {
			return array(
				'data'              => (string) $pre['data'],
				'mime_type'         => isset( $pre['mime_type'] ) ? (string) $pre['mime_type'] : 'audio/mpeg',
				'provider_metadata' => array(),
				'model_metadata'    => array(),
			);
		}

		try {
			$request_options = new RequestOptions();
			$request_options->setTimeout(
				get_default_request_timeout( Job_Manager::FEATURE_ID, 120 )
			);

			$prompt_builder = wp_ai_client_prompt( $text )
				->using_request_options( $request_options )
				->as_output_file_type( FileTypeEnum::inline() );

			if ( '' !== $voice ) {
				$prompt_builder = $prompt_builder->as_output_speech_voice( $voice );
			}

			// Same provider/model resolution as
			// Abstract_Ability::set_provider_model_preference(), replicated
			// here because the cron path runs outside an ability.
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

			if ( ! $prompt_builder->is_supported_for_text_to_speech_conversion() ) {
				return new WP_Error(
					'unsupported_model',
					esc_html__( 'Audio generation failed. Please ensure you have a connected provider that supports text to speech.', 'ai' )
				);
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

			return array(
				'data'              => $data,
				'mime_type'         => (string) $file->getMimeType(),
				'provider_metadata' => $provider_metadata,
				'model_metadata'    => $model_metadata,
			);
		} catch ( Throwable $t ) {
			return $this->maybe_voice_required_error( $t->getMessage() )
				?? new WP_Error( 'tts_failed', $t->getMessage() );
		}
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
