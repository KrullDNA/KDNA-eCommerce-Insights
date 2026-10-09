<?php
/**
 * Tests for Stage 11: the GST and tax summary (checked against hand-worked
 * test orders), financial year and period choices, digest timing, content,
 * scheduling and sending, the printable report data and every CSV export.
 *
 * Runs inside WordPress with WooCommerce and the plugin active, on a test
 * site only (it creates and deletes test orders, products, an overhead and
 * ad spend in 2023):
 *
 *     wp eval-file tests/test-tax-reports.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-tax-reports.php\n" );

$GLOBALS['kdna_ei_tr_count'] = 0;
$GLOBALS['kdna_ei_tr_fail']  = 0;
$GLOBALS['kdna_ei_tr_clean'] = array(
	'orders'   => array(),
	'products' => array(),
);

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Shown on failure.
 */
function kdna_ei_tr( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_tr_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_tr_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : ' (' . ( is_scalar( $note ) ? $note : wp_json_encode( $note ) ) . ')' ) . "\n";
}

/**
 * Checks an amount to the cent.
 *
 * @param string $label    What is being checked.
 * @param float  $expected Expected amount.
 * @param mixed  $actual   Actual amount.
 */
function kdna_ei_tr_amount( string $label, float $expected, $actual ): void {
	kdna_ei_tr( $label, null !== $actual && abs( $expected - (float) $actual ) < 0.005, 'expected ' . $expected . ', got ' . wp_json_encode( $actual ) );
}

/**
 * Creates an order with GST set exactly, as a store charging 10% would.
 *
 * @param WC_Product $product  Product.
 * @param int        $qty      Quantity.
 * @param float      $shipping Shipping charged, excluding GST.
 * @param float      $gst      GST on the items.
 * @param float      $ship_gst GST on shipping.
 * @param string     $paid     Paid date, Y-m-d H:i:s site time.
 * @param string     $status   Order status.
 * @return WC_Order
 */
function kdna_ei_tr_order( WC_Product $product, int $qty, float $shipping, float $gst, float $ship_gst, string $paid, string $status = 'processing' ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	if ( $shipping > 0 ) {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_id( 'flat_rate' );
		$item->set_method_title( 'Flat rate' );
		$item->set_total( (string) $shipping );
		$order->add_item( $item );
	}
	$order->set_billing_email( 'gst-test@example.com' );
	$order->set_payment_method( 'bacs' );
	$order->calculate_totals( false );
	$order->set_cart_tax( (string) $gst );
	$order->set_shipping_tax( (string) $ship_gst );
	$order->set_total( (string) ( (float) $order->get_total() + $gst + $ship_gst ) );
	$order->set_status( $status );
	$order->set_date_created( get_gmt_from_date( $paid, 'U' ) );
	if ( 'pending' !== $status ) {
		$order->set_date_paid( get_gmt_from_date( $paid, 'U' ) );
	}
	$order->save();
	$GLOBALS['kdna_ei_tr_clean']['orders'][] = $order->get_id();
	return $order;
}

/**
 * Processes an order now so its figures reach the summary tables.
 *
 * @param WC_Order $order Order.
 */
function kdna_ei_tr_process( WC_Order $order ): void {
	$days = KDNA_EcommerceInsights_Order_Processor::process( $order->get_id() );
	KDNA_EcommerceInsights_Summary::rebuild_days( $days );
}

/**
 * Calls a plugin REST route as the current user with a valid nonce.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function kdna_ei_tr_rest( WP_REST_Request $request ): WP_REST_Response {
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	return rest_do_request( $request );
}

/**
 * A range for two dates.
 *
 * @param string $start Y-m-d.
 * @param string $end   Y-m-d.
 * @return array
 */
function kdna_ei_tr_range( string $start, string $end ): array {
	return KDNA_EcommerceInsights_Dates::resolve( 'custom', $start, $end );
}

wp_set_current_user( 1 );
$kdna_ei_tr_settings = get_option( KDNA_EcommerceInsights_Settings::OPTION );
$kdna_ei_tr_last     = get_option( KDNA_EcommerceInsights_Digest::LAST_OPTION, null );
KDNA_EcommerceInsights_Settings::update(
	array(
		'tax'     => array(
			'system'           => 'au_gst',
			'rate'             => 10,
			'reporting_period' => 'quarterly',
		),
		'general' => array(
			'refund_dating' => 'refund_date',
			'date_basis'    => 'paid',
		),
	)
);
KDNA_EcommerceInsights_Cache::flush();

/*
 * -------------------------------------------------------------------------
 * GST against hand-worked orders
 * -------------------------------------------------------------------------
 */
echo "GST summary\n";

$kdna_ei_tr_q    = kdna_ei_tr_range( '2023-07-01', '2023-09-30' );
$kdna_ei_tr_q2   = kdna_ei_tr_range( '2023-10-01', '2023-12-31' );
$kdna_ei_tr_fy   = kdna_ei_tr_range( '2023-07-01', '2023-12-31' );
$kdna_ei_tr_base = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_fy, 'monthly' );
$kdna_ei_tr_bq   = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_fy, 'quarterly' );

