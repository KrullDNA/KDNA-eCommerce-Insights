<?php
/**
 * Simple log of background jobs and problems, kept in the sync_log table.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the sync_log table, which the Data settings tab shows so
 * the owner (or KDNA) can see what ran and what went wrong.
 */
class KDNA_EcommerceInsights_Log {

	/**
	 * Most log rows kept. Older rows are removed when new ones are added.
	 */
	const MAX_ROWS = 500;

	/**
	 * Adds a log row.
	 *
	 * @param string      $type        What ran, for example "backfill" or "order".
	 * @param string      $status      success, warning or error.
	 * @param string      $message     Plain-English description.
	 * @param string|null $started_at  When it started (UTC), defaults to now.
	 * @return int The new row ID.
	 */
	public static function add( string $type, string $status, string $message, ?string $started_at = null ): int {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = KDNA_EcommerceInsights_Install::table( 'sync_log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'type'        => mb_substr( $type, 0, 50 ),
				'status'      => mb_substr( $status, 0, 20 ),
				'message'     => $message,
				'started_at'  => $started_at ? $started_at : $now,
				'finished_at' => $now,
			)
		);
		$id = (int) $wpdb->insert_id;

		// Keep the table small.
		if ( $id > self::MAX_ROWS && 0 === $id % 50 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $id - self::MAX_ROWS ) );
		}

		return $id;
	}

	/**
	 * Returns the most recent log rows, newest first.
	 *
	 * @param int $limit How many rows.
	 * @return array[]
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'sync_log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) ), ARRAY_A );
	}
}
