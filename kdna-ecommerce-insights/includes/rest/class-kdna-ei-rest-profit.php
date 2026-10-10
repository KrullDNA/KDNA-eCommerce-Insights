<?php
/**
 * REST route: profit and loss.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /profit   The profit waterfall from section 6, a monthly P&L
 *                 statement, the cost breakdown and margins, plus the
 *                 comparison period's waterfall.
 */
class KDNA_EcommerceInsights_Rest_Profit extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/profit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_profit' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->range_args(),
			)
		);
	}

	/**
	 * Returns profit and loss for the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profit( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		return $this->respond(
			$request,
			'profit',
			array(),
			static function () use ( $range, $compare ) {
				$data                       = KDNA_EcommerceInsights_Report::profit( $range );
				$data['previous_waterfall'] = $compare ? KDNA_EcommerceInsights_Report::waterfall( KDNA_EcommerceInsights_Report::totals( $compare ) ) : null;
				return $data;
			},
			$range,
			$compare
		);
	}
}
