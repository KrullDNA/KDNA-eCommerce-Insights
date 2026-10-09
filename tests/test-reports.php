<?php
/**
 * Integration tests for Stage 5: date ranges, the metric registry and the
 * report REST API (access, caching, reliability details, debug timings,
 * consistency between routes, ad spend and speed).
 *
 * Test site only:
 *
 *     wp eval-file tests/test-reports.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-reports.php\n" );

$GLOBALS['kdna_ei_r_fail']  = 0;
$GLOBALS['kdna_ei_r_count'] = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Detail shown on failure.
 */
function kdna_ei_r( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_r_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_r_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : '  >> ' . ( is_string( $note ) ? $note : wp_json_encode( $note ) ) ) . "\n";
}

/**
 * Calls a plugin REST route as the current user, with or without a nonce.
 *
 * @param string $method HTTP method.
 * @param string $path   Path after kdna-ei/v1.
 * @param array  $params Parameters.
 * @param bool   $nonce  Send a valid REST nonce.
 * @return WP_REST_Response
 */
function kdna_ei_r_call( string $method, string $path, array $params = array(), bool $nonce = true ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/kdna-ei/v1' . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	if ( $nonce ) {
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	}
	return rest_do_request( $request );
}

/**
 * Finds one metric in a summary response.
 *
 * @param array  $data Response data.
 * @param string $key  Metric key.
 * @return array
 */
function kdna_ei_r_metric( array $data, string $key ): array {
	foreach ( $data['metrics'] as $metric ) {
		if ( $metric['key'] === $key ) {
			return $metric;
		}
	}
	return array();
}

wp_set_current_user( 1 );
do_action( 'rest_api_init' );

/*
 * -------------------------------------------------------------------------
 * Date ranges
 * -------------------------------------------------------------------------
 */
echo "Date ranges (today = Friday 9 October 2026)\n";
$today    = '2026-10-09';
$expected = array(
	'today'        => array( '2026-10-09', '2026-10-09' ),
	'yesterday'    => array( '2026-10-08', '2026-10-08' ),
	'last_7_days'  => array( '2026-10-03', '2026-10-09' ),
	'last_30_days' => array( '2026-09-10', '2026-10-09' ),
	'this_month'   => array( '2026-10-01', '2026-10-09' ),
	'last_month'   => array( '2026-09-01', '2026-09-30' ),
	'this_quarter' => array( '2026-10-01', '2026-10-09' ),
	'this_year'    => array( '2026-01-01', '2026-10-09' ),
	'last_year'    => array( '2025-01-01', '2025-12-31' ),
);
foreach ( $expected as $preset => $dates ) {
	$range = KDNA_EcommerceInsights_Dates::resolve( $preset, null, null, $today );
	kdna_ei_r( sprintf( '%s is %s to %s', $preset, $dates[0], $dates[1] ), array( $range['start'], $range['end'] ) === $dates, $range );
}
$custom = KDNA_EcommerceInsights_Dates::resolve( 'custom', '2026-02-01', '2026-02-28' );
kdna_ei_r( 'Custom range 1 to 28 Feb has 28 days', 28 === $custom['days'] );
kdna_ei_r( 'Custom range with end before start is refused', is_wp_error( KDNA_EcommerceInsights_Dates::resolve( 'custom', '2026-03-01', '2026-02-01' ) ) );
kdna_ei_r( 'Custom range with a made-up date is refused', is_wp_error( KDNA_EcommerceInsights_Dates::resolve( 'custom', '2026-02-30', '2026-03-01' ) ) );

$cmp = static function ( string $preset, string $mode, string $today = '2026-10-09' ): array {
	$range = KDNA_EcommerceInsights_Dates::resolve( $preset, null, null, $today );
	$c     = KDNA_EcommerceInsights_Dates::comparison( $range, $mode );
	return $c ? array( $c['start'], $c['end'] ) : array();
};
kdna_ei_r( 'This month (1 to 9 Oct) compares with 1 to 9 Sept', array( '2026-09-01', '2026-09-09' ) === $cmp( 'this_month', 'previous_period' ) );
kdna_ei_r( 'Last month (Sept) compares with all of August', array( '2026-08-01', '2026-08-31' ) === $cmp( 'last_month', 'previous_period' ) );
kdna_ei_r( 'Last 7 days compares with the 7 days before', array( '2025-09-26', '2025-10-02' ) !== $cmp( 'last_7_days', 'previous_period' ) && array( '2026-09-26', '2026-10-02' ) === $cmp( 'last_7_days', 'previous_period' ) );
kdna_ei_r( 'This quarter compares with the same days of last quarter', array( '2026-07-01', '2026-07-09' ) === $cmp( 'this_quarter', 'previous_period' ) );
kdna_ei_r( 'Same period last year', array( '2025-10-01', '2025-10-09' ) === $cmp( 'this_month', 'previous_year' ) );
kdna_ei_r( 'No comparison', array() === $cmp( 'this_month', 'none' ) );
kdna_ei_r( '31 March this month compares with 1 to 28 Feb, not into March', array( '2026-02-01', '2026-02-28' ) === $cmp( 'this_month', 'previous_period', '2026-03-31' ) );
kdna_ei_r( '29 Feb 2028 last year becomes 28 Feb 2027', array( '2027-02-01', '2027-02-28' ) === $cmp( 'this_month', 'previous_year', '2028-02-29' ) );

