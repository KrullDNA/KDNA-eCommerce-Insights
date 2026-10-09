<?php
/**
 * REST route: customers.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /customers   New and returning customers, repeat purchase rate,
 *                    lifetime value, time between orders, top customers by
 *                    profit, monthly cohort retention and locations.
 */
class KDNA_EcommerceInsights_Rest_Customers extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customers' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->range_args(),
			)
		);
	}

	/**
	 * Returns customer figures for the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customers( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		return $this->respond(
			$request,
			'customers',
			array(),
			static fn() => KDNA_EcommerceInsights_Report::customers( $range, $compare ),
			$range,
			$compare
		);
	}
}
