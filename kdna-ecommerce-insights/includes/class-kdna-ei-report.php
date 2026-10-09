<?php
/**
 * Report data: reads the summary and fact tables and hands totals to the
 * metric registry. Never loops through raw WooCommerce orders.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gathers the numbers behind every report: totals and time series from
 * daily_summary, product and customer detail from order_facts and
 * order_item_facts, ad spend, overheads and stock.
 *
 * Every query goes through KDNA_EcommerceInsights_Cache::timed() so debug
 * mode can show how long each one took.
 */
class KDNA_EcommerceInsights_Report {

	/**
	 * daily_summary columns added up into range totals.
	 */
	const SUMMARY_COLUMNS = array(
		'orders',
		'items_sold',
		'gross_sales',
		'discounts',
		'refunds',
		'shipping_charged',
		'tax',
		'net_revenue',
		'cogs',
		'gross_profit',
		'payment_fees',
		'shipping_costs',
		'extra_costs',
		'contribution_profit',
		'new_customers',
		'returning_customers',
		'new_customer_revenue',
		'estimated_fee_orders',
		'estimated_shipping_orders',
		'missing_cost_orders',
	);

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Full name of a plugin table.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	private static function t( string $name ): string {
		return KDNA_EcommerceInsights_Install::table( $name );
	}

	/**
	 * SQL placeholders and values for the counted order statuses.
	 *
	 * @return array{0: string, 1: string[]}
	 */
	private static function statuses(): array {
		$statuses = KDNA_EcommerceInsights_Summary::counted_statuses();
		return array( implode( ',', array_fill( 0, count( $statuses ), '%s' ) ), $statuses );
	}

