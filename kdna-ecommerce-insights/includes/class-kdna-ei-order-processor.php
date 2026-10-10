<?php
/**
 * Order processing engine: works out every profit line in section 6 of the
 * brief for one order and saves it to the summary tables.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a WooCommerce order into one order_facts row, one order_item_facts
 * row per product line and one refund_facts row per refund.
 *
 * All money is saved excluding tax and in the store's base currency.
 * Orders in another currency are converted with the exchange rate a
 * multi-currency plugin saved on the order; if there is none, the order is
 * flagged so the dashboard can say so.
 *
 * Processing never happens during checkout. Order events only queue a job
 * with Action Scheduler (WooCommerce's built-in background queue), which
 * runs moments later in the background.
 *
 * Works with both HPOS and legacy order storage, because it only uses
 * WooCommerce's own order functions and hooks.
 */
class KDNA_EcommerceInsights_Order_Processor {

	/**
	 * Action Scheduler group for every Insights background job.
	 */
	const GROUP = 'kdna-ei';

	/**
	 * Background job that processes one order.
	 */
	const PROCESS_HOOK = 'kdna_ei_process_order';

	/**
	 * Order statuses that never get a facts row (not real orders yet, or deleted).
	 */
	const IGNORED_STATUSES = array( 'checkout-draft', 'auto-draft', 'draft', 'trash' );

