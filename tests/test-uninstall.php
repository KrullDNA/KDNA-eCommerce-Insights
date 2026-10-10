<?php
/**
 * Tests uninstall.php: by default everything is kept; with "Delete all
 * plugin data on uninstall" on, everything Insights created is removed and
 * WooCommerce's own data is left alone.
 *
 * DESTRUCTIVE in delete mode. Run on a throwaway copy of a test site only,
 * one mode per run (uninstall.php can only be loaded once per process):
 *
 *     KDNA_EI_UNINSTALL_MODE=keep wp eval-file tests/test-uninstall.php
 *     KDNA_EI_UNINSTALL_MODE=delete KDNA_EI_UNINSTALL_CONFIRM=yes wp eval-file tests/test-uninstall.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-uninstall.php\n" );

$kdna_ei_tu_mode = (string) getenv( 'KDNA_EI_UNINSTALL_MODE' );
if ( ! in_array( $kdna_ei_tu_mode, array( 'keep', 'delete' ), true ) || ( 'delete' === $kdna_ei_tu_mode && 'yes' !== getenv( 'KDNA_EI_UNINSTALL_CONFIRM' ) ) ) {
	exit( "Set KDNA_EI_UNINSTALL_MODE to keep, or to delete with KDNA_EI_UNINSTALL_CONFIRM=yes on a throwaway copy.\n" );
}

$GLOBALS['kdna_ei_tu_count'] = 0;
$GLOBALS['kdna_ei_tu_fail']  = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Shown on failure.
 */
function kdna_ei_tu( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_tu_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_tu_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : ' (' . ( is_scalar( $note ) ? $note : wp_json_encode( $note ) ) . ')' ) . "\n";
}

/**
 * How many plugin tables exist.
 *
 * @return int
 */
function kdna_ei_tu_tables(): int {
	global $wpdb;
	$found = 0;
	foreach ( KDNA_EcommerceInsights_Install::TABLES as $table ) {
		$name   = KDNA_EcommerceInsights_Install::table( $table );
		$found += $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ? 1 : 0; // phpcs:ignore WordPress.DB
	}
	return $found;
}

global $wpdb;
wp_set_current_user( 1 );

// Something of everything: a cost price, a WooCommerce native cost, a preference, a job and a transient.
$kdna_ei_tu_product = new WC_Product_Simple();
$kdna_ei_tu_product->set_name( 'Uninstall test product' );
$kdna_ei_tu_product->set_regular_price( '20' );
$kdna_ei_tu_product->save();
update_post_meta( $kdna_ei_tu_product->get_id(), '_kdna_ei_cost', '7.5' );
update_post_meta( $kdna_ei_tu_product->get_id(), '_cogs_total_value', '7' );
update_user_meta( 1, 'kdna_ei_preferences', array( 'theme' => 'light' ) );
set_transient( 'kdna_ei_c_uninstall_test', array( 1 ), HOUR_IN_SECONDS );
as_schedule_single_action( time() + DAY_IN_SECONDS, 'kdna_ei_uninstall_test', array(), 'kdna-ei' );
$kdna_ei_tu_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ); // phpcs:ignore WordPress.DB

KDNA_EcommerceInsights_Settings::update( array( 'data' => array( 'delete_on_uninstall' => 'delete' === $kdna_ei_tu_mode ) ) );
wp_cache_flush();

define( 'WP_UNINSTALL_PLUGIN', KDNA_EI_BASENAME );
include KDNA_EI_PATH . 'uninstall.php';
wp_cache_flush();

$kdna_ei_tu_options = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", 'kdna\_ei\_%' ) ); // phpcs:ignore WordPress.DB
$kdna_ei_tu_cost    = get_post_meta( $kdna_ei_tu_product->get_id(), '_kdna_ei_cost', true );
$kdna_ei_tu_native  = get_post_meta( $kdna_ei_tu_product->get_id(), '_cogs_total_value', true );
$kdna_ei_tu_prefs   = get_user_meta( 1, 'kdna_ei_preferences', true );
$kdna_ei_tu_jobs    = as_get_scheduled_actions( array( 'group' => 'kdna-ei', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );

if ( 'keep' === $kdna_ei_tu_mode ) {
	echo "Keep (the default)\n";
	kdna_ei_tu( 'Every table is kept', count( KDNA_EcommerceInsights_Install::TABLES ) === kdna_ei_tu_tables() );
	kdna_ei_tu( 'Settings are kept', is_array( get_option( 'kdna_ei_settings' ) ) && $kdna_ei_tu_options > 3 );
	kdna_ei_tu( 'Cost prices are kept', '7.5' === $kdna_ei_tu_cost );
	kdna_ei_tu( 'Preferences are kept', 'light' === ( $kdna_ei_tu_prefs['theme'] ?? '' ) );
	kdna_ei_tu( 'Scheduled jobs are kept', (bool) $kdna_ei_tu_jobs );
	as_unschedule_all_actions( 'kdna_ei_uninstall_test' );
	delete_transient( 'kdna_ei_c_uninstall_test' );
	delete_post_meta( $kdna_ei_tu_product->get_id(), '_kdna_ei_cost' );
	$kdna_ei_tu_product->delete( true );
	KDNA_EcommerceInsights_Settings::update( array( 'data' => array( 'delete_on_uninstall' => false ) ) );
} else {
	echo "Delete (switched on)\n";
	kdna_ei_tu( 'Every plugin table is dropped', 0 === kdna_ei_tu_tables(), kdna_ei_tu_tables() );
	kdna_ei_tu( 'Every kdna_ei_ option is removed, settings and keys included', 0 === $kdna_ei_tu_options, $kdna_ei_tu_options );
	kdna_ei_tu( 'Cached figures are removed', false === get_transient( 'kdna_ei_c_uninstall_test' ) );
	kdna_ei_tu( 'Cost prices saved by Insights are removed', '' === $kdna_ei_tu_cost );
	kdna_ei_tu( 'WooCommerce\'s own cost field is left alone', '7' === $kdna_ei_tu_native );
	kdna_ei_tu( 'Preferences are removed', '' === $kdna_ei_tu_prefs );
	kdna_ei_tu( 'Scheduled jobs are removed', ! $kdna_ei_tu_jobs );
	kdna_ei_tu( 'WooCommerce orders are untouched', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ) === $kdna_ei_tu_orders ); // phpcs:ignore WordPress.DB
	kdna_ei_tu( 'Products are untouched', (bool) wc_get_product( $kdna_ei_tu_product->get_id() ) );
}

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_tu_count'], $GLOBALS['kdna_ei_tu_fail'] );