	/**
	 * Today in the site time zone, Y-m-d.
	 *
	 * @return string
	 */
	private static function today(): string {
		return ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Totals
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Adds up everything for a date range: the daily summary, ad spend and
	 * overheads, plus the number of different customers.
	 *
	 * @param array $range Range from KDNA_EcommerceInsights_Dates.
	 * @return array<string, float>
	 */
	public static function totals( array $range ): array {
		global $wpdb;

		$sums = implode( ', ', array_map( static fn( $c ) => "COALESCE( SUM( {$c} ), 0 ) AS {$c}", self::SUMMARY_COLUMNS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$summary = KDNA_EcommerceInsights_Cache::timed(
			'Totals from daily_summary',
			static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT ' . $sums . ' FROM ' . self::t( 'daily_summary' ) . ' WHERE summary_date BETWEEN %s AND %s', $range['start'], $range['end'] ), ARRAY_A )
		);

		list( $in, $statuses ) = self::statuses();
		$customers             = KDNA_EcommerceInsights_Cache::timed(
			'Distinct customers from order_facts',
			static fn() => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT( DISTINCT customer_key ) FROM ' . self::t( 'order_facts' ) . " WHERE report_date BETWEEN %s AND %s AND status IN ( $in )", array_merge( array( $range['start'], $range['end'] ), $statuses ) ) )
		);
		// phpcs:enable

		$totals              = array_map( 'floatval', (array) $summary );
		$totals['customers'] = (float) $customers;

		// The daily summary's returning figure counts returning orders. A
		// customer who orders on three days is still one returning customer.
		$totals['returning_customers'] = max( 0.0, $totals['customers'] - $totals['new_customers'] );

		return array_merge( $totals, self::ad_spend_totals( $range ), self::overhead_totals( $range ) );
	}

	/**
	 * Ad spend for a range, with claimable GST taken out of entries that include it.
	 *
	 * @param array $range Range.
	 * @return array{ad_spend: float, ad_spend_tax: float, ad_conversion_value: float}
	 */
	private static function ad_spend_totals( array $range ): array {
		global $wpdb;
		$rate = KDNA_EcommerceInsights_Ad_Spend::claimable_tax_rate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = KDNA_EcommerceInsights_Cache::timed(
			'Ad spend totals',
			static fn() => $wpdb->get_row(
				$wpdb->prepare(
					'SELECT COALESCE( SUM( spend ), 0 ) AS spend, COALESCE( SUM( CASE WHEN includes_gst = 1 THEN spend ELSE 0 END ), 0 ) AS spend_with_tax, COALESCE( SUM( conversion_value ), 0 ) AS conversion_value FROM ' . self::t( 'ad_spend' ) . ' WHERE spend_date BETWEEN %s AND %s',
					$range['start'],
					$range['end']
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$tax = $rate > 0 ? (float) $row['spend_with_tax'] * $rate / ( 100 + $rate ) : 0.0;

		return array(
			'ad_spend'            => (float) $row['spend'] - $tax,
			'ad_spend_tax'        => $tax,
			'ad_conversion_value' => (float) $row['conversion_value'],
		);
	}

	/**
	 * Overheads for a range, spread evenly by day.
	 *
	 * @param array      $range     Range.
	 * @param array|null $overheads Overheads already loaded, to avoid reloading for every chart bucket.
	 * @return array{overheads: float, overheads_tax: float}
	 */
	private static function overhead_totals( array $range, ?array $overheads = null ): array {
		$overheads = null === $overheads ? KDNA_EcommerceInsights_Cache::timed( 'Overheads', array( 'KDNA_EcommerceInsights_Overheads', 'all' ) ) : $overheads;
		$spread    = KDNA_EcommerceInsights_Overheads::spread( $overheads, $range['start'], $range['end'], KDNA_EcommerceInsights_Ad_Spend::claimable_tax_rate() );

		return array(
			'overheads'     => $spread['net'],
			'overheads_tax' => $spread['tax'],
		);
	}

	/**
	 * How reliable the figures for a range are: how many orders used
	 * estimated fees or shipping, are missing product costs, or are in
	 * another currency without an exchange rate. Included in every response.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public static function estimates( array $range ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$row = KDNA_EcommerceInsights_Cache::timed(
			'Estimate counts from order_facts',
			static fn() => $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS orders,
						COALESCE( SUM( CASE WHEN fee_source = 'estimated' THEN 1 ELSE 0 END ), 0 ) AS fee_orders,
						COALESCE( SUM( CASE WHEN shipping_source = 'estimated' THEN 1 ELSE 0 END ), 0 ) AS shipping_orders,
						COALESCE( SUM( missing_cost_flag ), 0 ) AS missing_cost_orders,
						COALESCE( SUM( currency_flag ), 0 ) AS currency_flag_orders,
						COALESCE( SUM( CASE WHEN contribution_profit < 0 THEN 1 ELSE 0 END ), 0 ) AS loss_orders
					FROM " . self::t( 'order_facts' ) . " WHERE report_date BETWEEN %s AND %s AND status IN ( $in )",
					array_merge( array( $range['start'], $range['end'] ), $statuses )
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$row = array_map( 'intval', (array) $row );

		return array(
			'orders'               => $row['orders'] ?? 0,
			'fee_orders'           => $row['fee_orders'] ?? 0,
			'shipping_orders'      => $row['shipping_orders'] ?? 0,
			'has_estimates'        => ( $row['fee_orders'] ?? 0 ) + ( $row['shipping_orders'] ?? 0 ) > 0,
			'missing_cost_orders'  => $row['missing_cost_orders'] ?? 0,
			'currency_flag_orders' => $row['currency_flag_orders'] ?? 0,
			'loss_orders'          => $row['loss_orders'] ?? 0,
			'missing_costs'        => KDNA_EcommerceInsights_Cost_Catalogue::missing_count(),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Time series
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Builds chart series for metrics over a range, grouped by day, week or month.
	 *
	 * @param array    $range       Range.
	 * @param string   $granularity day, week or month.
	 * @param string[] $metrics     Metric keys.
	 * @return array{buckets: array[], series: array<string, array>}
	 */
	public static function series( array $range, string $granularity, array $metrics ): array {
		global $wpdb;

		$columns = implode( ', ', array_merge( array( 'summary_date' ), self::SUMMARY_COLUMNS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$days = KDNA_EcommerceInsights_Cache::timed(
			'Daily rows from daily_summary',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM " . self::t( 'daily_summary' ) . ' WHERE summary_date BETWEEN %s AND %s', $range['start'], $range['end'] ), ARRAY_A )
		);
		$ads  = KDNA_EcommerceInsights_Cache::timed(
			'Daily ad spend',
			static fn() => $wpdb->get_results( $wpdb->prepare( 'SELECT spend_date, SUM( spend ) AS spend, SUM( CASE WHEN includes_gst = 1 THEN spend ELSE 0 END ) AS spend_with_tax, SUM( conversion_value ) AS conversion_value FROM ' . self::t( 'ad_spend' ) . ' WHERE spend_date BETWEEN %s AND %s GROUP BY spend_date', $range['start'], $range['end'] ), ARRAY_A )
		);
		// phpcs:enable

		$by_day = array();
		foreach ( (array) $days as $row ) {
			$by_day[ $row['summary_date'] ] = $row;
		}
		$ads_by_day = array();
		foreach ( (array) $ads as $row ) {
			$ads_by_day[ $row['spend_date'] ] = $row;
		}

		$rate      = KDNA_EcommerceInsights_Ad_Spend::claimable_tax_rate();
		$overheads = KDNA_EcommerceInsights_Cache::timed( 'Overheads', array( 'KDNA_EcommerceInsights_Overheads', 'all' ) );
		$buckets   = KDNA_EcommerceInsights_Dates::buckets( $range, $granularity );
		$series    = array_fill_keys( $metrics, array() );

		// Different customers per bucket, only when a customer metric is asked for.
		$people = array_intersect( $metrics, array( 'customers', 'returning_customers' ) ) ? self::customers_by_bucket( $range, $buckets ) : array();

		foreach ( $buckets as $bucket ) {
			$totals = array_fill_keys( self::SUMMARY_COLUMNS, 0.0 );
			$spend  = 0.0;
			$taxed  = 0.0;
			$value  = 0.0;

			$day = new DateTimeImmutable( $bucket['start'] );
			$end = new DateTimeImmutable( $bucket['end'] );
			while ( $day <= $end ) {
				$key = $day->format( 'Y-m-d' );
				if ( isset( $by_day[ $key ] ) ) {
					foreach ( self::SUMMARY_COLUMNS as $column ) {
						$totals[ $column ] += (float) $by_day[ $key ][ $column ];
					}
				}
				if ( isset( $ads_by_day[ $key ] ) ) {
					$spend += (float) $ads_by_day[ $key ]['spend'];
					$taxed += (float) $ads_by_day[ $key ]['spend_with_tax'];
					$value += (float) $ads_by_day[ $key ]['conversion_value'];
				}
				$day = $day->modify( '+1 day' );
			}

			$tax                           = $rate > 0 ? $taxed * $rate / ( 100 + $rate ) : 0.0;
			$totals['ad_spend']            = $spend - $tax;
			$totals['ad_spend_tax']        = $tax;
			$totals['ad_conversion_value'] = $value;
			$totals                        = array_merge( $totals, self::overhead_totals( $bucket, $overheads ) );

			if ( $people ) {
				$totals['customers']           = (float) ( $people[ $bucket['key'] ] ?? 0 );
				$totals['returning_customers'] = max( 0.0, $totals['customers'] - $totals['new_customers'] );
			}

			foreach ( $metrics as $metric ) {
				$series[ $metric ][] = KDNA_EcommerceInsights_Metrics::value( $metric, $totals );
			}
		}

		return array(
			'buckets' => $buckets,
			'series'  => $series,
		);
	}

	/**
	 * The number of different customers who ordered in each bucket. Guests
	 * are one customer per billing email.
	 *
	 * @param array   $range   Range.
	 * @param array[] $buckets Buckets from KDNA_EcommerceInsights_Dates::buckets().
	 * @return array<string, int> Keyed by bucket key.
	 */
	private static function customers_by_bucket( array $range, array $buckets ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Customers per day',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT report_date, customer_key FROM ' . self::t( 'order_facts' ) . " WHERE report_date BETWEEN %s AND %s AND status IN ( $in )",
					array_merge( array( $range['start'], $range['end'] ), $statuses )
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		// Which bucket each day belongs to, worked out once.
		$day_bucket = array();
		foreach ( $buckets as $bucket ) {
			$day = new DateTimeImmutable( $bucket['start'] );
			$end = new DateTimeImmutable( $bucket['end'] );
			while ( $day <= $end ) {
				$day_bucket[ $day->format( 'Y-m-d' ) ] = $bucket['key'];
				$day                                    = $day->modify( '+1 day' );
			}
		}

		$sets = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $day_bucket[ $row['report_date'] ] ) ) {
				$sets[ $day_bucket[ $row['report_date'] ] ][ $row['customer_key'] ] = true;
			}
		}
		return array_map( 'count', $sets );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Profit and loss
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The profit waterfall lines from section 6 for one set of totals.
	 *
	 * @param array $totals Totals.
	 * @return array[] Each: key, label, amount, type (add, subtract, total).
	 */
	public static function waterfall( array $totals ): array {
		$v = static fn( $key ) => (float) KDNA_EcommerceInsights_Metrics::value( $key, $totals );

		return array(
			array( 'key' => 'gross_sales', 'label' => __( 'Gross sales', 'kdna-ecommerce-insights' ), 'amount' => $v( 'gross_sales' ), 'type' => 'add' ),
			array( 'key' => 'discounts', 'label' => __( 'Discounts', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'discounts' ), 'type' => 'subtract' ),
			array( 'key' => 'refunds', 'label' => __( 'Refunds', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'refunds' ), 'type' => 'subtract' ),
			array( 'key' => 'shipping_charged', 'label' => __( 'Shipping charged', 'kdna-ecommerce-insights' ), 'amount' => $v( 'shipping_charged' ), 'type' => 'add' ),
			array( 'key' => 'net_revenue', 'label' => __( 'Net revenue', 'kdna-ecommerce-insights' ), 'amount' => (float) ( $totals['net_revenue'] ?? 0 ), 'type' => 'total' ),
			array( 'key' => 'cogs', 'label' => __( 'Cost of goods', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'cogs' ), 'type' => 'subtract' ),
			array( 'key' => 'gross_profit', 'label' => __( 'Gross profit', 'kdna-ecommerce-insights' ), 'amount' => $v( 'gross_profit' ), 'type' => 'total' ),
			array( 'key' => 'payment_fees', 'label' => __( 'Payment fees', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'payment_fees' ), 'type' => 'subtract' ),
			array( 'key' => 'shipping_costs', 'label' => __( 'Shipping costs', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'shipping_costs' ), 'type' => 'subtract' ),
			array( 'key' => 'extra_costs', 'label' => __( 'Extra order costs', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'extra_costs' ), 'type' => 'subtract' ),
			array( 'key' => 'contribution_profit', 'label' => __( 'Contribution profit', 'kdna-ecommerce-insights' ), 'amount' => $v( 'contribution_profit' ), 'type' => 'total' ),
			array( 'key' => 'ad_spend', 'label' => __( 'Ad spend', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'ad_spend' ), 'type' => 'subtract' ),
			array( 'key' => 'overheads', 'label' => __( 'Overheads', 'kdna-ecommerce-insights' ), 'amount' => -$v( 'overheads' ), 'type' => 'subtract' ),
			array( 'key' => 'net_profit', 'label' => __( 'Net profit', 'kdna-ecommerce-insights' ), 'amount' => $v( 'net_profit' ), 'type' => 'total' ),
		);
	}

	/**
	 * Profit and loss for a range: the waterfall, a statement by month and
	 * a breakdown of where the money went.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public static function profit( array $range ): array {
		$totals = self::totals( $range );

		$months = array();
		foreach ( KDNA_EcommerceInsights_Dates::buckets( $range, 'month' ) as $bucket ) {
			$month_totals = self::totals( $bucket );
			$lines        = array();
			foreach ( self::waterfall( $month_totals ) as $line ) {
				$lines[ $line['key'] ] = round( $line['amount'], 2 );
			}
			$lines['net_margin'] = KDNA_EcommerceInsights_Metrics::value( 'net_margin', $month_totals );
			$months[]            = array(
				'start' => $bucket['start'],
				'end'   => $bucket['end'],
				'lines' => $lines,
			);
		}

		$cost_keys = array( 'cogs', 'payment_fees', 'shipping_costs', 'extra_costs', 'ad_spend', 'overheads' );
		$costs     = array();
		$all       = 0.0;
		foreach ( $cost_keys as $key ) {
			$amount = max( 0, (float) KDNA_EcommerceInsights_Metrics::value( $key, $totals ) );
			$costs[] = array(
				'key'    => $key,
				'label'  => KDNA_EcommerceInsights_Metrics::get( $key )['label'],
				'amount' => round( $amount, 2 ),
			);
			$all += $amount;
		}
		foreach ( $costs as $i => $cost ) {
			$costs[ $i ]['share'] = $all > 0 ? round( $cost['amount'] / $all * 100, 1 ) : 0;
		}

		return array(
			'waterfall'      => self::waterfall( $totals ),
			'months'         => $months,
			'cost_breakdown' => $costs,
			'margins'        => array(
				'gross'        => KDNA_EcommerceInsights_Metrics::value( 'gross_margin', $totals ),
				'net'          => KDNA_EcommerceInsights_Metrics::value( 'net_margin', $totals ),
			),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Products
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Product and variation performance for a range, sortable, searchable
	 * and paginated, with best and worst performers and a category breakdown.
	 *
	 * @param array $range Range.
	 * @param array $args  group (product or variation), orderby, order, search, category, loss_only, page, per_page.
	 * @return array
	 */
	public static function products( array $range, array $args ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();

		$by_variation = 'variation' === ( $args['group'] ?? 'product' );
		$group_by     = $by_variation ? 'i.product_id, i.variation_id' : 'i.product_id';
		$select_var   = $by_variation ? 'i.variation_id' : '0';
		$restock      = 'restocked' === KDNA_EcommerceInsights_Settings::get( 'general.restock_treatment', 'restocked' ) ? 1 : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Product totals from order_item_facts',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT i.product_id, {$select_var} AS variation_id,
						SUM( i.qty ) AS units,
						SUM( i.refunded_qty ) AS refunded_units,
						SUM( i.line_net ) AS revenue,
						SUM( i.refunded_amount ) AS refunds,
						SUM( i.line_cost ) AS cost,
						SUM( CASE WHEN i.unit_cost IS NULL THEN 0 ELSE i.unit_cost * i.refunded_qty * {$restock} END ) AS cost_returned,
						SUM( i.missing_cost ) AS missing_lines,
						COUNT( DISTINCT i.order_id ) AS orders
					FROM " . self::t( 'order_item_facts' ) . ' i
					INNER JOIN ' . self::t( 'order_facts' ) . " f ON f.order_id = i.order_id
					WHERE f.report_date BETWEEN %s AND %s AND f.status IN ( $in )
					GROUP BY {$group_by}",
					array_merge( array( $range['start'], $range['end'] ), $statuses )
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$names      = self::product_names( $rows );
		$categories = self::product_categories( wp_list_pluck( (array) $rows, 'product_id' ) );
		$list       = array();

		foreach ( (array) $rows as $row ) {
			$revenue = (float) $row['revenue'] - (float) $row['refunds'];
			$cost    = (float) $row['cost'] - (float) $row['cost_returned'];
			$profit  = $revenue - $cost;
			$id      = (int) ( $row['variation_id'] ? $row['variation_id'] : $row['product_id'] );

			$list[] = array(
				'product_id'     => (int) $row['product_id'],
				'variation_id'   => (int) $row['variation_id'],
				'name'           => $names[ (int) $row['product_id'] ] ?? sprintf( /* translators: %d: product ID. */ __( 'Deleted product #%d', 'kdna-ecommerce-insights' ), (int) $row['product_id'] ),
				'variation'      => $row['variation_id'] ? ( $names[ (int) $row['variation_id'] . ':attributes' ] ?? '' ) : '',
				'units'          => (float) $row['units'],
				'orders'         => (int) $row['orders'],
				'revenue'        => round( $revenue, 2 ),
				'cost'           => round( $cost, 2 ),
				'profit'         => round( $profit, 2 ),
				'margin'         => KDNA_EcommerceInsights_Metrics::margin( $profit, $revenue ),
				'refund_rate'    => KDNA_EcommerceInsights_Metrics::margin( (float) $row['refunded_units'], (float) $row['units'] ),
				'missing_cost'   => (int) $row['missing_lines'] > 0,
				'categories'     => $categories[ (int) $row['product_id'] ] ?? array(),
				'thumbnail'      => '',
				'edit_url'       => '',
				'_id'            => $id,
			);
		}

		// Best and worst performers by profit, before filters.
		$by_profit = $list;
		usort( $by_profit, static fn( $a, $b ) => $b['profit'] <=> $a['profit'] );
		$best  = array_slice( $by_profit, 0, 5 );
		$worst = array_slice( array_reverse( $by_profit ), 0, 5 );

		// Category breakdown.
		$category_totals = array();
		foreach ( $list as $item ) {
			foreach ( $item['categories'] ?: array( 0 ) as $cat ) {
				if ( ! isset( $category_totals[ $cat ] ) ) {
					$category_totals[ $cat ] = array( 'id' => $cat, 'revenue' => 0.0, 'profit' => 0.0, 'units' => 0.0 );
				}
				$category_totals[ $cat ]['revenue'] += $item['revenue'];
				$category_totals[ $cat ]['profit']  += $item['profit'];
				$category_totals[ $cat ]['units']   += $item['units'];
			}
		}
		foreach ( $category_totals as $cat => $totals ) {
			$term                                = $cat ? get_term( $cat, 'product_cat' ) : null;
			$category_totals[ $cat ]['name']     = $term && ! is_wp_error( $term ) ? $term->name : __( 'Uncategorised', 'kdna-ecommerce-insights' );
			$category_totals[ $cat ]['revenue']  = round( $totals['revenue'], 2 );
			$category_totals[ $cat ]['profit']   = round( $totals['profit'], 2 );
			$category_totals[ $cat ]['margin']   = KDNA_EcommerceInsights_Metrics::margin( $totals['profit'], $totals['revenue'] );
		}
		usort( $category_totals, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

		// Filters.
		$search   = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		$category = (int) ( $args['category'] ?? 0 );
		$list     = array_values(
			array_filter(
				$list,
				static function ( $item ) use ( $search, $category, $args ) {
					if ( '' !== $search && false === strpos( strtolower( $item['name'] . ' ' . $item['variation'] ), $search ) ) {
						return false;
					}
					if ( $category && ! in_array( $category, $item['categories'], true ) ) {
						return false;
					}
					return empty( $args['loss_only'] ) || $item['profit'] < 0;
				}
			)
		);

		// Sorting.
		$orderby = in_array( $args['orderby'] ?? '', array( 'name', 'units', 'revenue', 'cost', 'profit', 'margin', 'refund_rate', 'orders' ), true ) ? $args['orderby'] : 'profit';
		$desc    = 'asc' !== strtolower( (string) ( $args['order'] ?? 'desc' ) );
		usort(
			$list,
			static function ( $a, $b ) use ( $orderby, $desc ) {
				$result = 'name' === $orderby ? strnatcasecmp( $a['name'], $b['name'] ) : ( (float) $a[ $orderby ] <=> (float) $b[ $orderby ] );
				return $desc ? -$result : $result;
			}
		);

		// Paging, then thumbnails and links for the visible rows only.
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$total    = count( $list );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = max( 1, min( $pages, (int) ( $args['page'] ?? 1 ) ) );
		$visible  = array_slice( $list, ( $page - 1 ) * $per_page, $per_page );

		foreach ( array( &$visible, &$best, &$worst ) as &$set ) {
			foreach ( $set as &$item ) {
				$thumb             = get_the_post_thumbnail_url( $item['_id'], 'thumbnail' );
				$item['thumbnail'] = $thumb ? $thumb : (string) get_the_post_thumbnail_url( $item['product_id'], 'thumbnail' );
				$item['edit_url']  = (string) get_edit_post_link( $item['product_id'], 'raw' );
				unset( $item['_id'] );
			}
			unset( $item );
		}
		unset( $set );

		return array(
			'rows'       => $visible,
			'total'      => $total,
			'page'       => $page,
			'pages'      => $pages,
			'best'       => $best,
			'worst'      => $worst,
			'categories' => array_values( $category_totals ),
			'loss_count' => count( array_filter( $by_profit, static fn( $item ) => $item['profit'] < 0 ) ),
		);
	}

	/**
	 * Product and variation names for report rows, in one query.
	 *
	 * @param array $rows Rows with product_id and variation_id.
	 * @return array<string, string> Names keyed by ID, and variation attributes keyed "ID:attributes".
	 */
	private static function product_names( array $rows ): array {
		global $wpdb;

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row['product_id'];
			if ( $row['variation_id'] ) {
				$ids[] = (int) $row['variation_id'];
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$posts = KDNA_EcommerceInsights_Cache::timed(
			'Product names',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_excerpt, post_type FROM {$wpdb->posts} WHERE ID IN ( $placeholders )", $ids ), ARRAY_A )
		);
		// phpcs:enable

		$names = array();
		foreach ( (array) $posts as $post ) {
			$names[ (int) $post['ID'] ] = wp_specialchars_decode( (string) $post['post_title'], ENT_QUOTES );
			if ( 'product_variation' === $post['post_type'] ) {
				$names[ (int) $post['ID'] . ':attributes' ] = wp_specialchars_decode( (string) $post['post_excerpt'], ENT_QUOTES );
			}
		}
		return $names;
	}

	/**
	 * Category IDs for each product, in one query.
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return array<int, int[]>
	 */
	private static function product_categories( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		if ( ! $product_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Product categories',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tr.object_id IN ( $placeholders )",
					$product_ids
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['object_id'] ][] = (int) $row['term_id'];
		}
		return $map;
	}

	/**
	 * Daily sales figures for one product (or one variation) straight from
	 * the order item facts, optionally split by variation.
	 *
	 * @param array $range           Range.
	 * @param int   $product_id      Parent product ID.
	 * @param int   $variation_id    Variation ID, or 0 for the whole product.
	 * @param bool  $by_variation    Group by variation instead of by day.
	 * @return array[] Rows with day or variation_id, units, refunded_units, revenue, cost, orders.
	 */
	private static function product_rows( array $range, int $product_id, int $variation_id, bool $by_variation = false ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();

		$restock = 'restocked' === KDNA_EcommerceInsights_Settings::get( 'general.restock_treatment', 'restocked' ) ? 1 : 0;
		$group   = $by_variation ? 'i.variation_id' : 'f.report_date';
		$where   = $variation_id ? ' AND i.variation_id = %d' : '';
		$values  = array_merge( array( $product_id, $range['start'], $range['end'] ), $statuses, $variation_id ? array( $variation_id ) : array() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			$by_variation ? 'Product figures by variation' : 'Product figures by day',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$group} AS grouping_key,
						SUM( i.qty ) AS units,
						SUM( i.refunded_qty ) AS refunded_units,
						SUM( i.line_net ) - SUM( i.refunded_amount ) AS revenue,
						SUM( i.line_cost ) - SUM( CASE WHEN i.unit_cost IS NULL THEN 0 ELSE i.unit_cost * i.refunded_qty * {$restock} END ) AS cost,
						SUM( i.missing_cost ) AS missing_lines,
						COUNT( DISTINCT i.order_id ) AS orders
					FROM " . self::t( 'order_item_facts' ) . ' i
					INNER JOIN ' . self::t( 'order_facts' ) . " f ON f.order_id = i.order_id
					WHERE i.product_id = %d AND f.report_date BETWEEN %s AND %s AND f.status IN ( $in ){$where}
					GROUP BY {$group}",
					$values
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		return (array) $rows;
	}

	/**
	 * Adds up product rows into one set of totals with profit, margin and
	 * refund rate.
	 *
	 * @param array[] $rows Rows from product_rows().
	 * @return array
	 */
	private static function product_totals( array $rows ): array {
		$totals = array( 'units' => 0.0, 'refunded_units' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'orders' => 0, 'missing_cost' => false );
		foreach ( $rows as $row ) {
			$totals['units']          += (float) $row['units'];
			$totals['refunded_units'] += (float) $row['refunded_units'];
			$totals['revenue']        += (float) $row['revenue'];
			$totals['cost']           += (float) $row['cost'];
			$totals['orders']         += (int) $row['orders'];
			$totals['missing_cost']    = $totals['missing_cost'] || (int) $row['missing_lines'] > 0;
		}

		$profit = $totals['revenue'] - $totals['cost'];
		return array(
			'units'        => $totals['units'],
			'orders'       => $totals['orders'],
			'revenue'      => round( $totals['revenue'], 2 ),
			'cost'         => round( $totals['cost'], 2 ),
			'profit'       => round( $profit, 2 ),
			'margin'       => KDNA_EcommerceInsights_Metrics::margin( $profit, $totals['revenue'] ),
			'refund_rate'  => KDNA_EcommerceInsights_Metrics::margin( $totals['refunded_units'], $totals['units'] ),
			'missing_cost' => $totals['missing_cost'],
		);
	}

	/**
	 * Everything the product drawer shows: the product's details, totals
	 * for the range and comparison period, a sales and profit series, and
	 * a split by variation for variable products.
	 *
	 * @param array      $range        Range.
	 * @param array|null $compare      Comparison range.
	 * @param int        $product_id   Parent product ID.
	 * @param int        $variation_id Variation ID, or 0 for the whole product.
	 * @return array
	 */
	public static function product_detail( array $range, ?array $compare, int $product_id, int $variation_id = 0 ): array {
		$days        = self::product_rows( $range, $product_id, $variation_id );
		$granularity = KDNA_EcommerceInsights_Dates::auto_granularity( $range );
		$buckets     = KDNA_EcommerceInsights_Dates::buckets( $range, $granularity );

		$by_day = array();
		foreach ( $days as $row ) {
			$by_day[ $row['grouping_key'] ] = $row;
		}

		$series = array( 'units' => array(), 'revenue' => array(), 'profit' => array() );
		foreach ( $buckets as $bucket ) {
			$in_bucket = array_filter( $by_day, static fn( $day ) => $day >= $bucket['start'] && $day <= $bucket['end'], ARRAY_FILTER_USE_KEY );
			$totals    = self::product_totals( $in_bucket );

			$series['units'][]   = $totals['units'];
			$series['revenue'][] = $totals['revenue'];
			$series['profit'][]  = $totals['profit'];
		}

		// Split by variation for a variable product.
		$variations = array();
		if ( ! $variation_id ) {
			$rows  = array_filter( self::product_rows( $range, $product_id, 0, true ), static fn( $row ) => (int) $row['grouping_key'] > 0 );
			$names = self::product_names( array_map( static fn( $row ) => array( 'product_id' => $product_id, 'variation_id' => (int) $row['grouping_key'] ), $rows ) );
			foreach ( $rows as $row ) {
				$variations[] = array_merge(
					array(
						'variation_id' => (int) $row['grouping_key'],
						'name'         => $names[ (int) $row['grouping_key'] . ':attributes' ] ?? '#' . (int) $row['grouping_key'],
					),
					self::product_totals( array( $row ) )
				);
			}
			usort( $variations, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );
		}

		// Product details.
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$parent  = $variation_id ? wc_get_product( $product_id ) : $product;
		$thumb   = $product ? get_the_post_thumbnail_url( $product->get_id(), 'medium' ) : '';
		if ( ! $thumb && $parent ) {
			$thumb = get_the_post_thumbnail_url( $parent->get_id(), 'medium' );
		}

		return array(
			'product'     => array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'name'         => $parent ? wp_specialchars_decode( $parent->get_name(), ENT_QUOTES ) : sprintf( /* translators: %d: product ID. */ __( 'Deleted product #%d', 'kdna-ecommerce-insights' ), $product_id ),
				'variation'    => $variation_id && $product ? wc_get_formatted_variation( $product, true, false ) : '',
				'sku'          => $product ? (string) $product->get_sku() : '',
				'price'        => $product && '' !== $product->get_price() ? (float) wc_get_price_excluding_tax( $product ) : null,
				'cost'         => $product ? KDNA_EcommerceInsights_Costs::get_effective_cost( $product ) : null,
				'stock_status' => $product ? $product->get_stock_status() : '',
				'stock'        => $product && $product->managing_stock() ? $product->get_stock_quantity() : null,
				'thumbnail'    => $thumb ? $thumb : '',
				'edit_url'     => (string) get_edit_post_link( $product_id, 'raw' ),
				'view_url'     => $parent ? (string) $parent->get_permalink() : '',
			),
			'totals'      => self::product_totals( $days ),
			'previous'    => $compare ? self::product_totals( self::product_rows( $compare, $product_id, $variation_id ) ) : null,
			'granularity' => $granularity,
			'buckets'     => $buckets,
			'series'      => $series,
			'variations'  => $variations,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Customers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Customer figures: period totals, lifetime metrics, top customers by
	 * profit, monthly cohort retention and location breakdown.
	 *
	 * @param array      $range    Range.
	 * @param array|null $compare  Comparison range.
	 * @return array
	 */
	public static function customers( array $range, ?array $compare ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();
		$facts                 = self::t( 'order_facts' );

		// Lifetime figures per customer, from every counted order.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$lifetime = KDNA_EcommerceInsights_Cache::timed(
			'Lifetime totals per customer',
			static fn() => $wpdb->get_results(
				$wpdb->prepare( "SELECT customer_key, COUNT(*) AS orders, SUM( net_revenue ) AS revenue, MIN( report_date ) AS first_day, MAX( report_date ) AS last_day FROM {$facts} WHERE status IN ( $in ) GROUP BY customer_key", $statuses ),
				ARRAY_A
			)
		);
		// phpcs:enable

		$all = array(
			'customers_all'        => 0,
			'repeat_customers_all' => 0,
			'orders_all'           => 0,
			'revenue_all'          => 0.0,
		);
		$gap_total = 0.0;
		$gap_count = 0;
		foreach ( (array) $lifetime as $row ) {
			++$all['customers_all'];
			$all['orders_all']  += (int) $row['orders'];
			$all['revenue_all'] += (float) $row['revenue'];
			if ( (int) $row['orders'] > 1 ) {
				++$all['repeat_customers_all'];
				$gap_total += ( strtotime( $row['last_day'] ) - strtotime( $row['first_day'] ) ) / DAY_IN_SECONDS / ( (int) $row['orders'] - 1 );
				++$gap_count;
			}
		}
		$all['days_between_orders'] = $gap_count ? round( $gap_total / $gap_count, 1 ) : null;

		$totals   = array_merge( self::totals( $range ), $all );
		$previous = $compare ? array_merge( self::totals( $compare ), $all ) : null;

		$metric_keys = array( 'customers', 'new_customers', 'returning_customers', 'new_customer_revenue', 'returning_customer_revenue', 'repeat_purchase_rate', 'average_lifetime_value', 'average_orders_per_customer', 'time_between_orders' );
		$metrics     = array();
		foreach ( $metric_keys as $key ) {
			$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $previous );
		}

		return array(
			'metrics'       => $metrics,
			'top_customers' => self::top_customers( $range ),
			'cohorts'       => self::cohorts( $range ),
			'locations'     => self::locations( $range ),
		);
	}

	/**
	 * The 25 customers who brought in the most contribution profit in a
	 * range, with their lifetime orders and when they first ordered. Guests
	 * are matched by billing email, so a repeat guest is one customer.
	 *
	 * @param array $range Range.
	 * @return array[]
	 */
	private static function top_customers( array $range ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();
		$facts                 = self::t( 'order_facts' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Top customers',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT customer_key, MAX( customer_id ) AS customer_id, COUNT(*) AS orders, SUM( net_revenue ) AS revenue, SUM( contribution_profit ) AS profit, MAX( order_id ) AS last_order_id FROM {$facts} WHERE report_date BETWEEN %s AND %s AND status IN ( $in ) GROUP BY customer_key ORDER BY profit DESC LIMIT 25",
					array_merge( array( $range['start'], $range['end'] ), $statuses )
				),
				ARRAY_A
			)
		);

		$keys     = wp_list_pluck( (array) $rows, 'customer_key' );
		$lifetime = array();
		if ( $keys ) {
			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT customer_key, COUNT(*) AS orders, SUM( net_revenue ) AS revenue, MIN( report_date ) AS first_day FROM {$facts} WHERE status IN ( $in ) AND customer_key IN ( $placeholders ) GROUP BY customer_key", array_merge( $statuses, $keys ) ), ARRAY_A ) as $row ) {
				$lifetime[ $row['customer_key'] ] = $row;
			}
		}
		// phpcs:enable

		$list = array();
		foreach ( (array) $rows as $row ) {
			$order  = wc_get_order( (int) $row['last_order_id'] );
			$name   = $order ? trim( $order->get_formatted_billing_full_name() ) : '';
			$life   = $lifetime[ $row['customer_key'] ] ?? array();
			$list[] = array(
				'name'             => '' !== $name ? $name : __( 'Guest', 'kdna-ecommerce-insights' ),
				'email'            => $order ? (string) $order->get_billing_email() : '',
				'guest'            => ! (int) $row['customer_id'],
				'orders'           => (int) $row['orders'],
				'revenue'          => round( (float) $row['revenue'], 2 ),
				'profit'           => round( (float) $row['profit'], 2 ),
				'lifetime_orders'  => (int) ( $life['orders'] ?? $row['orders'] ),
				'lifetime_revenue' => round( (float) ( $life['revenue'] ?? $row['revenue'] ), 2 ),
				'first_order'      => (string) ( $life['first_day'] ?? '' ),
				'profile_url'      => (int) $row['customer_id'] ? (string) get_edit_user_link( (int) $row['customer_id'] ) : '',
				'last_order_url'   => $order ? (string) $order->get_edit_order_url() : '',
			);
		}
		return $list;
	}

	/**
	 * Monthly cohort retention: for customers whose first ever order was in
	 * a month, the share who ordered in each month after it. Covers the
	 * months in the chosen range, up to the 12 most recent, and counts
	 * repeat orders up to the end of the range (or today, if sooner).
	 *
	 * @param array $range Range.
	 * @return array[] Each: month (Y-m), size, retention (percentages by
	 *                 months since, starting with the first month at 100).
	 */
	public static function cohorts( array $range ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();
		$facts                 = self::t( 'order_facts' );

		$last_month  = substr( min( $range['end'], self::today() ), 0, 7 );
		$first_month = max( substr( $range['start'], 0, 7 ), ( new DateTimeImmutable( $last_month . '-01' ) )->modify( '-11 months' )->format( 'Y-m' ) );
		$until       = ( new DateTimeImmutable( $last_month . '-01' ) )->modify( 'last day of this month' )->format( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Cohort months',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT f.customer_key, SUBSTR( f.report_date, 1, 7 ) AS month, firsts.first_month
					FROM {$facts} f
					INNER JOIN ( SELECT customer_key, SUBSTR( MIN( report_date ), 1, 7 ) AS first_month FROM {$facts} WHERE status IN ( $in ) GROUP BY customer_key ) firsts ON firsts.customer_key = f.customer_key
					WHERE f.status IN ( $in ) AND firsts.first_month BETWEEN %s AND %s AND f.report_date <= %s
					GROUP BY f.customer_key, SUBSTR( f.report_date, 1, 7 ), firsts.first_month",
					array_merge( $statuses, $statuses, array( $first_month, $last_month, $until ) )
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$cohorts = array();
		foreach ( (array) $rows as $row ) {
			$first = (string) $row['first_month'];
			$gap   = self::months_between( $first, (string) $row['month'] );
			$cohorts[ $first ]['customers'][ $row['customer_key'] ] = true;
			$cohorts[ $first ]['active'][ $gap ][ $row['customer_key'] ] = true;
		}
		ksort( $cohorts );

		$result = array();
		foreach ( $cohorts as $month => $data ) {
			$size      = count( $data['customers'] );
			$span      = self::months_between( $month, $last_month );
			$retention = array();
			for ( $i = 0; $i <= $span; $i++ ) {
				$retention[] = $size ? round( count( $data['active'][ $i ] ?? array() ) / $size * 100, 1 ) : 0;
			}
			$result[] = array(
				'month'     => $month,
				'size'      => $size,
				'retention' => $retention,
			);
		}
		return $result;
	}

	/**
	 * Whole months between two Y-m months.
	 *
	 * @param string $from Y-m.
	 * @param string $to   Y-m.
	 * @return int
	 */
	private static function months_between( string $from, string $to ): int {
		list( $fy, $fm ) = array_map( 'intval', explode( '-', $from ) );
		list( $ty, $tm ) = array_map( 'intval', explode( '-', $to ) );
		return max( 0, ( $ty - $fy ) * 12 + ( $tm - $fm ) );
	}

	/**
	 * Revenue and orders by country and state for a range.
	 *
	 * @param array $range Range.
	 * @return array[]
	 */
	private static function locations( array $range ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Locations',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT country, state, COUNT(*) AS orders, SUM( net_revenue ) AS revenue, COUNT( DISTINCT customer_key ) AS customers FROM ' . self::t( 'order_facts' ) . " WHERE report_date BETWEEN %s AND %s AND status IN ( $in ) GROUP BY country, state ORDER BY revenue DESC LIMIT 50",
					array_merge( array( $range['start'], $range['end'] ), $statuses )
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		$states    = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states() : array();

		return array_map(
			static function ( $row ) use ( $countries, $states ) {
				return array(
					'country'      => (string) $row['country'],
					'country_name' => html_entity_decode( $countries[ $row['country'] ] ?? (string) $row['country'], ENT_QUOTES ),
					'state'        => (string) $row['state'],
					'state_name'   => html_entity_decode( $states[ $row['country'] ][ $row['state'] ] ?? (string) $row['state'], ENT_QUOTES ),
					'orders'       => (int) $row['orders'],
					'customers'    => (int) $row['customers'],
					'revenue'      => round( (float) $row['revenue'], 2 ),
				);
			},
			(array) $rows
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Inventory
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Stock status counts, stock values, days of stock left, dead stock and
	 * the stock value trend. The work is done by the Inventory class.
	 *
	 * @param array|null $range Range for the stock value trend.
	 * @return array
	 */
	public static function inventory( ?array $range = null ): array {
		return KDNA_EcommerceInsights_Inventory::report( $range );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Marketing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Ad spend by channel and campaign, with ROAS per channel, the blended
	 * marketing metrics and spend by channel over time. Spend is always
	 * shown with claimable GST taken off, the same as in net profit.
	 *
	 * @param array      $range   Range.
	 * @param array|null $compare Comparison range.
	 * @return array
	 */
	public static function marketing( array $range, ?array $compare ): array {
		global $wpdb;
		$table = self::t( 'ad_spend' );
		$net   = KDNA_EcommerceInsights_Ad_Spend::net_spend_sql();
		$sums  = "SUM( {$net} ) AS spend, SUM( impressions ) AS impressions, SUM( clicks ) AS clicks, SUM( conversions ) AS conversions, SUM( conversion_value ) AS conversion_value";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$channels  = KDNA_EcommerceInsights_Cache::timed(
			'Ad spend by channel',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT channel, {$sums} FROM {$table} WHERE spend_date BETWEEN %s AND %s GROUP BY channel ORDER BY spend DESC", $range['start'], $range['end'] ), ARRAY_A )
		);
		$campaigns = KDNA_EcommerceInsights_Cache::timed(
			'Ad spend by campaign',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT channel, campaign_id, campaign_name, {$sums} FROM {$table} WHERE spend_date BETWEEN %s AND %s GROUP BY channel, campaign_id, campaign_name ORDER BY spend DESC LIMIT 200", $range['start'], $range['end'] ), ARRAY_A )
		);
		$daily     = KDNA_EcommerceInsights_Cache::timed(
			'Ad spend by day and channel',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT spend_date AS day, channel, SUM( {$net} ) AS spend FROM {$table} WHERE spend_date BETWEEN %s AND %s GROUP BY spend_date, channel", $range['start'], $range['end'] ), ARRAY_A )
		);
		// phpcs:enable

		$labels = self::channel_labels();
		$shape  = static function ( $row ) use ( $labels ) {
			$spend = (float) $row['spend'];
			$value = (float) $row['conversion_value'];
			return array_merge(
				$row,
				array(
					'label'            => $labels[ $row['channel'] ] ?? ucfirst( (string) $row['channel'] ),
					'spend'            => round( $spend, 2 ),
					'impressions'      => (int) $row['impressions'],
					'clicks'           => (int) $row['clicks'],
					'conversions'      => round( (float) $row['conversions'], 2 ),
					'conversion_value' => round( $value, 2 ),
					'roas'             => $spend > 0 && $value > 0 ? round( $value / $spend, 2 ) : null,
					'cpc'              => $spend > 0 && (float) $row['clicks'] > 0 ? round( $spend / (float) $row['clicks'], 2 ) : null,
					'cpa'              => $spend > 0 && (float) $row['conversions'] > 0 ? round( $spend / (float) $row['conversions'], 2 ) : null,
				)
			);
		};

		$totals   = self::totals( $range );
		$previous = $compare ? self::totals( $compare ) : null;
		$metrics  = array();
		foreach ( array( 'ad_spend', 'roas', 'mer', 'cpa', 'profit_after_ads', 'new_customers', 'net_revenue', 'net_profit' ) as $key ) {
			$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $previous );
		}

		// Spend per channel in each day, week or month of the range.
		$granularity = KDNA_EcommerceInsights_Dates::auto_granularity( $range );
		$buckets     = KDNA_EcommerceInsights_Dates::buckets( $range, $granularity );
		$day_bucket  = array();
		foreach ( $buckets as $index => $bucket ) {
			$day = new DateTimeImmutable( $bucket['start'] );
			$end = new DateTimeImmutable( $bucket['end'] );
			while ( $day <= $end ) {
				$day_bucket[ $day->format( 'Y-m-d' ) ] = $index;
				$day                                    = $day->modify( '+1 day' );
			}
		}
		$series = array();
		foreach ( (array) $daily as $row ) {
			if ( ! isset( $series[ $row['channel'] ] ) ) {
				$series[ $row['channel'] ] = array_fill( 0, count( $buckets ), 0.0 );
			}
			if ( isset( $day_bucket[ $row['day'] ] ) ) {
				$series[ $row['channel'] ][ $day_bucket[ $row['day'] ] ] += (float) $row['spend'];
			}
		}
		$series = array_map( static fn( $values ) => array_map( static fn( $v ) => round( $v, 2 ), $values ), $series );

		return array(
			'metrics'     => $metrics,
			'channels'    => array_map( $shape, (array) $channels ),
			'campaigns'   => array_map( $shape, (array) $campaigns ),
			'granularity' => $granularity,
			'buckets'     => $buckets,
			'series'      => $series,
			'gst_rate'    => KDNA_EcommerceInsights_Ad_Spend::claimable_tax_rate(),
		);
	}

	/**
	 * Channel names from Settings > Marketing, keyed by channel key.
	 *
	 * @return array<string, string>
	 */
	public static function channel_labels(): array {
		$labels = array();
		foreach ( (array) KDNA_EcommerceInsights_Settings::get( 'marketing.channels', array() ) as $channel ) {
			$labels[ $channel['key'] ] = $channel['label'];
		}
		return $labels;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Tax
	 * ---------------------------------------------------------------------
	 */

	/**
	 * GST, VAT or sales tax summary by month or quarter. The work is done
	 * by KDNA_EcommerceInsights_Tax; this stays for older callers.
	 *
	 * @param array  $range  Range.
	 * @param string $period monthly or quarterly. Empty uses Settings > Tax.
	 * @return array
	 */
	public static function tax( array $range, string $period = '' ): array {
		return KDNA_EcommerceInsights_Tax::summary( $range, $period );
	}
}
