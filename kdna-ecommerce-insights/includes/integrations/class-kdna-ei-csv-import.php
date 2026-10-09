<?php
/**
 * CSV reading and writing, plus the product cost CSV import and export.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes CSV files safely.
 *
 * Imports always happen in two steps: preview() checks every row and shows
 * exactly what would change, then only the confirmed changes are saved.
 * Bad rows are listed with a plain-English reason, never silently skipped.
 * Later stages reuse parse() for ad spend CSVs.
 */
class KDNA_EcommerceInsights_Csv_Import {

	/**
	 * Largest CSV accepted, in bytes (5 MB).
	 */
	const MAX_BYTES = 5242880;

	/**
	 * Most data rows accepted in one file.
	 */
	const MAX_ROWS = 20000;

	/**
	 * Column names accepted for each field of the cost CSV (lower case).
	 */
	const COST_COLUMNS = array(
		'id'   => array( 'id', 'product id', 'product_id', 'variation id', 'variation_id' ),
		'sku'  => array( 'sku', 'product sku' ),
		'cost' => array( 'cost', 'cost price', 'cost_price', 'unit cost', 'cogs', 'cost of goods' ),
	);

	/*
	 * ---------------------------------------------------------------------
	 * Generic CSV helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Reads CSV text into a header row and data rows. Works out whether the
	 * file uses commas, semicolons or tabs, and handles quoted values that
	 * contain line breaks.
	 *
	 * @param string $text CSV file contents.
	 * @return array{header: string[], rows: array<int, string[]>}|WP_Error Rows are keyed by their line number in the file.
	 */
	public static function parse( string $text ) {
		if ( strlen( $text ) > self::MAX_BYTES ) {
			return new WP_Error( 'kdna_ei_csv_too_large', __( 'That file is too large. Please upload a CSV under 5 MB.', 'kdna-ecommerce-insights' ) );
		}

		// Remove the invisible byte-order mark Excel adds to the start of UTF-8 files.
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );

		if ( '' === trim( (string) $text ) ) {
			return new WP_Error( 'kdna_ei_csv_empty', __( 'That file is empty.', 'kdna-ecommerce-insights' ) );
		}

		if ( ! seems_utf8( $text ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}

		$first_line = strtok( $text, "\r\n" );
		$delimiter  = ',';
		foreach ( array( ';', "\t" ) as $candidate ) {
			if ( substr_count( (string) $first_line, $candidate ) > substr_count( (string) $first_line, $delimiter ) ) {
				$delimiter = $candidate;
			}
		}

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );

		$header = null;
		$rows   = array();
		$line   = 0;

		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			++$line;