$weeks = KDNA_EcommerceInsights_Dates::buckets( array( 'preset' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-20', 'days' => 20 ), 'week' );
kdna_ei_r( 'Weekly buckets start on the week start day (Monday) and are trimmed to the range', '2026-10-01' === $weeks[0]['start'] && '2026-10-04' === $weeks[0]['end'] && '2026-10-05' === $weeks[1]['start'] && '2026-10-20' === end( $weeks )['end'], $weeks );

/*
 * -------------------------------------------------------------------------
 * Metric registry
 * -------------------------------------------------------------------------
 */
echo "\nMetric registry\n";
$all     = KDNA_EcommerceInsights_Metrics::all();
$missing = array();
foreach ( $all as $key => $metric ) {
	foreach ( array( 'label', 'group', 'format', 'help', 'compute' ) as $field ) {
		if ( empty( $metric[ $field ] ) ) {
			$missing[] = "$key.$field";
		}
	}
	if ( ! array_key_exists( 'higher_is_better', $metric ) || ! in_array( $metric['format'], array( 'currency', 'number', 'percent', 'ratio', 'days' ), true ) ) {
		$missing[] = "$key.format_or_flag";
	}
}
kdna_ei_r( count( $all ) . ' metrics, each with key, label, format, higher-is-better flag, help text and formula', ! $missing, $missing );
$groups = array_unique( wp_list_pluck( $all, 'group' ) );
kdna_ei_r( 'Covers every area in section 6.1', ! array_diff( array( 'sales', 'profit', 'marketing', 'customers', 'inventory', 'tax' ), $groups ), $groups );

$totals = array(
	'net_revenue'         => 1000,
	'cogs'                => 400,
	'payment_fees'        => 20,
	'shipping_costs'      => 60,
	'extra_costs'         => 20,
	'ad_spend'            => 150,
	'overheads'           => 100,
	'orders'              => 20,
	'new_customers'       => 10,
	'shipping_charged'    => 45,
	'ad_conversion_value' => 600,
);
$value = static fn( $key ) => KDNA_EcommerceInsights_Metrics::value( $key, $totals );
kdna_ei_r( 'Gross profit 1000 - 400 = 600', 600.0 === $value( 'gross_profit' ) );
kdna_ei_r( 'Contribution profit 600 - 20 - 60 - 20 = 500', 500.0 === $value( 'contribution_profit' ) );
kdna_ei_r( 'Net profit 500 - 150 - 100 = 250', 250.0 === $value( 'net_profit' ) );
kdna_ei_r( 'Net margin 25%', 25.0 === $value( 'net_margin' ) );
kdna_ei_r( 'Average order value 1000 / 20 = 50', 50.0 === $value( 'average_order_value' ) );
kdna_ei_r( 'Shipping recovery 45 / 60 = 75%', 75.0 === $value( 'shipping_recovery' ) );
kdna_ei_r( 'ROAS 600 / 150 = 4', 4.0 === $value( 'roas' ) );
kdna_ei_r( 'MER 1000 / 150', abs( 1000 / 150 - $value( 'mer' ) ) < 0.0001 );
kdna_ei_r( 'Cost per new customer 150 / 10 = 15', 15.0 === $value( 'cpa' ) );
kdna_ei_r( 'Dividing by zero gives no value, not an error', null === KDNA_EcommerceInsights_Metrics::value( 'net_margin', array() ) );

$up_cost = KDNA_EcommerceInsights_Metrics::evaluate( 'payment_fees', array( 'payment_fees' => 120 ), array( 'payment_fees' => 100 ) );
kdna_ei_r( 'Fees up 20% is shown as bad news', 20.0 === $up_cost['change'] && 'up' === $up_cost['direction'] && 'bad' === $up_cost['sentiment'], $up_cost );
$up_rev = KDNA_EcommerceInsights_Metrics::evaluate( 'net_revenue', array( 'net_revenue' => 120 ), array( 'net_revenue' => 100 ) );
kdna_ei_r( 'Revenue up 20% is shown as good news', 'good' === $up_rev['sentiment'] );
$margin = KDNA_EcommerceInsights_Metrics::evaluate( 'net_margin', array( 'net_revenue' => 100, 'cogs' => 55 ), array( 'net_revenue' => 100, 'cogs' => 58 ) );
kdna_ei_r( 'Percentages change by points: 42% to 45% is +3 points', 'points' === $margin['change_type'] && 3.0 === $margin['change'], $margin );
$est = KDNA_EcommerceInsights_Metrics::evaluate( 'net_profit', $totals, null, array( 'fee_orders' => 3 ) );
kdna_ei_r( 'Net profit is flagged as estimated when fees are estimated', true === $est['estimated'] );
kdna_ei_r( 'Orders are never flagged as estimated', false === KDNA_EcommerceInsights_Metrics::evaluate( 'orders', $totals, null, array( 'fee_orders' => 3 ) )['estimated'] );

/*
 * -------------------------------------------------------------------------
 * Access
 * -------------------------------------------------------------------------
 */
echo "\nAccess to every route\n";
$routes = array( '/summary', '/timeseries', '/profit', '/products', '/customers', '/inventory', '/marketing', '/tax', '/export?table=pnl', '/adspend', '/metrics', '/status', '/costs', '/overheads' );
$editor = wp_insert_user(
	array(
		'user_login' => 'kdna_ei_editor_' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'editor',
	)
);
$manager = wp_insert_user(
	array(
		'user_login' => 'kdna_ei_manager_' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'shop_manager',
	)
);
$codes = array();
foreach ( $routes as $route ) {
	list( $path, $query ) = array_pad( explode( '?', $route ), 2, '' );
	parse_str( $query, $params );

	wp_set_current_user( 0 );
	$codes[ $route ]['visitor'] = kdna_ei_r_call( 'GET', $path, $params )->get_status();
	wp_set_current_user( $editor );
	$codes[ $route ]['editor'] = kdna_ei_r_call( 'GET', $path, $params )->get_status();
	wp_set_current_user( $manager );
	$codes[ $route ]['shop_manager'] = kdna_ei_r_call( 'GET', $path, $params )->get_status();
	wp_set_current_user( 1 );
	$codes[ $route ]['admin_no_nonce'] = kdna_ei_r_call( 'GET', $path, $params, false )->get_status();
	$codes[ $route ]['admin']          = kdna_ei_r_call( 'GET', $path, $params )->get_status();
}
$bad = array_filter( $codes, static fn( $c ) => 401 !== $c['visitor'] || 403 !== $c['editor'] || 403 !== $c['shop_manager'] || 401 !== $c['admin_no_nonce'] || 200 !== $c['admin'] );
kdna_ei_r( 'All ' . count( $routes ) . ' routes: visitors 401, Editors and Shop Managers 403, Administrators without a nonce 401, with a nonce 200', ! $bad, $bad );
wp_delete_user( $editor );
wp_delete_user( $manager );

/*
 * -------------------------------------------------------------------------
 * Responses, reliability details, caching and debug
 * -------------------------------------------------------------------------
 */
echo "\nResponses\n";
KDNA_EcommerceInsights_Cache::flush();
$first = kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'last_year' ) )->get_data();
kdna_ei_r( 'Summary returns the five KPI metrics by default', 5 === count( $first['data']['metrics'] ), wp_list_pluck( $first['data']['metrics'], 'key' ) );
foreach ( array( '/summary', '/timeseries', '/profit', '/products', '/customers', '/inventory', '/marketing', '/tax' ) as $route ) {
	$meta = kdna_ei_r_call( 'GET', $route, array( 'preset' => 'last_year' ) )->get_data()['meta'];
	$ok   = isset( $meta['estimates']['fee_orders'], $meta['estimates']['shipping_orders'], $meta['estimates']['missing_cost_orders'], $meta['estimates']['missing_costs'], $meta['estimates']['has_estimates'] );
	kdna_ei_r( $route . ' includes estimated fee and shipping counts and missing costs', $ok, $meta );
}
kdna_ei_r( 'First request is worked out fresh', false === $first['meta']['cached'] );
$second = kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'last_year' ) )->get_data();
kdna_ei_r( 'Second request comes from the 10 minute cache', true === $second['meta']['cached'] );
$other = kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'last_year', 'metrics' => 'orders,gross_margin' ) )->get_data();
kdna_ei_r( 'A different metric set is cached separately', false === $other['meta']['cached'] && 2 === count( $other['data']['metrics'] ) );
do_action( 'kdna_ei_order_processed', 0, array() );
$third = kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'last_year' ) )->get_data();
kdna_ei_r( 'Cache is cleared when an order is processed', false === $third['meta']['cached'] );

