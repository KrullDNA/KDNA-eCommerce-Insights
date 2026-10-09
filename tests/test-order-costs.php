<?php
/**
 * Integration tests for order cost inputs in KDNA eCommerce Insights:
 * actual and estimated payment fees, shipping cost precedence, extra costs,
 * overheads storage and settings validation.
 *
 * Runs inside WordPress with WooCommerce and the plugin active, on a test
 * site only (it creates and deletes test orders, products and a zone):
 *
 *     wp eval-file tests/test-order-costs.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-order-costs.php\n" );

$GLOBALS['kdna_ei_it_failures'] = 0;
$GLOBALS['kdna_ei_it_count']    = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is being tested, in plain English.
 * @param bool   $ok    Whether it passed.
 * @param string $note  Extra detail shown on failure.
 */
function kdna_ei_it_check( string $label, bool $ok, string $note = '' ): void {
	++$GLOBALS['kdna_ei_it_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_it_failures'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : ' (' . $note . ')' ) . "\n";
}

/**
 * Creates a test order with one product line and, optionally, a shipping line.
 *
 * @param string     $gateway  Payment method ID.
 * @param array      $meta     Order meta to add, as a gateway would.
 * @param array|null $shipping Shipping line: method_id, instance_id, total.
 * @return WC_Order
 */
function kdna_ei_it_order( string $gateway, array $meta = array(), ?array $shipping = null ): WC_Order {
	$product = $GLOBALS['kdna_ei_it_product'];
	$order   = wc_create_order();
	$order->add_product( $product, 2 );
	if ( $shipping ) {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_id( $shipping['method_id'] );
		$item->set_instance_id( (string) $shipping['instance_id'] );
		$item->set_method_title( 'Test shipping' );
		$item->set_total( (string) $shipping['total'] );
		$order->add_item( $item );
	}
	$order->set_payment_method( $gateway );
	$order->calculate_totals( false );
	foreach ( $meta as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}
	$order->save();
	$GLOBALS['kdna_ei_it_orders'][] = $order->get_id();
	return $order;
}

wp_set_current_user( 1 );
$kdna_ei_it_settings = get_option( KDNA_EcommerceInsights_Settings::OPTION );

// A 1.5 kg product at $40 so 2 items are worth $80 and weigh 3 kg.
$kdna_ei_it_product = new WC_Product_Simple();
$kdna_ei_it_product->set_name( 'Integration test product' );
$kdna_ei_it_product->set_regular_price( '40' );
$kdna_ei_it_product->set_weight( '1.5' );
$kdna_ei_it_product->save();
$GLOBALS['kdna_ei_it_product'] = $kdna_ei_it_product;
$GLOBALS['kdna_ei_it_orders']  = array();

// A shipping zone with a flat rate method to attach a rule to.
$kdna_ei_it_zone = new WC_Shipping_Zone();
$kdna_ei_it_zone->set_zone_name( 'Integration test zone' );
$kdna_ei_it_zone->save();
$kdna_ei_it_instance = (int) $kdna_ei_it_zone->add_shipping_method( 'flat_rate' );

echo "Payment fees\n";

KDNA_EcommerceInsights_Settings::update(
	array(
		'costs' => array(
			'gateway_fees' => array(
				'bacs'   => array( 'percent' => 1.75, 'fixed' => 0.30 ),
				'stripe' => array( 'percent' => 9, 'fixed' => 9 ),
			),
		),
	)
);

$o = kdna_ei_it_order( 'stripe', array( '_stripe_fee' => '2.63', '_stripe_currency' => 'AUD' ) );
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'Stripe: actual fee read from _stripe_fee, not the rule', 'actual' === $f['source'] && 2.63 === $f['amount'] && 'AUD' === $f['currency'], wp_json_encode( $f ) );

