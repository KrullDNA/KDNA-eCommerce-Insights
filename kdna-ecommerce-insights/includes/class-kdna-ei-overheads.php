<?php
/**
 * Overheads: recurring and one-off business costs such as rent, software
 * and wages.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saves overheads and works out how much of them belongs to any date range.
 *
 * Overheads are spread evenly across days. A monthly cost is shared across
 * the days of each calendar month, so $3,000 of rent in a 30 day month adds
 * $100 a day, and a 7 day report carries $700 of it. That way any date range
 * shows a fair net profit, not a big loss on the 1st of every month.
 *
 * The spreading maths is kept free of WordPress (see spread()) so it can be
 * tested on its own.
 */
class KDNA_EcommerceInsights_Overheads {

	/**
	 * How often an overhead repeats.
	 */
	const FREQUENCIES = array( 'one_off', 'weekly', 'monthly', 'quarterly', 'yearly' );

	/*
	 * ---------------------------------------------------------------------
	 * Choice lists
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Built-in overhead categories. People can also type their own.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return array(
			'software'  => __( 'Software', 'kdna-ecommerce-insights' ),
			'rent'      => __( 'Rent', 'kdna-ecommerce-insights' ),
			'wages'     => __( 'Wages', 'kdna-ecommerce-insights' ),
			'agency'    => __( 'Agency', 'kdna-ecommerce-insights' ),
			'packaging' => __( 'Packaging', 'kdna-ecommerce-insights' ),
			'insurance' => __( 'Insurance', 'kdna-ecommerce-insights' ),
			'other'     => __( 'Other', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Display names for each frequency.
	 *
	 * @return array<string, string>
	 */
	public static function frequency_labels(): array {
		return array(
			'one_off'   => __( 'One-off', 'kdna-ecommerce-insights' ),
			'weekly'    => __( 'Weekly', 'kdna-ecommerce-insights' ),
			'monthly'   => __( 'Monthly', 'kdna-ecommerce-insights' ),
			'quarterly' => __( 'Quarterly', 'kdna-ecommerce-insights' ),
			'yearly'    => __( 'Yearly', 'kdna-ecommerce-insights' ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Saving and reading
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns every overhead, newest start date first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'overheads' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY start_date DESC, id DESC", ARRAY_A );

		return array_map( array( __CLASS__, 'format_row' ), (array) $rows );
	}

	/**
	 * Returns one overhead, or null if it does not exist.
	 *
	 * @param int $id Overhead ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'overheads' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::format_row( $row ) : null;
	}

	/**
	 * Checks an overhead before saving and explains any problem in plain English.
	 *
	 * @param array $data Submitted overhead.
	 * @return array<string, string> Problems keyed by field name. Empty when all is well.
	 */
	public static function validate( array $data ): array {
		$errors = array();

		if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
			$errors['name'] = __( 'Give this overhead a name, for example "Shopify apps" or "Studio rent".', 'kdna-ecommerce-insights' );
		}

		$amount = $data['amount'] ?? '';
		if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount < 0 ) {
			$errors['amount'] = __( 'Enter an amount of zero or more.', 'kdna-ecommerce-insights' );
		}

		if ( ! in_array( $data['frequency'] ?? '', self::FREQUENCIES, true ) ) {
			$errors['frequency'] = __( 'Choose how often this cost repeats.', 'kdna-ecommerce-insights' );
		}

		$start = self::date_or_null( $data['start_date'] ?? '' );
		if ( ! $start ) {
			$errors['start_date'] = 'one_off' === ( $data['frequency'] ?? '' )
				? __( 'Choose the date this cost was paid.', 'kdna-ecommerce-insights' )
				: __( 'Choose the date this cost starts.', 'kdna-ecommerce-insights' );
		}

		$end = self::date_or_null( $data['end_date'] ?? '' );
		if ( '' !== (string) ( $data['end_date'] ?? '' ) && ! $end ) {
			$errors['end_date'] = __( 'That end date is not a valid date.', 'kdna-ecommerce-insights' );
		} elseif ( $start && $end && $end < $start ) {
			$errors['end_date'] = __( 'The end date must be on or after the start date.', 'kdna-ecommerce-insights' );
		}

		return $errors;
	}

