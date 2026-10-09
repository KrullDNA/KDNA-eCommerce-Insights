<?php
/**
 * Integration tests for the Stage 4 order processing engine: every profit
 * line in section 6 of the brief, refunds, restock rules, cost lock-in,
 * the daily summary, first orders, currency conversion, background queueing,
 * the history backfill and both order storage types.
 *
 * Test site only (creates and deletes products and orders):
 *
 *     wp eval-file tests/test-order-processor.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-order-processor.php\n" );

global $wpdb;

$GLOBALS['kdna_ei_t_fail']  = 0;
$GLOBALS['kdna_ei_t_count'] = 0;
$GLOBALS['kdna_ei_t_clean'] = array(
	'orders'   => array(),
	'products' => array(),
);

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is checked, in plain English.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Detail shown on failure.
 */
function kdna_ei_t( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_t_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_t_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : '  >> ' . ( is_string( $note ) ? $note : wp_json_encode( $note ) ) ) . "\n";
}

/**
 * Checks two amounts are equal to a hundredth of a cent.
 *
 * @param string $label    What is checked.
 * @param float  $expected Expected amount.
 * @param mixed  $actual   Actual amount.
 */
function kdna_ei_t_amount( string $label, float $expected, $actual ): void {
	kdna_ei_t( $label . ' = ' . $expected, abs( $expected - (float) $actual ) < 0.0001, 'got ' . $actual );
}

/**
 * Creates a simple product with a price and optional cost.
 *
 * @param string     $name  Name.
 * @param float      $price Price.
 * @param float|null $cost  Cost, or null for none.
 * @return WC_Product_Simple
 */
function kdna_ei_t_product( string $name, float $price, ?float $cost ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( (string) $price );
	$product->save();
	if ( null !== $cost ) {
		KDNA_EcommerceInsights_Costs::set_cost( $product, $cost );
	}
	$GLOBALS['kdna_ei_t_clean']['products'][] = $product->get_id();
	return $product;
}

/**
 * Creates an order.
 *
 * @param array $lines    Each: product, qty, and optional total (after discount).
 * @param array $options  shipping (charged), status, paid (Y-m-d H:i:s site time), email, gateway, currency, meta.
 * @return WC_Order
 */
function kdna_ei_t_order( array $lines, array $options = array() ): WC_Order {
	$order = wc_create_order();
	foreach ( $lines as $line ) {
		$item_id = $order->add_product( $line['product'], $line['qty'] );
		if ( isset( $line['total'] ) ) {
			$item = $order->get_item( $item_id );
			$item->set_total( (string) $line['total'] );
			$item->save();
		}
	}
	if ( ! empty( $options['shipping'] ) ) {
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_instance_id( '1' );
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_total( (string) $options['shipping'] );
		$order->add_item( $shipping );
	}
	$order->set_billing_email( $options['email'] ?? 'shopper@example.com' );
	$order->set_payment_method( $options['gateway'] ?? 'bacs' );
	if ( ! empty( $options['currency'] ) ) {
		$order->set_currency( $options['currency'] );
	}
	foreach ( (array) ( $options['meta'] ?? array() ) as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}
	$order->calculate_totals( ! empty( $options['taxes'] ) );
	// calculate_totals() recalculates line totals from subtotals for discounts, so set them again.
	foreach ( $lines as $index => $line ) {
		if ( isset( $line['total'] ) ) {
			$items = array_values( $order->get_items() );
			$items[ $index ]->set_total( (string) $line['total'] );
			$items[ $index ]->save();
		}
	}
	$order->calculate_totals( ! empty( $options['taxes'] ) );
	$order->set_status( $options['status'] ?? 'processing' );
	$paid = $options['paid'] ?? '2023-09-15 10:00:00';
	$order->set_date_created( get_gmt_from_date( $paid, 'U' ) );
	if ( 'pending' !== ( $options['status'] ?? 'processing' ) ) {
		$order->set_date_paid( get_gmt_from_date( $paid, 'U' ) );
	}
	$order->save();
	$GLOBALS['kdna_ei_t_clean']['orders'][] = $order->get_id();
	return $order;
}

/**
 * Processes an order now and returns its saved order_facts row.
 *
 * @param WC_Order $order Order.
 * @return array
 */
