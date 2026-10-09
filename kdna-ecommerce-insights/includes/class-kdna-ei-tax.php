<?php
/**
 * Tax summary: GST (with the BAS-style lines), VAT or sales tax.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out the tax summary for the Tax & Reports screen, its CSV export
 * and the printable report, for the tax system chosen in Settings > Tax.
 *
 * For Australian GST it gives the lines a bookkeeper copies onto the BAS:
 *
 * - G1 Total sales: everything customers paid, including GST and shipping,
 *   less refunds.
 * - 1A GST on sales: GST charged, less GST refunded.
 * - 1B GST on purchases: an ESTIMATE, from overheads and ad spend marked as
 *   including GST. Stock purchases, shipping labels and payment fees are not
 *   included, because Insights does not know which of those bills had GST.
 * - Net position: 1A less 1B. Positive means GST to pay, negative means a
 *   refund is due.
 *
 * Figures follow the counted order statuses and date basis in Settings >
 * General. It is always a guide for the bookkeeper, never a lodgement.
 */
class KDNA_EcommerceInsights_Tax {

	/**
	 * The tax systems Insights understands.
	 *
	 * @return array<string, string>
	 */
	public static function systems(): array {
		return array(
			'au_gst'    => __( 'Australian GST (with BAS view)', 'kdna-ecommerce-insights' ),
			'vat'       => __( 'VAT or GST (other countries)', 'kdna-ecommerce-insights' ),
			'sales_tax' => __( 'Sales tax (no tax back on costs)', 'kdna-ecommerce-insights' ),
			'none'      => __( 'No tax reporting', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Which stretch of time the summary can cover, as offered on the screen.
	 *
	 * @return array<string, string>
	 */
	public static function spans(): array {
		return array(
			'range'       => __( 'Chosen date range', 'kdna-ecommerce-insights' ),
			'last_period' => __( 'Last full period', 'kdna-ecommerce-insights' ),
			'this_fy'     => __( 'This financial year', 'kdna-ecommerce-insights' ),
			'last_fy'     => __( 'Last financial year', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * The names of the four lines for a tax system. Australian GST uses the
	 * BAS labels so the bookkeeper can match them up at a glance.
	 *
	 * @param string $system Tax system key.
	 * @return array{sales: string, on_sales: string, on_costs: string, net: string, short: array}
	 */
	public static function labels( string $system ): array {
		switch ( $system ) {
			case 'au_gst':
				return array(
					'sales'    => __( 'G1 Total sales', 'kdna-ecommerce-insights' ),
					'on_sales' => __( '1A GST on sales', 'kdna-ecommerce-insights' ),
					'on_costs' => __( '1B GST on purchases (estimate)', 'kdna-ecommerce-insights' ),
					'net'      => __( 'Net GST position', 'kdna-ecommerce-insights' ),
					'short'    => array( 'G1', '1A', '1B', '' ),
					'tax'      => __( 'GST', 'kdna-ecommerce-insights' ),
				);
			case 'sales_tax':
				return array(
					'sales'    => __( 'Total sales including tax', 'kdna-ecommerce-insights' ),
					'on_sales' => __( 'Sales tax collected', 'kdna-ecommerce-insights' ),
					'on_costs' => __( 'Tax on costs', 'kdna-ecommerce-insights' ),
					'net'      => __( 'Sales tax to pay', 'kdna-ecommerce-insights' ),
					'short'    => array( '', '', '', '' ),
					'tax'      => __( 'Sales tax', 'kdna-ecommerce-insights' ),
				);
			default:
				return array(
					'sales'    => __( 'Total sales including tax', 'kdna-ecommerce-insights' ),
					'on_sales' => __( 'Tax on sales', 'kdna-ecommerce-insights' ),
					'on_costs' => __( 'Tax on costs (estimate)', 'kdna-ecommerce-insights' ),
					'net'      => __( 'Net tax position', 'kdna-ecommerce-insights' ),
					'short'    => array( '', '', '', '' ),
					'tax'      => __( 'Tax', 'kdna-ecommerce-insights' ),
				);
		}
	}

	/**
	 * The month a financial year starts in: July for Australia, January
	 * everywhere else.
	 *
	 * @param string $system Tax system key.
	 * @return int 1 to 12.
	 */
	public static function year_start_month( string $system ): int {
		$month = 'au_gst' === $system ? 7 : 1;

		/**
		 * Filters the month the financial year starts in (1 to 12).
		 *
		 * @param int    $month  Month number.
		 * @param string $system Tax system key.
		 */
		$month = (int) apply_filters( 'kdna_ei_financial_year_start_month', $month, $system );
		return $month >= 1 && $month <= 12 ? $month : 1;
	}

	/**
	 * Turns a span choice into dates. "range" keeps the screen's date range;
	 * "last_period" is the last full month or quarter; the financial year
	 * choices run from the start of the year to its end (or to today, while
	 * the year is still running).
	 *
	 * @param string      $span   A key from spans().
	 * @param array       $range  The screen's date range.
	 * @param string      $period monthly or quarterly.
	 * @param string      $system Tax system key.
	 * @param string|null $today  Today, Y-m-d, for testing.
	 * @return array Range with preset, start, end and days.
	 */
	public static function span_range( string $span, array $range, string $period, string $system, ?string $today = null ): array {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( $today ? $today : 'today', $tz );

		switch ( $span ) {
			case 'last_period':
				$this_start = self::period_start( $now, $period );
				$from       = $this_start->modify( 'quarterly' === $period ? '-3 months' : '-1 month' );
				$to         = $this_start->modify( '-1 day' );
				break;

			case 'this_fy':
			case 'last_fy':
				$month = self::year_start_month( $system );
				$year  = (int) $now->format( 'Y' ) - ( (int) $now->format( 'n' ) < $month ? 1 : 0 );
				if ( 'last_fy' === $span ) {
					--$year;
				}
				$from = $now->setDate( $year, $month, 1 );
				$to   = $from->modify( '+1 year -1 day' );
				if ( $to > $now ) {
					$to = $now;
				}
				break;

			default:
				return $range;
		}

		return array(
			'preset' => 'custom',
			'start'  => $from->format( 'Y-m-d' ),
			'end'    => $to->format( 'Y-m-d' ),
			'days'   => (int) $from->diff( $to )->days + 1,
		);
	}

	/**
	 * First day of the month or calendar quarter a date falls in. Calendar
	 * quarters line up with Australian BAS quarters (July to September and
	 * so on).
	 *
	 * @param DateTimeImmutable $day    Any day.
	 * @param string            $period monthly or quarterly.
	 * @return DateTimeImmutable
	 */
	private static function period_start( DateTimeImmutable $day, string $period ): DateTimeImmutable {
		$month = (int) $day->format( 'n' );
		if ( 'quarterly' === $period ) {
			$month = (int) floor( ( $month - 1 ) / 3 ) * 3 + 1;
		}
		return $day->setDate( (int) $day->format( 'Y' ), $month, 1 );
	}

	/**
	 * Splits a range into months or calendar quarters, trimmed to the range.
	 *
	 * @param array  $range  Range.
	 * @param string $period monthly or quarterly.
	 * @return array[] Each: start, end, and whether the period is complete.
	 */
	public static function periods( array $range, string $period ): array {
		$tz      = wp_timezone();
		$cursor  = new DateTimeImmutable( $range['start'], $tz );
		$end     = new DateTimeImmutable( $range['end'], $tz );
		$today   = new DateTimeImmutable( 'today', $tz );
		$periods = array();

		while ( $cursor <= $end ) {
			$start      = self::period_start( $cursor, $period );
			$full_end   = $start->modify( 'quarterly' === $period ? '+3 months -1 day' : '+1 month -1 day' );
			$period_end = min( $full_end, $end );

			$periods[] = array(
				'start'       => $cursor->format( 'Y-m-d' ),
				'end'         => $period_end->format( 'Y-m-d' ),
				'period'      => self::period_name( $start, $period ),
				'partial'     => $cursor > $start || $period_end < $full_end,
				'in_progress' => $full_end >= $today && $start <= $today,
			);
			$cursor    = $period_end->modify( '+1 day' );
		}

		return $periods;
	}

	/**
	 * A short name for a period, such as "Jul to Sep 2026" or "October 2026".
	 *
	 * @param DateTimeImmutable $start  First day of the month or quarter.
	 * @param string            $period monthly or quarterly.
	 * @return string
	 */
	private static function period_name( DateTimeImmutable $start, string $period ): string {
		if ( 'quarterly' !== $period ) {
			return wp_date( 'F Y', $start->getTimestamp(), $start->getTimezone() );
		}
		$last = $start->modify( '+2 months' );
		return sprintf(
			/* translators: 1: first month, 2: last month, 3: year. For example "Jul to Sep 2026". */
			__( '%1$s to %2$s %3$s', 'kdna-ecommerce-insights' ),
			wp_date( 'M', $start->getTimestamp(), $start->getTimezone() ),
			wp_date( 'M', $last->getTimestamp(), $last->getTimezone() ),
			$last->format( 'Y' )
		);
	}

	/**
	 * The four lines (and where the tax on costs came from) for a set of
	 * report totals.
	 *
	 * @param array  $totals Totals from KDNA_EcommerceInsights_Report::totals().
	 * @param string $system Tax system key.
	 * @return array{sales: float, on_sales: float, on_costs: float, net: float, costs: array}
	 */
	public static function lines( array $totals, string $system ): array {
		$claims    = in_array( $system, array( 'au_gst', 'vat' ), true );
		$overheads = $claims ? (float) ( $totals['overheads_tax'] ?? 0 ) : 0.0;
		$ads       = $claims ? (float) ( $totals['ad_spend_tax'] ?? 0 ) : 0.0;
		$on_sales  = (float) ( $totals['tax'] ?? 0 );

		return array(
			'sales'    => round( (float) ( $totals['net_revenue'] ?? 0 ) + $on_sales, 2 ),
			'on_sales' => round( $on_sales, 2 ),
			'on_costs' => round( $overheads + $ads, 2 ),
			'net'      => round( $on_sales - $overheads - $ads, 2 ),
			'costs'    => array(
				'overheads' => round( $overheads, 2 ),
				'ad_spend'  => round( $ads, 2 ),
			),
		);
	}

	/**
	 * The full tax summary for a range.
	 *
	 * @param array  $range  Range from KDNA_EcommerceInsights_Dates.
	 * @param string $period monthly or quarterly. Empty uses Settings > Tax.
	 * @return array
	 */
	public static function summary( array $range, string $period = '' ): array {
		$system = (string) KDNA_EcommerceInsights_Settings::get( 'tax.system', 'none' );
		if ( ! in_array( $period, array( 'monthly', 'quarterly' ), true ) ) {
			$period = (string) KDNA_EcommerceInsights_Settings::get( 'tax.reporting_period', 'quarterly' );
		}

		$rows = array();
		foreach ( self::periods( $range, $period ) as $bucket ) {
			$rows[] = array_merge( $bucket, self::lines( KDNA_EcommerceInsights_Report::totals( $bucket ), $system ) );
		}

		$totals = self::lines( KDNA_EcommerceInsights_Report::totals( $range ), $system );
		$labels = self::labels( $system );

		return array(
			'system'       => $system,
			'system_label' => self::systems()[ $system ] ?? '',
			'rate'         => (float) KDNA_EcommerceInsights_Settings::get( 'tax.rate', 0 ),
			'period'       => $period,
			'range'        => array(
				'start' => $range['start'],
				'end'   => $range['end'],
			),
			'labels'       => $labels,
			'periods'      => $rows,
			'totals'       => $totals,
			'position'     => $totals['net'] > 0.004 ? 'payable' : ( $totals['net'] < -0.004 ? 'refundable' : 'nil' ),
			'claims_costs' => in_array( $system, array( 'au_gst', 'vat' ), true ),
			'date_basis'   => (string) KDNA_EcommerceInsights_Settings::get( 'general.date_basis', 'paid' ),
			'note'         => self::note( $system ),
		);
	}

	/**
	 * The "guide, not a lodgement" note shown with every tax summary.
	 *
	 * @param string $system Tax system key.
	 * @return string
	 */
	public static function note( string $system ): string {
		if ( 'au_gst' === $system ) {
			return __( 'This is a guide for your bookkeeper, not a lodgement. G1 and 1A come from your orders. 1B is an estimate from overheads and ad spend marked as including GST only; GST on stock purchases, shipping labels, payment fees and other bills is not included, so your bookkeeper will add those from your accounts.', 'kdna-ecommerce-insights' );
		}
		if ( 'sales_tax' === $system ) {
			return __( 'This is a guide for your bookkeeper, not a lodgement. It shows the tax charged on orders, less tax refunded.', 'kdna-ecommerce-insights' );
		}
		return __( 'This is a guide for your bookkeeper, not a lodgement. Tax on sales comes from your orders. Tax on costs is an estimate from overheads and ad spend marked as including tax only.', 'kdna-ecommerce-insights' );
	}
}
