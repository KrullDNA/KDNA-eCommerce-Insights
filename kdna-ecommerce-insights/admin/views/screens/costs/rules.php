<?php
/**
 * Costs screen, rule tabs: payment fee rules, shipping cost rules and extra
 * per-order costs. Behaviour lives in admin/js/screens/cost-rules.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

$kdna_ei_symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
?>
<div x-data="kdnaEiCostRules" x-effect="if ( route === 'costs' && [ 'fees', 'shipping', 'extras' ].includes( tab ) ) ensureLoaded()">

	<template x-if="loadError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert" x-show="[ 'fees', 'shipping', 'extras' ].includes( tab )">
			<p x-text="loadError"></p>
			<button type="button" class="kdna-ei-btn" @click="load()"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</template>

	<?php // ----- Payment fees ----- ?>
	<section id="kdna-ei-costs-panel-fees" role="tabpanel" aria-labelledby="kdna-ei-costs-tab-fees" class="kdna-ei-card" x-show="tab === 'fees'" x-cloak>
		<div class="kdna-ei-card__header">
			<div>
				<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Payment fees', 'kdna-ecommerce-insights' ); ?></h2>
				<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Stripe, WooPayments and PayPal save the real fee on each order, and Insights uses it. For every other payment method, or orders where no fee was saved, Insights estimates the fee from the rule you set here.', 'kdna-ecommerce-insights' ); ?></p>
			</div>
		</div>

		<template x-if="notices.fees">
			<div class="kdna-ei-notice" :class="'kdna-ei-notice--' + notices.fees.type" role="status"><p x-text="notices.fees.text"></p></div>
		</template>

		<div class="kdna-ei-table-wrap">
			<table class="kdna-ei-table kdna-ei-rules-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Payment method', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actual fee', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Percentage', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Fixed per order', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric">
							<?php
							/* translators: %s: example order total, for example $100.00. */
							printf( esc_html__( 'Fee on a %s order', 'kdna-ecommerce-insights' ), esc_html( html_entity_decode( wp_strip_all_tags( wc_price( 100 ) ), ENT_QUOTES, 'UTF-8' ) ) );
							?>
						</th>
					</tr>
				</thead>
				<tbody>
					<template x-if="! loaded">
						<tr><td colspan="5"><span class="kdna-ei-skeleton kdna-ei-skeleton--block" style="height: 120px;"></span></td></tr>
					</template>
					<template x-for="gateway in ( loaded ? gateways : [] )" :key="gateway.id">
						<tr>
							<td>
								<span class="kdna-ei-product__text">
									<span class="kdna-ei-product__name" x-text="gateway.title"></span>
									<span class="kdna-ei-product__meta">
										<span x-text="gateway.id"></span>
										<span class="kdna-ei-badge kdna-ei-badge--plain" x-show="! gateway.enabled" x-text="t.disabled"></span>
									</span>
								</span>
							</td>
							<td>
								<span class="kdna-ei-badge" :class="gateway.reads_actual ? 'kdna-ei-badge--positive' : ''" x-text="gateway.reads_actual ? t.readsActual : t.estimatedOnly"></span>
							</td>
							<td>
								<label class="kdna-ei-affix" :class="{ 'is-invalid': fieldError( 'costs.gateway_fees.' + gateway.id + '.percent' ) }">
									<span class="kdna-ei-visually-hidden" x-text="sprintf( t.percentFor, gateway.title )"></span>
									<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0" x-model="form.gateway_fees[ gateway.id ].percent" @input="dirty.fees = true" />
									<span class="kdna-ei-affix__after" aria-hidden="true">%</span>
								</label>
								<p class="kdna-ei-field-error" x-show="fieldError( 'costs.gateway_fees.' + gateway.id + '.percent' )" x-text="fieldError( 'costs.gateway_fees.' + gateway.id + '.percent' )"></p>
							</td>
							<td>
								<label class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': fieldError( 'costs.gateway_fees.' + gateway.id + '.fixed' ) }">
									<span class="kdna-ei-visually-hidden" x-text="sprintf( t.fixedFor, gateway.title )"></span>
									<span class="kdna-ei-affix__before" aria-hidden="true"><?php echo esc_html( $kdna_ei_symbol ); ?></span>
									<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0.00" x-model="form.gateway_fees[ gateway.id ].fixed" @input="dirty.fees = true" />
								</label>
								<p class="kdna-ei-field-error" x-show="fieldError( 'costs.gateway_fees.' + gateway.id + '.fixed' )" x-text="fieldError( 'costs.gateway_fees.' + gateway.id + '.fixed' )"></p>
							</td>
							<td class="is-numeric kdna-ei-num" x-text="feeExample( gateway.id )"></td>
						</tr>
					</template>
					<template x-if="loaded && ! gateways.length">
						<tr><td colspan="5" class="kdna-ei-muted"><?php esc_html_e( 'No payment methods are installed yet.', 'kdna-ecommerce-insights' ); ?></td></tr>
					</template>
				</tbody>
			</table>
		</div>

		<p class="kdna-ei-help"><?php esc_html_e( 'Tip: use the rate from your gateway\'s pricing page, for example 1.75% + 0.30 for Australian cards. The percentage is applied to the full amount the customer paid, including tax and shipping.', 'kdna-ecommerce-insights' ); ?></p>

		<div class="kdna-ei-card__footer">
			<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="save( 'fees' )" :disabled="saving || ! loaded" x-text="saving === 'fees' ? t.saving : t.saveFees"></button>
		</div>
	</section>

	<?php // ----- Shipping ----- ?>
	<section id="kdna-ei-costs-panel-shipping" role="tabpanel" aria-labelledby="kdna-ei-costs-tab-shipping" class="kdna-ei-card" x-show="tab === 'shipping'" x-cloak>
		<div class="kdna-ei-card__header">
			<div>
				<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Shipping costs', 'kdna-ecommerce-insights' ); ?></h2>
				<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'What postage really costs you, which is often different from what the customer pays. Insights uses, in order: a cost typed on the order itself, the real label cost from your shipping plugin, then the rule for the order\'s shipping method below.', 'kdna-ecommerce-insights' ); ?></p>
			</div>
		</div>

		<template x-if="notices.shipping">
			<div class="kdna-ei-notice" :class="'kdna-ei-notice--' + notices.shipping.type" role="status"><p x-text="notices.shipping.text"></p></div>
		</template>

		<div class="kdna-ei-field-row">
			<div class="kdna-ei-field-row__label">
				<label for="kdna-ei-shipping-meta-key"><?php esc_html_e( 'Real label cost meta key', 'kdna-ecommerce-insights' ); ?></label>
				<p class="kdna-ei-help"><?php esc_html_e( 'If a shipping plugin such as ShipStation, Shippit or Australia Post saves the real label cost on each order, enter the order meta key it uses. Leave empty if not. Your developer or the plugin\'s support team can tell you the key.', 'kdna-ecommerce-insights' ); ?></p>
			</div>
			<div>
				<input id="kdna-ei-shipping-meta-key" type="text" class="kdna-ei-input" :class="{ 'is-invalid': fieldError( 'costs.shipping_cost_meta_key' ) }" placeholder="<?php esc_attr_e( 'For example _shipping_label_cost', 'kdna-ecommerce-insights' ); ?>" x-model="form.shipping_cost_meta_key" @input="dirty.shipping = true" spellcheck="false" />
				<p class="kdna-ei-field-error" x-show="fieldError( 'costs.shipping_cost_meta_key' )" x-text="fieldError( 'costs.shipping_cost_meta_key' )"></p>
			</div>
		</div>

		<h3 class="kdna-ei-subheading"><?php esc_html_e( 'Rules by shipping method', 'kdna-ecommerce-insights' ); ?></h3>

		<div class="kdna-ei-table-wrap">
			<table class="kdna-ei-table kdna-ei-rules-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Zone and method', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Cost is', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Plus per kg', 'kdna-ecommerce-insights' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-if="! loaded">
						<tr><td colspan="4"><span class="kdna-ei-skeleton kdna-ei-skeleton--block" style="height: 120px;"></span></td></tr>
					</template>
					<template x-for="method in ( loaded ? shippingRows : [] )" :key="method.key">
						<tr :class="{ 'is-fallback': method.key === '*' }">
							<td>
								<span class="kdna-ei-product__text">
									<span class="kdna-ei-product__name" x-text="method.title"></span>
									<span class="kdna-ei-product__meta">
										<span x-text="method.zone"></span>
										<span class="kdna-ei-badge kdna-ei-badge--plain" x-show="method.enabled === false" x-text="t.disabled"></span>
									</span>
								</span>
							</td>
							<td>
								<label>
									<span class="kdna-ei-visually-hidden" x-text="sprintf( t.ruleTypeFor, method.title )"></span>
									<select class="kdna-ei-select" x-model="form.shipping_rules[ method.key ].type" @change="dirty.shipping = true">
										<template x-for="( label, value ) in shippingTypes" :key="value">
											<option :value="value" x-text="label" :selected="form.shipping_rules[ method.key ].type === value"></option>
										</template>
									</select>
								</label>
							</td>
							<td>
								<template x-if="[ 'fixed', 'percent', 'per_item' ].includes( form.shipping_rules[ method.key ].type )">
									<div>
										<label class="kdna-ei-affix" :class="{ 'kdna-ei-affix--before': form.shipping_rules[ method.key ].type !== 'percent', 'is-invalid': fieldError( 'costs.shipping_rules.' + method.key + '.amount' ) }">
											<span class="kdna-ei-visually-hidden" x-text="sprintf( t.amountFor, method.title )"></span>
											<span class="kdna-ei-affix__before" aria-hidden="true" x-show="form.shipping_rules[ method.key ].type !== 'percent'"><?php echo esc_html( $kdna_ei_symbol ); ?></span>
											<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0" x-model="form.shipping_rules[ method.key ].amount" @input="dirty.shipping = true" />
											<span class="kdna-ei-affix__after" aria-hidden="true" x-show="form.shipping_rules[ method.key ].type === 'percent'">%</span>
										</label>
										<p class="kdna-ei-field-error" x-show="fieldError( 'costs.shipping_rules.' + method.key + '.amount' )" x-text="fieldError( 'costs.shipping_rules.' + method.key + '.amount' )"></p>
									</div>
								</template>
								<template x-if="form.shipping_rules[ method.key ].type === 'same_as_charged'">
									<span class="kdna-ei-muted" x-text="t.sameAsChargedNote"></span>
								</template>
							</td>
							<td>
								<template x-if="form.shipping_rules[ method.key ].type !== 'none'">
									<div>
										<label class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': fieldError( 'costs.shipping_rules.' + method.key + '.per_kg' ) }">
											<span class="kdna-ei-visually-hidden" x-text="sprintf( t.perKgFor, method.title )"></span>
											<span class="kdna-ei-affix__before" aria-hidden="true"><?php echo esc_html( $kdna_ei_symbol ); ?></span>
											<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0.00" x-model="form.shipping_rules[ method.key ].per_kg" @input="dirty.shipping = true" />
										</label>
										<p class="kdna-ei-field-error" x-show="fieldError( 'costs.shipping_rules.' + method.key + '.per_kg' )" x-text="fieldError( 'costs.shipping_rules.' + method.key + '.per_kg' )"></p>
									</div>
								</template>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>

		<p class="kdna-ei-help"><?php esc_html_e( 'Amounts exclude tax. "Per item" counts physical items only. The cost per kg uses the weight entered on each product.', 'kdna-ecommerce-insights' ); ?></p>

		<div class="kdna-ei-card__footer">
			<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="save( 'shipping' )" :disabled="saving || ! loaded" x-text="saving === 'shipping' ? t.saving : t.saveShipping"></button>
		</div>
	</section>

	<?php // ----- Extra order costs ----- ?>
	<section id="kdna-ei-costs-panel-extras" role="tabpanel" aria-labelledby="kdna-ei-costs-tab-extras" class="kdna-ei-card" x-show="tab === 'extras'" x-cloak>
		<div class="kdna-ei-card__header">
			<div>
				<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Extra order costs', 'kdna-ecommerce-insights' ); ?></h2>
				<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Costs that come with every order, such as a box, tissue paper, a thank-you card or a pick and pack fee. Each one is a fixed amount per order or a percentage of the order value (before tax).', 'kdna-ecommerce-insights' ); ?></p>
			</div>
		</div>

		<template x-if="notices.extras">
			<div class="kdna-ei-notice" :class="'kdna-ei-notice--' + notices.extras.type" role="status"><p x-text="notices.extras.text"></p></div>
		</template>

		<div class="kdna-ei-repeater">
			<template x-for="( cost, index ) in form.extra_costs" :key="cost.uid">
				<div class="kdna-ei-repeater__row">
					<label class="kdna-ei-repeater__label">
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Name', 'kdna-ecommerce-insights' ); ?></span>
						<input type="text" class="kdna-ei-input" :class="{ 'is-invalid': fieldError( 'costs.extra_costs.' + index + '.label' ) }" placeholder="<?php esc_attr_e( 'Packaging', 'kdna-ecommerce-insights' ); ?>" x-model="cost.label" @input="dirty.extras = true" />
						<span class="kdna-ei-field-error" x-show="fieldError( 'costs.extra_costs.' + index + '.label' )" x-text="fieldError( 'costs.extra_costs.' + index + '.label' )"></span>
					</label>
					<label>
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Type', 'kdna-ecommerce-insights' ); ?></span>
						<select class="kdna-ei-select" x-model="cost.type" @change="dirty.extras = true">
							<option value="fixed"><?php esc_html_e( 'Fixed per order', 'kdna-ecommerce-insights' ); ?></option>
							<option value="percent"><?php esc_html_e( 'Percentage of order', 'kdna-ecommerce-insights' ); ?></option>
						</select>
					</label>
					<label>
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Amount', 'kdna-ecommerce-insights' ); ?></span>
						<span class="kdna-ei-affix" :class="{ 'kdna-ei-affix--before': cost.type === 'fixed', 'is-invalid': fieldError( 'costs.extra_costs.' + index + '.amount' ) }">
							<span class="kdna-ei-affix__before" aria-hidden="true" x-show="cost.type === 'fixed'"><?php echo esc_html( $kdna_ei_symbol ); ?></span>
							<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0" x-model="cost.amount" @input="dirty.extras = true" />
							<span class="kdna-ei-affix__after" aria-hidden="true" x-show="cost.type === 'percent'">%</span>
						</span>
						<span class="kdna-ei-field-error" x-show="fieldError( 'costs.extra_costs.' + index + '.amount' )" x-text="fieldError( 'costs.extra_costs.' + index + '.amount' )"></span>
					</label>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-repeater__remove" @click="removeExtra( index )" :aria-label="sprintf( t.removeCost, cost.label || t.thisCost )">&times;</button>
				</div>
			</template>

			<p class="kdna-ei-muted" x-show="loaded && ! form.extra_costs.length"><?php esc_html_e( 'No extra costs yet.', 'kdna-ecommerce-insights' ); ?></p>

			<div>
				<button type="button" class="kdna-ei-btn" @click="addExtra()">+ <?php esc_html_e( 'Add a cost', 'kdna-ecommerce-insights' ); ?></button>
			</div>
		</div>

		<div class="kdna-ei-card__footer">
			<p class="kdna-ei-muted" x-show="form.extra_costs.length" x-text="extrasExample"></p>
			<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="save( 'extras' )" :disabled="saving || ! loaded" x-text="saving === 'extras' ? t.saving : t.saveExtras"></button>
		</div>
	</section>
</div>
