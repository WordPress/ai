<?php
/**
 * Tests for the Cohere text generation model payload builder.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Providers\Cohere
 */

namespace WordPress\AI\Tests\Integration\Includes\Providers\Cohere;

use ReflectionClass;
use WordPress\AI\Providers\Cohere\CohereTextGenerationModel;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WP_UnitTestCase;

/**
 * @group cohere
 */
class CohereTextGenerationModelTest extends WP_UnitTestCase {

	/**
	 * Ensures the payload uses the latest user message and maps chat history.
	 */
	public function test_build_payload_maps_latest_user_and_history(): void {
		$model = $this->createModel();
		$model->setConfig(
			ModelConfig::fromArray(
				array(
					'systemInstruction' => 'Be concise.',
					'candidateCount'    => 2,
				)
			)
		);

		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array( new MessagePart( 'First question?' ) )
			),
			new Message(
				MessageRoleEnum::model(),
				array( new MessagePart( 'First answer.' ) )
			),
			new Message(
				MessageRoleEnum::user(),
				array( new MessagePart( 'Second question?' ) )
			),
		);

		$payload = $this->invokeBuildPayload( $model, $prompt );

		$this->assertSame( 'Second question?', $payload['message'] );
		$this->assertSame( 'Be concise.', $payload['preamble'] );
		$this->assertArrayHasKey( 'chat_history', $payload );
		$this->assertSame(
			array(
				array(
					'role'    => 'USER',
					'message' => 'First question?',
				),
				array(
					'role'    => 'CHATBOT',
					'message' => 'First answer.',
				),
			),
			$payload['chat_history']
		);
		$this->assertSame( 2, $payload['response_count'] );
	}

	/**
	 * Creates a minimal Cohere model instance for testing.
	 *
	 * @return CohereTextGenerationModel
	 */
	private function createModel(): CohereTextGenerationModel {
		$metadata = new ModelMetadata(
			'command-r',
			'Cohere Command R',
			array( CapabilityEnum::textGeneration() ),
			array( new SupportedOption( OptionEnum::customOptions() ) )
		);

		$provider = new ProviderMetadata(
			'cohere',
			'Cohere',
			\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud()
		);

		return new CohereTextGenerationModel( $metadata, $provider );
	}

	/**
	 * Invokes the private buildPayload method via reflection.
	 *
	 * @param CohereTextGenerationModel $model  Model instance.
	 * @param array<int, Message>       $prompt Prompt messages.
	 * @return array<string, mixed>
	 */
	private function invokeBuildPayload( CohereTextGenerationModel $model, array $prompt ): array {
		$reflection = new ReflectionClass( CohereTextGenerationModel::class );
		$method     = $reflection->getMethod( 'buildPayload' );
		$method->setAccessible( true );

		/** @var array<string, mixed> $payload */
		$payload = $method->invoke( $model, $prompt );

		return $payload;
	}
}
