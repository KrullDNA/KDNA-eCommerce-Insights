<?php
/**
 * Tests for Stage 12: settings validation for every tab, cleaning, saving
 * one tab at a time, Reset to defaults, the settings context and sync log
 * routes, custom CSS rules, branding output, bundled fonts, sync failure
 * emails, and settings surviving deactivation and reactivation.
 *
 * Runs inside WordPress with WooCommerce and the plugin active, on a test
 * site only:
 *
 *     wp eval-file tests/test-settings.php
 *
 * Uninstall is tested separately in tests/test-uninstall.php, because it
 * deletes data.
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-settings.php\n" );

$GLOBALS['kdna_ei_ts_count'] = 0;
$GLOBALS['kdna_ei_ts_fail']  = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Shown on failure.
 */
function kdna_ei_ts( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_ts_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_ts_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : ' (' . ( is_scalar( $note ) ? $note : wp_json_encode( $note ) ) . ')' ) . "\n";
}

/**
 * Calls a plugin REST route as the current user with a valid nonce.
 *
 * @param string $method HTTP method.
 * @param string $route  Route after the namespace.
 * @param array  $params Parameters.
 * @return WP_REST_Response
 */
function kdna_ei_ts_rest( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/kdna-ei/v1' . $route );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}
	return rest_do_request( $request );
}

wp_set_current_user( 1 );
$kdna_ei_ts_saved = get_option( KDNA_EcommerceInsights_Settings::OPTION );
$kdna_ei_ts_v     = array( 'KDNA_EcommerceInsights_Settings', 'validate' );

/*
 * -------------------------------------------------------------------------
 * Validation, tab by tab
 * -------------------------------------------------------------------------
 */
echo "Validation\n";

$kdna_ei_ts_e = KDNA_EcommerceInsights_Settings::validate( array( 'general' => array( 'order_statuses' => array(), 'week_start' => 9, 'default_range' => 'custom', 'date_basis' => 'tomorrow' ) ) );
kdna_ei_ts( 'General: at least one counted status', isset( $kdna_ei_ts_e['general.order_statuses'] ) );
kdna_ei_ts( 'General: week start must be a day', isset( $kdna_ei_ts_e['general.week_start'] ) );
kdna_ei_ts( 'General: custom dates cannot be the default', false !== strpos( $kdna_ei_ts_e['general.default_range'] ?? '', 'Custom dates' ) );
kdna_ei_ts( 'General: unknown date basis refused', isset( $kdna_ei_ts_e['general.date_basis'] ) );
kdna_ei_ts( 'General: a good tab passes', array() === KDNA_EcommerceInsights_Settings::validate( array( 'general' => array( 'order_statuses' => array( 'processing', 'wc-completed' ), 'week_start' => '1', 'default_range' => 'last_30_days', 'date_basis' => 'paid' ) ) ) );
kdna_ei_ts( 'General: a status the store does not have is refused', isset( KDNA_EcommerceInsights_Settings::validate( array( 'general' => array( 'order_statuses' => array( 'made-up' ) ) ) )['general.order_statuses'] ) );

$kdna_ei_ts_e = KDNA_EcommerceInsights_Settings::validate( array( 'marketing' => array( 'channels' => array( array( 'key' => 'meta', 'label' => 'Meta' ), array( 'key' => '', 'label' => 'meta' ), array( 'key' => 'x', 'label' => '' ) ), 'ad_currency_rate' => '0', 'sync_frequency' => 'hourly' ) ) );
kdna_ei_ts( 'Marketing: duplicate channel names refused', isset( $kdna_ei_ts_e['marketing.channels.1.label'] ) );
kdna_ei_ts( 'Marketing: empty channel name refused', false !== strpos( $kdna_ei_ts_e['marketing.channels.2.label'] ?? '', 'name' ) );
kdna_ei_ts( 'Marketing: rate must be above zero', isset( $kdna_ei_ts_e['marketing.ad_currency_rate'] ) );
kdna_ei_ts( 'Marketing: unknown sync frequency refused', isset( $kdna_ei_ts_e['marketing.sync_frequency'] ) );

$kdna_ei_ts_e = KDNA_EcommerceInsights_Settings::validate( array( 'tax' => array( 'system' => 'gst', 'rate' => 120 ) ) );
kdna_ei_ts( 'Tax: unknown system and a rate over 100 refused', isset( $kdna_ei_ts_e['tax.system'], $kdna_ei_ts_e['tax.rate'] ) );

