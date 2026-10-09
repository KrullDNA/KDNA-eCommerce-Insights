<?php
/**
 * Payment gateway fees: the real fee where the gateway saves it, otherwise
 * a percentage plus fixed rule per gateway.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out what the payment gateway charged for an order.
 *
 * Where the actual fee is saved on the order we use that. Where it is not,
 * we apply the rule set for that gateway in Settings > Costs and label the
 * figure as an estimate, so the owner knows how reliable it is.
 *
 * Fee meta keys, confirmed during the Stage 3 build by reading each
 * gateway's own source code (the latest release on GitHub, October 2026).
 * Live test orders could not be placed from the build sandbox, so please
 * repeat the check with real sandbox orders on kdnastaging.com (section 13):
 *
 * WooCommerce Stripe Gateway 11.0.1 (gateway IDs "stripe" and "stripe_*")
 * - _stripe_fee       Total Stripe fee for the order. Stripe adds to it when
 *                     refunds or adjustments change the fee.
 *                     (WC_Stripe_Helper::META_NAME_FEE, written by
 *                     WC_Stripe_Payment_Gateway::update_fees()).
 * - Stripe Fee        Older name for the same value, still read by Stripe as
 *                     a fallback (WC_Stripe_Helper::LEGACY_META_NAME_FEE).
 * - _stripe_currency  Currency of the fee: the Stripe account's balance
 *                     currency, which can differ from the order currency.
 * - _stripe_net       Amount received after the fee (not needed here).
 *
 * WooPayments 11.2.0 (gateway IDs "woocommerce_payments" and "woocommerce_payments_*")
 * - _wcpay_transaction_fee  Fee for the order, written from the payment
 *                           webhook (WC_Payments_Webhook_Processing_Service).
 * - _wcpay_net              Amount received after the fee (not needed here).
 *
 * WooCommerce PayPal Payments 4.1.3 (gateway IDs "ppcp-gateway", "ppcp-credit-card-gateway" and other "ppcp-*")
 * - _ppcp_paypal_fees  Array from PayPal's seller receivable breakdown
 *                      (PayPalGateway::FEES_META_KEY). The fee is
 *                      ['paypal_fee']['value'] in ['paypal_fee']['currency_code'].
 * - _ppcp_paypal_refund_fees  Fee changes from refunds, handled in Stage 4.
 * - PayPal Transaction Fee    Plain fee value, also written by PayPal
 *                             Payments for Pay upon Invoice and by the older
 *                             PayPal Standard gateway.
 *
 * Other gateways can add their own fee keys with the kdna_ei_fee_meta_keys filter.
 */
class KDNA_EcommerceInsights_Fees {

	/**
	 * Fee came from the gateway's own record on the order.
	 */
	const SOURCE_ACTUAL = 'actual';

	/**
	 * Fee was worked out from the rule in Settings > Costs.
	 */
	const SOURCE_ESTIMATED = 'estimated';

	/**
	 * No fee recorded and no rule set for this gateway.
	 */
	const SOURCE_NONE = 'none';

	/*
	 * ---------------------------------------------------------------------
	 * Gateways
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Lists every payment gateway installed on the store, enabled or not,
	 * with whether Insights can read its actual fees.
	 *
	 * @return array[] Each: id, title, enabled, reads_actual.
	 */
	public static function installed_gateways(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$list = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$title = method_exists( $gateway, 'get_method_title' ) && $gateway->get_method_title() ? $gateway->get_method_title() : $gateway->get_title();
			$list[] = array(
				'id'           => (string) $id,
				'title'        => wp_strip_all_tags( (string) $title ),
				'enabled'      => 'yes' === $gateway->enabled,
				'reads_actual' => self::gateway_reads_actual( (string) $id ),
			);
		}