$kdna_ei_tr_product = new WC_Product_Simple();
$kdna_ei_tr_product->set_name( 'GST test product' );
$kdna_ei_tr_product->set_regular_price( '50' );
$kdna_ei_tr_product->save();
$GLOBALS['kdna_ei_tr_clean']['products'][] = $kdna_ei_tr_product->get_id();

// A: 2 x $50 + $10 shipping, GST $10 + $1 = $121 paid on 10 July.
$kdna_ei_tr_a = kdna_ei_tr_order( $kdna_ei_tr_product, 2, 10, 10, 1, '2023-07-10 10:00:00' );
// B: 1 x $50, GST $5 = $55 paid on 20 August, $22 (with $2 GST) refunded on 5 September.
$kdna_ei_tr_b = kdna_ei_tr_order( $kdna_ei_tr_product, 1, 0, 5, 0, '2023-08-20 10:00:00' );
// C: 1 x $50, GST $5 = $55 paid on 3 October (next quarter).
$kdna_ei_tr_c = kdna_ei_tr_order( $kdna_ei_tr_product, 1, 0, 5, 0, '2023-10-03 10:00:00' );
// D: never paid, so never counted.
$kdna_ei_tr_d = kdna_ei_tr_order( $kdna_ei_tr_product, 1, 0, 5, 0, '2023-07-15 10:00:00', 'pending' );

$kdna_ei_tr_refund = wc_create_refund(
	array(
		'order_id' => $kdna_ei_tr_b->get_id(),
		'amount'   => '22',
		'reason'   => 'GST test',
	)
);
$kdna_ei_tr_refund->set_cart_tax( '-2' );
$kdna_ei_tr_refund->set_date_created( get_gmt_from_date( '2023-09-05 09:00:00', 'U' ) );
$kdna_ei_tr_refund->save();

foreach ( array( $kdna_ei_tr_a, $kdna_ei_tr_b, $kdna_ei_tr_c, $kdna_ei_tr_d ) as $kdna_ei_tr_o ) {
	kdna_ei_tr_process( wc_get_order( $kdna_ei_tr_o->get_id() ) );
}

// Costs flagged as including GST: $1,100 a month overhead (July to September)
// and $220 of ad spend on 12 August. Plus $500 of ad spend without GST.
$kdna_ei_tr_overhead = KDNA_EcommerceInsights_Overheads::create(
	array(
		'name'         => 'GST test rent',
		'category'     => 'rent',
		'amount'       => 1100,
		'frequency'    => 'monthly',
		'start_date'   => '2023-07-01',
		'end_date'     => '2023-09-30',
		'includes_gst' => true,
	)
);
$kdna_ei_tr_ads   = array();
$kdna_ei_tr_ads[] = KDNA_EcommerceInsights_Ad_Spend::create(
	array(
		'start'         => '2023-08-12',
		'end'           => '2023-08-12',
		'channel'       => 'other',
		'campaign_name' => 'GST test with GST',
		'amount'        => 220,
		'includes_gst'  => true,
	)
);
$kdna_ei_tr_ads[] = KDNA_EcommerceInsights_Ad_Spend::create(
	array(
		'start'         => '2023-08-13',
		'end'           => '2023-08-13',
		'channel'       => 'other',
		'campaign_name' => 'GST test without GST',
		'amount'        => 500,
		'includes_gst'  => false,
	)
);
KDNA_EcommerceInsights_Cache::flush();

$kdna_ei_tr_m = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_fy, 'monthly' );
$kdna_ei_tr_k = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_fy, 'quarterly' );

/**
 * The change in one line for one period since before the test orders.
 *
 * @param array  $after  Summary after.
 * @param array  $before Summary before.
 * @param int    $index  Period position.
 * @param string $line   sales, on_sales, on_costs or net.
 * @return float
 */
function kdna_ei_tr_delta( array $after, array $before, int $index, string $line ): float {
	return (float) $after['periods'][ $index ][ $line ] - (float) $before['periods'][ $index ][ $line ];
}

kdna_ei_tr( 'Monthly view has six months for July to December', 6 === count( $kdna_ei_tr_m['periods'] ), count( $kdna_ei_tr_m['periods'] ) );
kdna_ei_tr( 'Quarterly view has two BAS quarters', 2 === count( $kdna_ei_tr_k['periods'] ) && '2023-09-30' === $kdna_ei_tr_k['periods'][0]['end'], $kdna_ei_tr_k['periods'] );
kdna_ei_tr( 'BAS labels are used for Australian GST', 'G1 Total sales' === $kdna_ei_tr_k['labels']['sales'] && '1A' === $kdna_ei_tr_k['labels']['short'][1] );

