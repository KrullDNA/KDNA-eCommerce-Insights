<?php
/**
 * Background processing of past orders: the first history backfill and the
 * recalculation tools.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Works through many orders in the background, 100 at a time, with a
 * progress record the dashboard shows as a progress bar.
 *
 * One job runs at a time. Jobs are resumable: progress is saved after every
 * batch, and if a batch is interrupted (a server timeout, a deploy) the next
 * page load in wp-admin or the nightly check carries on from where it stopped.
 *
 * Job types:
 * - all      Every order (the first backfill, and "Recalculate all orders").
 * - missing  Only orders that had products without a cost.
 * - after    Orders placed on or after a chosen date.
 */
class KDNA_EcommerceInsights_Backfill {

	/**
	 * Option holding the current job's progress.
	 */
	const OPTION = 'kdna_ei_job';

	/**
	 * Option set on activation, asking for the first backfill to start.
	 */
	const PENDING_OPTION = 'kdna_ei_backfill_pending';

	/**
	 * Background job that processes one batch.
	 */
	const BATCH_HOOK = 'kdna_ei_backfill_batch';

	/**
	 * Orders per batch.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Short-lived lock so two batches never run at the same time.
	 */
	const LOCK = 'kdna_ei_job_lock';

	/**
	 * Connects the batch job and the start-up check to WordPress.
	 */
	public function __construct() {
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'init', array( __CLASS__, 'maybe_start_pending' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_resume' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Job state
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns the current or last job's progress, with a percentage.
	 *
	 * @return array{mode: string, after: string, status: string, total: int, processed: int, errors: int, last_id: int, started_at: string, finished_at: string, percent: int}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'mode'        => '',
				'after'       => '',
				'status'      => 'idle',
				'total'       => 0,
				'processed'   => 0,
				'errors'      => 0,
				'last_id'     => 0,
				'started_at'  => '',
				'finished_at' => '',
			)
		);

		$state['percent'] = $state['total'] > 0 ? (int) min( 100, floor( $state['processed'] / $state['total'] * 100 ) ) : ( 'complete' === $state['status'] ? 100 : 0 );

