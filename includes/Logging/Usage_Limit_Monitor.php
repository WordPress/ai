<?php
/**
 * Monthly token limit warnings for AI requests.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks token usage for the current calendar month against a configured limit.
 *
 * When usage reaches the alert threshold or the limit, site administrators see an
 * admin notice and get one email per state per month. Requests are never blocked.
 *
 * @since x.x.x
 */
class Usage_Limit_Monitor {

	/**
	 * Usage state below the alert threshold.
	 */
	public const STATE_OK = 'ok';

	/**
	 * Usage state at or above the alert threshold but below the limit.
	 */
	public const STATE_WARNING = 'warning';

	/**
	 * Usage state at or above the limit.
	 */
	public const STATE_EXCEEDED = 'exceeded';

	/**
	 * Option that records which alert emails were sent this month.
	 */
	public const ALERTS_OPTION = 'wpai_usage_limit_alerts';

	/**
	 * Transient that caches this month's token usage.
	 */
	public const USAGE_TRANSIENT = 'wpai_usage_limit_month_tokens';

	/**
	 * How long the cached usage is trusted, in seconds.
	 */
	private const USAGE_CACHE_EXPIRATION = 5 * MINUTE_IN_SECONDS;

	/**
	 * Log manager used to read token usage.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Monthly token limit, or 0 when no limit is set.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Percentage of the limit at which to warn.
	 *
	 * @var int
	 */
	private int $threshold;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager $manager   Log manager.
	 * @param int                                          $limit     Monthly token limit, or 0 for no limit.
	 * @param int                                          $threshold Percentage of the limit at which to warn (1-100).
	 */
	public function __construct( AI_Request_Log_Manager $manager, int $limit, int $threshold ) {
		$this->manager   = $manager;
		$this->limit     = max( 0, $limit );
		$this->threshold = min( 100, max( 1, $threshold ) );
	}

	/**
	 * Registers the hooks that keep usage current and show warnings.
	 *
	 * Does nothing when no limit is set.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'wpai_request_logged', array( $this, 'handle_request_logged' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
	}

	/**
	 * Whether a monthly limit is set.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->limit > 0;
	}

	/**
	 * Adds a newly logged request to this month's usage and sends any due alert.
	 *
	 * @since x.x.x
	 *
	 * @param string               $log_id Log ID.
	 * @param array<string, mixed> $data   Logged data.
	 */
	public function handle_request_logged( string $log_id, array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Required by the action signature.
		$tokens = isset( $data['tokens_total'] ) ? (int) $data['tokens_total'] : 0;
		$cached = get_transient( self::USAGE_TRANSIENT );

		if ( $tokens > 0 && is_array( $cached ) && ( $cached['month'] ?? '' ) === $this->get_month_key() ) {
			$cached['tokens'] = (int) ( $cached['tokens'] ?? 0 ) + $tokens;
			set_transient( self::USAGE_TRANSIENT, $cached, self::USAGE_CACHE_EXPIRATION );
		}

		$this->maybe_send_alert();
	}

	/**
	 * Gets the tokens used so far this calendar month, in the site's time zone.
	 *
	 * @since x.x.x
	 *
	 * @param bool $force_refresh Whether to bypass the cache.
	 * @return int Tokens used this month.
	 */
	public function get_month_usage( bool $force_refresh = false ): int {
		$month = $this->get_month_key();

		if ( ! $force_refresh ) {
			$cached = get_transient( self::USAGE_TRANSIENT );

			if ( is_array( $cached ) && ( $cached['month'] ?? '' ) === $month ) {
				return (int) ( $cached['tokens'] ?? 0 );
			}
		}

		$tokens = $this->manager->get_tokens_since( $this->get_month_start_gmt() );

		set_transient(
			self::USAGE_TRANSIENT,
			array(
				'month'  => $month,
				'tokens' => $tokens,
			),
			self::USAGE_CACHE_EXPIRATION
		);

		return $tokens;
	}

