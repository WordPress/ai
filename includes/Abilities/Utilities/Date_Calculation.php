<?php
/**
 * Date calculation WordPress Abilities.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Utilities;

use DateTime;
use DateTimeZone;
use Exception;
use WP_Error;

/**
 * Date calculation utility WordPress Abilities.
 *
 * @since x.x.x
 */
class Date_Calculation {

	/**
	 * The default number of occurrences to calculate.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const OCCURRENCES_DEFAULT = 1;

	/**
	 * The maximum number of occurrences allowed.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const OCCURRENCES_MAX = 52;

	/**
	 * Register any needed hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		$this->register_calculate_dates_ability();
	}

	/**
	 * Registers the calculate-dates ability.
	 *
	 * @since x.x.x
	 */
	private function register_calculate_dates_ability(): void {
		wp_register_ability(
			'ai/calculate-dates',
			array(
				'label'               => esc_html__( 'Calculate dates', 'ai' ),
				'description'         => esc_html__( 'Calculate dates from natural language patterns like "3rd Tuesday", "every Monday", or "next Friday".', 'ai' ),
				'category'            => AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'pattern'     => array(
							'type'        => 'string',
							'description' => esc_html__( 'Natural language date pattern (e.g., "3rd Tuesday", "every Monday", "next Friday", "in 3 days").', 'ai' ),
						),
						'start_date'  => array(
							'type'        => 'string',
							'description' => esc_html__( 'Starting date for calculations (ISO 8601 format or "now"). Defaults to current date/time.', 'ai' ),
							'default'     => 'now',
						),
						'occurrences' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Number of dates to calculate for recurring patterns (1-52).', 'ai' ),
							'minimum'     => 1,
							'maximum'     => self::OCCURRENCES_MAX,
							'default'     => self::OCCURRENCES_DEFAULT,
						),
						'timezone'    => array(
							'type'        => 'string',
							'description' => esc_html__( 'Timezone for calculations (e.g., "America/New_York"). Defaults to WordPress site timezone.', 'ai' ),
						),
					),
					'required'   => array( 'pattern' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'dates'   => array(
							'type'        => 'array',
							'description' => esc_html__( 'Calculated dates in ISO 8601 format.', 'ai' ),
							'items'       => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
						'pattern' => array(
							'type'        => 'string',
							'description' => esc_html__( 'The original pattern that was interpreted.', 'ai' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_calculate_dates' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Execute the calculate-dates ability.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The input arguments.
	 * @return array<string, mixed>|\WP_Error The calculated dates or error.
	 */
	public function execute_calculate_dates( array $input ) {
		// Validate pattern is provided.
		if ( empty( $input['pattern'] ) ) {
			return new WP_Error(
				'pattern_required',
				esc_html__( 'A date pattern is required.', 'ai' )
			);
		}

		// Sanitize and set defaults.
		$pattern     = sanitize_text_field( $input['pattern'] );
		$start_date  = isset( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : 'now';
		$occurrences = isset( $input['occurrences'] ) ? absint( $input['occurrences'] ) : self::OCCURRENCES_DEFAULT;
		$timezone    = isset( $input['timezone'] ) ? sanitize_text_field( $input['timezone'] ) : wp_timezone_string();

		// Validate and limit occurrences.
		$occurrences = min( max( $occurrences, 1 ), self::OCCURRENCES_MAX );

		try {
			// Calculate the dates.
			$dates = $this->calculate_dates( $pattern, $start_date, $occurrences, $timezone );

			return array(
				'dates'   => $dates,
				'pattern' => $pattern,
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'date_calculation_failed',
				sprintf(
				/* translators: %s: Error message. */
					esc_html__( 'Failed to calculate dates: %s', 'ai' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Permission callback for date calculation.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args The input arguments.
	 * @return bool|\WP_Error True if permitted, WP_Error otherwise.
	 */
	public function permission_callback( array $args ) {
		// Anyone who can edit posts can use date calculations.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to calculate dates.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Calculate dates from natural language pattern.
	 *
	 * @since x.x.x
	 *
	 * @param string $pattern The natural language date pattern.
	 * @param string $start_date The starting date.
	 * @param int    $occurrences Number of dates to calculate.
	 * @param string $timezone The timezone to use.
	 * @return array<string> Array of calculated dates in ISO 8601 format.
	 * @throws Exception If date calculation fails.
	 */
	private function calculate_dates( string $pattern, string $start_date, int $occurrences, string $timezone ): array {
		$pattern = strtolower( trim( $pattern ) );

		// Validate timezone.
		try {
			$tz = new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			throw new Exception(
				sprintf(
				/* translators: %s: Timezone value. */
					esc_html__( 'Invalid timezone: %s', 'ai' ),
					$timezone
				)
			);
		}

		// Parse starting date.
		try {
			$current_date = new DateTime( $start_date, $tz );
		} catch ( Exception $e ) {
			throw new Exception(
				sprintf(
				/* translators: %s: Start date value. */
					esc_html__( 'Invalid start date: %s', 'ai' ),
					$start_date
				)
			);
		}

		$dates = array();

		// Relative patterns: "tomorrow", "next Monday", "in 3 days".
		if ( $this->is_relative_pattern( $pattern ) ) {
			$dates = $this->calculate_relative_dates( $pattern, $current_date, $occurrences );
		} elseif ( $this->is_nth_weekday_pattern( $pattern ) ) {
			// Nth weekday patterns: "3rd Tuesday", "first Friday", "last Monday".
			$dates = $this->calculate_nth_weekday_dates( $pattern, $current_date, $occurrences );
		} elseif ( $this->is_recurring_pattern( $pattern ) ) {
			// Recurring patterns: "every Monday", "every other Tuesday".
			$dates = $this->calculate_recurring_dates( $pattern, $current_date, $occurrences );
		} elseif ( $this->is_interval_pattern( $pattern ) ) {
			// Interval patterns: "every 2 weeks", "every month".
			$dates = $this->calculate_interval_dates( $pattern, $current_date, $occurrences );
		} else {
			throw new Exception(
				sprintf(
				/* translators: %s: Date pattern. */
					esc_html__( 'Unable to parse pattern: %s', 'ai' ),
					$pattern
				)
			);
		}

		return $dates;
	}

	/**
	 * Check if pattern is relative.
	 *
	 * @since x.x.x
	 *
	 * @param string $pattern The pattern to check.
	 * @return bool True if pattern is relative.
	 */
	private function is_relative_pattern( string $pattern ): bool {
		return (bool) preg_match( '/^(tomorrow|yesterday|today|next\s+\w+day|in\s+\d+\s+(day|week|month|year)s?)$/i', $pattern );
	}

	/**
	 * Check if pattern is nth weekday.
	 *
	 * @since x.x.x
	 *
	 * @param string $pattern The pattern to check.
	 * @return bool True if pattern is nth weekday.
	 */
	private function is_nth_weekday_pattern( string $pattern ): bool {
		return (bool) preg_match( '/^(\d+|first|second|third|fourth|fifth|last)\s+(\w+day)$/i', $pattern );
	}

	/**
	 * Check if pattern is recurring.
	 *
	 * @since x.x.x
	 *
	 * @param string $pattern The pattern to check.
	 * @return bool True if pattern is recurring.
	 */
	private function is_recurring_pattern( string $pattern ): bool {
		return (bool) preg_match( '/^every\s+(other\s+)?(\w+day)$/i', $pattern );
	}

	/**
	 * Check if pattern is interval.
	 *
	 * @since x.x.x
	 *
	 * @param string $pattern The pattern to check.
	 * @return bool True if pattern is interval.
	 */
	private function is_interval_pattern( string $pattern ): bool {
		return (bool) preg_match( '/^every\s+(\d+)\s+(day|week|month|year)s?$/i', $pattern );
	}

	/**
	 * Calculate relative dates.
	 *
	 * @since x.x.x
	 *
	 * @param string   $pattern The pattern.
	 * @param DateTime $start_date The starting date.
	 * @param int      $occurrences Number of occurrences.
	 * @return array<string> Array of dates.
	 * @throws Exception If calculation fails.
	 */
	private function calculate_relative_dates( string $pattern, DateTime $start_date, int $occurrences ): array {
		$dates = array();
		$date  = clone $start_date;

		// Simple keywords.
		if ( in_array( $pattern, array( 'today', 'tomorrow', 'yesterday' ), true ) ) {
			$date->modify( $pattern );
			$dates[] = $date->format( 'c' );
			return $dates;
		}

		// "next Monday" patterns.
		if ( preg_match( '/^next\s+(\w+day)$/i', $pattern, $matches ) ) {
			$weekday = $matches[1];
			$date->modify( 'next ' . $weekday );
			$dates[] = $date->format( 'c' );
			return $dates;
		}

		// "in X days/weeks/months" patterns.
		if ( preg_match( '/^in\s+(\d+)\s+(day|week|month|year)s?$/i', $pattern, $matches ) ) {
			$amount = (int) $matches[1];
			$unit   = $matches[2];
			$date->modify( "+{$amount} {$unit}" );
			$dates[] = $date->format( 'c' );
			return $dates;
		}

		return $dates;
	}

	/**
	 * Calculate nth weekday dates.
	 *
	 * @since x.x.x
	 *
	 * @param string   $pattern The pattern.
	 * @param DateTime $start_date The starting date.
	 * @param int      $occurrences Number of occurrences.
	 * @return array<string> Array of dates.
	 * @throws Exception If calculation fails.
	 */
	private function calculate_nth_weekday_dates( string $pattern, DateTime $start_date, int $occurrences ): array {
		$dates = array();

		if ( ! preg_match( '/^(\d+|first|second|third|fourth|fifth|last)\s+(\w+day)$/i', $pattern, $matches ) ) {
			return $dates;
		}

		$ordinal = strtolower( $matches[1] );
		$weekday = ucfirst( strtolower( $matches[2] ) );
		$nth     = $this->ordinal_to_number( $ordinal );

		$date = clone $start_date;

		for ( $i = 0; $i < $occurrences; $i++ ) {
			$temp = clone $date;
			$temp->modify( 'first day of this month' );

			if ( -1 === $nth ) {
				// "last Monday".
				$temp->modify( 'last ' . $weekday . ' of this month' );
			} else {
				// Find first occurrence.
				$temp->modify( 'first ' . $weekday . ' of this month' );

				// Add weeks for nth occurrence.
				if ( $nth > 1 ) {
					$temp->modify( '+' . ( $nth - 1 ) . ' weeks' );
				}
			}

			// Ensure we didn't overflow into next month.
			if ( (int) $temp->format( 'n' ) === (int) $date->format( 'n' ) ) {
				$dates[] = $temp->format( 'c' );
			}

			// Move to next month.
			$date->modify( 'first day of next month' );
		}

		return $dates;
	}

	/**
	 * Calculate recurring dates.
	 *
	 * @since x.x.x
	 *
	 * @param string   $pattern The pattern.
	 * @param DateTime $start_date The starting date.
	 * @param int      $occurrences Number of occurrences.
	 * @return array<string> Array of dates.
	 * @throws Exception If calculation fails.
	 */
	private function calculate_recurring_dates( string $pattern, DateTime $start_date, int $occurrences ): array {
		$dates = array();

		if ( ! preg_match( '/^every\s+(other\s+)?(\w+day)$/i', $pattern, $matches ) ) {
			return $dates;
		}

		$is_every_other = ! empty( $matches[1] );
		$weekday        = ucfirst( strtolower( $matches[2] ) );
		$interval       = $is_every_other ? 2 : 1;

		$date = clone $start_date;

		// Move to next occurrence if not already on it.
		if ( $date->format( 'l' ) !== $weekday ) {
			$date->modify( 'next ' . $weekday );
		}

		for ( $i = 0; $i < $occurrences; $i++ ) {
			$dates[] = $date->format( 'c' );
			$date->modify( '+' . $interval . ' weeks' );
		}

		return $dates;
	}

	/**
	 * Calculate interval dates.
	 *
	 * @since x.x.x
	 *
	 * @param string   $pattern The pattern.
	 * @param DateTime $start_date The starting date.
	 * @param int      $occurrences Number of occurrences.
	 * @return array<string> Array of dates.
	 * @throws Exception If calculation fails.
	 */
	private function calculate_interval_dates( string $pattern, DateTime $start_date, int $occurrences ): array {
		$dates = array();

		if ( ! preg_match( '/^every\s+(\d+)\s+(day|week|month|year)s?$/i', $pattern, $matches ) ) {
			return $dates;
		}

		$amount = (int) $matches[1];
		$unit   = strtolower( $matches[2] );

		$date = clone $start_date;

		for ( $i = 0; $i < $occurrences; $i++ ) {
			$dates[] = $date->format( 'c' );
			$date->modify( "+{$amount} {$unit}" );
		}

		return $dates;
	}

	/**
	 * Convert ordinal word to number.
	 *
	 * @since x.x.x
	 *
	 * @param string $ordinal The ordinal word.
	 * @return int The numeric value.
	 */
	private function ordinal_to_number( string $ordinal ): int {
		$map = array(
			'first'  => 1,
			'second' => 2,
			'third'  => 3,
			'fourth' => 4,
			'fifth'  => 5,
			'last'   => -1,
		);

		$ordinal = strtolower( $ordinal );

		return $map[ $ordinal ] ?? (int) $ordinal;
	}
}
