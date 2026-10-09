<?php
/**
 * Unit-style tests for the cost maths in KDNA eCommerce Insights:
 * overhead spreading, payment fee rules, shipping rules and extra order costs.
 *
 * These functions need no WordPress or database, so the tests run on their own:
 *
 *     php tests/test-cost-maths.php
 *
 * Every test prints PASS or FAIL. The script exits with code 1 if anything
 * fails, so it can be used in automated checks. This folder is not part of
 * the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

// The plugin files stop if ABSPATH is missing, so define it for standalone runs.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$kdna_ei_plugin = dirname( __DIR__ ) . '/kdna-ecommerce-insights/includes/';
require_once $kdna_ei_plugin . 'class-kdna-ei-overheads.php';
require_once $kdna_ei_plugin . 'class-kdna-ei-fees.php';
require_once $kdna_ei_plugin . 'class-kdna-ei-shipping.php';
require_once $kdna_ei_plugin . 'class-kdna-ei-extra-costs.php';

/*
 * -------------------------------------------------------------------------
 * Tiny test helpers
 * -------------------------------------------------------------------------
 */

$GLOBALS['kdna_ei_test_failures'] = 0;
$GLOBALS['kdna_ei_test_count']    = 0;

/**
 * Checks that two numbers match to within a hundredth of a cent and prints the result.
 *
 * @param string $label    What is being tested, in plain English.
 * @param float  $expected Expected value.
 * @param float  $actual   Value the code returned.
 */
function kdna_ei_assert_amount( string $label, float $expected, float $actual ): void {
	++$GLOBALS['kdna_ei_test_count'];
	$ok = abs( $expected - $actual ) < 0.0001;
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_test_failures'];
	}
	printf( "%s  %s (expected %s, got %s)\n", $ok ? 'PASS' : 'FAIL', $label, round( $expected, 4 ), round( $actual, 4 ) );
}

/**
 * Builds an overhead array the way the database returns it.
 *
 * @param float       $amount    Amount per period.
 * @param string      $frequency one_off, weekly, monthly, quarterly or yearly.
 * @param string      $start     Start date, Y-m-d.
 * @param string|null $end       End date, Y-m-d, or null.
 * @param bool        $gst       Whether the amount includes GST.
 * @param string      $category  Category.
 * @return array
 */
function kdna_ei_overhead( float $amount, string $frequency, string $start, ?string $end = null, bool $gst = false, string $category = 'rent' ): array {
	return array(
		'amount'       => $amount,
		'frequency'    => $frequency,
		'start_date'   => $start,
		'end_date'     => $end,
		'includes_gst' => $gst,
		'category'     => $category,
	);
}

/**
 * Returns the net overhead total for one overhead over a range.
 *
 * @param array  $overhead Overhead.
 * @param string $start    First day, Y-m-d.
 * @param string $end      Last day, Y-m-d.
 * @param float  $tax_rate GST rate.
 * @return array
 */
function kdna_ei_spread_one( array $overhead, string $start, string $end, float $tax_rate = 0.0 ): array {
	return KDNA_EcommerceInsights_Overheads::spread( array( $overhead ), $start, $end, $tax_rate );
}

/*
 * -------------------------------------------------------------------------
 * Overhead spreading
 * -------------------------------------------------------------------------
 */

/**
 * Tests that overheads are spread evenly by day across any date range.
 */