	/**
	 * Gets the current usage against the limit.
	 *
	 * @since x.x.x
	 *
	 * @param bool $force_refresh Whether to bypass the usage cache.
	 * @return array{limit: int, threshold: int, used: int, percent: int, state: string, resets_at: string} Usage status. `resets_at` is a GMT ISO 8601 date.
	 */
	public function get_status( bool $force_refresh = false ): array {
		$used    = $this->is_enabled() ? $this->get_month_usage( $force_refresh ) : 0;
		$percent = $this->is_enabled() ? (int) floor( $used / $this->limit * 100 ) : 0;

		if ( $this->is_enabled() && $used >= $this->limit ) {
			$state = self::STATE_EXCEEDED;
		} elseif ( $this->is_enabled() && $percent >= $this->threshold ) {
			$state = self::STATE_WARNING;
		} else {
			$state = self::STATE_OK;
		}

		return array(
			'limit'     => $this->limit,
			'threshold' => $this->threshold,
			'used'      => $used,
			'percent'   => $percent,
			'state'     => $state,
			'resets_at' => $this->get_next_month_start()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Emails site administrators the first time each state is reached in a month.
	 *
	 * @since x.x.x
	 *
	 * @return bool Whether an email was sent.
	 */
	public function maybe_send_alert(): bool {
		$status = $this->get_status();

		if ( self::STATE_OK === $status['state'] ) {
			return false;
		}

		$month = $this->get_month_key();
		$sent  = get_option( self::ALERTS_OPTION, array() );
		$sent  = is_array( $sent ) && ( $sent['month'] ?? '' ) === $month && is_array( $sent['states'] ?? null ) ? $sent['states'] : array();

		if ( in_array( $status['state'], $sent, true ) ) {
			return false;
		}

		// Reaching the limit covers the warning too, so it is never sent afterwards.
		$sent[] = $status['state'];
		if ( self::STATE_EXCEEDED === $status['state'] ) {
			$sent[] = self::STATE_WARNING;
		}

		update_option(
			self::ALERTS_OPTION,
			array(
				'month'  => $month,
				'states' => array_values( array_unique( $sent ) ),
			),
			false
		);

		/**
		 * Filters whether to email administrators when AI token usage reaches the monthly limit or alert threshold.
		 *
		 * @since x.x.x
		 *
		 * @param bool                 $send   Whether to send the email. Default true.
		 * @param array<string, mixed> $status Usage status from Usage_Limit_Monitor::get_status().
		 */
		if ( ! apply_filters( 'wpai_usage_limit_send_email', true, $status ) ) {
			return false;
		}

		/**
		 * Filters who receives AI usage limit emails.
		 *
		 * @since x.x.x
		 *
		 * @param string|list<string> $recipients Email addresses. Default the site admin email.
		 * @param array<string, mixed> $status     Usage status from Usage_Limit_Monitor::get_status().
		 */
		$recipients = apply_filters( 'wpai_usage_limit_email_recipients', get_option( 'admin_email' ), $status );

		if ( empty( $recipients ) ) {
			return false;
		}

		return wp_mail( $recipients, $this->get_email_subject( $status ), $this->get_email_message( $status ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- At most two emails per month.
	}

	/**
	 * Shows a warning to administrators while usage is at or above the alert threshold.
	 *
	 * @since x.x.x
	 */
	public function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->get_status();

		if ( self::STATE_OK === $status['state'] ) {
			return;
		}

		$message = sprintf(
			'%1$s <a href="%2$s">%3$s</a>',
			esc_html( $this->get_status_message( $status ) ),
			esc_url( admin_url( 'tools.php?page=ai-request-logs' ) ),
			esc_html__( 'View AI request logs', 'ai' )
		);

		wp_admin_notice(
			$message,
			array(
				'type'               => self::STATE_EXCEEDED === $status['state'] ? 'error' : 'warning',
				'additional_classes' => array( 'wpai-usage-limit-notice' ),
			)
		);
	}

	/**
	 * Describes the usage status in a sentence.
	 *
	 * @since x.x.x
	 *
	 * @param array{limit: int, threshold: int, used: int, percent: int, state: string, resets_at: string} $status Usage status.
	 * @return string Message.
	 */
	public function get_status_message( array $status ): string {
		if ( self::STATE_EXCEEDED === $status['state'] ) {
			return sprintf(
				/* translators: 1: Tokens used. 2: Monthly token limit. */
				__( 'AI requests on this site have used %1$s tokens this month, reaching the monthly limit of %2$s tokens. Requests are not blocked.', 'ai' ),
				number_format_i18n( $status['used'] ),
				number_format_i18n( $status['limit'] )
			);
		}

		return sprintf(
			/* translators: 1: Percentage of the limit used. 2: Tokens used. 3: Monthly token limit. */
			__( 'AI requests on this site have used %1$s%% of the monthly token limit (%2$s of %3$s tokens).', 'ai' ),
			number_format_i18n( $status['percent'] ),
			number_format_i18n( $status['used'] ),
			number_format_i18n( $status['limit'] )
		);
	}

	/**
	 * Builds the alert email subject.
	 *
	 * @param array{limit: int, threshold: int, used: int, percent: int, state: string, resets_at: string} $status Usage status.
	 * @return string Subject.
	 */
	private function get_email_subject( array $status ): string {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( self::STATE_EXCEEDED === $status['state'] ) {
			/* translators: %s: Site title. */
			return sprintf( __( '[%s] Monthly AI token limit reached', 'ai' ), $site );
		}

		/* translators: 1: Site title. 2: Percentage of the limit used. */
		return sprintf( __( '[%1$s] AI token usage is at %2$s%% of the monthly limit', 'ai' ), $site, number_format_i18n( $status['percent'] ) );
	}

	/**
	 * Builds the alert email body.
	 *
	 * @param array{limit: int, threshold: int, used: int, percent: int, state: string, resets_at: string} $status Usage status.
	 * @return string Message.
	 */
	private function get_email_message( array $status ): string {
		return implode(
			"\n\n",
			array(
				$this->get_status_message( $status ),
				sprintf(
					/* translators: %s: Date the usage resets. */
					__( 'Usage resets on %s.', 'ai' ),
					wp_date( (string) get_option( 'date_format' ), $this->get_next_month_start()->getTimestamp() )
				),
				sprintf(
					/* translators: %s: URL of the AI request logs screen. */
					__( 'See which plugins and features are using AI: %s', 'ai' ),
					admin_url( 'tools.php?page=ai-request-logs' )
				),
				sprintf(
					/* translators: %s: URL of the AI settings screen. */
					__( 'Change the limit in the AI Request Logging settings: %s', 'ai' ),
					admin_url( 'options-general.php?page=ai-wp-admin' )
				),
			)
		);
	}

	/**
	 * Gets the current month as `Y-m` in the site's time zone.
	 *
	 * @return string Month key.
	 */
	private function get_month_key(): string {
		return $this->get_month_start()->format( 'Y-m' );
	}

	/**
	 * Gets the start of the current month in the site's time zone.
	 *
	 * @return \DateTimeImmutable
	 */
	private function get_month_start(): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of this month' )->setTime( 0, 0 );
	}

	/**
	 * Gets the start of next month in the site's time zone.
	 *
	 * @return \DateTimeImmutable
	 */
	private function get_next_month_start(): DateTimeImmutable {
		return $this->get_month_start()->modify( '+1 month' );
	}

	/**
	 * Gets the start of the current month as a GMT MySQL datetime.
	 *
	 * @return string
	 */
	private function get_month_start_gmt(): string {
		return $this->get_month_start()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
