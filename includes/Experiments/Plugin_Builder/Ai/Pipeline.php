<?php
/**
 * Pipeline orchestrator class.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Ai;

use WordPress\AI\Experiments\Plugin_Builder\Config;

/**
 * Pipeline orchestrator — Plan + Generate.
 *
 * @since x.x.x
 */
class Pipeline {

	/**
	 * Max lines of context from a previously-generated file.
	 */
	private const MAX_CONTEXT_LINES_FEW  = 2000;
	private const MAX_CONTEXT_LINES_MANY = 1000;

	/**
	 * Run the full pipeline for a given job.
	 *
	 * Updates the WP transient after each step so the frontend can poll.
	 *
	 * @since x.x.x
	 *
	 * @param string     $job_id         Unique job identifier.
	 * @param string     $description    User's plugin description.
	 * @param string     $complexity     'simple' or 'complex'.
	 * @param array|null $previous_plan  Previous plan for context (modification requests).
	 * @param array|null $previous_files Previous files for context (modification requests).
	 */
	public function run(
		string $job_id,
		string $description,
		string $complexity = 'simple',
		?array $previous_plan = null,
		?array $previous_files = null
	): void {
		$transient_key = 'apb_job_' . $job_id;

		try {
			// --- Step 1: Plan ---
			$step_label = $previous_plan ? 'Analyzing modification request...' : 'Generating plugin plan...';
			$this->update_state(
				$transient_key,
				array(
					'status'       => 'planning',
					'current_step' => $step_label,
				)
			);

			$plan = $this->generate_plan( $description, $complexity, $previous_plan );

			$this->update_state(
				$transient_key,
				array(
					'status'       => 'planning',
					'current_step' => sprintf( 'Plan ready: %s (%d files)', $plan['plugin_name'], count( $plan['files'] ) ),
					'plan'         => $plan,
				)
			);

			// --- Step 2: Generate code ---
			$this->update_state(
				$transient_key,
				array(
					'status'       => 'coding',
					'current_step' => sprintf( 'Generating code for %d file(s)...', count( $plan['files'] ) ),
				)
			);

			$files = $this->generate_all_files( $plan, $transient_key );

			// --- Step 3: Security scan ---
			$scan = SecurityScanner::scan( $files );

			$review = null;
			if ( ! $scan['passed'] ) {
				$summary_parts = array();
				foreach ( $scan['issues'] as $issue ) {
					$summary_parts[] = sprintf( '%s line %d: %s', $issue['file_path'], $issue['line'], $issue['line_content'] );
				}
				$review = array(
					'passed'         => false,
					'review_summary' => 'Security scanner found potentially dangerous patterns.',
					'suggestions'    => array_map(
						static function ( $issue ) {
							return array(
								'action'      => 'UPDATE',
								'file_path'   => $issue['file_path'],
								'file_type'   => 'php',
								'reason'      => 'Dangerous pattern detected',
								'description' => sprintf( 'Line %d: %s', $issue['line'], $issue['line_content'] ),
							);
						},
						$scan['issues']
					),
				);
			}

			$this->update_state(
				$transient_key,
				array(
					'status'       => 'done',
					'current_step' => 'Plugin generated successfully!',
					'files'        => $files,
					'review'       => $review,
				)
			);
		} catch ( \Throwable $e ) {
			$this->update_state(
				$transient_key,
				array(
					'status'       => 'error',
					'current_step' => 'Error: ' . $e->getMessage(),
					'error'        => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Generate the plugin plan via the LLM.
	 *
	 * @param string     $description    User's plugin description.
	 * @param string     $complexity     'simple' or 'complex'.
	 * @param array<string, mixed>|null $previous_plan  Previous plan for context.
	 * @return array{plugin_name: string, plugin_slug: string, description: string, complexity: string, is_modification: bool, files: array<int, array<string, mixed>>, hooks_used: array<int, string>, wp_apis_used: array<int, string>, security_notes: array<int, string>, architecture: string} Plan data.
	 * @throws \RuntimeException If the prompt fails.
	 */
	private function generate_plan(
		string $description,
		string $complexity,
		?array $previous_plan = null
	): array {
		$system = Prompts::system_prompt( 'planner' );
		$prompt = Prompts::planner( $description, $complexity, Config::max_files(), $previous_plan );

		$response = wp_ai_client_prompt( $prompt )
			->with_system_prompt( $system )
			->using_max_tokens( Config::planner_max_tokens() )
			->using_temperature( 0.3 )
			->as_json_response()
			->generate_text();

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( $response->get_error_message() );
		}

		$plan = json_decode( $response, true );

		if ( null === $plan ) {
			throw new \RuntimeException( 'Failed to parse JSON response from LLM.' );
		}

		// Validate: ensure files array exists and is not empty.
		if ( empty( $plan['files'] ) || ! is_array( $plan['files'] ) ) {
			throw new \RuntimeException( 'Invalid plan: No files specified by the planner.' );
		}

		// Validate: ensure at least one main file.
		$has_main = false;
		foreach ( $plan['files'] as &$file ) {
			if ( ! empty( $file['is_main'] ) ) {
				$has_main = true;
				break;
			}
		}
		if ( ! $has_main ) {
			foreach ( $plan['files'] as &$file ) {
				if ( 'php' === ( $file['type'] ?? '' ) && false === strpos( $file['path'], '/' ) ) {
					$file['is_main'] = true;
					break;
				}
			}
		}
		unset( $file );

		return $plan;
	}

	/**
	 * Generate all planned files sequentially.
	 *
	 * @param array  $plan           The plugin plan.
	 * @param string $transient_key  Transient key for state updates.
	 * @return array Generated files [{path, type, content, description, is_main}, ...].
	 */
	private function generate_all_files( array $plan, string $transient_key ): array {
		$generated = array();

		// Sort: main file first, then alphabetical.
		$files = $plan['files'];
		usort(
			$files,
			static function ( $a, $b ) {
				$a_main = ! empty( $a['is_main'] ) ? 0 : 1;
				$b_main = ! empty( $b['is_main'] ) ? 0 : 1;
				if ( $a_main !== $b_main ) {
					return $a_main - $b_main;
				}
				return strcmp( $a['path'], $b['path'] );
			}
		);

		foreach ( $files as $index => $file_info ) {
			$this->update_state(
				$transient_key,
				array(
					'current_step' => sprintf( 'Generating %s (%d/%d)...', $file_info['path'], $index + 1, count( $files ) ),
				)
			);

			$gen_file    = $this->generate_file( $plan, $file_info, $generated );
			$generated[] = $gen_file;
		}

		return $generated;
	}

	/**
	 * Generate a single file.
	 *
	 * @param array $plan Plan info.
	 * @param array $file_info File info.
	 * @param array $previous_files Previous files context.
	 * @return array Generated file.
	 * @throws \RuntimeException On error.
	 */
	private function generate_file( array $plan, array $file_info, array $previous_files ): array {
		$file_type = $file_info['type'] ?? 'php';
		$context   = $this->build_file_context( $previous_files );

		switch ( $file_type ) {
			case 'css':
				$prompt = Prompts::coder_css( $plan, $file_info['path'], $file_info['description'], $context );
				break;
			case 'js':
				$prompt = Prompts::coder_js( $plan, $file_info['path'], $file_info['description'], $context );
				break;
			default:
				$prompt = Prompts::coder_php(
					$plan,
					$file_info['path'],
					$file_info['description'],
					! empty( $file_info['is_main'] ),
					$context
				);
				break;
		}

		$system = Prompts::system_prompt( 'coder', $file_type );

		$response = wp_ai_client_prompt( $prompt )
			->with_system_prompt( $system )
			->using_max_tokens( Config::coder_max_tokens() )
			->using_temperature( 0.2 )
			->generate_text();

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( $response->get_error_message() );
		}

		// Strip markdown fences.
		$content = self::strip_code_fences( $response );

		return array(
			'path'        => $file_info['path'],
			'type'        => $file_type,
			'content'     => $content,
			'description' => $file_info['description'],
			'is_main'     => ! empty( $file_info['is_main'] ),
		);
	}

	/**
	 * Build truncated context from previously generated files.
	 *
	 * @param array $previous_files Already-generated file arrays.
	 * @return array Array of {path, content} with truncated content.
	 */
	private function build_file_context( array $previous_files ): array {
		$max_lines = count( $previous_files ) <= 5
			? self::MAX_CONTEXT_LINES_FEW
			: self::MAX_CONTEXT_LINES_MANY;

		$context = array();
		foreach ( $previous_files as $file ) {
			$lines = explode( "\n", $file['content'] );
			if ( count( $lines ) > $max_lines ) {
				$omitted = count( $lines ) - $max_lines;
				$content = implode( "\n", array_slice( $lines, 0, $max_lines ) )
					. "\n// ... truncated ({$omitted} lines omitted)";
			} else {
				$content = $file['content'];
			}
			$context[] = array(
				'path'    => $file['path'],
				'content' => $content,
			);
		}
		return $context;
	}

	/**
	 * Merge partial updates into the job state.
	 * Uses direct database access to bypass object cache issues on shared hosting.
	 *
	 * @param string $transient_key Transient key.
	 * @param array  $updates       New updates.
	 */
	private function update_state( string $transient_key, array $updates ): void {
		$state = self::get_job_state( $transient_key );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state = array_merge( $state, $updates );
		self::set_job_state( $transient_key, $state );
	}

	/**
	 * Get job state directly from database, bypassing object cache.
	 *
	 * @param string $key Transient key.
	 * @return array|false Job state or false if not found.
	 */
	public static function get_job_state( string $key ) {
		global $wpdb;

		// Clear any cached value first.
		wp_cache_delete( $key, 'transient' );
		wp_cache_delete( '_transient_' . $key, 'options' );

		// Read directly from database.
		$option_name = '_transient_' . $key;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		if ( null === $value ) {
			return false;
		}

		$data = maybe_unserialize( $value );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Set job state directly to database, bypassing object cache.
	 *
	 * @param string $key   Transient key.
	 * @param array  $state Job state.
	 */
	public static function set_job_state( string $key, array $state ): void {
		global $wpdb;

		$option_name  = '_transient_' . $key;
		$timeout_name = '_transient_timeout_' . $key;
		$expiration   = time() + HOUR_IN_SECONDS;
		$value        = maybe_serialize( $state );

		// Clear cache first.
		wp_cache_delete( $key, 'transient' );
		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );

		// Check if exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				array( 'option_value' => $value ),
				array( 'option_name' => $option_name )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				array( 'option_value' => $expiration ),
				array( 'option_name' => $timeout_name )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $timeout_name,
					'option_value' => $expiration,
					'autoload'     => 'no',
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $option_name,
					'option_value' => $value,
					'autoload'     => 'no',
				)
			);
		}
	}

	/**
	 * Strip markdown code fences from generated code output.
	 *
	 * @param string $text Generated code text.
	 * @return string Code without outer markdown fences.
	 */
	private static function strip_code_fences( string $text ): string {
		$text = trim( $text );

		if ( 0 === strpos( $text, '```' ) ) {
			$lines = explode( "\n", $text );
			$start = 1;
			$end   = count( $lines );
			for ( $i = count( $lines ) - 1; $i > 0; $i-- ) {
				if ( '```' === trim( $lines[ $i ] ) ) {
					$end = $i;
					break;
				}
			}
			$text = implode( "\n", array_slice( $lines, $start, $end - $start ) );
		}

		return $text;
	}
}