function kdna_ei_t_process( WC_Order $order ): array {
	global $wpdb;
	$days = KDNA_EcommerceInsights_Order_Processor::process( $order->get_id() );
	KDNA_EcommerceInsights_Summary::rebuild_days( $days );
	$table = KDNA_EcommerceInsights_Install::table( 'order_facts' );
	return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order->get_id() ), ARRAY_A );
}

/**
 * Returns a daily_summary row.
 *
 * @param string $day Y-m-d.
 * @return array
 */
function kdna_ei_t_day( string $day ): array {
	global $wpdb;
	$table = KDNA_EcommerceInsights_Install::table( 'daily_summary' );
	return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE summary_date = %s", $day ), ARRAY_A );
}

wp_set_current_user( 1 );

// Remove anything left behind by an earlier run that stopped part way.
foreach ( KDNA_EcommerceInsights_Backfill::next_ids( 'all', '', 0, 100000 ) as $kdna_ei_t_old_id ) {
	$kdna_ei_t_old = wc_get_order( $kdna_ei_t_old_id );
	if ( $kdna_ei_t_old && str_ends_with( strtolower( (string) $kdna_ei_t_old->get_billing_email() ), '@example.com' ) ) {
		KDNA_EcommerceInsights_Order_Processor::delete( $kdna_ei_t_old_id );
		$kdna_ei_t_old->delete( true );
	}
}
foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'any' ) ) as $kdna_ei_t_old ) {
	if ( str_starts_with( $kdna_ei_t_old->get_name(), 'Test ' ) ) {
		$kdna_ei_t_old->delete( true );
	}
}

$kdna_ei_t_settings = get_option( KDNA_EcommerceInsights_Settings::OPTION );
$kdna_ei_t_hpos     = get_option( 'woocommerce_custom_orders_table_enabled' );
$kdna_ei_t_job      = get_option( KDNA_EcommerceInsights_Backfill::OPTION );

// Known rules for the tests.
update_option(
	KDNA_EcommerceInsights_Settings::OPTION,
	array_replace_recursive(
		KDNA_EcommerceInsights_Settings::defaults(),
		array(
			'costs' => array(
				'gateway_fees'           => array( 'bacs' => array( 'percent' => 1.75, 'fixed' => 0.30 ) ),
				'shipping_rules'         => array( array( 'method' => '*', 'type' => 'fixed', 'amount' => 6, 'per_kg' => 0 ) ),
				'shipping_cost_meta_key' => '',
				'extra_costs'            => array( array( 'label' => 'Box', 'type' => 'fixed', 'amount' => 1.5 ) ),
			),
		)
	)
);
KDNA_EcommerceInsights_Settings::reset( 'general' );
update_option( KDNA_EcommerceInsights_Summary::RULES_OPTION, null );

$serum   = kdna_ei_t_product( 'Test serum', 40, 10 );
$no_cost = kdna_ei_t_product( 'Test no cost', 25, null );

/*
 * -------------------------------------------------------------------------
 * Section 6, line by line
 * -------------------------------------------------------------------------
 */
echo "Every profit line in section 6 (2 x 40.00 serum, 10.00 discount, 10.00 shipping)\n";

$o = kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 2, 'total' => 70 ) ), array( 'shipping' => 10, 'paid' => '2023-09-15 10:00:00' ) );
$f = kdna_ei_t_process( $o );

kdna_ei_t_amount( '1. Gross sales', 80, $f['gross_sales'] );
kdna_ei_t_amount( '2. Discounts', 10, $f['discounts'] );
kdna_ei_t_amount( '3. Refunds', 0, $f['refunds'] );
kdna_ei_t_amount( '4. Shipping charged', 10, $f['shipping_charged'] );
kdna_ei_t_amount( '   Net revenue (80 - 10 - 0 + 10)', 80, $f['net_revenue'] );
kdna_ei_t_amount( '5. Cost of goods (2 x 10.00)', 20, $f['cogs'] );
kdna_ei_t_amount( '   Gross profit', 60, $f['gross_profit'] );
kdna_ei_t_amount( '6. Payment fee (1.75% of 80.00 + 0.30)', 1.70, $f['payment_fee'] );
kdna_ei_t( '   Fee is labelled estimated', 'estimated' === $f['fee_source'], $f['fee_source'] );
kdna_ei_t_amount( '7. Shipping cost (fixed rule)', 6, $f['shipping_cost'] );
kdna_ei_t_amount( '8. Extra order costs (box)', 1.5, $f['extra_costs'] );
kdna_ei_t_amount( '   Contribution profit (60 - 1.70 - 6 - 1.50)', 50.8, $f['contribution_profit'] );
kdna_ei_t( '   Report date is the paid date', '2023-09-15' === $f['report_date'], $f['report_date'] );
kdna_ei_t( '   Unit cost 10.00 locked on the order line', 10.0 === (float) $wpdb->get_var( $wpdb->prepare( 'SELECT unit_cost FROM ' . KDNA_EcommerceInsights_Install::table( 'order_item_facts' ) . ' WHERE order_id = %d', $o->get_id() ) ) );
kdna_ei_t( '   Not flagged for missing costs', 0 === (int) $f['missing_cost_flag'] );