kdna_ei_tr_amount( 'July G1: order A, $121 including GST and shipping', 121, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 0, 'sales' ) );
kdna_ei_tr_amount( 'July 1A: $10 on items plus $1 on shipping', 11, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 0, 'on_sales' ) );
kdna_ei_tr_amount( 'July 1B: $100 GST inside the $1,100 rent (unpaid order D ignored)', 100, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 0, 'on_costs' ) );
kdna_ei_tr_amount( 'August G1: order B, $55', 55, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 1, 'sales' ) );
kdna_ei_tr_amount( 'August 1A: $5', 5, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 1, 'on_sales' ) );
kdna_ei_tr_amount( 'August 1B: $100 rent plus $20 inside $220 ad spend (not the $500 without GST)', 120, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 1, 'on_costs' ) );
kdna_ei_tr_amount( 'September G1: the $22 refund comes off', -22, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 2, 'sales' ) );
kdna_ei_tr_amount( 'September 1A: the $2 GST refunded comes off', -2, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 2, 'on_sales' ) );
kdna_ei_tr_amount( 'October G1: order C, $55', 55, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 3, 'sales' ) );
kdna_ei_tr_amount( 'October 1B: rent has ended', 0, kdna_ei_tr_delta( $kdna_ei_tr_m, $kdna_ei_tr_base, 3, 'on_costs' ) );

kdna_ei_tr_amount( 'Jul to Sep G1: 121 + 55 - 22 = 154', 154, kdna_ei_tr_delta( $kdna_ei_tr_k, $kdna_ei_tr_bq, 0, 'sales' ) );
kdna_ei_tr_amount( 'Jul to Sep 1A: 11 + 5 - 2 = 14', 14, kdna_ei_tr_delta( $kdna_ei_tr_k, $kdna_ei_tr_bq, 0, 'on_sales' ) );
kdna_ei_tr_amount( 'Jul to Sep 1B: 3 x 100 rent + 20 ads = 320', 320, kdna_ei_tr_delta( $kdna_ei_tr_k, $kdna_ei_tr_bq, 0, 'on_costs' ) );
kdna_ei_tr_amount( 'Jul to Sep net: 14 - 320 = -306 (refund due)', -306, kdna_ei_tr_delta( $kdna_ei_tr_k, $kdna_ei_tr_bq, 0, 'net' ) );
kdna_ei_tr_amount( '1B split: $300 from overheads', 300, $kdna_ei_tr_k['periods'][0]['costs']['overheads'] - $kdna_ei_tr_bq['periods'][0]['costs']['overheads'] );
kdna_ei_tr_amount( '1B split: $20 from ad spend', 20, $kdna_ei_tr_k['periods'][0]['costs']['ad_spend'] - $kdna_ei_tr_bq['periods'][0]['costs']['ad_spend'] );
kdna_ei_tr_amount( 'Totals add up across the quarters', $kdna_ei_tr_k['periods'][0]['sales'] + $kdna_ei_tr_k['periods'][1]['sales'], $kdna_ei_tr_k['totals']['sales'] );

$kdna_ei_tr_only = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_q, 'quarterly' );
kdna_ei_tr( 'Net position says refund due when 1B is larger', 'refundable' === $kdna_ei_tr_only['position'] || $kdna_ei_tr_only['totals']['net'] >= 0, $kdna_ei_tr_only['totals'] );
kdna_ei_tr( 'The note says it is a guide, not a lodgement', false !== strpos( $kdna_ei_tr_only['note'], 'not a lodgement' ) );
kdna_ei_tr( 'No em dash in the note', false === strpos( $kdna_ei_tr_only['note'], "\u{2014}" ) );

// Refunds dated on the original order move the refund into August.
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'refund_dating' => 'order_date' ) ) );
KDNA_EcommerceInsights_Summary::rebuild_all();
$kdna_ei_tr_m2 = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_fy, 'monthly' );
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'refund_dating' => 'refund_date' ) ) );
KDNA_EcommerceInsights_Summary::rebuild_all();
$kdna_ei_tr_m2_aug = (float) $kdna_ei_tr_m2['periods'][1]['on_sales'] - (float) $kdna_ei_tr_base['periods'][1]['on_sales'];
kdna_ei_tr_amount( 'Refund dated on the order: August 1A becomes 5 - 2 = 3', 3, $kdna_ei_tr_m2_aug );

// Partial and running periods.
$kdna_ei_tr_part = KDNA_EcommerceInsights_Tax::periods( kdna_ei_tr_range( '2023-07-15', '2023-09-30' ), 'quarterly' );
kdna_ei_tr( 'A quarter cut short by the dates is marked as part of a period', 1 === count( $kdna_ei_tr_part ) && $kdna_ei_tr_part[0]['partial'] && 'Jul to Sep 2023' === $kdna_ei_tr_part[0]['period'], $kdna_ei_tr_part );
$kdna_ei_tr_now = KDNA_EcommerceInsights_Tax::periods( KDNA_EcommerceInsights_Dates::resolve( 'this_month' ), 'monthly' );
kdna_ei_tr( 'This month is marked as still running', $kdna_ei_tr_now && $kdna_ei_tr_now[0]['in_progress'] );

