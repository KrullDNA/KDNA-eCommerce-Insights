<?php
/**
 * REST route for per-user dashboard preferences.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the dashboard read and save the current administrator's theme,
 * Focus Mode, date range and comparison choice without reloading the page.
 *
 * Route: /wp-json/kdna-ei/v1/preferences
 */
class KDNA_EcommerceInsights_Rest_Preferences {

	/**
	 * Registers the GET and POST handlers for the preferences route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/preferences',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_preferences' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'theme'      => array(
							'type' => 'string',
							'enum' => array( 'dark', 'light' ),
						),
						'focus'      => array(
							'type' => 'boolean',
						),
						'range'      => array(
							'type' => 'string',
							'enum' => array_keys( KDNA_EcommerceInsights_Settings::range_presets() ),
						),
						'comparison' => array(
							'type' => 'string',
							'enum' => array_keys( KDNA_EcommerceInsights_Settings::comparison_modes() ),
						),
					),
				),
			)
		);
	}

	/**
	 * Only Administrators may use this route. The REST nonce sent by the
	 * dashboard is checked by WordPress before this runs; without it the
	 * request is treated as logged out and refused here.
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
	 * Returns the current user's preferences.
	 *
	 * @return WP_REST_Response
	 */
	public function get_preferences(): WP_REST_Response {
		return rest_ensure_response( KDNA_EcommerceInsights_Preferences::get() );
	}

	/**
	 * Saves whichever preferences were sent and returns the full set.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_preferences( WP_REST_Request $request ): WP_REST_Response {
		$changes = array();

		foreach ( array( 'theme', 'focus', 'range', 'comparison' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}

		return rest_ensure_response( KDNA_EcommerceInsights_Preferences::update( $changes ) );
	}
}
