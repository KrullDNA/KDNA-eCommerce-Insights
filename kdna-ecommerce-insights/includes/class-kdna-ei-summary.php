<?php
/**
 * Daily summary: one row per day with every core total, for fast charts.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the daily_summary table from order_facts and refund_facts, and runs
 * the nightly check that catches anything missed.
 *
 * Days are rebuilt from scratch rather than added to, so a day's totals are
 * always exactly the sum of its orders, however many times it is rebuilt.
 *
 * Section 6.2 rules applied here:
 * - Only orders with a counted status are included.
 * - Refunds land on the refund date (default) or on the original order's date.
 */
class KDNA_EcommerceInsights_Summary {

	/**
	 * Nightly background job.
	 */
	const NIGHTLY_HOOK = 'kdna_ei_nightly';

	/**
	 * Background job that rebuilds every day.
	 */
	const REBUILD_HOOK = 'kdna_ei_rebuild_summary';

	/**
	 * Option remembering the section 6.2 rules the data was last built with.
	 */
	const RULES_OPTION = 'kdna_ei_rules_snapshot';

	/**
	 * Days the nightly job rebuilds, counting back from today.
	 */
	const NIGHTLY_DAYS = 7;

	/**
	 * Connects the nightly job and settings changes to WordPress.
	 */
	public function __construct() {
		add_action( self::NIGHTLY_HOOK, array( $this, 'run_nightly' ) );
		add_action( self::REBUILD_HOOK, array( __CLASS__, 'rebuild_all' ) );
		add_action( 'init', array( $this, 'schedule_nightly' ), 20 );
		add_action( 'kdna_ei_settings_updated', array( $this, 'apply_rule_changes' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Rules
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Order statuses that count as a sale, from Settings > General.
	 *
	 * @return string[] Statuses without the "wc-" prefix.
	 */
	public static function counted_statuses(): array {
		$statuses = (array) KDNA_EcommerceInsights_Settings::get( 'general.order_statuses', array( 'processing', 'completed' ) );
		return $statuses ? array_values( $statuses ) : array( 'processing', 'completed' );
	}

	/**
	 * Reacts when the section 6.2 rules change in Settings:
	 * - Date basis: moves every order to its new day, then rebuilds every day.
	 * - Counted statuses: recalculates every order, because first-order
	 *   (new customer) flags depend on which orders count.
	 * - Refund dating: rebuilds every day.
	 * - Restock treatment: recalculates every order's cost of goods.
	 */
	public function apply_rule_changes(): void {
		$now = array(
			'statuses'      => self::counted_statuses(),
			'date_basis'    => KDNA_EcommerceInsights_Settings::get( 'general.date_basis' ),
			'refund_dating' => KDNA_EcommerceInsights_Settings::get( 'general.refund_dating' ),
			'restock'       => KDNA_EcommerceInsights_Settings::get( 'general.restock_treatment' ),
		);
		$was = get_option( self::RULES_OPTION, null );
		update_option( self::RULES_OPTION, $now, false );

		if ( ! is_array( $was ) || $was === $now ) {
			return;
		}

		if ( $was['statuses'] !== $now['statuses'] || $was['restock'] !== $now['restock'] ) {
			KDNA_EcommerceInsights_Backfill::start( 'all', array(), true );
			return;
		}

		if ( $was['date_basis'] !== $now['date_basis'] ) {
			self::refresh_report_dates();
		}

		self::schedule_rebuild_all();
	}

	/**
	 * Moves every order to the day its date basis now gives, straight in the
	 * database, without reprocessing the orders.
	 */
	public static function refresh_report_dates(): void {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'order_facts' );

		switch ( KDNA_EcommerceInsights_Settings::get( 'general.date_basis', 'paid' ) ) {
			case 'created':
				$expression = 'date_created';
				break;
			case 'completed':
				$expression = 'COALESCE( date_completed, date_paid, date_created )';
				break;
			default:
				$expression = 'COALESCE( date_paid, date_created )';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET report_date = DATE( {$expression} )" );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Rebuilding
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Queues a rebuild of every day in the background.
	 */
	public static function schedule_rebuild_all(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::REBUILD_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP, true );
		}
	}

	/**
	 * Rebuilds the given days. Days with no orders left are removed.
	 *
	 * @param string[] $days Days, Y-m-d.
	 */
	public static function rebuild_days( array $days ): void {
		$days = array_values( array_unique( array_filter( array_map( 'strval', $days ) ) ) );
		if ( ! $days ) {
			return;
		}

		sort( $days );

		// Rebuild in runs of nearby days, so one query covers many days.
		$start = $days[0];
		$prev  = $days[0];
		foreach ( array_slice( $days, 1 ) as $day ) {
			if ( strtotime( $day ) - strtotime( $prev ) > 7 * DAY_IN_SECONDS ) {
				self::rebuild_range( $start, $prev );
				$start = $day;
			}
			$prev = $day;
		}
		self::rebuild_range( $start, $prev );

		/**
		 * Fires after daily summary days are rebuilt, so cached reports can be cleared.
		 *
		 * @param string[] $days Rebuilt days.
		 */
		do_action( 'kdna_ei_summary_rebuilt', $days );
	}

	/**
	 * Rebuilds every day from the first order to today.
	 */
	public static function rebuild_all(): void {
		global $wpdb;
		$facts   = KDNA_EcommerceInsights_Install::table( 'order_facts' );
		$refunds = KDNA_EcommerceInsights_Install::table( 'refund_facts' );
		$summary = KDNA_EcommerceInsights_Install::table( 'daily_summary' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$first = min(
			array_filter(
				array(
					(string) $wpdb->get_var( "SELECT MIN( report_date ) FROM {$facts}" ),
					(string) $wpdb->get_var( "SELECT MIN( refund_date ) FROM {$refunds}" ),
					wp_date( 'Y-m-d' ),
				)
			)
		);
		$last  = max(
			(string) $wpdb->get_var( "SELECT MAX( report_date ) FROM {$facts}" ),
			(string) $wpdb->get_var( "SELECT MAX( refund_date ) FROM {$refunds}" ),
			wp_date( 'Y-m-d' )
		);

		// Remove any days outside the range, then rebuild a month at a time.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$summary} WHERE summary_date < %s OR summary_date > %s", $first, $last ) );
		// phpcs:enable

		$cursor = new DateTimeImmutable( $first );
		$end    = new DateTimeImmutable( $last );
		while ( $cursor <= $end ) {
			$chunk_end = min( $cursor->modify( 'last day of this month' ), $end );
			self::rebuild_range( $cursor->format( 'Y-m-d' ), $chunk_end->format( 'Y-m-d' ) );
			$cursor = $chunk_end->modify( '+1 day' );
		}

		do_action( 'kdna_ei_summary_rebuilt', array() );
	}

	/**
	 * Rebuilds every day between two dates (inclusive) from the fact tables.
	 *
	 * @param string $start First day, Y-m-d.
	 * @param string $end   Last day, Y-m-d.
	 */
	public static function rebuild_range( string $start, string $end ): void {
		global $wpdb;

		$facts    = KDNA_EcommerceInsights_Install::table( 'order_facts' );
		$refunds  = KDNA_EcommerceInsights_Install::table( 'refund_facts' );
		$summary  = KDNA_EcommerceInsights_Install::table( 'daily_summary' );
		$statuses = self::counted_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$by_order = 'order_date' === KDNA_EcommerceInsights_Settings::get( 'general.refund_dating', 'refund_date' );

		// With refunds on the refund date, each order's own day shows it as it
		// was before any refund, and refunds are taken off on the refund days.
		$pre_refund = $by_order ? '0' : '1';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$order_days = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT report_date AS day,
					COUNT(*) AS orders,
					SUM( items_count ) AS items_sold,
					SUM( gross_sales ) AS gross_sales,
					SUM( discounts ) AS discounts,
					SUM( refunds ) AS refunds,
					SUM( shipping_charged ) AS shipping_charged,
					SUM( tax + {$pre_refund} * tax_refunded ) AS tax,
					SUM( net_revenue + {$pre_refund} * refunds ) AS net_revenue,
					SUM( cogs + {$pre_refund} * cogs_returned ) AS cogs,
					SUM( payment_fee ) AS payment_fees,
					SUM( shipping_cost ) AS shipping_costs,
					SUM( extra_costs ) AS extra_costs,
					SUM( is_first_order ) AS new_customers,
					SUM( CASE WHEN is_first_order = 1 THEN net_revenue + {$pre_refund} * refunds ELSE 0 END ) AS new_customer_revenue,
					SUM( CASE WHEN fee_source = 'estimated' THEN 1 ELSE 0 END ) AS estimated_fee_orders,
					SUM( CASE WHEN shipping_source = 'estimated' THEN 1 ELSE 0 END ) AS estimated_shipping_orders,
					SUM( missing_cost_flag ) AS missing_cost_orders
				FROM {$facts}
				WHERE report_date BETWEEN %s AND %s AND status IN ( $in )
				GROUP BY report_date",
				array_merge( array( $start, $end ), $statuses )
			),
			ARRAY_A
		);

		$refund_days = array();
		if ( ! $by_order ) {
			$refund_days = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.refund_date AS day, SUM( r.amount ) AS refunds, SUM( r.tax ) AS tax, SUM( r.cogs_returned ) AS cogs_returned
					FROM {$refunds} r
					INNER JOIN {$facts} f ON f.order_id = r.order_id
					WHERE r.refund_date BETWEEN %s AND %s AND f.status IN ( $in )
					GROUP BY r.refund_date",
					array_merge( array( $start, $end ), $statuses )
				),
				ARRAY_A
			);
		}
		// phpcs:enable

