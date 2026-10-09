<?php
/**
 * REST routes for the overheads manager.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the Costs screen list, add, edit and delete overheads.
 *
 * Routes (Administrators only):
 * - GET    /overheads        Every overhead, plus totals for this month and this year.
 * - POST   /overheads        Add an overhead.
 * - POST   /overheads/{id}   Update an overhead.
 * - DELETE /overheads/{id}   Delete an overhead.
 */
class KDNA_EcommerceInsights_Rest_Overheads {

	/**
	 * Registers the overheads routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );
		$fields     = array(
			'name'         => array( 'type' => 'string' ),
			'category'     => array( 'type' => 'string' ),
			'amount'       => array( 'type' => array( 'number', 'string' ) ),
			'frequency'    => array( 'type' => 'string' ),
			'start_date'   => array( 'type' => 'string' ),
			'end_date'     => array( 'type' => array( 'string', 'null' ) ),
			'includes_gst' => array( 'type' => 'boolean' ),
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/overheads',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_overheads' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_overhead' ),
					'permission_callback' => $permission,
					'args'                => $fields,
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/overheads/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_overhead' ),
					'permission_callback' => $permission,
					'args'                => $fields,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_overhead' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * Only Administrators with a valid REST nonce may use these routes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		return KDNA_EcommerceInsights_Rest_Report_Base::check_access( $request );
	}

	/**
	 * Returns every overhead, with totals for this month and this year so the
	 * screen can show what overheads cost the business right now.
	 *
	 * @return WP_REST_Response
	 */
	public function list_overheads(): WP_REST_Response {
		$today = current_datetime();

		return rest_ensure_response(
			array(
				'overheads'  => KDNA_EcommerceInsights_Overheads::all(),
				'this_month' => KDNA_EcommerceInsights_Overheads::total_for_range( $today->format( 'Y-m-01' ), $today->format( 'Y-m-t' ) ),
				'this_year'  => KDNA_EcommerceInsights_Overheads::total_for_range( $today->format( 'Y-01-01' ), $today->format( 'Y-12-31' ) ),
				'categories' => KDNA_EcommerceInsights_Overheads::categories(),
			)
		);
	}

	/**
	 * Adds an overhead after checking it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_overhead( WP_REST_Request $request ) {
		$data   = $this->data_from( $request );
		$errors = KDNA_EcommerceInsights_Overheads::validate( $data );
		if ( $errors ) {
			return $this->invalid( $errors );
		}

		$saved = KDNA_EcommerceInsights_Overheads::create( $data );
		if ( ! $saved ) {
			return new WP_Error( 'kdna_ei_save_failed', __( 'The overhead could not be saved. Please try again.', 'kdna-ecommerce-insights' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $saved );
	}

	/**
	 * Updates an overhead after checking it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_overhead( WP_REST_Request $request ) {
		$data   = $this->data_from( $request );
		$errors = KDNA_EcommerceInsights_Overheads::validate( $data );
		if ( $errors ) {
			return $this->invalid( $errors );
		}

		$saved = KDNA_EcommerceInsights_Overheads::update( (int) $request['id'], $data );
		if ( ! $saved ) {
			return new WP_Error( 'kdna_ei_not_found', __( 'That overhead no longer exists.', 'kdna-ecommerce-insights' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $saved );
	}

	/**
	 * Deletes an overhead.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_overhead( WP_REST_Request $request ) {
		if ( ! KDNA_EcommerceInsights_Overheads::delete( (int) $request['id'] ) ) {
			return new WP_Error( 'kdna_ei_not_found', __( 'That overhead no longer exists.', 'kdna-ecommerce-insights' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Picks the overhead fields out of a request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	private function data_from( WP_REST_Request $request ): array {
		$data = array();
		foreach ( array( 'name', 'category', 'amount', 'frequency', 'start_date', 'end_date', 'includes_gst' ) as $key ) {
			$data[ $key ] = $request[ $key ];
		}
		return $data;
	}

	/**
	 * Builds the "please fix these" response listing each problem by field.
	 *
	 * @param array<string, string> $errors Problems keyed by field.
	 * @return WP_Error
	 */
	private function invalid( array $errors ): WP_Error {
		return new WP_Error(
			'kdna_ei_invalid_overhead',
			__( 'Please check the highlighted fields.', 'kdna-ecommerce-insights' ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}
}
