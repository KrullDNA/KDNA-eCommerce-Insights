<?php
/**
 * Integration tests for Stage 9: manual ad spend spread by day, editing,
 * GST, CSV import of real Meta and Google export layouts with presets,
 * re-imports that never double count, saved presets, the Marketing report
 * and net profit including ad spend everywhere.
 *
 * Uses dates in August to October 2021, which no other test uses, and
 * removes everything at the end. Sample exports are in tests/fixtures.
 *
 * Test site only:
 *
 *     wp eval-file tests/test-ad-spend.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-ad-spend.php\n" );

$GLOBALS['kdna_ei_a_fail']  = 0;
$GLOBALS['kdna_ei_a_count'] = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Detail shown on failure.
 */
function kdna_ei_a( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_a_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_a_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : '  >> ' . ( is_string( $note ) ? $note : wp_json_encode( $note ) ) ) . "\n";
}

/**
 * Calls a plugin REST route as the current user with a valid nonce.
 *
 * @param string $method HTTP method.
 * @param string $path   Path after kdna-ei/v1.
 * @param array  $params Parameters.
 * @return WP_REST_Response
 */
function kdna_ei_a_call( string $method, string $path, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/kdna-ei/v1' . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	return rest_do_request( $request );
}

/**
 * Total spend saved between two dates, optionally for one channel or source.
 *
 * @param string $start   Y-m-d.
 * @param string $end     Y-m-d.
 * @param string $channel Channel key or empty.
 * @param string $source  Source or empty.
 * @return float
 */
