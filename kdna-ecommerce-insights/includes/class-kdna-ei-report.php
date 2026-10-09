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

			foreach ( $metrics as $metric ) {
				$series[ $metric ][] = KDNA_EcommerceInsights_Metrics::value( $metric, $totals );
			}
		}

		return array(
			'buckets' => $buckets,
			'series'  => $series,
		);
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
			'cohorts'       => self::cohorts(),
			'locations'     => self::locations( $range ),
		);
	}

	/**
	 * The ten customers who brought in the most contribution profit in a range.
	 *
	 * @param array $range Range.
	 * @return array[]
	 */
	private static function top_customers( array $range ): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Top customers',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT customer_key, COUNT(*) AS orders, SUM( net_revenue ) AS revenue, SUM( contribution_profit ) AS profit, MAX( order_id ) AS last_order_id FROM ' . self::t( 'order_facts' ) . " WHERE report_date BETWEEN %s AND %s AND status IN ( $in ) GROUP BY customer_key ORDER BY profit DESC LIMIT 10",
					array_merge( array( $range['start'], $range['end'] ), $statuses )
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$list = array();
		foreach ( (array) $rows as $row ) {
			$order  = wc_get_order( (int) $row['last_order_id'] );
			$name   = $order ? trim( $order->get_formatted_billing_full_name() ) : '';
			$list[] = array(
				'name'    => '' !== $name ? $name : __( 'Guest', 'kdna-ecommerce-insights' ),
				'email'   => $order ? (string) $order->get_billing_email() : '',
				'orders'  => (int) $row['orders'],
				'revenue' => round( (float) $row['revenue'], 2 ),
				'profit'  => round( (float) $row['profit'], 2 ),
			);
		}
		return $list;
	}

	/**
	 * Monthly cohort retention for the last 12 months: of the customers whose
	 * first order was in a month, the share who ordered again in each
	 * following month.
	 *
	 * @return array[] Each: month (Y-m), size, retention (percentages by months since).
	 */
	private static function cohorts(): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();
		$facts                 = self::t( 'order_facts' );
		$from                  = ( new DateTimeImmutable( 'first day of this month', wp_timezone() ) )->modify( '-11 months' )->format( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Cohort months',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT f.customer_key, SUBSTR( f.report_date, 1, 7 ) AS month, firsts.first_month
					FROM {$facts} f
					INNER JOIN ( SELECT customer_key, SUBSTR( MIN( report_date ), 1, 7 ) AS first_month FROM {$facts} WHERE status IN ( $in ) GROUP BY customer_key ) firsts ON firsts.customer_key = f.customer_key
					WHERE f.status IN ( $in ) AND firsts.first_month >= %s
					GROUP BY f.customer_key, SUBSTR( f.report_date, 1, 7 ), firsts.first_month",
					array_merge( $statuses, $statuses, array( substr( $from, 0, 7 ) ) )
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

		$this_month = substr( self::today(), 0, 7 );
		$result     = array();
		foreach ( $cohorts as $month => $data ) {
			$size      = count( $data['customers'] );
			$span      = self::months_between( $month, $this_month );
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
	 * Stock status counts, stock values, days of stock left based on the
	 * last 30 days of sales, dead stock and the stock value trend.
	 *
	 * @return array
	 */
	public static function inventory(): array {
		global $wpdb;
		list( $in, $statuses ) = self::statuses();

		$threshold = (int) KDNA_EcommerceInsights_Settings::get( 'alerts.low_stock_threshold', 0 );
		$threshold = $threshold > 0 ? $threshold : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$dead_days = (int) KDNA_EcommerceInsights_Settings::get( 'alerts.dead_stock_days', 90 );
		$today     = new DateTimeImmutable( 'today', wp_timezone() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sales = KDNA_EcommerceInsights_Cache::timed(
			'Units sold per product, last 30 days and last sale',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT CASE WHEN i.variation_id > 0 THEN i.variation_id ELSE i.product_id END AS id,
						SUM( CASE WHEN f.report_date >= %s THEN i.qty - i.refunded_qty ELSE 0 END ) AS sold_30,
						MAX( f.report_date ) AS last_sale
					FROM ' . self::t( 'order_item_facts' ) . ' i INNER JOIN ' . self::t( 'order_facts' ) . " f ON f.order_id = i.order_id
					WHERE f.status IN ( $in )
					GROUP BY CASE WHEN i.variation_id > 0 THEN i.variation_id ELSE i.product_id END",
					array_merge( array( $today->modify( '-29 days' )->format( 'Y-m-d' ) ), $statuses )
				),
				ARRAY_A
			)
		);
		$trend = KDNA_EcommerceInsights_Cache::timed(
			'Stock value trend',
			static fn() => $wpdb->get_results( $wpdb->prepare( 'SELECT snapshot_date AS day, SUM( value_at_cost ) AS cost, SUM( value_at_retail ) AS retail FROM ' . self::t( 'stock_snapshots' ) . ' WHERE snapshot_date >= %s GROUP BY snapshot_date ORDER BY snapshot_date', $today->modify( '-89 days' )->format( 'Y-m-d' ) ), ARRAY_A )
		);
		// phpcs:enable

		$sold = array();
		foreach ( (array) $sales as $row ) {
			$sold[ (int) $row['id'] ] = array(
				'sold_30'   => max( 0, (float) $row['sold_30'] ),
				'last_sale' => (string) $row['last_sale'],
			);
		}

		$totals = array_fill_keys( array( 'units_in_stock', 'stock_value_cost', 'stock_value_retail', 'in_stock', 'low_stock', 'out_of_stock', 'dead_stock', 'on_backorder' ), 0.0 );
		$low    = array();
		$out    = array();
		$cover  = array();
		$dead   = array();

		$rows = KDNA_EcommerceInsights_Cache::timed( 'Product stock from the catalogue', array( 'KDNA_EcommerceInsights_Cost_Catalogue', 'sellable_rows' ) );
		foreach ( $rows as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}

			$stock   = null === $row['stock'] ? null : (float) $row['stock'];
			$item    = array(
				'id'        => $row['id'],
				'name'      => $row['name'] . ( '' !== $row['attributes'] ? ' (' . $row['attributes'] . ')' : '' ),
				'sku'       => $row['sku'],
				'stock'     => $stock,
				'sold_30'   => $sold[ $row['id'] ]['sold_30'] ?? 0.0,
				'last_sale' => $sold[ $row['id'] ]['last_sale'] ?? '',
			);

			if ( 'outofstock' === $row['stock_status'] ) {
				++$totals['out_of_stock'];
				$out[] = $item;
				continue;
			}
			if ( 'onbackorder' === $row['stock_status'] ) {
				++$totals['on_backorder'];
			}

			if ( null !== $stock && $row['manage_stock'] ) {
				$units                         = max( 0, $stock );
				$totals['units_in_stock']     += $units;
				$totals['stock_value_cost']   += $units * (float) $row['effective_cost'];
				$totals['stock_value_retail'] += $units * (float) $row['price'];

				if ( $stock <= $threshold ) {
					++$totals['low_stock'];
					$low[] = $item;
				} else {
					++$totals['in_stock'];
				}

				// Days of stock left at the last 30 days' selling speed.
				if ( $item['sold_30'] > 0 && $units > 0 ) {
					$days            = (int) floor( $units / ( $item['sold_30'] / 30 ) );
					$item['days']    = $days;
					$item['runs_out'] = $today->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
					// Suggest reordering two weeks before stock runs out.
					$item['reorder'] = $today->modify( '+' . max( 0, $days - 14 ) . ' days' )->format( 'Y-m-d' );
					$cover[]         = $item;
				}
			} else {
				++$totals['in_stock'];
			}

			// Dead stock: in stock but no sale within the set number of days.
			$in_stock = null === $stock ? 'instock' === $row['stock_status'] : $stock > 0;
			if ( $in_stock && ( '' === $item['last_sale'] || $item['last_sale'] < $today->modify( '-' . $dead_days . ' days' )->format( 'Y-m-d' ) ) ) {
				++$totals['dead_stock'];
				$dead[] = $item;
			}
		}

		usort( $cover, static fn( $a, $b ) => $a['days'] <=> $b['days'] );
		usort( $low, static fn( $a, $b ) => $a['stock'] <=> $b['stock'] );

		$metrics = array();
		foreach ( array( 'units_in_stock', 'stock_value_cost', 'stock_value_retail', 'in_stock', 'low_stock', 'out_of_stock', 'dead_stock' ) as $key ) {
			$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals );
		}

		return array(
			'metrics'       => $metrics,
			'status'        => array(
				'in_stock'     => (int) $totals['in_stock'],
				'low_stock'    => (int) $totals['low_stock'],
				'out_of_stock' => (int) $totals['out_of_stock'],
			),
			'threshold'     => $threshold,
			'low_stock'     => array_slice( $low, 0, 50 ),
			'out_of_stock'  => array_slice( $out, 0, 50 ),
			'days_of_cover' => array_slice( $cover, 0, 50 ),
			'dead_stock'    => array_slice( $dead, 0, 50 ),
			'dead_days'     => $dead_days,
			'trend'         => array_map(
				static fn( $row ) => array(
					'day'    => $row['day'],
					'cost'   => round( (float) $row['cost'], 2 ),
					'retail' => round( (float) $row['retail'], 2 ),
				),
				(array) $trend
			),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Marketing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Ad spend by channel and campaign, with ROAS per channel and the
	 * blended marketing metrics.
	 *
	 * @param array      $range   Range.
	 * @param array|null $compare Comparison range.
	 * @return array
	 */
	public static function marketing( array $range, ?array $compare ): array {
		global $wpdb;
		$table = self::t( 'ad_spend' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$channels  = KDNA_EcommerceInsights_Cache::timed(
			'Ad spend by channel',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT channel, SUM( spend ) AS spend, SUM( impressions ) AS impressions, SUM( clicks ) AS clicks, SUM( conversions ) AS conversions, SUM( conversion_value ) AS conversion_value FROM {$table} WHERE spend_date BETWEEN %s AND %s GROUP BY channel ORDER BY spend DESC", $range['start'], $range['end'] ), ARRAY_A )
		);
		$campaigns = KDNA_EcommerceInsights_Cache::timed(
			'Ad spend by campaign',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT channel, campaign_id, campaign_name, SUM( spend ) AS spend, SUM( impressions ) AS impressions, SUM( clicks ) AS clicks, SUM( conversions ) AS conversions, SUM( conversion_value ) AS conversion_value FROM {$table} WHERE spend_date BETWEEN %s AND %s GROUP BY channel, campaign_id, campaign_name ORDER BY spend DESC LIMIT 100", $range['start'], $range['end'] ), ARRAY_A )
		);
		$daily     = KDNA_EcommerceInsights_Cache::timed(
			'Ad spend by day and channel',
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT spend_date AS day, channel, SUM( spend ) AS spend FROM {$table} WHERE spend_date BETWEEN %s AND %s GROUP BY spend_date, channel", $range['start'], $range['end'] ), ARRAY_A )
		);
		// phpcs:enable

		$labels = array();
		foreach ( (array) KDNA_EcommerceInsights_Settings::get( 'marketing.channels', array() ) as $channel ) {
			$labels[ $channel['key'] ] = $channel['label'];
		}

		$shape = static function ( $row ) use ( $labels ) {
			$spend = (float) $row['spend'];
			return array_merge(
				$row,
				array(
					'label'            => $labels[ $row['channel'] ] ?? ucfirst( (string) $row['channel'] ),
					'spend'            => round( $spend, 2 ),
					'impressions'      => (int) $row['impressions'],
					'clicks'           => (int) $row['clicks'],
					'conversions'      => round( (float) $row['conversions'], 2 ),
					'conversion_value' => round( (float) $row['conversion_value'], 2 ),
					'roas'             => null === KDNA_EcommerceInsights_Metrics::divide( (float) $row['conversion_value'], $spend ) ? null : round( (float) $row['conversion_value'] / $spend, 2 ),
					'cpc'              => null === KDNA_EcommerceInsights_Metrics::divide( $spend, (float) $row['clicks'] ) ? null : round( $spend / (float) $row['clicks'], 2 ),
				)
			);
		};

		$totals   = self::totals( $range );
		$previous = $compare ? self::totals( $compare ) : null;
		$metrics  = array();
		foreach ( array( 'ad_spend', 'roas', 'mer', 'cpa', 'profit_after_ads', 'new_customers' ) as $key ) {
			$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $previous );
		}

		$series = array();
		foreach ( (array) $daily as $row ) {
			$series[ $row['channel'] ][ $row['day'] ] = round( (float) $row['spend'], 2 );
		}

		return array(
			'metrics'   => $metrics,
			'channels'  => array_map( $shape, (array) $channels ),
			'campaigns' => array_map( $shape, (array) $campaigns ),
			'daily'     => $series,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Tax
	 * ---------------------------------------------------------------------
	 */

	/**
	 * GST or VAT summary by month or quarter (Settings > Tax), with the
	 * BAS-style lines for Australian stores.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public static function tax( array $range ): array {
		$period  = (string) KDNA_EcommerceInsights_Settings::get( 'tax.reporting_period', 'quarterly' );
		$buckets = 'monthly' === $period ? KDNA_EcommerceInsights_Dates::buckets( $range, 'month' ) : self::quarters( $range );
		$keys    = array( 'bas_g1', 'bas_1a', 'bas_1b', 'net_gst' );
		$rows    = array();

		foreach ( $buckets as $bucket ) {
			$totals = self::totals( $bucket );
			$line   = array(
				'start' => $bucket['start'],
				'end'   => $bucket['end'],
			);
			foreach ( $keys as $key ) {
				$line[ $key ] = round( (float) KDNA_EcommerceInsights_Metrics::value( $key, $totals ), 2 );
			}
			$rows[] = $line;
		}

		$totals = self::totals( $range );
		$sum    = array();
		foreach ( $keys as $key ) {
			$sum[ $key ] = round( (float) KDNA_EcommerceInsights_Metrics::value( $key, $totals ), 2 );
		}

		return array(
			'system'  => KDNA_EcommerceInsights_Settings::get( 'tax.system', 'none' ),
			'rate'    => (float) KDNA_EcommerceInsights_Settings::get( 'tax.rate', 0 ),
			'period'  => $period,
			'periods' => $rows,
			'totals'  => $sum,
			'note'    => __( 'A guide for your bookkeeper, not a lodgement. GST paid on costs is an estimate from overheads and ad spend marked as including GST.', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Splits a range into calendar quarters, trimmed to the range.
	 *
	 * @param array $range Range.
	 * @return array[]
	 */
	private static function quarters( array $range ): array {
		$quarters = array();
		$cursor   = new DateTimeImmutable( $range['start'] );
		$end      = new DateTimeImmutable( $range['end'] );
		while ( $cursor <= $end ) {
			$month       = (int) floor( ( (int) $cursor->format( 'n' ) - 1 ) / 3 ) * 3 + 1;
			$q_end       = $cursor->setDate( (int) $cursor->format( 'Y' ), $month, 1 )->modify( '+3 months -1 day' );
			$q_end       = min( $q_end, $end );
			$quarters[]  = array(
				'start' => $cursor->format( 'Y-m-d' ),
				'end'   => $q_end->format( 'Y-m-d' ),
			);
			$cursor      = $q_end->modify( '+1 day' );
		}
		return $quarters;
	}
}
