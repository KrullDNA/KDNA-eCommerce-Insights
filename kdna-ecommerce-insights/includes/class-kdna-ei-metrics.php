<?php
/**
 * Metric registry: the single place every Insights number is defined and
 * calculated (section 6.1 of the brief).
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defines every metric once: its key, label, group, format, whether a
 * higher number is good news, help text, and the formula that works it out.
 *
 * Formulas work on "totals": plain sums for a date range, such as net
 * revenue, orders or ad spend, gathered by KDNA_EcommerceInsights_Report
 * from the summary tables. The admin app, the Elementor widgets, the email
 * digest and CSV exports all ask this class for numbers, so a figure can
 * never be calculated two different ways.
 *
 * Formats: currency, number, percent, ratio (for example ROAS 3.2x) and days.
 */
class KDNA_EcommerceInsights_Metrics {

	/**
	 * In-memory copy of the registry.
	 *
	 * @var array|null
	 */
	private static $registry = null;

	/*
	 * ---------------------------------------------------------------------
	 * The registry
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns every metric definition, keyed by metric key.
	 *
	 * Each definition has: key, label, group, format, higher_is_better
	 * (true, false or null for "neither"), help, needs (the totals it uses,
	 * so the API knows whether a figure includes estimates) and compute
	 * (a function that takes the totals and returns the value, or null when
	 * it cannot be worked out, for example a margin with no revenue).
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$t = static function ( array $totals, string $key ): float {
			return (float) ( $totals[ $key ] ?? 0 );
		};
		$div = array( __CLASS__, 'divide' );

		$metrics = array(

			/* ----- Sales ----- */

