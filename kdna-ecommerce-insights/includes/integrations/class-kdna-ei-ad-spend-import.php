<?php
/**
 * Ad spend CSV import: column mapping, presets, preview and import.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports ad spend from a spreadsheet exported from an ad platform.
 *
 * How it works, in plain English:
 * 1. The file is read, whatever the platform did to it: Google Ads puts a
 *    report title and date line above the real header and adds "Total:"
 *    rows at the bottom, and can save as UTF-16 with tabs.
 * 2. Each column is matched to a field (date, campaign, spend and so on),
 *    using the built-in Meta and Google presets, a saved preset or the
 *    owner's own choices.
 * 3. A preview shows what will be imported, with any problem rows.
 * 4. On import, spend already imported from CSV for the same channel and
 *    dates is replaced, so importing the same report twice never doubles
 *    the figures. Manual entries are never touched.
 *
 * A row covering several days (Meta exports without a daily breakdown)
 * is spread evenly across them, like a manual entry.
 */
class KDNA_EcommerceInsights_Ad_Spend_Import {

	/**
	 * The fields a column can be mapped to. Date and spend are required.
	 *
	 * @return array<string, array{label: string, required: bool}>
	 */
	public static function fields(): array {
		return array(
			'date'             => array( 'label' => __( 'Date (or start date)', 'kdna-ecommerce-insights' ), 'required' => true ),
			'end_date'         => array( 'label' => __( 'End date', 'kdna-ecommerce-insights' ), 'required' => false ),
			'spend'            => array( 'label' => __( 'Amount spent', 'kdna-ecommerce-insights' ), 'required' => true ),
			'campaign_name'    => array( 'label' => __( 'Campaign name', 'kdna-ecommerce-insights' ), 'required' => false ),
			'campaign_id'      => array( 'label' => __( 'Campaign ID', 'kdna-ecommerce-insights' ), 'required' => false ),
			'impressions'      => array( 'label' => __( 'Impressions', 'kdna-ecommerce-insights' ), 'required' => false ),
			'clicks'           => array( 'label' => __( 'Clicks', 'kdna-ecommerce-insights' ), 'required' => false ),
			'conversions'      => array( 'label' => __( 'Purchases or conversions', 'kdna-ecommerce-insights' ), 'required' => false ),
			'conversion_value' => array( 'label' => __( 'Purchase or conversion value', 'kdna-ecommerce-insights' ), 'required' => false ),
			'currency'         => array( 'label' => __( 'Currency', 'kdna-ecommerce-insights' ), 'required' => false ),
		);
	}

	/**
	 * The built-in presets for the standard Meta Ads Manager and Google Ads
	 * exports. Each field lists the column names those platforms use, in
	 * order of preference. A name ending in * matches any column starting
	 * with it, for example "amount spent (aud)".
	 *
	 * @return array<string, array{key: string, name: string, channel: string, builtin: bool, columns: array<string, string[]>}>
	 */
	public static function builtin_presets(): array {
		return array(
			'meta'   => array(
				'key'     => 'meta',
				'name'    => __( 'Meta Ads Manager export', 'kdna-ecommerce-insights' ),
				'channel' => 'meta',
				'builtin' => true,
				'columns' => array(
					'date'             => array( 'day', 'reporting starts', 'date' ),
					'end_date'         => array( 'reporting ends' ),
					'spend'            => array( 'amount spent*', 'amount spent' ),
					'campaign_name'    => array( 'campaign name', 'campaign' ),
					'campaign_id'      => array( 'campaign id' ),
					'impressions'      => array( 'impressions' ),
					'clicks'           => array( 'link clicks', 'clicks (all)', 'clicks' ),
					'conversions'      => array( 'purchases', 'website purchases', 'results' ),
					'conversion_value' => array( 'purchases conversion value', 'website purchases conversion value', 'purchase conversion value', 'purchases value' ),
					'currency'         => array( 'currency' ),
				),
			),
			'google' => array(
				'key'     => 'google',
				'name'    => __( 'Google Ads export', 'kdna-ecommerce-insights' ),
				'channel' => 'google',
				'builtin' => true,
				'columns' => array(
					'date'             => array( 'day', 'date' ),
					'end_date'         => array(),
					'spend'            => array( 'cost', 'cost*' ),
					'campaign_name'    => array( 'campaign', 'campaign name' ),
					'campaign_id'      => array( 'campaign id' ),
					'impressions'      => array( 'impr.', 'impressions' ),
					'clicks'           => array( 'clicks' ),
					'conversions'      => array( 'conversions', 'conv.' ),
					'conversion_value' => array( 'conv. value', 'conversion value', 'total conv. value', 'all conv. value' ),
					'currency'         => array( 'currency code', 'currency' ),
				),
			),
		);
	}

