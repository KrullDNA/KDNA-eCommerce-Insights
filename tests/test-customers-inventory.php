<?php
/**
 * Integration tests for Stage 8: repeat guests, the cohort grid against a
 * hand-worked example, customer counts, days of stock left and reorder
 * dates, shared variable stock, low stock thresholds, nightly snapshots
 * and the low stock emails.
 *
 * Test site only:
 *
 *     wp eval-file tests/test-customers-inventory.php
 *
 * Everything it creates is removed at the end. This folder is not part of
 * the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-customers-inventory.php\n" );

$GLOBALS['kdna_ei_c_fail']  = 0;
$GLOBALS['kdna_ei_c_count'] = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Detail shown on failure.
 */
function kdna_ei_c( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_c_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_c_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : '  >> ' . ( is_string( $note ) ? $note : wp_json_encode( $note ) ) ) . "\n";
}

/**
 * Adds made-up order facts directly (fast, and isolated to old dates).
 *
 * @param int    $order_id     Order ID (900... range).
 * @param string $customer_key Customer key.
 * @param string $date         Y-m-d.
 * @param float  $revenue      Net revenue.
 */
function kdna_ei_c_fact( int $order_id, string $customer_key, string $date, float $revenue = 50.0 ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		KDNA_EcommerceInsights_Install::table( 'order_facts' ),
		array(
			'order_id'     => $order_id,
			'report_date'  => $date,
			'status'       => 'completed',
			'currency'     => 'AUD',
			'customer_key' => $customer_key,
			'net_revenue'  => $revenue,
		)
	);
}

/**
 * Removes made-up facts and anything else this test created.
 *
 * @param int[] $order_ids   Real orders to delete.
 * @param int[] $product_ids Products to delete.
 */
function kdna_ei_c_cleanup( array $order_ids = array(), array $product_ids = array() ): void {
	global $wpdb;
	$orders = KDNA_EcommerceInsights_Install::table( 'order_facts' );
	$items  = KDNA_EcommerceInsights_Install::table( 'order_item_facts' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$items} WHERE order_id >= 910000000" );
	$wpdb->query( "DELETE FROM {$orders} WHERE order_id >= 910000000" );
	$wpdb->query( 'DELETE FROM ' . KDNA_EcommerceInsights_Install::table( 'stock_snapshots' ) . " WHERE snapshot_date = '2021-06-01'" );
	// phpcs:enable
	foreach ( $order_ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders} WHERE order_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE order_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->delete( true );
		}
	}
	foreach ( $product_ids as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			foreach ( $product->get_children() as $child ) {
				wp_delete_post( $child, true );
			}
			$product->delete( true );
		}
	}
	KDNA_EcommerceInsights_Cost_Catalogue::flush();
	KDNA_EcommerceInsights_Cache::flush();
}

wp_set_current_user( 1 );
global $wpdb;
kdna_ei_c_cleanup();

$kdna_ei_saved_alerts = KDNA_EcommerceInsights_Settings::get( 'alerts' );
$kdna_ei_saved_sent   = get_option( KDNA_EcommerceInsights_Inventory::ALERTED_OPTION, null );
$kdna_ei_orders       = array();
$kdna_ei_products     = array();

/*
 * ---------------------------------------------------------------------
 * Repeat guests
 * ---------------------------------------------------------------------
 */
echo "\n== Repeat guests are one customer ==\n";

$kdna_ei_simple = new WC_Product_Simple();
$kdna_ei_simple->set_name( 'KDNA test guest product' );
$kdna_ei_simple->set_regular_price( '20' );
$kdna_ei_simple->save();
$kdna_ei_products[] = $kdna_ei_simple->get_id();

