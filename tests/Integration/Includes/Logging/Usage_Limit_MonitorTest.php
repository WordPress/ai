<?php
/**
 * Integration tests for Usage_Limit_Monitor.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Usage_Limit_Monitor;

/**
 * Usage_Limit_Monitor test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Logging\Usage_Limit_Monitor
 */
class Usage_Limit_MonitorTest extends WP_UnitTestCase {

	/**
	 * Log manager backed by the real request log table.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Emails captured from wp_mail().
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $emails = array();

	/**
	 * Set up test case.
	 */
	protected function setUp(): void {
		parent::setUp();

		delete_option( 'wpai_request_logs_schema_version' );

		$this->manager = new AI_Request_Log_Manager();
		$this->manager->init();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		delete_transient( Usage_Limit_Monitor::USAGE_TRANSIENT );
		delete_option( Usage_Limit_Monitor::ALERTS_OPTION );

		$this->emails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_email' ), 10, 2 );
	}

	/**
	 * Tear down test case.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_email' ) );
		delete_transient( Usage_Limit_Monitor::USAGE_TRANSIENT );
		delete_option( Usage_Limit_Monitor::ALERTS_OPTION );
		delete_option( 'wpai_request_logs_schema_version' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Records an email instead of sending it.
	 *
	 * @param bool|null            $short_circuit Short-circuit value.
	 * @param array<string, mixed> $atts          Email arguments.
	 * @return bool
	 */
	public function capture_email( $short_circuit, array $atts ): bool {
		$this->emails[] = $atts;

		return true;
	}

	/**
	 * Logs a request that used the given number of tokens.
	 *
	 * @param int $tokens Total tokens.
	 */
	private function log_tokens( int $tokens ): void {
		$this->manager->log(
			array(
				'type'          => 'ai_client',
				'operation'     => 'openai:completions',
				'provider'      => 'openai',
				'tokens_input'  => $tokens,
				'tokens_output' => 0,
				'status'        => 'success',
			)
		);
	}

	/**
	 * A limit of zero disables the monitor and registers no hooks.
	 */
	public function test_zero_limit_is_disabled(): void {
		$monitor = new Usage_Limit_Monitor( $this->manager, 0, 80 );
		$monitor->register();

		$this->assertFalse( $monitor->is_enabled() );
		$this->assertFalse( has_action( 'admin_notices', array( $monitor, 'render_admin_notice' ) ) );

		$status = $monitor->get_status();
		$this->assertSame( Usage_Limit_Monitor::STATE_OK, $status['state'] );
		$this->assertSame( 0, $status['used'] );
	}

	/**
	 * Out-of-range constructor values are clamped.
	 */
	public function test_constructor_clamps_values(): void {
		$status = ( new Usage_Limit_Monitor( $this->manager, -5, 500 ) )->get_status();

		$this->assertSame( 0, $status['limit'] );
		$this->assertSame( 100, $status['threshold'] );
		$this->assertSame( 1, ( new Usage_Limit_Monitor( $this->manager, 10, 0 ) )->get_status()['threshold'] );
	}

	/**
	 * Registering with a limit hooks into logging and admin notices.
	 */
	public function test_register_adds_hooks_when_enabled(): void {
		$monitor = new Usage_Limit_Monitor( $this->manager, 1000, 80 );
		$monitor->register();

		$this->assertSame( 10, has_action( 'wpai_request_logged', array( $monitor, 'handle_request_logged' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $monitor, 'render_admin_notice' ) ) );

		remove_action( 'wpai_request_logged', array( $monitor, 'handle_request_logged' ) );
		remove_action( 'admin_notices', array( $monitor, 'render_admin_notice' ) );
	}

	/**
	 * Usage counts only this calendar month.
	 */
	public function test_month_usage_excludes_previous_months(): void {
		global $wpdb;

		$this->log_tokens( 300 );
		$this->log_tokens( 200 );

		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET timestamp = %s WHERE tokens_total = 200", gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$monitor = new Usage_Limit_Monitor( $this->manager, 1000, 80 );

		$this->assertSame( 300, $monitor->get_month_usage( true ) );
	}

