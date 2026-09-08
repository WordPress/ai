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
 * @since x.x.x
 */
class Voice_Resolver {

	/**
	 * Memoized model resolution, or false before the first resolution attempt.
	 *
	 * @since x.x.x
	 *
	 * @var array{metadata: \WordPress\AiClient\Providers\Models\DTO\ModelMetadata, provider_id: string, model_id: string}|false|null
	 */
	private $resolved = false;

	/**
	 * Returns the voices supported by the model that will be used for TTS.
	 *
	 * @since x.x.x
	 *
	 * @return list<string>|null List of voice identifiers, or null when the
	 *                           provider does not declare its voices.
	 */
	public function get_supported_voices(): ?array {
		$resolved = $this->resolve_model();
		$values   = null;

		if ( $resolved ) {
			foreach ( $resolved['metadata']->getSupportedOptions() as $option ) {
				if ( ! $option->getName()->isOutputSpeechVoice() ) {
					continue;
				}

				$values = $option->getSupportedValues();
				break;
			}
		}

		/**
		 * Filters the voices offered for text to speech.
		 *
		 * @since x.x.x
		 *
		 * @param array<int, mixed>|null $values      Voice identifiers declared by the resolved model, or null when it declares none.
		 * @param string                 $provider_id The resolved provider ID, or empty string.
		 * @param string                 $model_id    The resolved model ID, or empty string.
		 */
		$values = apply_filters(
			'wpai_tts_supported_voices',
			$values,
			$resolved['provider_id'] ?? '',
			$resolved['model_id'] ?? ''
		);

		if ( ! is_array( $values ) ) {
			return null;
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $value ): string {
						return is_scalar( $value ) ? (string) $value : '';
					},
					$values
				),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);
	}

	/**
	 * Returns the default voice to use when none is configured.
	 *
	 * @since x.x.x
	 *
	 * @return string The default voice, or empty string.
	 */
	public function get_default_voice(): string {
		$voices      = $this->get_supported_voices();
		$voice       = null !== $voices && array() !== $voices ? $voices[0] : '';
		$resolved    = $this->resolve_model();
		$provider_id = $resolved['provider_id'] ?? '';
		$model_id    = $resolved['model_id'] ?? '';

		/**
		 * Filters the default voice used for text to speech when none is configured.
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
	 * @since x.x.x
	 *
	 * @return array{metadata: \WordPress\AiClient\Providers\Models\DTO\ModelMetadata, provider_id: string, model_id: string}|null
	 *         The resolved model metadata, or null when none could be resolved.
	 */
	private function resolve_model(): ?array {
		if ( false !== $this->resolved ) {
			return $this->resolved;
		}

		$this->resolved = $this->find_model();

		return $this->resolved;
	}

	/**
	 * Finds the model that will be used for TTS, without memoizing.
	 *
	 * @since x.x.x
	 *
	 * @return array{metadata: \WordPress\AiClient\Providers\Models\DTO\ModelMetadata, provider_id: string, model_id: string}|null
	 *         The resolved model metadata, or null when none could be resolved.
	 */
	private function find_model(): ?array {
		try {
			$registry = AiClient::defaultRegistry();
			$config   = get_feature_developer_model_config( Job_Manager::FEATURE_ID );

			if ( ! empty( $config['provider'] ) && ! empty( $config['model'] ) ) {
				$metadata = $registry->getProviderClassName( $config['provider'] )::modelMetadataDirectory()
					->getModelMetadata( $config['model'] );

				return array(
					'metadata'    => $metadata,
					'provider_id' => (string) $config['provider'],
					'model_id'    => (string) $config['model'],
				);
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
				if (
					! is_array( $preferred_model ) ||
					2 !== count( $preferred_model ) ||
					2 !== count( array_filter( $preferred_model, 'is_string' ) )
				) {
					continue;
				}

				[ $provider_id, $model_id ] = array_values( $preferred_model );

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

					return array(
						'metadata'    => $directory->getModelMetadata( $model_id ),
						'provider_id' => $provider_id,
						'model_id'    => $model_id,
					);
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
								return array(
									'metadata'    => $model,
									'provider_id' => $connector_id,
									'model_id'    => $model->getId(),
								);
							}
						}
					}
				} catch ( Throwable $t ) {
					continue;
				}
			}
		} catch ( Throwable $t ) {
			return null;
		}

		return null;
	}
}