// Other tax systems.
KDNA_EcommerceInsights_Settings::update( array( 'tax' => array( 'system' => 'sales_tax' ) ) );
$kdna_ei_tr_st = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_q, 'quarterly' );
kdna_ei_tr( 'Sales tax: no tax claimed on costs', 0.0 === (float) $kdna_ei_tr_st['totals']['on_costs'] && ! $kdna_ei_tr_st['claims_costs'] );
kdna_ei_tr( 'Sales tax: net equals tax collected', abs( $kdna_ei_tr_st['totals']['net'] - $kdna_ei_tr_st['totals']['on_sales'] ) < 0.005 );
KDNA_EcommerceInsights_Settings::update( array( 'tax' => array( 'system' => 'vat' ) ) );
$kdna_ei_tr_vat = KDNA_EcommerceInsights_Tax::summary( $kdna_ei_tr_q, 'quarterly' );
kdna_ei_tr( 'VAT: plain labels, still claims tax on costs', 'Tax on sales' === $kdna_ei_tr_vat['labels']['on_sales'] && $kdna_ei_tr_vat['claims_costs'] );
KDNA_EcommerceInsights_Settings::update( array( 'tax' => array( 'system' => 'au_gst' ) ) );

/*
 * -------------------------------------------------------------------------
 * Financial years and last full period
 * -------------------------------------------------------------------------
 */
echo "\nDates covered\n";

$kdna_ei_tr_screen = KDNA_EcommerceInsights_Dates::resolve( 'this_month', null, null, '2026-10-09' );
$kdna_ei_tr_span   = static fn( $span, $period, $system ) => KDNA_EcommerceInsights_Tax::span_range( $span, $kdna_ei_tr_screen, $period, $system, '2026-10-09' );

$kdna_ei_tr_s = $kdna_ei_tr_span( 'last_period', 'quarterly', 'au_gst' );
kdna_ei_tr( 'Last full quarter on 9 Oct 2026 is July to September', '2026-07-01' === $kdna_ei_tr_s['start'] && '2026-09-30' === $kdna_ei_tr_s['end'], $kdna_ei_tr_s );
$kdna_ei_tr_s = $kdna_ei_tr_span( 'last_period', 'monthly', 'au_gst' );
kdna_ei_tr( 'Last full month is September', '2026-09-01' === $kdna_ei_tr_s['start'] && '2026-09-30' === $kdna_ei_tr_s['end'], $kdna_ei_tr_s );
$kdna_ei_tr_s = $kdna_ei_tr_span( 'this_fy', 'quarterly', 'au_gst' );
kdna_ei_tr( 'Australian financial year starts 1 July and runs to today', '2026-07-01' === $kdna_ei_tr_s['start'] && '2026-10-09' === $kdna_ei_tr_s['end'], $kdna_ei_tr_s );
$kdna_ei_tr_s = $kdna_ei_tr_span( 'last_fy', 'quarterly', 'au_gst' );
kdna_ei_tr( 'Last Australian financial year is July 2025 to June 2026', '2025-07-01' === $kdna_ei_tr_s['start'] && '2026-06-30' === $kdna_ei_tr_s['end'], $kdna_ei_tr_s );
$kdna_ei_tr_s = $kdna_ei_tr_span( 'this_fy', 'quarterly', 'vat' );
kdna_ei_tr( 'Other systems use calendar years', '2026-01-01' === $kdna_ei_tr_s['start'], $kdna_ei_tr_s );
$kdna_ei_tr_s = $kdna_ei_tr_span( 'range', 'quarterly', 'au_gst' );
kdna_ei_tr( 'Chosen date range is left as it is', $kdna_ei_tr_screen['start'] === $kdna_ei_tr_s['start'] && $kdna_ei_tr_screen['end'] === $kdna_ei_tr_s['end'] );
$kdna_ei_tr_jan = KDNA_EcommerceInsights_Tax::span_range( 'this_fy', $kdna_ei_tr_screen, 'quarterly', 'au_gst', '2026-03-02' );
kdna_ei_tr( 'In March the Australian financial year began the July before', '2025-07-01' === $kdna_ei_tr_jan['start'], $kdna_ei_tr_jan );

/*
 * -------------------------------------------------------------------------
 * REST and exports
 * -------------------------------------------------------------------------
 */
echo "\nREST and exports\n";

$kdna_ei_tr_req = new WP_REST_Request( 'GET', '/kdna-ei/v1/tax' );
$kdna_ei_tr_req->set_query_params( array( 'preset' => 'custom', 'start' => '2023-07-01', 'end' => '2023-12-31', 'period' => 'monthly', 'fresh' => true ) );
$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
kdna_ei_tr( 'GET /tax answers with the monthly split', 200 === $kdna_ei_tr_res->get_status() && 6 === count( $kdna_ei_tr_res->get_data()['data']['periods'] ?? array() ), $kdna_ei_tr_res->get_status() );
$kdna_ei_tr_req->set_query_params( array( 'span' => 'last_fy', 'fresh' => true ) );
$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
kdna_ei_tr( 'GET /tax?span=last_fy covers a whole financial year', 200 === $kdna_ei_tr_res->get_status() && '-07-01' === substr( $kdna_ei_tr_res->get_data()['data']['range']['start'] ?? '', 4 ), $kdna_ei_tr_res->get_data()['data']['range'] ?? '' );

