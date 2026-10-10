<?php
/**
 * Integration tests for Stage 10: token encryption, the Meta and Google Ads
 * connections, syncing (re-reading 7 days, replacing, no double counting),
 * blocking manual and CSV spend for synced dates, plain-English errors,
 * scheduling, logging and disconnecting.
 *
 * Meta and Google are simulated: every request to them is answered by the
 * mock below with replies shaped like the real APIs, so this runs without
 * accounts or internet access. The mock also checks the requests (tokens
 * only in headers, the right API versions).
 *
 * Test site only:
 *
 *     wp eval-file tests/test-ad-connections.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-ad-connections.php\n" );

$GLOBALS['kdna_ei_t_fail']  = 0;
$GLOBALS['kdna_ei_t_count'] = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is checked.
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
 * Calls a plugin REST route as the current user with a valid nonce.
 *
 * @param string $method HTTP method.
 * @param string $path   Path after kdna-ei/v1.
 * @param array  $params Parameters.
 * @return WP_REST_Response
 */
function kdna_ei_t_call( string $method, string $path, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/kdna-ei/v1' . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	return rest_do_request( $request );
}

require_once __DIR__ . '/support/ad-platform-mock.php';

/**
 * A day relative to today, Y-m-d (uses the mock's helper).
 *
 * @param int $offset Days back.
 * @return string
 */
function kdna_ei_t_day( int $offset ): string {
	return kdna_ei_mock_day( $offset );
}

/**
 * Spend saved for a channel and source in the last 30 days.
 *
 * @param string $channel Channel.
 * @param string $source  Source or empty for all.
 * @return float
 */
