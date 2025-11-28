<?php
/**
 * REST API controller for AI request logs.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging\REST;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI\Logging\AI_Request_Log_Manager;

/**
 * Provides `/ai/v1/logs` routes for the AI Request Logs admin UI.
 *
 * @since 0.1.0
 */
class AI_Request_Log_Controller extends WP_REST_Controller {

	/**
	 * Log manager instance.
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Constructor.
	 *
	 * @param AI_Request_Log_Manager $manager Log manager.
	 */
	public function __construct( AI_Request_Log_Manager $manager ) {
		$this->namespace = 'ai/v1';
		$this->rest_base = 'logs';
		$this->manager   = $manager;
	}

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {
		// GET /ai/v1/logs - List logs with filtering.
		// POST /ai/v1/logs - Update settings (enabled, retention).
		// DELETE /ai/v1/logs - Purge all logs.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_logs' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'enabled'        => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'retention_days' => array(
							'type'     => 'integer',
							'required' => false,
							'minimum'  => 1,
							'maximum'  => 365,
						),
						'max_rows'       => array(
							'type'     => 'integer',
							'required' => false,
							'minimum'  => 1000,
							'maximum'  => 10000000,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'purge_logs' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// GET /ai/v1/logs/summary - Get aggregate statistics.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'period' => array(
							'type'    => 'string',
							'enum'    => array( 'day', 'week', 'month', 'all' ),
							'default' => 'day',
						),
					),
				),
			)
		);

		// GET /ai/v1/logs/filters - Get filter options.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/filters',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_filters' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// GET /ai/v1/logs/stats - Get table statistics.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stats' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// GET /ai/v1/logs/{id} - Get single log entry.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-f0-9\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_log' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_uuid' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check - restricted to administrators.
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Validates a UUID format.
	 *
	 * @param string $value The value to validate.
	 * @return bool Whether the value is a valid UUID.
	 */
	public function validate_uuid( string $value ): bool {
		return (bool) preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value );
	}

	/**
	 * Retrieves logs with filtering and pagination.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'type'              => $request->get_param( 'type' ) ?? '',
			'status'            => $request->get_param( 'status' ) ?? '',
			'provider'          => $request->get_param( 'provider' ) ?? '',
			'operation'         => $request->get_param( 'operation' ) ?? '',
			'operation_pattern' => $request->get_param( 'operation_pattern' ) ?? '',
			'tokens_gt'         => $request->get_param( 'tokens_gt' ),
			'tokens_lt'         => $request->get_param( 'tokens_lt' ),
			'tokens_filter'     => $request->get_param( 'tokens_filter' ) ?? '',
			'user_id'           => $request->get_param( 'user_id' ) ?? 0,
			'date_from'         => $request->get_param( 'date_from' ) ?? '',
			'date_to'           => $request->get_param( 'date_to' ) ?? '',
			'search'            => $request->get_param( 'search' ) ?? '',
			'page'              => $request->get_param( 'page' ) ?? 1,
			'per_page'          => $request->get_param( 'per_page' ) ?? 25,
			'orderby'           => $request->get_param( 'orderby' ) ?? 'timestamp',
			'order'             => $request->get_param( 'order' ) ?? 'DESC',
			'cursor_id'         => $request->get_param( 'cursor_id' ),
			'cursor_timestamp'  => $request->get_param( 'cursor_timestamp' ),
		);

		$result = $this->manager->get_logs( $args );

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

		// Include cursor info for cursor-based pagination.
		if ( isset( $result['next_cursor'] ) ) {
			$response->header( 'X-WP-NextCursorId', (string) $result['next_cursor']['id'] );
			$response->header( 'X-WP-NextCursorTimestamp', $result['next_cursor']['timestamp'] );
		}

		return $response;
	}

	/**
	 * Retrieves a single log entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_log( WP_REST_Request $request ) {
		$log_id = $request->get_param( 'id' );
		$log    = $this->manager->get_log( $log_id );

		if ( ! $log ) {
			return new WP_Error(
				'ai_log_not_found',
				__( 'Log entry not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $log );
	}

	/**
	 * Retrieves aggregate statistics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		$period  = $request->get_param( 'period' ) ?? 'day';
		$summary = $this->manager->get_summary( $period );

		return rest_ensure_response( $summary );
	}

	/**
	 * Retrieves filter options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_filters( WP_REST_Request $request ): WP_REST_Response {
		$filters = $this->manager->get_filter_options();

		return rest_ensure_response( $filters );
	}

	/**
	 * Retrieves table statistics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_stats( WP_REST_Request $request ): WP_REST_Response {
		$stats = $this->manager->get_table_stats();

		// Add warning thresholds.
		$stats['warnings'] = array();

		// Warn if table is over 80% of max rows.
		if ( $stats['row_count'] > $stats['max_rows'] * 0.8 ) {
			$stats['warnings'][] = array(
				'type'    => 'row_count',
				'level'   => $stats['row_count'] >= $stats['max_rows'] ? 'error' : 'warning',
				'message' => $stats['row_count'] >= $stats['max_rows']
					? __( 'Log table has reached the maximum row limit. Oldest logs will be automatically deleted.', 'ai' )
					: sprintf(
						/* translators: %d: Percentage of max rows used. */
						__( 'Log table is at %d%% capacity. Consider increasing the max rows limit or reducing retention.', 'ai' ),
						(int) ( ( $stats['row_count'] / $stats['max_rows'] ) * 100 )
					),
			);
		}

		// Warn if table size exceeds 100MB.
		$size_warning_threshold = 100 * 1024 * 1024; // 100MB.
		$size_error_threshold   = 500 * 1024 * 1024; // 500MB.

		if ( $stats['table_size_bytes'] > $size_warning_threshold ) {
			$stats['warnings'][] = array(
				'type'    => 'table_size',
				'level'   => $stats['table_size_bytes'] >= $size_error_threshold ? 'error' : 'warning',
				'message' => sprintf(
					/* translators: %s: Table size formatted. */
					__( 'Log table size is %s. Consider reducing retention period or max rows to improve performance.', 'ai' ),
					$stats['table_size_formatted']
				),
			);
		}

		return rest_ensure_response( $stats );
	}

	/**
	 * Updates logging settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		if ( $request->has_param( 'enabled' ) ) {
			$this->manager->set_logging_enabled( (bool) $request->get_param( 'enabled' ) );
		}

		if ( $request->has_param( 'retention_days' ) ) {
			$this->manager->set_retention_days( (int) $request->get_param( 'retention_days' ) );
		}

		if ( $request->has_param( 'max_rows' ) ) {
			$this->manager->set_max_rows( (int) $request->get_param( 'max_rows' ) );
		}

		return rest_ensure_response(
			array(
				'enabled'        => $this->manager->is_logging_enabled(),
				'retention_days' => $this->manager->get_retention_days(),
				'max_rows'       => $this->manager->get_max_rows(),
			)
		);
	}

	/**
	 * Purges all logs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function purge_logs( WP_REST_Request $request ): WP_REST_Response {
		$deleted = $this->manager->purge_all_logs();

		return rest_ensure_response(
			array(
				'success' => true,
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: Number of deleted logs. */
					__( 'Successfully purged %d log entries.', 'ai' ),
					$deleted
				),
			)
		);
	}

	/**
	 * Gets collection parameters for logs list endpoint.
	 *
	 * @return array<string, array<string, mixed>> Parameter definitions.
	 */
	public function get_collection_params(): array {
		return array(
			'type'      => array(
				'description' => __( 'Filter by log type.', 'ai' ),
				'type'        => 'string',
				'enum'        => array( '', 'ai_client', 'mcp_tool', 'ability' ),
				'default'     => '',
			),
			'status'    => array(
				'description' => __( 'Filter by status.', 'ai' ),
				'type'        => 'string',
				'enum'        => array( '', 'success', 'error', 'timeout' ),
				'default'     => '',
			),
			'provider'  => array(
				'description' => __( 'Filter by AI provider.', 'ai' ),
				'type'        => 'string',
				'default'     => '',
			),
			'user_id'   => array(
				'description' => __( 'Filter by user ID.', 'ai' ),
				'type'        => 'integer',
				'default'     => 0,
			),
			'date_from' => array(
				'description' => __( 'Filter logs from this date (YYYY-MM-DD HH:MM:SS).', 'ai' ),
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'date_to'   => array(
				'description' => __( 'Filter logs until this date (YYYY-MM-DD HH:MM:SS).', 'ai' ),
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'search'            => array(
				'description' => __( 'Search in operation and error message.', 'ai' ),
				'type'        => 'string',
				'default'     => '',
			),
			'operation_pattern' => array(
				'description' => __( 'Regex pattern to filter operations (e.g., ":completions$" for completion requests only).', 'ai' ),
				'type'        => 'string',
				'default'     => '',
			),
			'tokens_gt'         => array(
				'description' => __( 'Filter logs with tokens greater than this value.', 'ai' ),
				'type'        => 'integer',
				'minimum'     => 0,
			),
			'tokens_lt'         => array(
				'description' => __( 'Filter logs with tokens less than this value.', 'ai' ),
				'type'        => 'integer',
				'minimum'     => 0,
			),
			'tokens_filter'     => array(
				'description' => __( 'Filter by tokens: "gt:N", "lt:N", or "none".', 'ai' ),
				'type'        => 'string',
				'default'     => '',
			),
			'page'              => array(
				'description' => __( 'Current page of the collection.', 'ai' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page'  => array(
				'description' => __( 'Maximum number of items per page.', 'ai' ),
				'type'        => 'integer',
				'default'     => 25,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'orderby'   => array(
				'description' => __( 'Sort collection by attribute.', 'ai' ),
				'type'        => 'string',
				'enum'        => array( 'timestamp', 'type', 'operation', 'duration_ms', 'tokens_total', 'cost_estimate', 'status' ),
				'default'     => 'timestamp',
			),
			'order'     => array(
				'description' => __( 'Order sort attribute ascending or descending.', 'ai' ),
				'type'        => 'string',
				'enum'        => array( 'ASC', 'DESC' ),
				'default'     => 'DESC',
			),
			'cursor_id'        => array(
				'description' => __( 'Cursor ID for cursor-based pagination (use with cursor_timestamp).', 'ai' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'cursor_timestamp' => array(
				'description' => __( 'Cursor timestamp for cursor-based pagination (use with cursor_id).', 'ai' ),
				'type'        => 'string',
				'format'      => 'date-time',
			),
		);
	}
}
