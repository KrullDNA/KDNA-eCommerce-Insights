<?php
/**
 * Advertising spend: saving entries spread evenly by day, and reading them back.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores ad spend in the ad_spend table, one row per day, channel and
 * campaign. An amount entered for a date range (for example a month) is
 * split evenly across its days, like overheads, so any report range carries
 * its fair share.
 *
 * Each entry's daily rows share an "entry group" ID so the whole entry can
 * be edited or deleted together. CSV imports (class-kdna-ei-ad-spend-import.php)
 * save through the same functions, and Stage 10 adds rows synced from Meta
 * and Google.
 */
class KDNA_EcommerceInsights_Ad_Spend {

	/**
	 * Checks a manual entry and explains any problem in plain English.
	 *
	 * @param array $data Entry: start, end, channel, campaign_name, amount, includes_gst.
	 * @return array<string, string> Problems keyed by field. Empty when all is well.
	 */
	public static function validate( array $data ): array {
		$errors = array();
		$start  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $data['start'] ?? '' ) );
		$end    = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $data['end'] ?? $data['start'] ?? '' ) );

		if ( ! $start || $start->format( 'Y-m-d' ) !== ( $data['start'] ?? '' ) ) {
			$errors['start'] = __( 'Choose the date this spend starts.', 'kdna-ecommerce-insights' );
		}
		if ( ! empty( $data['end'] ) && ( ! $end || $end->format( 'Y-m-d' ) !== $data['end'] ) ) {
			$errors['end'] = __( 'That end date is not a valid date.', 'kdna-ecommerce-insights' );
		} elseif ( $start && $end && $end < $start ) {
			$errors['end'] = __( 'The end date must be on or after the start date.', 'kdna-ecommerce-insights' );
		} elseif ( $start && $end && $start->diff( $end )->days > 366 ) {
			$errors['end'] = __( 'Please enter spend for a year or less at a time.', 'kdna-ecommerce-insights' );
		}
		if ( '' === sanitize_key( (string) ( $data['channel'] ?? '' ) ) ) {
			$errors['channel'] = __( 'Choose a channel, for example Meta or Google.', 'kdna-ecommerce-insights' );
		}
		// A live-connected channel's spend comes from the platform for synced dates.
		if ( ! $errors && class_exists( 'KDNA_EcommerceInsights_Ad_Sync' ) ) {
			$blocked = KDNA_EcommerceInsights_Ad_Sync::blocked( sanitize_key( (string) $data['channel'] ), (string) $data['start'], (string) ( ! empty( $data['end'] ) ? $data['end'] : $data['start'] ) );
			if ( '' !== $blocked ) {
				$errors['channel'] = $blocked;
			}
		}
		if ( mb_strlen( (string) ( $data['campaign_name'] ?? '' ) ) > 255 ) {
			$errors['campaign_name'] = __( 'Please keep the campaign name under 255 characters.', 'kdna-ecommerce-insights' );
		}
		$amount = $data['amount'] ?? '';
		if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount < 0 ) {
			$errors['amount'] = __( 'Enter an amount of zero or more.', 'kdna-ecommerce-insights' );
		}

		return $errors;
	}

	/**
	 * Saves a manual entry, split evenly across its days. Call validate() first.
	 *
	 * @param array  $data   Entry details.
	 * @param string $source manual, csv or api.
	 * @param string $group  Entry group ID to use, or empty for a new one.
	 * @return string The entry group ID.
	 */
	public static function create( array $data, string $source = 'manual', string $group = '' ): string {
		$start  = new DateTimeImmutable( (string) $data['start'] );
		$end    = new DateTimeImmutable( (string) ( ! empty( $data['end'] ) ? $data['end'] : $data['start'] ) );
		$days   = (int) $start->diff( $end )->days + 1;
		$group  = '' !== $group ? $group : wp_generate_uuid4();
		$rows   = array();

		foreach ( self::spread( (float) $data['amount'], $days ) as $i => $spend ) {
			$rows[] = array(
				'spend_date'    => $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' ),
				'channel'       => (string) $data['channel'],
				'campaign_id'   => (string) ( $data['campaign_id'] ?? '' ),
				'campaign_name' => (string) ( $data['campaign_name'] ?? '' ),
				'spend'         => $spend,
				'includes_gst'  => ! empty( $data['includes_gst'] ),
			);
		}

		self::insert_rows( $rows, $source, $group );
		do_action( 'kdna_ei_ad_spend_changed' );
		return $group;
	}

	/**
	 * Splits an amount evenly across a number of days, to four decimal
	 * places. The last day takes any rounding remainder so the total is exact.
	 *
	 * @param float $amount Amount.
	 * @param int   $days   Number of days.
	 * @return float[]
	 */
	public static function spread( float $amount, int $days ): array {
		$days   = max( 1, $days );
		$amount = round( $amount, 4 );
		$share  = floor( $amount / $days * 10000 ) / 10000;
		$list   = array_fill( 0, $days, $share );

		$list[ $days - 1 ] = round( $amount - $share * ( $days - 1 ), 4 );
		return $list;
	}

	/**
	 * Writes daily rows in batches of 200, which is much quicker than one at
	 * a time for imports.
	 *
	 * @param array[] $rows   Rows with spend_date, channel, campaign_id, campaign_name,
	 *                        spend and optionally impressions, clicks, conversions,
	 *                        conversion_value, includes_gst.
	 * @param string  $source manual, csv or api.
	 * @param string  $group  Entry group ID.
	 */
	public static function insert_rows( array $rows, string $source, string $group ): void {
		global $wpdb;

		$table    = KDNA_EcommerceInsights_Install::table( 'ad_spend' );
		$now      = current_time( 'mysql', true );
		$currency = (string) get_option( 'woocommerce_currency' );

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$values[] = $wpdb->prepare(
					'(%s, %s, %s, %s, %f, %d, %d, %f, %f, %d, %s, %s, %s, %s, %s)',
					$row['spend_date'],
					sanitize_key( (string) $row['channel'] ),
					mb_substr( sanitize_text_field( (string) ( $row['campaign_id'] ?? '' ) ), 0, 100 ),
					mb_substr( sanitize_text_field( (string) ( $row['campaign_name'] ?? '' ) ), 0, 255 ),
					round( (float) $row['spend'], 4 ),
					(int) ( $row['impressions'] ?? 0 ),
					(int) ( $row['clicks'] ?? 0 ),
					round( (float) ( $row['conversions'] ?? 0 ), 4 ),
					round( (float) ( $row['conversion_value'] ?? 0 ), 4 ),
					empty( $row['includes_gst'] ) ? 0 : 1,
					$source,
					$currency,
					$group,
					$now,
					$now
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} ( spend_date, channel, campaign_id, campaign_name, spend, impressions, clicks, conversions, conversion_value, includes_gst, source, currency, entry_group, created_at, updated_at ) VALUES " . implode( ',', $values ) );
		}
	}

	/**
	 * Changes a manual entry: its days are replaced, keeping the same entry
	 * ID. Call validate() first. Only manual entries can be edited; imported
	 * spend is changed by importing again.
	 *
	 * @param string $group Entry group ID.
	 * @param array  $data  New entry details.
	 * @return bool|WP_Error True when saved.
	 */
	public static function update( string $group, array $data ) {
		$entry = self::entry( $group );
		if ( ! $entry ) {
			return new WP_Error( 'kdna_ei_not_found', __( 'That ad spend entry no longer exists.', 'kdna-ecommerce-insights' ), array( 'status' => 404 ) );
		}
		if ( 'manual' !== $entry['source'] ) {
			return new WP_Error( 'kdna_ei_not_editable', __( 'Imported spend cannot be edited here. Delete the import and import the corrected file instead.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
		}

		self::delete_group( $group, false );
		self::create( $data, 'manual', $group );
		return true;
	}

	/**
	 * One entry's summary, or null if it does not exist.
	 *
	 * @param string $group Entry group ID.
	 * @return array|null
	 */
	public static function entry( string $group ): ?array {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'ad_spend' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT entry_group, MIN( channel ) AS channel, MIN( source ) AS source, MIN( spend_date ) AS start, MAX( spend_date ) AS end, SUM( spend ) AS amount FROM {$table} WHERE entry_group = %s GROUP BY entry_group", $group ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Deletes every day of an entry.
	 *
	 * @param string $group    Entry group ID.
	 * @param bool   $announce Tell the rest of the plugin (clears the report cache).
	 * @return bool True if anything was deleted.
	 */
	public static function delete_group( string $group, bool $announce = true ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (bool) $wpdb->delete( KDNA_EcommerceInsights_Install::table( 'ad_spend' ), array( 'entry_group' => $group ), array( '%s' ) );
		if ( $deleted && $announce ) {
			do_action( 'kdna_ei_ad_spend_changed' );
		}
		return $deleted;
	}

	/**
	 * Lists entries overlapping a date range, one line per entry with its
	 * full dates and total. A CSV import is one entry, however many days and
	 * campaigns it held.
	 *
	 * @param string $start First day, Y-m-d.
	 * @param string $end   Last day, Y-m-d.
	 * @return array[]
	 */
	public static function entries( string $start, string $end ): array {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'ad_spend' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_group, MIN( channel ) AS channel, MIN( campaign_name ) AS campaign_name, COUNT( DISTINCT campaign_name ) AS campaigns,
					MIN( source ) AS source, MAX( includes_gst ) AS includes_gst,
					MIN( spend_date ) AS start, MAX( spend_date ) AS end, SUM( spend ) AS amount, MAX( created_at ) AS created_at
				FROM {$table}
				WHERE entry_group IN ( SELECT DISTINCT entry_group FROM {$table} WHERE spend_date BETWEEN %s AND %s )
				GROUP BY entry_group
				ORDER BY start DESC, created_at DESC",
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static function ( $row ) {
				$row['amount']       = round( (float) $row['amount'], 2 );
				$row['campaigns']    = (int) $row['campaigns'];
				$row['includes_gst'] = (bool) $row['includes_gst'];
				$row['editable']     = 'manual' === $row['source'];
				$row['live']         = 'api' === $row['source'] && KDNA_EcommerceInsights_Ad_Sync::platform( $row['channel'] ) && KDNA_EcommerceInsights_Ad_Sync::platform( $row['channel'] )->configured();
				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * SQL for a row's spend with claimable GST or VAT taken off, for rows
	 * entered including it. Used by every report so ad spend is always
	 * counted the same way.
	 *
	 * @param string $alias Table alias prefix, for example "a." or "".
	 * @return string
	 */
	public static function net_spend_sql( string $alias = '' ): string {
		$rate = self::claimable_tax_rate();
		if ( $rate <= 0 ) {
			return "{$alias}spend";
		}
		$factor = $rate / ( 100 + $rate );
		return "( {$alias}spend - CASE WHEN {$alias}includes_gst = 1 THEN {$alias}spend * " . sprintf( '%.10F', $factor ) . ' ELSE 0 END )';
	}

	/**
	 * The share of an amount that is GST or VAT, when the store reports it
	 * and the amount includes it.
	 *
	 * @return float The rate, for example 10, or 0.
	 */
	public static function claimable_tax_rate(): float {
		$system = (string) KDNA_EcommerceInsights_Settings::get( 'tax.system', 'none' );
		return in_array( $system, array( 'au_gst', 'vat' ), true ) ? (float) KDNA_EcommerceInsights_Settings::get( 'tax.rate', 0 ) : 0.0;
	}
}
