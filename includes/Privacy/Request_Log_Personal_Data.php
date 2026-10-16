<?php
/**
 * Personal data exporter and eraser for AI request logs.
 *
 * @package WordPress\AI\Privacy
 */

declare( strict_types=1 );

namespace WordPress\AI\Privacy;

use WordPress\AI\Logging\AI_Request_Log_Repository;
use WordPress\AI\Logging\AI_Request_Log_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Connects AI request logs to the WordPress personal data export and erasure tools.
 *
 * Log rows are matched to a request by the user ID of the account that owns the
 * email address. Rows recorded without a logged-in user cannot be matched.
 *
 * @since x.x.x
 */
class Request_Log_Personal_Data {

	/**
	 * Exporter and eraser ID.
	 *
	 * @since x.x.x
	 */
	public const ID = 'wpai-request-logs';

	/**
	 * Rows exported per page.
	 *
	 * @since x.x.x
	 */
	public const EXPORT_PER_PAGE = 100;

	/**
	 * Rows erased per page.
	 *
	 * @since x.x.x
	 */
	public const ERASE_PER_PAGE = 1000;

	/**
	 * The schema manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Schema
	 */
	private AI_Request_Log_Schema $schema;

	/**
	 * The repository instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Repository
	 */
	private AI_Request_Log_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Schema|null     $schema     Optional schema manager.
	 * @param \WordPress\AI\Logging\AI_Request_Log_Repository|null $repository Optional repository.
	 */
	public function __construct(
		?AI_Request_Log_Schema $schema = null,
		?AI_Request_Log_Repository $repository = null
	) {
		$this->schema     = $schema ?? new AI_Request_Log_Schema();
		$this->repository = $repository ?? new AI_Request_Log_Repository( $this->schema );
	}

	/**
	 * Registers the exporter and eraser hooks.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Registers the request log exporter when the log table exists.
	 *
	 * The table is checked rather than the experiment's state, because logs
	 * recorded before the experiment was disabled remain in the table.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>> Exporters, including the request log exporter.
	 */
	public function register_exporter( $exporters ): array {
		$exporters = (array) $exporters;

		if ( ! $this->schema->table_exists() ) {
			return $exporters;
		}

		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'AI Request Logs', 'ai' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the request log eraser when the log table exists.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>> Erasers, including the request log eraser.
	 */
	public function register_eraser( $erasers ): array {
		$erasers = (array) $erasers;

		if ( ! $this->schema->table_exists() ) {
			return $erasers;
		}

		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'AI Request Logs', 'ai' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the request logs recorded for the user with the given email address.
	 *
	 * @since x.x.x
	 *
	 * @param string $email_address The email address of the data subject.
	 * @param int    $page          The page of results, starting at 1.
	 * @return array{data: list<array{group_id: string, group_label: string, group_description: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}|\WP_Error Export data, or an error if the logs could not be read.
	 */
	public function export( $email_address, $page = 1 ) {
		$user_id = $this->get_user_id( (string) $email_address );

		if ( 0 === $user_id ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rows = $this->repository->get_by_user( $user_id, self::EXPORT_PER_PAGE, max( 1, (int) $page ) );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => self::ID,
				'group_label'       => __( 'AI Request Logs', 'ai' ),
				'group_description' => __( 'Records of AI requests made while you were logged in.', 'ai' ),
				'item_id'           => self::ID . '-' . $row['log_id'],
				'data'              => $this->get_export_fields( $row ),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::EXPORT_PER_PAGE,
		);
	}

	/**
	 * Erases the request logs recorded for the user with the given email address.
	 *
	 * @since x.x.x
	 *
	 * @param string $email_address The email address of the data subject.
	 * @param int    $page          The page of the erasure, starting at 1. Unused, since erased rows no longer match.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}|\WP_Error Erasure result, or an error if the logs could not be changed.
	 */
	public function erase( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user_id = $this->get_user_id( (string) $email_address );

		if ( 0 === $user_id ) {
			return $result;
		}

		// A failed query is returned as an error, so core reports the request as failed instead of done.
		$deleted = $this->repository->delete_by_user( $user_id, self::ERASE_PER_PAGE );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		$remaining = $this->repository->count_by_user( $user_id );

		if ( is_wp_error( $remaining ) ) {
			return $remaining;
		}

		$result['items_removed'] = $deleted > 0;
		$result['done']          = 0 === $remaining;

		return $result;
	}

	/**
	 * Builds the name/value pairs exported for a log row.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $row Raw log row.
	 * @return list<array{name: string, value: string}> Export fields.
	 */
	private function get_export_fields( array $row ): array {
		$fields = array(
			__( 'Date (UTC)', 'ai' )       => $row['timestamp'] ?? '',
			__( 'Operation', 'ai' )        => $row['operation'] ?? '',
			__( 'Provider', 'ai' )         => $row['provider'] ?? '',
			__( 'Model', 'ai' )            => $row['model'] ?? '',
			__( 'Status', 'ai' )           => $row['status'] ?? '',
			__( 'Input tokens', 'ai' )     => $row['tokens_input'] ?? '',
			__( 'Output tokens', 'ai' )    => $row['tokens_output'] ?? '',
			__( 'Total tokens', 'ai' )     => $row['tokens_total'] ?? '',
			__( 'Error message', 'ai' )    => $row['error_message'] ?? '',
			__( 'Request preview', 'ai' )  => $row['request_preview'] ?? '',
			__( 'Response preview', 'ai' ) => $row['response_preview'] ?? '',
		);

		$data = array();

		foreach ( $fields as $name => $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				continue;
			}

			$data[] = array(
				'name'  => (string) $name,
				'value' => $value,
			);
		}

		return $data;
	}

	/**
	 * Returns the ID of the user with the given email address.
	 *
	 * @since x.x.x
	 *
	 * @param string $email_address The email address.
	 * @return int The user ID, or 0 if no account uses the address.
	 */
	private function get_user_id( string $email_address ): int {
		if ( '' === $email_address ) {
			return 0;
		}

		$user = get_user_by( 'email', $email_address );

		return $user ? (int) $user->ID : 0;
	}
}