$debug = kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'last_year', 'kdna_ei_debug' => true ) )->get_data();
kdna_ei_r( 'Debug mode returns query timings', ! empty( $debug['meta']['debug']['queries'] ) && isset( $debug['meta']['debug']['total_ms'] ), $debug['meta']['debug'] ?? null );
kdna_ei_r( 'Normal requests do not include debug details', ! isset( $third['meta']['debug'] ) );

/*
 * -------------------------------------------------------------------------
 * Figures agree across routes
 * -------------------------------------------------------------------------
 */
echo "\nFigures agree across routes (last year)\n";
$summary   = kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'last_year', 'metrics' => 'net_revenue,net_profit,orders,cogs' ) )->get_data()['data'];
$series    = kdna_ei_r_call( 'GET', '/timeseries', array( 'preset' => 'last_year', 'metrics' => 'net_revenue,orders', 'granularity' => 'month' ) )->get_data()['data'];
$profit    = kdna_ei_r_call( 'GET', '/profit', array( 'preset' => 'last_year' ) )->get_data()['data'];
$net_rev   = kdna_ei_r_metric( $summary, 'net_revenue' )['value'];
$orders    = kdna_ei_r_metric( $summary, 'orders' )['value'];
$net_prof  = kdna_ei_r_metric( $summary, 'net_profit' )['value'];
$waterfall = array_column( $profit['waterfall'], 'amount', 'key' );
kdna_ei_r( 'Last year has orders to test with (' . $orders . ')', $orders > 0 );
kdna_ei_r( 'Monthly chart adds up to the summary net revenue', abs( array_sum( $series['series']['net_revenue']['current'] ) - $net_rev ) < 0.01, array( array_sum( $series['series']['net_revenue']['current'] ), $net_rev ) );
kdna_ei_r( 'Monthly chart adds up to the summary orders', abs( array_sum( $series['series']['orders']['current'] ) - $orders ) < 0.01 );
kdna_ei_r( '12 monthly buckets for last year', 12 === count( $series['buckets'] ) );
kdna_ei_r( 'P&L waterfall net profit matches the summary', abs( $waterfall['net_profit'] - $net_prof ) < 0.01, array( $waterfall['net_profit'], $net_prof ) );
kdna_ei_r( 'P&L monthly statement adds up to the waterfall', abs( array_sum( array_map( static fn( $m ) => $m['lines']['net_profit'], $profit['months'] ) ) - $waterfall['net_profit'] ) < 0.05 );
$steps = $waterfall['gross_sales'] + $waterfall['discounts'] + $waterfall['refunds'] + $waterfall['shipping_charged'];
kdna_ei_r( 'Waterfall steps add up to net revenue', abs( $steps - $waterfall['net_revenue'] ) < 0.05 || 'refund_date' === KDNA_EcommerceInsights_Settings::get( 'general.refund_dating' ), array( $steps, $waterfall['net_revenue'] ) );