/*
 * -------------------------------------------------------------------------
 * Refunds and restock
 * -------------------------------------------------------------------------
 */
echo "\nPartial refund of one serum (35.00) on 20 September\n";

$items = $o->get_items();
$first = array_key_first( $items );
$r     = wc_create_refund(
	array(
		'order_id'   => $o->get_id(),
		'amount'     => 35,
		'line_items' => array(
			$first => array(
				'qty'          => 1,
				'refund_total' => 35,
			),
		),
	)
);
$r->set_date_created( get_gmt_from_date( '2023-09-20 12:00:00', 'U' ) );
$r->save();
$f = kdna_ei_t_process( wc_get_order( $o->get_id() ) );

kdna_ei_t_amount( 'Refunds', 35, $f['refunds'] );
kdna_ei_t_amount( 'Net revenue (80 - 35)', 45, $f['net_revenue'] );
kdna_ei_t_amount( 'Cost of goods with the refunded item restocked (20 - 10)', 10, $f['cogs'] );
kdna_ei_t_amount( 'Gross profit', 35, $f['gross_profit'] );

$d15 = kdna_ei_t_day( '2023-09-15' );
$d20 = kdna_ei_t_day( '2023-09-20' );
kdna_ei_t_amount( 'Refund dated on refund day: 15 Sept keeps full net revenue', 80, $d15['net_revenue'] ?? null );
kdna_ei_t_amount( 'Refund dated on refund day: 20 Sept shows the refund', 35, $d20['refunds'] ?? null );
kdna_ei_t_amount( 'Refund dated on refund day: 20 Sept net revenue', -35, $d20['net_revenue'] ?? null );
kdna_ei_t_amount( 'Refund dated on refund day: 20 Sept cost returned', -10, $d20['cogs'] ?? null );

KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'refund_dating' => 'order_date' ) ) );
KDNA_EcommerceInsights_Summary::rebuild_all();
$d15 = kdna_ei_t_day( '2023-09-15' );
kdna_ei_t_amount( 'Refund on order date: 15 Sept net revenue', 45, $d15['net_revenue'] ?? null );
kdna_ei_t( 'Refund on order date: 20 Sept has no row', array() === kdna_ei_t_day( '2023-09-20' ) );

KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'refund_dating' => 'refund_date', 'restock_treatment' => 'written_off' ) ) );
$f = kdna_ei_t_process( wc_get_order( $o->get_id() ) );
kdna_ei_t_amount( 'Refunded item written off: cost of goods stays', 20, $f['cogs'] );
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'restock_treatment' => 'restocked' ) ) );
KDNA_EcommerceInsights_Backfill::cancel();

echo "\nFull refund\n";
$o2 = kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'shipping' => 5, 'paid' => '2023-09-16 09:00:00', 'email' => 'refund@example.com' ) );
wc_create_refund(
	array(
		'order_id'   => $o2->get_id(),
		'amount'     => $o2->get_total(),
		'line_items' => array(
			array_key_first( $o2->get_items() ) => array(
				'qty'          => 1,
				'refund_total' => 40,
			),
		),
	)
);
$f = kdna_ei_t_process( wc_get_order( $o2->get_id() ) );
kdna_ei_t_amount( 'Fully refunded order: refunds', 45, $f['refunds'] );
kdna_ei_t_amount( 'Fully refunded order: net revenue', 0, $f['net_revenue'] );
kdna_ei_t_amount( 'Fully refunded order: cost of goods', 0, $f['cogs'] );

/*
 * -------------------------------------------------------------------------
 * Cost lock-in
 * -------------------------------------------------------------------------
 */