$kdna_ei_guest_emails = array( 'Guest.Repeat@Example.com', ' guest.repeat@example.com ', 'GUEST.REPEAT@EXAMPLE.COM' );
foreach ( $kdna_ei_guest_emails as $i => $email ) {
	$order = wc_create_order();
	$order->add_product( $kdna_ei_simple, 1 );
	$order->set_billing_email( trim( $email ) );
	$order->set_billing_first_name( 'Repeat' );
	$order->set_billing_last_name( 'Guest' );
	$order->set_date_created( sprintf( '2021-08-%02d 10:00:00', 3 + $i * 7 ) );
	$order->set_date_paid( sprintf( '2021-08-%02d 10:00:00', 3 + $i * 7 ) );
	$order->calculate_totals();
	$order->set_status( 'completed' );
	$order->save();
	$kdna_ei_orders[] = $order->get_id();
	KDNA_EcommerceInsights_Order_Processor::process( $order->get_id() );
}

$kdna_ei_keys = $wpdb->get_results( 'SELECT order_id, customer_key, is_first_order FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) . ' WHERE order_id IN ( ' . implode( ',', array_map( 'intval', $kdna_ei_orders ) ) . ' ) ORDER BY order_id', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
kdna_ei_c( 'Three guest orders saved', 3 === count( $kdna_ei_keys ), $kdna_ei_keys );
kdna_ei_c( 'Same email in any case or spacing gives one customer key', 1 === count( array_unique( wp_list_pluck( $kdna_ei_keys, 'customer_key' ) ) ), $kdna_ei_keys );
kdna_ei_c( 'Only the first order counts as a new customer', array( '1', '0', '0' ) === array_map( 'strval', wp_list_pluck( $kdna_ei_keys, 'is_first_order' ) ), $kdna_ei_keys );

KDNA_EcommerceInsights_Summary::rebuild_range( '2021-08-01', '2021-08-31' );
KDNA_EcommerceInsights_Cache::flush();
$kdna_ei_aug    = KDNA_EcommerceInsights_Dates::resolve( 'custom', '2021-08-01', '2021-08-31' );
$kdna_ei_totals = KDNA_EcommerceInsights_Report::totals( $kdna_ei_aug );
kdna_ei_c( 'August counts 1 customer', 1.0 === $kdna_ei_totals['customers'], $kdna_ei_totals['customers'] );
kdna_ei_c( 'August counts 1 new customer and 0 returning (they were new this month)', 1.0 === $kdna_ei_totals['new_customers'] && 0.0 === $kdna_ei_totals['returning_customers'], array( $kdna_ei_totals['new_customers'], $kdna_ei_totals['returning_customers'] ) );

$kdna_ei_series = KDNA_EcommerceInsights_Report::series( $kdna_ei_aug, 'month', array( 'customers', 'new_customers', 'returning_customers' ) );
kdna_ei_c( 'Monthly chart: 1 customer, not 3', array( 1.0 ) === array_map( 'floatval', $kdna_ei_series['series']['customers'] ), $kdna_ei_series['series'] );

/*
 * ---------------------------------------------------------------------
 * Cohort grid against a hand-worked example
 * ---------------------------------------------------------------------
 */
echo "\n== Cohort grid matches a manual check ==\n";

// Customer A: first Jan, again Feb and Apr. B: Jan only. C: first Feb, again Mar.
// D: first Feb, twice in Feb (still one customer that month). E: first Mar.
// F: first Dec 2020 (before the range, so not a cohort row), again Jan.
$kdna_ei_id = 910000000;
foreach (
	array(
		array( 'kdna-a', '2021-01-05' ),
		array( 'kdna-a', '2021-02-10' ),
		array( 'kdna-a', '2021-04-20' ),
		array( 'kdna-b', '2021-01-15' ),
		array( 'kdna-c', '2021-02-02' ),
		array( 'kdna-c', '2021-03-09' ),
		array( 'kdna-d', '2021-02-03' ),
		array( 'kdna-d', '2021-02-25' ),
		array( 'kdna-e', '2021-03-30' ),
		array( 'kdna-f', '2020-12-20' ),
		array( 'kdna-f', '2021-01-20' ),
	) as $fact
) {
	kdna_ei_c_fact( ++$kdna_ei_id, $fact[0], $fact[1] );
}
KDNA_EcommerceInsights_Cache::flush();

$kdna_ei_cohorts = KDNA_EcommerceInsights_Report::cohorts( KDNA_EcommerceInsights_Dates::resolve( 'custom', '2021-01-01', '2021-04-30' ) );
$kdna_ei_grid    = array();
foreach ( $kdna_ei_cohorts as $row ) {
	$kdna_ei_grid[ $row['month'] ] = array( $row['size'], $row['retention'] );
}
$kdna_ei_expected = array(
	'2021-01' => array( 2, array( 100.0, 50.0, 0.0, 50.0 ) ),
	'2021-02' => array( 2, array( 100.0, 50.0, 0.0 ) ),
	'2021-03' => array( 1, array( 100.0, 0.0 ) ),
);
kdna_ei_c( 'Three cohort rows (Dec 2020 customer is not a row)', array_keys( $kdna_ei_expected ) === array_keys( $kdna_ei_grid ), array_keys( $kdna_ei_grid ) );
foreach ( $kdna_ei_expected as $month => $expect ) {
	$got = $kdna_ei_grid[ $month ] ?? null;
	kdna_ei_c( "{$month}: size and retention match", $got && $expect[0] === $got[0] && $expect[1] === array_map( 'floatval', $got[1] ), $got );
}

$kdna_ei_short = KDNA_EcommerceInsights_Report::cohorts( KDNA_EcommerceInsights_Dates::resolve( 'custom', '2021-01-01', '2021-02-28' ) );
kdna_ei_c( 'A shorter range counts returns only up to its end', 2 === count( $kdna_ei_short ) && array( 100.0, 50.0 ) === array_map( 'floatval', $kdna_ei_short[0]['retention'] ), $kdna_ei_short );

$kdna_ei_req = new WP_REST_Request( 'GET', '/kdna-ei/v1/export' );
$kdna_ei_req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$kdna_ei_req->set_param( 'table', 'cohorts' );
$kdna_ei_req->set_param( 'start', '2021-01-01' );
$kdna_ei_req->set_param( 'end', '2021-04-30' );
$kdna_ei_out = rest_do_request( $kdna_ei_req )->get_data();
kdna_ei_c( 'Cohort CSV has a header and three rows', 4 === count( array_filter( explode( "\n", trim( $kdna_ei_out['csv'] ?? '' ) ) ) ), $kdna_ei_out );

/*
 * ---------------------------------------------------------------------
 * Days of stock left and reorder dates
 * ---------------------------------------------------------------------
 */
echo "\n== Days of stock left and reorder dates ==\n";

$kdna_ei_cover = KDNA_EcommerceInsights_Inventory::cover( 30, 30, '2026-01-01', 14 );
kdna_ei_c( '30 in stock, 1 a day: 30 days, runs out 31 Jan, reorder 17 Jan', 30 === $kdna_ei_cover['days'] && '2026-01-31' === $kdna_ei_cover['runs_out'] && '2026-01-17' === $kdna_ei_cover['reorder'] && ! $kdna_ei_cover['reorder_now'], $kdna_ei_cover );
$kdna_ei_cover = KDNA_EcommerceInsights_Inventory::cover( 10, 30, '2026-01-01', 14 );
kdna_ei_c( '10 days left with a 14 day lead time: reorder now (today)', 10 === $kdna_ei_cover['days'] && $kdna_ei_cover['reorder_now'] && '2026-01-01' === $kdna_ei_cover['reorder'], $kdna_ei_cover );
$kdna_ei_cover = KDNA_EcommerceInsights_Inventory::cover( 5, 0, '2026-01-01', 14 );
kdna_ei_c( 'No sales in 30 days: no estimate', null === $kdna_ei_cover['days'] && null === $kdna_ei_cover['reorder'], $kdna_ei_cover );
$kdna_ei_cover = KDNA_EcommerceInsights_Inventory::cover( 0, 9, '2026-01-01', 14 );
kdna_ei_c( 'None left but still selling: 0 days, reorder now', 0 === $kdna_ei_cover['days'] && $kdna_ei_cover['reorder_now'], $kdna_ei_cover );
$kdna_ei_cover = KDNA_EcommerceInsights_Inventory::cover( 100, 15, '2026-01-01', 14 );
kdna_ei_c( '100 in stock, 0.5 a day: 200 days', 200 === $kdna_ei_cover['days'] && '2026-07-20' === $kdna_ei_cover['runs_out'], $kdna_ei_cover );

// A real product selling 30 units over the last 30 days.
$kdna_ei_stocked = new WC_Product_Simple();
$kdna_ei_stocked->set_name( 'KDNA test stocked product' );
$kdna_ei_stocked->set_regular_price( '50' );
$kdna_ei_stocked->set_manage_stock( true );
$kdna_ei_stocked->set_stock_quantity( 45 );
$kdna_ei_stocked->save();
$kdna_ei_products[] = $kdna_ei_stocked->get_id();
KDNA_EcommerceInsights_Costs::set_cost( $kdna_ei_stocked, 20.0 );

$kdna_ei_today = new DateTimeImmutable( 'today', wp_timezone() );
for ( $d = 0; $d < 10; $d++ ) {
	++$kdna_ei_id;
	kdna_ei_c_fact( $kdna_ei_id, 'kdna-stock', $kdna_ei_today->modify( '-' . ( $d * 3 ) . ' days' )->format( 'Y-m-d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( KDNA_EcommerceInsights_Install::table( 'order_item_facts' ), array( 'order_id' => $kdna_ei_id, 'order_item_id' => $kdna_ei_id, 'product_id' => $kdna_ei_stocked->get_id(), 'qty' => 3, 'line_net' => 150, 'unit_cost' => 20, 'line_cost' => 60 ) );
}
KDNA_EcommerceInsights_Cost_Catalogue::flush();
KDNA_EcommerceInsights_Cache::flush();

$kdna_ei_report = KDNA_EcommerceInsights_Inventory::report();
$kdna_ei_row    = current( array_filter( $kdna_ei_report['days_of_cover'], static fn( $r ) => $r['id'] === $kdna_ei_stocked->get_id() ) );
$kdna_ei_lead   = (int) KDNA_EcommerceInsights_Settings::get( 'alerts.reorder_lead_days', 14 );
kdna_ei_c( 'Stocked product is in the reorder planner', (bool) $kdna_ei_row );
kdna_ei_c( 'Sells 1 a day, 45 days left', $kdna_ei_row && 1.0 === (float) $kdna_ei_row['per_day'] && 45 === $kdna_ei_row['days'], $kdna_ei_row );
kdna_ei_c( 'Reorder date is the lead time before it runs out', $kdna_ei_row && $kdna_ei_today->modify( '+' . ( 45 - $kdna_ei_lead ) . ' days' )->format( 'Y-m-d' ) === $kdna_ei_row['reorder'], $kdna_ei_row );
kdna_ei_c( 'Value at cost 45 x 20 = 900', $kdna_ei_row && 900.0 === (float) $kdna_ei_row['value_cost'], $kdna_ei_row );

/*
 * ---------------------------------------------------------------------
 * Low stock thresholds
 * ---------------------------------------------------------------------
 */
echo "\n== Low stock thresholds ==\n";

$kdna_ei_stocked->set_stock_quantity( 8 );
$kdna_ei_stocked->set_low_stock_amount( 10 );
$kdna_ei_stocked->save();
KDNA_EcommerceInsights_Cost_Catalogue::flush();
KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'low_stock_threshold' => 0 ) ) );

