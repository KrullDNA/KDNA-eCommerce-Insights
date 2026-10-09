<?php
/**
 * REST route: CSV export of any report table.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route (Administrators only):
 * - GET /export?table=...   Returns { filename, csv } for a report table,
 *                           using the same range and filters as the screen.
 *
 * Tables: summary, timeseries, pnl, products, categories, customers, locations,
 * channels, campaigns, low_stock, out_of_stock, days_of_cover, dead_stock, tax.
 * Product costs are exported from /costs/export.
 */
class KDNA_EcommerceInsights_Rest_Export extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Tables that can be exported.
	 */
	const TABLES = array( 'summary', 'timeseries', 'pnl', 'products', 'categories', 'customers', 'locations', 'channels', 'campaigns', 'low_stock', 'out_of_stock', 'days_of_cover', 'dead_stock', 'tax' );

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array_merge(
					$this->range_args(),
					KDNA_EcommerceInsights_Rest_Products::table_args(),
					array(
						'table'   => array(
							'type'     => 'string',
							'enum'     => self::TABLES,
							'required' => true,
						),
						'metrics' => array( 'type' => 'string' ),
					)
				),
			)
		);
	}

	/**
	 * Builds the CSV for the requested table.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range, $compare ) = $ranges;

		$table = (string) $request['table'];
		list( $header, $rows ) = $this->table( $table, $range, $compare, $request );

		return rest_ensure_response(
			array(
				'filename' => sprintf( 'kdna-insights-%s-%s-to-%s.csv', str_replace( '_', '-', $table ), $range['start'], $range['end'] ),
				'csv'      => KDNA_EcommerceInsights_Csv_Import::build( $header, $rows ),
			)
		);
	}

	/**
	 * Works out the header and rows for a table.
	 *
	 * @param string          $table   Table name.
	 * @param array           $range   Range.
	 * @param array|null      $compare Comparison range.
	 * @param WP_REST_Request $request Incoming request.
	 * @return array{0: string[], 1: array[]}
	 */
	private function table( string $table, array $range, ?array $compare, WP_REST_Request $request ): array {
		$money = static fn( $value ) => null === $value ? '' : wc_format_decimal( $value, 2 );

		switch ( $table ) {
			case 'summary':
				$totals   = KDNA_EcommerceInsights_Report::totals( $range );
				$previous = $compare ? KDNA_EcommerceInsights_Report::totals( $compare ) : null;
				$rows     = array();
				foreach ( KDNA_EcommerceInsights_Metrics::all() as $key => $metric ) {
					if ( in_array( $metric['group'], array( 'inventory', 'customers' ), true ) && ! in_array( $key, array( 'customers', 'new_customers', 'returning_customers', 'new_customer_revenue', 'returning_customer_revenue' ), true ) ) {
						continue;
					}
					$result = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $previous );
					$rows[] = array( $metric['label'], $money( $result['value'] ), $money( $result['previous'] ), null === $result['change'] ? '' : $result['change'] );
				}
				return array( array( __( 'Metric', 'kdna-ecommerce-insights' ), __( 'This period', 'kdna-ecommerce-insights' ), __( 'Comparison period', 'kdna-ecommerce-insights' ), __( 'Change', 'kdna-ecommerce-insights' ) ), $rows );

			case 'timeseries':
				$metrics = $this->metric_list( $request['metrics'], array( 'net_revenue', 'net_profit', 'orders' ) );
				$series  = KDNA_EcommerceInsights_Report::series( $range, KDNA_EcommerceInsights_Dates::auto_granularity( $range ), $metrics );
				$header  = array_merge( array( __( 'From', 'kdna-ecommerce-insights' ), __( 'To', 'kdna-ecommerce-insights' ) ), array_map( static fn( $key ) => KDNA_EcommerceInsights_Metrics::get( $key )['label'], $metrics ) );
				$rows    = array();
				foreach ( $series['buckets'] as $i => $bucket ) {
					$row = array( $bucket['start'], $bucket['end'] );
					foreach ( $metrics as $key ) {
						$row[] = $money( $series['series'][ $key ][ $i ] );
					}
					$rows[] = $row;
				}
				return array( $header, $rows );

			case 'pnl':
				$profit = KDNA_EcommerceInsights_Report::profit( $range );
				$header = array( __( 'Line', 'kdna-ecommerce-insights' ) );
				foreach ( $profit['months'] as $month ) {
					$header[] = substr( $month['start'], 0, 7 );
				}
				$header[] = __( 'Total', 'kdna-ecommerce-insights' );
				$rows     = array();
				foreach ( $profit['waterfall'] as $line ) {
					$row = array( $line['label'] );
					foreach ( $profit['months'] as $month ) {
						$row[] = $money( $month['lines'][ $line['key'] ] ?? 0 );
					}
					$row[]  = $money( $line['amount'] );
					$rows[] = $row;
				}

				// Net margin by month, as a percentage.
				$row = array( __( 'Net margin %', 'kdna-ecommerce-insights' ) );
				foreach ( $profit['months'] as $month ) {
					$row[] = null === $month['lines']['net_margin'] ? '' : round( $month['lines']['net_margin'], 1 );
				}
				$row[]  = null === $profit['margins']['net'] ? '' : round( $profit['margins']['net'], 1 );
				$rows[] = $row;
				return array( $header, $rows );

			case 'products':
				$args = array();
				foreach ( array_keys( KDNA_EcommerceInsights_Rest_Products::table_args() ) as $key ) {
					$args[ $key ] = $request[ $key ];
				}
				$args['page']     = 1;
				$args['per_page'] = 200;
				$rows             = array();
				do {
					$result = KDNA_EcommerceInsights_Report::products( $range, $args );
					foreach ( $result['rows'] as $item ) {
						$rows[] = array( $item['name'], $item['variation'], $item['units'], $item['orders'], $money( $item['revenue'] ), $money( $item['cost'] ), $money( $item['profit'] ), null === $item['margin'] ? '' : round( $item['margin'], 1 ), null === $item['refund_rate'] ? '' : round( $item['refund_rate'], 1 ) );
					}
					++$args['page'];
				} while ( $args['page'] <= $result['pages'] );
				return array( array( __( 'Product', 'kdna-ecommerce-insights' ), __( 'Variation', 'kdna-ecommerce-insights' ), __( 'Units', 'kdna-ecommerce-insights' ), __( 'Orders', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ), __( 'Cost', 'kdna-ecommerce-insights' ), __( 'Profit', 'kdna-ecommerce-insights' ), __( 'Margin %', 'kdna-ecommerce-insights' ), __( 'Refund rate %', 'kdna-ecommerce-insights' ) ), $rows );

			case 'categories':
				$result = KDNA_EcommerceInsights_Report::products( $range, array( 'per_page' => 1 ) );
				$rows   = array_map( static fn( $c ) => array( $c['name'], $c['units'], $money( $c['revenue'] ), $money( $c['profit'] ), null === $c['margin'] ? '' : round( $c['margin'], 1 ) ), $result['categories'] );
				return array( array( __( 'Category', 'kdna-ecommerce-insights' ), __( 'Units', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ), __( 'Profit', 'kdna-ecommerce-insights' ), __( 'Margin %', 'kdna-ecommerce-insights' ) ), $rows );

			case 'customers':
			case 'locations':
				$data = KDNA_EcommerceInsights_Report::customers( $range, null );
				if ( 'customers' === $table ) {
					$rows = array_map( static fn( $c ) => array( $c['name'], $c['email'], $c['orders'], $money( $c['revenue'] ), $money( $c['profit'] ) ), $data['top_customers'] );
					return array( array( __( 'Customer', 'kdna-ecommerce-insights' ), __( 'Email', 'kdna-ecommerce-insights' ), __( 'Orders', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ), __( 'Profit', 'kdna-ecommerce-insights' ) ), $rows );
				}
				$rows = array_map( static fn( $l ) => array( $l['country_name'], $l['state_name'], $l['orders'], $l['customers'], $money( $l['revenue'] ) ), $data['locations'] );
				return array( array( __( 'Country', 'kdna-ecommerce-insights' ), __( 'State', 'kdna-ecommerce-insights' ), __( 'Orders', 'kdna-ecommerce-insights' ), __( 'Customers', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ) ), $rows );

			case 'channels':
			case 'campaigns':
				$data = KDNA_EcommerceInsights_Report::marketing( $range, null );
				$rows = array_map( static fn( $c ) => array( $c['label'], $c['campaign_name'] ?? '', $money( $c['spend'] ), $c['impressions'], $c['clicks'], $c['conversions'], $money( $c['conversion_value'] ), $c['roas'] ), $data[ $table ] );
				return array( array( __( 'Channel', 'kdna-ecommerce-insights' ), __( 'Campaign', 'kdna-ecommerce-insights' ), __( 'Spend', 'kdna-ecommerce-insights' ), __( 'Impressions', 'kdna-ecommerce-insights' ), __( 'Clicks', 'kdna-ecommerce-insights' ), __( 'Conversions', 'kdna-ecommerce-insights' ), __( 'Conversion value', 'kdna-ecommerce-insights' ), __( 'ROAS', 'kdna-ecommerce-insights' ) ), $rows );

			case 'tax':
				$data = KDNA_EcommerceInsights_Report::tax( $range );
				$rows = array_map( static fn( $p ) => array( $p['start'], $p['end'], $money( $p['bas_g1'] ), $money( $p['bas_1a'] ), $money( $p['bas_1b'] ), $money( $p['net_gst'] ) ), $data['periods'] );
				return array( array( __( 'From', 'kdna-ecommerce-insights' ), __( 'To', 'kdna-ecommerce-insights' ), __( 'G1 Total sales', 'kdna-ecommerce-insights' ), __( '1A GST on sales', 'kdna-ecommerce-insights' ), __( '1B GST on purchases (estimate)', 'kdna-ecommerce-insights' ), __( 'Net GST', 'kdna-ecommerce-insights' ) ), $rows );

			default:
				$data = KDNA_EcommerceInsights_Report::inventory();
				$rows = array_map(
					static fn( $item ) => array( $item['name'], $item['sku'], $item['stock'], $item['sold_30'], $item['last_sale'], $item['days'] ?? '', $item['runs_out'] ?? '', $item['reorder'] ?? '' ),
					$data[ $table ] ?? array()
				);
				return array( array( __( 'Product', 'kdna-ecommerce-insights' ), __( 'SKU', 'kdna-ecommerce-insights' ), __( 'Stock', 'kdna-ecommerce-insights' ), __( 'Sold in last 30 days', 'kdna-ecommerce-insights' ), __( 'Last sale', 'kdna-ecommerce-insights' ), __( 'Days of stock left', 'kdna-ecommerce-insights' ), __( 'Runs out', 'kdna-ecommerce-insights' ), __( 'Reorder by', 'kdna-ecommerce-insights' ) ), $rows );
		}
	}
}