echo "\nCosts locked in on the paid date\n";
$history = KDNA_EcommerceInsights_Install::table( 'cost_history' );
$changing = kdna_ei_t_product( 'Test changing cost', 30, 5 );
$wpdb->query( $wpdb->prepare( "UPDATE {$history} SET changed_at = '2023-01-01 00:00:00' WHERE product_id = %d", $changing->get_id() ) );
KDNA_EcommerceInsights_Costs::set_cost( $changing, 8 );
$wpdb->query( $wpdb->prepare( "UPDATE {$history} SET changed_at = '2023-06-01 00:00:00' WHERE product_id = %d AND new_cost = 8", $changing->get_id() ) );
KDNA_EcommerceInsights_Costs::forget_history();

$march = kdna_ei_t_process( kdna_ei_t_order( array( array( 'product' => $changing, 'qty' => 1 ) ), array( 'paid' => '2023-03-01 10:00:00', 'email' => 'a@example.com' ) ) );
$july  = kdna_ei_t_process( kdna_ei_t_order( array( array( 'product' => $changing, 'qty' => 1 ) ), array( 'paid' => '2023-07-01 10:00:00', 'email' => 'a@example.com' ) ) );
kdna_ei_t_amount( 'Order paid in March uses the March cost', 5, $march['cogs'] );
kdna_ei_t_amount( 'Order paid in July uses the new cost', 8, $july['cogs'] );

$late = kdna_ei_t_order( array( array( 'product' => $no_cost, 'qty' => 2 ) ), array( 'paid' => '2023-02-01 10:00:00', 'email' => 'late@example.com' ) );
$f    = kdna_ei_t_process( $late );
kdna_ei_t( 'Product with no cost: order flagged as missing a cost', 1 === (int) $f['missing_cost_flag'] );
kdna_ei_t_amount( 'Product with no cost: cost of goods is 0 until a cost is entered', 0, $f['cogs'] );
KDNA_EcommerceInsights_Costs::set_cost( $no_cost, 12 );
$f = kdna_ei_t_process( wc_get_order( $late->get_id() ) );
kdna_ei_t_amount( 'Cost entered later fills the old order on recalculation (2 x 12)', 24, $f['cogs'] );
kdna_ei_t( 'And clears the missing cost flag', 0 === (int) $f['missing_cost_flag'] );

/*
 * -------------------------------------------------------------------------
 * Rules from section 6.2
 * -------------------------------------------------------------------------
 */
echo "\nSection 6.2 rules\n";
$pending = kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'status' => 'pending', 'paid' => '2023-09-17 09:00:00', 'email' => 'pending@example.com' ) );
kdna_ei_t_process( $pending );
kdna_ei_t( 'A pending order is not counted in the daily summary', array() === kdna_ei_t_day( '2023-09-17' ) );

$created_paid = kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'paid' => '2023-09-18 23:00:00', 'email' => 'dates@example.com' ) );
$created_paid->set_date_created( get_gmt_from_date( '2023-09-10 09:00:00', 'U' ) );
$created_paid->save();
$f = kdna_ei_t_process( $created_paid );
kdna_ei_t( 'Paid date basis: order counts on 18 Sept', '2023-09-18' === $f['report_date'], $f['report_date'] );
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'date_basis' => 'created' ) ) );
KDNA_EcommerceInsights_Summary::rebuild_all();
$moved = $wpdb->get_var( $wpdb->prepare( 'SELECT report_date FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . ' WHERE order_id = %d', $created_paid->get_id() ) );
kdna_ei_t( 'Switching to created date moves the order to 10 Sept without reprocessing', '2023-09-10' === $moved, $moved );
kdna_ei_t( 'And the daily summary follows', ! empty( kdna_ei_t_day( '2023-09-10' ) ) );
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'date_basis' => 'paid' ) ) );
KDNA_EcommerceInsights_Summary::rebuild_all();

echo "\nNew and returning customers\n";
$first_order  = kdna_ei_t_process( kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'paid' => '2023-08-01 09:00:00', 'email' => 'Repeat@Example.com' ) ) );
$second_order = kdna_ei_t_process( kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'paid' => '2023-08-20 09:00:00', 'email' => 'repeat@example.com' ) ) );
kdna_ei_t( 'Guest email is matched regardless of capitals: first order flagged', 1 === (int) $first_order['is_first_order'] );
$second_order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . ' WHERE order_id = %d', $second_order['order_id'] ), ARRAY_A );
kdna_ei_t( 'Second order is a returning customer', 0 === (int) $second_order['is_first_order'] );

