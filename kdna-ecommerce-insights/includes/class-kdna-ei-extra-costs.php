<?php
/**
 * Extra per-order costs, such as packaging, inserts or a pick and pack fee.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the extra costs set in Settings > Costs to every order. Each one is
 * a fixed amount per order or a percentage of the order value.
 */
class KDNA_EcommerceInsights_Extra_Costs {

	/**
	 * Returns the total extra costs for an order, with a line per cost.
	 *
	 * @param WC_Order $order Order.
	 * @return array{amount: float, lines: array[]}
	 */
	public static function get_order_extra_costs( WC_Order $order ): array {
		$rules = (array) KDNA_EcommerceInsights_Settings::get( 'costs.extra_costs', array() );
		$value = KDNA_EcommerceInsights_Shipping::order_value( $order );

		// A fully free order (for example a giveaway) still uses packaging, so
		// fixed costs always apply; percentages naturally come to zero.
		return self::calculate( $rules, $value );
	}

	/**
	 * Works out extra costs from a list of rules. Kept free of WordPress so
	 * it can be tested on its own.
	 *
	 * Example: packaging 1.50 fixed plus a 2% fulfilment fee on a 100.00
	 * order returns 3.50.
	 *
	 * @param array[] $rules       Each: label, type (fixed or percent), amount.
	 * @param float   $order_value Value of the items, after discounts, excluding tax.
	 * @return array{amount: float, lines: array[]}
	 */
	public static function calculate( array $rules, float $order_value ): array {
		$lines = array();
		$total = 0.0;

		foreach ( $rules as $rule ) {
			$amount = max( 0, (float) ( $rule['amount'] ?? 0 ) );
			$cost   = 'percent' === ( $rule['type'] ?? 'fixed' ) ? max( 0, $order_value ) * min( 100, $amount ) / 100 : $amount;
			$cost   = round( $cost, 4 );

			$lines[] = array(
				'label'  => (string) ( $rule['label'] ?? '' ),
				'amount' => $cost,
			);
			$total  += $cost;
		}

		return array(
			'amount' => round( $total, 4 ),
			'lines'  => $lines,
		);
	}
}
