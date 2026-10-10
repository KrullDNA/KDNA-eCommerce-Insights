<?php
/**
 * REST route: marketing.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /marketing   Ad spend by channel and campaign, ROAS per channel, MER,
 *                    cost per new customer and spend by day.
 */
class KDNA_EcommerceInsights_Rest_Marketing extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/marketing',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_marketing' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->range_args(),
			)
		);
	}

	/**
	 * Returns marketing figures for the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_marketing( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		return $this->respond(
			$request,
			'marketing',
			array(),
			static fn() => KDNA_EcommerceInsights_Report::marketing( $range, $compare ),
			$range,
			$compare
		);
	}
}