$kdna_ei_low = static fn() => in_array( $kdna_ei_stocked->get_id(), wp_list_pluck( KDNA_EcommerceInsights_Inventory::report()['low_stock'], 'id' ), true );
kdna_ei_c( "Uses the product's own low stock amount (8 left, amount 10): low", $kdna_ei_low() );
KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'low_stock_threshold' => 5 ) ) );
KDNA_EcommerceInsights_Cost_Catalogue::flush();
kdna_ei_c( 'The Insights setting (5) overrides it: not low', ! $kdna_ei_low() );
KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'low_stock_threshold' => 0 ) ) );
KDNA_EcommerceInsights_Cost_Catalogue::flush();

/*
 * ---------------------------------------------------------------------
 * Variable product sharing its parent's stock
 * ---------------------------------------------------------------------
 */
echo "\n== Shared variable stock is counted once ==\n";

$kdna_ei_variable = new WC_Product_Variable();
$kdna_ei_variable->set_name( 'KDNA test shared stock' );
$kdna_ei_variable->set_manage_stock( true );
$kdna_ei_variable->set_stock_quantity( 20 );
$kdna_ei_variable->save();
$kdna_ei_products[] = $kdna_ei_variable->get_id();
foreach ( array( '30ml', '50ml' ) as $size ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $kdna_ei_variable->get_id() );
	$variation->set_attributes( array( 'size' => $size ) );
	$variation->set_regular_price( '30' );
	$variation->save();
	KDNA_EcommerceInsights_Costs::set_cost( $variation, 10.0 );
}
KDNA_EcommerceInsights_Cost_Catalogue::flush();

