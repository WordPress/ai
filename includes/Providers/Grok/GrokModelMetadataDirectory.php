<?php
/**
 * Grok model metadata directory.
 *
 * @package WordPress\AI\Providers\Grok
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Grok;

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Lists Grok models and expresses their capabilities for discovery.
 *
 * @since 0.1.0
 */
class GrokModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {
	/**
	 * Known suffixes for image-only models.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- False positive: array values, not multiple constants.
	private const IMAGE_MODEL_KEYWORDS = array( 'image', 'img' );

	/**
	 * Known suffixes for multimodal chat models.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- False positive: array values, not multiple constants.
	private const MULTIMODAL_KEYWORDS = array( 'vision', '4.1', 'omni' );

	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			GrokProvider::url( $path ),
			$headers,
			$data
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$response_data = $response->getData();
		$models_data   = array();

		if ( isset( $response_data['data'] ) && is_array( $response_data['data'] ) ) {
			$models_data = $response_data['data'];
		} elseif ( isset( $response_data['models'] ) && is_array( $response_data['models'] ) ) {
			$models_data = $response_data['models'];
		}

		if ( empty( $models_data ) ) {
			throw ResponseException::fromMissingData( 'Grok', 'data' );
		}

		$metadata = array();
		foreach ( $models_data as $model_data ) {
			if ( ! is_array( $model_data ) || empty( $model_data['id'] ) ) {
				continue;
			}

			$model_id   = (string) $model_data['id'];
			$metadata[] = new ModelMetadata(
				$model_id,
				$this->format_model_name( $model_id ),
				$this->determine_capabilities( $model_id ),
				$this->determine_supported_options( $model_id )
			);
		}

		return $metadata;
	}

	/**
	 * Returns a human friendly label for a Grok model.
	 *
	 * @param string $model_id Model identifier.
	 *
	 * @return string
	 */
	private function format_model_name( string $model_id ): string {
		$label = str_replace( array( '-', '_' ), ' ', $model_id );
		return ucwords( $label );
	}

	/**
	 * Determines the supported capabilities for a given model identifier.
	 *
	 * @param string $model_id Model identifier.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum>
	 */
	private function determine_capabilities( string $model_id ): array {
		foreach ( self::IMAGE_MODEL_KEYWORDS as $keyword ) {
			if ( false !== strpos( $model_id, $keyword ) ) {
				return array( CapabilityEnum::imageGeneration() );
			}
		}

		return array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);
	}

	/**
	 * Determines the supported options for a given model identifier.
	 *
	 * @param string $model_id Model identifier.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption>
	 */
	private function determine_supported_options( string $model_id ): array {
		foreach ( self::IMAGE_MODEL_KEYWORDS as $keyword ) {
			if ( false !== strpos( $model_id, $keyword ) ) {
				return $this->get_image_options();
			}
		}

		$is_multimodal = $this->has_keyword( $model_id, self::MULTIMODAL_KEYWORDS );
		return $this->get_text_options( $is_multimodal );
	}

	/**
	 * Checks whether a model identifier contains any keyword.
	 *
	 * @param string $model_id Model identifier.
	 * @param array<int, string> $keywords Keywords to scan for.
	 *
	 * @return bool
	 */
	private function has_keyword( string $model_id, array $keywords ): bool {
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $model_id, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns base supported options for Grok chat models.
	 *
	 * @param bool $supports_multimodal Whether the model supports image inputs.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption>
	 */
	private function get_text_options( bool $supports_multimodal ): array {
		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::logprobs() ),
			new SupportedOption( OptionEnum::topLogprobs() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);

		$input_modalities = array(
			array( ModalityEnum::text() ),
		);

		if ( $supports_multimodal ) {
			$input_modalities[] = array( ModalityEnum::text(), ModalityEnum::image() );
		}

		$options[] = new SupportedOption( OptionEnum::inputModalities(), $input_modalities );
		$options[] = new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) );

		return $options;
	}

	/**
	 * Returns supported options for Grok image generators.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption>
	 */
	private function get_image_options(): array {
		return array(
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'image/png', 'image/jpeg', 'image/webp' ) ),
			new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ),
			new SupportedOption(
				OptionEnum::outputMediaOrientation(),
				array(
					MediaOrientationEnum::square(),
					MediaOrientationEnum::landscape(),
					MediaOrientationEnum::portrait(),
				)
			),
			new SupportedOption( OptionEnum::customOptions() ),
		);
	}
}
