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
						'start'      => array(
							'type'    => 'string',
							'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
						),
						'end'        => array(
							'type'    => 'string',
							'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
						),
						'kpi_fifth'  => array(
							'type' => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Only Administrators with a valid REST nonce may use this route.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		return KDNA_EcommerceInsights_Rest_Report_Base::check_access( $request );
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

		foreach ( array( 'theme', 'focus', 'range', 'comparison', 'start', 'end', 'kpi_fifth' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}

		return rest_ensure_response( KDNA_EcommerceInsights_Preferences::update( $changes ) );
	}
}