$kdna_ei_tr_tables = KDNA_EcommerceInsights_Rest_Export::TABLES;
$kdna_ei_tr_listed = array();
foreach ( KDNA_EcommerceInsights_Rest_Export::catalogue() as $kdna_ei_tr_group ) {
	$kdna_ei_tr_listed = array_merge( $kdna_ei_tr_listed, array_keys( $kdna_ei_tr_group ) );
}
kdna_ei_tr( 'Every listed export is a real table', ! array_diff( $kdna_ei_tr_listed, $kdna_ei_tr_tables ), array_diff( $kdna_ei_tr_listed, $kdna_ei_tr_tables ) );
kdna_ei_tr( 'Every table except the drawer variations is listed somewhere', array( 'variations' ) === array_values( array_diff( $kdna_ei_tr_tables, $kdna_ei_tr_listed ) ), array_diff( $kdna_ei_tr_tables, $kdna_ei_tr_listed ) );

$kdna_ei_tr_bad = array();
foreach ( $kdna_ei_tr_tables as $kdna_ei_tr_table ) {
	$kdna_ei_tr_req = new WP_REST_Request( 'GET', '/kdna-ei/v1/export' );
	$kdna_ei_tr_req->set_query_params( array( 'table' => $kdna_ei_tr_table, 'preset' => 'custom', 'start' => '2023-07-01', 'end' => '2023-12-31', 'product' => $kdna_ei_tr_product->get_id() ) );
	$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
	$kdna_ei_tr_csv = $kdna_ei_tr_res->get_data()['csv'] ?? '';
	if ( 200 !== $kdna_ei_tr_res->get_status() || substr_count( $kdna_ei_tr_csv, "\n" ) < 1 ) {
		$kdna_ei_tr_bad[] = $kdna_ei_tr_table . ':' . $kdna_ei_tr_res->get_status();
	}
}
kdna_ei_tr( 'Every one of the ' . count( $kdna_ei_tr_tables ) . ' tables downloads as CSV', ! $kdna_ei_tr_bad, $kdna_ei_tr_bad );

$kdna_ei_tr_req = new WP_REST_Request( 'GET', '/kdna-ei/v1/export' );
$kdna_ei_tr_req->set_query_params( array( 'table' => 'tax', 'preset' => 'custom', 'start' => '2023-07-01', 'end' => '2023-09-30', 'period' => 'quarterly' ) );
$kdna_ei_tr_csv = (string) ( kdna_ei_tr_rest( $kdna_ei_tr_req )->get_data()['csv'] ?? '' );
kdna_ei_tr( 'Tax CSV has the BAS headings, a total and the guide note', false !== strpos( $kdna_ei_tr_csv, 'G1 Total sales' ) && false !== strpos( $kdna_ei_tr_csv, 'Total,' ) && false !== strpos( $kdna_ei_tr_csv, 'not a lodgement' ) );

wp_set_current_user( 0 );
$kdna_ei_tr_res = rest_do_request( new WP_REST_Request( 'GET', '/kdna-ei/v1/digest' ) );
kdna_ei_tr( 'Digest routes are for administrators only', in_array( $kdna_ei_tr_res->get_status(), array( 401, 403 ), true ), $kdna_ei_tr_res->get_status() );
wp_set_current_user( 1 );

/*
 * -------------------------------------------------------------------------
 * Digest
 * -------------------------------------------------------------------------
 */
echo "\nDigest\n";

$kdna_ei_tr_tz  = wp_timezone();
$kdna_ei_tr_at  = static fn( $when ) => new DateTimeImmutable( $when, $kdna_ei_tr_tz );
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'week_start' => 1 ) ) );
kdna_ei_tr( 'Weekly: Friday 9 Oct 2026 gives Monday 12 Oct at 7am', '2026-10-12 07:00' === KDNA_EcommerceInsights_Digest::next_due( 'weekly', $kdna_ei_tr_at( '2026-10-09 10:00' ) )->format( 'Y-m-d H:i' ) );
kdna_ei_tr( 'Weekly: Monday at 6am gives the same day', '2026-10-12 07:00' === KDNA_EcommerceInsights_Digest::next_due( 'weekly', $kdna_ei_tr_at( '2026-10-12 06:00' ) )->format( 'Y-m-d H:i' ) );
kdna_ei_tr( 'Weekly: Monday at 7.30am gives the next Monday', '2026-10-19 07:00' === KDNA_EcommerceInsights_Digest::next_due( 'weekly', $kdna_ei_tr_at( '2026-10-12 07:30' ) )->format( 'Y-m-d H:i' ) );
kdna_ei_tr( 'Monthly: 9 Oct gives 1 Nov at 7am', '2026-11-01 07:00' === KDNA_EcommerceInsights_Digest::next_due( 'monthly', $kdna_ei_tr_at( '2026-10-09 10:00' ) )->format( 'Y-m-d H:i' ) );
kdna_ei_tr( 'Monthly: 1 Nov at 6am gives the same morning', '2026-11-01 07:00' === KDNA_EcommerceInsights_Digest::next_due( 'monthly', $kdna_ei_tr_at( '2026-11-01 06:00' ) )->format( 'Y-m-d H:i' ) );
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'week_start' => 0 ) ) );
kdna_ei_tr( 'Weeks starting Sunday: Friday gives Sunday', '2026-10-11 07:00' === KDNA_EcommerceInsights_Digest::next_due( 'weekly', $kdna_ei_tr_at( '2026-10-09 10:00' ) )->format( 'Y-m-d H:i' ) );
KDNA_EcommerceInsights_Settings::update( array( 'general' => array( 'week_start' => 1 ) ) );

