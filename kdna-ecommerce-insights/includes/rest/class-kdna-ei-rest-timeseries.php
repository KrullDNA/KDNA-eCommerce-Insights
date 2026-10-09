<?php
/**
 * REST route: chart series over time.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /timeseries   Daily, weekly or monthly values for chosen metrics,
 *                     with the comparison period lined up bucket by bucket.
 *                     ?metrics=net_revenue,net_profit&granularity=auto|day|week|month
 */
class KDNA_EcommerceInsights_Rest_Timeseries extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/timeseries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_timeseries' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array_merge(
					$this->range_args(),
					array(
						'metrics'     => array( 'type' => 'string' ),
						'granularity' => array(
							'type'    => 'string',
							'enum'    => array( 'auto', 'day', 'week', 'month' ),
							'default' => 'auto',
						),
					)
				),
			)
		);
	}

	/**
	 * Returns the chart series.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_timeseries( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		$metrics     = $this->metric_list( $request['metrics'], array( 'net_revenue', 'net_profit', 'orders' ) );
		$granularity = 'auto' === $request['granularity'] ? KDNA_EcommerceInsights_Dates::auto_granularity( $range ) : (string) $request['granularity'];

		return $this->respond(
			$request,
			'timeseries',
			array(
				'metrics'     => $metrics,
				'granularity' => $granularity,
			),
			static function () use ( $range, $compare, $metrics, $granularity ) {
				$current  = KDNA_EcommerceInsights_Report::series( $range, $granularity, $metrics );
				$previous = $compare ? KDNA_EcommerceInsights_Report::series( $compare, $granularity, $metrics ) : null;

				$series = array();
				foreach ( $metrics as $key ) {
					$metric         = KDNA_EcommerceInsights_Metrics::get( $key );
					$series[ $key ] = array(
						'label'    => $metric['label'],
						'format'   => $metric['format'],
						'current'  => $current['series'][ $key ],
						'previous' => $previous ? $previous['series'][ $key ] : null,
					);
				}

				return array(
					'granularity'      => $granularity,
					'buckets'          => $current['buckets'],
					'previous_buckets' => $previous ? $previous['buckets'] : null,
					'series'           => $series,
				);
			},
			$range,
			$compare
		);
	}
}