$o = kdna_ei_it_order( 'stripe', array( 'Stripe Fee' => '1.10' ) );
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'Stripe: older "Stripe Fee" key is still read', 'actual' === $f['source'] && 1.1 === $f['amount'], wp_json_encode( $f ) );

$o = kdna_ei_it_order( 'woocommerce_payments', array( '_wcpay_transaction_fee' => 1.84 ) );
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'WooPayments: actual fee read from _wcpay_transaction_fee', 'actual' === $f['source'] && 1.84 === $f['amount'], wp_json_encode( $f ) );

$o = kdna_ei_it_order(
	'ppcp-gateway',
	array(
		'_ppcp_paypal_fees' => array(
			'gross_amount' => array( 'currency_code' => 'AUD', 'value' => '80.00' ),
			'paypal_fee'   => array( 'currency_code' => 'AUD', 'value' => '2.94' ),
			'net_amount'   => array( 'currency_code' => 'AUD', 'value' => '77.06' ),
		),
	)
);
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'PayPal Payments: actual fee read from the _ppcp_paypal_fees breakdown', 'actual' === $f['source'] && 2.94 === $f['amount'] && 'AUD' === $f['currency'], wp_json_encode( $f ) );

$o = kdna_ei_it_order( 'paypal', array( 'PayPal Transaction Fee' => '3.05' ) );
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'PayPal: "PayPal Transaction Fee" key is read', 'actual' === $f['source'] && 3.05 === $f['amount'], wp_json_encode( $f ) );

$o = kdna_ei_it_order( 'bacs' );
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'Bank transfer with a rule: estimated as 1.75% + 0.30 of the order total', 'estimated' === $f['source'] && abs( $f['amount'] - ( (float) $o->get_total() * 0.0175 + 0.30 ) ) < 0.0001, wp_json_encode( $f ) . ' total ' . $o->get_total() );

$o = kdna_ei_it_order( 'stripe' );
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'Stripe order with no saved fee falls back to the Stripe rule', 'estimated' === $f['source'] && $f['amount'] > 9, wp_json_encode( $f ) );

$o = kdna_ei_it_order( 'cod' );
$f = KDNA_EcommerceInsights_Fees::get_order_fee( $o );
kdna_ei_it_check( 'Gateway with no rule and no saved fee: no fee, labelled as none', 'none' === $f['source'] && 0.0 === $f['amount'], wp_json_encode( $f ) );

$ids = wp_list_pluck( KDNA_EcommerceInsights_Fees::installed_gateways(), 'id' );
kdna_ei_it_check( 'Installed gateways are listed automatically', in_array( 'bacs', $ids, true ) && in_array( 'cod', $ids, true ), implode( ',', $ids ) );

echo "\nShipping costs\n";

KDNA_EcommerceInsights_Settings::update(
	array(
		'costs' => array(
			'shipping_rules'         => array(
				array( 'method' => 'flat_rate:' . $kdna_ei_it_instance, 'type' => 'fixed', 'amount' => 9.5, 'per_kg' => 1 ),
				array( 'method' => '*', 'type' => 'same_as_charged', 'amount' => 0, 'per_kg' => 0 ),
			),
			'shipping_cost_meta_key' => '_test_label_cost',
		),
	)
);

$ship = array( 'method_id' => 'flat_rate', 'instance_id' => $kdna_ei_it_instance, 'total' => 12 );

$o = kdna_ei_it_order( 'bacs', array(), $ship );
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( $o );
// The product weight is 1.5 in the store's weight unit, so 2 items weigh 3 of that unit, converted to kg.
$kdna_ei_it_kg = wc_get_weight( 3, 'kg' );
kdna_ei_it_check(
	sprintf( 'Rule for the method: 9.50 fixed + 1.00 per kg on 3 %s (%s kg) = %s estimated', get_option( 'woocommerce_weight_unit' ), round( $kdna_ei_it_kg, 4 ), round( 9.5 + $kdna_ei_it_kg, 4 ) ),
	'estimated' === $s['source'] && abs( $s['amount'] - ( 9.5 + $kdna_ei_it_kg ) ) < 0.0001,
	wp_json_encode( $s )
);

