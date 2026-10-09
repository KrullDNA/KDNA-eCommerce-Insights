<?php
/**
 * REST route reporting the plugin's current state.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells the dashboard what needs attention: missing product costs and the
 * progress of order processing. Later stages add sync times and alerts.
 *
 * Route: GET /wp-json/kdna-ei/v1/status (Administrators only).
 */
class KDNA_EcommerceInsights_Rest_Status {

	/**
	 * Registers the status route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Only Administrators may read the status.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'kdna_ei_forbidden',
			__( 'Sorry, only administrators can use Insights.', 'kdna-ecommerce-insights' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Returns the current status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		// Checking the status also nudges a stalled job back into life.
		KDNA_EcommerceInsights_Backfill::ensure_running();
		return rest_ensure_response( self::data() );
	}

	/**
	 * Builds the status data. Also used to print the first status into the
	 * page, so the dashboard has it without an extra request.
	 *
	 * @return array
	 */
	public static function data(): array {
		global $wpdb;
		$facts = KDNA_EcommerceInsights_Install::table( 'order_facts' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_row( "SELECT COUNT(*) AS processed, SUM( missing_cost_flag ) AS missing, SUM( currency_flag ) AS currency FROM {$facts}", ARRAY_A );
		// phpcs:enable

		return array(
			'missing_costs'          => KDNA_EcommerceInsights_Cost_Catalogue::missing_count(),
			'cost_source'            => KDNA_EcommerceInsights_Costs::native_enabled() ? 'woocommerce' : 'insights',
			'job'                    => KDNA_EcommerceInsights_Backfill::state(),
			'orders_processed'       => (int) ( $counts['processed'] ?? 0 ),
			'orders_missing_costs'   => (int) ( $counts['missing'] ?? 0 ),
			'orders_currency_flag'   => (int) ( $counts['currency'] ?? 0 ),
			'order_storage'          => KDNA_EcommerceInsights_Backfill::uses_hpos() ? 'hpos' : 'legacy',
			'recent_log'             => KDNA_EcommerceInsights_Log::recent( 8 ),
		);
	}
}
