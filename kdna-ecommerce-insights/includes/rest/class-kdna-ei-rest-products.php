<?php
/**
 * REST route: product performance.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /products   Units, revenue, cost, profit, margin and refund rate per
 *                   product or variation, paginated and sortable, with best
 *                   and worst performers and a category breakdown.
 */
class KDNA_EcommerceInsights_Rest_Products extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array_merge( $this->range_args(), self::table_args() ),
			)
		);
	}

	/**
	 * Sorting, filtering and paging arguments for the product table.
	 *
	 * @return array
	 */
	public static function table_args(): array {
		return array(
			'group'     => array(
				'type'    => 'string',
				'enum'    => array( 'product', 'variation' ),
				'default' => 'product',
			),
			'orderby'   => array(
				'type'    => 'string',
				'enum'    => array( 'name', 'units', 'revenue', 'cost', 'profit', 'margin', 'refund_rate', 'orders' ),
				'default' => 'profit',
			),
			'order'     => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'search'    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'category'  => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'loss_only' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'page'      => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page'  => array(
				'type'    => 'integer',
				'default' => 25,
				'minimum' => 1,
				'maximum' => 200,
			),
		);
	}

	/**
	 * Returns product performance for the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_products( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		$args = array();
		foreach ( array_keys( self::table_args() ) as $key ) {
			$args[ $key ] = $request[ $key ];
		}

		return $this->respond(
			$request,
			'products',
			$args,
			static fn() => KDNA_EcommerceInsights_Report::products( $range, $args ),
			$range,
			$compare
		);
	}
}
