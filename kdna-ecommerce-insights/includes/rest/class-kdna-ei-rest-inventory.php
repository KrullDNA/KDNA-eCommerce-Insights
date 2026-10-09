<?php
/**
 * REST route: inventory.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /inventory   Stock status, stock value at cost and retail, low and
 *                    out of stock lists, days of stock left, dead stock and
 *                    the stock value trend. Stock is always "now", so the
 *                    date range only affects the reliability details.
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

		return $this->respond(
			$request,
			'inventory',
			array(),
			array( 'KDNA_EcommerceInsights_Report', 'inventory' ),
			$range,
			$compare
		);
	}
}
