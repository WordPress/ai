<?php
/**
 * Intercepts AI Client prompts to enforce per-plugin, per-connector approval.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

use ReflectionException;
use ReflectionObject;
use Throwable;
use WP_AI_Client_Prompt_Builder;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Hooks `wp_ai_client_prevent_prompt` to block AI Client usage from unapproved callers.
 *
 * The filter fires from `WP_AI_Client_Prompt_Builder::__call()` at request time,
 * so the backtrace still contains the originating plugin/theme/mu-plugin.
 *
 * The builder clone exposes the caller's explicit provider / model preference
 * via protected properties of the underlying PromptBuilder SDK class. We read
 * those via reflection and resolve a list of candidate connector IDs for the
 * call. If the caller is approved for at least one candidate, the prompt is
 * allowed; otherwise it is prevented and a pending entry is recorded for each
 * denied candidate.
 *
 * Known limitations:
 * - A plugin that bypasses `wp_ai_client_prompt()` entirely (e.g. reads credential
 *   options directly and makes its own HTTP calls) is not caught here.
 * - When the caller does not specify an explicit provider or provider-scoped
 *   model preference, every registered AI provider connector is treated as a
 *   candidate. Approving for any one connector therefore lets the call through,
 *   even if the AI Client ultimately routes to a different (also registered)
 *   provider. Per-provider enforcement is only strict for callers that use
 *   `using_provider()` or provider-scoped `using_model_preference()`.
 *
 * @since x.x.x
 */
final class Prompt_Guard {
	/**
	 * Caller identifier.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Caller_Identifier
	 */
	private Caller_Identifier $identifier;

	/**
	 * Approvals store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Connector_Approval\Caller_Identifier $identifier Caller identifier.
	 * @param \WordPress\AI\Connector_Approval\Approvals_Store $store Approvals store.
	 */
	public function __construct( Caller_Identifier $identifier, Approvals_Store $store ) {
		$this->identifier = $identifier;
		$this->store      = $store;
	}

	/**
	 * Registers the prompt-prevention filter.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'wp_ai_client_prevent_prompt', array( $this, 'maybe_prevent_prompt' ), 10, 2 );
	}

	/**
	 * Returns true when the originating caller is not approved for any candidate connector.
	 *
	 * @since x.x.x
	 *
	 * @param bool $prevent Current prevent state from earlier filter callbacks.
	 * @param \WP_AI_Client_Prompt_Builder $builder Clone of the prompt builder.
	 * @return bool True to block the prompt, false to allow it through.
	 */
	public function maybe_prevent_prompt( bool $prevent, WP_AI_Client_Prompt_Builder $builder ): bool {
		if ( $prevent ) {
			return true;
		}

		$caller = $this->identifier->identify();
		if ( null === $caller ) {
			// No identifiable plugin/theme/mu-plugin on the stack — allow through
			// so core, wp-cli, and REST-originated requests aren't blocked.
			return false;
		}

		$candidates = $this->resolve_candidate_connectors( $builder );
		if ( array() === $candidates ) {
			// No AI provider connectors are registered at all. Nothing meaningful
			// to enforce against; let the AI Client handle the downstream error.
			return false;
		}

		foreach ( $candidates as $connector_id ) {
			if ( $this->store->is_approved( $caller['basename'], $connector_id ) ) {
				return false;
			}
		}

		foreach ( $candidates as $connector_id ) {
			$this->store->record_pending( $caller, $connector_id );
		}

		return true;
	}

	/**
	 * Resolves the list of connector IDs the builder could target.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder Builder clone.
	 * @return list<string> Candidate connector IDs.
	 */
	private function resolve_candidate_connectors( WP_AI_Client_Prompt_Builder $builder ): array {
		$php_builder = $this->extract_php_builder( $builder );
		if ( null !== $php_builder ) {
			$explicit_provider = $this->read_protected_property( $php_builder, 'providerIdOrClassName' );
			if ( is_string( $explicit_provider ) && '' !== $explicit_provider ) {
				return array( $this->normalize_provider_identifier( $explicit_provider ) );
			}

			$preference_keys = $this->read_protected_property( $php_builder, 'modelPreferenceKeys' );
			if ( is_array( $preference_keys ) && array() !== $preference_keys ) {
				$providers = array();
				foreach ( $preference_keys as $preference_key ) {
					if ( ! is_string( $preference_key ) ) {
						continue;
					}
					if ( 0 !== strpos( $preference_key, 'providerModel::' ) ) {
						// `model::{id}` form doesn't pin a provider — treat the
						// whole call as unconstrained so we fall through to the
						// "all registered connectors" branch below.
						$providers = array();
						break;
					}
					$parts = explode( '::', $preference_key, 3 );
					if ( 3 !== count( $parts ) || '' === $parts[1] ) {
						continue;
					}

					$providers[] = $parts[1];
				}
				if ( array() !== $providers ) {
					return array_values( array_unique( $providers ) );
				}
			}
		}

		return $this->all_ai_provider_connector_ids();
	}

	/**
	 * Returns the IDs of every registered AI provider connector.
	 *
	 * @since x.x.x
	 *
	 * @return list<string>
	 */
	private function all_ai_provider_connector_ids(): array {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return array();
		}

		$ids = array();
		foreach ( (array) wp_get_connectors() as $id => $data ) {
			if ( ! is_string( $id ) || ! is_array( $data ) ) {
				continue;
			}
			if ( ( $data['type'] ?? '' ) !== 'ai_provider' ) {
				continue;
			}
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * Returns the inner SDK PromptBuilder instance from a WP builder clone.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder Builder clone.
	 * @return object|null Returns the SDK PromptBuilder, or null if unavailable.
	 */
	private function extract_php_builder( WP_AI_Client_Prompt_Builder $builder ): ?object {
		try {
			$reflection = new ReflectionObject( $builder );
			if ( ! $reflection->hasProperty( 'builder' ) ) {
				return null;
			}
			$prop = $reflection->getProperty( 'builder' );
			$prop->setAccessible( true );
			$value = $prop->getValue( $builder );

			return is_object( $value ) ? $value : null;
		} catch ( ReflectionException $e ) {
			return null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Reads a protected/private property on the given object, returning null on failure.
	 *
	 * @since x.x.x
	 *
	 * @param object $target Target object.
	 * @param string $property Property name.
	 * @return mixed The property value, or null if it cannot be read.
	 */
	private function read_protected_property( object $target, string $property ) {
		try {
			$reflection = new ReflectionObject( $target );
			if ( ! $reflection->hasProperty( $property ) ) {
				return null;
			}
			$prop = $reflection->getProperty( $property );
			$prop->setAccessible( true );
			return $prop->getValue( $target );
		} catch ( ReflectionException $e ) {
			return null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Strips a namespace from a fully-qualified class name so it can be compared
	 * against `wp_get_connectors()` IDs. Pass-through for plain ID strings.
	 *
	 * @since x.x.x
	 *
	 * @param string $identifier Provider ID or fully-qualified class name.
	 * @return string
	 */
	private function normalize_provider_identifier( string $identifier ): string {
		if ( false === strpos( $identifier, '\\' ) ) {
			return $identifier;
		}

		$short = substr( (string) strrchr( $identifier, '\\' ), 1 );
		return '' !== $short ? $short : $identifier;
	}
}