	/**
	 * Connects order events to the background queue.
	 */
	public function __construct() {
		add_action( self::PROCESS_HOOK, array( $this, 'run_scheduled' ), 10, 1 );

		// Anything that can change an order's figures queues it for processing.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'queue_after_refund_deleted' ), 20, 2 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'kdna_ei_order_costs_changed', array( __CLASS__, 'queue' ), 20, 1 );

		// Legacy order storage: trashing or deleting an order post.
		add_action( 'wp_trash_post', array( $this, 'queue_legacy_post' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'queue_legacy_post' ), 20, 1 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Queueing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Queues an order to be processed in the background. Queueing the same
	 * order twice before it runs only runs it once.
	 *
	 * @param int|mixed $order_id Order ID (refund IDs are fine too).
	 */
	public static function queue( $order_id ): void {
		$order_id = (int) $order_id;
		if ( ! $order_id || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// A refund ID is fine: process() works on the refund's parent order.
		as_enqueue_async_action( self::PROCESS_HOOK, array( $order_id ), self::GROUP, true );
	}

	/**
	 * Queues the parent order after one of its refunds is deleted.
	 *
	 * @param int $refund_id Refund ID.
	 * @param int $order_id  Parent order ID.
	 */
	public function queue_after_refund_deleted( $refund_id, $order_id ): void {
		self::queue( $order_id );
	}

	/**
	 * Queues an order when it is trashed or deleted with legacy order storage.
	 *
	 * @param int $post_id Post ID.
	 */
	public function queue_legacy_post( $post_id ): void {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::queue( $post_id );
		}
	}

	/**
	 * Runs a queued job: processes the order and rebuilds the days it affects.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run_scheduled( $order_id ): void {
		try {
			$days = self::process( (int) $order_id );
			KDNA_EcommerceInsights_Summary::rebuild_days( $days );
		} catch ( Throwable $e ) {
			/* translators: 1: order ID, 2: error message. */
			KDNA_EcommerceInsights_Log::add( 'order', 'error', sprintf( __( 'Order #%1$d could not be processed: %2$s', 'kdna-ecommerce-insights' ), (int) $order_id, $e->getMessage() ) );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Processing one order
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Works out every profit line for one order and saves it. Removes the
	 * order's rows if it no longer exists, is trashed or is still a draft.
	 *
	 * Does not rebuild the daily summary itself, so a batch of orders can
	 * rebuild each day once. Returns the days that need rebuilding.
	 *
	 * @param int $order_id Order ID.
	 * @return string[] Affected days, Y-m-d.
	 */
	public static function process( int $order_id ): array {
		global $wpdb;

		$order = wc_get_order( $order_id );

		// A refund ID: process its parent order instead.
		if ( $order instanceof WC_Order_Refund ) {
			$order_id = $order->get_parent_id();
			$order    = wc_get_order( $order_id );
		}

		$old_days = self::stored_days( $order_id );

		if ( ! $order instanceof WC_Order || in_array( $order->get_status(), self::IGNORED_STATUSES, true ) ) {
			$customer = self::stored_customer( $order_id );
			self::delete( $order_id );
			$changed = '' !== $customer ? self::update_first_orders( $customer ) : array();
			return array_values( array_unique( array_merge( $old_days, $changed ) ) );
		}

		$old_customer = self::stored_customer( $order_id );
		$facts        = self::calculate( $order );

		$tables = array(
			'order'  => KDNA_EcommerceInsights_Install::table( 'order_facts' ),
			'item'   => KDNA_EcommerceInsights_Install::table( 'order_item_facts' ),
			'refund' => KDNA_EcommerceInsights_Install::table( 'refund_facts' ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->replace( $tables['order'], $facts['order'] );
		$wpdb->delete( $tables['item'], array( 'order_id' => $order_id ), array( '%d' ) );
		foreach ( $facts['items'] as $item ) {
			$wpdb->insert( $tables['item'], $item );
		}
		$wpdb->delete( $tables['refund'], array( 'order_id' => $order_id ), array( '%d' ) );
		foreach ( $facts['refunds'] as $refund ) {
			$wpdb->insert( $tables['refund'], $refund );
		}
		// phpcs:enable

		// Work out again which of the customer's orders was their first.
		$changed = self::update_first_orders( $facts['order']['customer_key'] );
		if ( '' !== $old_customer && $old_customer !== $facts['order']['customer_key'] ) {
			$changed = array_merge( $changed, self::update_first_orders( $old_customer ) );
		}

		$new_days = array_merge( array( $facts['order']['report_date'] ), wp_list_pluck( $facts['refunds'], 'refund_date' ) );

		/**
		 * Fires after an order's facts are saved.
		 *
		 * @param int   $order_id Order ID.
		 * @param array $facts    The saved order, item and refund rows.
		 */
		do_action( 'kdna_ei_order_processed', $order_id, $facts );

		return array_values( array_unique( array_filter( array_merge( $old_days, $new_days, $changed ) ) ) );
	}

	/**
	 * Works out every line in section 6 for one order, without saving.
	 *
	 * @param WC_Order $order Order.
	 * @return array{order: array, items: array[], refunds: array[]}
	 */
	public static function calculate( WC_Order $order ): array {
		$order_id  = $order->get_id();
		$rate_info = self::exchange_rate( $order );
		$rate      = $rate_info['rate'];
		$restock   = 'restocked' === KDNA_EcommerceInsights_Settings::get( 'general.restock_treatment', 'restocked' );

		// Dates in the site's time zone and in UTC.
		$created   = $order->get_date_created();
		$paid      = $order->get_date_paid();
		$completed = $order->get_date_completed();
		$lock_date = $paid ? $paid : $created;
		$lock_gmt  = $lock_date ? gmdate( 'Y-m-d H:i:s', $lock_date->getTimestamp() ) : current_time( 'mysql', true );

		// Load cost history for every product on the order in one go.
		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = (int) $item->get_product_id();
		}
		KDNA_EcommerceInsights_Costs::preload_history( $product_ids );

		// 1 and 2: gross sales and discounts from the product lines.
		$gross     = 0.0;
		$discounts = 0.0;
		$cogs      = 0.0;
		$items     = array();
		$unit_cost = array();
		$missing   = false;
		$qty_total = 0.0;

		foreach ( $order->get_items() as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item */
			$qty        = (float) $item->get_quantity();
			$subtotal   = (float) $item->get_subtotal();
			$total      = (float) $item->get_total();
			$gross     += $subtotal;
			$discounts += $subtotal - $total;
			$qty_total += $qty;

			$cost = self::line_unit_cost( $item, $lock_gmt );
			if ( null === $cost ) {
				$missing = true;
			}
			$unit_cost[ $item_id ] = $cost;

			$line_cost = null === $cost ? 0.0 : $cost * $qty;
			$cogs     += $line_cost;

			$refunded_qty    = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
			$refunded_amount = abs( (float) $order->get_total_refunded_for_item( $item_id ) );

			$items[] = array(
				'order_id'        => $order_id,
				'order_item_id'   => $item_id,
				'product_id'      => (int) $item->get_product_id(),
				'variation_id'    => (int) $item->get_variation_id(),
				'qty'             => $qty,
				'line_net'        => round( $total * $rate, 4 ),
				'unit_cost'       => null === $cost ? null : round( $cost, 4 ),
				'line_cost'       => round( $line_cost, 4 ),
				'refunded_qty'    => $refunded_qty,
				'refunded_amount' => round( $refunded_amount * $rate, 4 ),
				'missing_cost'    => null === $cost ? 1 : 0,
			);
		}

		// Fee lines the customer paid (surcharges) count as sales; negative fees are discounts.
		foreach ( $order->get_fees() as $fee ) {
			$amount = (float) $fee->get_total();
			if ( $amount >= 0 ) {
				$gross += $amount;
			} else {
				$discounts += abs( $amount );
			}
		}

		// 3: refunds, one row each, and the cost of refunded items added back if restocked.
		$refunds       = array();
		$refund_total  = 0.0;
		$refund_tax    = 0.0;
		$cogs_returned = 0.0;

		foreach ( self::refunds_of( $order ) as $refund ) {
			$tax    = abs( (float) $refund->get_total_tax() );
			$amount = max( 0, abs( (float) $refund->get_total() ) - $tax );

			$returned = 0.0;
			$qty      = 0.0;
			foreach ( $refund->get_items() as $refund_item ) {
				$refunded_qty = abs( (float) $refund_item->get_quantity() );
				$original_id  = (int) $refund_item->get_meta( '_refunded_item_id', true );
				$qty         += $refunded_qty;
				if ( $restock && $refunded_qty && isset( $unit_cost[ $original_id ] ) && null !== $unit_cost[ $original_id ] ) {
					$returned += $unit_cost[ $original_id ] * $refunded_qty;
				}
			}

			$refund_date = $refund->get_date_created();

			$refunds[] = array(
				'refund_id'       => $refund->get_id(),
				'order_id'        => $order_id,
				'refund_date'     => $refund_date ? $refund_date->date( 'Y-m-d' ) : null,
				'refund_date_gmt' => $refund_date ? gmdate( 'Y-m-d H:i:s', $refund_date->getTimestamp() ) : null,
				'amount'          => round( $amount * $rate, 4 ),
				'tax'             => round( $tax * $rate, 4 ),
				'shipping'        => round( abs( (float) $refund->get_shipping_total() ) * $rate, 4 ),
				'cogs_returned'   => round( $returned, 4 ),
				'items_qty'       => $qty,
			);

			$refund_total  += $amount;
			$refund_tax    += $tax;
			$cogs_returned += $returned;
		}

		// 4: shipping charged, excluding tax.
		$shipping_charged = (float) $order->get_shipping_total();

		// Net revenue = gross sales - discounts - refunds + shipping charged.
		$net_revenue = ( $gross - $discounts - $refund_total + $shipping_charged ) * $rate;

		// 5: cost of goods, less the cost of refunded items that went back into stock.
		$cogs_net = max( 0, $cogs - $cogs_returned );

		// 6, 7 and 8: payment fee, shipping cost and extra order costs.
		$fee      = KDNA_EcommerceInsights_Fees::get_order_fee( $order );
		$fee_base = self::fee_in_base_currency( $fee, $order, $rate );
		$shipping = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( $order );
		$extra    = KDNA_EcommerceInsights_Extra_Costs::calculate(
			(array) KDNA_EcommerceInsights_Settings::get( 'costs.extra_costs', array() ),
			KDNA_EcommerceInsights_Shipping::order_value( $order ) * $rate
		);

		$gross_profit        = $net_revenue - $cogs_net;
		$contribution_profit = $gross_profit - $fee_base['amount'] - $shipping['amount'] - $extra['amount'];

		$order_row = array(
			'order_id'            => $order_id,
			'report_date'         => self::report_date( $created, $paid, $completed ),
			'date_paid'           => $paid ? $paid->date( 'Y-m-d H:i:s' ) : null,
			'date_paid_gmt'       => $paid ? gmdate( 'Y-m-d H:i:s', $paid->getTimestamp() ) : null,
			'date_created'        => $created ? $created->date( 'Y-m-d H:i:s' ) : null,
			'date_created_gmt'    => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : null,
			'date_completed'      => $completed ? $completed->date( 'Y-m-d H:i:s' ) : null,
			'date_completed_gmt'  => $completed ? gmdate( 'Y-m-d H:i:s', $completed->getTimestamp() ) : null,
			'status'              => $order->get_status(),
			'currency'            => $order->get_currency(),
			'exchange_rate'       => $rate,
			'currency_flag'       => $rate_info['flag'] || $fee_base['flag'] ? 1 : 0,
			'gross_sales'         => round( $gross * $rate, 4 ),
			'discounts'           => round( $discounts * $rate, 4 ),
			'refunds'             => round( $refund_total * $rate, 4 ),
			'shipping_charged'    => round( $shipping_charged * $rate, 4 ),
			'tax'                 => round( ( (float) $order->get_total_tax() - $refund_tax ) * $rate, 4 ),
			'net_revenue'         => round( $net_revenue, 4 ),
			'cogs'                => round( $cogs_net, 4 ),
			'payment_fee'         => round( $fee_base['amount'], 4 ),
			'fee_source'          => $fee['source'],
			'shipping_cost'       => round( $shipping['amount'], 4 ),
			'shipping_source'     => $shipping['source'],
			'extra_costs'         => round( $extra['amount'], 4 ),
			'gross_profit'        => round( $gross_profit, 4 ),
			'contribution_profit' => round( $contribution_profit, 4 ),
			'customer_id'         => (int) $order->get_customer_id(),
			'customer_key'        => self::customer_key( $order ),
			'is_first_order'      => 0,
			'payment_method'      => mb_substr( (string) $order->get_payment_method(), 0, 100 ),
			'country'             => mb_substr( (string) $order->get_billing_country(), 0, 2 ),
			'state'               => mb_substr( (string) $order->get_billing_state(), 0, 100 ),
			'items_count'         => (int) round( $qty_total ),
			'missing_cost_flag'   => $missing ? 1 : 0,
			'cogs_returned'       => round( $cogs_returned, 4 ),
			'tax_refunded'        => round( $refund_tax * $rate, 4 ),
			'calculated_at'       => current_time( 'mysql', true ),
		);

		return array(
			'order'   => $order_row,
			'items'   => $items,
			'refunds' => $refunds,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns an order's refunds. Uses WooCommerce's own list, and if that
	 * comes back empty while the order shows money refunded (which happens
	 * with some database setups), reads the refunds from the order table.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order_Refund[]
	 */
	private static function refunds_of( WC_Order $order ): array {
		$refunds = $order->get_refunds();
		if ( $refunds || (float) $order->get_total_refunded() <= 0 ) {
			return $refunds;
		}

		$found = array();
		foreach ( KDNA_EcommerceInsights_Backfill::refund_ids( $order->get_id() ) as $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( $refund instanceof WC_Order_Refund ) {
				$found[] = $refund;
			}
		}
		return $found;
	}

	/**
	 * The cost per unit for an order line, locked in on the order's paid date.
	 * If WooCommerce's own Cost of Goods Sold already locked a cost on the
	 * line, that is used. Otherwise the cost history gives the cost that
	 * applied on the date.
	 *
	 * @param WC_Order_Item_Product $item     Order line.
	 * @param string                $date_gmt Paid date in UTC.
	 * @return float|null
	 */
	private static function line_unit_cost( $item, string $date_gmt ): ?float {
		$qty = (float) $item->get_quantity();

		if ( KDNA_EcommerceInsights_Costs::native_enabled() && method_exists( $item, 'get_cogs_value' ) && $qty > 0 ) {
			$native = (float) $item->get_cogs_value();
			if ( $native > 0 ) {
				return $native / $qty;
			}
		}

		$product_id = (int) $item->get_product_id();
		if ( ! $product_id ) {
			return null;
		}

		return KDNA_EcommerceInsights_Costs::cost_on_date( $product_id, (int) $item->get_variation_id(), $date_gmt );
	}

	/**
	 * Which day an order belongs to, following the "date basis" setting:
	 * the paid date (default), the created date or the completed date. Falls
	 * back to an earlier date when the chosen one is not set yet.
	 *
	 * @param WC_DateTime|null $created   Created date.
	 * @param WC_DateTime|null $paid      Paid date.
	 * @param WC_DateTime|null $completed Completed date.
	 * @return string|null Y-m-d in the site's time zone.
	 */
	public static function report_date( $created, $paid, $completed ): ?string {
		$basis = KDNA_EcommerceInsights_Settings::get( 'general.date_basis', 'paid' );

		switch ( $basis ) {
			case 'created':
				$date = $created;
				break;
			case 'completed':
				$date = $completed ? $completed : ( $paid ? $paid : $created );
				break;
			default:
				$date = $paid ? $paid : $created;
		}

		return $date ? $date->date( 'Y-m-d' ) : null;
	}

	/**
	 * A key that identifies the customer, so repeat customers are recognised
	 * even when they check out as a guest. Uses the billing email (stored as a
	 * one-way hash), then the account ID, then the order itself.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function customer_key( WC_Order $order ): string {
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' !== $email ) {
			return 'e:' . md5( $email );
		}
		if ( $order->get_customer_id() ) {
			return 'u:' . $order->get_customer_id();
		}
		return 'o:' . $order->get_id();
	}

	/**
	 * Works out the rate to turn the order's currency into the store's base
	 * currency, using the rate a multi-currency plugin saved on the order.
	 *
	 * Supported: Aelia Currency Switcher (_base_currency_exchange_rate, base =
	 * amount x rate) and WOOCS (_woocs_order_rate, base = amount / rate).
	 * Other plugins can supply a rate with the kdna_ei_order_exchange_rate filter.
	 *
	 * @param WC_Order $order Order.
	 * @return array{rate: float, flag: bool} flag is true when no rate was found.
	 */
	public static function exchange_rate( WC_Order $order ): array {
		$base = get_option( 'woocommerce_currency' );

		if ( $order->get_currency() === $base || '' === (string) $order->get_currency() ) {
			return array(
				'rate' => 1.0,
				'flag' => false,
			);
		}

		$rate  = null;
		$aelia = $order->get_meta( '_base_currency_exchange_rate', true );
		$woocs = $order->get_meta( '_woocs_order_rate', true );

		if ( is_numeric( $aelia ) && (float) $aelia > 0 ) {
			$rate = (float) $aelia;
		} elseif ( is_numeric( $woocs ) && (float) $woocs > 0 ) {
			$rate = 1 / (float) $woocs;
		}

		/**
		 * Filters the rate that converts an order's amounts to the base currency.
		 *
		 * @param float|null $rate  Rate, or null if none is known.
		 * @param WC_Order   $order Order.
		 */
		$rate = apply_filters( 'kdna_ei_order_exchange_rate', $rate, $order );

		if ( is_numeric( $rate ) && $rate > 0 ) {
			return array(
				'rate' => (float) $rate,
				'flag' => false,
			);
		}

		return array(
			'rate' => 1.0,
			'flag' => true,
		);
	}

	/**
	 * Converts a payment fee into the base currency. Gateway fees can be in
	 * the order currency or (for Stripe) the gateway account's currency.
	 *
	 * @param array    $fee   Fee from KDNA_EcommerceInsights_Fees.
	 * @param WC_Order $order Order.
	 * @param float    $rate  Order to base currency rate.
	 * @return array{amount: float, flag: bool}
	 */
	private static function fee_in_base_currency( array $fee, WC_Order $order, float $rate ): array {
		$base     = get_option( 'woocommerce_currency' );
		$currency = strtoupper( (string) ( $fee['currency'] ?? '' ) );

		if ( '' === $currency || $currency === $base ) {
			return array(
				'amount' => (float) $fee['amount'],
				'flag'   => false,
			);
		}
		if ( strtoupper( $order->get_currency() ) === $currency ) {
			return array(
				'amount' => (float) $fee['amount'] * $rate,
				'flag'   => false,
			);
		}

		// A fee in a third currency: use it as it is and flag the order.
		return array(
			'amount' => (float) $fee['amount'],
			'flag'   => true,
		);
	}

	/**
	 * Marks the earliest counted order of a customer as their first order and
	 * all their others as returning. Returns the days whose new-customer
	 * figures changed so they can be rebuilt.
	 *
	 * @param string $customer_key Customer key.
	 * @return string[] Days, Y-m-d.
	 */
	public static function update_first_orders( string $customer_key ): array {
		global $wpdb;

		if ( '' === $customer_key ) {
			return array();
		}

		$table    = KDNA_EcommerceInsights_Install::table( 'order_facts' );
		$statuses = KDNA_EcommerceInsights_Summary::counted_statuses();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, status, report_date, is_first_order, COALESCE( date_paid_gmt, date_created_gmt ) AS sort_date FROM {$table} WHERE customer_key = %s ORDER BY sort_date ASC, order_id ASC", $customer_key ), ARRAY_A );

		$first_found = false;
		$changed     = array();

		foreach ( (array) $rows as $row ) {
			$should = 0;
			if ( ! $first_found && in_array( $row['status'], $statuses, true ) ) {
				$should      = 1;
				$first_found = true;
			}
			if ( (int) $row['is_first_order'] !== $should ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, array( 'is_first_order' => $should ), array( 'order_id' => (int) $row['order_id'] ), array( '%d' ), array( '%d' ) );
				$changed[] = (string) $row['report_date'];
			}
		}

		return array_values( array_unique( array_filter( $changed ) ) );
	}

	/**
	 * Days an order currently counts on (its report date and refund dates).
	 *
	 * @param int $order_id Order ID.
	 * @return string[]
	 */
	private static function stored_days( int $order_id ): array {
		global $wpdb;
		$facts   = KDNA_EcommerceInsights_Install::table( 'order_facts' );
		$refunds = KDNA_EcommerceInsights_Install::table( 'refund_facts' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$days = array_merge(
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT report_date FROM {$facts} WHERE order_id = %d", $order_id ) ),
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT refund_date FROM {$refunds} WHERE order_id = %d", $order_id ) )
		);
		// phpcs:enable

		return array_values( array_unique( array_filter( $days ) ) );
	}

	/**
	 * The customer key saved for an order, or an empty string.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function stored_customer( int $order_id ): string {
		global $wpdb;
		$facts = KDNA_EcommerceInsights_Install::table( 'order_facts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT customer_key FROM {$facts} WHERE order_id = %d", $order_id ) );
	}

	/**
	 * Removes every row saved for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function delete( int $order_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( KDNA_EcommerceInsights_Install::table( 'order_facts' ), array( 'order_id' => $order_id ), array( '%d' ) );
		$wpdb->delete( KDNA_EcommerceInsights_Install::table( 'order_item_facts' ), array( 'order_id' => $order_id ), array( '%d' ) );
		$wpdb->delete( KDNA_EcommerceInsights_Install::table( 'refund_facts' ), array( 'order_id' => $order_id ), array( '%d' ) );
		// phpcs:enable
	}
}