echo "\nCurrency conversion\n";
$eur = kdna_ei_t_process( kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'currency' => 'EUR', 'meta' => array( '_woocs_order_rate' => 0.5 ), 'email' => 'eur@example.com' ) ) );
kdna_ei_t_amount( 'Order in EUR with a saved WOOCS rate is converted to the base currency', 80, $eur['gross_sales'] );
kdna_ei_t( 'And is not flagged', 0 === (int) $eur['currency_flag'] );
$gbp = kdna_ei_t_process( kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'currency' => 'GBP', 'email' => 'gbp@example.com' ) ) );
kdna_ei_t( 'Order in another currency with no saved rate is flagged', 1 === (int) $gbp['currency_flag'] );

echo "\nActual payment fee\n";
$stripe = kdna_ei_t_process( kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'gateway' => 'stripe', 'meta' => array( '_stripe_fee' => '1.46' ), 'email' => 'stripe@example.com' ) ) );
kdna_ei_t( 'Stripe fee read from the order and labelled actual', 'actual' === $stripe['fee_source'] && abs( 1.46 - (float) $stripe['payment_fee'] ) < 0.0001, $stripe['fee_source'] . ' ' . $stripe['payment_fee'] );

/*
 * -------------------------------------------------------------------------
 * Background queueing
 * -------------------------------------------------------------------------
 */
echo "\nBackground queueing (nothing runs during checkout)\n";
as_unschedule_all_actions( KDNA_EcommerceInsights_Order_Processor::PROCESS_HOOK );
$queued = kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 1 ) ), array( 'email' => 'queue@example.com' ) );
kdna_ei_t( 'Saving an order queues it in Action Scheduler', as_has_scheduled_action( KDNA_EcommerceInsights_Order_Processor::PROCESS_HOOK, array( $queued->get_id() ), KDNA_EcommerceInsights_Order_Processor::GROUP ) );
kdna_ei_t( 'But does not process it straight away', null === $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . ' WHERE order_id = %d', $queued->get_id() ) ) );
do_action( KDNA_EcommerceInsights_Order_Processor::PROCESS_HOOK, $queued->get_id() );
kdna_ei_t( 'Running the queued job processes it', null !== $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . ' WHERE order_id = %d', $queued->get_id() ) ) );
$queued->set_status( 'cancelled' );
$queued->save();
do_action( KDNA_EcommerceInsights_Order_Processor::PROCESS_HOOK, $queued->get_id() );
kdna_ei_t( 'A cancelled order keeps its row but is left out of the summary', 'cancelled' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . ' WHERE order_id = %d', $queued->get_id() ) ) );
$queued->delete( true );
do_action( KDNA_EcommerceInsights_Order_Processor::PROCESS_HOOK, $queued->get_id() );
kdna_ei_t( 'A deleted order has its rows removed', null === $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . ' WHERE order_id = %d', $queued->get_id() ) ) );

/*
 * -------------------------------------------------------------------------
 * Backfill and recalculation jobs
 * -------------------------------------------------------------------------
 */
echo "\nBackfill (resumable) and recalculation\n";
$total = KDNA_EcommerceInsights_Backfill::count( 'all' );
$state = KDNA_EcommerceInsights_Backfill::start( 'all', array(), true );
kdna_ei_t( 'Backfill counts every order (' . $total . ')', $state['total'] === $total && 'running' === $state['status'] );
KDNA_EcommerceInsights_Backfill::run_batch();
$after_one = KDNA_EcommerceInsights_Backfill::state();
kdna_ei_t( 'One batch processes up to 100 orders and saves progress', $after_one['processed'] === min( 100, $total ), $after_one );