$products = kdna_ei_r_call( 'GET', '/products', array( 'preset' => 'last_year', 'per_page' => 10, 'orderby' => 'revenue' ) )->get_data()['data'];
kdna_ei_r( 'Products are paginated (10 per page)', count( $products['rows'] ) <= 10 && $products['total'] > 0 && $products['pages'] >= 1, array( count( $products['rows'] ), $products['total'] ) );
kdna_ei_r( 'Products are sorted by revenue, highest first', $products['rows'][0]['revenue'] >= end( $products['rows'] )['revenue'] );
kdna_ei_r( 'Best and worst performers and a category breakdown are included', count( $products['best'] ) > 0 && count( $products['categories'] ) > 0 );
$customers = kdna_ei_r_call( 'GET', '/customers', array( 'preset' => 'last_year' ) )->get_data()['data'];
kdna_ei_r( 'Customers include lifetime metrics, top customers, cohorts and locations', ! empty( $customers['metrics'] ) && ! empty( $customers['top_customers'] ) && isset( $customers['cohorts'] ) && ! empty( $customers['locations'] ) );
$inventory = kdna_ei_r_call( 'GET', '/inventory' )->get_data()['data'];
kdna_ei_r( 'Inventory includes stock status counts and stock values', isset( $inventory['status']['in_stock'] ) && ! empty( $inventory['metrics'] ) );
$tax = kdna_ei_r_call( 'GET', '/tax', array( 'preset' => 'last_year' ) )->get_data()['data'];
kdna_ei_r( 'Tax summary has BAS lines and a guide-only note', isset( $tax['totals']['bas_g1'], $tax['note'] ) );