			// Skip completely empty lines.
			if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
				continue;
			}

			if ( null === $header ) {
				$header = array_map(
					static function ( $cell ) {
						return strtolower( trim( (string) $cell ) );
					},
					$cells
				);
				continue;
			}

			if ( count( $rows ) >= self::MAX_ROWS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				/* translators: %s: maximum number of rows. */
				return new WP_Error( 'kdna_ei_csv_too_many_rows', sprintf( __( 'That file has more than %s rows. Please split it into smaller files.', 'kdna-ecommerce-insights' ), number_format_i18n( self::MAX_ROWS ) ) );
			}

			$rows[ $line ] = array_map( 'trim', array_map( 'strval', $cells ) );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( null === $header ) {
			return new WP_Error( 'kdna_ei_csv_no_header', __( 'The file needs a header row naming each column.', 'kdna-ecommerce-insights' ) );
		}

		return array(
			'header' => $header,
			'rows'   => $rows,
		);
	}

	/**
	 * Builds CSV text from a header and rows. Text that a spreadsheet could
	 * mistake for a formula is made safe first.
	 *
	 * @param string[] $header Column names.
	 * @param array[]  $rows   Rows of values.
	 * @return string
	 */
	public static function build( array $header, array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv( $handle, $header, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( array( __CLASS__, 'safe_cell' ), $row ), ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Byte-order mark so Excel opens accented characters correctly.
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Stops a text cell starting with =, +, -, @ or a tab being run as a
	 * formula when the file is opened in Excel or Google Sheets.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function safe_cell( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) && ! is_numeric( $value ) ? "'" . $value : $value;
	}

	/**
	 * Reads a money amount typed in a CSV, such as "12.50", "$12.50",
	 * "1,299.00" or "12,50". Returns null if it is not a number.
	 *
	 * @param string $value Cell value.
	 * @return float|null
	 */
	public static function parse_amount( string $value ): ?float {
		$value = trim( html_entity_decode( $value ) );
		$value = preg_replace( '/[^\d.,\-]/u', '', $value );

		if ( '' === $value || '-' === $value ) {
			return null;
		}

		$has_comma = false !== strpos( $value, ',' );
		$has_dot   = false !== strpos( $value, '.' );

		if ( $has_comma && $has_dot ) {
			// Whichever separator comes last is the decimal point.
			if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
				$value = str_replace( array( '.', ',' ), array( '', '.' ), $value );
			} else {
				$value = str_replace( ',', '', $value );
			}
		} elseif ( $has_comma ) {
			// "12,50" is a decimal comma; "1,299" is a thousands separator.
			$value = preg_match( '/,\d{3}$/', $value ) ? str_replace( ',', '', $value ) : str_replace( ',', '.', $value );
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Finds which column holds a field, trying each accepted column name.
	 *
	 * @param string[] $header  Lower-cased header row.
	 * @param string[] $aliases Accepted column names.
	 * @return int|null Column position, or null if not present.
	 */
	private static function column( array $header, array $aliases ): ?int {
		foreach ( $aliases as $alias ) {
			$index = array_search( $alias, $header, true );
			if ( false !== $index ) {
				return (int) $index;
			}
		}
		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Product cost CSV
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The product cost list: every product and variation with its price and
	 * cost, ready to edit and import again.
	 *
	 * @return array{0: string[], 1: array[]} Header and rows.
	 */
	public static function cost_rows(): array {
		$header = array( 'id', 'parent_id', 'type', 'sku', 'name', 'variation', 'price', 'cost', 'cost_used' );
		$rows   = array();

		foreach ( KDNA_EcommerceInsights_Cost_Catalogue::index() as $parent ) {
			foreach ( array_merge( array( $parent ), $parent['children'] ) as $row ) {
				$rows[] = array(
					$row['id'],
					$row['parent_id'] ? $row['parent_id'] : '',
					$row['type'],
					$row['sku'],
					$row['name'],
					$row['attributes'],
					null === $row['price'] ? '' : wc_format_decimal( $row['price'] ),
					null === $row['own_cost'] ? '' : wc_format_decimal( $row['own_cost'] ),
					null === $row['effective_cost'] ? '' : wc_format_decimal( $row['effective_cost'] ),
				);
			}
		}

		return array( $header, $rows );
	}

	/**
	 * Builds the product cost CSV file (see cost_rows()).
	 *
	 * @return string
	 */
	public static function export_costs(): string {
		list( $header, $rows ) = self::cost_rows();
		return self::build( $header, $rows );
	}

	/**
	 * Checks a product cost CSV and reports, row by row, what importing it
	 * would do. Nothing is saved.
	 *
	 * Rows are matched by the id column when it holds a valid product or
	 * variation ID, otherwise by SKU. A blank cost leaves that product as it is.
	 *
	 * @param string $text CSV file contents.
	 * @return array|WP_Error {
	 *     @type array[] $rows    One result per data row: line, id, sku, name, old, new, status, message.
	 *     @type array[] $changes The changes to save: id and cost.
	 *     @type array   $counts  Number of rows per status.
	 * }
	 */
	public static function preview_costs( string $text ) {
		$parsed = self::parse( $text );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$header   = $parsed['header'];
		$id_col   = self::column( $header, self::COST_COLUMNS['id'] );
		$sku_col  = self::column( $header, self::COST_COLUMNS['sku'] );
		$cost_col = self::column( $header, self::COST_COLUMNS['cost'] );

		if ( null === $cost_col ) {
			return new WP_Error( 'kdna_ei_csv_no_cost', __( 'The file needs a "cost" column. Export a CSV first to see the expected layout.', 'kdna-ecommerce-insights' ) );
		}
		if ( null === $id_col && null === $sku_col ) {
			return new WP_Error( 'kdna_ei_csv_no_match_column', __( 'The file needs an "id" or "sku" column so each row can be matched to a product.', 'kdna-ecommerce-insights' ) );
		}

		$lookup  = KDNA_EcommerceInsights_Cost_Catalogue::lookup();
		$results = array();
		$changes = array();
		$seen    = array();
		$counts  = array(
			'change'    => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'error'     => 0,
		);

		foreach ( $parsed['rows'] as $line => $cells ) {
			$id_value  = null === $id_col ? '' : (string) ( $cells[ $id_col ] ?? '' );
			$sku_value = null === $sku_col ? '' : (string) ( $cells[ $sku_col ] ?? '' );
			$raw_cost  = (string) ( $cells[ $cost_col ] ?? '' );

			$result = array(
				'line'    => $line,
				'id'      => null,
				'sku'     => $sku_value,
				'name'    => '',
				'old'     => null,
				'new'     => null,
				'status'  => 'error',
				'message' => '',
			);

			// Match the row to a product: ID first, then SKU.
			$product_id = 0;
			if ( '' !== $id_value && ctype_digit( $id_value ) && isset( $lookup['ids'][ (int) $id_value ] ) ) {
				$product_id = (int) $id_value;
			}

			$sku_id = '' !== $sku_value ? ( $lookup['skus'][ strtolower( $sku_value ) ] ?? 0 ) : 0;

			if ( $product_id && $sku_id && $sku_id !== $product_id ) {
				/* translators: 1: product ID, 2: SKU. */
				$result['message'] = sprintf( __( 'ID %1$s and SKU %2$s belong to different products. Fix one of them so they match.', 'kdna-ecommerce-insights' ), $id_value, $sku_value );
				$results[]         = $result;
				++$counts['error'];
				continue;
			}

			$product_id = $product_id ? $product_id : $sku_id;

			if ( ! $product_id ) {
				$result['message'] = '' !== $id_value || '' !== $sku_value
					? __( 'No product found with this ID or SKU.', 'kdna-ecommerce-insights' )
					: __( 'This row has no ID or SKU.', 'kdna-ecommerce-insights' );
				$results[]         = $result;
				++$counts['error'];
				continue;
			}

			$row               = $lookup['ids'][ $product_id ];
			$result['id']      = $product_id;
			$result['sku']     = $row['sku'];
			$result['name']    = $row['name'] . ( '' !== $row['attributes'] ? ' (' . $row['attributes'] . ')' : '' );
			$result['old']     = $row['own_cost'];

			if ( isset( $seen[ $product_id ] ) ) {
				/* translators: %d: earlier line number. */
				$result['message'] = sprintf( __( 'This product already appears on line %d. Only the first row is used.', 'kdna-ecommerce-insights' ), $seen[ $product_id ] );
				$results[]         = $result;
				++$counts['error'];
				continue;
			}
			$seen[ $product_id ] = $line;

			if ( '' === trim( $raw_cost ) ) {
				$result['status']  = 'skipped';
				$result['message'] = __( 'Cost left blank, so this product is not changed.', 'kdna-ecommerce-insights' );
				$results[]         = $result;
				++$counts['skipped'];
				continue;
			}

			$cost = self::parse_amount( $raw_cost );
			if ( null === $cost ) {
				/* translators: %s: value found in the cost column. */
				$result['message'] = sprintf( __( '"%s" is not a number.', 'kdna-ecommerce-insights' ), $raw_cost );
				$results[]         = $result;
				++$counts['error'];
				continue;
			}
			if ( $cost < 0 ) {
				$result['message'] = __( 'Cost cannot be negative.', 'kdna-ecommerce-insights' );
				$results[]         = $result;
				++$counts['error'];
				continue;
			}

			$cost          = round( $cost, 4 );
			$result['new'] = $cost;

			if ( KDNA_EcommerceInsights_Costs::same( $row['own_cost'], $cost ) ) {
				$result['status']  = 'unchanged';
				$result['message'] = __( 'Same as the current cost.', 'kdna-ecommerce-insights' );
				++$counts['unchanged'];
			} else {
				$result['status']  = 'change';
				$result['message'] = '';
				$changes[]         = array(
					'id'   => $product_id,
					'cost' => $cost,
				);
				++$counts['change'];
			}

			$results[] = $result;
		}

		return array(
			'rows'    => $results,
			'changes' => $changes,
			'counts'  => $counts,
		);
	}
}