	/**
	 * Every preset: the two built-in ones plus any saved in Settings.
	 * Saved presets map each field to one column name.
	 *
	 * @return array[]
	 */
	public static function presets(): array {
		$presets = array();
		foreach ( self::builtin_presets() as $key => $preset ) {
			$presets[] = array(
				'key'     => $key,
				'name'    => $preset['name'],
				'channel' => $preset['channel'],
				'builtin' => true,
			);
		}
		foreach ( (array) KDNA_EcommerceInsights_Settings::get( 'marketing.csv_presets', array() ) as $preset ) {
			$presets[] = array(
				'key'          => 'saved-' . sanitize_title( $preset['name'] ),
				'name'         => $preset['name'],
				'channel'      => $preset['channel'] ?? $preset['platform'] ?? '',
				'builtin'      => false,
				'mapping'      => $preset['mapping'],
				'includes_gst' => ! empty( $preset['includes_gst'] ),
			);
		}
		return $presets;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading the file
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Reads an export into a header and rows, coping with what each platform
	 * adds around the table: UTF-16 text, report titles above the header,
	 * and total or summary rows.
	 *
	 * @param string $text File contents.
	 * @return array{header: string[], rows: array<int, string[]>, skipped: int}|WP_Error
	 */
	public static function read( string $text ) {
		// Google Ads "Excel CSV" files are UTF-16 with tabs.
		if ( 0 === strncmp( $text, "\xFF\xFE", 2 ) || 0 === strncmp( $text, "\xFE\xFF", 2 ) ) {
			$text = mb_convert_encoding( substr( $text, 2 ), 'UTF-8', 0 === strncmp( $text, "\xFF\xFE", 2 ) ? 'UTF-16LE' : 'UTF-16BE' );
		}
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );

		// Drop title lines above the real header: the header is the first of
		// the first ten lines that names a date column and a spend column.
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		for ( $i = 0; $i < min( 10, count( $lines ) ); $i++ ) {
			$line = strtolower( $lines[ $i ] );
			if ( preg_match( '/\b(day|date|reporting starts)\b/', $line ) && preg_match( '/\b(amount spent|cost|spend)\b/', $line ) ) {
				$lines = array_slice( $lines, $i );
				break;
			}
		}

		$parsed = KDNA_EcommerceInsights_Csv_Import::parse( implode( "\n", $lines ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// Drop Google's "Total: ..." rows and blank summary rows.
		$skipped = 0;
		foreach ( $parsed['rows'] as $line => $cells ) {
			if ( preg_match( '/^total\b/i', (string) ( $cells[0] ?? '' ) ) ) {
				unset( $parsed['rows'][ $line ] );
				++$skipped;
			}
		}
		$parsed['skipped'] = $skipped;

		return $parsed;
	}

	/**
	 * Works out which preset fits a file's columns, if any.
	 *
	 * @param string[] $header Header (lower case).
	 * @return string meta, google or empty.
	 */
	public static function detect( array $header ): string {
		$joined = ' ' . implode( ' | ', $header ) . ' ';
		if ( false !== strpos( $joined, 'amount spent' ) ) {
			return 'meta';
		}
		if ( in_array( 'cost', $header, true ) && ( in_array( 'impr.', $header, true ) || in_array( 'campaign', $header, true ) ) ) {
			return 'google';
		}
		return '';
	}

	/**
	 * Builds a mapping (field to column name) for a preset and a header.
	 *
	 * @param string   $preset Preset key.
	 * @param string[] $header Header (lower case).
	 * @return array<string, string>
	 */
	public static function mapping_for( string $preset, array $header ): array {
		$mapping = array_fill_keys( array_keys( self::fields() ), '' );

		foreach ( self::presets() as $saved ) {
			if ( $saved['key'] === $preset && ! $saved['builtin'] ) {
				foreach ( $saved['mapping'] as $field => $column ) {
					if ( isset( $mapping[ $field ] ) && in_array( $column, $header, true ) ) {
						$mapping[ $field ] = $column;
					}
				}
				return $mapping;
			}
		}

		$builtin = self::builtin_presets();
		$columns = $builtin[ $preset ]['columns'] ?? array();

		// No preset: try every name either platform uses.
		if ( ! $columns ) {
			foreach ( $builtin as $one ) {
				foreach ( $one['columns'] as $field => $names ) {
					$columns[ $field ] = array_merge( $columns[ $field ] ?? array(), $names );
				}
			}
		}

		foreach ( $columns as $field => $names ) {
			foreach ( $names as $name ) {
				foreach ( $header as $column ) {
					$match = '*' === substr( $name, -1 ) ? 0 === strpos( $column, rtrim( $name, '*' ) ) : $column === $name;
					if ( $match && ! in_array( $column, $mapping, true ) ) {
						$mapping[ $field ] = $column;
						continue 3;
					}
				}
			}
		}

		return $mapping;
	}

	/**
	 * The currency named in a spend column, for example "amount spent (usd)".
	 *
	 * @param string $column Column name.
	 * @return string Upper-case code, or empty.
	 */
	public static function currency_in( string $column ): string {
		return preg_match( '/\(([a-z]{3})\)/', $column, $match ) ? strtoupper( $match[1] ) : '';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Dates
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Works out whether slashed dates in a file are day first (1/09/2026)
	 * or month first (9/1/2026): a number above 12 decides it, otherwise the
	 * site's language does (month first for US English).
	 *
	 * @param string[] $values Date cells.
	 * @return string dmy or mdy.
	 */
	public static function date_order( array $values ): string {
		foreach ( $values as $value ) {
			if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-]\d{2,4}$#', trim( $value ), $m ) ) {
				if ( (int) $m[1] > 12 ) {
					return 'dmy';
				}
				if ( (int) $m[2] > 12 ) {
					return 'mdy';
				}
			}
		}
		return 'en_US' === get_locale() ? 'mdy' : 'dmy';
	}

	/**
	 * Turns a date cell into Y-m-d. Accepts 2026-09-01, 1/09/2026 (or
	 * 9/1/2026), 1 Sep 2026, Sep 1, 2026 and similar.
	 *
	 * @param string $value Cell.
	 * @param string $order dmy or mdy, for slashed dates.
	 * @return string Y-m-d, or empty if it is not a date.
	 */
	public static function parse_date( string $value, string $order ): string {
		$value = trim( $value );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] ) : '';
		}
		if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $value, $m ) ) {
			$year  = strlen( $m[3] ) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
			$day   = 'dmy' === $order ? (int) $m[1] : (int) $m[2];
			$month = 'dmy' === $order ? (int) $m[2] : (int) $m[1];
			return checkdate( $month, $day, $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
		}
		if ( preg_match( '/[a-z]/i', $value ) ) {
			$time = strtotime( str_replace( ',', '', $value ) . ' 12:00' );
			return $time ? gmdate( 'Y-m-d', $time ) : '';
		}
		return '';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Preview and import
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Reads a file with a mapping into daily rows, ready to save, and
	 * collects any problems.
	 *
	 * @param string $text    File contents.
	 * @param array  $options preset, mapping (field => column), channel,
	 *                        rate (to the store currency), includes_gst.
	 * @return array|WP_Error header, preset, mapping, currency, rows (daily),
	 *                        lines (one per file row, for the preview),
	 *                        errors, totals, start, end.
	 */
	public static function prepare( string $text, array $options ) {
		$file = self::read( $text );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$header  = $file['header'];
		$preset  = (string) ( $options['preset'] ?? '' );
		$preset  = '' !== $preset ? $preset : self::detect( $header );
		$mapping = ! empty( $options['mapping'] ) ? array_intersect_key( array_map( 'strval', (array) $options['mapping'] ), self::fields() ) : self::mapping_for( $preset, $header );
		$mapping = array_merge( array_fill_keys( array_keys( self::fields() ), '' ), array_map( static fn( $c ) => in_array( $c, $header, true ) ? $c : '', $mapping ) );

		$index = array();
		foreach ( $mapping as $field => $column ) {
			$position        = '' === $column ? false : array_search( $column, $header, true );
			$index[ $field ] = false === $position ? null : $position;
		}

		$store_currency = (string) get_option( 'woocommerce_currency' );
		$file_currency  = '' !== $mapping['spend'] ? self::currency_in( $mapping['spend'] ) : '';
		$rate           = isset( $options['rate'] ) && (float) $options['rate'] > 0 ? (float) $options['rate'] : 1.0;
		$cell           = static fn( $cells, $field ) => null === $index[ $field ] ? '' : (string) ( $cells[ $index[ $field ] ] ?? '' );
		$order          = self::date_order( array_map( static fn( $cells ) => $cell( $cells, 'date' ), $file['rows'] ) );

		$errors = array();
		foreach ( array( 'date', 'spend' ) as $field ) {
			if ( null === $index[ $field ] ) {
				$errors[] = array(
					'line'    => 0,
					/* translators: %s: field name. */
					'message' => sprintf( __( 'Choose which column holds the %s.', 'kdna-ecommerce-insights' ), strtolower( self::fields()[ $field ]['label'] ) ),
				);
			}
		}
		if ( $errors ) {
			return array_merge( compact( 'header', 'preset', 'mapping', 'errors' ), array( 'currency' => $file_currency, 'store_currency' => $store_currency, 'rows' => array(), 'lines' => array(), 'totals' => array( 'spend' => 0, 'rows' => 0, 'days' => 0, 'campaigns' => 0 ), 'start' => '', 'end' => '', 'skipped' => $file['skipped'] ) );
		}

		$lines = array();
		$daily = array();
		foreach ( $file['rows'] as $line => $cells ) {
			$start = self::parse_date( $cell( $cells, 'date' ), $order );
			$end   = null === $index['end_date'] ? $start : self::parse_date( $cell( $cells, 'end_date' ), $order );
			$spend = KDNA_EcommerceInsights_Csv_Import::parse_amount( $cell( $cells, 'spend' ) );
			$code  = strtoupper( trim( $cell( $cells, 'currency' ) ) );
			if ( '' !== $code && '' === $file_currency ) {
				$file_currency = $code;
			}

			if ( '' === $start ) {
				/* translators: 1: line number, 2: cell value. */
				$errors[] = array( 'line' => $line, 'message' => sprintf( __( 'Line %1$d: "%2$s" is not a date, so this row was skipped.', 'kdna-ecommerce-insights' ), $line, $cell( $cells, 'date' ) ) );
				continue;
			}
			if ( '' === $end || $end < $start ) {
				$end = $start;
			}
			if ( null === $spend || $spend < 0 ) {
				// A blank spend (Google shows "--") means nothing was spent.
				if ( '' === trim( $cell( $cells, 'spend' ), " -\t" ) ) {
					$spend = 0.0;
				} else {
					/* translators: 1: line number, 2: cell value. */
					$errors[] = array( 'line' => $line, 'message' => sprintf( __( 'Line %1$d: "%2$s" is not an amount, so this row was skipped.', 'kdna-ecommerce-insights' ), $line, $cell( $cells, 'spend' ) ) );
					continue;
				}
			}

			$number = static fn( $field ) => (float) KDNA_EcommerceInsights_Csv_Import::parse_amount( $cell( $cells, $field ) );
			$days   = (int) ( new DateTimeImmutable( $start ) )->diff( new DateTimeImmutable( $end ) )->days + 1;
			$row    = array(
				'campaign_name'    => $cell( $cells, 'campaign_name' ),
				'campaign_id'      => $cell( $cells, 'campaign_id' ),
				'spend'            => $spend * $rate,
				'impressions'      => $number( 'impressions' ),
				'clicks'           => $number( 'clicks' ),
				'conversions'      => $number( 'conversions' ),
				'conversion_value' => $number( 'conversion_value' ) * $rate,
			);

			$lines[] = array_merge( $row, array( 'line' => $line, 'start' => $start, 'end' => $end, 'days' => $days ) );

			// Spread a row covering several days evenly, then add it up by day and campaign.
			$shares = array();
			foreach ( array( 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value' ) as $field ) {
				$shares[ $field ] = KDNA_EcommerceInsights_Ad_Spend::spread( (float) $row[ $field ], $days );
			}
			for ( $i = 0; $i < $days; $i++ ) {
				$day = ( new DateTimeImmutable( $start ) )->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
				$key = $day . '|' . $row['campaign_id'] . '|' . $row['campaign_name'];
				if ( ! isset( $daily[ $key ] ) ) {
					$daily[ $key ] = array(
						'spend_date'       => $day,
						'channel'          => sanitize_key( (string) ( $options['channel'] ?? '' ) ),
						'campaign_id'      => $row['campaign_id'],
						'campaign_name'    => $row['campaign_name'],
						'spend'            => 0.0,
						'impressions'      => 0.0,
						'clicks'           => 0.0,
						'conversions'      => 0.0,
						'conversion_value' => 0.0,
						'includes_gst'     => ! empty( $options['includes_gst'] ),
					);
				}
				foreach ( $shares as $field => $values ) {
					$daily[ $key ][ $field ] += $values[ $i ];
				}
			}
		}

		$days_list = array_unique( wp_list_pluck( array_values( $daily ), 'spend_date' ) );
		sort( $days_list );

		return array(
			'header'         => $header,
			'preset'         => $preset,
			'mapping'        => $mapping,
			'currency'       => $file_currency,
			'store_currency' => $store_currency,
			'rows'           => array_values( $daily ),
			'lines'          => $lines,
			'errors'         => $errors,
			'skipped'        => $file['skipped'],
			'start'          => $days_list ? $days_list[0] : '',
			'end'            => $days_list ? end( $days_list ) : '',
			'totals'         => array(
				'spend'            => round( array_sum( wp_list_pluck( $lines, 'spend' ) ), 2 ),
				'conversion_value' => round( array_sum( wp_list_pluck( $lines, 'conversion_value' ) ), 2 ),
				'rows'             => count( $lines ),
				'days'             => count( $days_list ),
				'campaigns'        => count( array_unique( wp_list_pluck( $lines, 'campaign_name' ) ) ),
			),
		);
	}

	/**
	 * Previews an import: the mapping used, the first rows as they will be
	 * saved, totals, problems, and how much CSV spend would be replaced.
	 *
	 * @param string $text    File contents.
	 * @param array  $options See prepare().
	 * @return array|WP_Error
	 */
	public static function preview( string $text, array $options ) {
		$prepared = self::prepare( $text, $options );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$channel  = sanitize_key( (string) ( $options['channel'] ?? '' ) );
		$replaces = $prepared['start'] && $channel ? self::existing( $channel, $prepared['start'], $prepared['end'] ) : 0.0;

		return array(
			'header'         => $prepared['header'],
			'preset'         => $prepared['preset'],
			'mapping'        => $prepared['mapping'],
			'currency'       => $prepared['currency'],
			'store_currency' => $prepared['store_currency'],
			'lines'          => array_slice( $prepared['lines'], 0, 12 ),
			'errors'         => array_slice( $prepared['errors'], 0, 20 ),
			'error_count'    => count( $prepared['errors'] ),
			'skipped'        => $prepared['skipped'],
			'totals'         => $prepared['totals'],
			'start'          => $prepared['start'],
			'end'            => $prepared['end'],
			'replaces'       => round( $replaces, 2 ),
			'blocked'        => $prepared['start'] && $channel ? KDNA_EcommerceInsights_Ad_Sync::blocked( $channel, $prepared['start'], $prepared['end'] ) : '',
		);
	}

	/**
	 * Imports a file: replaces CSV spend already saved for the channel and
	 * dates, then saves the new rows as one entry.
	 *
	 * @param string $text    File contents.
	 * @param array  $options See prepare(). channel is required.
	 * @return array|WP_Error entry_group, rows, spend, replaced, start, end.
	 */
	public static function import( string $text, array $options ) {
		$channel = sanitize_key( (string) ( $options['channel'] ?? '' ) );
		if ( '' === $channel ) {
			return new WP_Error( 'kdna_ei_import_channel', __( 'Choose which channel this spend is for.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
		}

		$prepared = self::prepare( $text, $options );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( ! $prepared['rows'] ) {
			$message = $prepared['errors'] ? $prepared['errors'][0]['message'] : __( 'There is nothing to import in that file.', 'kdna-ecommerce-insights' );
			return new WP_Error( 'kdna_ei_import_empty', $message, array( 'status' => 400 ) );
		}

		$blocked = KDNA_EcommerceInsights_Ad_Sync::blocked( $channel, $prepared['start'], $prepared['end'] );
		if ( '' !== $blocked ) {
			return new WP_Error( 'kdna_ei_import_blocked', $blocked, array( 'status' => 400 ) );
		}

		$replaced = self::existing( $channel, $prepared['start'], $prepared['end'] );
		self::remove_existing( $channel, $prepared['start'], $prepared['end'] );

		$group = wp_generate_uuid4();
		KDNA_EcommerceInsights_Ad_Spend::insert_rows( $prepared['rows'], 'csv', $group );
		do_action( 'kdna_ei_ad_spend_changed' );

		KDNA_EcommerceInsights_Log::add(
			'adspend',
			$prepared['errors'] ? 'warning' : 'success',
			sprintf(
				/* translators: 1: number of rows, 2: channel, 3: start date, 4: end date, 5: problem rows. */
				__( 'Imported %1$d ad spend rows for %2$s from %3$s to %4$s. %5$d rows had problems.', 'kdna-ecommerce-insights' ),
				$prepared['totals']['rows'],
				$channel,
				$prepared['start'],
				$prepared['end'],
				count( $prepared['errors'] )
			)
		);

		return array(
			'entry_group' => $group,
			'rows'        => $prepared['totals']['rows'],
			'spend'       => $prepared['totals']['spend'],
			'replaced'    => round( $replaced, 2 ),
			'errors'      => count( $prepared['errors'] ),
			'start'       => $prepared['start'],
			'end'         => $prepared['end'],
		);
	}

	/**
	 * Total CSV spend already saved for a channel between two dates.
	 *
	 * @param string $channel Channel key.
	 * @param string $start   Y-m-d.
	 * @param string $end     Y-m-d.
	 * @return float
	 */
	public static function existing( string $channel, string $start, string $end ): float {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( SUM( spend ), 0 ) FROM ' . KDNA_EcommerceInsights_Install::table( 'ad_spend' ) . " WHERE source = 'csv' AND channel = %s AND spend_date BETWEEN %s AND %s", $channel, $start, $end ) );
	}

	/**
	 * Deletes CSV spend for a channel between two dates, before a new import
	 * of the same period. Manual and synced spend is left alone.
	 *
	 * @param string $channel Channel key.
	 * @param string $start   Y-m-d.
	 * @param string $end     Y-m-d.
	 */
	private static function remove_existing( string $channel, string $start, string $end ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . KDNA_EcommerceInsights_Install::table( 'ad_spend' ) . " WHERE source = 'csv' AND channel = %s AND spend_date BETWEEN %s AND %s", $channel, $start, $end ) );
	}

	/**
	 * Saves the current mapping as a named preset in Settings, replacing
	 * one with the same name.
	 *
	 * @param string $name         Preset name.
	 * @param string $channel      Channel key.
	 * @param array  $mapping      Field to column name.
	 * @param bool   $includes_gst Whether amounts include GST.
	 * @return array The saved presets list.
	 */
	public static function save_preset( string $name, string $channel, array $mapping, bool $includes_gst ): array {
		$name    = sanitize_text_field( $name );
		$presets = array_values(
			array_filter(
				(array) KDNA_EcommerceInsights_Settings::get( 'marketing.csv_presets', array() ),
				static fn( $p ) => strtolower( $p['name'] ) !== strtolower( $name )
			)
		);

		$presets[] = array(
			'name'         => $name,
			'channel'      => sanitize_key( $channel ),
			'mapping'      => array_filter( array_intersect_key( $mapping, self::fields() ) ),
			'includes_gst' => $includes_gst,
		);

		KDNA_EcommerceInsights_Settings::update( array( 'marketing' => array( 'csv_presets' => $presets ) ) );
		return self::presets();
	}
}
