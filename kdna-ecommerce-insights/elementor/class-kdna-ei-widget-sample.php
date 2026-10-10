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
	 * The sample store's last 30 days and the 30 days before, built once
	 * per page load.
	 *
	 * @return array days, before_days, buckets, previous_buckets, totals, prior.
	 */
	private static function period(): array {
		static $period = null;
		if ( null !== $period ) {
			return $period;
		}
		$days  = 30;
		$today = new DateTimeImmutable( 'today', wp_timezone() );
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

		$period = array(
			'days'             => $current,
			'before_days'      => $previous,
			'buckets'          => $buckets,
			'previous_buckets' => $before,
			'totals'           => self::total( $current ),
			'prior'            => self::total( $previous ),
			'today'            => $today,
		);
		return $period;
	}

	/**
	 * KPI figures for any metrics, like /summary.
	 *
	 * @param string[] $keys Metric keys.
	 * @return array{metrics: array[]}
	 */
	public static function summary( array $keys ): array {
		$p       = self::period();
		$metrics = array();
		foreach ( array_unique( $keys ) as $key ) {
			if ( KDNA_EcommerceInsights_Metrics::get( $key ) ) {
				$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $p['totals'], $p['prior'] );
			}
		}
		return array( 'metrics' => $metrics );
	}

	/**
	 * Daily values for any metrics with the comparison lined up, like
	 * /timeseries.
	 *
	 * @param string[] $keys Metric keys.
	 * @return array
	 */
	public static function timeseries( array $keys ): array {
		$p      = self::period();
		$series = array();
		foreach ( array_unique( $keys ) as $key ) {
			$metric = KDNA_EcommerceInsights_Metrics::get( $key );
			if ( ! $metric ) {
				continue;
			}
			$per_day        = static fn( $d ) => null === ( $v = KDNA_EcommerceInsights_Metrics::value( $key, self::total( array( $d ) ) ) ) ? null : round( (float) $v, 2 );
			$series[ $key ] = array(
				'label'    => $metric['label'],
				'format'   => $metric['format'],
				'current'  => array_map( $per_day, $p['days'] ),
				'previous' => array_map( $per_day, $p['before_days'] ),
			);
		}
		return array(
			'granularity'      => 'day',
			'buckets'          => $p['buckets'],
			'previous_buckets' => $p['previous_buckets'],
			'series'           => $series,
		);
	}

	/**
	 * Stock status and low stock rows, like /inventory.
	 *
	 * @return array
	 */
	public static function inventory(): array {
		$items = array(
			array( __( 'Vitamin C Brightening Drops', 'kdna-ecommerce-insights' ), 'VC-30', 4, 31 ),
			array( __( 'Overnight Repair Mask', 'kdna-ecommerce-insights' ), 'ORM-50', 6, 22 ),
			array( __( 'Daily SPF 50 Moisturiser', 'kdna-ecommerce-insights' ), 'SPF-75', 9, 48 ),
			array( __( 'Hydrating Face Serum', 'kdna-ecommerce-insights' ), 'HFS-30', 3, 40 ),
			array( __( 'Gentle Cream Cleanser', 'kdna-ecommerce-insights' ), 'GCC-150', 8, 27 ),
		);
		$today = self::period()['today'];
		$rows  = array();
		foreach ( $items as $i => $item ) {
			$per_day = round( $item[3] / 30, 2 );
			$days    = $per_day > 0 ? (int) floor( $item[2] / $per_day ) : null;
			$rows[]  = array(
				'id'           => 9100 + $i,
				'product_id'   => 9100 + $i,
				'variation_id' => 0,
				'name'         => $item[0],
				'sku'          => $item[1],
				'stock'        => $item[2],
				'threshold'    => 10,
				'sold_30'      => $item[3],
				'per_day'      => $per_day,
				'days'         => $days,
				'runs_out'     => null === $days ? '' : $today->modify( '+' . $days . ' days' )->format( 'Y-m-d' ),
				'reorder'      => $today->format( 'Y-m-d' ),
				'reorder_now'  => true,
				'cost'         => 12.5 + $i * 3,
				'value_cost'   => round( $item[2] * ( 12.5 + $i * 3 ), 2 ),
				'value_retail' => round( $item[2] * ( 39 + $i * 8 ), 2 ),
				'thumbnail'    => '',
			);
		}
		return array(
			'status'    => array(
				'in_stock'     => 142,
				'low_stock'    => 11,
				'out_of_stock' => 6,
			),
			'low_stock' => $rows,
		);
	}

	/**
	 * The profit waterfall, cost breakdown and statement by month, like
	 * /profit.
	 *
	 * @return array
	 */
	public static function profit(): array {
		$p      = self::period();
		$months = array();
		foreach ( $p['buckets'] as $i => $bucket ) {
			$months[ substr( $bucket['start'], 0, 7 ) ][] = $p['days'][ $i ];
		}
		$statement = array();
		foreach ( $months as $month => $days ) {
			$totals = self::total( $days );
			$lines  = array();
			foreach ( KDNA_EcommerceInsights_Report::waterfall( $totals ) as $line ) {
				$lines[ $line['key'] ] = round( $line['amount'], 2 );
			}
			$lines['net_margin'] = KDNA_EcommerceInsights_Metrics::value( 'net_margin', $totals );
			$start               = new DateTimeImmutable( $month . '-01' );
			$statement[]         = array(
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $start->format( 'Y-m-t' ),
				'lines' => $lines,
			);
		}
		return array(
			'waterfall'      => KDNA_EcommerceInsights_Report::waterfall( $p['totals'] ),
			'months'         => $statement,
			'cost_breakdown' => KDNA_EcommerceInsights_Report::cost_breakdown( $p['totals'] ),
		);
	}

	/**
	 * Ad spend by channel and by campaign, like /marketing.
	 *
	 * @return array
	 */
	public static function marketing(): array {
		$spend    = self::period()['totals']['ad_spend'];
		$channels = array(
			array( 'meta', __( 'Meta Ads', 'kdna-ecommerce-insights' ), 0.56, 3.6 ),
			array( 'google', __( 'Google Ads', 'kdna-ecommerce-insights' ), 0.34, 4.1 ),
			array( 'tiktok', __( 'TikTok Ads', 'kdna-ecommerce-insights' ), 0.1, 2.2 ),
		);
		$campaigns = array(
			array( 'meta', __( 'Autumn serum launch', 'kdna-ecommerce-insights' ), 0.31, 3.9 ),
			array( 'google', __( 'Brand search', 'kdna-ecommerce-insights' ), 0.19, 6.2 ),
			array( 'meta', __( 'Retargeting, all products', 'kdna-ecommerce-insights' ), 0.25, 3.1 ),
			array( 'google', __( 'Shopping, best sellers', 'kdna-ecommerce-insights' ), 0.15, 2.6 ),
			array( 'tiktok', __( 'Skincare routine videos', 'kdna-ecommerce-insights' ), 0.1, 2.2 ),
		);
		$shape = static function ( $row, $is_campaign ) use ( $spend, $channels ) {
			$amount = round( $spend * $row[2], 2 );
			$value  = round( $amount * $row[3], 2 );
			$clicks = (int) round( $amount / 1.35 );
			$conv   = round( $value / 82, 2 );
			$label  = $row[1];
			foreach ( $channels as $channel ) {
				if ( $channel[0] === $row[0] ) {
					$label = $channel[1];
				}
			}
			return array(
				'channel'          => $row[0],
				'label'            => $label,
				'campaign_id'      => $is_campaign ? md5( $row[1] ) : '',
				'campaign_name'    => $is_campaign ? $row[1] : '',
				'spend'            => $amount,
				'impressions'      => $clicks * 46,
				'clicks'           => $clicks,
				'conversions'      => $conv,
				'conversion_value' => $value,
				'roas'             => $row[3],
				'cpc'              => $clicks ? round( $amount / $clicks, 2 ) : null,
				'cpa'              => $conv ? round( $amount / $conv, 2 ) : null,
			);
		};
		return array(
			'channels'  => array_map( static fn( $row ) => $shape( $row, false ), $channels ),
			'campaigns' => array_map( static fn( $row ) => $shape( $row, true ), $campaigns ),
		);
	}

	/**
	 * Customer figures and top customers, like /customers.
	 *
	 * @return array
	 */
	public static function customers(): array {
		$p     = self::period();
		$names = array( 'Olivia M.', 'Grace T.', 'Amelia R.', 'Chloe W.', 'Isla K.', 'Sophie L.', 'Mia H.', 'Ruby D.', 'Zara P.', 'Ella B.' );
		$rows  = array();
		foreach ( $names as $i => $name ) {
			$orders  = max( 1, 6 - (int) floor( $i / 2 ) );
			$revenue = round( 640 - $i * 47.5, 2 );
			$rows[]  = array(
				'name'             => $name,
				'email'            => '',
				'guest'            => 4 === $i,
				'orders'           => $orders,
				'revenue'          => $revenue,
				'profit'           => round( $revenue * 0.38, 2 ),
				'lifetime_orders'  => $orders + 3,
				'lifetime_revenue' => round( $revenue * 1.9, 2 ),
				'first_order'      => $p['today']->modify( '-' . ( 400 - $i * 31 ) . ' days' )->format( 'Y-m-d' ),
				'profile_url'      => '',
				'last_order_url'   => '',
			);
		}
		$metrics = array();
		foreach ( array( 'customers', 'new_customers', 'returning_customers', 'new_customer_revenue', 'returning_customer_revenue' ) as $key ) {
			$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $p['totals'], $p['prior'] );
		}
		return array(
			'metrics'       => $metrics,
			'top_customers' => $rows,
		);
	}

	/**
	 * Product performance rows, like /products.
	 *
	 * @param int $count How many products.
	 * @return array[]
	 */
	public static function products( int $count = 5 ): array {
		$names    = array(
			array( __( 'Hydrating Face Serum', 'kdna-ecommerce-insights' ), 64, 0.71 ),
			array( __( 'Daily SPF 50 Moisturiser', 'kdna-ecommerce-insights' ), 81, 0.66 ),
			array( __( 'Gentle Cream Cleanser', 'kdna-ecommerce-insights' ), 97, 0.62 ),
			array( __( 'Overnight Repair Mask', 'kdna-ecommerce-insights' ), 38, 0.69 ),
			array( __( 'Vitamin C Brightening Drops', 'kdna-ecommerce-insights' ), 29, 0.58 ),
			array( __( 'Peptide Eye Cream', 'kdna-ecommerce-insights' ), 26, 0.64 ),
			array( __( 'Exfoliating Toner', 'kdna-ecommerce-insights' ), 44, 0.6 ),
			array( __( 'Lip Treatment Balm', 'kdna-ecommerce-insights' ), 71, 0.55 ),
			array( __( 'Retinal Night Serum', 'kdna-ecommerce-insights' ), 19, 0.68 ),
			array( __( 'Refill Pouch, Cleanser', 'kdna-ecommerce-insights' ), 33, 0.52 ),
		);
		$products = array();
		foreach ( array_slice( $names, 0, max( 1, min( 10, $count ) ) ) as $i => $item ) {
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
				'refund_rate'  => round( 1.2 + $i * 0.3, 1 ),
				'thumbnail'    => '',
			);
		}
		return $products;
	}

	/**
	 * Progress towards this month's revenue goal.
	 *
	 * @return array{metric: string, value: float, target: float}
	 */
	public static function goal(): array {
		$p         = self::period();
		$month_day = (int) $p['today']->format( 'j' );
		$goal      = KDNA_EcommerceInsights_Metrics::evaluate( 'net_revenue', self::total( array_slice( $p['days'], -$month_day ) ) );
		return array(
			'metric' => 'revenue',
			'value'  => (float) $goal['value'],
			'target' => 60000,
		);
	}

	/**
	 * Background figures every widget gets: the estimates behind the alerts
	 * and the sync status.
	 *
	 * @return array
	 */
	public static function basics(): array {
		$p = self::period();
		return array(
			'estimates' => array(
				'orders'               => (int) $p['totals']['orders'],
				'fee_orders'           => 0,
				'shipping_orders'      => 0,
				'missing_cost_orders'  => 0,
				'currency_flag_orders' => 0,
				'loss_orders'          => 2,
				'missing_costs'        => 3,
			),
			'compare'   => array(
				'start' => $p['previous_buckets'][0]['start'],
				'end'   => $p['previous_buckets'][ count( $p['previous_buckets'] ) - 1 ]['end'],
			),
			'status'    => array(
				'orders_processed' => 1240,
				'missing_costs'    => 3,
				'sync_errors'      => 0,
			),
		);
	}

	/**
	 * Everything the Overview needs, in the same shapes the REST API sends.
	 *
	 * @param string $fifth The metric shown in the fifth KPI.
	 * @return array
	 */
	public static function overview( string $fifth = 'average_order_value' ): array {
		$basics = self::basics();
		return array(
			'summary'    => self::summary( array( 'net_revenue', 'net_profit', 'orders', 'net_margin', $fifth ) ),
			'estimates'  => $basics['estimates'],
			'compare'    => $basics['compare'],
			'timeseries' => self::timeseries( array( 'net_revenue', 'net_profit', 'orders' ) ),
			'inventory'  => array( 'status' => self::inventory()['status'] ),
			'products'   => self::products( 5 ),
			'waterfall'  => self::profit()['waterfall'],
			'goal'       => self::goal(),
			'status'     => $basics['status'],
		);
	}
}