		return $list;
	}

	/**
	 * Checks whether a gateway is one whose real fee Insights knows how to read.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 */
	public static function gateway_reads_actual( string $gateway_id ): bool {
		return (bool) preg_match( '/^(stripe|woocommerce_payments|ppcp-|paypal)/', $gateway_id );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Order fees
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns the payment fee for an order and where it came from.
	 *
	 * @param WC_Order $order Order.
	 * @return array{amount: float, source: string, currency: string, meta_key: string}
	 */
	public static function get_order_fee( WC_Order $order ): array {
		$actual = self::read_actual_fee( $order );
		if ( null !== $actual ) {
			return $actual;
		}

		$rules   = (array) KDNA_EcommerceInsights_Settings::get( 'costs.gateway_fees', array() );
		$gateway = sanitize_key( (string) $order->get_payment_method() );
		$rule    = $rules[ $gateway ] ?? null;
		$total   = (float) $order->get_total();

		if ( ! $rule || ( (float) ( $rule['percent'] ?? 0 ) <= 0 && (float) ( $rule['fixed'] ?? 0 ) <= 0 ) ) {
			return array(
				'amount'   => 0.0,
				'source'   => self::SOURCE_NONE,
				'currency' => $order->get_currency(),
				'meta_key' => '',
			);
		}

		return array(
			'amount'   => self::rule_fee( $total, (float) ( $rule['percent'] ?? 0 ), (float) ( $rule['fixed'] ?? 0 ) ),
			'source'   => self::SOURCE_ESTIMATED,
			'currency' => $order->get_currency(),
			'meta_key' => '',
		);
	}

	/**
	 * Works out a fee from a gateway rule: a percentage of the amount the
	 * customer paid plus a fixed amount per order. A free order has no fee.
	 *
	 * Example: rule_fee( 100, 1.75, 0.30 ) returns 2.05.
	 *
	 * @param float $total   Amount the customer paid, including tax and shipping.
	 * @param float $percent Percentage fee, for example 1.75.
	 * @param float $fixed   Fixed fee per order, for example 0.30.
	 * @return float
	 */
	public static function rule_fee( float $total, float $percent, float $fixed ): float {
		if ( $total <= 0 ) {
			return 0.0;
		}
		return round( $total * max( 0, $percent ) / 100 + max( 0, $fixed ), 4 );
	}

	/**
	 * Looks for a real fee saved on the order by a payment gateway.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null Fee details, or null if no gateway saved one.
	 */
	public static function read_actual_fee( WC_Order $order ): ?array {
		foreach ( self::fee_meta_keys() as $key => $reader ) {
			$raw = $order->get_meta( $key, true );
			if ( '' === $raw || null === $raw || array() === $raw ) {
				continue;
			}

			$found = call_user_func( $reader, $raw, $order );
			if ( null === $found ) {
				continue;
			}

			return array(
				'amount'   => round( abs( (float) $found['amount'] ), 4 ),
				'source'   => self::SOURCE_ACTUAL,
				'currency' => strtoupper( (string) ( $found['currency'] ?? '' ) ) ? strtoupper( (string) $found['currency'] ) : $order->get_currency(),
				'meta_key' => $key,
			);
		}

		return null;
	}

	/**
	 * Lists the order meta keys gateways use for their fee, in the order they
	 * are checked, each with a small function that reads the amount and currency.
	 *
	 * @return array<string, callable>
	 */
	public static function fee_meta_keys(): array {
		$plain = static function ( $raw ) {
			$amount = is_scalar( $raw ) ? KDNA_EcommerceInsights_Csv_Import::parse_amount( (string) $raw ) : null;
			return null === $amount ? null : array( 'amount' => $amount );
		};

		$keys = array(
			// Stripe: fee in the Stripe account currency.
			'_stripe_fee'             => static function ( $raw, WC_Order $order ) use ( $plain ) {
				$found = $plain( $raw );
				if ( $found ) {
					$found['currency'] = (string) $order->get_meta( '_stripe_currency', true );
				}
				return $found;
			},
			'Stripe Fee'              => static function ( $raw, WC_Order $order ) use ( $plain ) {
				$found = $plain( $raw );
				if ( $found ) {
					$found['currency'] = (string) $order->get_meta( '_stripe_currency', true );
				}
				return $found;
			},
			// WooPayments.
			'_wcpay_transaction_fee'  => $plain,
			// PayPal Payments: a breakdown array.
			'_ppcp_paypal_fees'       => static function ( $raw ) {
				if ( ! is_array( $raw ) || ! isset( $raw['paypal_fee']['value'] ) || ! is_numeric( $raw['paypal_fee']['value'] ) ) {
					return null;
				}
				return array(
					'amount'   => (float) $raw['paypal_fee']['value'],
					'currency' => (string) ( $raw['paypal_fee']['currency_code'] ?? '' ),
				);
			},
			// PayPal Standard and PayPal Payments Pay upon Invoice.
			'PayPal Transaction Fee'  => $plain,
		);

		/**
		 * Filters the order meta keys read for actual payment fees.
		 * Each value is a function receiving the raw meta value and the order,
		 * returning array( 'amount' => float, 'currency' => string ) or null.
		 *
		 * @param array<string, callable> $keys Meta keys and their readers.
		 */
		return apply_filters( 'kdna_ei_fee_meta_keys', $keys );
	}
}