	/**
	 * Usage is cached and the cache is topped up as requests are logged.
	 */
	public function test_month_usage_cache_is_updated_on_logged_requests(): void {
		$monitor = new Usage_Limit_Monitor( $this->manager, 100000, 80 );

		$this->log_tokens( 100 );
		$this->assertSame( 100, $monitor->get_month_usage() );

		// Logged without the hook, so only a refresh sees it.
		$this->log_tokens( 50 );
		$this->assertSame( 100, $monitor->get_month_usage() );
		$this->assertSame( 150, $monitor->get_month_usage( true ) );

		$monitor->register();
		$this->log_tokens( 25 );
		$this->assertSame( 175, $monitor->get_month_usage() );

		remove_action( 'wpai_request_logged', array( $monitor, 'handle_request_logged' ) );
		remove_action( 'admin_notices', array( $monitor, 'render_admin_notice' ) );
	}

	/**
	 * A cache from a previous month is ignored.
	 */
	public function test_month_usage_ignores_cache_from_other_month(): void {
		set_transient(
			Usage_Limit_Monitor::USAGE_TRANSIENT,
			array(
				'month'  => '1999-01',
				'tokens' => 99999,
			)
		);
		$this->log_tokens( 10 );

		$this->assertSame( 10, ( new Usage_Limit_Monitor( $this->manager, 1000, 80 ) )->get_month_usage() );
	}

