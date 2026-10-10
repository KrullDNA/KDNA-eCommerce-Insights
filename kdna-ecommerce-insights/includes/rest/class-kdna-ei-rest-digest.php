<?php
/**
 * REST routes: email digests.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes (Administrators only):
 *
 * - GET  /digest           Digest settings, next send and last send.
 * - POST /digest           Save frequency, recipients and sections.
 * - GET  /digest/preview   The digest email as HTML, for the preview window.
 * - POST /digest/test      Send a test digest now.
 */
class KDNA_EcommerceInsights_Rest_Digest extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$fields = array(
			'frequency'  => array(
				'type' => 'string',
				'enum' => array( 'off', 'weekly', 'monthly' ),
			),
			'recipients' => array( 'type' => 'string' ),
			'sections'   => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => array_keys( KDNA_EcommerceInsights_Digest::sections() ),
				),
			),
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/digest',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_digest' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $fields,
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/digest/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $fields,
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/digest/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $fields,
			)
		);
	}

	/**
	 * Digest settings and timing.
	 *
	 * @return WP_REST_Response
	 */
	public function get_digest(): WP_REST_Response {
		return rest_ensure_response( KDNA_EcommerceInsights_Digest::overview() );
	}

	/**
	 * Checks the recipients and sections, explaining any problem in plain
	 * English against its field.
	 *
	 * @param WP_REST_Request $request   Incoming request.
	 * @param bool            $need_all  Whether recipients and sections are required.
	 * @return array{0: string[], 1: string[], 2: array<string, string>} Recipients, sections, errors.
	 */
	private function check( WP_REST_Request $request, bool $need_all ): array {
		$errors = array();
		$list   = array_filter( array_map( 'trim', explode( ',', str_replace( array( ';', "\n" ), ',', (string) $request['recipients'] ) ) ) );
		$bad    = array_filter( $list, static fn( $email ) => ! is_email( $email ) );

		if ( $bad ) {
			/* translators: %s: the addresses that are not valid. */
			$errors['recipients'] = sprintf( __( 'These are not email addresses: %s. Separate addresses with commas.', 'kdna-ecommerce-insights' ), implode( ', ', $bad ) );
		} elseif ( $need_all && ! $list ) {
			$errors['recipients'] = __( 'Add at least one email address, for example you@yourstore.com.', 'kdna-ecommerce-insights' );
		}

		$sections = array_values( array_intersect( array_keys( KDNA_EcommerceInsights_Digest::sections() ), (array) $request['sections'] ) );
		if ( $need_all && ! $sections ) {
			$errors['sections'] = __( 'Choose at least one thing to include.', 'kdna-ecommerce-insights' );
		}

		return array( array_values( array_map( 'sanitize_email', $list ) ), $sections, $errors );
	}

	/**
	 * Turns field problems into a REST error.
	 *
	 * @param array<string, string> $errors Problems keyed by field.
	 * @return WP_Error
	 */
	private function invalid( array $errors ): WP_Error {
		return new WP_Error(
			'kdna_ei_invalid_digest',
			__( 'Please check the highlighted fields.', 'kdna-ecommerce-insights' ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/**
	 * Saves the digest settings and reschedules the next send.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$frequency = (string) ( $request['frequency'] ? $request['frequency'] : 'off' );
		list( $recipients, $sections, $errors ) = $this->check( $request, 'off' !== $frequency );
		if ( $errors ) {
			return $this->invalid( $errors );
		}

		KDNA_EcommerceInsights_Settings::update(
			array(
				'alerts' => array(
					'digest_frequency'  => $frequency,
					'digest_recipients' => implode( ', ', $recipients ),
					'digest_sections'   => $sections,
				),
			)
		);

		return rest_ensure_response( KDNA_EcommerceInsights_Digest::overview() );
	}

	/**
	 * The digest email for the last complete week or month, for the
	 * preview window.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response {
		$frequency = 'monthly' === $request['frequency'] ? 'monthly' : 'weekly';
		$sections  = null === $request['sections'] ? null : array_values( array_intersect( array_keys( KDNA_EcommerceInsights_Digest::sections() ), (array) $request['sections'] ) );
		$data      = KDNA_EcommerceInsights_Digest::data( $frequency, $sections, KDNA_EcommerceInsights_Digest::last_due( $frequency ) );

		return rest_ensure_response(
			array(
				'subject' => $data['subject'],
				'html'    => KDNA_EcommerceInsights_Digest::render( $data ),
				'dates'   => $data['dates'],
			)
		);
	}

	/**
	 * Sends a test digest to the addresses on screen (saved or not).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		list( $recipients, $sections, $errors ) = $this->check( $request, true );
		if ( $errors ) {
			return $this->invalid( $errors );
		}

		$frequency = 'monthly' === $request['frequency'] ? 'monthly' : 'weekly';
		$result    = KDNA_EcommerceInsights_Digest::send( $frequency, true, $recipients, $sections, KDNA_EcommerceInsights_Digest::last_due( $frequency ) );
		if ( ! $result['sent'] ) {
			return new WP_Error( 'kdna_ei_digest_not_sent', $result['message'], array( 'status' => 500 ) );
		}
		return rest_ensure_response( $result );
	}
}
