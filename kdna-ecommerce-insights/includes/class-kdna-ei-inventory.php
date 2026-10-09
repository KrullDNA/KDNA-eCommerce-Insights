<?php
/**
 * Stock: figures, nightly snapshots, days of stock left and low stock emails.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything about stock in one place.
 *
 * - Stock items: every product or variation that holds its own stock, with
 *   variations that share their parent's stock counted once under the parent.
 * - Report: status counts, stock value at cost and at retail (excluding tax),
 *   low and out of stock lists, days of stock left at the last 30 days'
 *   selling speed, suggested reorder dates, dead stock and the value trend.
 * - Snapshots: once a night (after the nightly check) each item's stock and
 *   value is saved to stock_snapshots, which draws the stock value trend.
 * - Alerts: an optional email when products become low or out of stock,
 *   bundled so a busy morning sends one email rather than dozens.
 */
class KDNA_EcommerceInsights_Inventory {

	/**
	 * Action Scheduler hook that sends low stock emails.
	 */
	const ALERT_HOOK = 'kdna_ei_stock_alerts';

	/**
	 * Option remembering which items were already in the last alert email.
	 */
	const ALERTED_OPTION = 'kdna_ei_stock_alerted';

	/**
	 * Days of sales used to work out how fast each item sells.
	 */
	const SPEED_DAYS = 30;