function kdna_ei_a_sum( string $start, string $end, string $channel = '', string $source = '' ): float {
	global $wpdb;
	$sql = 'SELECT COALESCE( SUM( spend ), 0 ) FROM ' . KDNA_EcommerceInsights_Install::table( 'ad_spend' ) . ' WHERE spend_date BETWEEN %s AND %s';
	$arg = array( $start, $end );
	if ( $channel ) {
		$sql  .= ' AND channel = %s';
		$arg[] = $channel;
	}
	if ( $source ) {
		$sql  .= ' AND source = %s';
		$arg[] = $source;
	}
	return round( (float) $wpdb->get_var( $wpdb->prepare( $sql, $arg ) ), 4 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Removes every ad spend row this test could have created.
 */
function kdna_ei_a_cleanup(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DELETE FROM ' . KDNA_EcommerceInsights_Install::table( 'ad_spend' ) . " WHERE spend_date BETWEEN '2021-08-01' AND '2021-10-31'" );
	KDNA_EcommerceInsights_Cache::flush();
}

wp_set_current_user( 1 );
kdna_ei_a_cleanup();

$kdna_ei_fixtures = __DIR__ . '/fixtures/';
$kdna_ei_saved    = array(
	'tax'       => KDNA_EcommerceInsights_Settings::get( 'tax' ),
	'marketing' => KDNA_EcommerceInsights_Settings::get( 'marketing' ),
);
KDNA_EcommerceInsights_Settings::update( array( 'tax' => array( 'system' => 'none' ) ) );

/*
 * ---------------------------------------------------------------------
 * Manual entries
 * ---------------------------------------------------------------------
 */
echo "\n== Manual spend is spread by day ==\n";

$kdna_ei_r = kdna_ei_a_call( 'POST', '/adspend', array( 'start' => '2021-09-01', 'end' => '2021-09-30', 'channel' => 'meta', 'campaign_name' => 'September retainer', 'amount' => 3000 ) );
$kdna_ei_g = $kdna_ei_r->get_data()['data']['entry_group'] ?? '';
kdna_ei_a( 'Monthly entry saved', 200 === $kdna_ei_r->get_status() && $kdna_ei_g, $kdna_ei_r->get_data() );
kdna_ei_a( 'Each day of September gets $100', 100.0 === kdna_ei_a_sum( '2021-09-01', '2021-09-01' ) && 100.0 === kdna_ei_a_sum( '2021-09-30', '2021-09-30' ), array( kdna_ei_a_sum( '2021-09-01', '2021-09-01' ), kdna_ei_a_sum( '2021-09-30', '2021-09-30' ) ) );
kdna_ei_a( 'Nothing spills into August or October', 0.0 === kdna_ei_a_sum( '2021-08-01', '2021-08-31' ) && 0.0 === kdna_ei_a_sum( '2021-10-01', '2021-10-31' ) );
kdna_ei_a( 'September adds up to exactly $3,000', 3000.0 === kdna_ei_a_sum( '2021-09-01', '2021-09-30' ) );
kdna_ei_a( 'A week of September is $700', 700.0 === kdna_ei_a_sum( '2021-09-08', '2021-09-14' ) );

$kdna_ei_r = kdna_ei_a_call( 'POST', '/adspend', array( 'start' => '2021-08-01', 'end' => '2021-08-07', 'channel' => 'google', 'amount' => 1000 ) );
kdna_ei_a( '$1,000 over 7 days adds up to exactly $1,000 (rounding goes on the last day)', 1000.0 === kdna_ei_a_sum( '2021-08-01', '2021-08-07' ), kdna_ei_a_sum( '2021-08-01', '2021-08-07' ) );
$kdna_ei_g2 = $kdna_ei_r->get_data()['data']['entry_group'];

$kdna_ei_r = kdna_ei_a_call( 'POST', '/adspend', array( 'start' => '2021-09-10', 'end' => '2021-09-01', 'channel' => '', 'amount' => '-5' ) );
$kdna_ei_f = $kdna_ei_r->get_data()['data']['fields'] ?? array();
kdna_ei_a( 'Bad entries are refused with a message per field', 400 === $kdna_ei_r->get_status() && isset( $kdna_ei_f['end'], $kdna_ei_f['channel'], $kdna_ei_f['amount'] ), $kdna_ei_f );

echo "\n== Editing and deleting ==\n";

$kdna_ei_r = kdna_ei_a_call( 'PUT', '/adspend/' . $kdna_ei_g2, array( 'start' => '2021-08-01', 'end' => '2021-08-10', 'channel' => 'google', 'campaign_name' => 'Brand', 'amount' => 500 ) );
kdna_ei_a( 'A manual entry can be changed', 200 === $kdna_ei_r->get_status(), $kdna_ei_r->get_data() );
kdna_ei_a( 'Old days are replaced: August now $500 over 10 days', 500.0 === kdna_ei_a_sum( '2021-08-01', '2021-08-31' ) && 50.0 === kdna_ei_a_sum( '2021-08-10', '2021-08-10' ) );
$kdna_ei_entry = KDNA_EcommerceInsights_Ad_Spend::entry( $kdna_ei_g2 );
kdna_ei_a( 'It keeps the same entry ID', $kdna_ei_entry && '2021-08-10' === $kdna_ei_entry['end'], $kdna_ei_entry );

$kdna_ei_r = kdna_ei_a_call( 'DELETE', '/adspend/' . $kdna_ei_g2 );
kdna_ei_a( 'Deleting removes every day', 200 === $kdna_ei_r->get_status() && 0.0 === kdna_ei_a_sum( '2021-08-01', '2021-08-31' ) );

/*
 * ---------------------------------------------------------------------
 * Net profit includes ad spend everywhere
 * ---------------------------------------------------------------------
 */
echo "\n== Net profit includes ad spend ==\n";

$kdna_ei_range = array(
	'start'   => '2021-09-01',
	'end'     => '2021-09-30',
	'compare' => 'none',
	'fresh'   => true,
);
$kdna_ei_metric = static function ( array $metrics, string $key ) {
	foreach ( $metrics as $m ) {
		if ( $m['key'] === $key ) {
			return (float) $m['value'];
		}
	}
	return null;
};
$kdna_ei_with = kdna_ei_a_call( 'GET', '/summary', $kdna_ei_range + array( 'metrics' => 'net_profit,ad_spend,contribution_profit,overheads' ) )->get_data()['data']['metrics'];
kdna_ei_a( 'Summary (Overview) ad spend is $3,000', 3000.0 === round( $kdna_ei_metric( $kdna_ei_with, 'ad_spend' ), 2 ) );
kdna_ei_a( 'Overview net profit = contribution profit less ad spend less overheads', abs( $kdna_ei_metric( $kdna_ei_with, 'net_profit' ) - ( $kdna_ei_metric( $kdna_ei_with, 'contribution_profit' ) - 3000 - $kdna_ei_metric( $kdna_ei_with, 'overheads' ) ) ) < 0.01 );

KDNA_EcommerceInsights_Ad_Spend::delete_group( $kdna_ei_g );
$kdna_ei_without = kdna_ei_a_call( 'GET', '/summary', $kdna_ei_range + array( 'metrics' => 'net_profit' ) )->get_data()['data']['metrics'];
kdna_ei_a( 'Removing the $3,000 raises Overview net profit by exactly $3,000', abs( ( $kdna_ei_metric( $kdna_ei_without, 'net_profit' ) - $kdna_ei_metric( $kdna_ei_with, 'net_profit' ) ) - 3000 ) < 0.01 );

KDNA_EcommerceInsights_Ad_Spend::create( array( 'start' => '2021-09-01', 'end' => '2021-09-30', 'channel' => 'meta', 'amount' => 3000 ) );
KDNA_EcommerceInsights_Cache::flush();
$kdna_ei_profit = kdna_ei_a_call( 'GET', '/profit', $kdna_ei_range )->get_data()['data'];
$kdna_ei_lines  = array_column( $kdna_ei_profit['waterfall'], 'amount', 'key' );
kdna_ei_a( 'P&L shows ad spend of -$3,000', -3000.0 === round( $kdna_ei_lines['ad_spend'], 2 ), $kdna_ei_lines['ad_spend'] );
kdna_ei_a( 'P&L net profit matches the Overview', abs( $kdna_ei_lines['net_profit'] - $kdna_ei_metric( $kdna_ei_with, 'net_profit' ) ) < 0.01 );
$kdna_ei_series = kdna_ei_a_call( 'GET', '/timeseries', $kdna_ei_range + array( 'metrics' => 'net_profit,ad_spend' ) )->get_data()['data']['series'];
kdna_ei_a( 'Daily chart ad spend adds up to $3,000', 3000.0 === round( array_sum( $kdna_ei_series['ad_spend']['current'] ), 2 ) );
kdna_ei_a( 'Daily chart net profit adds up to the total', abs( array_sum( $kdna_ei_series['net_profit']['current'] ) - $kdna_ei_lines['net_profit'] ) < 0.05 );

echo "\n== GST is taken off spend entered including it ==\n";

KDNA_EcommerceInsights_Settings::update( array( 'tax' => array( 'system' => 'au_gst', 'rate' => 10 ) ) );
KDNA_EcommerceInsights_Ad_Spend::create( array( 'start' => '2021-08-01', 'end' => '2021-08-31', 'channel' => 'tiktok', 'amount' => 1100, 'includes_gst' => true ) );
KDNA_EcommerceInsights_Cache::flush();
$kdna_ei_aug = kdna_ei_a_call( 'GET', '/summary', array( 'start' => '2021-08-01', 'end' => '2021-08-31', 'compare' => 'none', 'metrics' => 'ad_spend' ) )->get_data()['data']['metrics'];
kdna_ei_a( '$1,100 including GST counts as $1,000 in net profit', 1000.0 === round( $kdna_ei_metric( $kdna_ei_aug, 'ad_spend' ), 2 ), $kdna_ei_aug );
$kdna_ei_mkt = KDNA_EcommerceInsights_Report::marketing( KDNA_EcommerceInsights_Dates::resolve( 'custom', '2021-08-01', '2021-08-31' ), null );
kdna_ei_a( 'The Marketing screen shows the same $1,000', 1000.0 === round( $kdna_ei_mkt['channels'][0]['spend'], 2 ), $kdna_ei_mkt['channels'] );
KDNA_EcommerceInsights_Settings::update( array( 'tax' => array( 'system' => 'none' ) ) );

/*
 * ---------------------------------------------------------------------
 * CSV import
 * ---------------------------------------------------------------------
 */
echo "\n== Meta Ads Manager export (daily) ==\n";

$kdna_ei_csv = file_get_contents( $kdna_ei_fixtures . 'meta-ads-manager-daily.csv' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$kdna_ei_p   = kdna_ei_a_call( 'POST', '/adspend/import/preview', array( 'csv' => $kdna_ei_csv, 'channel' => 'meta' ) )->get_data();
kdna_ei_a( 'Recognised as a Meta export', 'meta' === ( $kdna_ei_p['preset'] ?? '' ), $kdna_ei_p['preset'] ?? $kdna_ei_p );
kdna_ei_a( 'Columns mapped by the preset', 'day' === $kdna_ei_p['mapping']['date'] && 'amount spent (aud)' === $kdna_ei_p['mapping']['spend'] && 'campaign name' === $kdna_ei_p['mapping']['campaign_name'] && 'link clicks' === $kdna_ei_p['mapping']['clicks'] && 'purchases conversion value' === $kdna_ei_p['mapping']['conversion_value'], $kdna_ei_p['mapping'] );
kdna_ei_a( 'Currency read from the column name (AUD)', 'AUD' === $kdna_ei_p['currency'] );
kdna_ei_a( 'Preview totals: 6 rows, 2 campaigns, 3 days, $339.90', 6 === $kdna_ei_p['totals']['rows'] && 2 === $kdna_ei_p['totals']['campaigns'] && 3 === $kdna_ei_p['totals']['days'] && 339.9 === $kdna_ei_p['totals']['spend'], $kdna_ei_p['totals'] );
kdna_ei_a( 'No problem rows', 0 === $kdna_ei_p['error_count'], $kdna_ei_p['errors'] );
kdna_ei_a( 'Preview says $100 a day of Meta CSV spend would be replaced: none yet', 0.0 === (float) $kdna_ei_p['replaces'] );

$kdna_ei_i = kdna_ei_a_call( 'POST', '/adspend/import', array( 'csv' => $kdna_ei_csv, 'channel' => 'meta' ) )->get_data();
kdna_ei_a( 'Imported', isset( $kdna_ei_i['entry_group'] ) && 339.9 === (float) $kdna_ei_i['spend'], $kdna_ei_i );
kdna_ei_a( 'Saved CSV spend is $339.90', 339.9 === kdna_ei_a_sum( '2021-09-01', '2021-09-03', 'meta', 'csv' ) );
kdna_ei_a( 'Manual Meta spend on the same days is untouched', 300.0 === kdna_ei_a_sum( '2021-09-01', '2021-09-03', 'meta', 'manual' ) );

$kdna_ei_i = kdna_ei_a_call( 'POST', '/adspend/import', array( 'csv' => $kdna_ei_csv, 'channel' => 'meta' ) )->get_data();
kdna_ei_a( 'Importing the same file again replaces it, never doubles it', 339.9 === kdna_ei_a_sum( '2021-09-01', '2021-09-03', 'meta', 'csv' ) && 339.9 === (float) $kdna_ei_i['replaced'], $kdna_ei_i );

$kdna_ei_mkt      = KDNA_EcommerceInsights_Report::marketing( KDNA_EcommerceInsights_Dates::resolve( 'custom', '2021-09-01', '2021-09-03' ), null );
$kdna_ei_campaign = current( array_filter( $kdna_ei_mkt['campaigns'], static fn( $c ) => 'Spring Serum Launch' === $c['campaign_name'] ) );
kdna_ei_a( 'Campaign spend, purchases and value add up', $kdna_ei_campaign && 263.6 === $kdna_ei_campaign['spend'] && 12.0 === $kdna_ei_campaign['conversions'] && 1068.0 === $kdna_ei_campaign['conversion_value'], $kdna_ei_campaign );
kdna_ei_a( 'Campaign ROAS = value / spend (4.05)', $kdna_ei_campaign && 4.05 === $kdna_ei_campaign['roas'], $kdna_ei_campaign['roas'] ?? null );
$kdna_ei_entries = KDNA_EcommerceInsights_Ad_Spend::entries( '2021-09-01', '2021-09-03' );
$kdna_ei_import  = current( array_filter( $kdna_ei_entries, static fn( $e ) => 'csv' === $e['source'] ) );
kdna_ei_a( 'The import is one line in the entries list, with 2 campaigns', 1 === count( array_filter( $kdna_ei_entries, static fn( $e ) => 'csv' === $e['source'] ) ) && 2 === $kdna_ei_import['campaigns'], $kdna_ei_entries );
kdna_ei_a( 'Imported spend cannot be edited, only deleted', ! $kdna_ei_import['editable'] && 400 === kdna_ei_a_call( 'PUT', '/adspend/' . $kdna_ei_import['entry_group'], array( 'start' => '2021-09-01', 'channel' => 'meta', 'amount' => 1 ) )->get_status() );

echo "\n== Meta Ads Manager export (whole month, no daily breakdown) ==\n";

$kdna_ei_csv = file_get_contents( $kdna_ei_fixtures . 'meta-ads-manager-month.csv' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$kdna_ei_i   = kdna_ei_a_call( 'POST', '/adspend/import', array( 'csv' => $kdna_ei_csv, 'channel' => 'meta' ) )->get_data();
kdna_ei_a( 'Imported "1,550.00" and 310.00', 1860.0 === (float) $kdna_ei_i['spend'], $kdna_ei_i );
kdna_ei_a( 'Spread across all 31 days of October', '2021-10-01' === $kdna_ei_i['start'] && '2021-10-31' === $kdna_ei_i['end'] && 60.0 === kdna_ei_a_sum( '2021-10-15', '2021-10-15', 'meta' ), kdna_ei_a_sum( '2021-10-15', '2021-10-15', 'meta' ) );
kdna_ei_a( 'October adds up to exactly $1,860', 1860.0 === kdna_ei_a_sum( '2021-10-01', '2021-10-31', 'meta' ) );

echo "\n== Google Ads export ==\n";

$kdna_ei_csv = file_get_contents( $kdna_ei_fixtures . 'google-ads-campaigns.csv' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$kdna_ei_p   = kdna_ei_a_call( 'POST', '/adspend/import/preview', array( 'csv' => $kdna_ei_csv, 'channel' => 'google' ) )->get_data();
kdna_ei_a( 'Recognised as a Google Ads export (title lines skipped)', 'google' === ( $kdna_ei_p['preset'] ?? '' ), $kdna_ei_p );
kdna_ei_a( 'Columns mapped by the preset', 'cost' === $kdna_ei_p['mapping']['spend'] && 'impr.' === $kdna_ei_p['mapping']['impressions'] && 'conv. value' === $kdna_ei_p['mapping']['conversion_value'] && 'campaign id' === $kdna_ei_p['mapping']['campaign_id'], $kdna_ei_p['mapping'] );
kdna_ei_a( 'Both "Total:" rows left out', 2 === $kdna_ei_p['skipped'] && 6 === $kdna_ei_p['totals']['rows'], array( $kdna_ei_p['skipped'], $kdna_ei_p['totals'] ) );
kdna_ei_a( 'Total $1,844.70 ("1,234.50" read correctly, "--" read as nothing spent)', 1844.7 === $kdna_ei_p['totals']['spend'] && 0 === $kdna_ei_p['error_count'], array( $kdna_ei_p['totals'], $kdna_ei_p['errors'] ) );
kdna_ei_a( 'Currency read from the Currency code column', 'AUD' === $kdna_ei_p['currency'] );

// Google's "Excel" download: the same table as UTF-16 with tabs between cells.
$kdna_ei_tabbed = implode( "\n", array_map( static fn( $line ) => implode( "\t", str_getcsv( $line, ',', '"', '\\' ) ), explode( "\n", trim( $kdna_ei_csv ) ) ) );
$kdna_ei_utf16  = "\xFF\xFE" . mb_convert_encoding( $kdna_ei_tabbed, 'UTF-16LE', 'UTF-8' );
$kdna_ei_p     = KDNA_EcommerceInsights_Ad_Spend_Import::preview( $kdna_ei_utf16, array( 'channel' => 'google' ) );
kdna_ei_a( 'The UTF-16 tab-separated "Excel" version reads the same', ! is_wp_error( $kdna_ei_p ) && 'google' === $kdna_ei_p['preset'] && 1844.7 === $kdna_ei_p['totals']['spend'], is_wp_error( $kdna_ei_p ) ? $kdna_ei_p->get_error_message() : $kdna_ei_p['totals'] );

$kdna_ei_i = kdna_ei_a_call( 'POST', '/adspend/import', array( 'csv' => $kdna_ei_csv, 'channel' => 'google' ) )->get_data();
kdna_ei_a( 'Imported $1,844.70 for Google', 1844.7 === kdna_ei_a_sum( '2021-09-01', '2021-09-03', 'google' ), $kdna_ei_i );
$kdna_ei_mkt = KDNA_EcommerceInsights_Report::marketing( KDNA_EcommerceInsights_Dates::resolve( 'custom', '2021-09-01', '2021-09-03' ), null );
$kdna_ei_ch  = array_column( $kdna_ei_mkt['channels'], null, 'channel' );
kdna_ei_a( 'Google ROAS 5,552.90 / 1,844.70 = 3.01', 3.01 === $kdna_ei_ch['google']['roas'], $kdna_ei_ch['google'] );
kdna_ei_a( 'Spend by channel over time adds up to each channel total', abs( array_sum( $kdna_ei_mkt['series']['google'] ) - 1844.7 ) < 0.01 && abs( array_sum( $kdna_ei_mkt['series']['meta'] ) - ( 339.9 + 300 ) ) < 0.01, $kdna_ei_mkt['series'] );

echo "\n== Mapping by hand, dates and presets ==\n";

$kdna_ei_own = "When,Spent,Campaign\n13/09/2021,40.00,Newsletter\n14/09/2021,\"1.234,50\",Newsletter\nnot a date,5,Oops\n";
$kdna_ei_p   = KDNA_EcommerceInsights_Ad_Spend_Import::preview( $kdna_ei_own, array( 'channel' => 'email' ) );
kdna_ei_a( 'Unknown layout: asks for the date and spend columns', 2 === count( $kdna_ei_p['errors'] ) && '' === $kdna_ei_p['mapping']['date'], $kdna_ei_p['errors'] );
$kdna_ei_map = array( 'date' => 'when', 'spend' => 'spent', 'campaign_name' => 'campaign' );
$kdna_ei_p   = KDNA_EcommerceInsights_Ad_Spend_Import::preview( $kdna_ei_own, array( 'channel' => 'email', 'mapping' => $kdna_ei_map ) );
kdna_ei_a( 'Day-first dates (13/09/2021) are understood', '2021-09-13' === $kdna_ei_p['start'] && '2021-09-14' === $kdna_ei_p['end'], array( $kdna_ei_p['start'], $kdna_ei_p['end'] ) );
kdna_ei_a( 'European "1.234,50" reads as 1,234.50', 1274.5 === $kdna_ei_p['totals']['spend'], $kdna_ei_p['totals'] );
kdna_ei_a( 'The bad row is reported with its line number', 1 === $kdna_ei_p['error_count'] && false !== strpos( $kdna_ei_p['errors'][0]['message'], 'Line 4' ), $kdna_ei_p['errors'] );
kdna_ei_a( 'US dates (9/13/2021) are understood too', '2021-09-13' === KDNA_EcommerceInsights_Ad_Spend_Import::parse_date( '9/13/2021', KDNA_EcommerceInsights_Ad_Spend_Import::date_order( array( '9/13/2021' ) ) ) );
kdna_ei_a( 'Written dates ("Sep 1, 2021", "1 Sep 2021") are understood', '2021-09-01' === KDNA_EcommerceInsights_Ad_Spend_Import::parse_date( 'Sep 1, 2021', 'dmy' ) && '2021-09-01' === KDNA_EcommerceInsights_Ad_Spend_Import::parse_date( '1 Sep 2021', 'dmy' ) );

$kdna_ei_r = kdna_ei_a_call( 'POST', '/adspend/presets', array( 'name' => 'Newsletter sheet', 'channel' => 'email', 'mapping' => $kdna_ei_map ) )->get_data();
$kdna_ei_k = current( array_filter( $kdna_ei_r['presets'], static fn( $p ) => 'Newsletter sheet' === $p['name'] ) );
kdna_ei_a( 'Mapping saved as a preset', $kdna_ei_k && 'email' === $kdna_ei_k['channel'] && ! $kdna_ei_k['builtin'], $kdna_ei_r );
$kdna_ei_p = KDNA_EcommerceInsights_Ad_Spend_Import::preview( $kdna_ei_own, array( 'channel' => 'email', 'preset' => $kdna_ei_k['key'] ) );
kdna_ei_a( 'Next time the preset maps it with no clicks', 'when' === $kdna_ei_p['mapping']['date'] && 1274.5 === $kdna_ei_p['totals']['spend'], $kdna_ei_p['mapping'] );
$kdna_ei_setup = kdna_ei_a_call( 'GET', '/adspend/setup' )->get_data();
kdna_ei_a( 'Setup lists the Meta and Google presets plus the saved one', 3 <= count( $kdna_ei_setup['presets'] ) && 'meta' === $kdna_ei_setup['presets'][0]['key'] && 'google' === $kdna_ei_setup['presets'][1]['key'], wp_list_pluck( $kdna_ei_setup['presets'], 'key' ) );
$kdna_ei_r = kdna_ei_a_call( 'DELETE', '/adspend/presets', array( 'name' => 'Newsletter sheet' ) )->get_data();
kdna_ei_a( 'Saved preset can be deleted', ! array_filter( $kdna_ei_r['presets'], static fn( $p ) => 'Newsletter sheet' === $p['name'] ) );

$kdna_ei_r = kdna_ei_a_call( 'POST', '/adspend/import', array( 'csv' => $kdna_ei_own, 'channel' => '', 'mapping' => $kdna_ei_map ) );
kdna_ei_a( 'Import without a channel is refused', 400 === $kdna_ei_r->get_status() );

$kdna_ei_r = kdna_ei_a_call( 'POST', '/adspend/import', array( 'csv' => "Day,Cost\n2021-09-01,10\n", 'channel' => 'google', 'rate' => '1.5' ) )->get_data();
kdna_ei_a( 'An exchange rate converts the amounts (10 x 1.5 = 15)', 15.0 === (float) $kdna_ei_r['spend'], $kdna_ei_r );

$kdna_ei_r = kdna_ei_a_call( 'GET', '/export', array( 'table' => 'adspend', 'start' => '2021-08-01', 'end' => '2021-10-31' ) )->get_data();
kdna_ei_a( 'Entries export as CSV', false !== strpos( $kdna_ei_r['csv'] ?? '', 'Includes GST' ), $kdna_ei_r );

/*
 * ---------------------------------------------------------------------
 * Clean up
 * ---------------------------------------------------------------------
 */
kdna_ei_a_cleanup();
KDNA_EcommerceInsights_Settings::update( $kdna_ei_saved );
kdna_ei_a( 'Test spend removed', 0.0 === kdna_ei_a_sum( '2021-08-01', '2021-10-31' ) );

echo "\n" . $GLOBALS['kdna_ei_a_count'] . ' checks, ' . $GLOBALS['kdna_ei_a_fail'] . " failed\n";