$o = kdna_ei_it_order( 'bacs', array(), array( 'method_id' => 'local_pickup', 'instance_id' => 99, 'total' => 7.25 ) );
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( $o );
kdna_ei_it_check( 'Method without its own rule uses the "all other methods" rule', 'estimated' === $s['source'] && 7.25 === $s['amount'], wp_json_encode( $s ) );

$o = kdna_ei_it_order( 'bacs', array( '_test_label_cost' => '8.40' ), $ship );
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( $o );
kdna_ei_it_check( 'Real label cost from the configured meta key beats the rule', 'actual' === $s['source'] && 8.4 === $s['amount'], wp_json_encode( $s ) );

$o->update_meta_data( KDNA_EcommerceInsights_Shipping::OVERRIDE_META_KEY, '6.00' );
$o->save();
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( wc_get_order( $o->get_id() ) );
kdna_ei_it_check( 'Manual override on the order beats everything', 'override' === $s['source'] && 6.0 === $s['amount'], wp_json_encode( $s ) );

$o = kdna_ei_it_order( 'bacs' );
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( $o );
kdna_ei_it_check( 'Order with no shipping has no shipping cost', 'none' === $s['source'] && 0.0 === $s['amount'], wp_json_encode( $s ) );

$methods = wp_list_pluck( KDNA_EcommerceInsights_Shipping::configured_methods(), 'key' );
kdna_ei_it_check( 'Shipping methods in zones are listed for the rules table', in_array( 'flat_rate:' . $kdna_ei_it_instance, $methods, true ), implode( ',', $methods ) );

echo "\nOverride box save (simulated form post)\n";

$o = kdna_ei_it_order( 'bacs', array(), $ship );
$_POST['kdna_ei_order_costs_nonce']      = wp_create_nonce( KDNA_EcommerceInsights_Shipping::NONCE_ACTION );
$_POST['kdna_ei_shipping_cost_override'] = '11.20';
( new KDNA_EcommerceInsights_Shipping() )->save_order_box( $o->get_id() );
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( wc_get_order( $o->get_id() ) );
kdna_ei_it_check( 'Override box saves the typed cost', 'override' === $s['source'] && 11.2 === $s['amount'], wp_json_encode( $s ) );
$_POST['kdna_ei_shipping_cost_override'] = '';
( new KDNA_EcommerceInsights_Shipping() )->save_order_box( $o->get_id() );
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( wc_get_order( $o->get_id() ) );
kdna_ei_it_check( 'Clearing the override box goes back to the automatic figure', 'estimated' === $s['source'], wp_json_encode( $s ) );
$_POST['kdna_ei_order_costs_nonce'] = 'bad';
$_POST['kdna_ei_shipping_cost_override'] = '99';
( new KDNA_EcommerceInsights_Shipping() )->save_order_box( $o->get_id() );
$s = KDNA_EcommerceInsights_Shipping::get_order_shipping_cost( wc_get_order( $o->get_id() ) );
kdna_ei_it_check( 'A bad security nonce saves nothing', 'estimated' === $s['source'], wp_json_encode( $s ) );
unset( $_POST['kdna_ei_order_costs_nonce'], $_POST['kdna_ei_shipping_cost_override'] );

echo "\nExtra order costs\n";

KDNA_EcommerceInsights_Settings::update(
	array(
		'costs' => array(
			'extra_costs' => array(
				array( 'label' => 'Box', 'type' => 'fixed', 'amount' => 1.5 ),
				array( 'label' => 'Pick and pack', 'type' => 'percent', 'amount' => 5 ),
			),
		),
	)
);
$o = kdna_ei_it_order( 'bacs' );
$e = KDNA_EcommerceInsights_Extra_Costs::get_order_extra_costs( $o );
kdna_ei_it_check( 'Box 1.50 + 5% of the 80.00 item value = 5.50', 5.5 === $e['amount'], wp_json_encode( $e ) );

