<?php
/**
 * Imports product costs saved by other WooCommerce cost plugins.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copies product costs from another cost plugin into Insights, so clients
 * switching over do not have to type them in again.
 *
 * Meta keys confirmed during the Stage 2 build:
 *
 * Cost of Goods for WooCommerce by WPFactory (checked in plugin source v4.2.3,
 * includes/class-wpfcogs-products.php, get_product_cost()):
 * - _alg_wc_cog_cost         Cost on simple products, variable parents and
 *                            variations. An empty variation cost falls back to
 *                            the parent's _alg_wc_cog_cost. Values can be saved
 *                            with a decimal comma (for example "12,50").
 * - _alg_wc_cog_cost_archive Older costs (history), not imported.
 * - _alg_wc_cog_item_cost    Cost locked on order lines, not product meta.
 *
 * WooCommerce Cost of Goods by SkyVerge (paid plugin, source not public; keys
 * confirmed from its official documentation's CSV import table at
 * woocommerce.com/document/cost-of-goods-sold and Metorik's integration notes):
 * - _wc_cog_cost              Cost on simple products and on each variation.
 * - _wc_cog_cost_variable     Default cost on a variable parent product.
 * - _wc_cog_min_variation_cost and _wc_cog_max_variation_cost
 *                             Worked-out ranges on the parent, not imported.
 * - _wc_cog_item_cost         Cost locked on order lines, not product meta.
 */
class KDNA_EcommerceInsights_Cost_Plugin_Import {

	/**
	 * Products handled per request, so big catalogues never time out.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Lists the cost plugins Insights can import from, with the meta keys
	 * each one uses.
	 *
	 * @return array<string, array{label: string, keys: string[], parent_keys: string[]}>
	 */
	public static function sources(): array {
		$sources = array(
			'wpfactory' => array(
				'label'       => __( 'Cost of Goods for WooCommerce (WPFactory)', 'kdna-ecommerce-insights' ),
				'keys'        => array( '_alg_wc_cog_cost' ),
				'parent_keys' => array( '_alg_wc_cog_cost' ),
			),
			'skyverge'  => array(
				'label'       => __( 'WooCommerce Cost of Goods (SkyVerge)', 'kdna-ecommerce-insights' ),
				'keys'        => array( '_wc_cog_cost' ),
				'parent_keys' => array( '_wc_cog_cost_variable', '_wc_cog_cost' ),
			),
		);

		// Costs typed into Insights before WooCommerce's own Cost of Goods Sold was
		// switched on stay in our meta key. Offer to copy them into WooCommerce.
		if ( KDNA_EcommerceInsights_Costs::native_enabled() ) {
			$sources['insights'] = array(
				'label'       => __( 'Earlier Insights costs (entered before WooCommerce Cost of Goods Sold was switched on)', 'kdna-ecommerce-insights' ),
				'keys'        => array( KDNA_EcommerceInsights_Costs::META_KEY ),
				'parent_keys' => array( KDNA_EcommerceInsights_Costs::META_KEY ),
			);
		}

		return $sources;
	}

	/**
	 * Returns every product and variation ID that has a cost saved by the
	 * given plugin, lowest ID first.
	 *
	 * @param string $source Source key from sources().
	 * @return int[]
	 */
	private static function product_ids( string $source ): array {
		global $wpdb;

		$sources = self::sources();
		if ( ! isset( $sources[ $source ] ) ) {
			return array();
		}

		$keys         = array_unique( array_merge( $sources[ $source ]['keys'], $sources[ $source ]['parent_keys'] ) );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are built above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key IN ( $placeholders ) AND pm.meta_value <> ''
					AND p.post_type IN ( 'product', 'product_variation' ) AND p.post_status <> 'trash'
				ORDER BY pm.post_id ASC",
				$keys
			)
		);
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Lists each source plugin with how many products have a cost saved by it,
	 * so the screen only offers imports that have something to bring in.
	 *
	 * @return array[]
	 */
	public static function detect(): array {
		$found = array();
		foreach ( self::sources() as $key => $source ) {
			$found[] = array(
				'key'   => $key,
				'label' => $source['label'],
				'count' => count( self::product_ids( $key ) ),
			);
		}
		return $found;
	}

	/**
	 * Imports one batch of costs from a source plugin.
	 *
	 * @param string $source    Source key from sources().
	 * @param int    $offset    How many products earlier batches have handled.
	 * @param bool   $overwrite Whether to replace costs already entered in Insights.
	 * @return array{total: int, processed: int, imported: int, skipped: int, next_offset: int, done: bool}|WP_Error
	 */
	public static function import_batch( string $source, int $offset, bool $overwrite ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $source ] ) ) {
			return new WP_Error( 'kdna_ei_unknown_source', __( 'That cost plugin is not supported.', 'kdna-ecommerce-insights' ) );
		}

		$ids      = self::product_ids( $source );
		$batch    = array_slice( $ids, max( 0, $offset ), self::BATCH_SIZE );
		$imported = 0;
		$skipped  = 0;

		foreach ( $batch as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				++$skipped;
				continue;
			}

			$keys = $product->is_type( 'variable' ) ? $sources[ $source ]['parent_keys'] : $sources[ $source ]['keys'];
			$cost = null;
			foreach ( $keys as $key ) {
				$raw = (string) get_post_meta( $id, $key, true );
				if ( '' !== $raw ) {
					$cost = KDNA_EcommerceInsights_Csv_Import::parse_amount( $raw );
					break;
				}
			}

			$current = KDNA_EcommerceInsights_Costs::get_own_cost( $product );

			if ( null === $cost || $cost < 0 || ( null !== $current && ! $overwrite ) ) {
				++$skipped;
				continue;
			}

			if ( KDNA_EcommerceInsights_Costs::set_cost( $product, $cost ) ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		$next = $offset + count( $batch );

		return array(
			'total'       => count( $ids ),
			'processed'   => count( $batch ),
			'imported'    => $imported,
			'skipped'     => $skipped,
			'next_offset' => $next,
			'done'        => $next >= count( $ids ),
		);
	}
}