$kdna_ei_ts_e = KDNA_EcommerceInsights_Settings::validate(
	array(
		'branding' => array(
			'store_name' => str_repeat( 'a', 61 ),
			'logo_id'    => 999999,
			'font'       => 'comic-sans',
			'colours'    => array( 'light' => array( 'accent' => 'pink', 'negative' => '#D64545' ) ),
			'custom_css' => '</style><script>alert(1)</script>',
		),
	)
);
kdna_ei_ts( 'Branding: name over 60 characters refused', isset( $kdna_ei_ts_e['branding.store_name'] ) );
kdna_ei_ts( 'Branding: logo must be a Media Library image', isset( $kdna_ei_ts_e['branding.logo_id'] ) );
kdna_ei_ts( 'Branding: unknown font refused', isset( $kdna_ei_ts_e['branding.font'] ) );
kdna_ei_ts( 'Branding: a colour must be a colour code', isset( $kdna_ei_ts_e['branding.colours.light.accent'] ) && ! isset( $kdna_ei_ts_e['branding.colours.light.negative'] ) );
kdna_ei_ts( 'Branding: HTML in custom CSS refused', isset( $kdna_ei_ts_e['branding.custom_css'] ) );

$kdna_ei_ts_e = KDNA_EcommerceInsights_Settings::validate( array( 'hero' => array( 'type' => 'goals', 'goal_targets' => array( 'revenue' => '-5', 'orders' => '1.5' ) ) ) );
kdna_ei_ts( 'Goals: negative revenue target refused', isset( $kdna_ei_ts_e['hero.goal_targets.revenue'] ) );
kdna_ei_ts( 'Goals: orders target must be whole', isset( $kdna_ei_ts_e['hero.goal_targets.orders'] ) );

$kdna_ei_ts_e = KDNA_EcommerceInsights_Settings::validate( array( 'alerts' => array( 'low_stock_threshold' => '-1', 'dead_stock_days' => '0', 'reorder_lead_days' => '400', 'alert_recipients' => '', 'low_stock_emails' => true, 'digest_frequency' => 'monthly', 'digest_recipients' => 'a@example.com; nope', 'digest_sections' => array() ) ) );
kdna_ei_ts( 'Alerts: threshold, dead stock days and lead time checked', isset( $kdna_ei_ts_e['alerts.low_stock_threshold'], $kdna_ei_ts_e['alerts.dead_stock_days'], $kdna_ei_ts_e['alerts.reorder_lead_days'] ) );
kdna_ei_ts( 'Alerts: switching on emails needs an address', false !== strpos( $kdna_ei_ts_e['alerts.alert_recipients'] ?? '', 'at least one' ) );
kdna_ei_ts( 'Alerts: the bad address is named', false !== strpos( $kdna_ei_ts_e['alerts.digest_recipients'] ?? '', 'nope' ) );
kdna_ei_ts( 'Alerts: a digest needs at least one section', isset( $kdna_ei_ts_e['alerts.digest_sections'] ) );

$kdna_ei_ts_all = '';
foreach ( array( 'general' => array( 'order_statuses' => array() ), 'alerts' => array( 'dead_stock_days' => 0, 'low_stock_threshold' => 'x' ), 'branding' => array( 'custom_css' => '<b>' ) ) as $kdna_ei_ts_tab => $kdna_ei_ts_input ) {
	$kdna_ei_ts_all .= implode( ' ', KDNA_EcommerceInsights_Settings::validate( array( $kdna_ei_ts_tab => $kdna_ei_ts_input ) ) );
}
kdna_ei_ts( 'No em dash in any message', false === strpos( $kdna_ei_ts_all, "\u{2014}" ) );

/*
 * -------------------------------------------------------------------------
 * Saving, cleaning and resetting
 * -------------------------------------------------------------------------
 */
echo "\nSaving\n";

$kdna_ei_ts_res = kdna_ei_ts_rest( 'POST', '/settings', array( 'settings' => array( 'alerts' => array( 'dead_stock_days' => 0 ) ) ) );
kdna_ei_ts( 'A bad value is refused with its field, nothing saved', 400 === $kdna_ei_ts_res->get_status() && isset( $kdna_ei_ts_res->get_data()['data']['fields']['alerts.dead_stock_days'] ) && (int) KDNA_EcommerceInsights_Settings::get( 'alerts.dead_stock_days' ) > 0 );

$kdna_ei_ts_before = KDNA_EcommerceInsights_Settings::get( 'general' );
$kdna_ei_ts_res    = kdna_ei_ts_rest( 'POST', '/settings', array( 'settings' => array( 'tax' => array( 'system' => 'vat', 'rate' => '20', 'reporting_period' => 'monthly' ) ) ) );
kdna_ei_ts( 'Saving one tab works', 200 === $kdna_ei_ts_res->get_status() && 'vat' === KDNA_EcommerceInsights_Settings::get( 'tax.system' ) && 20.0 === (float) KDNA_EcommerceInsights_Settings::get( 'tax.rate' ) );
kdna_ei_ts( 'Saving one tab leaves the others alone', KDNA_EcommerceInsights_Settings::get( 'general' ) === $kdna_ei_ts_before );

KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'alert_recipients' => 'one@example.com; two@example.com, not-email' ) ) );
kdna_ei_ts( 'Semicolons accepted between addresses and junk dropped', 'one@example.com, two@example.com' === KDNA_EcommerceInsights_Settings::get( 'alerts.alert_recipients' ), KDNA_EcommerceInsights_Settings::get( 'alerts.alert_recipients' ) );

$kdna_ei_ts_css = KDNA_EcommerceInsights_Settings::clean_css( ".a{color:red}</style><script>x</script>\n.b{width:expression(alert(1));background:url(javascript:alert(1))}" );
kdna_ei_ts( 'Custom CSS cannot close the style block or run script', false === strpos( $kdna_ei_ts_css, '<' ) && false === stripos( $kdna_ei_ts_css, 'expression(' ) && false === stripos( $kdna_ei_ts_css, 'javascript:' ) && false !== strpos( $kdna_ei_ts_css, '.a{color:red}' ), $kdna_ei_ts_css );

// Without unfiltered_html, custom CSS cannot be changed.
add_filter( 'map_meta_cap', $kdna_ei_ts_deny = static fn( $caps, $cap ) => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps, 10, 2 );
$kdna_ei_ts_res = kdna_ei_ts_rest( 'POST', '/settings', array( 'settings' => array( 'branding' => array( 'custom_css' => '.x{}' ) ) ) );
kdna_ei_ts( 'Custom CSS needs the unfiltered HTML permission', 400 === $kdna_ei_ts_res->get_status() && isset( $kdna_ei_ts_res->get_data()['data']['fields']['branding.custom_css'] ) );
$kdna_ei_ts_res = kdna_ei_ts_rest( 'GET', '/settings/context' );
kdna_ei_ts( 'The screen is told custom CSS is locked', false === $kdna_ei_ts_res->get_data()['can_edit_css'] );
remove_filter( 'map_meta_cap', $kdna_ei_ts_deny, 10 );
$kdna_ei_ts_res = kdna_ei_ts_rest( 'POST', '/settings', array( 'settings' => array( 'branding' => array( 'custom_css' => '.kdna-ei-root{--kdna-ei-radius:12px}' ) ) ) );
kdna_ei_ts( 'An administrator can save custom CSS', 200 === $kdna_ei_ts_res->get_status() && false !== strpos( KDNA_EcommerceInsights_Admin::custom_css(), '--kdna-ei-radius:12px' ) );

// Branding output.
KDNA_EcommerceInsights_Settings::update(
	array(
		'branding' => array(
			'font'    => 'manrope',
			'colours' => array( 'dark' => array( 'accent' => '#e13172' ) ),
		),
	)
);
$kdna_ei_ts_brand = KDNA_EcommerceInsights_Admin::branding_css();
kdna_ei_ts( 'Brand CSS carries the new accent for dark mode only', false !== strpos( $kdna_ei_ts_brand, '.kdna-ei-root{--kdna-ei-accent:#E13172;}' ) && false === strpos( $kdna_ei_ts_brand, 'light"]{--kdna-ei-accent' ), $kdna_ei_ts_brand );
kdna_ei_ts( 'Brand CSS switches the font', false !== strpos( $kdna_ei_ts_brand, '"Manrope"' ) );
kdna_ei_ts( 'Emails use the light accent, not the dark one', '#5A6FE0' === strtoupper( KDNA_EcommerceInsights_Digest::brand()['accent'] ) );
$kdna_ei_ts_events = 0;
add_action( 'kdna_ei_settings_updated', $kdna_ei_ts_count = static function () use ( &$kdna_ei_ts_events ) {
	++$kdna_ei_ts_events;
} );
$kdna_ei_ts_res = kdna_ei_ts_rest( 'POST', '/settings/reset', array( 'tab' => 'branding' ) );
kdna_ei_ts( 'Reset to defaults puts Branding back', 200 === $kdna_ei_ts_res->get_status() && 'figtree' === KDNA_EcommerceInsights_Settings::get( 'branding.font' ) && '#7188EE' === KDNA_EcommerceInsights_Settings::get( 'branding.colours.dark.accent' ) && '' === KDNA_EcommerceInsights_Settings::get( 'branding.custom_css' ) );
kdna_ei_ts( 'Reset tells the rest of the plugin (cache, schedules)', 1 === $kdna_ei_ts_events );
kdna_ei_ts( 'After reset the brand CSS is empty', '' === KDNA_EcommerceInsights_Admin::branding_css() );
kdna_ei_ts( 'Reset leaves other tabs alone', 'vat' === KDNA_EcommerceInsights_Settings::get( 'tax.system' ) );
$kdna_ei_ts_res = kdna_ei_ts_rest( 'POST', '/settings/reset', array( 'tab' => 'costs' ) );
kdna_ei_ts( 'Costs cannot be reset from here (it would wipe the rules)', 400 === $kdna_ei_ts_res->get_status() );
remove_action( 'kdna_ei_settings_updated', $kdna_ei_ts_count );

