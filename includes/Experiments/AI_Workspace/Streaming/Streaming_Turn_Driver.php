<?php
/**
 * Streams a workspace model round through the Anthropic streaming model.
 *
 * @package WordPress\AI\Experiments\AI_Workspace\Streaming
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\Streaming;

use Throwable;
use WP_AI_Client_Ability_Function_Resolver;
use WordPress\AI\Experiments\AI_Workspace\Stream_Driver_Interface;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Builds and drives the streaming model for one round.
 *
 * This is the only class in the workspace that names the third-party Anthropic
 * provider's model, and it never does so before
 * {@see Anthropic_Streaming_Text_Generation_Model::is_available()} has said the
 * provider plugin is installed: autoloading that class where the plugin is absent
 * is a fatal error, not a catchable one.
 *
 * Everything that can go wrong on the way out arrives as a
 * {@see Streaming_Exception} — `CODE_NOT_APPROVED` when the connector has not been
 * approved for this caller, `CODE_TRANSPORT` when the host cannot open a stream at
 * all, `CODE_HTTP_STATUS` when the provider refused. Every one of them returns
 * null so the caller can answer with a buffered request instead of failing the
 * turn, and fires `wpai_workspace_streaming_fallback` so the decision is visible.
 *
 * @since x.x.x
 */
final class Streaming_Turn_Driver implements Stream_Driver_Interface {

	/**
	 * Provider ID whose streaming model this driver builds.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PROVIDER = 'anthropic';

	/**
	 * A pre-built model, supplied instead of building one.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Streaming_Text_Generation_Model|null
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Streaming_Text_Generation_Model|null $model Optional. A ready model.
	 */
	public function __construct( ?Anthropic_Streaming_Text_Generation_Model $model = null ) {
		$this->model = $model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @param callable                                       $on_text            Receives text deltas as they arrive.
	 * @return \WordPress\AiClient\Messages\DTO\Message|null The assistant message, or null to fall back.
	 */
	public function stream( array $messages, array $ability_names, string $system_instruction, callable $on_text ): ?Message {
		$model = null === $this->model ? $this->create_model() : $this->model;

		if ( null === $model ) {
			return null;
		}

		$model->setConfig( $this->build_config( $ability_names, $system_instruction ) );

		try {
			$streamed = $model->streamGenerateTextResult( $messages );

			foreach ( $streamed as $chunk ) {
				/*
				 * Only the assistant's visible text is emitted. getReasoningDeltaText()
				 * carries the model's thinking, which is not the reply and must not be
				 * rendered as one.
				 */
				$text = $chunk->getDeltaText();

				if ( '' === $text ) {
					continue;
				}

				$on_text( $text );
			}

			return $streamed->getFinalResult()->toMessage();
		} catch ( Streaming_Exception $e ) {
			return $this->fall_back( (int) $e->getCode(), $e->getMessage() );
		} catch ( Throwable $e ) {
			return $this->fall_back( 0, $e->getMessage() );
		}
	}

	/**
	 * Announces a fallback to a buffered request.
	 *
	 * @since x.x.x
	 *
	 * @param int    $code    The Streaming_Exception code, or 0.
	 * @param string $message The failure message.
	 * @return null Always null, so the caller falls back.
	 */
	private function fall_back( int $code, string $message ) {
		/**
		 * Fires when a streaming turn falls back to a buffered request.
		 *
		 * @since x.x.x
		 *
		 * @param int    $code    The Streaming_Exception code, or 0 for anything else.
		 * @param string $message The failure message.
		 */
		do_action( 'wpai_workspace_streaming_fallback', $code, $message );

		return null;
	}

	/**
	 * Builds the model configuration for a round.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $ability_names      Abilities to declare as tools.
	 * @param string       $system_instruction The system instruction.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelConfig The configuration.
	 */
	private function build_config( array $ability_names, string $system_instruction ): ModelConfig {
		$config = ModelConfig::fromArray( array() );

		$config->setSystemInstruction( $system_instruction );

		$declarations = array();

		foreach ( $ability_names as $ability_name ) {
			$ability = wp_get_ability( $ability_name );

			if ( ! $ability instanceof \WP_Ability ) {
				continue;
			}

			$input_schema = wp_prepare_json_schema_for_client( $ability->get_input_schema() );

			$declarations[] = new FunctionDeclaration(
				WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $ability_name ),
				$ability->get_description(),
				array() !== $input_schema ? $input_schema : null
			);
		}

		if ( array() !== $declarations ) {
			$config->setFunctionDeclarations( $declarations );
		}

		return $config;
	}

	/**
	 * Builds a streaming model, or returns null when this host cannot stream.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Streaming_Text_Generation_Model|null The model, or null.
	 */
	private function create_model(): ?Anthropic_Streaming_Text_Generation_Model {
		if ( ! class_exists( AiClient::class ) || ! Anthropic_Streaming_Text_Generation_Model::is_available() ) {
			return null;
		}

		try {
			$registry = AiClient::defaultRegistry();

			if ( ! $registry->isProviderConfigured( self::PROVIDER ) ) {
				return null;
			}

			// Function-calling support is gated before the turn starts, so the
			// model lookup only has to require text generation itself.
			$requirements = new ModelRequirements( array( CapabilityEnum::textGeneration() ), array() );
			$candidates   = $registry->findProviderModelsMetadataForSupport( self::PROVIDER, $requirements );

			if ( array() === $candidates ) {
				return null;
			}

			$provider_class = $registry->getProviderClassName( self::PROVIDER );

			/** @var \WordPress\AiClient\Providers\Contracts\ProviderInterface $provider_class */
			$model = new Anthropic_Streaming_Text_Generation_Model( $candidates[0], $provider_class::metadata() );

			// Authentication and the shared transporter come from the registry, so
			// nothing about credentials is reimplemented here. The streaming
			// decorator then wraps that transporter, which is what restores the
			// connector approval check and request logging on this path.
			$registry->bindModelDependencies( $model );
			$model->setHttpTransporter( new Streaming_Http_Transporter( $registry->getHttpTransporter() ) );

			return $model;
		} catch ( Throwable $e ) {
			return null;
		}
	}
}
