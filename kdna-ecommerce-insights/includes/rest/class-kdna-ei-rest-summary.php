<?php
/**
 * REST routes: KPI summary and the metric list.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes (Administrators only):
 * - GET /summary   KPI values for a range, each with the comparison value,
 *                  the change and whether the change is good or bad news.
 *                  ?metrics=net_revenue,net_profit picks which metrics.
 * - GET /metrics   Every metric's label, format, help text and higher-is-better flag.
 */
class KDNA_EcommerceInsights_Rest_Summary extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * KPI strip metrics when none are asked for (section 8: net revenue, net
	 * profit, orders, margin, plus a fifth).
	 */
	const DEFAULT_METRICS = array( 'net_revenue', 'net_profit', 'orders', 'net_margin', 'average_order_value' );

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array_merge(
					$this->range_args(),
					array(
						'metrics' => array( 'type' => 'string' ),
					)
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/metrics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_metrics' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Returns KPI values for the range with comparison and change.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;
		$metrics                 = $this->metric_list( $request['metrics'], self::DEFAULT_METRICS );

		return $this->respond(
			$request,
			'summary',
			array( 'metrics' => $metrics ),
			static function () use ( $range, $compare, $metrics ) {
				$totals    = KDNA_EcommerceInsights_Report::totals( $range );
				$previous  = $compare ? KDNA_EcommerceInsights_Report::totals( $compare ) : null;
				$estimates = KDNA_EcommerceInsights_Report::estimates( $range );

				$list = array();
				foreach ( $metrics as $key ) {
					$list[] = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $previous, $estimates );
				}
				return array( 'metrics' => $list );
			},
			$range,
			$compare
		);
	}

	/**
	 * Returns every metric's description.
	 *
	 * @return WP_REST_Response
	 */
	public function get_metrics(): WP_REST_Response {
		return rest_ensure_response( array( 'data' => KDNA_EcommerceInsights_Metrics::describe() ) );
	}
}
