<?php
/**
 * Fal.ai model metadata directory.
 *
 * @package WordPress\AI\Providers\FalAi
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\FalAi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Expresses Fal.ai image models for discovery.
 *
 * @since 0.1.0
 */
class FalAiModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {
	/**
	 * Hardcoded Fal.ai model catalogue.
	 *
	 * @var array<int, array<string, string>>
	 */
	private $catalogue = array(
		// FLUX.2 models.
		array(
			'id'   => 'fal-ai/flux-2',
			'name' => 'FLUX.2 Dev',
			'mime' => 'image/jpeg',
		),
		array(
			'id'   => 'fal-ai/flux-2-pro',
			'name' => 'FLUX.2 Pro',
			'mime' => 'image/jpeg',
		),
		array(
			'id'   => 'fal-ai/flux-2-flex',
			'name' => 'FLUX.2 Flex',
			'mime' => 'image/jpeg',
		),
		// FLUX.1 models.
		array(
			'id'   => 'fal-ai/flux/dev',
			'name' => 'FLUX.1 Dev',
			'mime' => 'image/jpeg',
		),
		array(
			'id'   => 'fal-ai/flux/schnell',
			'name' => 'FLUX.1 Schnell',
			'mime' => 'image/jpeg',
		),
		// Other models.
		array(
			'id'   => 'fal-ai/fast-sdxl',
			'name' => 'Fast SDXL',
			'mime' => 'image/png',
		),
	);

	/**
	 * {@inheritDoc}
	 */
	protected function sendListModelsRequest(): array {
		$capabilities = array( CapabilityEnum::imageGeneration() );
		$options      = $this->get_default_options();
		$metadata_map = array();

		foreach ( $this->catalogue as $model ) {
			$metadata_map[ $model['id'] ] = new ModelMetadata(
				$model['id'],
				$model['name'],
				$capabilities,
				$this->merge_options_with_mime( $options, $model['mime'] ) // @phpstan-ignore argument.type
			);
		}

		return $metadata_map;
	}

	/**
	 * Returns baseline supported options.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption>
	 */
	private function get_default_options(): array {
		return array(
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
			new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::remote(), FileTypeEnum::inline() ) ),
			new SupportedOption( OptionEnum::customOptions() ),
		);
	}

	/**
	 * Adds MIME-specific option metadata.
	 *
	 * @param array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption> $options Base option list.
	 * @param string                      $mime_type MIME string.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption>
	 */
	private function merge_options_with_mime( array $options, string $mime_type ): array {
		$mime_option = new SupportedOption( OptionEnum::outputMimeType(), array( $mime_type ) );

		return array_merge( $options, array( $mime_option ) );
	}
}
