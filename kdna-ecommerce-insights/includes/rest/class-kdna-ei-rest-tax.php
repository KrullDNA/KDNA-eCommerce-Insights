<?php
/**
 * REST route: tax.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /tax   GST, VAT or sales tax by month or quarter, with the BAS-style
 *              lines (G1, 1A, 1B) for Australian stores. A guide, not a
 *              lodgement. ?period=monthly|quarterly overrides Settings > Tax;
 *              ?span=last_period|this_fy|last_fy swaps the date range for
 *              the last full period or a financial year.
 */
class KDNA_EcommerceInsights_Rest_Tax extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/tax',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tax' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array_merge( $this->range_args(), self::tax_args() ),
			)
		);
	}

	/**
	 * The extra choices the tax summary takes.
	 *
	 * @return array
	 */
	public static function tax_args(): array {
		return array(
			'period' => array(
				'type' => 'string',
				'enum' => array( 'monthly', 'quarterly' ),
			),
			'span'   => array(
				'type'    => 'string',
				'enum'    => array_keys( KDNA_EcommerceInsights_Tax::spans() ),
				'default' => 'range',
			),
		);
	}

	/**
	 * Works out the period and dates the tax summary should use.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param array           $range   The screen's date range.
	 * @return array{0: array, 1: string}
	 */
	public static function tax_range( WP_REST_Request $request, array $range ): array {
		$period = (string) ( $request['period'] ? $request['period'] : KDNA_EcommerceInsights_Settings::get( 'tax.reporting_period', 'quarterly' ) );
		$system = (string) KDNA_EcommerceInsights_Settings::get( 'tax.system', 'none' );
		$span   = (string) ( $request['span'] ? $request['span'] : 'range' );
		return array( KDNA_EcommerceInsights_Tax::span_range( $span, $range, $period, $system ), $period );
	}

	/**
	 * Returns the tax summary for the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tax( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range ) = $ranges;
		list( $range, $period ) = self::tax_range( $request, $range );

		return $this->respond(
			$request,
			'tax',
			array(
				'period' => $period,
				'system' => KDNA_EcommerceInsights_Settings::get( 'tax' ),
			),
			static fn() => KDNA_EcommerceInsights_Tax::summary( $range, $period ),
			$range,
			null
		);
	}
}