	/**
	 * Adds a new overhead. Call validate() first.
	 *
	 * @param array $data Overhead details.
	 * @return array|null The saved overhead, or null if saving failed.
	 */
	public static function create( array $data ): ?array {
		global $wpdb;

		$row               = self::clean( $data );
		$row['created_at'] = current_time( 'mysql', true );
		$row['updated_at'] = $row['created_at'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( KDNA_EcommerceInsights_Install::table( 'overheads' ), $row );

		if ( ! $ok ) {
			return null;
		}

		do_action( 'kdna_ei_overheads_changed' );
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Updates an overhead. Call validate() first.
	 *
	 * @param int   $id   Overhead ID.
	 * @param array $data New details.
	 * @return array|null The saved overhead, or null if it does not exist.
	 */
	public static function update( int $id, array $data ): ?array {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return null;
		}

		$row               = self::clean( $data );
		$row['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( KDNA_EcommerceInsights_Install::table( 'overheads' ), $row, array( 'id' => $id ) );

		do_action( 'kdna_ei_overheads_changed' );
		return self::get( $id );
	}

	/**
	 * Deletes an overhead.
	 *
	 * @param int $id Overhead ID.
	 * @return bool True if it was deleted.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (bool) $wpdb->delete( KDNA_EcommerceInsights_Install::table( 'overheads' ), array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			do_action( 'kdna_ei_overheads_changed' );
		}
		return $deleted;
	}

	/**
	 * Cleans submitted details into a database row.
	 *
	 * @param array $data Submitted overhead.
	 * @return array
	 */
	private static function clean( array $data ): array {
		$frequency = in_array( $data['frequency'] ?? '', self::FREQUENCIES, true ) ? $data['frequency'] : 'monthly';
		$category  = trim( sanitize_text_field( (string) ( $data['category'] ?? 'other' ) ) );

		return array(
			'name'         => mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 255 ),
			'category'     => mb_substr( '' !== $category ? $category : 'other', 0, 50 ),
			'amount'       => wc_format_decimal( max( 0, (float) ( $data['amount'] ?? 0 ) ), 4 ),
			'frequency'    => $frequency,
			'start_date'   => self::date_or_null( $data['start_date'] ?? '' ),
			// A one-off cost has no end date.
			'end_date'     => 'one_off' === $frequency ? null : self::date_or_null( $data['end_date'] ?? '' ),
			'includes_gst' => empty( $data['includes_gst'] ) ? 0 : 1,
		);
	}

	/**
	 * Turns a database row into the shape used by the dashboard, including
	 * the cost per month for easy comparison.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function format_row( array $row ): array {
		$amount = (float) $row['amount'];

		return array(
			'id'           => (int) $row['id'],
			'name'         => (string) $row['name'],
			'category'     => (string) $row['category'],
			'amount'       => $amount,
			'frequency'    => (string) $row['frequency'],
			'start_date'   => (string) $row['start_date'],
			'end_date'     => $row['end_date'] ? (string) $row['end_date'] : null,
			'includes_gst' => (bool) $row['includes_gst'],
			'monthly'      => self::monthly_equivalent( $amount, (string) $row['frequency'] ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Spreading overheads across days
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns the overhead total for a date range using the saved overheads,
	 * with the GST share taken out where the cost includes GST and the store
	 * reports GST or VAT.
	 *
	 * @param string $start First day, Y-m-d.
	 * @param string $end   Last day, Y-m-d (included).
	 * @return array{total: float, tax: float, net: float, by_category: array<string, float>}
	 */
	public static function total_for_range( string $start, string $end ): array {
		$system   = (string) KDNA_EcommerceInsights_Settings::get( 'tax.system', 'none' );
		$tax_rate = in_array( $system, array( 'au_gst', 'vat' ), true ) ? (float) KDNA_EcommerceInsights_Settings::get( 'tax.rate', 0 ) : 0.0;

		return self::spread( self::all(), $start, $end, $tax_rate );
	}

	/**
	 * Adds up every overhead's share of a date range by spreading each one
	 * evenly by day. Works on plain arrays, with no database or WordPress
	 * needed, so it can be tested on its own.
	 *
	 * @param array[] $overheads Each: amount, frequency, start_date, end_date, includes_gst, category.
	 * @param string  $start     First day of the range, Y-m-d.
	 * @param string  $end       Last day of the range, Y-m-d (included).
	 * @param float   $tax_rate  GST or VAT rate to take out of costs that include it, for example 10.
	 * @return array{total: float, tax: float, net: float, by_category: array<string, float>}
	 */
	public static function spread( array $overheads, string $start, string $end, float $tax_rate = 0.0 ): array {
		$result = array(
			'total'       => 0.0,
			'tax'         => 0.0,
			'net'         => 0.0,
			'by_category' => array(),
		);

		$range_start = self::to_date( $start );
		$range_end   = self::to_date( $end );
		if ( ! $range_start || ! $range_end || $range_end < $range_start ) {
			return $result;
		}

		foreach ( $overheads as $overhead ) {
			$amount = self::amount_in_range( $overhead, $range_start, $range_end );
			if ( $amount <= 0 ) {
				continue;
			}

			$tax = ! empty( $overhead['includes_gst'] ) && $tax_rate > 0 ? $amount * $tax_rate / ( 100 + $tax_rate ) : 0.0;
			$net = $amount - $tax;

			$category = (string) ( $overhead['category'] ?? 'other' );

			$result['total']                  += $amount;
			$result['tax']                    += $tax;
			$result['net']                    += $net;
			$result['by_category'][ $category ] = ( $result['by_category'][ $category ] ?? 0 ) + $net;
		}

		$result['total'] = round( $result['total'], 4 );
		$result['tax']   = round( $result['tax'], 4 );
		$result['net']   = round( $result['net'], 4 );
		foreach ( $result['by_category'] as $category => $value ) {
			$result['by_category'][ $category ] = round( $value, 4 );
		}

		return $result;
	}

	/**
	 * Works out how much of one overhead falls inside a date range.
	 *
	 * @param array             $overhead    Overhead: amount, frequency, start_date, end_date.
	 * @param DateTimeImmutable $range_start First day of the range.
	 * @param DateTimeImmutable $range_end   Last day of the range.
	 * @return float
	 */
	public static function amount_in_range( array $overhead, DateTimeImmutable $range_start, DateTimeImmutable $range_end ): float {
		$amount    = max( 0, (float) ( $overhead['amount'] ?? 0 ) );
		$frequency = (string) ( $overhead['frequency'] ?? 'monthly' );
		$starts    = self::to_date( (string) ( $overhead['start_date'] ?? '' ) );
		$ends      = ! empty( $overhead['end_date'] ) ? self::to_date( (string) $overhead['end_date'] ) : null;

		if ( ! $starts || $amount <= 0 ) {
			return 0.0;
		}

		// A one-off cost lands in full on its date.
		if ( 'one_off' === $frequency ) {
			return ( $starts >= $range_start && $starts <= $range_end ) ? $amount : 0.0;
		}

		// Only count days when both the range and the overhead are active.
		$from = max( $range_start, $starts );
		$to   = $ends ? min( $range_end, $ends ) : $range_end;
		if ( $to < $from ) {
			return 0.0;
		}

		$total = 0.0;
		$day   = $from;
		while ( $day <= $to ) {
			// Move a whole period at a time: to the end of the month, quarter
			// or year containing this day, or the end of the overlap.
			$period_end = self::period_end( $day, $frequency );
			$chunk_end  = min( $period_end, $to );
			$days       = (int) $day->diff( $chunk_end )->days + 1;

			$total += $days * self::daily_rate( $amount, $frequency, $day );
			$day    = $chunk_end->modify( '+1 day' );
		}

		return $total;
	}

	/**
	 * The cost per day of a recurring overhead on a given day.
	 *
	 * Weekly costs are divided by 7. Monthly, quarterly and yearly costs are
	 * divided by the number of days in that calendar month, quarter or year,
	 * so each full period adds up to exactly the amount entered.
	 *
	 * @param float             $amount    Amount per period.
	 * @param string            $frequency weekly, monthly, quarterly or yearly.
	 * @param DateTimeImmutable $day       Day to price.
	 * @return float
	 */
	public static function daily_rate( float $amount, string $frequency, DateTimeImmutable $day ): float {
		switch ( $frequency ) {
			case 'weekly':
				return $amount / 7;
			case 'monthly':
				return $amount / (int) $day->format( 't' );
			case 'quarterly':
				$start = self::quarter_start( $day );
				return $amount / ( (int) $start->diff( $start->modify( '+3 months' ) )->days );
			case 'yearly':
				return $amount / ( $day->format( 'L' ) ? 366 : 365 );
			default:
				return 0.0;
		}
	}

	/**
	 * The last day of the calendar period containing a day. Weekly costs use
	 * the same rate every day, so they are handled a month at a time.
	 *
	 * @param DateTimeImmutable $day       Any day.
	 * @param string            $frequency Overhead frequency.
	 * @return DateTimeImmutable
	 */
	private static function period_end( DateTimeImmutable $day, string $frequency ): DateTimeImmutable {
		switch ( $frequency ) {
			case 'quarterly':
				return self::quarter_start( $day )->modify( '+3 months -1 day' );
			case 'yearly':
				return $day->setDate( (int) $day->format( 'Y' ), 12, 31 );
			default:
				return $day->modify( 'last day of this month' );
		}
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
	 * What a recurring cost works out to per month, for comparing overheads
	 * side by side. One-off costs return null.
	 *
	 * @param float  $amount    Amount per period.
	 * @param string $frequency Overhead frequency.
	 * @return float|null
	 */
	public static function monthly_equivalent( float $amount, string $frequency ): ?float {
		switch ( $frequency ) {
			case 'weekly':
				return round( $amount * 52 / 12, 2 );
			case 'monthly':
				return round( $amount, 2 );
			case 'quarterly':
				return round( $amount / 3, 2 );
			case 'yearly':
				return round( $amount / 12, 2 );
			default:
				return null;
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Date helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Turns a Y-m-d string into a date at midnight, or null if it is not a real date.
	 *
	 * @param string $value Date text.
	 * @return DateTimeImmutable|null
	 */
	private static function to_date( string $value ): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	/**
	 * Returns a valid Y-m-d date string, or null.
	 *
	 * @param mixed $value Submitted date.
	 * @return string|null
	 */
	private static function date_or_null( $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return self::to_date( $value ) ? $value : null;
	}
}
