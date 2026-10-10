<?php
/**
 * Sample data for designing widgets in the Elementor editor.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Realistic made-up figures for the editor's "Sample data" preview state,
 * so a page can be designed on a store with no orders yet.
 *
 * The figures are built as daily totals and then run through the real
 * metric registry and profit waterfall, so they always add up the same way
 * live figures do (net profit really is revenue less every cost). They are
 * the same every time for a given day, so the preview does not jump about.
 *
 * Sample data is only ever printed inside the Elementor editor.
 */
class KDNA_EcommerceInsights_Widget_Sample {

	/**
	 * A repeatable "random" number between 0 and 1 for a seed.
	 *
	 * @param int $seed Seed.
	 * @return float
	 */
	private static function noise( int $seed ): float {
		$x = sin( $seed * 12.9898 ) * 43758.5453;
		return $x - floor( $x );
	}

	/**
	 * Daily totals for one day of the sample store.
	 *
	 * @param int   $index  Day number (0 is the first day shown).
	 * @param float $factor Scale, so the comparison period is a little lower.
	 * @return array<string, float>
	 */
	private static function day( int $index, float $factor ): array {
		$weekend = in_array( ( $index + 3 ) % 7, array( 5, 6 ), true ) ? 1.18 : 1.0;
		$wave    = 1 + 0.18 * sin( $index / 4.2 ) + 0.1 * ( self::noise( $index + (int) ( $factor * 100 ) ) - 0.5 );
		$orders  = max( 3, round( 28 * $weekend * $wave * $factor ) );
		$aov     = 72 + 16 * self::noise( $index * 7 );
		$gross   = $orders * $aov;

		return array(
			'orders'               => $orders,
			'items_sold'           => round( $orders * 2.3 ),
			'gross_sales'          => $gross,
			'discounts'            => $gross * 0.06,
			'refunds'              => $gross * 0.015,
			'shipping_charged'     => $orders * 6.5,
			'tax'                  => $gross * 0.09,
			'cogs'                 => $gross * 0.31,
			'payment_fees'         => $gross * 0.028,
			'shipping_costs'       => $orders * 8.9,
			'extra_costs'          => $orders * 1.4,
			'ad_spend'             => 210 * $wave * $factor,
			'overheads'            => 165,
			'new_customers'        => round( $orders * 0.58 ),
			'returning_customers'  => round( $orders * 0.42 ),
			'customers'            => round( $orders * 0.97 ),
			'new_customer_revenue' => $gross * 0.55,
		);
	}

	/**
	 * Adds up days into totals, working out net revenue and the profit
	 * figures the same way the order engine does.
	 *
	 * @param array[] $days Daily totals.
	 * @return array<string, float>
	 */
	private static function total( array $days ): array {
		$sum = array();
		foreach ( $days as $day ) {
			foreach ( $day as $key => $value ) {
				$sum[ $key ] = ( $sum[ $key ] ?? 0 ) + $value;
			}
		}
		$sum['net_revenue']         = $sum['gross_sales'] - $sum['discounts'] - $sum['refunds'] + $sum['shipping_charged'];
		$sum['gross_profit']        = $sum['net_revenue'] - $sum['cogs'];
		$sum['contribution_profit'] = $sum['gross_profit'] - $sum['payment_fees'] - $sum['shipping_costs'] - $sum['extra_costs'];
		$sum['ad_spend_tax']        = 0;
		$sum['overheads_tax']       = 0;
		$sum['ad_conversion_value'] = $sum['ad_spend'] * 3.4;
		return $sum;
	}

	/**
	 * Everything the Overview needs, in the same shapes the REST API sends.
	 *
	 * @param string $fifth The metric shown in the fifth KPI.
	 * @return array
	 */
	public static function overview( string $fifth = 'average_order_value' ): array {
		$days  = 30;
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$first = $today->modify( '-' . ( $days - 1 ) . ' days' );

		$current  = array();
		$previous = array();
		$buckets  = array();
		$before   = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$current[]  = self::day( $i + 40, 1.0 );
			$previous[] = self::day( $i + 10, 0.9 );
			$date       = $first->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$old        = $first->modify( '-' . ( $days - $i ) . ' days' )->format( 'Y-m-d' );
			$buckets[]  = array(
				'key'   => $date,
				'start' => $date,
				'end'   => $date,
			);
			$before[]   = array(
				'key'   => $old,
				'start' => $old,
				'end'   => $old,
			);
		}