/*
 * -------------------------------------------------------------------------
 * Context, log, fonts
 * -------------------------------------------------------------------------
 */
echo "\nContext and log\n";

$kdna_ei_ts_ctx = kdna_ei_ts_rest( 'GET', '/settings/context' )->get_data();
kdna_ei_ts( 'Context lists the store order statuses without wc-', in_array( 'processing', wp_list_pluck( $kdna_ei_ts_ctx['order_statuses'], 'key' ), true ) );
kdna_ei_ts( 'Context lists seven weekdays', 7 === count( $kdna_ei_ts_ctx['weekdays'] ) );
kdna_ei_ts( 'Context never includes a connection secret', ! preg_match( '/EAAG|secret-value|refresh_token|developer_token/', wp_json_encode( $kdna_ei_ts_ctx ) ) );
kdna_ei_ts( 'Context counts cost rules', isset( $kdna_ei_ts_ctx['costs']['gateway_rules'], $kdna_ei_ts_ctx['costs']['missing_costs'] ) );

for ( $kdna_ei_ts_i = 0; $kdna_ei_ts_i < 3; $kdna_ei_ts_i++ ) {
	KDNA_EcommerceInsights_Log::add( 'settings_test', 0 === $kdna_ei_ts_i ? 'error' : 'success', 'Settings test entry ' . $kdna_ei_ts_i );
}
$kdna_ei_ts_log = kdna_ei_ts_rest( 'GET', '/log', array( 'type' => 'settings_test', 'per_page' => 5 ) )->get_data();
kdna_ei_ts( 'Log filters by type', 3 === $kdna_ei_ts_log['total'] && 'Settings test entry 2' === $kdna_ei_ts_log['rows'][0]['message'], $kdna_ei_ts_log['total'] );
$kdna_ei_ts_log = kdna_ei_ts_rest( 'GET', '/log', array( 'type' => 'settings_test', 'status' => 'error' ) )->get_data();
kdna_ei_ts( 'Log filters by result', 1 === $kdna_ei_ts_log['total'] );
$kdna_ei_ts_log = kdna_ei_ts_rest( 'GET', '/log', array( 'per_page' => 5, 'page' => 2 ) )->get_data();
kdna_ei_ts( 'Log pages', 2 === $kdna_ei_ts_log['page'] && $kdna_ei_ts_log['pages'] >= 1 && count( $kdna_ei_ts_log['rows'] ) <= 5 );
kdna_ei_ts( 'Log names the kinds of entry in plain English', 'Meta sync' === KDNA_EcommerceInsights_Log::type_labels()['sync_meta'] );
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . KDNA_EcommerceInsights_Install::table( 'sync_log' ) . ' WHERE type = %s', 'settings_test' ) ); // phpcs:ignore WordPress.DB

$kdna_ei_ts_missing = array();
foreach ( array( 'figtree', 'inter', 'montserrat', 'dm-sans', 'manrope' ) as $kdna_ei_ts_font ) {
	foreach ( array( 300, 400, 500, 600 ) as $kdna_ei_ts_weight ) {
		if ( ! is_readable( KDNA_EI_PATH . "assets/fonts/{$kdna_ei_ts_font}/{$kdna_ei_ts_font}-latin-{$kdna_ei_ts_weight}-normal.woff2" ) ) {
			$kdna_ei_ts_missing[] = "$kdna_ei_ts_font $kdna_ei_ts_weight";
		}
	}
	if ( ! is_readable( KDNA_EI_PATH . "assets/fonts/{$kdna_ei_ts_font}/OFL.txt" ) ) {
		$kdna_ei_ts_missing[] = "$kdna_ei_ts_font licence";
	}
}
kdna_ei_ts( 'All five fonts are bundled with their licences', ! $kdna_ei_ts_missing, $kdna_ei_ts_missing );
kdna_ei_ts( 'The font stylesheet declares every bundled font', 20 === substr_count( (string) file_get_contents( KDNA_EI_PATH . 'assets/css/kdna-ei-fonts.css' ), '@font-face' ) );

