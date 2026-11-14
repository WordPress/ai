<?php
/**
 * Default model client for Site Agent turns.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use Throwable;
use WP_Error;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;

use function WordPress\AI\get_feature_developer_model_config;
use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Runs a Site Agent model round through WordPress's prompt builder.
 *
 * Rounds are buffered: the model's reply arrives whole. Streaming is a
 * deliberate follow-up so the transport stays simple and testable.
 *
 * @since x.x.x
 */
class Prompt_Model_Client implements Model_Client_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return bool True when text generation can run.
	 */
	public function supports_text_generation(): bool {
		return has_valid_ai_credentials();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the model can be offered tools.
	 */
	public function supports_function_calling(): bool {
		return Function_Calling_Support::is_available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @param callable|null                                  $on_text            Optional. Receives text deltas as they stream.
	 * @return \WordPress\AiClient\Messages\DTO\Message|\WP_Error The assistant message, or an error.
	 */
	public function generate( array $messages, array $ability_names, string $system_instruction, ?callable $on_text = null ) {
		unset( $on_text );

		return $this->generate_buffered(
			self::without_unsigned_thoughts( $messages ),
			$ability_names,
			$system_instruction
		);
	}

	/**
	 * Drops thought parts that carry no signature from the conversation.
	 *
	 * A reasoning model's private thinking comes back as thought-channel parts,
	 * and the whole history is replayed on the next round. Some providers only
	 * accept that thinking back when it carries the signature they issued.
	 * Signed thought parts pass through unchanged; an unsigned one is omitted.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages The conversation.
	 * @return list<\WordPress\AiClient\Messages\DTO\Message> The conversation without unsigned thought parts.
	 */
	public static function without_unsigned_thoughts( array $messages ): array {
		$kept = array();

		foreach ( $messages as $message ) {
			$parts   = array();
			$dropped = false;

			foreach ( $message->getParts() as $part ) {
				if ( $part->getChannel()->isThought() ) {
					$signature = $part->getThoughtSignature();

					if ( null === $signature || '' === $signature ) {
						$dropped = true;
						continue;
					}
				}

				$parts[] = $part;
			}

			if ( ! $dropped ) {
				$kept[] = $message;
				continue;
			}

			if ( array() === $parts ) {
				continue;
			}

			$kept[] = new Message( $message->getRole(), $parts );
		}

		return $kept;
	}

	/**
	 * Resolves the model preference for a Site Agent turn.
	 *
	 * @since x.x.x
	 *
	 * @return array{provider: string, model: string, fallback: array<int, array{string, string}>} The resolved preference.
	 */
	public static function get_model_preference(): array {
		$config = get_feature_developer_model_config( Site_Agent::get_id() );

		/**
		 * Filters the fallback model list for Site Agent turns.
		 *
		 * @since x.x.x
		 *
		 * @param array<int, array{string, string}> $models The preferred provider/model pairs.
		 */
		$fallback = (array) apply_filters(
			'wpai_site_agent_model_preference',
			get_preferred_models_for_text_generation()
		);

		return array(
			'provider' => $config['provider'],
			'model'    => $config['model'],
			'fallback' => $fallback,
		);
	}

	/**
	 * Applies the resolved model preference to a prompt builder.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder The prompt builder.
	 * @return \WP_AI_Client_Prompt_Builder The configured builder.
	 */
	private function apply_model_preference( \WP_AI_Client_Prompt_Builder $builder ): \WP_AI_Client_Prompt_Builder {
		$preference = self::get_model_preference();

		if ( '' !== $preference['provider'] && '' !== $preference['model'] ) {
			$builder->using_model(
				AiClient::defaultRegistry()->getProviderModel( $preference['provider'], $preference['model'] )
			);

			return $builder;
		}

		if ( '' !== $preference['provider'] ) {
			$builder->using_provider( $preference['provider'] );
		}

		if ( array() !== $preference['fallback'] ) {
			$builder->using_model_preference( ...$preference['fallback'] );
		}

		return $builder;
	}

	/**
	 * Runs a buffered model round through the core prompt builder.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @return \WordPress\AiClient\Messages\DTO\Message|\WP_Error The assistant message, or an error.
	 */
	protected function generate_buffered( array $messages, array $ability_names, string $system_instruction ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'site_agent_ai_client_unavailable',
				__( 'The WordPress AI client is not available in this environment.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		try {
			$builder = wp_ai_client_prompt( $messages )
				->using_system_instruction( $system_instruction );

			$builder = $this->apply_model_preference( $builder );

			if ( array() !== $ability_names ) {
				$builder = $builder->using_abilities( ...$ability_names );
			}

			$result = $builder->generate_text_result();
		} catch ( Throwable $e ) {
			return new WP_Error(
				'site_agent_generation_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result->toMessage();
	}
}