function kdna_ei_test_overhead_spreading(): void {
	echo "\nOverhead spreading\n";

	$rent = kdna_ei_overhead( 3000, 'monthly', '2026-01-01' );

	// The brief's acceptance test: $3,000 a month over 7 days is roughly $700.
	kdna_ei_assert_amount( '$3,000 a month over 7 days of June (30 days) is $700', 700, kdna_ei_spread_one( $rent, '2026-06-01', '2026-06-07' )['total'] );
	kdna_ei_assert_amount( '$3,000 a month over 7 days of July (31 days) is $677.42', 3000 / 31 * 7, kdna_ei_spread_one( $rent, '2026-07-01', '2026-07-07' )['total'] );
	kdna_ei_assert_amount( 'A full month adds exactly the monthly amount', 3000, kdna_ei_spread_one( $rent, '2026-02-01', '2026-02-28' )['total'] );
	kdna_ei_assert_amount( 'A full year of monthly rent is 12 times the amount', 36000, kdna_ei_spread_one( $rent, '2026-01-01', '2026-12-31' )['total'] );
	kdna_ei_assert_amount( 'One day of rent in February is $3,000 / 28', 3000 / 28, kdna_ei_spread_one( $rent, '2026-02-10', '2026-02-10' )['total'] );
	kdna_ei_assert_amount( 'A range across two months uses each month\'s own day rate', 6 * 100 + 5 * 3000 / 31, kdna_ei_spread_one( $rent, '2026-06-25', '2026-07-05' )['total'] );

	kdna_ei_assert_amount( 'An overhead starting mid-month only counts from its start date', 1500, kdna_ei_spread_one( kdna_ei_overhead( 3000, 'monthly', '2026-06-16' ), '2026-06-01', '2026-06-30' )['total'] );
	kdna_ei_assert_amount( 'An overhead that ended stops counting after its end date', 1000, kdna_ei_spread_one( kdna_ei_overhead( 3000, 'monthly', '2026-01-01', '2026-06-10' ), '2026-06-01', '2026-06-30' )['total'] );
	kdna_ei_assert_amount( 'An overhead that has not started yet adds nothing', 0, kdna_ei_spread_one( kdna_ei_overhead( 3000, 'monthly', '2027-01-01' ), '2026-06-01', '2026-06-30' )['total'] );

	kdna_ei_assert_amount( 'Weekly $70 over 14 days is $140', 140, kdna_ei_spread_one( kdna_ei_overhead( 70, 'weekly', '2026-01-01' ), '2026-03-01', '2026-03-14' )['total'] );
	kdna_ei_assert_amount( 'Quarterly $900 over a full quarter is $900', 900, kdna_ei_spread_one( kdna_ei_overhead( 900, 'quarterly', '2026-01-01' ), '2026-01-01', '2026-03-31' )['total'] );
	kdna_ei_assert_amount( 'Quarterly $900 over January (31 of 90 days) is $310', 310, kdna_ei_spread_one( kdna_ei_overhead( 900, 'quarterly', '2026-01-01' ), '2026-01-01', '2026-01-31' )['total'] );
	kdna_ei_assert_amount( 'Yearly $3,650 is $10 a day in a normal year', 10, kdna_ei_spread_one( kdna_ei_overhead( 3650, 'yearly', '2026-01-01' ), '2026-05-05', '2026-05-05' )['total'] );
	kdna_ei_assert_amount( 'Yearly $3,660 is $10 a day in a leap year', 10, kdna_ei_spread_one( kdna_ei_overhead( 3660, 'yearly', '2028-01-01' ), '2028-02-29', '2028-02-29' )['total'] );
	kdna_ei_assert_amount( 'A full year of a yearly cost is exactly the amount', 1200, kdna_ei_spread_one( kdna_ei_overhead( 1200, 'yearly', '2025-01-01' ), '2026-01-01', '2026-12-31' )['total'] );

	$one_off = kdna_ei_overhead( 500, 'one_off', '2026-06-15' );
	kdna_ei_assert_amount( 'A one-off cost lands in full on its date', 500, kdna_ei_spread_one( $one_off, '2026-06-01', '2026-06-30' )['total'] );
	kdna_ei_assert_amount( 'A one-off cost outside the range adds nothing', 0, kdna_ei_spread_one( $one_off, '2026-07-01', '2026-07-31' )['total'] );

	$with_gst = kdna_ei_spread_one( kdna_ei_overhead( 1100, 'monthly', '2026-01-01', null, true ), '2026-06-01', '2026-06-30', 10 );
	kdna_ei_assert_amount( 'GST-inclusive $1,100 has $100 GST taken out', 100, $with_gst['tax'] );
	kdna_ei_assert_amount( 'GST-inclusive $1,100 leaves a net cost of $1,000', 1000, $with_gst['net'] );
	kdna_ei_assert_amount( 'Costs without GST keep their full amount as net', 1100, kdna_ei_spread_one( kdna_ei_overhead( 1100, 'monthly', '2026-01-01' ), '2026-06-01', '2026-06-30', 10 )['net'] );

	$mixed = KDNA_EcommerceInsights_Overheads::spread(
		array(
			kdna_ei_overhead( 3000, 'monthly', '2026-01-01', null, false, 'rent' ),
			kdna_ei_overhead( 300, 'monthly', '2026-01-01', null, false, 'software' ),
			kdna_ei_overhead( 200, 'one_off', '2026-06-10', null, false, 'software' ),
		),
		'2026-06-01',
		'2026-06-30'
	);
	kdna_ei_assert_amount( 'Several overheads add up', 3500, $mixed['total'] );
	kdna_ei_assert_amount( 'Totals are split by category (software)', 500, $mixed['by_category']['software'] ?? 0 );

	kdna_ei_assert_amount( 'A backwards date range returns nothing', 0, kdna_ei_spread_one( $rent, '2026-06-30', '2026-06-01' )['total'] );
	kdna_ei_assert_amount( 'An invalid date returns nothing', 0, kdna_ei_spread_one( $rent, '2026-02-30', '2026-03-01' )['total'] );

	kdna_ei_assert_amount( 'Weekly $100 is $433.33 a month', 433.33, (float) KDNA_EcommerceInsights_Overheads::monthly_equivalent( 100, 'weekly' ) );
	kdna_ei_assert_amount( 'Yearly $1,200 is $100 a month', 100, (float) KDNA_EcommerceInsights_Overheads::monthly_equivalent( 1200, 'yearly' ) );
}

/*
 * -------------------------------------------------------------------------
 * Payment fee rules
 * -------------------------------------------------------------------------
 */

/**
 * Tests the percentage plus fixed payment fee rule.
 */
