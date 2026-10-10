<?php
/**
 * Date ranges and comparison ranges for every report.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a date range choice ("This month", "Last 30 days", a custom range)
 * into exact first and last days, in the site's time zone, and works out
 * the matching range to compare against (section 8.2 of the brief).
 *
 * Every range is a pair of whole days, Y-m-d, both included.
 */
class KDNA_EcommerceInsights_Dates {

	/**
	 * Presets that follow calendar periods, so their comparison steps back a
	 * whole month, quarter or year rather than a number of days.
	 */
	const CALENDAR_PRESETS = array(
		'this_month'   => 'month',
		'last_month'   => 'month',
		'this_quarter' => 'quarter',
		'this_year'    => 'year',
		'last_year'    => 'year',
	);

	/**
	 * Works out a date range from a preset, or from custom start and end dates.
	 *
	 * @param string      $preset A key from KDNA_EcommerceInsights_Settings::range_presets().
	 * @param string|null $start  Custom first day, Y-m-d (only for "custom").
	 * @param string|null $end    Custom last day, Y-m-d (only for "custom").
	 * @param string|null $today  Today's date, Y-m-d, for testing. Defaults to today in the site time zone.
	 * @return array{preset: string, start: string, end: string, days: int}|WP_Error
	 */
	public static function resolve( string $preset, ?string $start = null, ?string $end = null, ?string $today = null ) {
		$tz  = wp_timezone();
		$now = $today ? new DateTimeImmutable( $today, $tz ) : new DateTimeImmutable( 'today', $tz );

		switch ( $preset ) {
			case 'today':
				$from = $now;
				$to   = $now;
				break;
			case 'yesterday':
				$from = $now->modify( '-1 day' );
				$to   = $from;
				break;
			case 'last_7_days':
				$from = $now->modify( '-6 days' );
				$to   = $now;
				break;
			case 'last_30_days':
				$from = $now->modify( '-29 days' );
				$to   = $now;
				break;
			case 'this_month':
				$from = $now->modify( 'first day of this month' );
				$to   = $now;
				break;
			case 'last_month':
				$from = $now->modify( 'first day of last month' );
				$to   = $now->modify( 'last day of last month' );
				break;
			case 'this_quarter':
				$from = self::quarter_start( $now );
				$to   = $now;
				break;
			case 'this_year':
				$from = $now->setDate( (int) $now->format( 'Y' ), 1, 1 );
				$to   = $now;
				break;
			case 'last_year':
				$year = (int) $now->format( 'Y' ) - 1;
				$from = $now->setDate( $year, 1, 1 );
				$to   = $now->setDate( $year, 12, 31 );
				break;
			case 'custom':
				$from = self::parse( (string) $start, $tz );
				$to   = self::parse( (string) $end, $tz );
				if ( ! $from || ! $to ) {
					return new WP_Error( 'kdna_ei_bad_range', __( 'Choose a valid start and end date.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
				}
				if ( $to < $from ) {
					return new WP_Error( 'kdna_ei_bad_range', __( 'The end date must be on or after the start date.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
				}
				if ( (int) $from->diff( $to )->days > 3660 ) {
					return new WP_Error( 'kdna_ei_bad_range', __( 'Please choose a range of ten years or less.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
				}
				break;
			default:
				return new WP_Error( 'kdna_ei_bad_range', __( 'That date range is not recognised.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
		}

		return self::make( $preset, $from, $to );
	}

	/**
	 * Works out the range to compare against.
	 *
	 * - previous_period: the same length of time just before. For calendar
	 *   presets this steps back a whole calendar period and keeps the same
	 *   days, so "This month" on the 9th compares with the 1st to the 9th
	 *   of last month, not the 9 days before.
	 * - previous_year: the same dates one year earlier.
	 * - none: no comparison.
	 *
	 * @param array  $range Range from resolve().
	 * @param string $mode  previous_period, previous_year or none.
	 * @return array|null Range, or null for no comparison.
	 */
	public static function comparison( array $range, string $mode ): ?array {
		$tz   = wp_timezone();
		$from = new DateTimeImmutable( $range['start'], $tz );
		$to   = new DateTimeImmutable( $range['end'], $tz );

		if ( 'previous_year' === $mode ) {
			return self::make( 'compare', self::shift( $from, '-1 year' ), self::shift( $to, '-1 year' ) );
		}

		if ( 'previous_period' !== $mode ) {
			return null;
		}

		$unit = self::CALENDAR_PRESETS[ $range['preset'] ] ?? '';
		if ( $unit ) {
			$step   = 'quarter' === $unit ? '-3 months' : '-1 ' . $unit;
			$p_from = self::shift( $from, $step );
			$p_to   = self::shift( $to, $step );

			// A full last month compares with the full month before it.
			if ( $to->format( 'Y-m-d' ) === $to->modify( 'last day of this month' )->format( 'Y-m-d' ) && 'month' === $unit ) {
				$p_to = $p_from->modify( 'last day of this month' );
			}
			return self::make( 'compare', $p_from, $p_to );
		}

		$days = (int) $range['days'];
		return self::make( 'compare', $from->modify( '-' . $days . ' days' ), $from->modify( '-1 day' ) );
	}

	/**
	 * The best chart grouping for a range: daily up to about three months,
	 * weekly up to two years, then monthly.
	 *
	 * @param array $range Range from resolve().
	 * @return string day, week or month.
	 */
	public static function auto_granularity( array $range ): string {
		if ( $range['days'] <= 92 ) {
			return 'day';
		}
		return $range['days'] <= 731 ? 'week' : 'month';
	}

	/**
	 * Splits a range into chart buckets (days, weeks or months). Weeks start
	 * on the day set in Settings > General. The first and last buckets are
	 * trimmed to the range.
	 *
	 * @param array  $range       Range from resolve().
	 * @param string $granularity day, week or month.
	 * @return array[] Each: start, end (Y-m-d), key.
	 */
	public static function buckets( array $range, string $granularity ): array {
		$tz      = wp_timezone();
		$cursor  = new DateTimeImmutable( $range['start'], $tz );
		$end     = new DateTimeImmutable( $range['end'], $tz );
		$week    = (int) KDNA_EcommerceInsights_Settings::get( 'general.week_start', 1 );
		$buckets = array();

		while ( $cursor <= $end ) {
			switch ( $granularity ) {
				case 'month':
					$bucket_end = $cursor->modify( 'last day of this month' );
					break;
				case 'week':
					$offset     = ( (int) $cursor->format( 'w' ) - $week + 7 ) % 7;
					$bucket_end = $cursor->modify( '+' . ( 6 - $offset ) . ' days' );
					break;
				default:
					$bucket_end = $cursor;
			}
			$bucket_end = min( $bucket_end, $end );

			$buckets[] = array(
				'key'   => $cursor->format( 'Y-m-d' ),
				'start' => $cursor->format( 'Y-m-d' ),
				'end'   => $bucket_end->format( 'Y-m-d' ),
			);
			$cursor    = $bucket_end->modify( '+1 day' );
		}

		return $buckets;
	}

	/**
	 * Builds a range array from two dates.
	 *
	 * @param string            $preset Preset key.
	 * @param DateTimeImmutable $from   First day.
	 * @param DateTimeImmutable $to     Last day.
	 * @return array{preset: string, start: string, end: string, days: int}
	 */
	private static function make( string $preset, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		return array(
			'preset' => $preset,
			'start'  => $from->format( 'Y-m-d' ),
			'end'    => $to->format( 'Y-m-d' ),
			'days'   => (int) $from->diff( $to )->days + 1,
		);
	}

	/**
	 * Moves a date by months or years without spilling into the next month,
	 * so 31 March minus a month is 28 (or 29) February, not 3 March.
	 *
	 * @param DateTimeImmutable $date Date.
	 * @param string            $step For example "-1 month", "-3 months" or "-1 year".
	 * @return DateTimeImmutable
	 */
	private static function shift( DateTimeImmutable $date, string $step ): DateTimeImmutable {
		$first  = $date->modify( 'first day of this month' )->modify( $step );
		$last   = (int) $first->format( 't' );
		$day    = min( (int) $date->format( 'j' ), $last );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day );
	}

	/**
	 * The first day of the calendar quarter containing a day.
	 *
	 * @param DateTimeImmutable $day Any day.
	 * @return DateTimeImmutable
	 */
	private static function quarter_start( DateTimeImmutable $day ): DateTimeImmutable {
		$month = (int) floor( ( (int) $day->format( 'n' ) - 1 ) / 3 ) * 3 + 1;
		return $day->setDate( (int) $day->format( 'Y' ), $month, 1 );
	}

	/**
	 * Reads a Y-m-d date, or returns null if it is not a real date.
	 *
	 * @param string       $value Date text.
	 * @param DateTimeZone $tz    Time zone.
	 * @return DateTimeImmutable|null
	 */
	private static function parse( string $value, DateTimeZone $tz ): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}
}
