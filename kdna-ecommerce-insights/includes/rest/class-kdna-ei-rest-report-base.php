<?php
/**
 * Shared base for every Insights report route.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles what every report route has in common:
 *
 * - Access: Administrators only (manage_options), with a valid REST nonce.
 * - Date range: preset or custom start and end, plus the comparison range.
 * - Caching for 10 minutes per route, range and settings.
 * - Every response includes how reliable the figures are: orders with
 *   estimated fees or shipping, orders missing product costs, and orders in
 *   another currency without an exchange rate.
 * - Debug mode (?kdna_ei_debug=1) adds query timings.
 *
 * Responses look like: { data: {...}, meta: { range, compare, estimates, cached, generated_at, debug } }.
 */
abstract class KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers this controller's routes.
	 */
	abstract public function register_routes(): void;

	/*
	 * ---------------------------------------------------------------------
	 * Access
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Allows Administrators with a valid REST nonce. Requests signed in with
	 * a WordPress application password (for example from a reporting tool)
	 * do not use nonces and are allowed for Administrators too.
	 *
	 * @param WP_REST_Request|null $request Incoming request.
	 * @return bool|WP_Error
	 */
	public static function check_access( $request = null ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'kdna_ei_forbidden', __( 'Please log in as an administrator to see Insights.', 'kdna-ecommerce-insights' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'kdna_ei_forbidden', __( 'Sorry, only administrators can use Insights.', 'kdna-ecommerce-insights' ), array( 'status' => 403 ) );
		}

		if ( ! did_action( 'application_password_did_authenticate' ) ) {
			$nonce = $request instanceof WP_REST_Request ? ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) ) : '';
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error( 'kdna_ei_bad_nonce', __( 'Your session has expired. Please reload the page.', 'kdna-ecommerce-insights' ), array( 'status' => 401 ) );
			}
		}

		return true;
	}

	/**
	 * Permission callback used by every report route.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		return self::check_access( $request );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Arguments
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The date range arguments every report route accepts.
	 *
	 * @return array
	 */
	protected function range_args(): array {
		return array(
			'preset'        => array(
				'type' => 'string',
				'enum' => array_keys( KDNA_EcommerceInsights_Settings::range_presets() ),
			),
			'start'         => array(
				'type'    => 'string',
				'pattern' => '^\d{4}-\d{2}-\d{2}$',
			),
			'end'           => array(
				'type'    => 'string',
				'pattern' => '^\d{4}-\d{2}-\d{2}$',
			),
			'compare'       => array(
				'type' => 'string',
				'enum' => array_keys( KDNA_EcommerceInsights_Settings::comparison_modes() ),
			),
			'fresh'         => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'kdna_ei_debug' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Splits a comma separated list of metric keys, keeping only real metrics.
	 *
	 * @param mixed    $value    Submitted list.
	 * @param string[] $fallback Metrics to use when none are valid.
	 * @return string[]
	 */
	protected function metric_list( $value, array $fallback ): array {
		$keys = is_array( $value ) ? $value : explode( ',', (string) $value );
		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ), static fn( $key ) => null !== KDNA_EcommerceInsights_Metrics::get( $key ) ) ) );
		return $keys ? array_slice( $keys, 0, 20 ) : $fallback;
	}

	/**
	 * Works out the range and comparison range from a request, falling back
	 * to the administrator's saved date range and comparison.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array{0: array, 1: array|null}|WP_Error
	 */
	protected function ranges( WP_REST_Request $request ) {
		$prefs   = KDNA_EcommerceInsights_Preferences::get();
		$preset  = $request['preset'] ? (string) $request['preset'] : (string) $prefs['range'];
		$compare = $request['compare'] ? (string) $request['compare'] : (string) $prefs['comparison'];

		// A start and end date without a preset means a custom range.
		if ( ! $request['preset'] && $request['start'] && $request['end'] ) {
			$preset = 'custom';
		}
		$start = $request['start'] ? (string) $request['start'] : (string) $prefs['start'];
		$end   = $request['end'] ? (string) $request['end'] : (string) $prefs['end'];
		if ( 'custom' === $preset && ( ! $start || ! $end ) ) {
			$preset = 'this_month';
		}

		$range = KDNA_EcommerceInsights_Dates::resolve( $preset, $start, $end );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		return array( $range, KDNA_EcommerceInsights_Dates::comparison( $range, $compare ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Responding
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Builds a report response: works out (or fetches from cache) the data,
	 * adds the reliability details and, in debug mode, query timings.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $route   Route name for the cache.
	 * @param array           $args    Everything that changes the answer.
	 * @param callable        $build   Works out the data.
	 * @param array|null      $range   Range, for the meta and estimates.
	 * @param array|null      $compare Comparison range.
	 * @return WP_REST_Response
	 */
	protected function respond( WP_REST_Request $request, string $route, array $args, callable $build, ?array $range = null, ?array $compare = null ): WP_REST_Response {
		$debug = (bool) $request['kdna_ei_debug'] && current_user_can( 'manage_options' );
		KDNA_EcommerceInsights_Cache::set_debug( $debug );
		$started = microtime( true );

		$result = KDNA_EcommerceInsights_Cache::remember(
			$route,
			array_merge( $args, array( 'range' => $range, 'compare' => $compare ) ),
			static function () use ( $build, $range ) {
				return array(
					'data'      => call_user_func( $build ),
					'estimates' => $range ? KDNA_EcommerceInsights_Report::estimates( $range ) : null,
				);
			},
			(bool) $request['fresh']
		);

		$meta = array(
			'range'        => $range,
			'compare'      => $compare,
			'estimates'    => $result['data']['estimates'],
			'cached'       => $result['cached'],
			'generated_at' => gmdate( 'c' ),
			'currency'     => get_option( 'woocommerce_currency' ),
		);

		if ( $debug ) {
			$meta['debug'] = array(
				'route'    => $route,
				'total_ms' => round( ( microtime( true ) - $started ) * 1000, 2 ),
				'queries'  => KDNA_EcommerceInsights_Cache::timings(),
				'memory'   => size_format( memory_get_peak_usage() ),
			);
		}

		KDNA_EcommerceInsights_Cache::set_debug( false );

		return rest_ensure_response(
			array(
				'data' => $result['data']['data'],
				'meta' => $meta,
			)
		);
	}
}
