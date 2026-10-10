<?php
/**
 * Integration tests for Stage 7: the Products report with 1,000+ products
 * (sorting, search, filters, paging and speed), the product drill-down
 * route, the P&L matching the summary, and the CSV exports.
 *
 * It adds 1,200 made-up products' worth of sales facts in February 2022
 * (a month no other test uses), checks them, then removes them again.
 *
 * Test site only:
 *
 *     wp eval-file tests/test-products-scale.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-products-scale.php\n" );

$GLOBALS['kdna_ei_p_fail']  = 0;
$GLOBALS['kdna_ei_p_count'] = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Detail shown on failure.
 */
function kdna_ei_p( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_p_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_p_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : '  >> ' . ( is_string( $note ) ? $note : wp_json_encode( $note ) ) ) . "\n";
}

/**
 * Calls a plugin REST route as the current user with a valid nonce.
 *
 * @param string $path   Path after kdna-ei/v1.
 * @param array  $params Parameters.
 * @return WP_REST_Response
 */
function kdna_ei_p_call( string $path, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/kdna-ei/v1' . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	return rest_do_request( $request );
}

/**
 * Removes the made-up facts.
 */
function kdna_ei_p_cleanup(): void {
	global $wpdb;
	$orders = KDNA_EcommerceInsights_Install::table( 'order_facts' );
	$items  = KDNA_EcommerceInsights_Install::table( 'order_item_facts' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$items} WHERE order_id >= 900000000" );
	$wpdb->query( "DELETE FROM {$orders} WHERE order_id >= 900000000" );
	// phpcs:enable
	KDNA_EcommerceInsights_Cache::flush();
}

wp_set_current_user( 1 );
global $wpdb;

$kdna_ei_orders = KDNA_EcommerceInsights_Install::table( 'order_facts' );
$kdna_ei_items  = KDNA_EcommerceInsights_Install::table( 'order_item_facts' );
$kdna_ei_count  = 1200;

kdna_ei_p_cleanup();

// One order per product per day for three days, so each product has 3 sales.
// Every tenth product loses money; every seventh has no cost.
$kdna_ei_order_id = 900000000;
$kdna_ei_item_id  = 900000000;
for ( $day = 1; $day <= 3; $day++ ) {
	$order_rows = array();
	$item_rows  = array();
	for ( $n = 1; $n <= $kdna_ei_count; $n++ ) {
		++$kdna_ei_order_id;
		++$kdna_ei_item_id;
		$product = 800000000 + $n;
		$revenue = 10 + $n % 97;
		$cost    = 0 === $n % 7 ? 0 : ( 0 === $n % 10 ? $revenue * 1.2 : $revenue * 0.4 );
		$missing = 0 === $n % 7 ? 1 : 0;

		$order_rows[] = $wpdb->prepare( '(%d, %s, %s, %s)', $kdna_ei_order_id, sprintf( '2022-02-%02d', $day ), 'completed', 'AUD' );
		$item_rows[]  = $wpdb->prepare( '(%d, %d, %d, 0, 1, %f, %s, %f, 0, 0, %d)', $kdna_ei_order_id, $kdna_ei_item_id, $product, $revenue, $missing ? null : $cost, $cost, $missing );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "INSERT INTO {$kdna_ei_orders} ( order_id, report_date, status, currency ) VALUES " . implode( ',', $order_rows ) );
	$wpdb->query( "INSERT INTO {$kdna_ei_items} ( order_id, order_item_id, product_id, variation_id, qty, line_net, unit_cost, line_cost, refunded_qty, refunded_amount, missing_cost ) VALUES " . str_replace( "''", 'NULL', implode( ',', $item_rows ) ) );
	// phpcs:enable
}
KDNA_EcommerceInsights_Cache::flush();

$kdna_ei_range = array(
	'preset' => 'custom',
	'start'  => '2022-02-01',
	'end'    => '2022-02-28',
);

echo "\n== Products table with {$kdna_ei_count} products ==\n";