$kdna_ei_tr_p = KDNA_EcommerceInsights_Digest::period( 'weekly', '2026-10-12' );
kdna_ei_tr( 'Weekly digest on 12 Oct covers 5 to 11 Oct', '2026-10-05' === $kdna_ei_tr_p['start'] && '2026-10-11' === $kdna_ei_tr_p['end'] );
$kdna_ei_tr_p = KDNA_EcommerceInsights_Digest::period( 'monthly', '2026-11-01' );
kdna_ei_tr( 'Monthly digest on 1 Nov covers October', '2026-10-01' === $kdna_ei_tr_p['start'] && '2026-10-31' === $kdna_ei_tr_p['end'] );
$kdna_ei_tr_prev = KDNA_EcommerceInsights_Digest::previous( 'monthly', $kdna_ei_tr_p );
kdna_ei_tr( 'October is compared with September', '2026-09-01' === $kdna_ei_tr_prev['start'] && '2026-09-30' === $kdna_ei_tr_prev['end'] );
$kdna_ei_tr_p = KDNA_EcommerceInsights_Digest::period( 'monthly', '2026-03-01' );
kdna_ei_tr( 'Monthly digest on 1 March covers all of February', '2026-02-01' === $kdna_ei_tr_p['start'] && '2026-02-28' === $kdna_ei_tr_p['end'] );
kdna_ei_tr( 'Range in words', '28 September to 4 October 2026' === KDNA_EcommerceInsights_Digest::range_text( kdna_ei_tr_range( '2026-09-28', '2026-10-04' ) ), KDNA_EcommerceInsights_Digest::range_text( kdna_ei_tr_range( '2026-09-28', '2026-10-04' ) ) );

KDNA_EcommerceInsights_Settings::update( array( 'branding' => array( 'store_name' => 'Test "Store" & Co' ) ) );
$kdna_ei_tr_all  = array_keys( KDNA_EcommerceInsights_Digest::sections() );
$kdna_ei_tr_data = KDNA_EcommerceInsights_Digest::data( 'weekly', $kdna_ei_tr_all, KDNA_EcommerceInsights_Digest::last_due( 'weekly' ) );
$kdna_ei_tr_html = KDNA_EcommerceInsights_Digest::render( $kdna_ei_tr_data );
kdna_ei_tr( 'Digest has six key figures', 6 === count( $kdna_ei_tr_data['kpis'] ?? array() ) );
kdna_ei_tr( 'Digest headline is net profit', 'net_profit' === $kdna_ei_tr_data['headline']['key'] );
kdna_ei_tr( 'Digest store name is escaped', false === strpos( $kdna_ei_tr_html, '"Store"' ) && false !== strpos( $kdna_ei_tr_html, 'Test &quot;Store&quot; &amp; Co' ) );
kdna_ei_tr( 'Digest email is table based with an Outlook button', false !== strpos( $kdna_ei_tr_html, 'role="presentation"' ) && false !== strpos( $kdna_ei_tr_html, 'v:roundrect' ) );
kdna_ei_tr( 'Digest uses the brand accent colour', false !== stripos( $kdna_ei_tr_html, KDNA_EcommerceInsights_Digest::brand()['accent'] ) );
kdna_ei_tr( 'Digest has no em dash and no script', false === strpos( $kdna_ei_tr_html, "\u{2014}" ) && false === stripos( $kdna_ei_tr_html, '<script' ) );
kdna_ei_tr( 'Digest links back to Insights', false !== strpos( $kdna_ei_tr_html, 'page=kdna-ei' ) );
$kdna_ei_tr_small = KDNA_EcommerceInsights_Digest::data( 'weekly', array( 'alerts' ), KDNA_EcommerceInsights_Digest::last_due( 'weekly' ) );
kdna_ei_tr( 'Unticked sections are left out', ! isset( $kdna_ei_tr_small['kpis'] ) && ! isset( $kdna_ei_tr_small['top_products'] ) && isset( $kdna_ei_tr_small['alerts'] ) );
kdna_ei_tr( 'Stock sentence uses singular and plural correctly', '1 product is low on stock and 3 are out of stock.' === KDNA_EcommerceInsights_Digest::stock_sentence( 1, 3 ), KDNA_EcommerceInsights_Digest::stock_sentence( 1, 3 ) );

// Sending, without really sending.
$GLOBALS['kdna_ei_tr_mail'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['kdna_ei_tr_mail'][] = $atts;
		return true;
	},
	1,
	2
);

