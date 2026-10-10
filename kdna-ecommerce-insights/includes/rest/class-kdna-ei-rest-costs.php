<?php
/**
 * REST routes for the Costs screen product cost editor.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the Costs screen list products, save costs in bulk, export and import
 * cost CSVs, and import costs from other cost plugins.
 *
 * Routes (all under /wp-json/kdna-ei/v1, Administrators only):
 * - GET  /costs                  One page of products with price, cost and margin.
 * - POST /costs                  Save many costs at once.
 * - GET  /costs/export           Product cost CSV.
 * - POST /costs/import/preview   Check a CSV and list what would change.
 * - GET  /costs/plugin-import    Which other cost plugins have costs to import.
 * - POST /costs/plugin-import    Import one batch from another cost plugin.
 */
class KDNA_EcommerceInsights_Rest_Costs {

	/**
	 * Most cost changes accepted in one save request.
	 */
	const MAX_ITEMS = 500;

	/**
	 * Registers every costs route.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/costs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_costs' ),
					'permission_callback' => $permission,
					'args'                => array(
						'search'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'category'     => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
						'missing'      => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'stock_status' => array(
							'type'    => 'string',
							'default' => '',
							'enum'    => array( '', 'instock', 'outofstock', 'onbackorder' ),
						),
						'page'         => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page'     => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 200,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_costs' ),
					'permission_callback' => $permission,
					'args'                => array(
						'items' => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => self::MAX_ITEMS,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'id'   => array(
										'type'     => 'integer',
										'required' => true,
									),
									'cost' => array(
										'type' => array( 'number', 'null' ),
									),
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/costs/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_csv' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/costs/import/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_import' ),
				'permission_callback' => $permission,
				'args'                => array(
					'csv' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => KDNA_EcommerceInsights_Csv_Import::MAX_BYTES,
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/costs/plugin-import',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'detect_plugins' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_from_plugin' ),
					'permission_callback' => $permission,
					'args'                => array(
						'source'    => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array_keys( KDNA_EcommerceInsights_Cost_Plugin_Import::sources() ),
						),
						'offset'    => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
						'overwrite' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Only Administrators with a valid REST nonce may use these routes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		return KDNA_EcommerceInsights_Rest_Report_Base::check_access( $request );
	}

	/**
	 * Returns one page of products and variations, plus the summary numbers
	 * and category list for the filters.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_costs( WP_REST_Request $request ): WP_REST_Response {
		$result = KDNA_EcommerceInsights_Cost_Catalogue::query(
			array(
				'search'       => $request['search'],
				'category'     => $request['category'],
				'missing'      => $request['missing'],
				'stock_status' => $request['stock_status'],
				'page'         => $request['page'],
				'per_page'     => $request['per_page'],
			)
		);

		$result['summary']    = KDNA_EcommerceInsights_Cost_Catalogue::summary();
		$result['categories'] = $this->categories();
		$result['native']     = KDNA_EcommerceInsights_Costs::native_enabled();

		return rest_ensure_response( $result );
	}

	/**
	 * Saves a list of cost changes. Each item is { id, cost }, where a cost
	 * of null clears it. Items that cannot be saved are reported back with a
	 * reason; the rest are still saved.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function save_costs( WP_REST_Request $request ): WP_REST_Response {
		$saved     = 0;
		$unchanged = 0;
		$errors    = array();

		foreach ( (array) $request['items'] as $item ) {
			$id      = (int) ( $item['id'] ?? 0 );
			$product = $id ? wc_get_product( $id ) : false;

			if ( ! $product || in_array( $product->get_type(), KDNA_EcommerceInsights_Cost_Catalogue::SKIPPED_TYPES, true ) ) {
				$errors[] = array(
					'id'      => $id,
					'message' => __( 'This product no longer exists or cannot have a cost.', 'kdna-ecommerce-insights' ),
				);
				continue;
			}

			$raw = $item['cost'] ?? null;
			if ( null !== $raw && ( ! is_numeric( $raw ) || (float) $raw < 0 ) ) {
				$errors[] = array(
					'id'      => $id,
					'message' => __( 'Cost must be a number of zero or more.', 'kdna-ecommerce-insights' ),
				);
				continue;
			}

			if ( KDNA_EcommerceInsights_Costs::set_cost( $product, null === $raw ? null : (float) $raw ) ) {
				++$saved;
			} else {
				++$unchanged;
			}
		}

		KDNA_EcommerceInsights_Cost_Catalogue::flush();

		return rest_ensure_response(
			array(
				'saved'     => $saved,
				'unchanged' => $unchanged,
				'errors'    => $errors,
				'summary'   => KDNA_EcommerceInsights_Cost_Catalogue::summary(),
			)
		);
	}

	/**
	 * Returns the product cost CSV and a file name with today's date.
	 *
	 * @return WP_REST_Response
	 */
	public function export_csv(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'filename' => 'kdna-insights-product-costs-' . wp_date( 'Y-m-d' ) . '.csv',
				'csv'      => KDNA_EcommerceInsights_Csv_Import::export_costs(),
			)
		);
	}

	/**
	 * Checks an uploaded cost CSV and returns what would change, without saving.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_import( WP_REST_Request $request ) {
		$preview = KDNA_EcommerceInsights_Csv_Import::preview_costs( (string) $request['csv'] );

		if ( is_wp_error( $preview ) ) {
			$preview->add_data( array( 'status' => 400 ) );
			return $preview;
		}

		return rest_ensure_response( $preview );
	}

	/**
	 * Lists the other cost plugins and how many products each has a cost for.
	 *
	 * @return WP_REST_Response
	 */
	public function detect_plugins(): WP_REST_Response {
		return rest_ensure_response( KDNA_EcommerceInsights_Cost_Plugin_Import::detect() );
	}

	/**
	 * Imports one batch of costs from another cost plugin. The screen calls
	 * this repeatedly, moving the offset on, until it reports done.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_from_plugin( WP_REST_Request $request ) {
		$result = KDNA_EcommerceInsights_Cost_Plugin_Import::import_batch(
			(string) $request['source'],
			(int) $request['offset'],
			(bool) $request['overwrite']
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		KDNA_EcommerceInsights_Cost_Catalogue::flush();

		return rest_ensure_response( $result );
	}

	/**
	 * Returns product categories for the filter, indented by level.
	 *
	 * @return array[]
	 */
	private function categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$list = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$list, $by_parent ) {
			foreach ( $by_parent[ $parent ] ?? array() as $term ) {
				$list[] = array(
					'id'    => (int) $term->term_id,
					'name'  => str_repeat( '  ', $depth ) . wp_specialchars_decode( $term->name, ENT_QUOTES ),
					'depth' => $depth,
				);
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $list;
	}
}