$started  = microtime( true );
$response = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'fresh' => true ) );
$elapsed  = ( microtime( true ) - $started ) * 1000;
$data     = $response->get_data()['data'] ?? array();

kdna_ei_p( 'Route answers', 200 === $response->get_status(), $response->get_data() );
kdna_ei_p( 'Counts every product', $kdna_ei_count === ( $data['total'] ?? 0 ), $data['total'] ?? null );
kdna_ei_p( '25 rows on page one of 48', 25 === count( $data['rows'] ?? array() ) && 48 === ( $data['pages'] ?? 0 ), array( count( $data['rows'] ?? array() ), $data['pages'] ?? null ) );
kdna_ei_p( sprintf( 'Fast enough: %d ms (under 1,500 ms)', $elapsed ), $elapsed < 1500 );

$profits = wp_list_pluck( $data['rows'], 'profit' );
$sorted  = $profits;
rsort( $sorted );
kdna_ei_p( 'Sorted by profit, highest first, by default', $profits === $sorted );
// Every tenth product loses money, except those that are also every seventh (no cost).
$kdna_ei_losses = count( array_filter( range( 1, $kdna_ei_count ), static fn( $n ) => 0 === $n % 10 && 0 !== $n % 7 ) );
kdna_ei_p( "Loss count is every tenth product with a cost ({$kdna_ei_losses})", $kdna_ei_losses === ( $data['loss_count'] ?? 0 ), $data['loss_count'] ?? null );
kdna_ei_p( 'Best and worst performers listed', 5 === count( $data['best'] ) && 5 === count( $data['worst'] ) );
kdna_ei_p( 'Worst performer loses money', $data['worst'][0]['profit'] < 0 );

$asc = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'orderby' => 'revenue', 'order' => 'asc' ) )->get_data()['data'];
$rev = wp_list_pluck( $asc['rows'], 'revenue' );
$chk = $rev;
sort( $chk );
kdna_ei_p( 'Sorts by revenue, lowest first', $rev === $chk );

$by_name = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'orderby' => 'name', 'order' => 'asc' ) )->get_data()['data'];
$names   = wp_list_pluck( $by_name['rows'], 'name' );
$chk     = $names;
natcasesort( $chk );
kdna_ei_p( 'Sorts by name, A to Z', array_values( $chk ) === $names );

$last = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'page' => 48 ) )->get_data()['data'];
kdna_ei_p( 'Last page (48) is full: 25 x 48 = 1,200', 25 === count( $last['rows'] ) && 48 === $last['page'] );

$beyond = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'page' => 500 ) )->get_data()['data'];
kdna_ei_p( 'A page past the end shows the last page', 48 === $beyond['page'] );

$hundred = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'per_page' => 100, 'page' => 2 ) )->get_data()['data'];
kdna_ei_p( '100 per page gives 12 pages', 12 === $hundred['pages'] && 100 === count( $hundred['rows'] ) );

$search = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'search' => '#800000777' ) )->get_data()['data'];
kdna_ei_p( 'Search finds one product', 1 === $search['total'] && 800000777 === $search['rows'][0]['product_id'], $search['total'] );

$loss = kdna_ei_p_call( '/products', $kdna_ei_range + array( 'loss_only' => true ) )->get_data()['data'];
kdna_ei_p( 'Loss-making filter keeps only losses', $kdna_ei_losses === $loss['total'] && max( wp_list_pluck( $loss['rows'], 'profit' ) ) < 0, $loss['total'] );

$missing = array_filter( $by_name['rows'], static fn( $row ) => $row['missing_cost'] );
kdna_ei_p( 'Products without a cost are flagged', count( $missing ) > 0 );

echo "\n== Product drawer ==\n";