	/**
	 * The state follows the threshold and the limit.
	 */
	public function test_status_states(): void {
		$monitor = new Usage_Limit_Monitor( $this->manager, 1000, 80 );

		$this->log_tokens( 799 );
		$status = $monitor->get_status( true );
		$this->assertSame( Usage_Limit_Monitor::STATE_OK, $status['state'] );
		$this->assertSame( 79, $status['percent'] );

		$this->log_tokens( 1 );
		$status = $monitor->get_status( true );
		$this->assertSame( Usage_Limit_Monitor::STATE_WARNING, $status['state'] );
		$this->assertSame( 80, $status['percent'] );

		$this->log_tokens( 200 );
		$status = $monitor->get_status( true );
		$this->assertSame( Usage_Limit_Monitor::STATE_EXCEEDED, $status['state'] );
		$this->assertSame( 100, $status['percent'] );
		$this->assertSame( 1000, $status['used'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $status['resets_at'] );
		$this->assertGreaterThan( time(), strtotime( $status['resets_at'] ) );
	}

	/**
	 * Each state is emailed once per month, and the limit email covers the warning.
	 */
	public function test_alerts_are_sent_once_per_state(): void {
		$monitor = new Usage_Limit_Monitor( $this->manager, 1000, 80 );
		$monitor->register();

		$this->log_tokens( 500 );
		$this->assertCount( 0, $this->emails );

		$this->log_tokens( 400 );
		$this->assertCount( 1, $this->emails );
		$this->assertSame( get_option( 'admin_email' ), $this->emails[0]['to'] );
		$this->assertStringContainsString( '90%', $this->emails[0]['subject'] );
		$this->assertStringContainsString( 'tools.php?page=ai-request-logs', $this->emails[0]['message'] );

		$this->log_tokens( 10 );
		$this->assertCount( 1, $this->emails, 'The warning should only be sent once.' );

		$this->log_tokens( 200 );
		$this->assertCount( 2, $this->emails );
		$this->assertStringContainsString( 'limit reached', $this->emails[1]['subject'] );

		$this->log_tokens( 200 );
		$this->assertCount( 2, $this->emails, 'The limit email should only be sent once.' );

		remove_action( 'wpai_request_logged', array( $monitor, 'handle_request_logged' ) );
		remove_action( 'admin_notices', array( $monitor, 'render_admin_notice' ) );
	}

	/**
	 * Going straight past the limit sends only the limit email.
	 */
	public function test_exceeding_without_warning_sends_single_email(): void {
		$this->log_tokens( 5000 );

		$monitor = new Usage_Limit_Monitor( $this->manager, 1000, 80 );

		$this->assertTrue( $monitor->maybe_send_alert() );
		$this->assertFalse( $monitor->maybe_send_alert() );
		$this->assertCount( 1, $this->emails );
		$this->assertSame(
			array( Usage_Limit_Monitor::STATE_EXCEEDED, Usage_Limit_Monitor::STATE_WARNING ),
			get_option( Usage_Limit_Monitor::ALERTS_OPTION )['states']
		);
	}

	/**
	 * Alerts recorded for a previous month do not suppress this month's alerts.
	 */
	public function test_alerts_from_previous_month_are_ignored(): void {
		update_option(
			Usage_Limit_Monitor::ALERTS_OPTION,
			array(
				'month'  => '1999-01',
				'states' => array( Usage_Limit_Monitor::STATE_WARNING, Usage_Limit_Monitor::STATE_EXCEEDED ),
			)
		);
		$this->log_tokens( 900 );

		$this->assertTrue( ( new Usage_Limit_Monitor( $this->manager, 1000, 80 ) )->maybe_send_alert() );
		$this->assertCount( 1, $this->emails );
	}

	/**
	 * Below the threshold no alert is sent.
	 */
	public function test_no_alert_below_threshold(): void {
		$this->log_tokens( 100 );

		$this->assertFalse( ( new Usage_Limit_Monitor( $this->manager, 1000, 80 ) )->maybe_send_alert() );
		$this->assertCount( 0, $this->emails );
		$this->assertFalse( get_option( Usage_Limit_Monitor::ALERTS_OPTION ) );
	}

	/**
	 * Emails can be turned off, and the alert is still recorded.
	 */
	public function test_email_can_be_disabled_by_filter(): void {
		add_filter( 'wpai_usage_limit_send_email', '__return_false' );
		$this->log_tokens( 900 );

		$this->assertFalse( ( new Usage_Limit_Monitor( $this->manager, 1000, 80 ) )->maybe_send_alert() );
		$this->assertCount( 0, $this->emails );
		$this->assertSame( array( Usage_Limit_Monitor::STATE_WARNING ), get_option( Usage_Limit_Monitor::ALERTS_OPTION )['states'] );

		remove_filter( 'wpai_usage_limit_send_email', '__return_false' );
	}

	/**
	 * Recipients can be changed or removed by filter.
	 */
	public function test_email_recipients_are_filterable(): void {
		$this->log_tokens( 900 );

		$recipients = static function (): array {
			return array( 'ops@example.com' );
		};
		add_filter( 'wpai_usage_limit_email_recipients', $recipients );

		( new Usage_Limit_Monitor( $this->manager, 1000, 80 ) )->maybe_send_alert();

		remove_filter( 'wpai_usage_limit_email_recipients', $recipients );

		$this->assertSame( array( 'ops@example.com' ), $this->emails[0]['to'] );

		delete_option( Usage_Limit_Monitor::ALERTS_OPTION );
		add_filter( 'wpai_usage_limit_email_recipients', '__return_empty_array' );

		$this->assertFalse( ( new Usage_Limit_Monitor( $this->manager, 1000, 80 ) )->maybe_send_alert() );
		$this->assertCount( 1, $this->emails );

		remove_filter( 'wpai_usage_limit_email_recipients', '__return_empty_array' );
	}

	/**
	 * Administrators see a warning notice at the threshold.
	 */
	public function test_admin_notice_warning(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->log_tokens( 850 );

		$output = get_echo( array( new Usage_Limit_Monitor( $this->manager, 1000, 80 ), 'render_admin_notice' ) );

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( '85%', $output );
		$this->assertStringContainsString( 'tools.php?page=ai-request-logs', $output );
	}

	/**
	 * Administrators see an error notice once the limit is reached.
	 */
	public function test_admin_notice_exceeded(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->log_tokens( 1200 );

		$output = get_echo( array( new Usage_Limit_Monitor( $this->manager, 1000, 80 ), 'render_admin_notice' ) );

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'reaching the monthly limit', $output );
	}

	/**
	 * No notice is shown below the threshold or to users who cannot manage options.
	 */
	public function test_admin_notice_hidden(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->log_tokens( 100 );

		$this->assertSame( '', get_echo( array( new Usage_Limit_Monitor( $this->manager, 1000, 80 ), 'render_admin_notice' ) ) );

		$this->log_tokens( 2000 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', get_echo( array( new Usage_Limit_Monitor( $this->manager, 1000, 80 ), 'render_admin_notice' ) ) );
	}
}
