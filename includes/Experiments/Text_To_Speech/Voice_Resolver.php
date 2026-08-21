<?php
/**
 * Voice resolver for text to speech.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

use function WordPress\AI\get_ai_connectors;
use function WordPress\AI\get_feature_developer_model_config;
use function WordPress\AI\get_preferred_speech_models;
use function WordPress\AI\has_connector_authentication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the available voices and the default voice for text to speech.
 *
 * Voices are read from the resolved model's metadata: providers can declare
 * their voice IDs as supported values on the `outputSpeechVoice` option. When
 * a provider declares no values, no voices can be listed and the default is
 * empty (letting the provider apply its own default), unless a default is
 * supplied via the `wpai_tts_default_voice` filter.
 *
 * @since x.x.x
 */
class Voice_Resolver {

	/**
	 * Transient name prefix used to cache the supported voices.
	 *
	 * @since x.x.x
	 */
	private const TRANSIENT_PREFIX = 'wpai_tts_voice_choices_';

	/**
	 * Returns the voices supported by the model that will be used for TTS.
	 *
	 * @since x.x.x
	 *
	 * @return string[]|null List of voice identifiers, or null when the
	 *                       provider does not declare its voices.
	 */
	public function get_supported_voices(): ?array {
		$resolved = $this->resolve_model();

		if ( ! $resolved ) {
			return null;
		}

		$transient_key = self::TRANSIENT_PREFIX . md5( "{$resolved['provider_id']}/{$resolved['model_id']}" );
		$cached        = get_transient( $transient_key );

		if ( is_array( $cached ) ) {
			return array_map( 'strval', $cached );
		}

		$voices = null;

		foreach ( $resolved['metadata']->getSupportedOptions() as $option ) {
			if ( ! $option->getName()->isOutputSpeechVoice() ) {
				continue;
			}

			$values = $option->getSupportedValues();

			if ( null !== $values ) {
				$voices = array_values(
					array_filter(
						array_map(
							static function ( $value ): string {
								return is_scalar( $value ) ? (string) $value : '';
							},
							$values
						)
					)
				);
			}

			break;
		}

		if ( null !== $voices ) {
			set_transient( $transient_key, $voices, HOUR_IN_SECONDS );
		}

		return $voices;
	}

	/**
	 * Returns the default voice to use when none is configured.
	 *
	 * Defaults to the first voice the provider declares, then passes the
	 * result through the `wpai_tts_default_voice` filter. Returns an empty
	 * string when no default is available, in which case no voice is sent
	 * and the provider default (if any) applies.
	 *
	 * @since x.x.x
	 *
	 * @return string The default voice, or empty string.
	 */
	public function get_default_voice(): string {
		$voices      = $this->get_supported_voices();
		$voice       = is_array( $voices ) && array() !== $voices ? $voices[0] : '';
		$resolved    = $this->resolve_model();
		$provider_id = $resolved['provider_id'] ?? '';
		$model_id    = $resolved['model_id'] ?? '';

		/**
		 * Filters the default voice used for text to speech when none is configured.
		 *
		 * Providers that require a voice but do not declare their voices as
		 * supported values on the `outputSpeechVoice` option can be supported
		 * by returning a valid voice identifier here.
		 *
		 * @since x.x.x
		 *
		 * @param string $voice       The default voice. Empty string when no default is available.
		 * @param string $provider_id The resolved provider ID, or empty string.
		 * @param string $model_id    The resolved model ID, or empty string.
		 */
		$voice = apply_filters( 'wpai_tts_default_voice', $voice, $provider_id, $model_id );

		return is_string( $voice ) ? trim( $voice ) : '';
	}

	/**
	 * Resolves the metadata of the model that will be used for TTS.
	 *
	 * Mirrors the provider/model resolution order used in
	 * {@see Speech_Generator::generate_chunk()} so the voices offered match
	 * the model that generation will use.
	 *
	 * @since x.x.x
	 *
	 * @return array{metadata: \WordPress\AiClient\Providers\Models\DTO\ModelMetadata, provider_id: string, model_id: string}|null
	 *         The resolved model metadata, or null when none could be resolved.
	 */
	private function resolve_model(): ?array {
		static $resolved = false;

		if ( false !== $resolved ) {
			return $resolved;
		}

		$resolved = null;

		try {
			$registry = AiClient::defaultRegistry();
			$config   = get_feature_developer_model_config( Job_Manager::FEATURE_ID );

			if ( ! empty( $config['provider'] ) && ! empty( $config['model'] ) ) {
				$metadata = $registry->getProviderClassName( $config['provider'] )::modelMetadataDirectory()
					->getModelMetadata( $config['model'] );

				$resolved = array(
					'metadata'    => $metadata,
					'provider_id' => (string) $config['provider'],
					'model_id'    => (string) $config['model'],
				);

				return $resolved;
			}

			$connectors = array_values(
				array_filter(
					array_keys( get_ai_connectors() ),
					static function ( string $connector_id ): bool {
						return has_connector_authentication( $connector_id );
					}
				)
			);

			foreach ( get_preferred_speech_models() as $preferred_model ) {
				[ $provider_id, $model_id ] = $preferred_model;

				if ( ! empty( $config['provider'] ) && $config['provider'] !== $provider_id ) {
					continue;
				}

				if ( ! in_array( $provider_id, $connectors, true ) ) {
					continue;
				}

				try {
					$directory = $registry->getProviderClassName( $provider_id )::modelMetadataDirectory();

					if ( ! $directory->hasModelMetadata( $model_id ) ) {
						continue;
					}

					$resolved = array(
						'metadata'    => $directory->getModelMetadata( $model_id ),
						'provider_id' => $provider_id,
						'model_id'    => $model_id,
					);

					return $resolved;
				} catch ( Throwable $t ) {
					continue;
				}
			}

			foreach ( $connectors as $connector_id ) {
				if ( ! empty( $config['provider'] ) && $config['provider'] !== $connector_id ) {
					continue;
				}

				try {
					$models = $registry->getProviderClassName( $connector_id )::modelMetadataDirectory()->listModelMetadata();

					foreach ( $models as $model ) {
						foreach ( $model->getSupportedCapabilities() as $capability ) {
							if ( CapabilityEnum::TEXT_TO_SPEECH_CONVERSION === $capability->value ) {
								$resolved = array(
									'metadata'    => $model,
									'provider_id' => $connector_id,
									'model_id'    => $model->getId(),
								);

								return $resolved;
							}
						}
					}
				} catch ( Throwable $t ) {
					continue;
				}
			}
		} catch ( Throwable $t ) {
			$resolved = null;
		}

		return $resolved;
	}
}
