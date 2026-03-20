<?php
/**
 * BackgroundJob for handling async pipeline jobs.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Ai;

use WordPress\AI\Experiments\Plugin_Builder\Config;

/**
 * WordPress background processing via non-blocking loopback POST to admin-ajax.php.
 *
 * @since x.x.x
 */
class BackgroundJob {

	public const ACTION = 'apb_run_pipeline';

	/**
	 * Register the AJAX handler (called from Plugin bootstrap).
	 *
	 * @since x.x.x
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( new self(), 'handle' ) );
	}

	/**
	 * Dispatch a background pipeline job via non-blocking loopback.
	 *
	 * @since x.x.x
	 *
	 * @param string     $job_id         UUID of the job.
	 * @param string     $description    Plugin description.
	 * @param string     $complexity     'simple' or 'complex'.
	 * @param array|null $previous_plan  Previous plan for context (modification requests).
	 * @param array|null $previous_files Previous files for context (modification requests).
	 */
	public static function dispatch(
		string $job_id,
		string $description,
		string $complexity,
		?array $previous_plan = null,
		?array $previous_files = null
	): void {
		$url = admin_url( 'admin-ajax.php' );

		$body = array(
			'action'      => self::ACTION,
			'job_id'      => $job_id,
			'description' => $description,
			'complexity'  => $complexity,
			'_nonce'      => wp_create_nonce( self::ACTION ),
		);

		// Add context if provided (for modification requests).
		if ( $previous_plan ) {
			$body['previous_plan'] = wp_json_encode( $previous_plan );
		}
		if ( $previous_files ) {
			$body['previous_files'] = wp_json_encode( $previous_files );
		}

		wp_safe_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'sslverify' => apply_filters( 'wp_ai_experiments_apb_https_local_ssl_verify', false ),
				// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
				'cookies'   => $_COOKIE,
				'body'      => $body,
			)
		);
	}

	/**
	 * Handle the background AJAX request.
	 *
	 * @since x.x.x
	 */
	public function handle(): void {
		// Verify nonce.
		if ( ! check_ajax_referer( self::ACTION, '_nonce', false ) ) {
			wp_die( 'Invalid nonce', 'Forbidden', array( 'response' => 403 ) );
		}

		// Verify capability.
		if ( ! current_user_can( Config::generate_capability() ) ) {
			wp_die( 'Insufficient permissions', 'Forbidden', array( 'response' => 403 ) );
		}

		$job_id      = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$complexity  = isset( $_POST['complexity'] ) ? sanitize_text_field( wp_unslash( $_POST['complexity'] ) ) : 'simple';

		// Parse context from JSON if provided.
		$previous_plan  = null;
		$previous_files = null;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_plan = isset( $_POST['previous_plan'] ) ? wp_unslash( $_POST['previous_plan'] ) : '';
		if ( ! empty( $raw_plan ) ) {
			$decoded = json_decode( $raw_plan, true, 32 ); // Limit depth to prevent DoS.
			if ( is_array( $decoded ) && isset( $decoded['plugin_slug'] ) ) {
				$previous_plan = $decoded;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_files = isset( $_POST['previous_files'] ) ? wp_unslash( $_POST['previous_files'] ) : '';
		if ( ! empty( $raw_files ) ) {
			$decoded = json_decode( $raw_files, true, 32 ); // Limit depth to prevent DoS.
			if ( is_array( $decoded ) ) {
				$previous_files = $decoded;
			}
		}

		if ( empty( $job_id ) || empty( $description ) ) {
			wp_die( 'Missing required parameters', 'Bad Request', array( 'response' => 400 ) );
		}

		// Give the pipeline plenty of time to run.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Generic.PHP.NoSilencedErrors.Forbidden
		@set_time_limit( 300 );

		// Increase memory limit for large generations.
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed,WordPress.PHP.NoSilencedErrors.Discouraged,Generic.PHP.NoSilencedErrors.Forbidden
		@ini_set( 'memory_limit', '512M' );

		$pipeline = new Pipeline();
		$pipeline->run( $job_id, $description, $complexity, $previous_plan, $previous_files );

		wp_die();
	}
}
