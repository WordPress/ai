<?php
/**
 * Contract for registered job types.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A unit of work that the async queue can run.
 *
 * Job types are registered by ID. A queued job stores only its type and
 * arguments, never code, so a site can inspect and validate work before it
 * runs and a malicious payload cannot inject executable code.
 *
 * @since 1.6.0
 */
interface Job_Type_Interface {

	/**
	 * Returns the unique job type ID.
	 *
	 * Use a namespaced slug such as `ai/ability` or `my-plugin/import-post`.
	 *
	 * @since 1.6.0
	 *
	 * @return string The job type ID.
	 */
	public function get_id(): string;

	/**
	 * Returns a human-readable label.
	 *
	 * @since 1.6.0
	 *
	 * @return string The label.
	 */
	public function get_label(): string;

	/**
	 * Performs the work.
	 *
	 * Called from a WP-Cron request, with the enqueuing user restored as the
	 * current user. Return a WP_Error to mark the job as failed.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed>          $args The job arguments.
	 * @param \WordPress\AI\Jobs\Job        $job  The job record.
	 * @return mixed|\WP_Error The result, or a WP_Error on failure.
	 */
	public function handle( array $args, Job $job );

	/**
	 * Checks whether a user may enqueue this job.
	 *
	 * Runs when the job is enqueued and again before it executes, both with
	 * the enqueuing user as the current user.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $args    The job arguments.
	 * @param int                  $user_id The user who wants to enqueue the job.
	 * @return bool|\WP_Error True when allowed, or a WP_Error explaining why not.
	 */
	public function can_enqueue( array $args, int $user_id );

	/**
	 * Returns the JSON schema for the job arguments.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, mixed> The schema.
	 */
	public function get_input_schema(): array;
}
