<?php
/**
 * Shipping costs: what the postage actually cost the store, not what the
 * customer paid for it.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out the real shipping cost of an order, in this order of trust:
 *
 * 1. A manual override typed into the Insights box on the order screen.
 * 2. The real label cost saved on the order by a shipping plugin, read from
 *    the meta key set in Settings > Costs (for example ShipStation, Shippit
 *    or Australia Post).
 * 3. An estimate from the rule for the order's shipping method.
 *
 * Also adds the Insights costs box to the order edit screen, which works
 * with both HPOS and legacy order storage.
 */
class KDNA_EcommerceInsights_Shipping {

	/**
	 * Order meta key holding a manual shipping cost override.
	 */
	const OVERRIDE_META_KEY = '_kdna_ei_shipping_cost_override';

	/**
	 * Nonce action for the order screen box.
	 */
	const NONCE_ACTION = 'kdna_ei_order_costs';

	/**
	 * Cost typed in on the order screen.
	 */
	const SOURCE_OVERRIDE = 'override';

	/**
	 * Real label cost saved by a shipping plugin.
	 */
	const SOURCE_ACTUAL = 'actual';

	/**
	 * Worked out from a shipping rule.
	 */
	const SOURCE_ESTIMATED = 'estimated';

	/**
	 * No shipping on the order, or no rule applies.
	 */
	const SOURCE_NONE = 'none';

