<?php
/**
 * Product costs: the Cost price field, the WooCommerce native COGS bridge
 * and the cost history log.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Reads, saves and logs the cost price of every product and variation.
 *
 * There is only ever one cost per product. When WooCommerce's own Cost of
 * Goods Sold feature is switched on, that native value is the cost and our
 * own field is hidden. Otherwise the cost lives in our own meta field.
 *
 * Variations without their own cost use the parent product's cost.
 */
class KDNA_EcommerceInsights_Costs {

	/**
	 * Meta key holding the cost when WooCommerce native COGS is off.
	 */
	const META_KEY = '_kdna_ei_cost';

	/**
	 * Meta key WooCommerce native COGS uses for the defined product cost.
	 */
	const NATIVE_META_KEY = '_cogs_total_value';

	/**
	 * Meta key flagging a native COGS variation cost as "added to the parent cost".
	 */
	const NATIVE_ADDITIVE_META_KEY = '_cogs_value_is_additive';

	/**
	 * Set while we save a native cost ourselves, so the change is logged once only.
	 *
	 * @var bool
	 */
	private static $saving = false;

	/**
	 * Connects the cost field and history logging to WordPress.
	 */
	public function __construct() {
		// Log cost changes made anywhere in WooCommerce when native COGS is on.
		add_action( 'woocommerce_before_product_object_save', array( $this, 'log_native_change' ) );

		if ( is_admin() ) {
			// Simple products: field in the General tab pricing area.
			add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_field' ) );
			// Variable products: a default cost the variations inherit.
			add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_variable_parent_field' ) );
			add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );

			// Variations: field inside each variation's pricing row.
			add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_field' ), 10, 3 );
			add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation_field' ), 10, 2 );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Where costs are stored
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Checks whether WooCommerce's own Cost of Goods Sold feature is on.
	 *
	 * @return bool
	 */
	public static function native_enabled(): bool {
		return class_exists( FeaturesUtil::class ) && FeaturesUtil::feature_is_enabled( 'cost_of_goods_sold' );
	}

	/**
	 * Returns the meta key that currently holds product costs.
	 *
	 * @return string
	 */
	public static function meta_key(): string {
		return self::native_enabled() ? self::NATIVE_META_KEY : self::META_KEY;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading costs
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns the cost set directly on a product or variation, or null when
	 * none has been entered. Does not look at the parent product.
	 *
	 * @param int|WC_Product $product Product or product ID.
	 * @return float|null
	 */
	public static function get_own_cost( $product ): ?float {
		$product_id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
		if ( ! $product_id ) {
			return null;
		}

		if ( self::native_enabled() ) {
			$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
			return $product ? $product->get_cogs_value() : null;
		}

		return self::parse( get_post_meta( $product_id, self::META_KEY, true ) );
	}

	/**
	 * Returns the cost that actually applies to a product: its own cost, or
	 * for a variation with no cost of its own, the parent product's cost.
	 * Returns null when there is no cost anywhere.
	 *
	 * @param int|WC_Product $product Product or product ID.
	 * @return float|null
	 */
	public static function get_effective_cost( $product ): ?float {
		$product = $product instanceof WC_Product ? $product : wc_get_product( (int) $product );
		if ( ! $product ) {
			return null;
		}

		$own       = self::get_own_cost( $product );
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;

		if ( ! $parent_id ) {
			return $own;
		}

		$parent_cost = self::get_own_cost( $parent_id );

		// Native COGS lets a variation's cost be added on top of the parent cost.
		if ( self::native_enabled() && method_exists( $product, 'get_cogs_value_is_additive' ) && $product->get_cogs_value_is_additive() ) {
			return ( null === $own && null === $parent_cost ) ? null : (float) $own + (float) $parent_cost;
		}

		return $own ?? $parent_cost;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Saving costs
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Saves a product or variation's own cost and logs the change.
	 * Pass null to clear the cost (a variation then uses its parent's cost).
	 *
	 * @param int|WC_Product $product Product or product ID.
	 * @param float|null     $cost    New cost, or null to clear it.
	 * @return bool True if the cost changed.
	 */
	public static function set_cost( $product, ?float $cost ): bool {
		$product = $product instanceof WC_Product ? $product : wc_get_product( (int) $product );
		if ( ! $product ) {
			return false;
		}

		$cost = null === $cost ? null : round( max( 0, $cost ), 4 );
		$old  = self::get_own_cost( $product );

		if ( self::same( $old, $cost ) ) {
			return false;
		}

		if ( self::native_enabled() ) {
			// Saving through WooCommerce keeps its lookup tables up to date.
			// The change is logged by log_native_change() during the save.
			$product->set_cogs_value( $cost );
			$product->save();
			return true;
		}

		if ( null === $cost ) {
			delete_post_meta( $product->get_id(), self::META_KEY );
		} else {
			update_post_meta( $product->get_id(), self::META_KEY, wc_format_decimal( $cost, 4 ) );
		}

		self::log_change( $product, $old, $cost );

		/**
		 * Fires after a product cost changes.
		 *
		 * @param int        $product_id Product or variation ID.
		 * @param float|null $old        Previous cost.
		 * @param float|null $cost       New cost.
		 */
		do_action( 'kdna_ei_cost_changed', $product->get_id(), $old, $cost );

		return true;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Cost history
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Writes one row to the cost_history table, so later stages can apply
	 * the cost that was correct on each order date.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param float|null $old     Previous cost.
	 * @param float|null $new     New cost.
	 */
	public static function log_change( WC_Product $product, ?float $old, ?float $new ): void {
		global $wpdb;

		$is_variation = $product->is_type( 'variation' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			KDNA_EcommerceInsights_Install::table( 'cost_history' ),
			array(
				'product_id'   => $is_variation ? $product->get_parent_id() : $product->get_id(),
				'variation_id' => $is_variation ? $product->get_id() : 0,
				'old_cost'     => $old,
				'new_cost'     => $new,
				'changed_at'   => current_time( 'mysql', true ),
				'user_id'      => get_current_user_id(),
			),
			array( '%d', '%d', '%f', '%f', '%s', '%d' )
		);
	}

	/**
	 * Logs a native COGS cost change just before WooCommerce saves a product,
	 * whichever screen, import or API made the change.
	 *
	 * @param WC_Product $product Product about to be saved.
	 */
	public function log_native_change( $product ): void {
		if ( ! $product instanceof WC_Product || ! $product->get_id() || ! self::native_enabled() ) {
			return;
		}

		$changes = $product->get_changes();
		if ( ! array_key_exists( 'cogs_value', $changes ) ) {
			return;
		}

		// get_data() still holds the values from before this save.
		$data = $product->get_data();
		$old  = isset( $data['cogs_value'] ) ? self::parse( $data['cogs_value'] ) : null;
		$new  = self::parse( $changes['cogs_value'] );

		if ( ! self::same( $old, $new ) ) {
			self::log_change( $product, $old, $new );
			do_action( 'kdna_ei_cost_changed', $product->get_id(), $old, $new );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Product editor fields (only when native COGS is off)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Shows the Cost price field for simple products, beside the prices.
	 */
	public function render_simple_field(): void {
		global $product_object;

		if ( self::native_enabled() || ! $product_object instanceof WC_Product ) {
			return;
		}

		$cost = self::get_own_cost( $product_object );

		woocommerce_wp_text_input(
			array(
				'id'            => 'kdna_ei_cost',
				'value'         => null === $cost ? '' : wc_format_localized_price( $cost ),
				'label'         => self::field_label(),
				'data_type'     => 'price',
				'wrapper_class' => 'kdna-ei-cost-field show_if_simple show_if_external',
				'desc_tip'      => true,
				'description'   => __( 'What this product costs you to buy or make, excluding tax. Used by Insights to work out profit.', 'kdna-ecommerce-insights' ),
			)
		);

		$this->print_placement_script();
	}

	/**
	 * Shows a default Cost price field for variable products. Variations
	 * without their own cost use this one.
	 */
	public function render_variable_parent_field(): void {
		global $product_object;

		if ( self::native_enabled() || ! $product_object instanceof WC_Product ) {
			return;
		}

		$cost = self::get_own_cost( $product_object );

		echo '<div class="options_group show_if_variable">';
		woocommerce_wp_text_input(
			array(
				'id'          => 'kdna_ei_cost_variable',
				'value'       => null === $cost ? '' : wc_format_localized_price( $cost ),
				'label'       => self::field_label(),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Default cost for every variation, excluding tax. A variation with its own cost price uses that instead.', 'kdna-ecommerce-insights' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the simple or variable product cost when the product is updated.
	 * WooCommerce has already checked the edit form's security nonce.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_product_field( $product ): void {
		if ( self::native_enabled() || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		$field = $product->is_type( 'variable' ) ? 'kdna_ei_cost_variable' : 'kdna_ei_cost';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product form nonce.
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = wc_clean( wp_unslash( $_POST[ $field ] ) );

		// The product may be brand new, so make sure it has an ID before saving meta.
		if ( ! $product->get_id() ) {
			$product->save();
		}

		self::set_cost( $product, self::parse( wc_format_decimal( $raw ) ) );
	}

	/**
	 * Shows the Cost price field inside a variation, with the parent cost as
	 * placeholder text so it is clear what an empty field will use.
	 *
	 * @param int     $loop           Position of the variation in the list.
	 * @param array   $variation_data Variation data (unused).
	 * @param WP_Post $variation      Variation post.
	 */
	public function render_variation_field( $loop, $variation_data, $variation ): void {
		if ( self::native_enabled() ) {
			return;
		}

		$cost        = self::get_own_cost( $variation->ID );
		$parent_cost = self::get_own_cost( $variation->post_parent );

		woocommerce_wp_text_input(
			array(
				'id'            => 'kdna_ei_variable_cost_' . $loop,
				'name'          => 'kdna_ei_variable_cost[' . $loop . ']',
				'value'         => null === $cost ? '' : wc_format_localized_price( $cost ),
				'label'         => self::field_label(),
				'data_type'     => 'price',
				'placeholder'   => null === $parent_cost
					? __( 'No cost set', 'kdna-ecommerce-insights' )
					/* translators: %s: parent product cost price. */
					: sprintf( __( 'Parent cost: %s', 'kdna-ecommerce-insights' ), wc_format_localized_price( $parent_cost ) ),
				'wrapper_class' => 'form-row form-row-full kdna-ei-cost-field',
				'desc_tip'      => true,
				'description'   => __( 'Leave empty to use the parent product cost.', 'kdna-ecommerce-insights' ),
			)
		);
	}

	/**
	 * Saves a variation's cost. WooCommerce has already checked the
	 * "save variations" security nonce.
	 *
	 * @param WC_Product_Variation $variation Variation being saved.
	 * @param int                  $index     Position of the variation in the submitted form.
	 */
	public function save_variation_field( $variation, $index ): void {
		if ( self::native_enabled() || ! current_user_can( 'edit_product', $variation->get_parent_id() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the save-variations nonce.
		if ( ! isset( $_POST['kdna_ei_variable_cost'][ $index ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = wc_clean( wp_unslash( $_POST['kdna_ei_variable_cost'][ $index ] ) );

		self::set_cost( $variation, self::parse( wc_format_decimal( $raw ) ) );
	}

	/**
	 * Moves the simple product cost field so it sits directly under Regular
	 * price, when that placement is chosen in Settings > Costs. WooCommerce
	 * only offers a hook after both prices, so a tiny script does the move.
	 */
	private function print_placement_script(): void {
		if ( 'after_regular_price' !== KDNA_EcommerceInsights_Settings::get( 'costs.cost_field_placement' ) ) {
			return;
		}
		?>
		<script>
			( function () {
				var field = document.querySelector( '.kdna-ei-cost-field' );
				var regular = document.querySelector( '._regular_price_field' );
				if ( field && regular ) {
					regular.insertAdjacentElement( 'afterend', field );
				}
			}() );
		</script>
		<?php
	}

	/**
	 * The field label, including the store currency symbol like WooCommerce's own price fields.
	 *
	 * @return string
	 */
	private static function field_label(): string {
		/* translators: %s: currency symbol. */
		return sprintf( __( 'Cost price (%s)', 'kdna-ecommerce-insights' ), get_woocommerce_currency_symbol() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Turns a stored or submitted value into a cost, or null when it is empty
	 * or not a number.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function parse( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return round( max( 0, (float) $value ), 4 );
	}

	/**
	 * Checks whether two costs are the same, treating "no cost" as its own value.
	 *
	 * @param float|null $a First cost.
	 * @param float|null $b Second cost.
	 * @return bool
	 */
	public static function same( ?float $a, ?float $b ): bool {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		return abs( $a - $b ) < 0.00005;
	}
}