/*
 * -------------------------------------------------------------------------
 * Sync failure emails
 * -------------------------------------------------------------------------
 */
echo "\nSync failure emails\n";

$GLOBALS['kdna_ei_ts_mail'] = array();
add_filter(
	'pre_wp_mail',
	$kdna_ei_ts_mailer = static function ( $short, $atts ) {
		$GLOBALS['kdna_ei_ts_mail'][] = $atts;
		return true;
	},
	1,
	2
);
KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'sync_failure_emails' => false, 'alert_recipients' => 'owner@example.com' ) ) );
kdna_ei_ts( 'No email while sync failure emails are off', false === KDNA_EcommerceInsights_Ad_Sync::email_failure( 'meta', new WP_Error( 'x', 'Token expired.' ) ) && ! $GLOBALS['kdna_ei_ts_mail'] );
KDNA_EcommerceInsights_Settings::update( array( 'alerts' => array( 'sync_failure_emails' => true ) ) );
kdna_ei_ts( 'Email sent when switched on', true === KDNA_EcommerceInsights_Ad_Sync::email_failure( 'meta', new WP_Error( 'x', 'Token expired.' ) ) && 1 === count( $GLOBALS['kdna_ei_ts_mail'] ) );
kdna_ei_ts( 'The email names the platform and the reason', false !== strpos( $GLOBALS['kdna_ei_ts_mail'][0]['subject'], 'Meta' ) && false !== strpos( $GLOBALS['kdna_ei_ts_mail'][0]['message'], 'Token expired.' ) );

// run() only emails when a working connection starts failing.
$kdna_ei_ts_status = get_option( KDNA_EcommerceInsights_Ad_Sync::STATUS_OPTION );
update_option( KDNA_EcommerceInsights_Ad_Sync::STATUS_OPTION, array( 'meta' => array( 'state' => 'error', 'message' => 'Still broken' ) ) );
$GLOBALS['kdna_ei_ts_mail'] = array();
KDNA_EcommerceInsights_Ad_Sync::run( 'meta' );
kdna_ei_ts( 'No repeat email while it is still failing', ! $GLOBALS['kdna_ei_ts_mail'] );
update_option( KDNA_EcommerceInsights_Ad_Sync::STATUS_OPTION, $kdna_ei_ts_status );
remove_filter( 'pre_wp_mail', $kdna_ei_ts_mailer, 1 );

/*
 * -------------------------------------------------------------------------
 * Deactivate and reactivate
 * -------------------------------------------------------------------------
 */
echo "\nDeactivate and reactivate\n";

KDNA_EcommerceInsights_Settings::update( array( 'branding' => array( 'store_name' => 'Kept Through Reactivation' ), 'data' => array( 'delete_on_uninstall' => true ) ) );
$kdna_ei_ts_snapshot = get_option( KDNA_EcommerceInsights_Settings::OPTION );
KDNA_EcommerceInsights_Install::deactivate();
KDNA_EcommerceInsights_Install::activate();
kdna_ei_ts( 'Every setting survives deactivation and reactivation', get_option( KDNA_EcommerceInsights_Settings::OPTION ) === $kdna_ei_ts_snapshot );
kdna_ei_ts( 'Tables survive deactivation', (bool) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . KDNA_EcommerceInsights_Install::table( 'order_facts' ) ) ); // phpcs:ignore WordPress.DB
delete_option( KDNA_EcommerceInsights_Backfill::PENDING_OPTION );

// uninstall.php keeps its own copy of the table list: it must match.
$kdna_ei_ts_src = (string) file_get_contents( KDNA_EI_PATH . 'uninstall.php' );
$kdna_ei_ts_ok  = true;
foreach ( KDNA_EcommerceInsights_Install::TABLES as $kdna_ei_ts_table ) {
	$kdna_ei_ts_ok = $kdna_ei_ts_ok && false !== strpos( $kdna_ei_ts_src, "'" . $kdna_ei_ts_table . "'" );
}
kdna_ei_ts( 'uninstall.php knows every plugin table', $kdna_ei_ts_ok && substr_count( $kdna_ei_ts_src, "\t'" ) >= count( KDNA_EcommerceInsights_Install::TABLES ) );

// Tidy up.
update_option( KDNA_EcommerceInsights_Settings::OPTION, $kdna_ei_ts_saved );
KDNA_EcommerceInsights_Cache::flush();

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_ts_count'], $GLOBALS['kdna_ei_ts_fail'] );