	/**
	 * Connects the order screen box to WordPress.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_order_box' ), 30 );
			add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_box' ), 50, 1 );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Order shipping cost
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns the shipping cost of an order and where the figure came from.
	 *
	 * @param WC_Order $order Order.
	 * @return array{amount: float, source: string}
	 */
	public static function get_order_shipping_cost( WC_Order $order ): array {
		// 1. Manual override.
		$override = $order->get_meta( self::OVERRIDE_META_KEY, true );
		if ( '' !== $override && is_numeric( $override ) ) {
			return array(
				'amount' => round( max( 0, (float) $override ), 4 ),
				'source' => self::SOURCE_OVERRIDE,
			);
		}

		// 2. Real label cost from a shipping plugin.
		$meta_key = trim( (string) KDNA_EcommerceInsights_Settings::get( 'costs.shipping_cost_meta_key', '' ) );
		if ( '' !== $meta_key ) {
			$raw    = $order->get_meta( $meta_key, true );
			$amount = is_scalar( $raw ) && '' !== $raw ? KDNA_EcommerceInsights_Csv_Import::parse_amount( (string) $raw ) : null;
			if ( null !== $amount && $amount >= 0 ) {
				return array(
					'amount' => round( $amount, 4 ),
					'source' => self::SOURCE_ACTUAL,
				);
			}
		}

		// 3. Estimate from the rules.
		$shipping_items = $order->get_items( 'shipping' );
		if ( ! $shipping_items ) {
			return array(
				'amount' => 0.0,
				'source' => self::SOURCE_NONE,
			);
		}

		$order_value = self::order_value( $order );
		$quantity    = 0;
		$weight_kg   = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->is_virtual() ) {
				continue;
			}
			$quantity += (int) $item->get_quantity();
			if ( $product && is_numeric( $product->get_weight() ) ) {
				$weight_kg += wc_get_weight( (float) $product->get_weight(), 'kg' ) * (int) $item->get_quantity();
			}
		}

		$total = 0.0;
		$found = false;
		$first = true;

		foreach ( $shipping_items as $shipping ) {
			$rule = self::rule_for( $shipping->get_method_id(), (int) $shipping->get_instance_id() );
			if ( ! $rule ) {
				continue;
			}
			$found = true;

			// Order-wide parts (percentage, per item, per kg) count once, on the
			// first shipping line, so split shipments are not charged twice.
			$total += self::rule_cost(
				$rule,
				(float) $shipping->get_total(),
				$first ? $order_value : 0.0,
				$first ? $quantity : 0,
				$first ? $weight_kg : 0.0
			);
			$first = false;
		}

		return array(
			'amount' => round( $total, 4 ),
			'source' => $found ? self::SOURCE_ESTIMATED : self::SOURCE_NONE,
		);
	}

	/**
	 * Works out a shipping cost from a rule. All amounts exclude tax.
	 *
	 * Rule types:
	 * - fixed            The amount, per shipment.
	 * - percent          The amount as a percentage of the order value.
	 * - per_item         The amount multiplied by the number of items shipped.
	 * - same_as_charged  Whatever the customer paid for shipping.
	 * - none             No cost.
	 * Any type can add a cost per kilogram on top.
	 *
	 * Example: a fixed 9.50 rule plus 1.20 per kg, on a 2.5 kg order, returns 12.50.
	 *
	 * @param array $rule        Rule: type, amount, per_kg.
	 * @param float $charged     Shipping the customer paid, excluding tax.
	 * @param float $order_value Value of the items, after discounts and excluding tax.
	 * @param int   $quantity    Number of items shipped.
	 * @param float $weight_kg   Total weight in kilograms.
	 * @return float
	 */
	public static function rule_cost( array $rule, float $charged, float $order_value, int $quantity, float $weight_kg ): float {
		$amount = max( 0, (float) ( $rule['amount'] ?? 0 ) );

		switch ( $rule['type'] ?? 'none' ) {
			case 'fixed':
				$cost = $amount;
				break;
			case 'percent':
				$cost = $order_value * min( 100, $amount ) / 100;
				break;
			case 'per_item':
				$cost = $amount * max( 0, $quantity );
				break;
			case 'same_as_charged':
				$cost = max( 0, $charged );
				break;
			default:
				$cost = 0.0;
		}

		$cost += max( 0, (float) ( $rule['per_kg'] ?? 0 ) ) * max( 0, $weight_kg );

		return round( $cost, 4 );
	}

	/**
	 * Finds the rule for a shipping method in a zone, falling back to the
	 * rule for "all other methods". Returns null when neither is set.
	 *
	 * @param string $method_id   Shipping method ID, for example "flat_rate".
	 * @param int    $instance_id The method's instance in its shipping zone.
	 * @return array|null
	 */
	public static function rule_for( string $method_id, int $instance_id ): ?array {
		$rules    = (array) KDNA_EcommerceInsights_Settings::get( 'costs.shipping_rules', array() );
		$key      = strtolower( $method_id . ':' . $instance_id );
		$fallback = null;

		foreach ( $rules as $rule ) {
			$method = (string) ( $rule['method'] ?? '' );
			if ( $method === $key && 'none' !== ( $rule['type'] ?? 'none' ) ) {
				return $rule;
			}
			if ( '*' === $method && 'none' !== ( $rule['type'] ?? 'none' ) ) {
				$fallback = $rule;
			}
		}

		return $fallback;
	}

	/**
	 * The value of an order's items after discounts, excluding tax and shipping.
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function order_value( WC_Order $order ): float {
		$value = 0.0;
		foreach ( $order->get_items() as $item ) {
			$value += (float) $item->get_total();
		}
		return max( 0, $value );
	}

	/**
	 * Lists every shipping method set up in every zone, for the rules table.
	 *
	 * @return array[] Each: key ("flat_rate:3"), zone, title, method_id, enabled.
	 */
	public static function configured_methods(): array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$zones   = WC_Shipping_Zones::get_zones();
		$zones[] = array(
			'zone_id'          => 0,
			'zone_name'        => __( 'Locations not covered by your other zones', 'kdna-ecommerce-insights' ),
			'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods(),
		);

		$methods = array();
		foreach ( $zones as $zone ) {
			foreach ( (array) $zone['shipping_methods'] as $method ) {
				$methods[] = array(
					'key'       => strtolower( $method->id . ':' . $method->instance_id ),
					'zone'      => wp_strip_all_tags( (string) $zone['zone_name'] ),
					'title'     => wp_strip_all_tags( (string) $method->get_title() ),
					'method_id' => (string) $method->id,
					'enabled'   => 'yes' === $method->enabled,
				);
			}
		}

		return $methods;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Order screen box
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Adds the "Insights costs" box beside the order on the order edit screen.
	 * Works for both HPOS and legacy order storage.
	 */
	public function add_order_box(): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'wc_get_page_screen_id' ) ) {
			return;
		}

		add_meta_box(
			'kdna-ei-order-costs',
			__( 'Insights costs', 'kdna-ecommerce-insights' ),
			array( $this, 'render_order_box' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Prints the order screen box: the payment fee, the shipping cost with a
	 * manual override field, and extra order costs, each labelled with where
	 * the figure came from.
	 *
	 * @param WP_Post|WC_Order $post_or_order Legacy post or HPOS order.
	 */
	public function render_order_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$currency = array( 'currency' => $order->get_currency() );
		$fee      = KDNA_EcommerceInsights_Fees::get_order_fee( $order );
		$shipping = self::get_order_shipping_cost( $order );
		$extra    = KDNA_EcommerceInsights_Extra_Costs::get_order_extra_costs( $order );
		$override = $order->get_meta( self::OVERRIDE_META_KEY, true );
		$labels   = self::source_labels();

		wp_nonce_field( self::NONCE_ACTION, 'kdna_ei_order_costs_nonce' );
		?>
		<style>
			#kdna-ei-order-costs .kdna-ei-ob-row { display: flex; justify-content: space-between; gap: 8px; padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
			#kdna-ei-order-costs .kdna-ei-ob-source { display: block; color: #646970; font-size: 12px; }
			#kdna-ei-order-costs .kdna-ei-ob-field { margin-top: 12px; }
			#kdna-ei-order-costs .kdna-ei-ob-field input { width: 100%; }
		</style>
		<div class="kdna-ei-ob-row">
			<span>
				<?php esc_html_e( 'Payment fee', 'kdna-ecommerce-insights' ); ?>
				<span class="kdna-ei-ob-source"><?php echo esc_html( $labels['fee'][ $fee['source'] ] ?? '' ); ?></span>
			</span>
			<strong><?php echo wp_kses_post( wc_price( $fee['amount'], array( 'currency' => $fee['currency'] ) ) ); ?></strong>
		</div>
		<div class="kdna-ei-ob-row">
			<span>
				<?php esc_html_e( 'Shipping cost', 'kdna-ecommerce-insights' ); ?>
				<span class="kdna-ei-ob-source"><?php echo esc_html( $labels['shipping'][ $shipping['source'] ] ?? '' ); ?></span>
			</span>
			<strong><?php echo wp_kses_post( wc_price( $shipping['amount'], $currency ) ); ?></strong>
		</div>
		<?php if ( $extra['amount'] > 0 ) : ?>
			<div class="kdna-ei-ob-row">
				<span><?php esc_html_e( 'Extra order costs', 'kdna-ecommerce-insights' ); ?></span>
				<strong><?php echo wp_kses_post( wc_price( $extra['amount'], $currency ) ); ?></strong>
			</div>
		<?php endif; ?>
		<p class="kdna-ei-ob-field">
			<label for="kdna_ei_shipping_cost_override">
				<?php
				/* translators: %s: currency symbol. */
				printf( esc_html__( 'Actual shipping cost (%s)', 'kdna-ecommerce-insights' ), esc_html( get_woocommerce_currency_symbol( $order->get_currency() ) ) );
				?>
			</label>
			<input
				type="text"
				inputmode="decimal"
				class="short wc_input_price"
				id="kdna_ei_shipping_cost_override"
				name="kdna_ei_shipping_cost_override"
				value="<?php echo esc_attr( '' === $override ? '' : wc_format_localized_price( wc_format_decimal( $override, wc_get_price_decimals() ) ) ); ?>"
				placeholder="<?php esc_attr_e( 'Leave empty to use the rule', 'kdna-ecommerce-insights' ); ?>"
			/>
			<span class="description"><?php esc_html_e( 'Type what the postage really cost to correct this order. Clear it to go back to the automatic figure.', 'kdna-ecommerce-insights' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Saves the shipping cost override when the order is updated. Works for
	 * both HPOS and legacy order storage.
	 *
	 * @param int $order_id Order ID.
	 */
	public function save_order_box( $order_id ): void {
		if ( ! isset( $_POST['kdna_ei_order_costs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kdna_ei_order_costs_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['kdna_ei_shipping_cost_override'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$raw    = trim( sanitize_text_field( wp_unslash( $_POST['kdna_ei_shipping_cost_override'] ) ) );
		$amount = '' === $raw ? null : KDNA_EcommerceInsights_Csv_Import::parse_amount( wc_format_decimal( $raw ) );
		$old    = $order->get_meta( self::OVERRIDE_META_KEY, true );

		if ( null === $amount || $amount < 0 ) {
			if ( '' === $old ) {
				return;
			}
			$order->delete_meta_data( self::OVERRIDE_META_KEY );
		} else {
			if ( '' !== $old && abs( (float) $old - $amount ) < 0.00005 ) {
				return;
			}
			$order->update_meta_data( self::OVERRIDE_META_KEY, wc_format_decimal( $amount, 4 ) );
		}

		$order->save();

		/**
		 * Fires when a cost input on an order changes, so its profit can be
		 * worked out again (from Stage 4).
		 *
		 * @param int $order_id Order ID.
		 */
		do_action( 'kdna_ei_order_costs_changed', $order->get_id() );
	}

	/**
	 * Plain-English labels explaining where each figure came from.
	 *
	 * @return array{fee: array<string, string>, shipping: array<string, string>}
	 */
	public static function source_labels(): array {
		return array(
			'fee'      => array(
				KDNA_EcommerceInsights_Fees::SOURCE_ACTUAL    => __( 'Actual fee from the payment gateway', 'kdna-ecommerce-insights' ),
				KDNA_EcommerceInsights_Fees::SOURCE_ESTIMATED => __( 'Estimated from your fee rule', 'kdna-ecommerce-insights' ),
				KDNA_EcommerceInsights_Fees::SOURCE_NONE      => __( 'No fee recorded and no rule set', 'kdna-ecommerce-insights' ),
			),
			'shipping' => array(
				self::SOURCE_OVERRIDE  => __( 'Entered on this order', 'kdna-ecommerce-insights' ),
				self::SOURCE_ACTUAL    => __( 'Actual label cost from your shipping plugin', 'kdna-ecommerce-insights' ),
				self::SOURCE_ESTIMATED => __( 'Estimated from your shipping rule', 'kdna-ecommerce-insights' ),
				self::SOURCE_NONE      => __( 'No shipping, or no rule set', 'kdna-ecommerce-insights' ),
			),
		);
	}
}
