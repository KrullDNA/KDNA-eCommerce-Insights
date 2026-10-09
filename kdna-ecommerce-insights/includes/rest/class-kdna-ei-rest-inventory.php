<?php
/**
 * REST route: inventory.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes (Administrators only):
 * - GET  /inventory              Stock status, stock value at cost and retail,
 *                                low and out of stock lists, days of stock
 *                                left, dead stock and the stock value trend.
 *                                Stock is always "now"; the date range sets
 *                                the dates of the trend.
 * - POST /inventory/test-alert   Sends a test low stock email.
 */
class KDNA_EcommerceInsights_Rest_Inventory extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/inventory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inventory' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->range_args(),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/inventory/test-alert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_test_alert' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Returns inventory figures.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_inventory( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		// A new install gets its first snapshot now, so the trend has a start.
		KDNA_EcommerceInsights_Inventory::ensure_snapshot();

		return $this->respond(
			$request,
			'inventory',
			array(),
			static fn() => KDNA_EcommerceInsights_Report::inventory( $range ),
			$range,
			$compare
		);
	}

	/**
	 * Sends a test low stock email to the alert recipients.
	 *
	 * @return WP_REST_Response
	 */
	public function send_test_alert(): WP_REST_Response {
		return rest_ensure_response( KDNA_EcommerceInsights_Inventory::send_alerts( true ) );
	}
}