function kdna_ei_test_fee_rules(): void {
	echo "\nPayment fee rules\n";

	kdna_ei_assert_amount( '1.75% + $0.30 on $100 is $2.05', 2.05, KDNA_EcommerceInsights_Fees::rule_fee( 100, 1.75, 0.30 ) );
	kdna_ei_assert_amount( '2.9% + $0.30 on $49.95 is $1.7486', 49.95 * 0.029 + 0.30, KDNA_EcommerceInsights_Fees::rule_fee( 49.95, 2.9, 0.30 ) );
	kdna_ei_assert_amount( 'Percentage only: 3.5% of $200 is $7', 7, KDNA_EcommerceInsights_Fees::rule_fee( 200, 3.5, 0 ) );
	kdna_ei_assert_amount( 'Fixed only: $0.50 per order', 0.5, KDNA_EcommerceInsights_Fees::rule_fee( 80, 0, 0.5 ) );
	kdna_ei_assert_amount( 'A free order has no fee', 0, KDNA_EcommerceInsights_Fees::rule_fee( 0, 1.75, 0.30 ) );
	kdna_ei_assert_amount( 'Negative rule values are treated as zero', 0, KDNA_EcommerceInsights_Fees::rule_fee( 100, -2, -1 ) );

	++$GLOBALS['kdna_ei_test_count'];
	$checks = array(
		'stripe'                   => true,
		'stripe_klarna'            => true,
		'woocommerce_payments'     => true,
		'ppcp-gateway'             => true,
		'ppcp-credit-card-gateway' => true,
		'paypal'                   => true,
		'bacs'                     => false,
		'afterpay'                 => false,
	);
	$ok = true;
	foreach ( $checks as $gateway => $expected ) {
		$ok = $ok && ( KDNA_EcommerceInsights_Fees::gateway_reads_actual( $gateway ) === $expected );
	}
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_test_failures'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  Stripe, WooPayments and PayPal gateways are recognised as reporting actual fees\n";
}

/*
 * -------------------------------------------------------------------------
 * Shipping rules and extra costs
 * -------------------------------------------------------------------------
 */

/**
 * Tests each shipping rule type, including the cost per kilogram.
 */
function kdna_ei_test_shipping_rules(): void {
	echo "\nShipping rules\n";

	$rule = static function ( string $type, float $amount, float $per_kg = 0 ): array {
		return array(
			'type'   => $type,
			'amount' => $amount,
			'per_kg' => $per_kg,
		);
	};

	kdna_ei_assert_amount( 'Fixed $9.50 plus $1.20 per kg on 2.5 kg is $12.50', 12.5, KDNA_EcommerceInsights_Shipping::rule_cost( $rule( 'fixed', 9.5, 1.2 ), 10, 80, 2, 2.5 ) );
	kdna_ei_assert_amount( '8% of a $150 order is $12', 12, KDNA_EcommerceInsights_Shipping::rule_cost( $rule( 'percent', 8 ), 10, 150, 3, 0 ) );
	kdna_ei_assert_amount( '$2.75 per item for 4 items is $11', 11, KDNA_EcommerceInsights_Shipping::rule_cost( $rule( 'per_item', 2.75 ), 10, 150, 4, 0 ) );
	kdna_ei_assert_amount( 'Same as charged uses what the customer paid', 10.95, KDNA_EcommerceInsights_Shipping::rule_cost( $rule( 'same_as_charged', 0 ), 10.95, 150, 4, 0 ) );
	kdna_ei_assert_amount( 'No rule type adds nothing', 0, KDNA_EcommerceInsights_Shipping::rule_cost( $rule( 'none', 5 ), 10, 150, 4, 3 ) );
}

/**
 * Tests extra per-order costs, fixed and percentage.
 */
function kdna_ei_test_extra_costs(): void {
	echo "\nExtra order costs\n";

	$rules = array(
		array(
			'label'  => 'Packaging',
			'type'   => 'fixed',
			'amount' => 1.5,
		),
		array(
			'label'  => 'Fulfilment',
			'type'   => 'percent',
			'amount' => 2,
		),
	);

	$result = KDNA_EcommerceInsights_Extra_Costs::calculate( $rules, 100 );
	kdna_ei_assert_amount( 'Packaging $1.50 plus 2% fulfilment on $100 is $3.50', 3.5, $result['amount'] );
	kdna_ei_assert_amount( 'Each cost is listed separately (fulfilment $2)', 2, $result['lines'][1]['amount'] );
	kdna_ei_assert_amount( 'A free order still carries fixed packaging', 1.5, KDNA_EcommerceInsights_Extra_Costs::calculate( $rules, 0 )['amount'] );
	kdna_ei_assert_amount( 'No extra costs set adds nothing', 0, KDNA_EcommerceInsights_Extra_Costs::calculate( array(), 100 )['amount'] );
}

/*
 * -------------------------------------------------------------------------
 * Run everything
 * -------------------------------------------------------------------------
 */

kdna_ei_test_overhead_spreading();
kdna_ei_test_fee_rules();
kdna_ei_test_shipping_rules();
kdna_ei_test_extra_costs();

printf( "\n%d tests, %d failed\n", $GLOBALS['kdna_ei_test_count'], $GLOBALS['kdna_ei_test_failures'] );
exit( $GLOBALS['kdna_ei_test_failures'] ? 1 : 0 );
