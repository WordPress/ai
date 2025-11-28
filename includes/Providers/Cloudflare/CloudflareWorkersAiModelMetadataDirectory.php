<?php
/**
 * Cloudflare Workers AI model metadata directory.
 *
 * @package WordPress\AI\Providers\Cloudflare
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Cloudflare;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Lists Workers AI models via Cloudflare's REST API.
 *
 * @since 0.1.0
 */
class CloudflareWorkersAiModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {
	/**
	 * Curated list of Workers AI chat models exposed via the run endpoint.
	 *
	 * @var array<int, array{id:string,name:string}>
	 */
	private $catalogue = array(
		array(
			'id'   => '@cf/meta/llama-3-8b-instruct',
			'name' => 'Meta Llama 3 8B (Cloudflare)',
		),
		array(
			'id'   => '@cf/meta/llama-3-70b-instruct',
			'name' => 'Meta Llama 3 70B (Cloudflare)',
		),
		array(
			'id'   => '@cf/mistral/mistral-7b-instruct-v0.2',
			'name' => 'Mistral 7B Instruct (Cloudflare)',
		),
	);

	/**
	 * {@inheritDoc}
	 */
	protected function sendListModelsRequest(): array {
		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);

		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);

		$map = array();
		foreach ( $this->catalogue as $model ) {
			$map[ $model['id'] ] = new ModelMetadata(
				$model['id'],
				$model['name'],
				$capabilities,
				$options
			);
		}

		return $map;
	}
}
