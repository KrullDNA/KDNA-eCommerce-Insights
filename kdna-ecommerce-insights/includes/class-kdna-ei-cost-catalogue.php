<?php
/**
 * Fast list of every product and variation with its price and cost.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the product list behind the Costs screen, the CSV export and the
 * missing cost count.
 *
 * Loading every product as a full WooCommerce object would be slow on a big
 * catalogue, so this reads just the columns it needs in two database queries
 * and works everything else out in PHP.
 */
class KDNA_EcommerceInsights_Cost_Catalogue {

	/**
	 * Transient caching the missing cost count for the /status endpoint.
	 */
	const MISSING_TRANSIENT = 'kdna_ei_missing_costs';

	/**
	 * Product statuses listed on the Costs screen.
	 */
	const LISTED_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * Product statuses that count towards the missing cost warning (products a customer can buy).
	 */
	const LIVE_STATUSES = array( 'publish', 'private' );

	/**
	 * Product types that have no cost of their own and are left out.
	 */
	const SKIPPED_TYPES = array( 'grouped', 'external' );

	/**
	 * In-memory copy of the catalogue for the current request.
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Clears the cached missing cost count whenever a product or cost changes.
	 */
	public function __construct() {
		$events = array(
			'kdna_ei_cost_changed',
			'woocommerce_new_product',
			'woocommerce_update_product',
			'woocommerce_delete_product',
			'woocommerce_trash_product',
			'woocommerce_new_product_variation',
			'woocommerce_update_product_variation',
			'woocommerce_delete_product_variation',
			'update_option_woocommerce_feature_cost_of_goods_sold_enabled',
		);

		foreach ( $events as $event ) {
			add_action( $event, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Forgets the cached catalogue and missing cost count.
	 */
	public static function flush(): void {
		self::$index = null;
		delete_transient( self::MISSING_TRANSIENT );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Building the catalogue
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns every listed product, each with its variations under 'children'.
	 *
	 * @return array<int, array> Parent rows keyed by product ID, sorted by name.
	 */
	public static function index(): array {
		if ( null !== self::$index ) {
			return self::$index;
		}

		global $wpdb;

		$native   = KDNA_EcommerceInsights_Costs::native_enabled();
		$cost_key = KDNA_EcommerceInsights_Costs::meta_key();
		$statuses = "'" . implode( "','", array_map( 'esc_sql', self::LISTED_STATUSES ) ) . "'";

		// One query for every product and variation with the details we need.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and the fixed status list are not user input.
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_parent AS parent_id, p.post_type AS post_type, p.post_title AS title,
					p.post_excerpt AS attributes, p.post_status AS status, p.menu_order AS menu_order,
					type_term.slug AS product_type,
					sku.meta_value AS sku, cost.meta_value AS cost, additive.meta_value AS additive,
					price.meta_value AS price, regular.meta_value AS regular_price,
					stock_status.meta_value AS stock_status, stock.meta_value AS stock,
					manage_stock.meta_value AS manage_stock, low_stock.meta_value AS low_stock_amount,
					tax_status.meta_value AS tax_status, tax_class.meta_value AS tax_class
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent AND p.post_type = 'product_variation'
				LEFT JOIN {$wpdb->term_relationships} type_rel ON type_rel.object_id = p.ID
					AND type_rel.term_taxonomy_id IN ( SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_type' )
				LEFT JOIN {$wpdb->term_taxonomy} type_tax ON type_tax.term_taxonomy_id = type_rel.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} type_term ON type_term.term_id = type_tax.term_id
				LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				LEFT JOIN {$wpdb->postmeta} cost ON cost.post_id = p.ID AND cost.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} additive ON additive.post_id = p.ID AND additive.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} price ON price.post_id = p.ID AND price.meta_key = '_price'
				LEFT JOIN {$wpdb->postmeta} regular ON regular.post_id = p.ID AND regular.meta_key = '_regular_price'
				LEFT JOIN {$wpdb->postmeta} stock_status ON stock_status.post_id = p.ID AND stock_status.meta_key = '_stock_status'
				LEFT JOIN {$wpdb->postmeta} stock ON stock.post_id = p.ID AND stock.meta_key = '_stock'
				LEFT JOIN {$wpdb->postmeta} manage_stock ON manage_stock.post_id = p.ID AND manage_stock.meta_key = '_manage_stock'
				LEFT JOIN {$wpdb->postmeta} low_stock ON low_stock.post_id = p.ID AND low_stock.meta_key = '_low_stock_amount'
				LEFT JOIN {$wpdb->postmeta} tax_status ON tax_status.post_id = p.ID AND tax_status.meta_key = '_tax_status'
				LEFT JOIN {$wpdb->postmeta} tax_class ON tax_class.post_id = p.ID AND tax_class.meta_key = '_tax_class'
				WHERE ( p.post_type = 'product' AND p.post_status IN ( $statuses ) )
					OR ( p.post_type = 'product_variation' AND p.post_status IN ( 'publish', 'private' ) AND parent.post_status IN ( $statuses ) )
				ORDER BY p.post_type ASC, p.menu_order ASC, p.ID ASC",
				$cost_key,
				KDNA_EcommerceInsights_Costs::NATIVE_ADDITIVE_META_KEY
			),
			ARRAY_A
		);

		// A second query for every product's categories.
		$category_rows = $wpdb->get_results(
			"SELECT tr.object_id AS id, tt.term_id AS term_id
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = 'product_cat'",
			ARRAY_A
		);
		// phpcs:enable

		$categories = array();
		foreach ( (array) $category_rows as $row ) {
			$categories[ (int) $row['id'] ][] = (int) $row['term_id'];
		}

		$parents    = array();
		$variations = array();

		foreach ( (array) $records as $record ) {
			if ( 'product' === $record['post_type'] ) {
				$type = $record['product_type'] ? $record['product_type'] : 'simple';
				if ( in_array( $type, self::SKIPPED_TYPES, true ) ) {
					continue;
				}
				$parents[ (int) $record['id'] ] = self::make_row( $record, $type, $categories[ (int) $record['id'] ] ?? array() );
			} else {
				$variations[] = $record;
			}
		}

		// Attach variations to their parents. Variations of skipped or missing parents are dropped.
		foreach ( $variations as $record ) {
			$parent_id = (int) $record['parent_id'];
			if ( ! isset( $parents[ $parent_id ] ) ) {
				continue;
			}
			$parent = $parents[ $parent_id ];
			$row    = self::make_row( $record, 'variation', $parent['categories'], $parent );

			$parents[ $parent_id ]['children'][] = $row;
		}

		// Work out each row's effective cost, margin and missing flag.
		foreach ( $parents as $id => $parent ) {
			$parents[ $id ] = self::finalise( $parent, $native );
		}

		uasort(
			$parents,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] ) ?: $a['id'] <=> $b['id'];
			}
		);

		self::$index = $parents;
		return self::$index;
	}

	/**
	 * Turns one database record into a catalogue row.
	 *
	 * @param array      $record     Database record.
	 * @param string     $type       Product type, or 'variation'.
	 * @param int[]      $categories Category IDs (variations use their parent's).
	 * @param array|null $parent     Parent row, for variations.
	 * @return array
	 */
	private static function make_row( array $record, string $type, array $categories, ?array $parent = null ): array {
		$is_variation = 'variation' === $type;
		$manage_stock = $record['manage_stock'];
		$stock        = '' === (string) $record['stock'] || null === $record['stock'] ? null : (float) $record['stock'];

		// A variation that does not manage its own stock uses the parent's stock level.
		$stock_owner = 'yes' === $manage_stock ? (int) $record['id'] : 0;
		if ( $is_variation && 'yes' !== $manage_stock && $parent && $parent['manage_stock'] ) {
			$stock       = $parent['stock'];
			$stock_owner = (int) $parent['id'];
		}

		// Low stock amount set on the product (or variation, falling back to its parent).
		$low_stock = '' === (string) $record['low_stock_amount'] || null === $record['low_stock_amount'] ? null : (float) $record['low_stock_amount'];
		if ( null === $low_stock && $is_variation && $parent ) {
			$low_stock = $parent['low_stock'];
		}

		$tax_class = (string) $record['tax_class'];
		if ( $is_variation && ( 'parent' === $tax_class || null === $record['tax_class'] ) && $parent ) {
			$tax_class = $parent['tax_class'];
		}

		$tax_status = $is_variation && $parent ? $parent['tax_status'] : ( $record['tax_status'] ? $record['tax_status'] : 'taxable' );
		$price      = '' === (string) $record['price'] ? null : (float) $record['price'];

		return array(
			'id'            => (int) $record['id'],
			'parent_id'     => $is_variation ? (int) $record['parent_id'] : 0,
			'type'          => $type,
			'status'        => $is_variation && $parent ? $parent['status'] : $record['status'],
			'enabled'       => 'publish' === $record['status'],
			'name'          => $is_variation && $parent ? $parent['name'] : wp_specialchars_decode( (string) $record['title'], ENT_QUOTES ),
			'attributes'    => $is_variation ? wp_specialchars_decode( (string) $record['attributes'], ENT_QUOTES ) : '',
			'sku'           => (string) $record['sku'],
			'own_cost'      => KDNA_EcommerceInsights_Costs::parse( $record['cost'] ),
			'additive'      => 'yes' === $record['additive'],
			'price'         => $price,
			'price_net'     => null === $price ? null : self::price_excluding_tax( $price, $tax_status, $tax_class ),
			'stock_status'  => $record['stock_status'] ? (string) $record['stock_status'] : 'instock',
			'manage_stock'  => 'yes' === $manage_stock,
			'stock'         => $stock,
			'stock_owner'   => $stock_owner,
			'low_stock'     => $low_stock,
			'tax_status'    => $tax_status,
			'tax_class'     => $tax_class,
			'categories'    => $categories,
			'children'      => array(),
		);
	}

	/**
	 * Works out effective cost, margin and the missing cost flag for a
	 * parent row and its variations.
	 *
	 * @param array $parent Parent row with children.
	 * @param bool  $native Whether WooCommerce native COGS is on.
	 * @return array
	 */
	private static function finalise( array $parent, bool $native ): array {
		$parent_cost = $parent['own_cost'];

		foreach ( $parent['children'] as $i => $child ) {
			if ( $native && $child['additive'] ) {
				$effective = ( null === $child['own_cost'] && null === $parent_cost ) ? null : (float) $child['own_cost'] + (float) $parent_cost;
			} else {
				$effective = $child['own_cost'] ?? $parent_cost;
			}

			$child['effective_cost'] = $effective;
			$child['inherited']      = null === $child['own_cost'] && null !== $parent_cost;
			$child['missing']        = null === $effective;
			$child['margin']         = self::margin( $child['price_net'], $effective );
			$child['sellable']       = true;

			$parent['children'][ $i ] = $child;
		}

		$is_variable = 'variable' === $parent['type'] || ! empty( $parent['children'] );

		$parent['effective_cost'] = $parent_cost;
		$parent['inherited']      = false;
		$parent['sellable']       = ! $is_variable;
		$parent['missing']        = $is_variable ? false : null === $parent_cost;
		$parent['margin']         = $is_variable ? null : self::margin( $parent['price_net'], $parent_cost );

		return $parent;
	}

	/**
	 * Returns the price without tax when the store enters prices including
	 * tax, using the store's base tax rate the same way WooCommerce does.
	 *
	 * @param float  $price      Price as entered.
	 * @param string $tax_status Product tax status.
	 * @param string $tax_class  Product tax class.
	 * @return float
	 */
	private static function price_excluding_tax( float $price, string $tax_status, string $tax_class ): float {
		static $rates = array();

		if ( ! wc_tax_enabled() || ! wc_prices_include_tax() || 'taxable' !== $tax_status ) {
			return $price;
		}

		if ( ! isset( $rates[ $tax_class ] ) ) {
			$rates[ $tax_class ] = WC_Tax::get_base_tax_rates( $tax_class );
		}

		return $price - array_sum( WC_Tax::calc_tax( $price, $rates[ $tax_class ], true ) );
	}

	/**
	 * Returns the margin as a percentage of the price (before tax), or null
	 * when there is no price or no cost to work from.
	 *
	 * @param float|null $price_net Price excluding tax.
	 * @param float|null $cost      Cost price.
	 * @return float|null
	 */
	public static function margin( ?float $price_net, ?float $cost ): ?float {
		if ( null === $price_net || null === $cost || $price_net <= 0 ) {
			return null;
		}
		return round( ( $price_net - $cost ) / $price_net * 100, 1 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading the catalogue
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns every sellable row (simple products and variations) as a flat list.
	 *
	 * @return array[]
	 */
	public static function sellable_rows(): array {
		$rows = array();
		foreach ( self::index() as $parent ) {
			if ( $parent['sellable'] ) {
				$rows[] = $parent;
			}
			foreach ( $parent['children'] as $child ) {
				$rows[] = $child;
			}
		}
		return $rows;
	}

	/**
	 * Counts products and variations customers can buy that have no cost
	 * price anywhere. Cached for ten minutes and cleared on any change.
	 *
	 * @return int
	 */
	public static function missing_count(): int {
		$cached = get_transient( self::MISSING_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = 0;
		foreach ( self::sellable_rows() as $row ) {
			if ( $row['missing'] && in_array( $row['status'], self::LIVE_STATUSES, true ) ) {
				++$count;
			}
		}

		set_transient( self::MISSING_TRANSIENT, $count, 10 * MINUTE_IN_SECONDS );
		return $count;
	}

	/**
	 * Returns headline numbers for the Costs screen summary cards.
	 *
	 * @return array{products: int, missing: int, with_cost: int, average_margin: float|null}
	 */
	public static function summary(): array {
		$rows    = self::sellable_rows();
		$missing = 0;
		$margins = array();

		foreach ( $rows as $row ) {
			if ( $row['missing'] ) {
				++$missing;
			} elseif ( null !== $row['margin'] ) {
				$margins[] = $row['margin'];
			}
		}

		return array(
			'products'       => count( $rows ),
			'missing'        => $missing,
			'with_cost'      => count( $rows ) - $missing,
			'average_margin' => $margins ? round( array_sum( $margins ) / count( $margins ), 1 ) : null,
		);
	}

	/**
	 * Returns one page of the catalogue for the Costs screen, after search
	 * and filters. Each page holds whole products: a variable product always
	 * appears together with its matching variations.
	 *
	 * @param array $args {
	 *     @type string $search       Text matched against name, SKU and variation attributes.
	 *     @type int    $category     Product category ID, 0 for all.
	 *     @type bool   $missing      Only rows with no cost.
	 *     @type string $stock_status instock, outofstock, onbackorder, or '' for all.
	 *     @type int    $page         Page number, from 1.
	 *     @type int    $per_page     Products per page.
	 * }
	 * @return array{rows: array[], total: int, page: int, pages: int}
	 */
	public static function query( array $args ): array {
		$search   = self::lower( trim( (string) ( $args['search'] ?? '' ) ) );
		$category = (int) ( $args['category'] ?? 0 );
		$missing  = ! empty( $args['missing'] );
		$stock    = (string) ( $args['stock_status'] ?? '' );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );

		$category_ids = $category ? self::category_with_children( $category ) : array();

		// Checks one row against every active filter.
		$matches = static function ( array $row ) use ( $search, $category_ids, $missing, $stock ): bool {
			if ( $category_ids && ! array_intersect( $row['categories'], $category_ids ) ) {
				return false;
			}
			if ( $missing && ! $row['missing'] ) {
				return false;
			}
			if ( '' !== $stock && $row['stock_status'] !== $stock ) {
				return false;
			}
			if ( '' !== $search ) {
				$haystack = self::lower( $row['name'] . ' ' . $row['sku'] . ' ' . $row['attributes'] . ' ' . $row['id'] );
				if ( false === strpos( $haystack, $search ) ) {
					return false;
				}
			}
			return true;
		};

		$groups = array();
		foreach ( self::index() as $parent ) {
			$parent_matches = $matches( $parent ) && ( $parent['sellable'] || ! $missing );
			$children       = array_values( array_filter( $parent['children'], $matches ) );

			// When the parent itself matches the search, show all its variations that pass the other filters.
			if ( $parent_matches && '' !== $search ) {
				$children = array_values(
					array_filter(
						$parent['children'],
						static function ( $child ) use ( $matches, $parent ) {
							return $matches( array_merge( $child, array( 'sku' => $child['sku'] . ' ' . $parent['sku'], 'name' => $parent['name'] ) ) );
						}
					)
				);
			}

			if ( ! $parent_matches && ! $children ) {
				continue;
			}

			$parent['children'] = $children;
			$groups[]           = $parent;
		}

		$total = count( $groups );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = max( 1, min( $pages, (int) ( $args['page'] ?? 1 ) ) );

		$rows = array();
		foreach ( array_slice( $groups, ( $page - 1 ) * $per_page, $per_page ) as $group ) {
			$children = $group['children'];
			unset( $group['children'] );

			$rows[] = self::present( $group, count( $children ) );
			foreach ( $children as $child ) {
				$rows[] = self::present( $child, 0 );
			}
		}

		return array(
			'rows'  => $rows,
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
		);
	}

	/**
	 * Prepares a row for the dashboard, adding the thumbnail and edit link
	 * and leaving out internal fields.
	 *
	 * @param array $row            Catalogue row.
	 * @param int   $children_count Number of variations shown under it.
	 * @return array
	 */
	private static function present( array $row, int $children_count ): array {
		$edit_id = $row['parent_id'] ? $row['parent_id'] : $row['id'];
		$thumb   = get_the_post_thumbnail_url( $row['id'], 'thumbnail' );
		if ( ! $thumb && $row['parent_id'] ) {
			$thumb = get_the_post_thumbnail_url( $row['parent_id'], 'thumbnail' );
		}

		return array(
			'id'             => $row['id'],
			'parent_id'      => $row['parent_id'],
			'type'           => $row['type'],
			'status'         => $row['status'],
			'name'           => $row['name'],
			'attributes'     => $row['attributes'],
			'sku'            => $row['sku'],
			'price'          => $row['price'],
			'price_net'      => $row['price_net'],
			'own_cost'       => $row['own_cost'],
			'effective_cost' => $row['effective_cost'],
			'inherited'      => $row['inherited'],
			'additive'       => $row['additive'],
			'missing'        => $row['missing'],
			'margin'         => $row['margin'],
			'sellable'       => $row['sellable'],
			'stock_status'   => $row['stock_status'],
			'manage_stock'   => $row['manage_stock'],
			'stock'          => $row['stock'],
			'children_count' => $children_count,
			'thumbnail'      => $thumb ? $thumb : '',
			'edit_url'       => (string) get_edit_post_link( $edit_id, 'raw' ),
		);
	}

	/**
	 * Returns a category ID plus the IDs of all its sub-categories, so
	 * filtering by "Skincare" also finds products in "Skincare > Serums".
	 *
	 * @param int $category_id Category ID.
	 * @return int[]
	 */
	private static function category_with_children( int $category_id ): array {
		$children = get_term_children( $category_id, 'product_cat' );
		return array_merge( array( $category_id ), is_array( $children ) ? array_map( 'intval', $children ) : array() );
	}

	/**
	 * Lower-cases text for case-insensitive searching, including accented letters.
	 *
	 * @param string $text Text to lower-case.
	 * @return string
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * Finds one row (product or variation) by ID.
	 *
	 * @param int $id Product or variation ID.
	 * @return array|null
	 */
	public static function find( int $id ): ?array {
		foreach ( self::index() as $parent ) {
			if ( $parent['id'] === $id ) {
				return $parent;
			}
			foreach ( $parent['children'] as $child ) {
				if ( $child['id'] === $id ) {
					return $child;
				}
			}
		}
		return null;
	}

	/**
	 * Returns a lookup of every product and variation ID, and every SKU, used
	 * to match CSV rows quickly.
	 *
	 * @return array{ids: array<int, array>, skus: array<string, int>}
	 */
	public static function lookup(): array {
		$ids  = array();
		$skus = array();

		foreach ( self::index() as $parent ) {
			foreach ( array_merge( array( $parent ), $parent['children'] ) as $row ) {
				$ids[ $row['id'] ] = $row;
				if ( '' !== $row['sku'] ) {
					$skus[ strtolower( $row['sku'] ) ] = $row['id'];
				}
			}
		}

		return array(
			'ids'  => $ids,
			'skus' => $skus,
		);
	}
}
