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

	/**
	 * Plain names for each kind of log row, shown in the Data tab filter.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			'backfill'    => __( 'Order processing', 'kdna-ecommerce-insights' ),
			'order'       => __( 'Single orders', 'kdna-ecommerce-insights' ),
			'nightly'     => __( 'Nightly check', 'kdna-ecommerce-insights' ),
			'sync_meta'   => __( 'Meta sync', 'kdna-ecommerce-insights' ),
			'sync_google' => __( 'Google Ads sync', 'kdna-ecommerce-insights' ),
			'snapshot'    => __( 'Stock snapshots', 'kdna-ecommerce-insights' ),
			'adspend'     => __( 'Ad spend imports', 'kdna-ecommerce-insights' ),
			'alert'       => __( 'Low stock emails', 'kdna-ecommerce-insights' ),
			'sync_alert'  => __( 'Sync failure emails', 'kdna-ecommerce-insights' ),
			'digest'      => __( 'Digest emails', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * One page of the log, newest first, with the total for paging.
	 *
	 * @param int    $page     Page number, from 1.
	 * @param int    $per_page Rows per page.
	 * @param string $type     Only this type, or empty for all.
	 * @param string $status   Only this result (success, warning, error), or empty for all.
	 * @return array{rows: array[], total: int, pages: int, page: int, types: array}
	 */
	public static function page( int $page, int $per_page, string $type = '', string $status = '' ): array {
		global $wpdb;
		$table  = KDNA_EcommerceInsights_Install::table( 'sync_log' );
		$where  = array( '1=1' );
		$values = array();

		if ( '' !== $type ) {
			$where[]  = 'type = %s';
			$values[] = $type;
		}
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		$sql_where = implode( ' AND ', $where );
		$per_page  = max( 1, $per_page );
		$page      = max( 1, $page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		$types     = $wpdb->get_col( "SELECT DISTINCT type FROM {$table} ORDER BY type" );
		// phpcs:enable

		$labels = self::type_labels();
		$known  = array();
		foreach ( (array) $types as $key ) {
			$known[ $key ] = $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', (string) $key ) );
		}

		return array(
			'rows'  => array_map(
				static function ( $row ) use ( $known ) {
					$row['type_label'] = $known[ $row['type'] ] ?? $row['type'];
					return $row;
				},
				(array) $rows
			),
			'total' => $total,
			'pages' => (int) max( 1, ceil( $total / $per_page ) ),
			'page'  => $page,
			'types' => $known,
		);
	}
}