		return $state;
	}

	/**
	 * Saves the job's progress.
	 *
	 * @param array $state Job state.
	 */
	private static function save( array $state ): void {
		unset( $state['percent'] );
		update_option( self::OPTION, $state, false );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Starting, resuming and cancelling
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Starts a job.
	 *
	 * @param string $mode  all, missing or after.
	 * @param array  $args  For "after": array( 'after' => 'Y-m-d' ).
	 * @param bool   $force Replace a job that is already running.
	 * @return array|WP_Error The new job state.
	 */
	public static function start( string $mode, array $args = array(), bool $force = false ) {
		if ( ! in_array( $mode, array( 'all', 'missing', 'after' ), true ) ) {
			return new WP_Error( 'kdna_ei_bad_mode', __( 'That recalculation type is not recognised.', 'kdna-ecommerce-insights' ) );
		}

		$after = '';
		if ( 'after' === $mode ) {
			$after = (string) ( $args['after'] ?? '' );
			$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $after );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $after ) {
				return new WP_Error( 'kdna_ei_bad_date', __( 'Choose a valid date to recalculate from.', 'kdna-ecommerce-insights' ) );
			}
		}

		$current = self::state();
		if ( 'running' === $current['status'] && ! $force ) {
			return new WP_Error( 'kdna_ei_job_running', __( 'Insights is already working through your orders. Please wait for it to finish, then try again.', 'kdna-ecommerce-insights' ) );
		}

		self::unschedule();

		$state = array(
			'mode'        => $mode,
			'after'       => $after,
			'status'      => 'running',
			'total'       => self::count( $mode, $after ),
			'processed'   => 0,
			'errors'      => 0,
			'last_id'     => 0,
			'started_at'  => current_time( 'mysql', true ),
			'finished_at' => '',
		);

		// Nothing to work through (a brand new store): finished straight away.
		if ( 0 === (int) $state['total'] ) {
			self::finish( $state );
			return self::state();
		}

		self::save( $state );
		self::schedule_next();

		return self::state();
	}

	/**
	 * Starts the first history backfill once WooCommerce's job queue is
	 * ready, if activation asked for it.
	 */
	public static function maybe_start_pending(): void {
		if ( ! get_option( self::PENDING_OPTION ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		delete_option( self::PENDING_OPTION );

		$state = self::state();
		if ( 'running' === $state['status'] ) {
			self::ensure_running();
			return;
		}

		self::start( 'all' );
	}

	/**
	 * In wp-admin, checks at most once a minute that a running job still
	 * has its next batch queued, and requeues it if not.
	 */
	public static function maybe_resume(): void {
		if ( get_transient( 'kdna_ei_resume_check' ) ) {
			return;
		}
		set_transient( 'kdna_ei_resume_check', 1, MINUTE_IN_SECONDS );
		self::ensure_running();
	}

	/**
	 * Requeues the next batch of a running job if it is missing, which is
	 * how an interrupted job resumes.
	 *
	 * @return bool True if a batch had to be requeued.
	 */
	public static function ensure_running(): bool {
		$state = self::state();
		if ( 'running' !== $state['status'] || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		if ( as_has_scheduled_action( self::BATCH_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP ) ) {
			return false;
		}
		// A batch that is still running holds the lock.
		if ( get_transient( self::LOCK ) ) {
			return false;
		}

		self::schedule_next();
		return true;
	}

	/**
	 * Stops the running job. Orders already processed keep their figures.
	 *
	 * @return array The job state.
	 */
	public static function cancel(): array {
		$state = self::state();
		if ( 'running' === $state['status'] ) {
			$state['status']      = 'cancelled';
			$state['finished_at'] = current_time( 'mysql', true );
			self::save( $state );
		}
		self::unschedule();
		return self::state();
	}

	/**
	 * Queues the next batch.
	 */
	private static function schedule_next(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP, true );
		}
	}

	/**
	 * Removes any queued batch.
	 */
	private static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Running a batch
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Processes the next batch of up to 100 orders, rebuilds the days they
	 * touched, saves progress and queues the next batch, or finishes the job.
	 *
	 * @return array The job state after the batch.
	 */
	public static function run_batch(): array {
		$state = self::state();
		if ( 'running' !== $state['status'] || get_transient( self::LOCK ) ) {
			return $state;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$ids  = self::next_ids( $state['mode'], $state['after'], (int) $state['last_id'], self::BATCH_SIZE );
		$days = array();

		foreach ( $ids as $id ) {
			try {
				$days = array_merge( $days, KDNA_EcommerceInsights_Order_Processor::process( $id ) );
			} catch ( Throwable $e ) {
				++$state['errors'];
				/* translators: 1: order ID, 2: error message. */
				KDNA_EcommerceInsights_Log::add( 'backfill', 'error', sprintf( __( 'Order #%1$d could not be processed: %2$s', 'kdna-ecommerce-insights' ), $id, $e->getMessage() ) );
			}
			$state['last_id'] = $id;
			++$state['processed'];
		}

		KDNA_EcommerceInsights_Summary::rebuild_days( $days );

		if ( count( $ids ) < self::BATCH_SIZE ) {
			self::finish( $state );
		} else {
			self::save( $state );
			delete_transient( self::LOCK );
			self::schedule_next();
		}

		delete_transient( self::LOCK );
		return self::state();
	}

	/**
	 * Wraps up a finished job: for a full run, removes figures for orders
	 * that no longer exist and rebuilds every day, then logs the result.
	 *
	 * @param array $state Job state.
	 */
	private static function finish( array $state ): void {
		if ( 'all' === $state['mode'] ) {
			self::remove_deleted_orders();
			KDNA_EcommerceInsights_Summary::rebuild_all();
		}

		$state['status']      = 'complete';
		$state['finished_at'] = current_time( 'mysql', true );
		$state['total']       = max( $state['total'], $state['processed'] );
		self::save( $state );

		$labels = array(
			'all'     => __( 'All orders processed', 'kdna-ecommerce-insights' ),
			'missing' => __( 'Orders missing costs recalculated', 'kdna-ecommerce-insights' ),
			'after'   => __( 'Orders after the chosen date recalculated', 'kdna-ecommerce-insights' ),
		);
		/* translators: 1: what finished, 2: number of orders, 3: number of problems. */
		$message = sprintf( __( '%1$s: %2$d orders, %3$d problems.', 'kdna-ecommerce-insights' ), $labels[ $state['mode'] ] ?? '', $state['processed'], $state['errors'] );
		KDNA_EcommerceInsights_Log::add( 'backfill', $state['errors'] ? 'warning' : 'success', $message, $state['started_at'] );

		do_action( 'kdna_ei_job_complete', $state );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Finding orders (HPOS and legacy storage)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Checks whether WooCommerce stores orders in its own HPOS tables.
	 *
	 * @return bool
	 */
	public static function uses_hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Builds the database query parts that select real orders for a job,
	 * from whichever table WooCommerce uses.
	 *
	 * @param string $mode  Job type.
	 * @param string $after Date for "after" jobs.
	 * @return array{table: string, id: string, where: string, args: array}
	 */
	private static function source( string $mode, string $after ): array {
		global $wpdb;

		if ( 'missing' === $mode ) {
			return array(
				'table' => KDNA_EcommerceInsights_Install::table( 'order_facts' ),
				'id'    => 'order_id',
				'where' => 'missing_cost_flag = 1',
				'args'  => array(),
			);
		}

		$skip = array( 'trash', 'auto-draft', 'draft', 'wc-checkout-draft', 'checkout-draft' );
		$not  = implode( ',', array_fill( 0, count( $skip ), '%s' ) );

		if ( self::uses_hpos() ) {
			$source = array(
				'table' => $wpdb->prefix . 'wc_orders',
				'id'    => 'id',
				'where' => "type = 'shop_order' AND status NOT IN ( $not )",
				'args'  => $skip,
			);
			$date_column = 'date_created_gmt';
		} else {
			$source = array(
				'table' => $wpdb->posts,
				'id'    => 'ID',
				'where' => "post_type = 'shop_order' AND post_status NOT IN ( $not )",
				'args'  => $skip,
			);
			$date_column = 'post_date_gmt';
		}

		if ( 'after' === $mode && '' !== $after ) {
			// The chosen date is a site-time date; compare from its start in UTC.
			$from_gmt          = get_gmt_from_date( $after . ' 00:00:00' );
			$source['where']  .= " AND {$date_column} >= %s";
			$source['args'][]  = $from_gmt;
		}

		return $source;
	}

	/**
	 * Counts the orders a job will process.
	 *
	 * @param string $mode  Job type.
	 * @param string $after Date for "after" jobs.
	 * @return int
	 */
	public static function count( string $mode, string $after = '' ): int {
		global $wpdb;
		$source = self::source( $mode, $after );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT COUNT(*) FROM {$source['table']} WHERE {$source['where']}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $source['args'] ? $wpdb->prepare( $sql, $source['args'] ) : $sql );
	}

	/**
	 * Returns the next order IDs for a job, lowest first, after the last one done.
	 *
	 * @param string $mode    Job type.
	 * @param string $after   Date for "after" jobs.
	 * @param int    $last_id Last order ID processed.
	 * @param int    $limit   How many.
	 * @return int[]
	 */
	public static function next_ids( string $mode, string $after, int $last_id, int $limit ): array {
		global $wpdb;
		$source = self::source( $mode, $after );

		$sql  = "SELECT {$source['id']} FROM {$source['table']} WHERE {$source['where']} AND {$source['id']} > %d ORDER BY {$source['id']} ASC LIMIT %d";
		$args = array_merge( $source['args'], array( $last_id, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * Returns the IDs of an order's refunds straight from the order table.
	 * Used when WooCommerce's own refund lookup comes back empty for an order
	 * that has refunds, which happens with some database setups.
	 *
	 * @param int $order_id Parent order ID.
	 * @return int[]
	 */
	public static function refund_ids( int $order_id ): array {
		global $wpdb;

		if ( self::uses_hpos() ) {
			$sql = $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order_refund' AND parent_order_id = %d ORDER BY id ASC", $order_id );
		} else {
			$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order_refund' AND post_parent = %d ORDER BY ID ASC", $order_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Returns the IDs of orders changed since a moment in time, for the
	 * nightly check, straight from whichever order table WooCommerce uses.
	 *
	 * @param string $since_gmt Date and time in UTC, Y-m-d H:i:s.
	 * @return int[]
	 */
	public static function modified_since( string $since_gmt ): array {
		global $wpdb;
		$source = self::source( 'all', '' );
		$column = self::uses_hpos() ? 'date_updated_gmt' : 'post_modified_gmt';

		$sql  = "SELECT {$source['id']} FROM {$source['table']} WHERE {$source['where']} AND {$column} >= %s ORDER BY {$source['id']} ASC";
		$args = array_merge( $source['args'], array( $since_gmt ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * Removes saved figures for orders that have since been deleted or trashed.
	 */
	private static function remove_deleted_orders(): void {
		global $wpdb;
		$facts  = KDNA_EcommerceInsights_Install::table( 'order_facts' );
		$source = self::source( 'all', '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gone = $wpdb->get_col( $wpdb->prepare( "SELECT f.order_id FROM {$facts} f WHERE NOT EXISTS ( SELECT 1 FROM {$source['table']} o WHERE o.{$source['id']} = f.order_id AND {$source['where']} )", $source['args'] ) );

		foreach ( (array) $gone as $order_id ) {
			KDNA_EcommerceInsights_Order_Processor::delete( (int) $order_id );
		}
	}
}