$kdna_ei_tr_req = new WP_REST_Request( 'POST', '/kdna-ei/v1/digest' );
$kdna_ei_tr_req->set_body_params( array( 'frequency' => 'weekly', 'recipients' => 'owner@example.com, nope', 'sections' => array( 'kpis' ) ) );
$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
kdna_ei_tr( 'A wrong address is refused with a plain message', 400 === $kdna_ei_tr_res->get_status() && false !== strpos( $kdna_ei_tr_res->get_data()['data']['fields']['recipients'] ?? '', 'nope' ), $kdna_ei_tr_res->get_data() );
$kdna_ei_tr_req->set_body_params( array( 'frequency' => 'weekly', 'recipients' => 'owner@example.com', 'sections' => array() ) );
$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
kdna_ei_tr( 'At least one section is needed', 400 === $kdna_ei_tr_res->get_status() && isset( $kdna_ei_tr_res->get_data()['data']['fields']['sections'] ) );
$kdna_ei_tr_req->set_body_params( array( 'frequency' => 'weekly', 'recipients' => 'owner@example.com; partner@example.com', 'sections' => array( 'kpis', 'top_products', 'alerts' ) ) );
$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
kdna_ei_tr( 'Saving weekly works (semicolons accepted)', 200 === $kdna_ei_tr_res->get_status() && 'owner@example.com, partner@example.com' === $kdna_ei_tr_res->get_data()['recipients'], $kdna_ei_tr_res->get_data() );