	/**
	 * Connects snapshots and alerts to WordPress and WooCommerce.
	 */
	public function __construct() {
		add_action( 'kdna_ei_nightly_done', array( __CLASS__, 'nightly' ) );
		add_action( self::ALERT_HOOK, array( __CLASS__, 'send_alerts' ) );

		// WooCommerce says when an order takes a product to its low stock
		// amount or to zero. Queue one bundled check a few minutes later.
		add_action( 'woocommerce_low_stock', array( __CLASS__, 'queue_alerts' ) );
		add_action( 'woocommerce_no_stock', array( __CLASS__, 'queue_alerts' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Stock items
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The low stock threshold for an item: the Insights setting if one is
	 * set, otherwise the product's own low stock amount, otherwise the
	 * WooCommerce store-wide amount.
	 *
	 * @param float|null $own The product's own low stock amount.
	 * @return float
	 */
	public static function threshold( ?float $own ): float {
		$override = (int) KDNA_EcommerceInsights_Settings::get( 'alerts.low_stock_threshold', 0 );
		if ( $override > 0 ) {
			return (float) $override;
		}
		if ( null !== $own ) {
			return $own;
		}
		return (float) get_option( 'woocommerce_notify_low_stock_amount', 2 );
	}

	/**
	 * Every published product or variation that holds stock, from the
	 * product catalogue. Variations that share their parent's stock are
	 * counted once, under the parent.
	 *
	 * @return array[] Each: id, product_id, variation_id, name, sku, managed,
	 *                 stock, status, cost, retail, threshold, members (IDs
	 *                 whose sales count towards this item).
	 */
	public static function items(): array {
		$items = array();

		foreach ( KDNA_EcommerceInsights_Cost_Catalogue::index() as $parent ) {
			if ( 'publish' !== $parent['status'] ) {
				continue;
			}

			if ( $parent['sellable'] ) {
				$items[] = self::item( $parent, (int) $parent['id'], 0, array( (int) $parent['id'] ) );
			}

			$shared = array();
			foreach ( $parent['children'] as $child ) {
				if ( 'publish' !== $child['status'] ) {
					continue;
				}
				if ( (int) $child['stock_owner'] === (int) $parent['id'] ) {
					$shared[] = $child;
					continue;
				}
				$items[] = self::item( $child, (int) $parent['id'], (int) $child['id'], array( (int) $child['id'] ) );
			}

			// Variations sharing the parent's stock: one item for the parent.
			if ( $shared ) {
				$item = self::item( $parent, (int) $parent['id'], 0, array_merge( array( (int) $parent['id'] ), array_map( static fn( $c ) => (int) $c['id'], $shared ) ) );

				$costs  = array_filter( array_map( static fn( $c ) => $c['effective_cost'], $shared ), static fn( $v ) => null !== $v );
				$prices = array_filter( array_map( static fn( $c ) => $c['price_net'], $shared ), static fn( $v ) => null !== $v );

				$item['managed'] = true;
				$item['stock']   = null === $parent['stock'] ? 0.0 : (float) $parent['stock'];
				$item['cost']    = null !== $parent['effective_cost'] ? (float) $parent['effective_cost'] : ( $costs ? array_sum( $costs ) / count( $costs ) : null );
				$item['retail']  = $prices ? array_sum( $prices ) / count( $prices ) : null;
				$items[]         = $item;
			}
		}

		return $items;
	}

	/**
	 * Turns one catalogue row into a stock item.
	 *
	 * @param array $row          Catalogue row.
	 * @param int   $product_id   Parent product ID.
	 * @param int   $variation_id Variation ID, or 0.
	 * @param int[] $members      IDs whose sales count towards this item.
	 * @return array
	 */
	private static function item( array $row, int $product_id, int $variation_id, array $members ): array {
		$managed = (bool) $row['manage_stock'] && null !== $row['stock'];

		return array(
			'id'           => $variation_id ? $variation_id : $product_id,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'name'         => $row['name'] . ( '' !== $row['attributes'] ? ' (' . $row['attributes'] . ')' : '' ),
			'sku'          => (string) $row['sku'],
			'managed'      => $managed,
			'stock'        => $managed ? (float) $row['stock'] : null,
			'status'       => (string) $row['stock_status'],
			'cost'         => null === $row['effective_cost'] ? null : (float) $row['effective_cost'],
			'retail'       => null === $row['price_net'] ? null : (float) $row['price_net'],
			'threshold'    => self::threshold( $row['low_stock'] ?? null ),
			'members'      => $members,
		);
	}

	/**
	 * Units sold in the last 30 days (after refunds) and the date of the
	 * last sale, for every product and variation that has sold.
	 *
	 * @param string $today Y-m-d.
	 * @return array<int, array{sold: float, last_sale: string}> Keyed by product or variation ID.
	 */
	public static function sales( string $today ): array {
		global $wpdb;

		$statuses = KDNA_EcommerceInsights_Summary::counted_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$from     = ( new DateTimeImmutable( $today ) )->modify( '-' . ( self::SPEED_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Units sold per product, last 30 days and last sale',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT CASE WHEN i.variation_id > 0 THEN i.variation_id ELSE i.product_id END AS id,
						SUM( CASE WHEN f.report_date BETWEEN %s AND %s THEN i.qty - i.refunded_qty ELSE 0 END ) AS sold,
						MAX( f.report_date ) AS last_sale
					FROM ' . KDNA_EcommerceInsights_Install::table( 'order_item_facts' ) . ' i
					INNER JOIN ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . " f ON f.order_id = i.order_id
					WHERE f.status IN ( $in ) AND f.report_date <= %s
					GROUP BY CASE WHEN i.variation_id > 0 THEN i.variation_id ELSE i.product_id END",
					array_merge( array( $from, $today ), $statuses, array( $today ) )
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		$sales = array();
		foreach ( (array) $rows as $row ) {
			$sales[ (int) $row['id'] ] = array(
				'sold'      => max( 0.0, (float) $row['sold'] ),
				'last_sale' => (string) $row['last_sale'],
			);
		}
		return $sales;
	}

	/**
	 * Days of stock left at the current selling speed, the day stock runs
	 * out and the day to reorder (the supplier lead time before that).
	 *
	 * @param float  $stock     Units in stock.
	 * @param float  $sold      Units sold in the last 30 days.
	 * @param string $today     Y-m-d.
	 * @param int    $lead_days Supplier lead time in days.
	 * @return array{days: int|null, per_day: float, runs_out: string|null, reorder: string|null, reorder_now: bool}
	 */
	public static function cover( float $stock, float $sold, string $today, int $lead_days ): array {
		$per_day = $sold / self::SPEED_DAYS;
		if ( $per_day <= 0 || $stock <= 0 ) {
			return array(
				'days'        => $stock <= 0 ? 0 : null,
				'per_day'     => round( $per_day, 2 ),
				'runs_out'    => null,
				'reorder'     => null,
				'reorder_now' => $stock <= 0 && $per_day > 0,
			);
		}

		$days  = (int) floor( $stock / $per_day );
		$start = new DateTimeImmutable( $today );

		return array(
			'days'        => $days,
			'per_day'     => round( $per_day, 2 ),
			'runs_out'    => $start->modify( '+' . $days . ' days' )->format( 'Y-m-d' ),
			'reorder'     => $start->modify( '+' . max( 0, $days - $lead_days ) . ' days' )->format( 'Y-m-d' ),
			'reorder_now' => $days <= $lead_days,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Report
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The Inventory screen's figures. Stock is always "now"; the range only
	 * sets the dates of the stock value trend.
	 *
	 * @param array|null $range Range for the trend, or null for the last 90 days.
	 * @return array
	 */
	public static function report( ?array $range = null ): array {
		$today     = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
		$dead_days = (int) KDNA_EcommerceInsights_Settings::get( 'alerts.dead_stock_days', 90 );
		$lead_days = (int) KDNA_EcommerceInsights_Settings::get( 'alerts.reorder_lead_days', 14 );
		$dead_from = ( new DateTimeImmutable( $today ) )->modify( '-' . $dead_days . ' days' )->format( 'Y-m-d' );
		$sales     = self::sales( $today );

		$totals = array_fill_keys( array( 'units_in_stock', 'stock_value_cost', 'stock_value_retail', 'in_stock', 'low_stock', 'out_of_stock', 'dead_stock', 'on_backorder' ), 0.0 );
		$lists  = array(
			'low_stock'     => array(),
			'out_of_stock'  => array(),
			'days_of_cover' => array(),
			'dead_stock'    => array(),
		);
		$no_cost = 0;

		foreach ( KDNA_EcommerceInsights_Cache::timed( 'Stock items from the catalogue', array( __CLASS__, 'items' ) ) as $item ) {
			// Sales of everything that shares this stock.
			$sold      = 0.0;
			$last_sale = '';
			foreach ( $item['members'] as $member ) {
				$sold     += $sales[ $member ]['sold'] ?? 0.0;
				$last_sale = max( $last_sale, $sales[ $member ]['last_sale'] ?? '' );
			}

			$row = array(
				'id'           => $item['id'],
				'product_id'   => $item['product_id'],
				'variation_id' => $item['variation_id'],
				'name'         => $item['name'],
				'sku'          => $item['sku'],
				'stock'        => $item['stock'],
				'threshold'    => $item['threshold'],
				'sold_30'      => $sold,
				'last_sale'    => $last_sale,
				'cost'         => $item['cost'],
				'value_cost'   => null,
				'value_retail' => null,
			);

			$out = 'outofstock' === $item['status'] || ( $item['managed'] && $item['stock'] <= 0 && 'onbackorder' !== $item['status'] );
			if ( 'onbackorder' === $item['status'] ) {
				++$totals['on_backorder'];
			}

			if ( $item['managed'] ) {
				$units = max( 0.0, (float) $item['stock'] );

				$row['value_cost']   = null === $item['cost'] ? null : round( $units * $item['cost'], 2 );
				$row['value_retail'] = null === $item['retail'] ? null : round( $units * $item['retail'], 2 );
				$row                 = array_merge( $row, self::cover( $units, $sold, $today, $lead_days ) );

				$totals['units_in_stock']     += $units;
				$totals['stock_value_cost']   += (float) $row['value_cost'];
				$totals['stock_value_retail'] += (float) $row['value_retail'];
				if ( $units > 0 && null === $item['cost'] ) {
					++$no_cost;
				}
			}

			if ( $out ) {
				++$totals['out_of_stock'];
				$lists['out_of_stock'][] = $row;
				continue;
			}

			if ( $item['managed'] && $item['stock'] <= $item['threshold'] ) {
				++$totals['low_stock'];
				$lists['low_stock'][] = $row;
			} else {
				++$totals['in_stock'];
			}

			if ( $item['managed'] && null !== $row['days'] ) {
				$lists['days_of_cover'][] = $row;
			}

			// Dead stock: in stock, but no sale within the set number of days.
			if ( '' === $last_sale || $last_sale < $dead_from ) {
				++$totals['dead_stock'];
				$lists['dead_stock'][] = $row;
			}
		}

		usort( $lists['low_stock'], static fn( $a, $b ) => $a['stock'] <=> $b['stock'] );
		usort( $lists['out_of_stock'], static fn( $a, $b ) => $b['sold_30'] <=> $a['sold_30'] );
		usort( $lists['days_of_cover'], static fn( $a, $b ) => $a['days'] <=> $b['days'] );
		usort( $lists['dead_stock'], static fn( $a, $b ) => (float) $b['value_cost'] <=> (float) $a['value_cost'] );

		$metrics = array();
		foreach ( array( 'units_in_stock', 'stock_value_cost', 'stock_value_retail', 'in_stock', 'low_stock', 'out_of_stock', 'dead_stock' ) as $key ) {
			$metrics[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals );
		}

		$counts = array();
		foreach ( $lists as $key => $list ) {
			$counts[ $key ] = count( $list );
			$lists[ $key ]  = self::with_links( array_slice( $list, 0, 200 ) );
		}

		return array_merge(
			$lists,
			array(
				'metrics'   => $metrics,
				'status'    => array(
					'in_stock'     => (int) $totals['in_stock'],
					'low_stock'    => (int) $totals['low_stock'],
					'out_of_stock' => (int) $totals['out_of_stock'],
				),
				'counts'    => $counts,
				'dead_value' => round( array_sum( array_map( static fn( $r ) => (float) $r['value_cost'], $lists['dead_stock'] ) ), 2 ),
				'no_cost'   => $no_cost,
				'threshold' => (int) KDNA_EcommerceInsights_Settings::get( 'alerts.low_stock_threshold', 0 ),
				'store_threshold' => (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ),
				'dead_days' => $dead_days,
				'lead_days' => $lead_days,
				'trend'     => self::trend( $range ),
			)
		);
	}

	/**
	 * Adds thumbnails and edit links to listed items.
	 *
	 * @param array[] $rows Rows.
	 * @return array[]
	 */
	private static function with_links( array $rows ): array {
		foreach ( $rows as &$row ) {
			$thumb            = get_the_post_thumbnail_url( $row['id'], 'thumbnail' );
			$row['thumbnail'] = $thumb ? $thumb : (string) get_the_post_thumbnail_url( $row['product_id'], 'thumbnail' );
			$row['edit_url']  = (string) get_edit_post_link( $row['product_id'], 'raw' );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Total stock value at cost and retail per day from the snapshots.
	 *
	 * @param array|null $range Range, or null for the last 90 days.
	 * @return array[] Each: day, cost, retail, units.
	 */
	public static function trend( ?array $range = null ): array {
		global $wpdb;

		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$start = $range ? $range['start'] : $today->modify( '-89 days' )->format( 'Y-m-d' );
		$end   = $range ? min( $range['end'], $today->format( 'Y-m-d' ) ) : $today->format( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = KDNA_EcommerceInsights_Cache::timed(
			'Stock value trend',
			static fn() => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT snapshot_date AS day, SUM( value_at_cost ) AS cost, SUM( value_at_retail ) AS retail, SUM( stock_qty ) AS units FROM ' . KDNA_EcommerceInsights_Install::table( 'stock_snapshots' ) . ' WHERE snapshot_date BETWEEN %s AND %s GROUP BY snapshot_date ORDER BY snapshot_date',
					$start,
					$end
				),
				ARRAY_A
			)
		);
		// phpcs:enable

		return array_map(
			static fn( $row ) => array(
				'day'    => $row['day'],
				'cost'   => round( (float) $row['cost'], 2 ),
				'retail' => round( (float) $row['retail'], 2 ),
				'units'  => (float) $row['units'],
			),
			(array) $rows
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Snapshots
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Nightly work after the nightly check: today's snapshot, then any
	 * low stock email that is due.
	 */
	public static function nightly(): void {
		$count = self::snapshot();

		KDNA_EcommerceInsights_Log::add(
			'snapshot',
			'success',
			/* translators: %d: number of products. */
			sprintf( __( 'Stock snapshot saved for %d products.', 'kdna-ecommerce-insights' ), $count )
		);

		self::send_alerts();
	}

	/**
	 * Saves today's stock level and value for every item that tracks its
	 * stock, replacing any earlier snapshot for the same day. Writes in
	 * batches of 500 rows so large catalogues stay quick.
	 *
	 * @param string $date Y-m-d, or empty for today.
	 * @return int Items saved.
	 */
	public static function snapshot( string $date = '' ): int {
		global $wpdb;

		$date  = '' !== $date ? $date : ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
		$table = KDNA_EcommerceInsights_Install::table( 'stock_snapshots' );
		$rows  = array();

		foreach ( self::items() as $item ) {
			if ( ! $item['managed'] ) {
				continue;
			}
			$units  = max( 0.0, (float) $item['stock'] );
			$rows[] = $wpdb->prepare(
				'(%s, %d, %d, %f, %f, %f)',
				$date,
				$item['product_id'],
				$item['variation_id'],
				(float) $item['stock'],
				null === $item['cost'] ? 0 : round( $units * $item['cost'], 4 ),
				null === $item['retail'] ? 0 : round( $units * $item['retail'], 4 )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE snapshot_date = %s", $date ) );
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} ( snapshot_date, product_id, variation_id, stock_qty, value_at_cost, value_at_retail ) VALUES " . implode( ',', $chunk ) );
		}
		// phpcs:enable

		KDNA_EcommerceInsights_Cache::flush();
		return count( $rows );
	}

	/**
	 * Takes today's snapshot if there is none yet, so a new install has a
	 * starting point for the stock value trend straight away.
	 */
	public static function ensure_snapshot(): void {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'stock_snapshots' );
		$today = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE snapshot_date = %s LIMIT 1", $today ) ) ) {
			self::snapshot( $today );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Low stock emails
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Queues one bundled low stock check ten minutes from now, unless one
	 * is already waiting. Does nothing when the emails are switched off.
	 */
	public static function queue_alerts(): void {
		if ( ! KDNA_EcommerceInsights_Settings::get( 'alerts.low_stock_emails', false ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::ALERT_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP ) ) {
			as_schedule_single_action( time() + 10 * MINUTE_IN_SECONDS, self::ALERT_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
		}
	}

	/**
	 * Emails the alert recipients about products that have become low or
	 * out of stock since the last email. Products already reported are not
	 * repeated until they are restocked and run low again.
	 *
	 * @param bool $test Send now with every low and out of stock product,
	 *                   marked as a test, whether or not emails are on.
	 * @return array{sent: bool, items: int, message: string}
	 */
	public static function send_alerts( bool $test = false ): array {
		if ( ! $test && ! KDNA_EcommerceInsights_Settings::get( 'alerts.low_stock_emails', false ) ) {
			return array( 'sent' => false, 'items' => 0, 'message' => __( 'Low stock emails are switched off.', 'kdna-ecommerce-insights' ) );
		}

		KDNA_EcommerceInsights_Cost_Catalogue::flush();
		$report  = self::report();
		$current = array();
		foreach ( array( 'out_of_stock', 'low_stock' ) as $list ) {
			foreach ( $report[ $list ] as $row ) {
				$current[ $row['id'] ] = $list;
			}
		}

		$alerted = (array) get_option( self::ALERTED_OPTION, array() );
		$new     = array_diff_key( $current, $alerted );

		// Remember what is low now, so restocked items can alert again later.
		if ( ! $test ) {
			update_option( self::ALERTED_OPTION, $current, false );
		}

		if ( ! $test && ! $new ) {
			return array( 'sent' => false, 'items' => 0, 'message' => __( 'Nothing new is low on stock.', 'kdna-ecommerce-insights' ) );
		}

		// Low stock first: those can still be reordered before sales stop.
		$show = $test ? $current : $new;
		$rows = array();
		foreach ( array( 'low_stock', 'out_of_stock' ) as $list ) {
			foreach ( $report[ $list ] as $row ) {
				if ( ! isset( $show[ $row['id'] ] ) ) {
					continue;
				}
				$rows[] = array(
					$row['name'],
					'out_of_stock' === $list ? __( 'Out of stock', 'kdna-ecommerce-insights' ) : wc_stock_amount( $row['stock'] ) . ' ' . __( 'left', 'kdna-ecommerce-insights' ),
					null === ( $row['days'] ?? null ) || 'out_of_stock' === $list ? '' : sprintf( /* translators: %d: days. */ _n( '%d day', '%d days', (int) $row['days'], 'kdna-ecommerce-insights' ), (int) $row['days'] ),
					wc_stock_amount( $row['sold_30'] ),
				);
			}
		}

		if ( ! $rows ) {
			return array( 'sent' => false, 'items' => 0, 'message' => __( 'No products are low or out of stock right now, so there is nothing to show.', 'kdna-ecommerce-insights' ) );
		}

		$store   = KDNA_EcommerceInsights_Settings::store_name();
		$subject = $test
			/* translators: %s: store name. */
			? sprintf( __( '[%s] Test: low stock alert', 'kdna-ecommerce-insights' ), $store )
			/* translators: 1: store name, 2: number of products. */
			: sprintf( _n( '[%1$s] %2$d product is running low', '[%1$s] %2$d products are running low', count( $rows ), 'kdna-ecommerce-insights' ), $store, count( $rows ) );

		// Keep the email readable: the first 25, then a line saying how many more.
		$table = KDNA_EcommerceInsights_Mailer::table(
			array( __( 'Product', 'kdna-ecommerce-insights' ), __( 'Stock', 'kdna-ecommerce-insights' ), __( 'Lasts about', 'kdna-ecommerce-insights' ), __( 'Sold in 30 days', 'kdna-ecommerce-insights' ) ),
			array_slice( $rows, 0, 25 ),
			array( 1, 2, 3 )
		);
		if ( count( $rows ) > 25 ) {
			$table .= '<p style="margin:16px 0 0;font-size:14px;color:#6B6E78;">' . esc_html(
				/* translators: %d: number of products not listed. */
				sprintf( _n( 'And %d more product. See them all in Inventory.', 'And %d more products. See them all in Inventory.', count( $rows ) - 25, 'kdna-ecommerce-insights' ), count( $rows ) - 25 )
			) . '</p>';
		}

		$sent = KDNA_EcommerceInsights_Mailer::send(
			KDNA_EcommerceInsights_Settings::get( 'alerts.alert_recipients', get_option( 'admin_email' ) ),
			$subject,
			$test ? __( 'This is a test low stock alert', 'kdna-ecommerce-insights' ) : __( 'Time to reorder', 'kdna-ecommerce-insights' ),
			$test
				? __( 'Real alerts look like this, but only list products that have just become low or out of stock.', 'kdna-ecommerce-insights' )
				: __( 'These products have just become low or out of stock. "Lasts about" is based on how fast each one sold over the last 30 days.', 'kdna-ecommerce-insights' ),
			$table,
			admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/inventory' ),
			__( 'Open Inventory', 'kdna-ecommerce-insights' )
		);

		if ( ! $test ) {
			KDNA_EcommerceInsights_Log::add(
				'alert',
				$sent ? 'success' : 'error',
				$sent
					/* translators: %d: number of products. */
					? sprintf( __( 'Low stock email sent for %d products.', 'kdna-ecommerce-insights' ), count( $rows ) )
					: __( 'Low stock email could not be sent. Check that this site can send email.', 'kdna-ecommerce-insights' )
			);
		}

		return array(
			'sent'    => $sent,
			'items'   => count( $rows ),
			'message' => $sent
				/* translators: %s: email addresses. */
				? sprintf( __( 'Sent to %s.', 'kdna-ecommerce-insights' ), implode( ', ', KDNA_EcommerceInsights_Mailer::recipients( KDNA_EcommerceInsights_Settings::get( 'alerts.alert_recipients', '' ) ) ) )
				: __( 'The email could not be sent. Check the recipients, and that this site can send email (an SMTP plugin usually helps).', 'kdna-ecommerce-insights' ),
		);
	}
}
