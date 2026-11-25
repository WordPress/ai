<?php
/**
 * Registry of job types.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the job types available to the async queue.
 *
 * @since 1.6.0
 */
final class Job_Registry {

	/**
	 * Registered job types, keyed by ID.
	 *
	 * @since 1.6.0
	 *
	 * @var array<string, \WordPress\AI\Jobs\Job_Type_Interface>
	 */
	private array $types = array();

	/**
	 * Registers a job type.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job_Type_Interface $type The job type.
	 * @return bool True when registered, false when the ID was invalid or already used.
	 */
	public function register( Job_Type_Interface $type ): bool {
		$id = $type->get_id();

		if ( ! preg_match( '#^[a-z0-9][a-z0-9_\-/]{0,190}$#', $id ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'Job type IDs may only contain lowercase letters, digits, hyphens, underscores and slashes.', 'ai' ),
				'1.6.0'
			);

			return false;
		}

		if ( isset( $this->types[ $id ] ) ) {
			return false;
		}

		$this->types[ $id ] = $type;

		return true;
	}

	/**
	 * Returns a job type.
	 *
	 * @since 1.6.0
	 *
	 * @param string $id The job type ID.
	 * @return \WordPress\AI\Jobs\Job_Type_Interface|null The job type, or null when not registered.
	 */
	public function get( string $id ): ?Job_Type_Interface {
		return $this->types[ $id ] ?? null;
	}

	/**
	 * Returns whether a job type is registered.
	 *
	 * @since 1.6.0
	 *
	 * @param string $id The job type ID.
	 * @return bool True when registered.
	 */
	public function has( string $id ): bool {
		return isset( $this->types[ $id ] );
	}

	/**
	 * Returns every registered job type.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, \WordPress\AI\Jobs\Job_Type_Interface> Job types keyed by ID.
	 */
	public function all(): array {
		return $this->types;
	}
}