$kdna_ei_items  = array_filter( KDNA_EcommerceInsights_Inventory::items(), static fn( $i ) => $i['product_id'] === $kdna_ei_variable->get_id() );
$kdna_ei_shared = current( $kdna_ei_items );
kdna_ei_c( 'One stock item for the parent, not one per variation', 1 === count( $kdna_ei_items ), array_values( $kdna_ei_items ) );
kdna_ei_c( 'It holds 20 units, valued at the variations\' cost', $kdna_ei_shared && 20.0 === $kdna_ei_shared['stock'] && 10.0 === $kdna_ei_shared['cost'], $kdna_ei_shared );
kdna_ei_c( 'Sales of both variations count towards it', $kdna_ei_shared && 3 === count( $kdna_ei_shared['members'] ), $kdna_ei_shared );

/*
 * ---------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------
 */
echo "\n== Nightly snapshot ==\n";

$kdna_ei_started = microtime( true );
$kdna_ei_saved   = KDNA_EcommerceInsights_Inventory::snapshot( '2021-06-01' );
$kdna_ei_ms      = ( microtime( true ) - $kdna_ei_started ) * 1000;
$kdna_ei_managed = count( array_filter( KDNA_EcommerceInsights_Inventory::items(), static fn( $i ) => $i['managed'] ) );
$kdna_ei_snap    = $wpdb->get_row( "SELECT COUNT(*) AS n, SUM( value_at_cost ) AS cost FROM " . KDNA_EcommerceInsights_Install::table( 'stock_snapshots' ) . " WHERE snapshot_date = '2021-06-01'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
kdna_ei_c( 'One row per tracked item', $kdna_ei_managed === $kdna_ei_saved && $kdna_ei_saved === (int) $kdna_ei_snap['n'], array( $kdna_ei_managed, $kdna_ei_saved, $kdna_ei_snap ) );
kdna_ei_c( sprintf( 'Quick: %d ms for %d items (under 2,000 ms)', $kdna_ei_ms, $kdna_ei_saved ), $kdna_ei_ms < 2000 );
kdna_ei_c( 'Snapshot value matches the stock value at cost', abs( (float) $kdna_ei_snap['cost'] - (float) current( array_filter( KDNA_EcommerceInsights_Inventory::report()['metrics'], static fn( $m ) => 'stock_value_cost' === $m['key'] ) )['value'] ) < 0.05, $kdna_ei_snap );
KDNA_EcommerceInsights_Inventory::snapshot( '2021-06-01' );
$kdna_ei_again = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . KDNA_EcommerceInsights_Install::table( 'stock_snapshots' ) . " WHERE snapshot_date = '2021-06-01'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
kdna_ei_c( 'Running it twice replaces the day, no duplicates', $kdna_ei_again === $kdna_ei_saved, $kdna_ei_again );
kdna_ei_c( 'Snapshot runs after the nightly check', false !== has_action( 'kdna_ei_nightly_done', array( 'KDNA_EcommerceInsights_Inventory', 'nightly' ) ) );

/*
 * ---------------------------------------------------------------------
 * Low stock emails
 * ---------------------------------------------------------------------
 */
echo "\n== Low stock emails ==\n";

$GLOBALS['kdna_ei_mails'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $return, $atts ) {
		$GLOBALS['kdna_ei_mails'][] = $atts;
		return true;
	},
	1,
	2
);

KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'low_stock_emails' => false ) ) );
$kdna_ei_result = KDNA_EcommerceInsights_Inventory::send_alerts();
kdna_ei_c( 'Nothing is sent while emails are off', ! $kdna_ei_result['sent'] && ! $GLOBALS['kdna_ei_mails'] );

KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'low_stock_emails' => true, 'alert_recipients' => 'stock@example.com' ) ) );
delete_option( KDNA_EcommerceInsights_Inventory::ALERTED_OPTION );
$kdna_ei_result = KDNA_EcommerceInsights_Inventory::send_alerts();
$kdna_ei_mail   = end( $GLOBALS['kdna_ei_mails'] );
kdna_ei_c( 'First check emails the low and out of stock products', $kdna_ei_result['sent'] && $kdna_ei_result['items'] > 0, $kdna_ei_result );
kdna_ei_c( 'Sent to the alert recipients', $kdna_ei_mail && array( 'stock@example.com' ) === (array) $kdna_ei_mail['to'], $kdna_ei_mail['to'] ?? null );
kdna_ei_c( 'Lists the low stock test product', $kdna_ei_mail && false !== strpos( $kdna_ei_mail['message'], 'KDNA test stocked product' ) );

$kdna_ei_count  = count( $GLOBALS['kdna_ei_mails'] );
$kdna_ei_result = KDNA_EcommerceInsights_Inventory::send_alerts();
kdna_ei_c( 'A second check with nothing new sends nothing', ! $kdna_ei_result['sent'] && count( $GLOBALS['kdna_ei_mails'] ) === $kdna_ei_count, $kdna_ei_result );

