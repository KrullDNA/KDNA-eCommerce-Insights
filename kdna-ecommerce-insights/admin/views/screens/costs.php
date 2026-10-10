<?php
/**
 * Costs screen: every cost Insights takes off revenue, one tab each.
 *
 * - Product costs   admin/views/screens/costs/products.php
 * - Payment fees, Shipping, Extra order costs
 *                   admin/views/screens/costs/rules.php
 * - Overheads       admin/views/screens/costs/overheads.php
 * - Recalculate     admin/views/screens/costs/recalculate.php
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

$kdna_ei_cost_tabs = array(
	'products'  => __( 'Product costs', 'kdna-ecommerce-insights' ),
	'fees'      => __( 'Payment fees', 'kdna-ecommerce-insights' ),
	'shipping'  => __( 'Shipping', 'kdna-ecommerce-insights' ),
	'extras'    => __( 'Extra order costs', 'kdna-ecommerce-insights' ),
	'overheads' => __( 'Overheads', 'kdna-ecommerce-insights' ),
	'recalc'    => __( 'Recalculate', 'kdna-ecommerce-insights' ),
);
?>
<div class="kdna-ei-costs-screen" x-data="{ tab: 'products' }" @kdna:ei-costs-tab.window="tab = $event.detail">
	<div class="kdna-ei-tabs kdna-ei-tabs--screen" role="tablist" aria-label="<?php esc_attr_e( 'Cost types', 'kdna-ecommerce-insights' ); ?>">
		<?php foreach ( $kdna_ei_cost_tabs as $kdna_ei_key => $kdna_ei_label ) : ?>
			<button
				type="button"
				role="tab"
				class="kdna-ei-tab"
				id="kdna-ei-costs-tab-<?php echo esc_attr( $kdna_ei_key ); ?>"
				aria-controls="kdna-ei-costs-panel-<?php echo esc_attr( $kdna_ei_key ); ?>"
				:aria-selected="tab === '<?php echo esc_js( $kdna_ei_key ); ?>' ? 'true' : 'false'"
				aria-selected="<?php echo 'products' === $kdna_ei_key ? 'true' : 'false'; ?>"
				@click="tab = '<?php echo esc_js( $kdna_ei_key ); ?>'"
			><?php echo esc_html( $kdna_ei_label ); ?></button>
		<?php endforeach; ?>
	</div>

	<div id="kdna-ei-costs-panel-products" role="tabpanel" aria-labelledby="kdna-ei-costs-tab-products" x-show="tab === 'products'">
		<?php include __DIR__ . '/costs/products.php'; ?>
	</div>

	<?php include __DIR__ . '/costs/rules.php'; ?>

	<div id="kdna-ei-costs-panel-overheads" role="tabpanel" aria-labelledby="kdna-ei-costs-tab-overheads" x-show="tab === 'overheads'" x-cloak>
		<?php include __DIR__ . '/costs/overheads.php'; ?>
	</div>

	<div id="kdna-ei-costs-panel-recalc" role="tabpanel" aria-labelledby="kdna-ei-costs-tab-recalc" x-show="tab === 'recalc'" x-cloak>
		<?php include __DIR__ . '/costs/recalculate.php'; ?>
	</div>
</div>
