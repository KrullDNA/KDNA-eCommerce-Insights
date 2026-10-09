<?php
/**
 * REST routes for background jobs: history processing and recalculation.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the dashboard start, resume and cancel order processing jobs.
 *
 * Routes (Administrators only):
 * - POST   /jobs          Start a job: mode all, missing or after (with after = Y-m-d).
 * - POST   /jobs/resume   Requeue a running job that stalled.
 * - DELETE /jobs          Cancel the running job.
 */
class KDNA_EcommerceInsights_Rest_Jobs {

	/**
	 * Registers the job routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_job' ),
					'permission_callback' => $permission,
					'args'                => array(
						'mode'  => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'all', 'missing', 'after' ),
						),
						'after' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'cancel_job' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/jobs/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume_job' ),
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
	 * Starts a job and returns the full status.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_job( WP_REST_Request $request ) {
		$result = KDNA_EcommerceInsights_Backfill::start( (string) $request['mode'], array( 'after' => (string) $request['after'] ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}

		return rest_ensure_response( KDNA_EcommerceInsights_Rest_Status::data() );
	}

	/**
	 * Requeues a stalled job and returns the full status.
	 *
	 * @return WP_REST_Response
	 */
	public function resume_job(): WP_REST_Response {
		KDNA_EcommerceInsights_Backfill::ensure_running();
		return rest_ensure_response( KDNA_EcommerceInsights_Rest_Status::data() );
	}

	/**
	 * Cancels the running job and returns the full status.
	 *
	 * @return WP_REST_Response
	 */
	public function cancel_job(): WP_REST_Response {
		KDNA_EcommerceInsights_Backfill::cancel();
		return rest_ensure_response( KDNA_EcommerceInsights_Rest_Status::data() );
	}
}
