<?php
/**
 * Job type that runs a registered WordPress ability.
 *
 * @package WordPress\AI\Jobs\Job_Types
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs\Job_Types;

use WP_Ability;
use WP_Error;
use WordPress\AI\Jobs\Job;
use WordPress\AI\Jobs\Job_Type_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Runs any registered ability in the background.
 *
 * This is the bridge between the synchronous Abilities API and the async
 * queue: callers enqueue `ai/ability` with the ability name and its input,
 * and the queue re-checks that ability's own permission callback before it
 * executes. Because only a registered name can run, a queued payload can
 * never introduce code.
 *
 * @since 1.6.0
 */
class Ability_Job_Type implements Job_Type_Interface {

	/**
	 * Job type ID.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const ID = 'ai/ability';

	/**
	 * Ability that must never be enqueued through this job type.
	 *
	 * Enqueuing it again from a job would recurse.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	private const ENQUEUE_ABILITY = 'ai/enqueue-job';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Run an AI ability', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'ability' ),
			'properties' => array(
				'ability' => array(
					'type'        => 'string',
					'description' => __( 'Name of a registered ability, such as ai/excerpt-generation.', 'ai' ),
				),
				'input'   => array(
					'type'        => 'object',
					'description' => __( 'Input passed to the ability.', 'ai' ),
					'default'     => array(),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle( array $args, Job $job ) {
		$ability = $this->get_ability( $args );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		$input   = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();
		$allowed = $ability->check_permissions( $input );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( true !== $allowed ) {
			return new WP_Error(
				'wpai_job_not_allowed',
				__( 'The enqueuing user is no longer allowed to run this ability.', 'ai' )
			);
		}

		return $ability->execute( $input );
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_enqueue( array $args, int $user_id ) {
		$ability = $this->get_ability( $args );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		$input = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();

		return $ability->check_permissions( $input );
	}

	/**
	 * Resolves the ability named in the job arguments.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $args The job arguments.
	 * @return \WP_Ability|\WP_Error The ability, or a WP_Error.
	 */
	private function get_ability( array $args ) {
		$name = isset( $args['ability'] ) && is_string( $args['ability'] ) ? $args['ability'] : '';

		if ( '' === $name ) {
			return new WP_Error(
				'wpai_missing_ability',
				__( 'A registered ability name is required.', 'ai' )
			);
		}

		if ( self::ENQUEUE_ABILITY === $name ) {
			return new WP_Error(
				'wpai_recursive_job',
				__( 'The enqueue ability cannot be run as a job.', 'ai' )
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error(
				'wpai_abilities_unavailable',
				__( 'The Abilities API is not available.', 'ai' )
			);
		}

		$ability = wp_get_ability( $name );

		if ( ! $ability instanceof WP_Ability ) {
			return new WP_Error(
				'wpai_unknown_ability',
				/* translators: %s: Ability name. */
				sprintf( __( 'Unknown ability: %s', 'ai' ), $name )
			);
		}

		/**
		 * Filters whether an ability may be run as an async job.
		 *
		 * Return false to keep an ability synchronous even when a caller
		 * names it in an `ai/ability` job.
		 *
		 * @since 1.6.0
		 *
		 * @param bool       $allowed Whether the ability may run as a job. Default true.
		 * @param \WP_Ability $ability The ability.
		 */
		if ( ! (bool) apply_filters( 'wpai_job_ability_allowed', true, $ability ) ) {
			return new WP_Error(
				'wpai_job_ability_not_allowed',
				/* translators: %s: Ability name. */
				sprintf( __( 'The ability %s is not allowed to run as a background job.', 'ai' ), $name )
			);
		}

		return $ability;
	}
}