function kdna_ei_t_spend( string $channel, string $source = '' ): float {
	global $wpdb;
	$sql = 'SELECT COALESCE( SUM( spend ), 0 ) FROM ' . KDNA_EcommerceInsights_Install::table( 'ad_spend' ) . ' WHERE channel = %s AND spend_date >= %s' . ( $source ? ' AND source = %s' : '' );
	$arg = array_filter( array( $channel, kdna_ei_t_day( 60 ), $source ) );
	return round( (float) $wpdb->get_var( $wpdb->prepare( $sql, $arg ) ), 2 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}


wp_set_current_user( 1 );
global $wpdb;

$kdna_ei_saved = array(
	'marketing' => KDNA_EcommerceInsights_Settings::get( 'marketing' ),
	'secrets'   => get_option( KDNA_EcommerceInsights_Crypto::OPTION, null ),
	'status'    => get_option( KDNA_EcommerceInsights_Ad_Sync::STATUS_OPTION, null ),
);
$kdna_ei_table = KDNA_EcommerceInsights_Install::table( 'ad_spend' );
$wpdb->query( "DELETE FROM {$kdna_ei_table} WHERE channel IN ( 'meta', 'google' ) AND spend_date >= '" . kdna_ei_t_day( 60 ) . "'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
KDNA_EcommerceInsights_Ad_Sync::disconnect( 'meta' );
KDNA_EcommerceInsights_Ad_Sync::disconnect( 'google' );

/*
 * ---------------------------------------------------------------------
 * Encryption
 * ---------------------------------------------------------------------
 */
echo "\n== Token encryption ==\n";

$kdna_ei_secret = 'EAAG-secret-' . wp_generate_password( 20, false );
$kdna_ei_enc    = KDNA_EcommerceInsights_Crypto::encrypt( $kdna_ei_secret );
kdna_ei_t( 'Encrypted text does not contain the secret', false === strpos( $kdna_ei_enc, $kdna_ei_secret ) && 0 === strpos( $kdna_ei_enc, 'kdna1' ) );
kdna_ei_t( 'Decrypts back to the same secret', $kdna_ei_secret === KDNA_EcommerceInsights_Crypto::decrypt( $kdna_ei_enc ) );
kdna_ei_t( 'Encrypting twice gives different text (random nonce)', $kdna_ei_enc !== KDNA_EcommerceInsights_Crypto::encrypt( $kdna_ei_secret ) );
$kdna_ei_tampered = substr( $kdna_ei_enc, 0, -4 ) . ( 'AAAA' === substr( $kdna_ei_enc, -4 ) ? 'BBBB' : 'AAAA' );
kdna_ei_t( 'Tampered text is rejected', null === KDNA_EcommerceInsights_Crypto::decrypt( $kdna_ei_tampered ) );
$kdna_ei_salt = static fn( $salt ) => $salt . 'changed';
add_filter( 'salt', $kdna_ei_salt );
kdna_ei_t( 'After the site salts change, the old value cannot be read', null === KDNA_EcommerceInsights_Crypto::decrypt( $kdna_ei_enc ) );
remove_filter( 'salt', $kdna_ei_salt );

/*
 * ---------------------------------------------------------------------
 * Meta
 * ---------------------------------------------------------------------
 */
echo "\n== Meta: saving and testing ==\n";

$kdna_ei_r = kdna_ei_t_call( 'POST', '/connections/meta', array( 'app_id' => 'abc', 'ad_account_id' => '', 'token' => 'short' ) );
$kdna_ei_f = $kdna_ei_r->get_data()['data']['fields'] ?? array();
kdna_ei_t( 'Bad details are refused with a message per field', 400 === $kdna_ei_r->get_status() && isset( $kdna_ei_f['app_id'], $kdna_ei_f['ad_account_id'], $kdna_ei_f['token'] ), $kdna_ei_f );

$kdna_ei_token = str_repeat( 'EAAG', 15 );
$kdna_ei_r     = kdna_ei_t_call( 'POST', '/connections/meta', array( 'app_id' => '1234567890123456', 'ad_account_id' => 'act_123456789', 'token' => $kdna_ei_token ) );
$kdna_ei_d     = $kdna_ei_r->get_data();
kdna_ei_t( 'Saved, and tested straight away', 200 === $kdna_ei_r->get_status() && true === $kdna_ei_d['test']['ok'], $kdna_ei_d );
kdna_ei_t( 'Card shows the ad account name', 'Maison Commerce Ads' === $kdna_ei_d['connection']['account'] && 'connected' === $kdna_ei_d['connection']['state'], $kdna_ei_d['connection'] );
kdna_ei_t( 'act_ prefix removed from the saved account ID', '123456789' === KDNA_EcommerceInsights_Settings::get( 'marketing.meta.ad_account_id' ) );

$kdna_ei_last = end( $GLOBALS['kdna_ei_t_log'] );
kdna_ei_t( 'Request uses Graph API ' . KDNA_EcommerceInsights_Meta_Ads::GRAPH_VERSION, false !== strpos( $kdna_ei_last['url'], '/' . KDNA_EcommerceInsights_Meta_Ads::GRAPH_VERSION . '/act_123456789' ) );
kdna_ei_t( 'Token is sent in a header, never in the address', false === strpos( $kdna_ei_last['url'], $kdna_ei_token ) && 'Bearer ' . $kdna_ei_token === $kdna_ei_last['args']['headers']['Authorization'] );

$kdna_ei_raw = wp_json_encode( get_option( KDNA_EcommerceInsights_Crypto::OPTION ) );
kdna_ei_t( 'Token is encrypted in the database', false === strpos( $kdna_ei_raw, $kdna_ei_token ) && false !== strpos( $kdna_ei_raw, 'kdna1' ) );
$kdna_ei_all = wp_json_encode( kdna_ei_t_call( 'GET', '/connections' )->get_data() ) . wp_json_encode( kdna_ei_t_call( 'GET', '/settings' )->get_data() ) . wp_json_encode( $kdna_ei_d );
kdna_ei_t( 'Token never appears in any REST response', false === strpos( $kdna_ei_all, $kdna_ei_token ) && false === strpos( $kdna_ei_all, 'EAAGEAAG' ) );
kdna_ei_t( 'REST only says a token is saved', true === kdna_ei_t_call( 'GET', '/connections' )->get_data()['connections']['meta']['settings']['has_token'] );
$kdna_ei_r = kdna_ei_t_call( 'POST', '/connections/meta', array( 'app_id' => '1234567890123456', 'ad_account_id' => '123456789', 'token' => '' ) );
kdna_ei_t( 'Saving again with an empty token keeps the saved one', 200 === $kdna_ei_r->get_status() && $kdna_ei_token === KDNA_EcommerceInsights_Crypto::get( KDNA_EcommerceInsights_Meta_Ads::TOKEN ) );

echo "\n== Meta: syncing ==\n";

// Manual Meta spend added before connecting, for the same days.
KDNA_EcommerceInsights_Ad_Spend::create( array( 'start' => kdna_ei_t_day( 3 ), 'end' => kdna_ei_t_day( 1 ), 'channel' => 'meta', 'amount' => 90 ) );
KDNA_EcommerceInsights_Ad_Spend::create( array( 'start' => kdna_ei_t_day( 20 ), 'end' => kdna_ei_t_day( 20 ), 'channel' => 'meta', 'amount' => 15 ) );

$kdna_ei_r = kdna_ei_t_call( 'POST', '/connections/meta/sync' );
$kdna_ei_s = $kdna_ei_r->get_data()['result'] ?? array();
kdna_ei_t( 'Sync now reads both pages: 3 campaign days', 200 === $kdna_ei_r->get_status() && 3 === $kdna_ei_s['rows'], $kdna_ei_r->get_data() );
kdna_ei_t( 'Spend 120.50 + 100 + 30 = 250.50', 250.5 === (float) $kdna_ei_s['spend'] && 250.5 === kdna_ei_t_spend( 'meta', 'api' ), array( $kdna_ei_s, kdna_ei_t_spend( 'meta', 'api' ) ) );
kdna_ei_t( 'It covers the last 7 days', kdna_ei_t_day( 6 ) === $kdna_ei_s['start'] && kdna_ei_t_day( 0 ) === $kdna_ei_s['end'] );
kdna_ei_t( 'Manual Meta spend on synced days ($90) is replaced, and reported', 90.0 === (float) $kdna_ei_s['replaced'] && 0.0 === kdna_ei_t_spend( 'meta', 'manual' ) - 15.0, array( $kdna_ei_s['replaced'], kdna_ei_t_spend( 'meta', 'manual' ) ) );
kdna_ei_t( 'Manual Meta spend before the window ($15) is kept', 15.0 === kdna_ei_t_spend( 'meta', 'manual' ) );

$kdna_ei_camp = $wpdb->get_row( $wpdb->prepare( "SELECT SUM( conversions ) AS c, SUM( conversion_value ) AS v, SUM( clicks ) AS k FROM {$kdna_ei_table} WHERE channel = 'meta' AND source = 'api' AND campaign_name = 'Spring launch' AND spend_date >= %s", kdna_ei_t_day( 7 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
kdna_ei_t( 'Purchases counted once (omni_purchase), not three times: 5 and $600', 5.0 === (float) $kdna_ei_camp['c'] && 600.0 === (float) $kdna_ei_camp['v'], $kdna_ei_camp );
kdna_ei_t( 'Link clicks are used: 120', 120 === (int) $kdna_ei_camp['k'], $kdna_ei_camp );

kdna_ei_t_call( 'POST', '/connections/meta/sync' );
kdna_ei_t( 'Syncing again replaces the 7 days: still $250.50, no duplicates', 250.5 === kdna_ei_t_spend( 'meta', 'api' ), kdna_ei_t_spend( 'meta', 'api' ) );

$kdna_ei_sum = kdna_ei_t_call( 'GET', '/summary', array( 'start' => kdna_ei_t_day( 6 ), 'end' => kdna_ei_t_day( 0 ), 'compare' => 'none', 'metrics' => 'ad_spend', 'fresh' => true ) )->get_data()['data']['metrics'][0]['value'];
kdna_ei_t( 'Overview ad spend for the week is exactly the synced $250.50', 250.5 === round( (float) $kdna_ei_sum, 2 ), $kdna_ei_sum );

echo "\n== No double counting ==\n";

$kdna_ei_r = kdna_ei_t_call( 'POST', '/adspend', array( 'start' => kdna_ei_t_day( 2 ), 'end' => kdna_ei_t_day( 2 ), 'channel' => 'meta', 'amount' => 50 ) );
$kdna_ei_m = $kdna_ei_r->get_data()['data']['fields']['channel'] ?? '';
kdna_ei_t( 'Manual Meta spend on a synced date is refused', 400 === $kdna_ei_r->get_status() && false !== strpos( $kdna_ei_m, 'count it twice' ), $kdna_ei_m );
$kdna_ei_r = kdna_ei_t_call( 'POST', '/adspend', array( 'start' => kdna_ei_t_day( 0 ), 'end' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ), 'channel' => 'meta', 'amount' => 50 ) );
kdna_ei_t( 'Future Meta spend is refused too (it will be synced)', 400 === $kdna_ei_r->get_status() );
$kdna_ei_r = kdna_ei_t_call( 'POST', '/adspend', array( 'start' => kdna_ei_t_day( 30 ), 'end' => kdna_ei_t_day( 25 ), 'channel' => 'meta', 'amount' => 60 ) );
kdna_ei_t( 'Meta spend from before the first synced day is allowed', 200 === $kdna_ei_r->get_status(), $kdna_ei_r->get_data() );
$kdna_ei_r = kdna_ei_t_call( 'POST', '/adspend', array( 'start' => kdna_ei_t_day( 2 ), 'end' => kdna_ei_t_day( 2 ), 'channel' => 'tiktok', 'amount' => 20 ) );
kdna_ei_t( 'Other channels on the same day are allowed', 200 === $kdna_ei_r->get_status() );
$kdna_ei_tiktok = $kdna_ei_r->get_data()['data']['entry_group'];
$kdna_ei_r      = kdna_ei_t_call( 'POST', '/adspend/import', array( 'csv' => 'Day,Amount spent (' . get_option( 'woocommerce_currency' ) . ")\n" . kdna_ei_t_day( 1 ) . ",40\n", 'channel' => 'meta' ) );
kdna_ei_t( 'CSV import of Meta spend for synced dates is refused', 400 === $kdna_ei_r->get_status() && false !== strpos( $kdna_ei_r->get_data()['message'], 'count it twice' ), $kdna_ei_r->get_data() );
$kdna_ei_live = current( array_filter( KDNA_EcommerceInsights_Ad_Spend::entries( kdna_ei_t_day( 6 ), kdna_ei_t_day( 0 ) ), static fn( $e ) => 'api' === $e['source'] ) );
kdna_ei_t( 'Synced spend shows as live and cannot be deleted while connected', $kdna_ei_live && $kdna_ei_live['live'] && 400 === kdna_ei_t_call( 'DELETE', '/adspend/' . $kdna_ei_live['entry_group'] )->get_status(), $kdna_ei_live );

echo "\n== Currency ==\n";

$GLOBALS['kdna_ei_t_mode'] = array( 'meta_currency' => 'USD' === get_option( 'woocommerce_currency' ) ? 'AUD' : 'USD' );
$kdna_ei_r                 = kdna_ei_t_call( 'POST', '/connections/meta/test' )->get_data();
kdna_ei_t( 'A different ad account currency is flagged', false !== strpos( $kdna_ei_r['connection']['warning'], 'conversion rate' ), $kdna_ei_r['connection']['warning'] ?? $kdna_ei_r );
kdna_ei_t_call( 'POST', '/connections/rate', array( 'rate' => '0.5' ) );
kdna_ei_t_call( 'POST', '/connections/meta/sync' );
kdna_ei_t( 'With a rate of 0.5, the same spend is stored as $125.25', 125.25 === kdna_ei_t_spend( 'meta', 'api' ), kdna_ei_t_spend( 'meta', 'api' ) );
kdna_ei_t_call( 'POST', '/connections/rate', array( 'rate' => '1' ) );
$GLOBALS['kdna_ei_t_mode'] = array();
kdna_ei_t_call( 'POST', '/connections/meta/sync' );

echo "\n== Meta: expired token ==\n";

$GLOBALS['kdna_ei_t_mode'] = array( 'meta_expired' => true );
$kdna_ei_r                 = kdna_ei_t_call( 'POST', '/connections/meta/sync' );
$kdna_ei_d                 = $kdna_ei_r->get_data();
kdna_ei_t( 'A failed sync says so in plain English', 400 === $kdna_ei_r->get_status() && false !== strpos( $kdna_ei_d['message'], 'no longer valid' ), $kdna_ei_d );
kdna_ei_t( 'The card shows the problem', 'error' === $kdna_ei_d['data']['connection']['state'] && false !== strpos( $kdna_ei_d['data']['connection']['message'], 'Generate a new token' ), $kdna_ei_d['data']['connection'] ?? null );
kdna_ei_t( 'Spend already synced is left untouched', 250.5 === kdna_ei_t_spend( 'meta', 'api' ) );
kdna_ei_t( 'The dashboard still works', 200 === kdna_ei_t_call( 'GET', '/summary', array( 'preset' => 'this_month' ) )->get_status() );
$GLOBALS['kdna_ei_t_mode'] = array();

$kdna_ei_logs = $wpdb->get_col( 'SELECT status FROM ' . KDNA_EcommerceInsights_Install::table( 'sync_log' ) . " WHERE type = 'sync_meta' ORDER BY id DESC LIMIT 2" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
kdna_ei_t( 'Every sync is logged in sync_log, failures included', array( 'error', 'success' ) === $kdna_ei_logs, $kdna_ei_logs );

$kdna_ei_salt = static fn( $salt ) => $salt . 'changed';
add_filter( 'salt', $kdna_ei_salt );
$kdna_ei_r = KDNA_EcommerceInsights_Ad_Sync::test( 'meta' );
remove_filter( 'salt', $kdna_ei_salt );
kdna_ei_t( 'If the site salts change, it asks for the token again', is_wp_error( $kdna_ei_r ) && false !== strpos( $kdna_ei_r->get_error_message(), 'paste the token in again' ), is_wp_error( $kdna_ei_r ) ? $kdna_ei_r->get_error_message() : $kdna_ei_r );

echo "\n== Scheduling ==\n";

KDNA_EcommerceInsights_Settings::update( array( 'marketing' => array( 'sync_frequency' => 'daily' ) ) );
kdna_ei_t( 'A daily sync is scheduled for Meta', as_has_scheduled_action( KDNA_EcommerceInsights_Ad_Sync::HOOK, array( 'meta' ), KDNA_EcommerceInsights_Order_Processor::GROUP ) );
kdna_ei_t( 'Nothing is scheduled for Google while it is not set up', ! as_has_scheduled_action( KDNA_EcommerceInsights_Ad_Sync::HOOK, array( 'google' ), KDNA_EcommerceInsights_Order_Processor::GROUP ) );
$kdna_ei_next = as_next_scheduled_action( KDNA_EcommerceInsights_Ad_Sync::HOOK, array( 'meta' ), KDNA_EcommerceInsights_Order_Processor::GROUP );
kdna_ei_t( 'It runs at about 5am site time', is_int( $kdna_ei_next ) && '05:00' === wp_date( 'H:i', $kdna_ei_next ), is_int( $kdna_ei_next ) ? wp_date( 'Y-m-d H:i', $kdna_ei_next ) : $kdna_ei_next );
KDNA_EcommerceInsights_Settings::update( array( 'marketing' => array( 'sync_frequency' => 'manual' ) ) );
kdna_ei_t( '"Manual only" removes the schedule', ! as_has_scheduled_action( KDNA_EcommerceInsights_Ad_Sync::HOOK, array( 'meta' ), KDNA_EcommerceInsights_Order_Processor::GROUP ) );
KDNA_EcommerceInsights_Settings::update( array( 'marketing' => array( 'sync_frequency' => 'daily' ) ) );

/*
 * ---------------------------------------------------------------------
 * Google Ads
 * ---------------------------------------------------------------------
 */
echo "\n== Google Ads: saving and signing in ==\n";

$kdna_ei_r = kdna_ei_t_call( 'POST', '/connections/google', array( 'client_id' => 'nope', 'customer_id' => '12345', 'developer_token' => '!!' ) );
$kdna_ei_f = $kdna_ei_r->get_data()['data']['fields'] ?? array();
kdna_ei_t( 'Bad details are refused with a message per field', 400 === $kdna_ei_r->get_status() && isset( $kdna_ei_f['client_id'], $kdna_ei_f['client_secret'], $kdna_ei_f['developer_token'], $kdna_ei_f['customer_id'] ), $kdna_ei_f );

$kdna_ei_dev = 'AbCdEfGhIjKlMnOpQrStUv';
$kdna_ei_r   = kdna_ei_t_call( 'POST', '/connections/google', array( 'client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'test-secret-value', 'developer_token' => $kdna_ei_dev, 'customer_id' => '123-456-7890', 'login_customer_id' => '' ) );
$kdna_ei_d   = $kdna_ei_r->get_data();
kdna_ei_t( 'Saved; not tested yet because sign-in is still needed', 200 === $kdna_ei_r->get_status() && null === $kdna_ei_d['test'] && in_array( 'Sign in with Google', $kdna_ei_d['connection']['missing'], true ), $kdna_ei_d );
kdna_ei_t( 'Customer ID saved as digits', '1234567890' === KDNA_EcommerceInsights_Settings::get( 'marketing.google.customer_id' ) );

$kdna_ei_url = kdna_ei_t_call( 'GET', '/connections/google/auth-url' )->get_data()['url'] ?? '';
parse_str( (string) wp_parse_url( $kdna_ei_url, PHP_URL_QUERY ), $kdna_ei_q );
kdna_ei_t( 'Sign-in address asks for read access to Google Ads, offline', 0 === strpos( $kdna_ei_url, KDNA_EcommerceInsights_Google_Ads::AUTH_URL ) && KDNA_EcommerceInsights_Google_Ads::SCOPE === $kdna_ei_q['scope'] && 'offline' === $kdna_ei_q['access_type'] && KDNA_EcommerceInsights_Google_Ads::redirect_uri() === $kdna_ei_q['redirect_uri'], $kdna_ei_q );

$kdna_ei_google = KDNA_EcommerceInsights_Ad_Sync::platform( 'google' );
$kdna_ei_r      = $kdna_ei_google->complete_sign_in( 'forged-state', 'GOODCODE' );
kdna_ei_t( 'A reply with the wrong check value is refused', is_wp_error( $kdna_ei_r ) && 'kdna_ei_google_state' === $kdna_ei_r->get_error_code() );
$kdna_ei_url = $kdna_ei_google->auth_url();
parse_str( (string) wp_parse_url( $kdna_ei_url, PHP_URL_QUERY ), $kdna_ei_q );
$kdna_ei_r = $kdna_ei_google->complete_sign_in( $kdna_ei_q['state'], 'GOODCODE' );
kdna_ei_t( 'A genuine reply signs in', true === $kdna_ei_r, $kdna_ei_r );
kdna_ei_t( 'The check value works only once', is_wp_error( $kdna_ei_google->complete_sign_in( $kdna_ei_q['state'], 'GOODCODE' ) ) );
$kdna_ei_raw = wp_json_encode( get_option( KDNA_EcommerceInsights_Crypto::OPTION ) ) . wp_json_encode( get_transient( KDNA_EcommerceInsights_Google_Ads::ACCESS_CACHE ) );
kdna_ei_t( 'Refresh token, secret, developer token and access token are all encrypted', false === strpos( $kdna_ei_raw, '1//refresh-token-secret' ) && false === strpos( $kdna_ei_raw, 'test-secret-value' ) && false === strpos( $kdna_ei_raw, $kdna_ei_dev ) && false === strpos( $kdna_ei_raw, 'ya29.' ) );
$kdna_ei_r = $kdna_ei_google->complete_sign_in( '', '', 'access_denied' );
kdna_ei_t( 'Cancelling sign-in gives a plain message', is_wp_error( $kdna_ei_r ) && false !== strpos( $kdna_ei_r->get_error_message(), 'cancelled' ) );

echo "\n== Google Ads: testing and syncing ==\n";

$kdna_ei_r = kdna_ei_t_call( 'POST', '/connections/google/test' )->get_data();
kdna_ei_t( 'Test reads the account name', 'Maison Commerce Google' === ( $kdna_ei_r['account']['name'] ?? '' ), $kdna_ei_r );
$kdna_ei_last = end( $GLOBALS['kdna_ei_t_log'] );
kdna_ei_t( 'Request uses Google Ads API ' . KDNA_EcommerceInsights_Google_Ads::API_VERSION . ' with the developer token header', false !== strpos( $kdna_ei_last['url'], '/' . KDNA_EcommerceInsights_Google_Ads::API_VERSION . '/' ) && $kdna_ei_dev === $kdna_ei_last['args']['headers']['developer-token'] && ! isset( $kdna_ei_last['args']['headers']['login-customer-id'] ) );

$kdna_ei_r = kdna_ei_t_call( 'POST', '/connections/google/sync' )->get_data();
kdna_ei_t( 'Sync follows the pages: 3 campaign days', 3 === ( $kdna_ei_r['result']['rows'] ?? 0 ), $kdna_ei_r );
kdna_ei_t( 'Cost in micros converted: 45.25 + 40 + 9.99 = 95.24', 95.24 === kdna_ei_t_spend( 'google', 'api' ), kdna_ei_t_spend( 'google', 'api' ) );
$kdna_ei_gv = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM( conversion_value ) FROM {$kdna_ei_table} WHERE channel = 'google' AND source = 'api' AND spend_date >= %s", kdna_ei_t_day( 7 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
kdna_ei_t( 'Conversion value saved: 832.50', 832.5 === round( $kdna_ei_gv, 2 ), $kdna_ei_gv );
kdna_ei_t( 'Meta spend is untouched by the Google sync', 250.5 === kdna_ei_t_spend( 'meta', 'api' ) );

kdna_ei_t_call( 'POST', '/connections/google', array( 'client_id' => '123-abc.apps.googleusercontent.com', 'customer_id' => '1234567890', 'login_customer_id' => '9876543210' ) );
kdna_ei_t_call( 'POST', '/connections/google/test' );
$kdna_ei_last = end( $GLOBALS['kdna_ei_t_log'] );
kdna_ei_t( 'A manager account ID is sent as login-customer-id', '9876543210' === ( $kdna_ei_last['args']['headers']['login-customer-id'] ?? '' ) );

$GLOBALS['kdna_ei_t_mode'] = array( 'google_dev_not_approved' => true );
$kdna_ei_r                 = kdna_ei_t_call( 'POST', '/connections/google/sync' )->get_data();
kdna_ei_t( 'Developer token without access is explained', false !== strpos( $kdna_ei_r['message'] ?? '', 'Explorer' ), $kdna_ei_r );
delete_transient( KDNA_EcommerceInsights_Google_Ads::ACCESS_CACHE );
$GLOBALS['kdna_ei_t_mode'] = array( 'google_revoked' => true );
$kdna_ei_r                 = kdna_ei_t_call( 'POST', '/connections/google/sync' )->get_data();
kdna_ei_t( 'Revoked sign-in is explained', false !== strpos( $kdna_ei_r['message'] ?? '', 'Sign in with Google' ), $kdna_ei_r );
kdna_ei_t( 'Google spend already synced is untouched', 95.24 === kdna_ei_t_spend( 'google', 'api' ) );
$GLOBALS['kdna_ei_t_mode'] = array();

echo "\n== Disconnecting ==\n";

$kdna_ei_r = kdna_ei_t_call( 'DELETE', '/connections/meta' )->get_data();
kdna_ei_t( 'Meta disconnected', 'not_set_up' === $kdna_ei_r['connection']['state'] && ! KDNA_EcommerceInsights_Crypto::has( KDNA_EcommerceInsights_Meta_Ads::TOKEN ) );
kdna_ei_t( 'Synced spend is kept', 250.5 === kdna_ei_t_spend( 'meta', 'api' ) );
kdna_ei_t( 'Its schedule is removed', ! as_has_scheduled_action( KDNA_EcommerceInsights_Ad_Sync::HOOK, array( 'meta' ), KDNA_EcommerceInsights_Order_Processor::GROUP ) );
$kdna_ei_r = kdna_ei_t_call( 'POST', '/adspend', array( 'start' => kdna_ei_t_day( 2 ), 'end' => kdna_ei_t_day( 2 ), 'channel' => 'meta', 'amount' => 10 ) );
kdna_ei_t( 'Manual Meta spend is allowed again', 200 === $kdna_ei_r->get_status() );
$kdna_ei_live = current( array_filter( KDNA_EcommerceInsights_Ad_Spend::entries( kdna_ei_t_day( 6 ), kdna_ei_t_day( 0 ) ), static fn( $e ) => 'api' === $e['source'] && 'meta' === $e['channel'] ) );
kdna_ei_t( 'Old synced spend can now be deleted', $kdna_ei_live && ! $kdna_ei_live['live'] && 200 === kdna_ei_t_call( 'DELETE', '/adspend/' . $kdna_ei_live['entry_group'] )->get_status() );

/*
 * ---------------------------------------------------------------------
 * Clean up
 * ---------------------------------------------------------------------
 */
KDNA_EcommerceInsights_Ad_Sync::disconnect( 'google' );
$wpdb->query( "DELETE FROM {$kdna_ei_table} WHERE channel IN ( 'meta', 'google', 'tiktok' ) AND spend_date >= '" . kdna_ei_t_day( 60 ) . "'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
KDNA_EcommerceInsights_Settings::update( array( 'marketing' => $kdna_ei_saved['marketing'] ) );
foreach ( array( KDNA_EcommerceInsights_Crypto::OPTION => 'secrets', KDNA_EcommerceInsights_Ad_Sync::STATUS_OPTION => 'status' ) as $kdna_ei_option => $kdna_ei_key ) {
	if ( null === $kdna_ei_saved[ $kdna_ei_key ] ) {
		delete_option( $kdna_ei_option );
	} else {
		update_option( $kdna_ei_option, $kdna_ei_saved[ $kdna_ei_key ], false );
	}
}
KDNA_EcommerceInsights_Ad_Sync::reschedule();
KDNA_EcommerceInsights_Cache::flush();

echo "\n" . $GLOBALS['kdna_ei_t_count'] . ' checks, ' . $GLOBALS['kdna_ei_t_fail'] . " failed\n";