/*
 * -------------------------------------------------------------------------
 * Ad spend
 * -------------------------------------------------------------------------
 */
echo "\nAd spend\n";
$before = kdna_ei_r_metric( kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'custom', 'start' => '2025-03-01', 'end' => '2025-03-31', 'metrics' => 'net_profit,ad_spend' ) )->get_data()['data'], 'net_profit' )['value'];
$bad    = kdna_ei_r_call( 'POST', '/adspend', array( 'start' => '2025-03-01', 'end' => '2025-02-01', 'channel' => 'meta', 'amount' => 'abc' ) );
kdna_ei_r( 'Bad ad spend is refused with a message per field', 400 === $bad->get_status() && isset( $bad->get_data()['data']['fields']['end'], $bad->get_data()['data']['fields']['amount'] ), $bad->get_data() );
$made  = kdna_ei_r_call( 'POST', '/adspend', array( 'start' => '2025-03-01', 'end' => '2025-03-31', 'channel' => 'meta', 'campaign_name' => 'Autumn edit', 'amount' => 310 ) );
$group = $made->get_data()['data']['entry_group'] ?? '';
$after = kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'custom', 'start' => '2025-03-01', 'end' => '2025-03-31', 'metrics' => 'net_profit,ad_spend' ) )->get_data()['data'];
kdna_ei_r( '$310 for March is spread at $10 a day', abs( 310 - kdna_ei_r_metric( $after, 'ad_spend' )['value'] ) < 0.01 );
kdna_ei_r( 'Net profit falls by the ad spend', abs( ( $before - 310 ) - kdna_ei_r_metric( $after, 'net_profit' )['value'] ) < 0.01, array( $before, kdna_ei_r_metric( $after, 'net_profit' )['value'] ) );
$week = kdna_ei_r_metric( kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'custom', 'start' => '2025-03-01', 'end' => '2025-03-07', 'metrics' => 'ad_spend' ) )->get_data()['data'], 'ad_spend' )['value'];
kdna_ei_r( 'A 7 day range carries $70 of it', abs( 70 - $week ) < 0.01, $week );
$listed = kdna_ei_r_call( 'GET', '/adspend', array( 'preset' => 'custom', 'start' => '2025-03-10', 'end' => '2025-03-12' ) )->get_data()['data'];
kdna_ei_r( 'Entry is listed with its full dates and total', 1 === count( $listed ) && 310.0 === $listed[0]['amount'] && '2025-03-31' === $listed[0]['end'], $listed );
kdna_ei_r( 'Entry can be deleted', 200 === kdna_ei_r_call( 'DELETE', '/adspend/' . $group )->get_status() );

/*
 * -------------------------------------------------------------------------
 * Exports
 * -------------------------------------------------------------------------
 */
echo "\nCSV exports\n";
foreach ( KDNA_EcommerceInsights_Rest_Export::TABLES as $table ) {
	$response = kdna_ei_r_call( 'GET', '/export', array( 'table' => $table, 'preset' => 'last_year' ) );
	$csv      = (string) ( $response->get_data()['csv'] ?? '' );
	kdna_ei_r( "Export $table", 200 === $response->get_status() && substr_count( $csv, "\n" ) >= 1, $response->get_status() );
}

/*
 * -------------------------------------------------------------------------
 * Speed
 * -------------------------------------------------------------------------
 */
echo "\nSpeed (fresh, without the cache)\n";
global $wpdb;
$order_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) );
foreach ( array( '/summary', '/timeseries' ) as $route ) {
	$start = microtime( true );
	kdna_ei_r_call( 'GET', $route, array( 'preset' => 'custom', 'start' => '2025-10-09', 'end' => '2026-10-08', 'fresh' => true ) );
	$ms = ( microtime( true ) - $start ) * 1000;
	kdna_ei_r( sprintf( 'One year of %s over %s orders in %d ms (target under 500)', $route, number_format( $order_count ), $ms ), $ms < 500 );
}
$start = microtime( true );
kdna_ei_r_call( 'GET', '/summary', array( 'preset' => 'custom', 'start' => '2025-10-09', 'end' => '2026-10-08' ) );
kdna_ei_r( sprintf( 'Same request from the cache in %d ms', ( microtime( true ) - $start ) * 1000 ), true );

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_r_count'], $GLOBALS['kdna_ei_r_fail'] );
