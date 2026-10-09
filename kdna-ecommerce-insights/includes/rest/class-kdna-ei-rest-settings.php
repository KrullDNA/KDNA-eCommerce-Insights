<?php
/**
 * REST routes for reading and saving Insights settings.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the dashboard read and save settings without a page reload.
 *
 * Routes (Administrators only):
 * - GET  /settings      Every setting.
 * - POST /settings      Save changes to one or more tabs, checked first.
 * - GET  /costs/setup   What the Costs screen needs to build its rule
 *                       tables: installed gateways and shipping methods.
 */
class KDNA_EcommerceInsights_Rest_Settings {

	/**
	 * Registers the settings routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $permission,
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/costs/setup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_costs_setup' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Only Administrators may use these routes.
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
	 * Returns every setting.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( KDNA_EcommerceInsights_Settings::all() );
	}

	/**
	 * Checks and saves changes. If anything needs fixing, nothing is saved and
	 * each problem is returned in plain English against its field.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ) {
		$changes = (array) $request['settings'];

		// Only known tabs can be changed.
		$changes = array_intersect_key( $changes, KDNA_EcommerceInsights_Settings::defaults() );

		$errors = KDNA_EcommerceInsights_Settings::validate( $changes );
		if ( $errors ) {
			return new WP_Error(
				'kdna_ei_invalid_settings',
				__( 'Some settings need fixing before they can be saved.', 'kdna-ecommerce-insights' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return rest_ensure_response( KDNA_EcommerceInsights_Settings::update( $changes ) );
	}

	/**
	 * Returns the payment gateways and shipping methods on this store, with
	 * the current cost rules, for the Costs screen.
	 *
	 * @return WP_REST_Response
	 */
	public function get_costs_setup(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'gateways'         => KDNA_EcommerceInsights_Fees::installed_gateways(),
				'shipping_methods' => KDNA_EcommerceInsights_Shipping::configured_methods(),
				'costs'            => KDNA_EcommerceInsights_Settings::get( 'costs' ),
				'weight_unit'      => get_option( 'woocommerce_weight_unit', 'kg' ),
			)
		);
	}
}
