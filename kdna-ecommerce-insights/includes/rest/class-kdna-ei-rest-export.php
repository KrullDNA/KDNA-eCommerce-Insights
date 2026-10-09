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
 * Every table in the app can be exported. catalogue() lists them by
 * screen, for the top bar Export menu and the Tax & Reports screen.
 */
class KDNA_EcommerceInsights_Rest_Export extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Tables that can be exported.
	 */
	const TABLES = array( 'summary', 'timeseries', 'top_products', 'stock_status', 'pnl', 'cost_breakdown', 'margin_trend', 'products', 'categories', 'variations', 'customer_trend', 'customers', 'cohorts', 'locations', 'low_stock', 'out_of_stock', 'days_of_cover', 'dead_stock', 'stock_trend', 'spend_by_channel', 'channels', 'campaigns', 'adspend', 'product_costs', 'fee_rules', 'shipping_rules', 'extra_costs', 'overheads', 'tax', 'activity' );

	/**
	 * Every exportable table, grouped by the screen it appears on, with a
	 * plain-English name. Shown in the top bar Export menu and on the Tax &
	 * Reports screen.
	 *
	 * @return array<string, array<string, string>> Screen ID => table => name.
	 */
	public static function catalogue(): array {
		return array(
			'overview'  => array(
				'summary'      => __( 'Key figures', 'kdna-ecommerce-insights' ),
				'timeseries'   => __( 'Performance over time', 'kdna-ecommerce-insights' ),
				'top_products' => __( 'Top products', 'kdna-ecommerce-insights' ),
				'stock_status' => __( 'Inventory status', 'kdna-ecommerce-insights' ),
			),
			'profit'    => array(
				'pnl'            => __( 'Profit and loss statement', 'kdna-ecommerce-insights' ),
				'cost_breakdown' => __( 'Cost breakdown', 'kdna-ecommerce-insights' ),
				'margin_trend'   => __( 'Margin trend', 'kdna-ecommerce-insights' ),
			),
			'products'  => array(
				'products'   => __( 'Product performance', 'kdna-ecommerce-insights' ),
				'categories' => __( 'Categories', 'kdna-ecommerce-insights' ),
			),
			'customers' => array(
				'customer_trend' => __( 'New and returning customers', 'kdna-ecommerce-insights' ),
				'customers'      => __( 'Top customers', 'kdna-ecommerce-insights' ),
				'cohorts'        => __( 'Cohort retention', 'kdna-ecommerce-insights' ),
				'locations'      => __( 'Locations', 'kdna-ecommerce-insights' ),
			),
			'inventory' => array(
				'stock_status'  => __( 'Stock status', 'kdna-ecommerce-insights' ),
				'low_stock'     => __( 'Low stock', 'kdna-ecommerce-insights' ),
				'out_of_stock'  => __( 'Out of stock', 'kdna-ecommerce-insights' ),
				'days_of_cover' => __( 'Days of stock left', 'kdna-ecommerce-insights' ),
				'dead_stock'    => __( 'Dead stock', 'kdna-ecommerce-insights' ),
				'stock_trend'   => __( 'Stock value trend', 'kdna-ecommerce-insights' ),
			),
			'marketing' => array(
				'spend_by_channel' => __( 'Ad spend by channel over time', 'kdna-ecommerce-insights' ),
				'channels'         => __( 'Channels', 'kdna-ecommerce-insights' ),
				'campaigns'        => __( 'Campaigns', 'kdna-ecommerce-insights' ),
				'adspend'          => __( 'Spend entries', 'kdna-ecommerce-insights' ),
			),
			'costs'     => array(
				'product_costs'  => __( 'Product costs', 'kdna-ecommerce-insights' ),
				'fee_rules'      => __( 'Payment fee rules', 'kdna-ecommerce-insights' ),
				'shipping_rules' => __( 'Shipping cost rules', 'kdna-ecommerce-insights' ),
				'extra_costs'    => __( 'Extra order costs', 'kdna-ecommerce-insights' ),
				'overheads'      => __( 'Overheads', 'kdna-ecommerce-insights' ),
			),
			'reports'   => array(
				'tax' => __( 'Tax summary', 'kdna-ecommerce-insights' ),
			),
			'settings'  => array(
				'activity' => __( 'Recent activity', 'kdna-ecommerce-insights' ),
			),
		);
	}

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
					KDNA_EcommerceInsights_Rest_Tax::tax_args(),
					array(
						'product' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
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
		if ( 'tax' === $table ) {
			list( $range ) = KDNA_EcommerceInsights_Rest_Tax::tax_range( $request, $range );
		}
		list( $header, $rows ) = $this->table( $table, $range, $compare, $request );

		// Settings and cost tables are not tied to dates.
		if ( in_array( $table, array( 'product_costs', 'fee_rules', 'shipping_rules', 'extra_costs', 'overheads', 'activity', 'stock_status' ), true ) ) {
			return rest_ensure_response(
				array(
					'filename' => sprintf( 'kdna-insights-%s-%s.csv', str_replace( '_', '-', $table ), wp_date( 'Y-m-d' ) ),
					'csv'      => KDNA_EcommerceInsights_Csv_Import::build( $header, $rows ),
				)
			);
		}

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

			case 'cohorts':
				$cohorts = KDNA_EcommerceInsights_Report::cohorts( $range );
				$width   = $cohorts ? max( array_map( static fn( $c ) => count( $c['retention'] ), $cohorts ) ) : 0;
				$header  = array( __( 'First order month', 'kdna-ecommerce-insights' ), __( 'Customers', 'kdna-ecommerce-insights' ) );
				for ( $i = 0; $i < $width; $i++ ) {
					/* translators: %d: months after the first order. */
					$header[] = 0 === $i ? __( 'Month 0 %', 'kdna-ecommerce-insights' ) : sprintf( __( 'Month %d %%', 'kdna-ecommerce-insights' ), $i );
				}
				$rows = array_map( static fn( $c ) => array_merge( array( $c['month'], $c['size'] ), $c['retention'] ), $cohorts );
				return array( $header, $rows );

			case 'customers':
			case 'locations':
				$data = KDNA_EcommerceInsights_Report::customers( $range, null );
				if ( 'customers' === $table ) {
					$rows = array_map( static fn( $c ) => array( $c['name'], $c['email'], $c['guest'] ? __( 'Guest', 'kdna-ecommerce-insights' ) : __( 'Account', 'kdna-ecommerce-insights' ), $c['orders'], $money( $c['revenue'] ), $money( $c['profit'] ), $c['lifetime_orders'], $money( $c['lifetime_revenue'] ), $c['first_order'] ), $data['top_customers'] );
					return array( array( __( 'Customer', 'kdna-ecommerce-insights' ), __( 'Email', 'kdna-ecommerce-insights' ), __( 'Type', 'kdna-ecommerce-insights' ), __( 'Orders', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ), __( 'Profit', 'kdna-ecommerce-insights' ), __( 'Lifetime orders', 'kdna-ecommerce-insights' ), __( 'Lifetime revenue', 'kdna-ecommerce-insights' ), __( 'First order', 'kdna-ecommerce-insights' ) ), $rows );
				}
				$rows = array_map( static fn( $l ) => array( $l['country_name'], $l['state_name'], $l['orders'], $l['customers'], $money( $l['revenue'] ) ), $data['locations'] );
				return array( array( __( 'Country', 'kdna-ecommerce-insights' ), __( 'State', 'kdna-ecommerce-insights' ), __( 'Orders', 'kdna-ecommerce-insights' ), __( 'Customers', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ) ), $rows );

			case 'channels':
			case 'campaigns':
				$data = KDNA_EcommerceInsights_Report::marketing( $range, null );
				$rows = array_map( static fn( $c ) => array( $c['label'], $c['campaign_name'] ?? '', $money( $c['spend'] ), $c['impressions'], $c['clicks'], $c['conversions'], $money( $c['conversion_value'] ), $c['roas'] ), $data[ $table ] );
				return array( array( __( 'Channel', 'kdna-ecommerce-insights' ), __( 'Campaign', 'kdna-ecommerce-insights' ), __( 'Spend', 'kdna-ecommerce-insights' ), __( 'Impressions', 'kdna-ecommerce-insights' ), __( 'Clicks', 'kdna-ecommerce-insights' ), __( 'Conversions', 'kdna-ecommerce-insights' ), __( 'Conversion value', 'kdna-ecommerce-insights' ), __( 'ROAS', 'kdna-ecommerce-insights' ) ), $rows );

			case 'adspend':
				$labels = KDNA_EcommerceInsights_Report::channel_labels();
				$rows   = array_map( static fn( $e ) => array( $e['start'], $e['end'], $labels[ $e['channel'] ] ?? $e['channel'], $e['campaigns'] > 1 ? $e['campaigns'] . ' ' . __( 'campaigns', 'kdna-ecommerce-insights' ) : $e['campaign_name'], $money( $e['amount'] ), $e['includes_gst'] ? __( 'Yes', 'kdna-ecommerce-insights' ) : __( 'No', 'kdna-ecommerce-insights' ), $e['source'] ), KDNA_EcommerceInsights_Ad_Spend::entries( $range['start'], $range['end'] ) );
				return array( array( __( 'From', 'kdna-ecommerce-insights' ), __( 'To', 'kdna-ecommerce-insights' ), __( 'Channel', 'kdna-ecommerce-insights' ), __( 'Campaign', 'kdna-ecommerce-insights' ), __( 'Amount', 'kdna-ecommerce-insights' ), __( 'Includes GST', 'kdna-ecommerce-insights' ), __( 'Source', 'kdna-ecommerce-insights' ) ), $rows );

			case 'tax':
				$data   = KDNA_EcommerceInsights_Tax::summary( $range, (string) ( $request['period'] ? $request['period'] : '' ) );
				$labels = $data['labels'];
				$rows   = array_map( static fn( $p ) => array( $p['period'], $p['start'], $p['end'], $p['partial'] ? __( 'Yes', 'kdna-ecommerce-insights' ) : __( 'No', 'kdna-ecommerce-insights' ), $money( $p['sales'] ), $money( $p['on_sales'] ), $money( $p['on_costs'] ), $money( $p['costs']['overheads'] ), $money( $p['costs']['ad_spend'] ), $money( $p['net'] ) ), $data['periods'] );
				$t      = $data['totals'];
				$rows[] = array( __( 'Total', 'kdna-ecommerce-insights' ), $range['start'], $range['end'], '', $money( $t['sales'] ), $money( $t['on_sales'] ), $money( $t['on_costs'] ), $money( $t['costs']['overheads'] ), $money( $t['costs']['ad_spend'] ), $money( $t['net'] ) );
				$rows[] = array( $data['note'] );
				return array( array( __( 'Period', 'kdna-ecommerce-insights' ), __( 'From', 'kdna-ecommerce-insights' ), __( 'To', 'kdna-ecommerce-insights' ), __( 'Part period', 'kdna-ecommerce-insights' ), $labels['sales'], $labels['on_sales'], $labels['on_costs'], __( 'of which overheads', 'kdna-ecommerce-insights' ), __( 'of which ad spend', 'kdna-ecommerce-insights' ), $labels['net'] ), $rows );

			case 'top_products':
				$result = KDNA_EcommerceInsights_Report::products( $range, array( 'per_page' => 10, 'orderby' => 'profit', 'order' => 'desc' ) );
				$rows   = array_map( static fn( $item ) => array( $item['name'], $item['units'], $money( $item['revenue'] ), $money( $item['profit'] ), null === $item['margin'] ? '' : round( $item['margin'], 1 ) ), $result['rows'] );
				return array( array( __( 'Product', 'kdna-ecommerce-insights' ), __( 'Units', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ), __( 'Profit', 'kdna-ecommerce-insights' ), __( 'Margin %', 'kdna-ecommerce-insights' ) ), $rows );

			case 'stock_status':
				$data  = KDNA_EcommerceInsights_Inventory::report();
				$names = array(
					'in_stock'     => __( 'In stock', 'kdna-ecommerce-insights' ),
					'low_stock'    => __( 'Low stock', 'kdna-ecommerce-insights' ),
					'out_of_stock' => __( 'Out of stock', 'kdna-ecommerce-insights' ),
				);
				$all   = max( 1, array_sum( $data['status'] ) );
				$rows  = array();
				foreach ( $names as $key => $name ) {
					$rows[] = array( $name, $data['status'][ $key ], round( $data['status'][ $key ] / $all * 100, 1 ) );
				}
				return array( array( __( 'Status', 'kdna-ecommerce-insights' ), __( 'Products', 'kdna-ecommerce-insights' ), __( 'Share %', 'kdna-ecommerce-insights' ) ), $rows );

			case 'cost_breakdown':
				$profit = KDNA_EcommerceInsights_Report::profit( $range );
				$rows   = array_map( static fn( $c ) => array( $c['label'], $money( $c['amount'] ), $c['share'] ), $profit['cost_breakdown'] );
				return array( array( __( 'Cost', 'kdna-ecommerce-insights' ), __( 'Amount', 'kdna-ecommerce-insights' ), __( 'Share of costs %', 'kdna-ecommerce-insights' ) ), $rows );

			case 'margin_trend':
			case 'customer_trend':
				$metrics = 'margin_trend' === $table ? array( 'gross_margin', 'net_margin' ) : array( 'new_customers', 'returning_customers', 'new_customer_revenue', 'returning_customer_revenue' );
				$series  = KDNA_EcommerceInsights_Report::series( $range, KDNA_EcommerceInsights_Dates::auto_granularity( $range ), $metrics );
				$header  = array_merge( array( __( 'From', 'kdna-ecommerce-insights' ), __( 'To', 'kdna-ecommerce-insights' ) ), array_map( static fn( $key ) => KDNA_EcommerceInsights_Metrics::get( $key )['label'], $metrics ) );
				$rows    = array();
				foreach ( $series['buckets'] as $i => $bucket ) {
					$row = array( $bucket['start'], $bucket['end'] );
					foreach ( $metrics as $key ) {
						$value = $series['series'][ $key ][ $i ];
						$row[] = null === $value ? '' : round( (float) $value, 2 );
					}
					$rows[] = $row;
				}
				return array( $header, $rows );

			case 'variations':
				$detail = KDNA_EcommerceInsights_Report::product_detail( $range, null, (int) $request['product'] );
				$rows   = array_map( static fn( $v ) => array( $detail['product']['name'] ?? '', $v['name'], $v['units'], $money( $v['revenue'] ), $money( $v['profit'] ), null === $v['margin'] ? '' : round( $v['margin'], 1 ) ), $detail['variations'] );
				return array( array( __( 'Product', 'kdna-ecommerce-insights' ), __( 'Variation', 'kdna-ecommerce-insights' ), __( 'Units', 'kdna-ecommerce-insights' ), __( 'Revenue', 'kdna-ecommerce-insights' ), __( 'Profit', 'kdna-ecommerce-insights' ), __( 'Margin %', 'kdna-ecommerce-insights' ) ), $rows );

			case 'stock_trend':
				$rows = array_map( static fn( $d ) => array( $d['day'], $d['units'], $money( $d['cost'] ), $money( $d['retail'] ) ), KDNA_EcommerceInsights_Inventory::trend( $range ) );
				return array( array( __( 'Date', 'kdna-ecommerce-insights' ), __( 'Units in stock', 'kdna-ecommerce-insights' ), __( 'Value at cost', 'kdna-ecommerce-insights' ), __( 'Value at retail', 'kdna-ecommerce-insights' ) ), $rows );

			case 'spend_by_channel':
				$data   = KDNA_EcommerceInsights_Report::marketing( $range, null );
				$labels = KDNA_EcommerceInsights_Report::channel_labels();
				$keys   = array_keys( $data['series'] );
				$header = array_merge( array( __( 'From', 'kdna-ecommerce-insights' ), __( 'To', 'kdna-ecommerce-insights' ) ), array_map( static fn( $key ) => $labels[ $key ] ?? ucfirst( $key ), $keys ), array( __( 'Total', 'kdna-ecommerce-insights' ) ) );
				$rows   = array();
				foreach ( $data['buckets'] as $i => $bucket ) {
					$row   = array( $bucket['start'], $bucket['end'] );
					$total = 0.0;
					foreach ( $keys as $key ) {
						$row[]  = $money( $data['series'][ $key ][ $i ] );
						$total += $data['series'][ $key ][ $i ];
					}
					$row[]  = $money( $total );
					$rows[] = $row;
				}
				return array( $header, $rows );

			case 'product_costs':
				return KDNA_EcommerceInsights_Csv_Import::cost_rows();

			case 'fee_rules':
				$rules = (array) KDNA_EcommerceInsights_Settings::get( 'costs.gateway_fees', array() );
				$rows  = array();
				foreach ( KDNA_EcommerceInsights_Fees::installed_gateways() as $gateway ) {
					$rule   = $rules[ $gateway['id'] ] ?? array();
					$rows[] = array( $gateway['title'], $gateway['id'], $gateway['enabled'] ? __( 'Yes', 'kdna-ecommerce-insights' ) : __( 'No', 'kdna-ecommerce-insights' ), $gateway['reads_actual'] ? __( 'Yes', 'kdna-ecommerce-insights' ) : __( 'No', 'kdna-ecommerce-insights' ), (float) ( $rule['percent'] ?? 0 ), $money( (float) ( $rule['fixed'] ?? 0 ) ) );
				}
				return array( array( __( 'Payment method', 'kdna-ecommerce-insights' ), __( 'ID', 'kdna-ecommerce-insights' ), __( 'Enabled', 'kdna-ecommerce-insights' ), __( 'Reads the actual fee', 'kdna-ecommerce-insights' ), __( 'Estimate: percentage', 'kdna-ecommerce-insights' ), __( 'Estimate: fixed fee', 'kdna-ecommerce-insights' ) ), $rows );

			case 'shipping_rules':
				$rules = array();
				foreach ( (array) KDNA_EcommerceInsights_Settings::get( 'costs.shipping_rules', array() ) as $rule ) {
					$rules[ $rule['method'] ] = $rule;
				}
				$types = array(
					'none'            => __( 'No cost', 'kdna-ecommerce-insights' ),
					'fixed'           => __( 'Fixed amount', 'kdna-ecommerce-insights' ),
					'percent'         => __( 'Percentage of order', 'kdna-ecommerce-insights' ),
					'per_item'        => __( 'Per item', 'kdna-ecommerce-insights' ),
					'same_as_charged' => __( 'Same as charged', 'kdna-ecommerce-insights' ),
				);
				$rows  = array();
				$shown = array();
				foreach ( KDNA_EcommerceInsights_Shipping::configured_methods() as $method ) {
					$rule    = $rules[ $method['key'] ] ?? $rules[ $method['method_id'] ] ?? null;
					$shown[] = $method['key'];
					$rows[]  = array( $method['zone'], $method['title'], $types[ $rule['type'] ?? 'none' ] ?? '', $rule ? $money( $rule['amount'] ) : '', $rule ? $money( $rule['per_kg'] ) : '' );
				}
				if ( isset( $rules['*'] ) ) {
					$rows[] = array( __( 'Every other method', 'kdna-ecommerce-insights' ), '', $types[ $rules['*']['type'] ] ?? '', $money( $rules['*']['amount'] ), $money( $rules['*']['per_kg'] ) );
				}
				return array( array( __( 'Zone', 'kdna-ecommerce-insights' ), __( 'Method', 'kdna-ecommerce-insights' ), __( 'Cost is', 'kdna-ecommerce-insights' ), __( 'Amount', 'kdna-ecommerce-insights' ), __( 'Plus per kg', 'kdna-ecommerce-insights' ) ), $rows );

			case 'extra_costs':
				$rows = array_map( static fn( $c ) => array( $c['label'], 'percent' === $c['type'] ? __( 'Percentage of order', 'kdna-ecommerce-insights' ) : __( 'Fixed amount per order', 'kdna-ecommerce-insights' ), 'percent' === $c['type'] ? (float) $c['amount'] : $money( $c['amount'] ) ), (array) KDNA_EcommerceInsights_Settings::get( 'costs.extra_costs', array() ) );
				return array( array( __( 'Cost', 'kdna-ecommerce-insights' ), __( 'Type', 'kdna-ecommerce-insights' ), __( 'Amount', 'kdna-ecommerce-insights' ) ), $rows );

			case 'overheads':
				$categories  = KDNA_EcommerceInsights_Overheads::categories();
				$frequencies = KDNA_EcommerceInsights_Overheads::frequency_labels();
				$rows        = array_map( static fn( $o ) => array( $o['name'], $categories[ $o['category'] ] ?? $o['category'], $money( $o['amount'] ), $frequencies[ $o['frequency'] ] ?? $o['frequency'], $o['start_date'], (string) $o['end_date'], $o['includes_gst'] ? __( 'Yes', 'kdna-ecommerce-insights' ) : __( 'No', 'kdna-ecommerce-insights' ) ), KDNA_EcommerceInsights_Overheads::all() );
				return array( array( __( 'Name', 'kdna-ecommerce-insights' ), __( 'Category', 'kdna-ecommerce-insights' ), __( 'Amount', 'kdna-ecommerce-insights' ), __( 'How often', 'kdna-ecommerce-insights' ), __( 'Starts', 'kdna-ecommerce-insights' ), __( 'Ends', 'kdna-ecommerce-insights' ), __( 'Includes GST', 'kdna-ecommerce-insights' ) ), $rows );

			case 'activity':
				$rows = array_map( static fn( $l ) => array( get_date_from_gmt( $l['finished_at'], 'Y-m-d H:i' ), $l['type'], $l['status'], $l['message'] ), KDNA_EcommerceInsights_Log::recent( 500 ) );
				return array( array( __( 'When', 'kdna-ecommerce-insights' ), __( 'Type', 'kdna-ecommerce-insights' ), __( 'Result', 'kdna-ecommerce-insights' ), __( 'What happened', 'kdna-ecommerce-insights' ) ), $rows );

			default:
				$data = KDNA_EcommerceInsights_Report::inventory( $range );
				$rows = array_map(
					static fn( $item ) => array( $item['name'], $item['sku'], $item['stock'] ?? '', $item['sold_30'], $item['last_sale'], $item['days'] ?? '', $item['runs_out'] ?? '', $item['reorder'] ?? '', $money( $item['value_cost'] ?? null ), $money( $item['value_retail'] ?? null ) ),
					$data[ $table ] ?? array()
				);
				return array( array( __( 'Product', 'kdna-ecommerce-insights' ), __( 'SKU', 'kdna-ecommerce-insights' ), __( 'Stock', 'kdna-ecommerce-insights' ), __( 'Sold in last 30 days', 'kdna-ecommerce-insights' ), __( 'Last sale', 'kdna-ecommerce-insights' ), __( 'Days of stock left', 'kdna-ecommerce-insights' ), __( 'Runs out', 'kdna-ecommerce-insights' ), __( 'Reorder by', 'kdna-ecommerce-insights' ), __( 'Value at cost', 'kdna-ecommerce-insights' ), __( 'Value at retail', 'kdna-ecommerce-insights' ) ), $rows );
		}
	}
}