		// Combine order days and refund days into one set of totals per day.
		$rows    = array();
		$numbers = array( 'orders', 'items_sold', 'gross_sales', 'discounts', 'refunds', 'shipping_charged', 'tax', 'net_revenue', 'cogs', 'payment_fees', 'shipping_costs', 'extra_costs', 'new_customers', 'new_customer_revenue', 'estimated_fee_orders', 'estimated_shipping_orders', 'missing_cost_orders' );

		foreach ( (array) $order_days as $row ) {
			$day = (string) $row['day'];
			foreach ( $numbers as $key ) {
				$rows[ $day ][ $key ] = (float) $row[ $key ];
			}
			if ( ! $by_order ) {
				// The order day shows the order before refunds.
				$rows[ $day ]['refunds'] = 0.0;
			}
		}

		foreach ( (array) $refund_days as $row ) {
			$day = (string) $row['day'];
			if ( ! isset( $rows[ $day ] ) ) {
				$rows[ $day ] = array_fill_keys( $numbers, 0.0 );
			}
			$rows[ $day ]['refunds']     += (float) $row['refunds'];
			$rows[ $day ]['net_revenue'] -= (float) $row['refunds'];
			$rows[ $day ]['tax']         -= (float) $row['tax'];
			$rows[ $day ]['cogs']        -= (float) $row['cogs_returned'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$summary} WHERE summary_date BETWEEN %s AND %s", $start, $end ) );

		$now = current_time( 'mysql', true );
		foreach ( $rows as $day => $totals ) {
			$gross_profit = $totals['net_revenue'] - $totals['cogs'];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$summary,
				array(
					'summary_date'              => $day,
					'orders'                    => (int) $totals['orders'],
					'items_sold'                => round( $totals['items_sold'], 4 ),
					'gross_sales'               => round( $totals['gross_sales'], 4 ),
					'discounts'                 => round( $totals['discounts'], 4 ),
					'refunds'                   => round( $totals['refunds'], 4 ),
					'shipping_charged'          => round( $totals['shipping_charged'], 4 ),
					'tax'                       => round( $totals['tax'], 4 ),
					'net_revenue'               => round( $totals['net_revenue'], 4 ),
					'cogs'                      => round( $totals['cogs'], 4 ),
					'gross_profit'              => round( $gross_profit, 4 ),
					'payment_fees'              => round( $totals['payment_fees'], 4 ),
					'shipping_costs'            => round( $totals['shipping_costs'], 4 ),
					'extra_costs'               => round( $totals['extra_costs'], 4 ),
					'contribution_profit'       => round( $gross_profit - $totals['payment_fees'] - $totals['shipping_costs'] - $totals['extra_costs'], 4 ),
					'new_customers'             => (int) $totals['new_customers'],
					'returning_customers'       => (int) ( $totals['orders'] - $totals['new_customers'] ),
					'new_customer_revenue'      => round( $totals['new_customer_revenue'], 4 ),
					'estimated_fee_orders'      => (int) $totals['estimated_fee_orders'],
					'estimated_shipping_orders' => (int) $totals['estimated_shipping_orders'],
					'missing_cost_orders'       => (int) $totals['missing_cost_orders'],
					'updated_at'                => $now,
				)
			);
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Nightly check
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Makes sure the nightly job is scheduled, at about 3am site time.
	 */
	public function schedule_nightly(): void {
		// Remember the rules the data is built with, so later changes can be spotted.
		if ( false === get_option( self::RULES_OPTION ) ) {
			$this->apply_rule_changes();
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::NIGHTLY_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP ) ) {
			return;
		}

		$next = new DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );
		as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::NIGHTLY_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
	}

	/**
	 * The nightly check: processes any order changed in the last two days
	 * whose figures are out of date or missing, restarts history processing
	 * if it stalled, then rebuilds the last seven days.
	 */
	public function run_nightly(): void {
		$started = current_time( 'mysql', true );
		$fixed   = self::reconcile( 2 * DAY_IN_SECONDS );

		KDNA_EcommerceInsights_Backfill::ensure_running();

		$today = new DateTimeImmutable( 'today', wp_timezone() );
		self::rebuild_range( $today->modify( '-' . ( self::NIGHTLY_DAYS - 1 ) . ' days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );

		/**
		 * Fires after the nightly check, for other nightly work (stock
		 * snapshots arrive in Stage 8).
		 */
		do_action( 'kdna_ei_nightly_done' );

		/* translators: %d: number of orders brought up to date. */
		KDNA_EcommerceInsights_Log::add( 'nightly', 'success', sprintf( __( 'Nightly check finished. %d orders brought up to date, last 7 days rebuilt.', 'kdna-ecommerce-insights' ), $fixed ), $started );
	}

	/**
	 * Processes orders changed within a time window whose saved figures are
	 * older than the change or missing.
	 *
	 * @param int $seconds How far back to look.
	 * @return int Orders processed.
	 */
	public static function reconcile( int $seconds ): int {
		global $wpdb;

		$ids = KDNA_EcommerceInsights_Backfill::modified_since( gmdate( 'Y-m-d H:i:s', time() - $seconds ) );

		$facts = KDNA_EcommerceInsights_Install::table( 'order_facts' );
		$days  = array();
		$count = 0;

		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$calculated = (string) $wpdb->get_var( $wpdb->prepare( "SELECT calculated_at FROM {$facts} WHERE order_id = %d", $id ) );
			$modified   = $order->get_date_modified();
			$modified   = $modified ? gmdate( 'Y-m-d H:i:s', $modified->getTimestamp() ) : '';

			if ( '' === $calculated || $calculated < $modified ) {
				$days = array_merge( $days, KDNA_EcommerceInsights_Order_Processor::process( (int) $id ) );
				++$count;
			}
		}

		self::rebuild_days( $days );
		return $count;
	}
}
