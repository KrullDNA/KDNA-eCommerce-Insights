<?php
/**
 * Runs when KDNA eCommerce Insights is deleted from the Plugins screen.
 *
 * By default nothing is removed: tables, settings, cost prices and logs all
 * stay, so reinstalling picks up exactly where it left off. Only when
 * Settings > Data > "Delete all plugin data on uninstall" is switched on is
 * everything the plugin created removed:
 *
 * - its database tables (processed orders, summaries, ad spend, overheads,
 *   cost history, stock snapshots, refunds and the sync log)
 * - every kdna_ei_ option, including settings and encrypted connection keys
 * - its cached figures (transients)
 * - cost prices and shipping cost overrides saved by Insights on products
 *   and orders, and each person's dashboard preferences
 * - its Action Scheduler jobs and their history
 *
 * WooCommerce's own orders, products and its built-in cost field are never
 * touched. On a multisite network each site is checked separately, using
 * that site's own setting.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Short names of the plugin's tables. Kept in step with
 * KDNA_EcommerceInsights_Install::TABLES (tests/test-settings.php checks).
 */
const KDNA_EI_UNINSTALL_TABLES = array(
	'order_facts',
	'order_item_facts',
	'daily_summary',
	'ad_spend',
	'overheads',
	'cost_history',
	'stock_snapshots',
	'sync_log',
	'refund_facts',
);

/**
 * Whether the current site has asked for its data to be deleted.
 *
 * @return bool
 */
function kdna_ei_uninstall_wanted(): bool {
	$settings = get_option( 'kdna_ei_settings', array() );
	return is_array( $settings ) && ! empty( $settings['data']['delete_on_uninstall'] );
}

/**
 * Removes everything Insights created on the current site.
 */
function kdna_ei_uninstall_site(): void {
	global $wpdb;

	// Background jobs first, so nothing runs half way through.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'kdna-ei' );
	}
	$groups  = $wpdb->prefix . 'actionscheduler_groups';
	$actions = $wpdb->prefix . 'actionscheduler_actions';
	$logs    = $wpdb->prefix . 'actionscheduler_logs';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups ) ) === $groups ) {
		$group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$groups} WHERE slug = %s", 'kdna-ei' ) );
		if ( $group_id ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs ) ) === $logs ) {
				$wpdb->query( $wpdb->prepare( "DELETE l FROM {$logs} l INNER JOIN {$actions} a ON a.action_id = l.action_id WHERE a.group_id = %d", $group_id ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$actions} WHERE group_id = %d", $group_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$groups} WHERE group_id = %d", $group_id ) );
		}
	}

	// Tables.
	foreach ( KDNA_EI_UNINSTALL_TABLES as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'kdna_ei_' . $table );
	}

	// Options and cached figures.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'kdna_ei_' ) . '%', $wpdb->esc_like( '_transient_kdna_ei_' ) . '%', $wpdb->esc_like( '_transient_timeout_kdna_ei_' ) . '%' ) );

	// Cost prices and shipping overrides saved by Insights (WooCommerce's own cost field is left alone).
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_kdna_ei_' ) . '%' ) );
	$orders_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders_meta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_kdna_ei_' ) . '%' ) );
	}
	// phpcs:enable

	wp_cache_flush();
}

if ( is_multisite() ) {
	$kdna_ei_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $kdna_ei_site_ids as $kdna_ei_site_id ) {
		switch_to_blog( (int) $kdna_ei_site_id );
		if ( kdna_ei_uninstall_wanted() ) {
			kdna_ei_uninstall_site();
		}
		restore_current_blog();
	}
} elseif ( kdna_ei_uninstall_wanted() ) {
	kdna_ei_uninstall_site();
}

// Dashboard preferences are stored per person, shared by every site on a
// network, so they go only when every site asked for its data to be deleted.
if ( ! is_multisite() ? ! get_option( 'kdna_ei_settings' ) : ! array_filter(
	array_map(
		static function ( $site_id ) {
			return get_blog_option( (int) $site_id, 'kdna_ei_settings' );
		},
		get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		)
	)
) ) {
	delete_metadata( 'user', 0, 'kdna_ei_preferences', '', true );
}
