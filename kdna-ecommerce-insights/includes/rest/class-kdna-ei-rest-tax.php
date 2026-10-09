<?php
/**
 * REST route: tax.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /tax   GST or VAT by month or quarter, with BAS-style lines (G1,
 *              1A, 1B) for Australian stores. A guide, not a lodgement.
 */
class KDNA_EcommerceInsights_Rest_Tax extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/tax',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tax' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->range_args(),
			)
		);
	}

	/**
	 * Returns the tax summary for the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tax( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		return $this->respond(
			$request,
			'tax',
			array(),
			static fn() => KDNA_EcommerceInsights_Report::tax( $range ),
			$range,
			$compare
		);
	}
}
