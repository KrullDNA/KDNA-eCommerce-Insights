<?php
/**
 * REST routes: live ad platform connections.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes (Administrators only). No response ever includes a token, secret
 * or developer token, only whether one is saved.
 *
 * - GET    /connections                       Status of Meta and Google Ads.
 * - POST   /connections/{platform}            Save settings and secrets.
 * - POST   /connections/{platform}/test       Test the connection.
 * - POST   /connections/{platform}/sync       Sync now (?days=7 to 365).
 * - DELETE /connections/{platform}            Disconnect (synced spend is kept).
 * - GET    /connections/google/auth-url       The Sign in with Google address.
 * - POST   /connections/rate                  Save the ad account currency rate.
 */
class KDNA_EcommerceInsights_Rest_Connections extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$platform = '(?P<platform>meta|google)';

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/connections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_connections' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/connections/' . $platform,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/connections/' . $platform . '/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/connections/' . $platform . '/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => KDNA_EcommerceInsights_Ad_Sync::WINDOW_DAYS,
						'minimum' => 1,
						'maximum' => 365,
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/connections/google/auth-url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'google_auth_url' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/connections/rate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_rate' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'rate'      => array( 'type' => array( 'number', 'string' ), 'required' => true ),
					'frequency' => array( 'type' => 'string', 'enum' => array( 'daily', 'twice_daily', 'manual' ) ),
				),
			)
		);
	}

	/**
	 * Both connections, plus anything to tell the owner after signing in
	 * with Google.
	 *
	 * @return WP_REST_Response
	 */
	public function list_connections(): WP_REST_Response {
		$notice_key = 'kdna_ei_google_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		delete_transient( $notice_key );

		return rest_ensure_response(
			array(
				'connections' => KDNA_EcommerceInsights_Ad_Sync::overview(),
				'rate'        => (float) KDNA_EcommerceInsights_Settings::get( 'marketing.ad_currency_rate', 1 ),
				'frequency'   => (string) KDNA_EcommerceInsights_Settings::get( 'marketing.sync_frequency', 'daily' ),
				'currency'    => (string) get_option( 'woocommerce_currency' ),
				'notice'      => $notice ? $notice : null,
			)
		);
	}

	/**
	 * Saves a platform's settings and secrets, then tests the connection
	 * when everything needed is there.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$key      = (string) $request['platform'];
		$platform = KDNA_EcommerceInsights_Ad_Sync::platform( $key );
		$errors   = $platform->save( $request->get_params() );

		if ( $errors ) {
			return new WP_Error(
				'kdna_ei_invalid_connection',
				__( 'Please check the highlighted fields.', 'kdna-ecommerce-insights' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		KDNA_EcommerceInsights_Ad_Sync::schedule();
		$test = $platform->configured() ? KDNA_EcommerceInsights_Ad_Sync::test( $key ) : null;

		return rest_ensure_response(
			array(
				'saved'      => true,
				'test'       => is_wp_error( $test ) ? array( 'ok' => false, 'message' => $test->get_error_message() ) : ( $test ? array( 'ok' => true ) : null ),
				'connection' => KDNA_EcommerceInsights_Ad_Sync::overview()[ $key ],
			)
		);
	}

	/**
	 * Tests a connection.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		$key    = (string) $request['platform'];
		$result = KDNA_EcommerceInsights_Ad_Sync::test( $key );
		if ( is_wp_error( $result ) ) {
			return $this->with_connection( $result, $key );
		}
		return rest_ensure_response(
			array(
				'account'    => $result,
				'connection' => KDNA_EcommerceInsights_Ad_Sync::overview()[ $key ],
			)
		);
	}

	/**
	 * Syncs now.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync( WP_REST_Request $request ) {
		$key    = (string) $request['platform'];
		$result = KDNA_EcommerceInsights_Ad_Sync::sync( $key, (int) $request['days'] );
		if ( is_wp_error( $result ) ) {
			return $this->with_connection( $result, $key );
		}
		return rest_ensure_response(
			array(
				'result'     => $result,
				'connection' => KDNA_EcommerceInsights_Ad_Sync::overview()[ $key ],
			)
		);
	}

	/**
	 * Adds the connection's updated status to an error, so the card can
	 * show it without another request.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $key   Platform key.
	 * @return WP_Error
	 */
	private function with_connection( WP_Error $error, string $key ): WP_Error {
		$data               = (array) $error->get_error_data();
		$data['status']     = 400;
		$data['connection'] = KDNA_EcommerceInsights_Ad_Sync::overview()[ $key ];
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Disconnects a platform.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function disconnect( WP_REST_Request $request ): WP_REST_Response {
		$key = (string) $request['platform'];
		KDNA_EcommerceInsights_Ad_Sync::disconnect( $key );
		return rest_ensure_response( array( 'connection' => KDNA_EcommerceInsights_Ad_Sync::overview()[ $key ] ) );
	}

	/**
	 * The Sign in with Google address.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function google_auth_url() {
		$url = KDNA_EcommerceInsights_Ad_Sync::platform( 'google' )->auth_url();
		return is_wp_error( $url ) ? $url : rest_ensure_response( array( 'url' => $url ) );
	}

	/**
	 * Saves the ad account currency rate and sync frequency.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_rate( WP_REST_Request $request ) {
		$rate = KDNA_EcommerceInsights_Csv_Import::parse_amount( (string) $request['rate'] );
		if ( null === $rate || $rate <= 0 ) {
			return new WP_Error( 'kdna_ei_invalid_rate', __( 'Enter a rate above zero, for example 1.52.', 'kdna-ecommerce-insights' ), array( 'status' => 400, 'fields' => array( 'rate' => __( 'Enter a rate above zero, for example 1.52.', 'kdna-ecommerce-insights' ) ) ) );
		}
		$changes = array( 'ad_currency_rate' => $rate );
		if ( $request['frequency'] ) {
			$changes['sync_frequency'] = (string) $request['frequency'];
		}
		KDNA_EcommerceInsights_Settings::update( array( 'marketing' => $changes ) );
		return $this->list_connections();
	}
}