$one    = $search['rows'][0];
$detail = kdna_ei_p_call( '/products/' . $one['product_id'], $kdna_ei_range + array( 'compare' => 'none' ) );
$d      = $detail->get_data()['data'] ?? array();
kdna_ei_p( 'Drawer route answers', 200 === $detail->get_status(), $detail->get_data() );
kdna_ei_p( 'Drawer totals match the table row', abs( $d['totals']['profit'] - $one['profit'] ) < 0.01 && abs( $d['totals']['revenue'] - $one['revenue'] ) < 0.01, array( $d['totals'], $one ) );
kdna_ei_p( 'Drawer series adds up to the total', abs( array_sum( $d['series']['revenue'] ) - $d['totals']['revenue'] ) < 0.01 );
kdna_ei_p( 'Drawer has one point per day in February', 28 === count( $d['buckets'] ) && 'day' === $d['granularity'] );
kdna_ei_p( 'No comparison when none chosen', null === $d['previous'] );

$no_nonce = new WP_REST_Request( 'GET', '/kdna-ei/v1/products/' . $one['product_id'] );
kdna_ei_p( 'Drawer route needs a nonce', 401 === rest_do_request( $no_nonce )->get_status() );

echo "\n== Exports ==\n";

$csv   = kdna_ei_p_call( '/export', $kdna_ei_range + array( 'table' => 'products' ) )->get_data();
$lines = array_filter( explode( "\n", trim( $csv['csv'] ?? '' ) ) );
kdna_ei_p( 'Products CSV has every product plus a header', $kdna_ei_count + 1 === count( $lines ), count( $lines ) );
kdna_ei_p( 'Products CSV file name has the dates', 'kdna-insights-products-2022-02-01-to-2022-02-28.csv' === ( $csv['filename'] ?? '' ), $csv['filename'] ?? '' );

$csv   = kdna_ei_p_call( '/export', $kdna_ei_range + array( 'table' => 'products', 'loss_only' => true ) )->get_data();
$lines = array_filter( explode( "\n", trim( $csv['csv'] ?? '' ) ) );
kdna_ei_p( 'Products CSV follows the loss-making filter', $kdna_ei_losses + 1 === count( $lines ), count( $lines ) );

$csv = kdna_ei_p_call( '/export', $kdna_ei_range + array( 'table' => 'categories' ) )->get_data();
kdna_ei_p( 'Categories CSV works', false !== strpos( $csv['csv'] ?? '', 'Category' ), $csv );

$csv = kdna_ei_p_call( '/export', $kdna_ei_range + array( 'table' => 'pnl' ) )->get_data();
kdna_ei_p( 'P&L CSV ends with the net margin row', false !== strpos( $csv['csv'] ?? '', 'Net margin %' ), $csv );

echo "\n== P&L matches the summary ==\n";

$month  = array(
	'preset'  => 'last_month',
	'compare' => 'none',
);
$sum    = kdna_ei_p_call( '/summary', $month + array( 'metrics' => 'net_revenue,gross_profit,contribution_profit,net_profit' ) )->get_data()['data']['metrics'];
$profit = kdna_ei_p_call( '/profit', $month )->get_data()['data'];
$lines  = array();
foreach ( $profit['waterfall'] as $line ) {
	$lines[ $line['key'] ] = $line['amount'];
}
foreach ( $sum as $metric ) {
	kdna_ei_p( 'P&L ' . $metric['label'] . ' matches the summary', abs( $lines[ $metric['key'] ] - $metric['value'] ) < 0.01, array( $lines[ $metric['key'] ], $metric['value'] ) );
}
$month_total = 0.0;
foreach ( $profit['months'] as $m ) {
	$month_total += $m['lines']['net_profit'];
}
kdna_ei_p( 'Monthly statement adds up to the total', abs( $month_total - $lines['net_profit'] ) < 0.05, array( $month_total, $lines['net_profit'] ) );

kdna_ei_p_cleanup();
$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$kdna_ei_orders} WHERE order_id >= 900000000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
kdna_ei_p( 'Made-up facts removed', 0 === $left );

echo "\n" . $GLOBALS['kdna_ei_p_count'] . ' checks, ' . $GLOBALS['kdna_ei_p_fail'] . " failed\n";
