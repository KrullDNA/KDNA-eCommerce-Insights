<?php
/**
 * REST routes: ad spend entries.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes (Administrators only):
 * - GET    /adspend           Entries overlapping the range.
 * - POST   /adspend           Add spend for a day or a date range, spread evenly by day.
 * - DELETE /adspend/{group}   Delete an entry (all its days).
 *
 * Stage 9 adds CSV import and the Marketing screen form on top of these.
 */
class KDNA_EcommerceInsights_Rest_Adspend extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_entries' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->range_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_entry' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'start'         => array( 'type' => 'string', 'required' => true ),
						'end'           => array( 'type' => 'string' ),
						'channel'       => array( 'type' => 'string', 'required' => true ),
						'campaign_name' => array( 'type' => 'string' ),
						'amount'        => array( 'type' => array( 'number', 'string' ), 'required' => true ),
						'includes_gst'  => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend/(?P<group>[a-f0-9\-]{36})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_entry' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Lists entries overlapping the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_entries( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range ) = $ranges;

		return rest_ensure_response(
			array(
				'data' => KDNA_EcommerceInsights_Ad_Spend::entries( $range['start'], $range['end'] ),
				'meta' => array( 'range' => $range ),
			)
		);
	}

	/**
	 * Adds an entry after checking it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_entry( WP_REST_Request $request ) {
		$data = array(
			'start'         => (string) $request['start'],
			'end'           => (string) $request['end'],
			'channel'       => (string) $request['channel'],
			'campaign_name' => (string) $request['campaign_name'],
			'amount'        => $request['amount'],
			'includes_gst'  => (bool) $request['includes_gst'],
		);

		$errors = KDNA_EcommerceInsights_Ad_Spend::validate( $data );
		if ( $errors ) {
			return new WP_Error(
				'kdna_ei_invalid_ad_spend',
				__( 'Please check the highlighted fields.', 'kdna-ecommerce-insights' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return rest_ensure_response( array( 'data' => array( 'entry_group' => KDNA_EcommerceInsights_Ad_Spend::create( $data ) ) ) );
	}

	/**
	 * Deletes an entry.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_entry( WP_REST_Request $request ) {
		if ( ! KDNA_EcommerceInsights_Ad_Spend::delete_group( (string) $request['group'] ) ) {
			return new WP_Error( 'kdna_ei_not_found', __( 'That ad spend entry no longer exists.', 'kdna-ecommerce-insights' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'data' => array( 'deleted' => true ) ) );
	}
}