echo "\nSettings validation\n";

$errors = KDNA_EcommerceInsights_Settings::validate(
	array(
		'costs' => array(
			'gateway_fees'           => array( 'bacs' => array( 'percent' => 150, 'fixed' => -1 ) ),
			'shipping_cost_meta_key' => 'has spaces',
			'extra_costs'            => array( array( 'label' => '', 'type' => 'percent', 'amount' => 120 ) ),
		),
	)
);
kdna_ei_it_check( 'Bad values each get a plain-English message', 5 === count( $errors ), wp_json_encode( $errors ) );
kdna_ei_it_check( 'Good values pass', array() === KDNA_EcommerceInsights_Settings::validate( array( 'costs' => array( 'gateway_fees' => array( 'bacs' => array( 'percent' => 1.75, 'fixed' => 0.3 ) ) ) ) ) );

echo "\nOverheads storage\n";

$bad = KDNA_EcommerceInsights_Overheads::validate( array( 'name' => '', 'amount' => 'abc', 'frequency' => 'daily', 'start_date' => '2026-02-30', 'end_date' => '' ) );
kdna_ei_it_check( 'Invalid overhead lists a problem for each field', isset( $bad['name'], $bad['amount'], $bad['frequency'], $bad['start_date'] ), wp_json_encode( $bad ) );
$bad = KDNA_EcommerceInsights_Overheads::validate( array( 'name' => 'Rent', 'amount' => 3000, 'frequency' => 'monthly', 'start_date' => '2026-06-01', 'end_date' => '2026-05-01' ) );
kdna_ei_it_check( 'End date before start date is refused', isset( $bad['end_date'] ), wp_json_encode( $bad ) );

// Measure only what this test adds, in case the site already has overheads.
$kdna_ei_it_before = KDNA_EcommerceInsights_Overheads::total_for_range( '2026-06-01', '2026-06-07' )['net'];
$rent = KDNA_EcommerceInsights_Overheads::create( array( 'name' => 'Test rent', 'category' => 'rent', 'amount' => 3000, 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'end_date' => '', 'includes_gst' => false ) );
kdna_ei_it_check( 'Overhead is saved', $rent && 3000.0 === $rent['amount'] && null === $rent['end_date'] && 3000.0 === $rent['monthly'], wp_json_encode( $rent ) );
$week = KDNA_EcommerceInsights_Overheads::total_for_range( '2026-06-01', '2026-06-07' );
kdna_ei_it_check( 'Saved $3,000 monthly overhead adds $700 to a 7 day June range', abs( $week['net'] - $kdna_ei_it_before - 700 ) < 0.0001, wp_json_encode( $week ) );
$updated = KDNA_EcommerceInsights_Overheads::update( $rent['id'], array( 'name' => 'Test rent', 'category' => 'Studio', 'amount' => 3100, 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'includes_gst' => true ) );
kdna_ei_it_check( 'Overhead is updated, with a custom category', 3100.0 === $updated['amount'] && 'Studio' === $updated['category'] && '2026-12-31' === $updated['end_date'] && $updated['includes_gst'], wp_json_encode( $updated ) );
kdna_ei_it_check( 'Overhead is deleted', KDNA_EcommerceInsights_Overheads::delete( $rent['id'] ) && null === KDNA_EcommerceInsights_Overheads::get( $rent['id'] ) );

// Tidy up everything this test created.
foreach ( $GLOBALS['kdna_ei_it_orders'] as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->delete( true );
	}
}
$kdna_ei_it_product->delete( true );
$kdna_ei_it_zone->delete();
update_option( KDNA_EcommerceInsights_Settings::OPTION, $kdna_ei_it_settings );

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_it_count'], $GLOBALS['kdna_ei_it_failures'] );