		$totals = self::total( $current );
		$prior  = self::total( $previous );

		// Series per day, through the same metric code as live figures.
		$series = array();
		foreach ( array( 'net_revenue', 'net_profit', 'orders' ) as $key ) {
			$metric         = KDNA_EcommerceInsights_Metrics::get( $key );
			$series[ $key ] = array(
				'label'    => $metric['label'],
				'format'   => $metric['format'],
				'current'  => array_map( static fn( $d ) => round( (float) KDNA_EcommerceInsights_Metrics::value( $key, self::total( array( $d ) ) ), 2 ), $current ),
				'previous' => array_map( static fn( $d ) => round( (float) KDNA_EcommerceInsights_Metrics::value( $key, self::total( array( $d ) ) ), 2 ), $previous ),
			);
		}

		$metrics = array();
		foreach ( array_unique( array( 'net_revenue', 'net_profit', 'orders', 'net_margin', $fifth ) ) as $key ) {
			if ( KDNA_EcommerceInsights_Metrics::get( $key ) ) {
				$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $prior );
			}
		}

		$names    = array(
			array( __( 'Hydrating Face Serum', 'kdna-ecommerce-insights' ), 64, 0.71 ),
			array( __( 'Daily SPF 50 Moisturiser', 'kdna-ecommerce-insights' ), 81, 0.66 ),
			array( __( 'Gentle Cream Cleanser', 'kdna-ecommerce-insights' ), 97, 0.62 ),
			array( __( 'Overnight Repair Mask', 'kdna-ecommerce-insights' ), 38, 0.69 ),
			array( __( 'Vitamin C Brightening Drops', 'kdna-ecommerce-insights' ), 29, 0.58 ),
		);
		$products = array();
		foreach ( $names as $i => $item ) {
			$revenue    = round( $item[1] * ( 58 - $i * 4 ), 2 );
			$profit     = round( $revenue * $item[2], 2 );
			$products[] = array(
				'product_id'   => 9000 + $i,
				'variation_id' => 0,
				'name'         => $item[0],
				'variation'    => '',
				'units'        => $item[1],
				'orders'       => (int) round( $item[1] * 0.9 ),
				'revenue'      => $revenue,
				'cost'         => round( $revenue - $profit, 2 ),
				'profit'       => $profit,
				'margin'       => round( $item[2] * 100, 1 ),
				'thumbnail'    => '',
			);
		}

		$month_day = (int) $today->format( 'j' );
		$goal      = KDNA_EcommerceInsights_Metrics::evaluate( 'net_revenue', self::total( array_slice( $current, -$month_day ) ) );

		return array(
			'summary'    => array( 'metrics' => $metrics ),
			'estimates'  => array(
				'orders'               => (int) $totals['orders'],
				'fee_orders'           => 0,
				'shipping_orders'      => 0,
				'missing_cost_orders'  => 0,
				'currency_flag_orders' => 0,
				'loss_orders'          => 2,
				'missing_costs'        => 3,
			),
			'compare'    => array(
				'start' => $before[0]['start'],
				'end'   => $before[ $days - 1 ]['end'],
			),
			'timeseries' => array(
				'granularity'      => 'day',
				'buckets'          => $buckets,
				'previous_buckets' => $before,
				'series'           => $series,
			),
			'inventory'  => array(
				'status' => array(
					'in_stock'     => 142,
					'low_stock'    => 11,
					'out_of_stock' => 6,
				),
			),
			'products'   => $products,
			'waterfall'  => KDNA_EcommerceInsights_Report::waterfall( $totals ),
			'goal'       => array(
				'metric' => 'revenue',
				'value'  => (float) $goal['value'],
				'target' => 60000,
			),
			'status'     => array(
				'orders_processed' => 1240,
				'missing_costs'    => 3,
				'sync_errors'      => 0,
			),
		);
	}
}