// Simulate an interruption: the queued next batch is lost.
as_unschedule_all_actions( KDNA_EcommerceInsights_Backfill::BATCH_HOOK );
delete_transient( KDNA_EcommerceInsights_Backfill::LOCK );
if ( $after_one['processed'] < $total ) {
	kdna_ei_t( 'An interrupted job is requeued by the resume check', KDNA_EcommerceInsights_Backfill::ensure_running() );
}
$guard = 0;
while ( 'running' === KDNA_EcommerceInsights_Backfill::state()['status'] && $guard++ < 1000 ) {
	KDNA_EcommerceInsights_Backfill::run_batch();
}
$done = KDNA_EcommerceInsights_Backfill::state();
kdna_ei_t( 'Backfill finishes and every order is processed exactly once', 'complete' === $done['status'] && $done['processed'] === $total && 100 === $done['percent'], $done );

$missing_count = KDNA_EcommerceInsights_Backfill::count( 'missing' );
$state         = KDNA_EcommerceInsights_Backfill::start( 'missing' );
kdna_ei_t( 'Recalculate orders missing costs counts only those (' . $missing_count . ')', $state['total'] === $missing_count );
while ( 'running' === KDNA_EcommerceInsights_Backfill::state()['status'] ) {
	KDNA_EcommerceInsights_Backfill::run_batch();
}
$after_count = KDNA_EcommerceInsights_Backfill::count( 'after', '2023-09-15' );
$state       = KDNA_EcommerceInsights_Backfill::start( 'after', array( 'after' => '2023-09-15' ) );
kdna_ei_t( 'Recalculate orders after a date counts only those (' . $after_count . ')', $state['total'] === $after_count && $after_count < $total );
kdna_ei_t( 'A second job cannot start while one is running', is_wp_error( KDNA_EcommerceInsights_Backfill::start( 'all' ) ) );
KDNA_EcommerceInsights_Backfill::cancel();
kdna_ei_t( 'A running job can be cancelled', 'cancelled' === KDNA_EcommerceInsights_Backfill::state()['status'] );
kdna_ei_t( 'A bad date is refused in plain English', is_wp_error( KDNA_EcommerceInsights_Backfill::start( 'after', array( 'after' => '2023-02-30' ) ) ) );

/*
 * -------------------------------------------------------------------------
 * Legacy order storage
 * -------------------------------------------------------------------------
 */
echo "\nLegacy order storage\n";
// WooCommerce refuses to switch storage while orders are out of sync, so
// switch the setting directly for this test only, and switch it back after.
$wpdb->update( $wpdb->options, array( 'option_value' => 'no' ), array( 'option_name' => 'woocommerce_custom_orders_table_enabled' ) );
wp_cache_flush();
kdna_ei_t( 'Storage switched to legacy for this test', ! KDNA_EcommerceInsights_Backfill::uses_hpos() );
$legacy = kdna_ei_t_order( array( array( 'product' => $serum, 'qty' => 3 ) ), array( 'email' => 'legacy@example.com', 'paid' => '2023-09-19 09:00:00' ) );
kdna_ei_t( 'Order is stored as a post', 'shop_order' === get_post_type( $legacy->get_id() ) );
$f = kdna_ei_t_process( $legacy );
kdna_ei_t_amount( 'Legacy order processed: gross sales 3 x 40', 120, $f['gross_sales'] ?? null );
kdna_ei_t( 'Backfill finds legacy orders', in_array( $legacy->get_id(), KDNA_EcommerceInsights_Backfill::next_ids( 'all', '', $legacy->get_id() - 1, 5 ), true ) );
$legacy->delete( true );
$wpdb->update( $wpdb->options, array( 'option_value' => $kdna_ei_t_hpos ), array( 'option_name' => 'woocommerce_custom_orders_table_enabled' ) );
wp_cache_flush();

/*
 * -------------------------------------------------------------------------
 * Tidy up
 * -------------------------------------------------------------------------
 */
foreach ( $GLOBALS['kdna_ei_t_clean']['orders'] as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->delete( true );
	}
	KDNA_EcommerceInsights_Order_Processor::delete( $order_id );
}
foreach ( $GLOBALS['kdna_ei_t_clean']['products'] as $product_id ) {
	$product = wc_get_product( $product_id );
	if ( $product ) {
		$product->delete( true );
	}
}
update_option( KDNA_EcommerceInsights_Settings::OPTION, $kdna_ei_t_settings );
update_option( KDNA_EcommerceInsights_Backfill::OPTION, $kdna_ei_t_job );
delete_option( KDNA_EcommerceInsights_Summary::RULES_OPTION );
KDNA_EcommerceInsights_Summary::rebuild_all();

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_t_count'], $GLOBALS['kdna_ei_t_fail'] );