$kdna_ei_tr_next = as_next_scheduled_action( KDNA_EcommerceInsights_Digest::HOOK, null, KDNA_EcommerceInsights_Order_Processor::GROUP );
kdna_ei_tr( 'Saving schedules the next weekly digest for Monday 7am', is_int( $kdna_ei_tr_next ) && KDNA_EcommerceInsights_Digest::next_due( 'weekly' )->getTimestamp() === $kdna_ei_tr_next, $kdna_ei_tr_next );
KDNA_EcommerceInsights_Digest::schedule();
kdna_ei_tr( 'Scheduling again does not add a second job', 1 === count( as_get_scheduled_actions( array( 'hook' => KDNA_EcommerceInsights_Digest::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ) );

$kdna_ei_tr_req = new WP_REST_Request( 'GET', '/kdna-ei/v1/digest/preview' );
$kdna_ei_tr_req->set_query_params( array( 'frequency' => 'monthly', 'sections' => 'kpis,alerts' ) );
$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
kdna_ei_tr( 'Preview returns the email HTML and subject', 200 === $kdna_ei_tr_res->get_status() && false !== strpos( $kdna_ei_tr_res->get_data()['html'] ?? '', 'Your month in review' ) && false !== strpos( $kdna_ei_tr_res->get_data()['subject'] ?? '', 'monthly' ) );

delete_option( KDNA_EcommerceInsights_Digest::LAST_OPTION );
$kdna_ei_tr_req = new WP_REST_Request( 'POST', '/kdna-ei/v1/digest/test' );
$kdna_ei_tr_req->set_body_params( array( 'frequency' => 'weekly', 'recipients' => 'tester@example.com', 'sections' => array( 'kpis' ) ) );
$kdna_ei_tr_res = kdna_ei_tr_rest( $kdna_ei_tr_req );
$kdna_ei_tr_m1  = end( $GLOBALS['kdna_ei_tr_mail'] );
kdna_ei_tr( 'Send test goes to the address on screen, marked Test', 200 === $kdna_ei_tr_res->get_status() && array( 'tester@example.com' ) === (array) $kdna_ei_tr_m1['to'] && 0 === strpos( $kdna_ei_tr_m1['subject'], 'Test: ' ), $kdna_ei_tr_m1['subject'] ?? '' );
kdna_ei_tr( 'Send test is sent as HTML', false !== strpos( implode( "\n", (array) $kdna_ei_tr_m1['headers'] ), 'text/html' ) );
kdna_ei_tr( 'Send test is not remembered as the real digest', false === get_option( KDNA_EcommerceInsights_Digest::LAST_OPTION, false ) );

$kdna_ei_tr_before = count( $GLOBALS['kdna_ei_tr_mail'] );
KDNA_EcommerceInsights_Digest::run();
kdna_ei_tr( 'The scheduled run sends one email to both recipients', $kdna_ei_tr_before + 1 === count( $GLOBALS['kdna_ei_tr_mail'] ) && array( 'owner@example.com', 'partner@example.com' ) === array_values( (array) end( $GLOBALS['kdna_ei_tr_mail'] )['to'] ) );
KDNA_EcommerceInsights_Digest::run();
kdna_ei_tr( 'A retried run does not send the same digest twice', $kdna_ei_tr_before + 1 === count( $GLOBALS['kdna_ei_tr_mail'] ) );
kdna_ei_tr( 'The run schedules the next digest', is_int( as_next_scheduled_action( KDNA_EcommerceInsights_Digest::HOOK, null, KDNA_EcommerceInsights_Order_Processor::GROUP ) ) );
kdna_ei_tr( 'The send is logged', 'digest' === ( KDNA_EcommerceInsights_Log::recent( 1 )[0]['type'] ?? '' ) );

$kdna_ei_tr_req = new WP_REST_Request( 'POST', '/kdna-ei/v1/digest' );
$kdna_ei_tr_req->set_body_params( array( 'frequency' => 'off', 'recipients' => '', 'sections' => array( 'kpis' ) ) );
kdna_ei_tr_rest( $kdna_ei_tr_req );
kdna_ei_tr( 'Switching off removes the scheduled digest', false === as_next_scheduled_action( KDNA_EcommerceInsights_Digest::HOOK, null, KDNA_EcommerceInsights_Order_Processor::GROUP ) );

/*
 * -------------------------------------------------------------------------
 * Printable report
 * -------------------------------------------------------------------------
 */
echo "\nPrintable report\n";

$kdna_ei_tr_pr = KDNA_EcommerceInsights_Print_Report::data( $kdna_ei_tr_fy, KDNA_EcommerceInsights_Dates::comparison( $kdna_ei_tr_fy, 'previous_year' ) );
kdna_ei_tr( 'Report has eight key figures', 8 === count( $kdna_ei_tr_pr['kpis'] ) );
kdna_ei_tr( 'Report chart is an SVG with lines', 0 === strpos( $kdna_ei_tr_pr['chart'], '<svg' ) && false !== strpos( $kdna_ei_tr_pr['chart'], 'polyline' ) );
kdna_ei_tr( 'Six months fit as six statement columns', 'month' === $kdna_ei_tr_pr['columns']['unit'] && 6 === count( $kdna_ei_tr_pr['columns']['columns'] ) );
$kdna_ei_tr_pr = KDNA_EcommerceInsights_Print_Report::data( kdna_ei_tr_range( '2023-01-01', '2023-12-31' ), null );
kdna_ei_tr( 'A year is grouped into four quarters to fit A4', 'quarter' === $kdna_ei_tr_pr['columns']['unit'] && 4 === count( $kdna_ei_tr_pr['columns']['columns'] ) );
$kdna_ei_tr_pr = KDNA_EcommerceInsights_Print_Report::data( kdna_ei_tr_range( '2020-01-01', '2023-12-31' ), null );
kdna_ei_tr( 'Four years are grouped by year', 'year' === $kdna_ei_tr_pr['columns']['unit'] && 4 === count( $kdna_ei_tr_pr['columns']['columns'] ) );
$kdna_ei_tr_net = 0.0;
foreach ( $kdna_ei_tr_pr['columns']['columns'] as $kdna_ei_tr_col ) {
	$kdna_ei_tr_net += (float) $kdna_ei_tr_col['lines']['net_profit'];
}
kdna_ei_tr_amount( 'Grouped columns add up to the whole period net profit', (float) $kdna_ei_tr_pr['statement'][ count( $kdna_ei_tr_pr['statement'] ) - 1 ]['amount'], $kdna_ei_tr_net );
kdna_ei_tr( 'Printable report address carries a nonce', false !== strpos( KDNA_EcommerceInsights_Print_Report::base_url(), '_wpnonce=' ) );

/*
 * -------------------------------------------------------------------------
 * Tidy up
 * -------------------------------------------------------------------------
 */
foreach ( $GLOBALS['kdna_ei_tr_clean']['orders'] as $kdna_ei_tr_id ) {
	$kdna_ei_tr_o = wc_get_order( $kdna_ei_tr_id );
	if ( $kdna_ei_tr_o ) {
		$kdna_ei_tr_o->delete( true );
	}
	KDNA_EcommerceInsights_Order_Processor::delete( $kdna_ei_tr_id );
}
foreach ( $GLOBALS['kdna_ei_tr_clean']['products'] as $kdna_ei_tr_id ) {
	$kdna_ei_tr_p = wc_get_product( $kdna_ei_tr_id );
	if ( $kdna_ei_tr_p ) {
		$kdna_ei_tr_p->delete( true );
	}
}
if ( $kdna_ei_tr_overhead ) {
	KDNA_EcommerceInsights_Overheads::delete( (int) $kdna_ei_tr_overhead['id'] );
}
foreach ( $kdna_ei_tr_ads as $kdna_ei_tr_ad ) {
	if ( is_string( $kdna_ei_tr_ad ) && '' !== $kdna_ei_tr_ad ) {
		KDNA_EcommerceInsights_Ad_Spend::delete_group( $kdna_ei_tr_ad );
	}
}
update_option( KDNA_EcommerceInsights_Settings::OPTION, $kdna_ei_tr_settings );
if ( null === $kdna_ei_tr_last ) {
	delete_option( KDNA_EcommerceInsights_Digest::LAST_OPTION );
} else {
	update_option( KDNA_EcommerceInsights_Digest::LAST_OPTION, $kdna_ei_tr_last, false );
}
KDNA_EcommerceInsights_Digest::reschedule();
KDNA_EcommerceInsights_Summary::rebuild_all();
KDNA_EcommerceInsights_Cache::flush();

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_tr_count'], $GLOBALS['kdna_ei_tr_fail'] );
