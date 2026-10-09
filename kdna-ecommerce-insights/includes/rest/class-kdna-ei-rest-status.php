<?php
/**
 * REST route reporting the plugin's current state.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells the dashboard what needs attention. Stage 2 reports missing product
 * costs. Later stages add background processing progress, sync times and alerts.
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
		return rest_ensure_response( self::data() );
	}

	/**
	 * Builds the status data. Also used to print the first status into the
	 * page, so the dashboard has it without an extra request.
	 *
	 * @return array
	 */
	public static function data(): array {
		return array(
			'missing_costs' => KDNA_EcommerceInsights_Cost_Catalogue::missing_count(),
			'cost_source'   => KDNA_EcommerceInsights_Costs::native_enabled() ? 'woocommerce' : 'insights',
		);
	}
}