			'gross_sales'                => array(
				'group'            => 'sales',
				'label'            => __( 'Gross sales', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'Product sales before discounts and refunds, excluding tax and shipping.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'gross_sales' ),
			),
			'net_revenue'                => array(
				'group'            => 'sales',
				'label'            => __( 'Net revenue', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'What the business keeps from sales: gross sales less discounts and refunds, plus shipping charged. Excludes tax unless Settings > General says to show it.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'net_revenue' ) + ( self::revenue_includes_tax() ? $t( $x, 'tax' ) : 0 ),
			),
			'orders'                     => array(
				'group'            => 'sales',
				'label'            => __( 'Orders', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => true,
				'help'             => __( 'Orders with a counted status (Settings > General).', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'orders' ),
			),
			'items_sold'                 => array(
				'group'            => 'sales',
				'label'            => __( 'Items sold', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => true,
				'help'             => __( 'Units sold across all orders.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'items_sold' ),
			),
			'average_order_value'        => array(
				'group'            => 'sales',
				'label'            => __( 'Average order value', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'Net revenue divided by orders.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'net_revenue' ) + ( self::revenue_includes_tax() ? $t( $x, 'tax' ) : 0 ), $t( $x, 'orders' ) ),
			),
			'items_per_order'            => array(
				'group'            => 'sales',
				'label'            => __( 'Items per order', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'decimals'         => 1,
				'higher_is_better' => true,
				'help'             => __( 'Average number of units in each order.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'items_sold' ), $t( $x, 'orders' ) ),
			),
			'discount_rate'              => array(
				'group'            => 'sales',
				'label'            => __( 'Discount rate', 'kdna-ecommerce-insights' ),
				'format'           => 'percent',
				'higher_is_better' => false,
				'help'             => __( 'Discounts as a share of gross sales.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'discounts' ) * 100, $t( $x, 'gross_sales' ) ),
			),
			'refund_rate'                => array(
				'group'            => 'sales',
				'label'            => __( 'Refund rate', 'kdna-ecommerce-insights' ),
				'format'           => 'percent',
				'higher_is_better' => false,
				'help'             => __( 'Refunds as a share of revenue before refunds.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'refunds' ) * 100, $t( $x, 'net_revenue' ) + $t( $x, 'refunds' ) ),
			),
			'discounts'                  => array(
				'group'            => 'sales',
				'label'            => __( 'Discounts', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'Coupon and order discounts, excluding tax.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'discounts' ),
			),
			'refunds'                    => array(
				'group'            => 'sales',
				'label'            => __( 'Refunds', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'Money refunded, excluding tax.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'refunds' ),
			),
			'shipping_charged'           => array(
				'group'            => 'sales',
				'label'            => __( 'Shipping charged', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'What customers paid for shipping, excluding tax.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'shipping_charged' ),
			),

			/* ----- Profit ----- */

			'cogs'                       => array(
				'group'            => 'profit',
				'label'            => __( 'Cost of goods', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'What the products sold cost you, using the cost on the day each order was paid.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'cogs' ),
			),
			'gross_profit'               => array(
				'group'            => 'profit',
				'label'            => __( 'Gross profit', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'Net revenue less cost of goods.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'net_revenue' ) - $t( $x, 'cogs' ),
			),
			'gross_margin'               => array(
				'group'            => 'profit',
				'label'            => __( 'Gross margin', 'kdna-ecommerce-insights' ),
				'format'           => 'percent',
				'higher_is_better' => true,
				'help'             => __( 'Gross profit as a share of net revenue.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( ( $t( $x, 'net_revenue' ) - $t( $x, 'cogs' ) ) * 100, $t( $x, 'net_revenue' ) ),
			),
			'payment_fees'               => array(
				'group'            => 'profit',
				'label'            => __( 'Payment fees', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'What payment gateways charged. Actual fees where the gateway saves them, otherwise your fee rules.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'payment_fees' ),
			),
			'shipping_costs'             => array(
				'group'            => 'profit',
				'label'            => __( 'Shipping costs', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'What postage really cost you.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'shipping_costs' ),
			),
			'shipping_recovery'          => array(
				'group'            => 'profit',
				'label'            => __( 'Shipping recovery', 'kdna-ecommerce-insights' ),
				'format'           => 'percent',
				'higher_is_better' => true,
				'help'             => __( 'How much of the real postage cost customers covered. Over 100% means shipping makes a profit.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'shipping_charged' ) * 100, $t( $x, 'shipping_costs' ) ),
			),
			'extra_costs'                => array(
				'group'            => 'profit',
				'label'            => __( 'Extra order costs', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'Packaging and other per-order costs from Settings > Costs.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'extra_costs' ),
			),
			'contribution_profit'        => array(
				'group'            => 'profit',
				'label'            => __( 'Contribution profit', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'What orders contribute before marketing and overheads: gross profit less payment fees, shipping costs and extra order costs.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => self::contribution( $x ),
			),
			'ad_spend'                   => array(
				'group'            => 'profit',
				'label'            => __( 'Ad spend', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'Advertising spend for the period, excluding GST you can claim back.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'ad_spend' ),
			),
			'overheads'                  => array(
				'group'            => 'profit',
				'label'            => __( 'Overheads', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'Running costs spread evenly by day, so every date range carries its fair share.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'overheads' ),
			),
			'net_profit'                 => array(
				'group'            => 'profit',
				'label'            => __( 'Net profit', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'The bottom line: contribution profit less ad spend and overheads.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => self::contribution( $x ) - $t( $x, 'ad_spend' ) - $t( $x, 'overheads' ),
			),
			'net_margin'                 => array(
				'group'            => 'profit',
				'label'            => __( 'Net margin', 'kdna-ecommerce-insights' ),
				'format'           => 'percent',
				'higher_is_better' => true,
				'help'             => __( 'Net profit as a share of net revenue.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( ( self::contribution( $x ) - $t( $x, 'ad_spend' ) - $t( $x, 'overheads' ) ) * 100, $t( $x, 'net_revenue' ) ),
			),

			/* ----- Marketing ----- */

			'roas'                       => array(
				'group'            => 'marketing',
				'label'            => __( 'ROAS', 'kdna-ecommerce-insights' ),
				'format'           => 'ratio',
				'higher_is_better' => true,
				'help'             => __( 'Return on ad spend: revenue the ad platforms say they drove, divided by ad spend.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'ad_conversion_value' ), $t( $x, 'ad_spend' ) ),
			),
			'mer'                        => array(
				'group'            => 'marketing',
				'label'            => __( 'MER (blended ROAS)', 'kdna-ecommerce-insights' ),
				'format'           => 'ratio',
				'higher_is_better' => true,
				'help'             => __( 'Marketing efficiency ratio: all net revenue divided by all ad spend.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'net_revenue' ), $t( $x, 'ad_spend' ) ),
			),
			'cpa'                        => array(
				'group'            => 'marketing',
				'label'            => __( 'Cost per new customer', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => false,
				'help'             => __( 'Ad spend divided by new customers.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'ad_spend' ), $t( $x, 'new_customers' ) ),
			),
			'profit_after_ads'           => array(
				'group'            => 'marketing',
				'label'            => __( 'Profit after ads', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'Contribution profit less ad spend, before overheads.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => self::contribution( $x ) - $t( $x, 'ad_spend' ),
			),

			/* ----- Customers ----- */

			'customers'                  => array(
				'group'            => 'customers',
				'label'            => __( 'Customers', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => true,
				'help'             => __( 'Different customers who ordered in the period. Guests are matched by email.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'customers' ),
			),
			'new_customers'              => array(
				'group'            => 'customers',
				'label'            => __( 'New customers', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => true,
				'help'             => __( 'Customers placing their first ever order.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'new_customers' ),
			),
			'returning_customers'        => array(
				'group'            => 'customers',
				'label'            => __( 'Returning customer orders', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => true,
				'help'             => __( 'Orders from customers who had ordered before.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'returning_customers' ),
			),
			'new_customer_revenue'       => array(
				'group'            => 'customers',
				'label'            => __( 'New customer revenue', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'Net revenue from first orders.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'new_customer_revenue' ),
			),
			'returning_customer_revenue' => array(
				'group'            => 'customers',
				'label'            => __( 'Returning customer revenue', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'Net revenue from repeat orders.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'net_revenue' ) - $t( $x, 'new_customer_revenue' ),
			),
			'repeat_purchase_rate'       => array(
				'group'            => 'customers',
				'label'            => __( 'Repeat purchase rate', 'kdna-ecommerce-insights' ),
				'format'           => 'percent',
				'higher_is_better' => true,
				'help'             => __( 'Share of all customers who have ordered more than once.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'repeat_customers_all' ) * 100, $t( $x, 'customers_all' ) ),
			),
			'average_lifetime_value'     => array(
				'group'            => 'customers',
				'label'            => __( 'Average lifetime value', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => true,
				'help'             => __( 'Average net revenue per customer across all their orders.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'revenue_all' ), $t( $x, 'customers_all' ) ),
			),
			'average_orders_per_customer' => array(
				'group'            => 'customers',
				'label'            => __( 'Orders per customer', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'decimals'         => 2,
				'higher_is_better' => true,
				'help'             => __( 'Average number of orders each customer has placed.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'orders_all' ), $t( $x, 'customers_all' ) ),
			),
			'time_between_orders'        => array(
				'group'            => 'customers',
				'label'            => __( 'Time between orders', 'kdna-ecommerce-insights' ),
				'format'           => 'days',
				'higher_is_better' => false,
				'help'             => __( 'Average days between one order and the next, for repeat customers.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => isset( $x['days_between_orders'] ) ? (float) $x['days_between_orders'] : null,
			),

			/* ----- Inventory ----- */

			'units_in_stock'             => array(
				'group'            => 'inventory',
				'label'            => __( 'Units in stock', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => null,
				'help'             => __( 'Units on hand for products that track stock.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'units_in_stock' ),
			),
			'stock_value_cost'           => array(
				'group'            => 'inventory',
				'label'            => __( 'Stock value at cost', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'What your stock on hand cost you.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'stock_value_cost' ),
			),
			'stock_value_retail'         => array(
				'group'            => 'inventory',
				'label'            => __( 'Stock value at retail', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'What your stock on hand would sell for at current prices.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'stock_value_retail' ),
			),
			'low_stock'                  => array(
				'group'            => 'inventory',
				'label'            => __( 'Low stock', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => false,
				'help'             => __( 'Products at or below the low stock threshold.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'low_stock' ),
			),
			'out_of_stock'               => array(
				'group'            => 'inventory',
				'label'            => __( 'Out of stock', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => false,
				'help'             => __( 'Products that cannot be bought right now.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'out_of_stock' ),
			),
			'in_stock'                   => array(
				'group'            => 'inventory',
				'label'            => __( 'In stock', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => true,
				'help'             => __( 'Products in stock and above the low stock threshold.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'in_stock' ),
			),
			'sell_through_rate'          => array(
				'group'            => 'inventory',
				'label'            => __( 'Sell-through rate', 'kdna-ecommerce-insights' ),
				'format'           => 'percent',
				'higher_is_better' => true,
				'help'             => __( 'Units sold in the period as a share of units sold plus units still in stock.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $div( $t( $x, 'items_sold' ) * 100, $t( $x, 'items_sold' ) + $t( $x, 'units_in_stock' ) ),
			),
			'dead_stock'                 => array(
				'group'            => 'inventory',
				'label'            => __( 'Dead stock', 'kdna-ecommerce-insights' ),
				'format'           => 'number',
				'higher_is_better' => false,
				'help'             => __( 'Products in stock with no sales in the number of days set in Settings > Alerts.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'dead_stock' ),
			),

			/* ----- Tax ----- */

			'gst_collected'              => array(
				'group'            => 'tax',
				'label'            => __( 'GST collected', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'Tax charged on sales, less tax refunded.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'tax' ),
			),
			'gst_on_costs'               => array(
				'group'            => 'tax',
				'label'            => __( 'GST paid on costs (estimate)', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'An estimate from overheads and ad spend marked as including GST.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'overheads_tax' ) + $t( $x, 'ad_spend_tax' ),
			),
			'net_gst'                    => array(
				'group'            => 'tax',
				'label'            => __( 'Net GST position', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'GST collected less GST paid on costs. A guide for your bookkeeper, not a lodgement.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'tax' ) - $t( $x, 'overheads_tax' ) - $t( $x, 'ad_spend_tax' ),
			),
			'bas_g1'                     => array(
				'group'            => 'tax',
				'label'            => __( 'G1 Total sales', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'Total sales including GST, as on the BAS.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'net_revenue' ) + $t( $x, 'tax' ),
			),
			'bas_1a'                     => array(
				'group'            => 'tax',
				'label'            => __( '1A GST on sales', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'GST on sales, as on the BAS.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'tax' ),
			),
			'bas_1b'                     => array(
				'group'            => 'tax',
				'label'            => __( '1B GST on purchases (estimate)', 'kdna-ecommerce-insights' ),
				'format'           => 'currency',
				'higher_is_better' => null,
				'help'             => __( 'Estimated GST on purchases, from costs marked as including GST.', 'kdna-ecommerce-insights' ),
				'compute'          => static fn( $x ) => $t( $x, 'overheads_tax' ) + $t( $x, 'ad_spend_tax' ),
			),
		);

		// Which metrics depend on estimated payment fees or shipping costs.
		$uses_fees     = array( 'payment_fees', 'contribution_profit', 'net_profit', 'net_margin', 'profit_after_ads' );
		$uses_shipping = array( 'shipping_costs', 'shipping_recovery', 'contribution_profit', 'net_profit', 'net_margin', 'profit_after_ads' );
		$uses_cogs     = array( 'cogs', 'gross_profit', 'gross_margin', 'contribution_profit', 'net_profit', 'net_margin', 'profit_after_ads' );

		foreach ( $metrics as $key => $metric ) {
			$metrics[ $key ]['key']           = $key;
			$metrics[ $key ]['uses_fees']     = in_array( $key, $uses_fees, true );
			$metrics[ $key ]['uses_shipping'] = in_array( $key, $uses_shipping, true );
			$metrics[ $key ]['uses_cogs']     = in_array( $key, $uses_cogs, true );
			$metrics[ $key ]['decimals']      = $metric['decimals'] ?? ( 'number' === $metric['format'] ? 0 : null );
		}

		/**
		 * Filters the metric registry, so add-ons can add their own metrics.
		 *
		 * @param array $metrics Metric definitions keyed by key.
		 */
		self::$registry = apply_filters( 'kdna_ei_metrics', $metrics );

		return self::$registry;
	}

	/**
	 * Returns one metric definition, or null.
	 *
	 * @param string $key Metric key.
	 * @return array|null
	 */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Returns the public description of every metric (without formulas), for
	 * the dashboard and Elementor controls.
	 *
	 * @return array[]
	 */
	public static function describe(): array {
		$list = array();
		foreach ( self::all() as $key => $metric ) {
			$list[ $key ] = array(
				'key'              => $key,
				'label'            => $metric['label'],
				'group'            => $metric['group'],
				'format'           => $metric['format'],
				'decimals'         => $metric['decimals'],
				'higher_is_better' => $metric['higher_is_better'],
				'help'             => $metric['help'],
			);
		}
		return $list;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Calculating
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Works out one metric from a set of totals.
	 *
	 * @param string $key    Metric key.
	 * @param array  $totals Totals for a range.
	 * @return float|null
	 */
	public static function value( string $key, array $totals ): ?float {
		$metric = self::get( $key );
		if ( ! $metric ) {
			return null;
		}
		$value = call_user_func( $metric['compute'], $totals );
		return null === $value ? null : round( (float) $value, 4 );
	}

	/**
	 * Works out a metric for this period and the comparison period, with
	 * the change and whether the change is good or bad news.
	 *
	 * Percentages change by percentage points (42% to 45% is +3 points);
	 * everything else changes by a percentage of the previous value.
	 * A rise in a cost is bad news, so "sentiment" uses higher_is_better.
	 *
	 * @param string     $key      Metric key.
	 * @param array      $current  Totals for the range.
	 * @param array|null $previous Totals for the comparison range, or null.
	 * @param array      $estimates Estimate counts for the range: fee_orders, shipping_orders, missing_cost_orders.
	 * @return array|null
	 */
	public static function evaluate( string $key, array $current, ?array $previous = null, array $estimates = array() ): ?array {
		$metric = self::get( $key );
		if ( ! $metric ) {
			return null;
		}

		$value = self::value( $key, $current );
		$prev  = null === $previous ? null : self::value( $key, $previous );

		$result = array(
			'key'              => $key,
			'label'            => $metric['label'],
			'format'           => $metric['format'],
			'decimals'         => $metric['decimals'],
			'higher_is_better' => $metric['higher_is_better'],
			'help'             => $metric['help'],
			'value'            => $value,
			'previous'         => $prev,
			'change'           => null,
			'change_type'      => 'percent' === $metric['format'] ? 'points' : 'percent',
			'direction'        => 'flat',
			'sentiment'        => 'neutral',
			'estimated'        => ( $metric['uses_fees'] && ! empty( $estimates['fee_orders'] ) ) || ( $metric['uses_shipping'] && ! empty( $estimates['shipping_orders'] ) ),
			'incomplete'       => $metric['uses_cogs'] && ! empty( $estimates['missing_cost_orders'] ),
		);

		if ( null !== $value && null !== $prev ) {
			if ( 'points' === $result['change_type'] ) {
				$result['change'] = round( $value - $prev, 2 );
			} elseif ( 0.0 !== (float) $prev ) {
				$result['change'] = round( ( $value - $prev ) / abs( $prev ) * 100, 1 );
			}

			$difference = $value - $prev;
			if ( abs( $difference ) > 0.00001 ) {
				$result['direction'] = $difference > 0 ? 'up' : 'down';
				if ( null !== $metric['higher_is_better'] ) {
					$good                = $metric['higher_is_better'] ? $difference > 0 : $difference < 0;
					$result['sentiment'] = $good ? 'good' : 'bad';
				}
			}
		}

		return $result;
	}

	/**
	 * Contribution profit from totals: net revenue less cost of goods,
	 * payment fees, shipping costs and extra order costs.
	 *
	 * @param array $totals Totals.
	 * @return float
	 */
	public static function contribution( array $totals ): float {
		return (float) ( $totals['net_revenue'] ?? 0 ) - (float) ( $totals['cogs'] ?? 0 ) - (float) ( $totals['payment_fees'] ?? 0 ) - (float) ( $totals['shipping_costs'] ?? 0 ) - (float) ( $totals['extra_costs'] ?? 0 );
	}

	/**
	 * Divides safely: returns null instead of failing when dividing by zero.
	 *
	 * @param float $top    Number to divide.
	 * @param float $bottom Number to divide by.
	 * @return float|null
	 */
	public static function divide( float $top, float $bottom ): ?float {
		return abs( $bottom ) < 0.0000001 ? null : $top / $bottom;
	}

	/**
	 * Margin as a percentage of revenue, for product and order tables.
	 *
	 * @param float $profit  Profit.
	 * @param float $revenue Revenue.
	 * @return float|null
	 */
	public static function margin( float $profit, float $revenue ): ?float {
		$margin = self::divide( $profit * 100, $revenue );
		return null === $margin ? null : round( $margin, 1 );
	}

	/**
	 * Formats a metric value for emails and the printable report, for
	 * example "$1,234.50", "32.4%", "2.10x" or "12 days". A missing value
	 * shows as "n/a".
	 *
	 * @param float|null $value    Value.
	 * @param string     $format   currency, percent, ratio, days or number.
	 * @param int|null   $decimals Decimal places, or null for the default.
	 * @return string
	 */
	public static function display( ?float $value, string $format, ?int $decimals = null ): string {
		if ( null === $value ) {
			return __( 'n/a', 'kdna-ecommerce-insights' );
		}
		switch ( $format ) {
			case 'currency':
				$places = null === $decimals ? ( abs( $value ) >= 1000 ? 0 : wc_get_price_decimals() ) : $decimals;
				return html_entity_decode( wp_strip_all_tags( wc_price( $value, array( 'decimals' => $places ) ) ), ENT_QUOTES, 'UTF-8' );
			case 'percent':
				return number_format_i18n( $value, null === $decimals ? 1 : $decimals ) . '%';
			case 'ratio':
				return number_format_i18n( $value, null === $decimals ? 2 : $decimals ) . 'x';
			case 'days':
				/* translators: %s: number of days. */
				return sprintf( __( '%s days', 'kdna-ecommerce-insights' ), number_format_i18n( $value, null === $decimals ? 0 : $decimals ) );
			default:
				return number_format_i18n( $value, null === $decimals ? 0 : $decimals );
		}
	}

	/**
	 * The change line for a metric result, such as "12.5%" or "1.2 pts",
	 * with an up or down arrow. Empty when there is nothing to compare.
	 *
	 * @param array $result Result from evaluate().
	 * @return string
	 */
	public static function change_text( array $result ): string {
		if ( null === $result['change'] ) {
			return '';
		}
		$arrow  = 'up' === $result['direction'] ? "\u{25B2}" : ( 'down' === $result['direction'] ? "\u{25BC}" : '' );
		$amount = 'points' === $result['change_type']
			/* translators: %s: change in percentage points. */
			? sprintf( __( '%s pts', 'kdna-ecommerce-insights' ), number_format_i18n( abs( $result['change'] ), 1 ) )
			: number_format_i18n( abs( $result['change'] ), abs( $result['change'] ) >= 100 ? 0 : 1 ) . '%';
		return trim( $arrow . ' ' . $amount );
	}

	/**
	 * Whether revenue figures should include tax (Settings > General).
	 * Profit maths always excludes tax.
	 *
	 * @return bool
	 */
	public static function revenue_includes_tax(): bool {
		return 'incl_tax' === KDNA_EcommerceInsights_Settings::get( 'general.revenue_display', 'excl_tax' );
	}

	/**
	 * Forgets the in-memory registry (after settings change, for example).
	 */
	public static function reset(): void {
		self::$registry = null;
	}
}