$kdna_ei_stocked->set_stock_quantity( 100 );
$kdna_ei_stocked->save();
KDNA_EcommerceInsights_Cost_Catalogue::flush();
KDNA_EcommerceInsights_Inventory::send_alerts();
$kdna_ei_stocked->set_stock_quantity( 3 );
$kdna_ei_stocked->save();
KDNA_EcommerceInsights_Cost_Catalogue::flush();
$kdna_ei_result = KDNA_EcommerceInsights_Inventory::send_alerts();
$kdna_ei_mail   = end( $GLOBALS['kdna_ei_mails'] );
kdna_ei_c( 'Restocked then low again: alerts again, for just that product', $kdna_ei_result['sent'] && 1 === $kdna_ei_result['items'] && false !== strpos( $kdna_ei_mail['message'], 'KDNA test stocked product' ), $kdna_ei_result );

$kdna_ei_result = KDNA_EcommerceInsights_Inventory::send_alerts( true );
kdna_ei_c( 'Test email lists everything currently low or out', $kdna_ei_result['sent'] && $kdna_ei_result['items'] > 1, $kdna_ei_result );
kdna_ei_c( 'Test email subject says it is a test', false !== strpos( end( $GLOBALS['kdna_ei_mails'] )['subject'], 'Test' ) );

/*
 * ---------------------------------------------------------------------
 * Clean up
 * ---------------------------------------------------------------------
 */
KDNA_EcommerceInsights_Settings::update( array( 'alerts' => $kdna_ei_saved_alerts ) );
if ( null === $kdna_ei_saved_sent ) {
	delete_option( KDNA_EcommerceInsights_Inventory::ALERTED_OPTION );
} else {
	update_option( KDNA_EcommerceInsights_Inventory::ALERTED_OPTION, $kdna_ei_saved_sent, false );
}
kdna_ei_c_cleanup( $kdna_ei_orders, $kdna_ei_products );
KDNA_EcommerceInsights_Summary::rebuild_range( '2021-08-01', '2021-08-31' );

echo "\n" . $GLOBALS['kdna_ei_c_count'] . ' checks, ' . $GLOBALS['kdna_ei_c_fail'] . " failed\n";
