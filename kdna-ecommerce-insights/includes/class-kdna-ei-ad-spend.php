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
 * be edited or deleted together. Stage 9 adds CSV import on top of this and
 * Stage 10 adds rows synced from Meta and Google.
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
	 * @return string The entry group ID.
	 */
	public static function create( array $data, string $source = 'manual' ): string {
		global $wpdb;

		$start  = new DateTimeImmutable( (string) $data['start'] );
		$end    = new DateTimeImmutable( (string) ( ! empty( $data['end'] ) ? $data['end'] : $data['start'] ) );
		$days   = (int) $start->diff( $end )->days + 1;
		$amount = round( (float) $data['amount'], 4 );
		$group  = wp_generate_uuid4();
		$now    = current_time( 'mysql', true );
		$share  = floor( $amount / $days * 10000 ) / 10000;

		for ( $i = 0; $i < $days; $i++ ) {
			// The last day takes any rounding remainder so the total is exact.
			$spend = $i === $days - 1 ? $amount - $share * ( $days - 1 ) : $share;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				KDNA_EcommerceInsights_Install::table( 'ad_spend' ),
				array(
					'spend_date'    => $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' ),
					'channel'       => sanitize_key( (string) $data['channel'] ),
					'campaign_id'   => mb_substr( sanitize_text_field( (string) ( $data['campaign_id'] ?? '' ) ), 0, 100 ),
					'campaign_name' => mb_substr( sanitize_text_field( (string) ( $data['campaign_name'] ?? '' ) ), 0, 255 ),
					'spend'         => round( $spend, 4 ),
					'includes_gst'  => empty( $data['includes_gst'] ) ? 0 : 1,
					'source'        => $source,
					'currency'      => get_option( 'woocommerce_currency' ),
					'entry_group'   => $group,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}

		do_action( 'kdna_ei_ad_spend_changed' );
		return $group;
	}

	/**
	 * Deletes every day of an entry.
	 *
	 * @param string $group Entry group ID.
	 * @return bool True if anything was deleted.
	 */
	public static function delete_group( string $group ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (bool) $wpdb->delete( KDNA_EcommerceInsights_Install::table( 'ad_spend' ), array( 'entry_group' => $group ), array( '%s' ) );
		if ( $deleted ) {
			do_action( 'kdna_ei_ad_spend_changed' );
		}
		return $deleted;
	}

	/**
	 * Lists entries overlapping a date range, one line per entry with its
	 * full dates and total.
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
				"SELECT entry_group, channel, campaign_name, source, MAX( includes_gst ) AS includes_gst,
					MIN( spend_date ) AS start, MAX( spend_date ) AS end, SUM( spend ) AS amount
				FROM {$table}
				WHERE entry_group IN ( SELECT DISTINCT entry_group FROM {$table} WHERE spend_date BETWEEN %s AND %s )
				GROUP BY entry_group, channel, campaign_name, source
				ORDER BY start DESC",
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static function ( $row ) {
				$row['amount']       = round( (float) $row['amount'], 2 );
				$row['includes_gst'] = (bool) $row['includes_gst'];
				return $row;
			},
			(array) $rows
		);
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
