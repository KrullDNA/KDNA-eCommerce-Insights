<?php
/**
 * Short-term cache for report responses, and query timing for debug mode.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps report answers for 10 minutes so the dashboard is instant on
 * repeat views, and throws them all away the moment anything changes.
 *
 * Rather than finding and deleting every cached answer, each one is saved
 * under a "version" number. Changing data bumps the version, so old answers
 * are simply never asked for again and expire on their own.
 *
 * Also times database queries when debug mode is on (?kdna_ei_debug=1).
 */
class KDNA_EcommerceInsights_Cache {

	/**
	 * How long answers are kept.
	 */
	const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Option holding the current cache version.
	 */
	const VERSION_OPTION = 'kdna_ei_cache_version';

	/**
	 * Query timings collected during this request, in debug mode.
	 *
	 * @var array[]
	 */
	private static $timings = array();

	/**
	 * Whether debug timing is on for this request.
	 *
	 * @var bool
	 */
	private static $debug = false;

	/**
	 * Clears the cache whenever figures could have changed.
	 */
	public function __construct() {
		$events = array(
			'kdna_ei_order_processed',
			'kdna_ei_summary_rebuilt',
			'kdna_ei_overheads_changed',
			'kdna_ei_ad_spend_changed',
			'kdna_ei_settings_updated',
			'kdna_ei_cost_changed',
			'kdna_ei_job_complete',
		);
		foreach ( $events as $event ) {
			add_action( $event, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Throws away every cached answer by moving to a new version.
	 */
	public static function flush(): void {
		update_option( self::VERSION_OPTION, (string) microtime( true ), true );
	}

	/**
	 * Returns a cached answer, or works it out, saves it and returns it.
	 *
	 * @param string   $route    Route name, for example "summary".
	 * @param array    $args     Everything that changes the answer (range, metrics...).
	 * @param callable $callback Works out the answer when it is not cached.
	 * @param bool     $fresh    Skip the cache and work it out again.
	 * @return array{data: mixed, cached: bool}
	 */
	public static function remember( string $route, array $args, callable $callback, bool $fresh = false ): array {
		$key = 'kdna_ei_c_' . md5( $route . '|' . wp_json_encode( $args ) . '|' . get_option( self::VERSION_OPTION, '1' ) . '|' . get_current_user_id() );

		if ( ! $fresh && ! self::$debug ) {
			$hit = get_transient( $key );
			if ( false !== $hit ) {
				return array(
					'data'   => $hit,
					'cached' => true,
				);
			}
		}

		$data = call_user_func( $callback );
		set_transient( $key, $data, self::TTL );

		return array(
			'data'   => $data,
			'cached' => false,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Debug timing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Turns debug timing on or off for this request.
	 *
	 * @param bool $on Whether debug mode is on.
	 */
	public static function set_debug( bool $on ): void {
		self::$debug   = $on;
		self::$timings = array();
	}

	/**
	 * Whether debug timing is on.
	 *
	 * @return bool
	 */
	public static function debugging(): bool {
		return self::$debug;
	}

	/**
	 * Runs a database query, timing it in debug mode.
	 *
	 * @param string   $label    Plain-English name for the query.
	 * @param callable $callback Runs the query and returns its result.
	 * @return mixed The query result.
	 */
	public static function timed( string $label, callable $callback ) {
		if ( ! self::$debug ) {
			return call_user_func( $callback );
		}

		$start  = microtime( true );
		$result = call_user_func( $callback );

		self::$timings[] = array(
			'label' => $label,
			'ms'    => round( ( microtime( true ) - $start ) * 1000, 2 ),
			'rows'  => is_array( $result ) ? count( $result ) : 1,
		);

		return $result;
	}

	/**
	 * The query timings collected so far.
	 *
	 * @return array[]
	 */
	public static function timings(): array {
		return self::$timings;
	}
}
