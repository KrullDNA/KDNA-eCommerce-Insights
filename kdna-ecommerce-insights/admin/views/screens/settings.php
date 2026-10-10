<?php
/**
 * Settings screen (section 11 of the brief): every tab, each saved on its
 * own, with plain-English help and validation. Behaviour lives in
 * admin/js/screens/settings.js; the Data tab's processing card uses the
 * app's own job controls in admin/js/kdna-ei-app.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;

$kdna_ei_settings_tabs = array(
	'general'   => __( 'General', 'kdna-ecommerce-insights' ),
	'costs'     => __( 'Costs', 'kdna-ecommerce-insights' ),
	'marketing' => __( 'Marketing', 'kdna-ecommerce-insights' ),
	'tax'       => __( 'Tax', 'kdna-ecommerce-insights' ),
	'branding'  => __( 'Branding', 'kdna-ecommerce-insights' ),
	'hero'      => __( 'Hero card and Goals', 'kdna-ecommerce-insights' ),
	'alerts'    => __( 'Alerts and digests', 'kdna-ecommerce-insights' ),
	'data'      => __( 'Data', 'kdna-ecommerce-insights' ),
);

/**
 * Prints a list of radio choices, each with a short explanation.
 *
 * @param string $model   Alpine path the choice is stored in, for example "form.general.date_basis".
 * @param string $name    Radio group name.
 * @param array  $options Value => array( label, help ).
 */
$kdna_ei_choices = static function ( string $model, string $name, array $options ): void {
	echo '<div class="kdna-ei-choice-list">';
	foreach ( $options as $value => $option ) {
		printf(
			'<label class="kdna-ei-choice" :class="{ \'is-selected\': %1$s === \'%2$s\' }"><input type="radio" name="%3$s" value="%4$s" x-model="%1$s" /><span class="kdna-ei-choice__text"><span class="kdna-ei-choice__label">%5$s</span><span class="kdna-ei-muted">%6$s</span></span></label>',
			esc_attr( $model ),
			esc_js( (string) $value ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_html( $option[0] ),
			esc_html( $option[1] )
		);
	}
	echo '</div>';
};

/**
 * Prints the error line for a field path.
 *
 * @param string $path Field path, for example "general.week_start".
 */
$kdna_ei_error = static function ( string $path ): void {
	printf(
		'<p class="kdna-ei-field-error" role="alert" x-show="err( \'%1$s\' )" x-text="err( \'%1$s\' )"></p>',
		esc_js( $path )
	);
};

/**
 * Prints the save bar at the bottom of a tab.
 *
 * @param string $tab       Tab key.
 * @param bool   $resetable Whether the tab has a Reset to defaults button.
 */
$kdna_ei_footer = static function ( string $tab, bool $resetable = true ): void {
	?>
	<div class="kdna-ei-settings-footer">
		<?php if ( $resetable ) : ?>
			<template x-if="confirmReset !== '<?php echo esc_js( $tab ); ?>'">
				<button type="button" class="kdna-ei-btn" @click="confirmReset = '<?php echo esc_js( $tab ); ?>'" :disabled="busy !== ''"><?php esc_html_e( 'Reset to defaults', 'kdna-ecommerce-insights' ); ?></button>
			</template>
			<template x-if="confirmReset === '<?php echo esc_js( $tab ); ?>'">
				<span class="kdna-ei-confirm">
					<span><?php esc_html_e( 'Put every setting on this tab back to how it was when Insights was installed?', 'kdna-ecommerce-insights' ); ?></span>
					<button type="button" class="kdna-ei-btn" @click="resetTab( '<?php echo esc_js( $tab ); ?>' )"><?php esc_html_e( 'Yes, reset', 'kdna-ecommerce-insights' ); ?></button>
					<button type="button" class="kdna-ei-link" @click="confirmReset = ''"><?php esc_html_e( 'Cancel', 'kdna-ecommerce-insights' ); ?></button>
				</span>
			</template>
		<?php endif; ?>
		<p class="kdna-ei-settings-footer__status" role="status" aria-live="polite">
			<span x-show="isDirty( '<?php echo esc_js( $tab ); ?>' ) && busy !== '<?php echo esc_js( $tab ); ?>'" class="kdna-ei-muted"><?php esc_html_e( 'Unsaved changes', 'kdna-ecommerce-insights' ); ?></span>
			<span x-show="notice.tab === '<?php echo esc_js( $tab ); ?>'" :class="'kdna-ei-text-' + notice.type" x-text="notice.text"></span>
		</p>
		<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="save( '<?php echo esc_js( $tab ); ?>' )" :disabled="busy !== '' || ! isDirty( '<?php echo esc_js( $tab ); ?>' )" x-text="busy === '<?php echo esc_js( $tab ); ?>' ? t.saving : t.save"></button>
	</div>
	<?php
};
?>
<div class="kdna-ei-settings" x-data="kdnaEiSettings" x-effect="if ( route === 'settings' ) ensureLoaded()" @kdna:ei-settings-tab.window="openTab( $event.detail )">
	<nav class="kdna-ei-card kdna-ei-settings__nav" aria-label="<?php esc_attr_e( 'Settings tabs', 'kdna-ecommerce-insights' ); ?>">
		<?php foreach ( $kdna_ei_settings_tabs as $kdna_ei_key => $kdna_ei_label ) : ?>
			<button
				type="button"
				class="kdna-ei-settings__tab"
				:class="{ 'is-active': settingsTab === '<?php echo esc_js( $kdna_ei_key ); ?>' }"
				:aria-current="settingsTab === '<?php echo esc_js( $kdna_ei_key ); ?>' ? 'page' : false"
				@click="openTab( '<?php echo esc_js( $kdna_ei_key ); ?>' )"
			>
				<span><?php echo esc_html( $kdna_ei_label ); ?></span>
				<span class="kdna-ei-settings__dirty" x-show="isDirty( '<?php echo esc_js( $kdna_ei_key ); ?>' )" title="<?php esc_attr_e( 'Unsaved changes', 'kdna-ecommerce-insights' ); ?>"><span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Unsaved changes', 'kdna-ecommerce-insights' ); ?></span></span>
			</button>
		<?php endforeach; ?>
	</nav>

	<div class="kdna-ei-settings__panel">
		<template x-if="loadError">
			<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
				<p x-text="loadError"></p>
				<button type="button" class="kdna-ei-btn" @click="load()"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
			</div>
		</template>

		<?php // Loading placeholder for the form tabs. ?>
		<div class="kdna-ei-card" x-show="! loaded && settingsTab !== 'data'" aria-hidden="true">
			<div class="kdna-ei-card__header"><span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span></div>
			<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 5; $kdna_ei_i++ ) : ?>
				<div class="kdna-ei-skel-field">
					<div class="kdna-ei-skel-stack">
						<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 50%;"></span>
						<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 80%; height: 10px;"></span>
					</div>
					<span class="kdna-ei-skeleton kdna-ei-skeleton--block"></span>
				</div>
			<?php endfor; ?>
		</div>

		<?php // ================================================= General. ?>
		<template x-if="loaded">
		<section class="kdna-ei-card kdna-ei-settings-panel" x-show="settingsTab === 'general'" aria-labelledby="kdna-ei-set-general">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-set-general" class="kdna-ei-card__title"><?php esc_html_e( 'General', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'The rules behind every figure: which orders count, which day they belong to, and how refunds are treated.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label" id="kdna-ei-set-statuses"><?php esc_html_e( 'Orders that count as a sale', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Usually Processing and Completed. Pending, on hold, cancelled and failed orders are normally left out.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<div class="kdna-ei-checks" role="group" aria-labelledby="kdna-ei-set-statuses" :class="{ 'is-invalid': err( 'general.order_statuses' ) }">
						<template x-for="status in context.order_statuses" :key="status.key">
							<label class="kdna-ei-check">
								<input type="checkbox" :checked="form.general.order_statuses.indexOf( status.key ) !== -1" @change="toggleIn( form.general.order_statuses, status.key )" />
								<span x-text="status.label"></span>
							</label>
						</template>
					</div>
					<?php $kdna_ei_error( 'general.order_statuses' ); ?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Which day an order belongs to', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Decides which day, month or quarter an order is counted in, for every report and the GST summary.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<?php
					$kdna_ei_choices(
						'form.general.date_basis',
						'kdna-ei-date-basis',
						array(
							'paid'      => array( __( 'The day it was paid', 'kdna-ecommerce-insights' ), __( 'Recommended. Matches when the money came in.', 'kdna-ecommerce-insights' ) ),
							'created'   => array( __( 'The day it was placed', 'kdna-ecommerce-insights' ), __( 'Counts orders as soon as they arrive, paid or not yet.', 'kdna-ecommerce-insights' ) ),
							'completed' => array( __( 'The day it was completed', 'kdna-ecommerce-insights' ), __( 'Counts orders once they are sent. Orders not yet completed use their paid date.', 'kdna-ecommerce-insights' ) ),
						)
					);
					$kdna_ei_error( 'general.date_basis' );
					?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Refunds', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Which day a refund comes off your figures.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<?php
					$kdna_ei_choices(
						'form.general.refund_dating',
						'kdna-ei-refund-dating',
						array(
							'refund_date' => array( __( 'On the day of the refund', 'kdna-ecommerce-insights' ), __( 'Recommended. Past months stay as they were reported.', 'kdna-ecommerce-insights' ) ),
							'order_date'  => array( __( 'Against the original order', 'kdna-ecommerce-insights' ), __( 'Shows the true result of each order, but past months can change.', 'kdna-ecommerce-insights' ) ),
						)
					);
					?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Refunded items', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Whether the product cost of a refunded item comes back to you.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<?php
					$kdna_ei_choices(
						'form.general.restock_treatment',
						'kdna-ei-restock',
						array(
							'restocked'   => array( __( 'Back into stock', 'kdna-ecommerce-insights' ), __( 'The item can be sold again, so its cost is added back.', 'kdna-ecommerce-insights' ) ),
							'written_off' => array( __( 'Written off', 'kdna-ecommerce-insights' ), __( 'The item is lost, so its cost stays as a cost.', 'kdna-ecommerce-insights' ) ),
						)
					);
					?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Revenue figures', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Profit is always worked out without tax. This only changes how revenue is shown.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<?php
					$kdna_ei_choices(
						'form.general.revenue_display',
						'kdna-ei-revenue-display',
						array(
							'excl_tax' => array( __( 'Excluding tax', 'kdna-ecommerce-insights' ), __( 'Recommended. The money the business actually keeps.', 'kdna-ecommerce-insights' ) ),
							'incl_tax' => array( __( 'Including tax', 'kdna-ecommerce-insights' ), __( 'Matches the totals customers paid.', 'kdna-ecommerce-insights' ) ),
						)
					);
					?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-week"><?php esc_html_e( 'Weeks start on', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help"><?php esc_html_e( 'Used for weekly charts and the weekly digest.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<select id="kdna-ei-set-week" class="kdna-ei-select kdna-ei-setting__narrow" x-model.number="form.general.week_start">
						<template x-for="day in context.weekdays" :key="day.key">
							<option :value="day.key" x-text="day.label" :selected="day.key === form.general.week_start"></option>
						</template>
					</select>
					<?php $kdna_ei_error( 'general.week_start' ); ?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-range"><?php esc_html_e( 'Starting date range', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help"><?php esc_html_e( 'What a new user sees first. Everyone can then change it, and Insights remembers each person\'s choice.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div class="kdna-ei-setting__pair">
					<div>
						<select id="kdna-ei-set-range" class="kdna-ei-select" x-model="form.general.default_range">
							<?php foreach ( KDNA_EcommerceInsights_Settings::range_presets() as $kdna_ei_key => $kdna_ei_label ) : ?>
								<?php if ( 'custom' !== $kdna_ei_key ) : ?>
									<option value="<?php echo esc_attr( $kdna_ei_key ); ?>"><?php echo esc_html( $kdna_ei_label ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<?php $kdna_ei_error( 'general.default_range' ); ?>
					</div>
					<div>
						<label class="kdna-ei-visually-hidden" for="kdna-ei-set-compare"><?php esc_html_e( 'Compared with', 'kdna-ecommerce-insights' ); ?></label>
						<select id="kdna-ei-set-compare" class="kdna-ei-select" x-model="form.general.comparison">
							<?php foreach ( KDNA_EcommerceInsights_Settings::comparison_modes() as $kdna_ei_key => $kdna_ei_label ) : ?>
								<option value="<?php echo esc_attr( $kdna_ei_key ); ?>"><?php echo esc_html( $kdna_ei_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<div class="kdna-ei-notice kdna-ei-notice--warning kdna-ei-settings-panel__note" x-show="rulesChanged" x-cloak>
				<p><?php esc_html_e( 'Changing which orders count, which day they belong to, refunds or refunded items re-works your figures in the background after you save. The dashboard stays usable while it runs, and a progress bar shows at the top.', 'kdna-ecommerce-insights' ); ?></p>
			</div>

			<?php $kdna_ei_footer( 'general' ); ?>
		</section>
		</template>

		<?php // ================================================= Costs. ?>
		<template x-if="loaded">
		<section class="kdna-ei-card kdna-ei-settings-panel" x-show="settingsTab === 'costs'" aria-labelledby="kdna-ei-set-costs">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-set-costs" class="kdna-ei-card__title"><?php esc_html_e( 'Costs', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Cost prices and cost rules are edited on the Costs screen, where you can see every product and payment method together. Here is a summary, with a shortcut to each.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Cost price field on the product screen', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Where the Cost price box sits when you edit a product in WooCommerce.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<?php
					$kdna_ei_choices(
						'form.costs.cost_field_placement',
						'kdna-ei-cost-placement',
						array(
							'after_regular_price' => array( __( 'After the regular price', 'kdna-ecommerce-insights' ), __( 'Next to what you charge, so margin is easy to judge.', 'kdna-ecommerce-insights' ) ),
							'after_sale_price'    => array( __( 'After the sale price', 'kdna-ecommerce-insights' ), __( 'Below both prices.', 'kdna-ecommerce-insights' ) ),
						)
					);
					$kdna_ei_error( 'costs.cost_field_placement' );
					?>
				</div>
			</div>

			<ul class="kdna-ei-summary-list">
				<li>
					<div>
						<p class="kdna-ei-summary-list__title"><?php esc_html_e( 'Product costs', 'kdna-ecommerce-insights' ); ?></p>
						<p class="kdna-ei-muted" x-text="context.costs.missing_costs ? sprintf( t.missingCosts, context.costs.missing_costs ) : t.allCosts"></p>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="openCosts( 'products' )"><?php esc_html_e( 'Edit product costs', 'kdna-ecommerce-insights' ); ?></button>
				</li>
				<li>
					<div>
						<p class="kdna-ei-summary-list__title"><?php esc_html_e( 'Payment fee rules', 'kdna-ecommerce-insights' ); ?></p>
						<p class="kdna-ei-muted" x-text="sprintf( t.ruleCount, context.costs.gateway_rules )"></p>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="openCosts( 'fees' )"><?php esc_html_e( 'Edit payment fees', 'kdna-ecommerce-insights' ); ?></button>
				</li>
				<li>
					<div>
						<p class="kdna-ei-summary-list__title"><?php esc_html_e( 'Shipping cost rules', 'kdna-ecommerce-insights' ); ?></p>
						<p class="kdna-ei-muted" x-text="sprintf( t.ruleCount, context.costs.shipping_rules ) + ( context.costs.meta_key ? ' ' + sprintf( t.metaKey, context.costs.meta_key ) : '' )"></p>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="openCosts( 'shipping' )"><?php esc_html_e( 'Edit shipping costs', 'kdna-ecommerce-insights' ); ?></button>
				</li>
				<li>
					<div>
						<p class="kdna-ei-summary-list__title"><?php esc_html_e( 'Extra costs on every order', 'kdna-ecommerce-insights' ); ?></p>
						<p class="kdna-ei-muted" x-text="sprintf( t.extraCount, context.costs.extra_costs )"></p>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="openCosts( 'extras' )"><?php esc_html_e( 'Edit extra costs', 'kdna-ecommerce-insights' ); ?></button>
				</li>
				<li>
					<div>
						<p class="kdna-ei-summary-list__title"><?php esc_html_e( 'Overheads', 'kdna-ecommerce-insights' ); ?></p>
						<p class="kdna-ei-muted" x-text="sprintf( t.overheadCount, context.costs.overheads )"></p>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="openCosts( 'overheads' )"><?php esc_html_e( 'Edit overheads', 'kdna-ecommerce-insights' ); ?></button>
				</li>
				<li>
					<div>
						<p class="kdna-ei-summary-list__title"><?php esc_html_e( 'Recalculation tools', 'kdna-ecommerce-insights' ); ?></p>
						<p class="kdna-ei-muted"><?php esc_html_e( 'Apply changed costs or rules to past orders.', 'kdna-ecommerce-insights' ); ?></p>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="openCosts( 'recalc' )"><?php esc_html_e( 'Open recalculation', 'kdna-ecommerce-insights' ); ?></button>
				</li>
			</ul>

			<?php $kdna_ei_footer( 'costs', false ); ?>
		</section>
		</template>

		<?php // ================================================= Marketing. ?>
		<template x-if="loaded">
		<section class="kdna-ei-card kdna-ei-settings-panel" x-show="settingsTab === 'marketing'" aria-labelledby="kdna-ei-set-marketing">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-set-marketing" class="kdna-ei-card__title"><?php esc_html_e( 'Marketing', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Ad channels, saved CSV column layouts and the live Meta and Google Ads connections.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Ad channels', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'The channels you can add spend to. Renaming a channel keeps its spend. A channel with spend cannot be removed.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<ul class="kdna-ei-channel-list">
						<template x-for="( channel, index ) in form.marketing.channels" :key="channel.uid">
							<li>
								<label class="kdna-ei-visually-hidden" :for="'kdna-ei-channel-' + channel.uid"><?php esc_html_e( 'Channel name', 'kdna-ecommerce-insights' ); ?></label>
								<input type="text" class="kdna-ei-input" :id="'kdna-ei-channel-' + channel.uid" x-model="channel.label" maxlength="40" :class="{ 'is-invalid': err( 'marketing.channels.' + index + '.label' ) }" />
								<span class="kdna-ei-muted kdna-ei-channel-list__use" x-text="channelUse( channel )"></span>
								<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="removeChannel( index )" :disabled="channelLocked( channel )" :title="channelLocked( channel ) ? t.channelLocked : t.removeChannel" :aria-label="t.removeChannel + ': ' + channel.label">&times;</button>
								<p class="kdna-ei-field-error kdna-ei-channel-list__error" x-show="err( 'marketing.channels.' + index + '.label' )" x-text="err( 'marketing.channels.' + index + '.label' )"></p>
							</li>
						</template>
					</ul>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="addChannel()">+ <?php esc_html_e( 'Add a channel', 'kdna-ecommerce-insights' ); ?></button>
					<?php $kdna_ei_error( 'marketing.channels' ); ?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Saved CSV layouts', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Column layouts saved while importing ad spend. Meta and Google Ads layouts are built in.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<p class="kdna-ei-muted" x-show="! savedPresets.length"><?php esc_html_e( 'None saved yet.', 'kdna-ecommerce-insights' ); ?></p>
					<ul class="kdna-ei-summary-list kdna-ei-summary-list--compact" x-show="savedPresets.length">
						<template x-for="preset in savedPresets" :key="preset.name">
							<li>
								<div>
									<p class="kdna-ei-summary-list__title" x-text="preset.name"></p>
									<p class="kdna-ei-muted" x-text="presetText( preset )"></p>
								</div>
								<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="deletePreset( preset )" :disabled="busy !== ''"><?php esc_html_e( 'Delete', 'kdna-ecommerce-insights' ); ?></button>
							</li>
						</template>
					</ul>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Live connections', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Set up, test and sync on the Marketing screen. Keys are stored encrypted and never shown again.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<ul class="kdna-ei-summary-list kdna-ei-summary-list--compact">
						<template x-for="connection in context.connections" :key="connection.key">
							<li>
								<div>
									<p class="kdna-ei-summary-list__title">
										<span x-text="connection.label"></span>
										<span class="kdna-ei-badge" :class="connectionBadge( connection )" x-text="t.states[ connection.state ] || connection.state"></span>
									</p>
									<p class="kdna-ei-muted" x-text="connectionText( connection )"></p>
								</div>
								<a href="#/marketing" class="kdna-ei-btn kdna-ei-btn--small"><?php esc_html_e( 'Manage', 'kdna-ecommerce-insights' ); ?></a>
							</li>
						</template>
					</ul>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-rate"><?php esc_html_e( 'Ad account currency rate', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help" x-text="sprintf( t.rateHelp, context.currency )"></p>
				</div>
				<div>
					<input id="kdna-ei-set-rate" type="text" inputmode="decimal" class="kdna-ei-input kdna-ei-setting__narrow" x-model="form.marketing.ad_currency_rate" :class="{ 'is-invalid': err( 'marketing.ad_currency_rate' ) }" />
					<?php $kdna_ei_error( 'marketing.ad_currency_rate' ); ?>
				</div>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Sync connected platforms', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'Each sync re-reads the last 7 days, because platforms keep adjusting recent figures.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<?php
					$kdna_ei_choices(
						'form.marketing.sync_frequency',
						'kdna-ei-sync-frequency',
						array(
							'daily'       => array( __( 'Every morning', 'kdna-ecommerce-insights' ), __( 'Recommended. At about 5am.', 'kdna-ecommerce-insights' ) ),
							'twice_daily' => array( __( 'Twice a day', 'kdna-ecommerce-insights' ), __( 'At about 5am and 5pm.', 'kdna-ecommerce-insights' ) ),
							'manual'      => array( __( 'Only when I press Sync now', 'kdna-ecommerce-insights' ), __( 'Nothing runs on a schedule.', 'kdna-ecommerce-insights' ) ),
						)
					);
					?>
				</div>
			</div>

			<?php $kdna_ei_footer( 'marketing', false ); ?>
		</section>
		</template>

		<?php // ================================================= Tax. ?>
		<template x-if="loaded">
		<section class="kdna-ei-card kdna-ei-settings-panel" x-show="settingsTab === 'tax'" aria-labelledby="kdna-ei-set-tax">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-set-tax" class="kdna-ei-card__title"><?php esc_html_e( 'Tax', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'How the tax summary on Tax & Reports is worked out. It is always a guide for your bookkeeper, not a lodgement.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<a href="#/reports" class="kdna-ei-btn kdna-ei-btn--small"><?php esc_html_e( 'See the tax summary', 'kdna-ecommerce-insights' ); ?></a>
			</div>

			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Tax system', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<?php
					$kdna_ei_choices(
						'form.tax.system',
						'kdna-ei-tax-system',
						array(
							'au_gst'    => array( __( 'Australian GST', 'kdna-ecommerce-insights' ), __( 'Shows the BAS lines G1, 1A and 1B. Financial years run July to June.', 'kdna-ecommerce-insights' ) ),
							'vat'       => array( __( 'VAT, or GST outside Australia', 'kdna-ecommerce-insights' ), __( 'Tax on sales less tax on costs. Financial years run January to December.', 'kdna-ecommerce-insights' ) ),
							'sales_tax' => array( __( 'Sales tax', 'kdna-ecommerce-insights' ), __( 'Shows the tax collected on orders. Nothing is claimed back on costs.', 'kdna-ecommerce-insights' ) ),
							'none'      => array( __( 'No tax reporting', 'kdna-ecommerce-insights' ), __( 'Hides the tax summary.', 'kdna-ecommerce-insights' ) ),
						)
					);
					$kdna_ei_error( 'tax.system' );
					?>
				</div>
			</div>

			<div class="kdna-ei-setting" x-show="form.tax.system === 'au_gst' || form.tax.system === 'vat'">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-tax-rate"><?php esc_html_e( 'Rate (%)', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help"><?php esc_html_e( 'Used to work out the tax inside overheads and ad spend marked as including tax. 10 for Australian GST.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<input id="kdna-ei-set-tax-rate" type="text" inputmode="decimal" class="kdna-ei-input kdna-ei-setting__narrow" x-model="form.tax.rate" :class="{ 'is-invalid': err( 'tax.rate' ) }" />
					<?php $kdna_ei_error( 'tax.rate' ); ?>
				</div>
			</div>

			<div class="kdna-ei-setting" x-show="form.tax.system !== 'none'">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'You report', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'The grouping the tax summary opens with. You can switch it on the summary too.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'You report', 'kdna-ecommerce-insights' ); ?>">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.tax.reporting_period === 'monthly' ? 'true' : 'false'" @click="form.tax.reporting_period = 'monthly'"><?php esc_html_e( 'Monthly', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.tax.reporting_period === 'quarterly' ? 'true' : 'false'" @click="form.tax.reporting_period = 'quarterly'"><?php esc_html_e( 'Quarterly', 'kdna-ecommerce-insights' ); ?></button>
					</div>
				</div>
			</div>

			<?php $kdna_ei_footer( 'tax' ); ?>
		</section>
		</template>

		<?php // ================================================= Branding. ?>
		<template x-if="loaded">
		<section class="kdna-ei-card kdna-ei-settings-panel kdna-ei-branding" x-show="settingsTab === 'branding'" aria-labelledby="kdna-ei-set-branding">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-set-branding" class="kdna-ei-card__title"><?php esc_html_e( 'Branding', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Make Insights look like the store: name, logo, font and colours. Used by the dashboard, the charts, the email digest and the printable report.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<div class="kdna-ei-branding__layout">
				<div class="kdna-ei-branding__fields">
					<div class="kdna-ei-field">
						<label class="kdna-ei-field-label" for="kdna-ei-set-store"><?php esc_html_e( 'Store display name', 'kdna-ecommerce-insights' ); ?></label>
						<input id="kdna-ei-set-store" type="text" class="kdna-ei-input" x-model="form.branding.store_name" maxlength="60" :placeholder="context.site_name" :class="{ 'is-invalid': err( 'branding.store_name' ) }" />
						<p class="kdna-ei-help"><?php esc_html_e( 'Shown above each page title and in emails. Leave empty to use the site title.', 'kdna-ecommerce-insights' ); ?></p>
						<?php $kdna_ei_error( 'branding.store_name' ); ?>
					</div>

					<div class="kdna-ei-field">
						<p class="kdna-ei-field-label"><?php esc_html_e( 'Logo', 'kdna-ecommerce-insights' ); ?></p>
						<div class="kdna-ei-logo-picker">
							<span class="kdna-ei-logo-picker__preview">
								<template x-if="logo.url"><img :src="logo.thumb || logo.url" alt="" /></template>
								<template x-if="! logo.url"><span x-text="initial"></span></template>
							</span>
							<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="chooseLogo()" x-text="logo.url ? t.changeLogo : t.chooseLogo"></button>
							<button type="button" class="kdna-ei-link" x-show="logo.url" @click="removeLogo()"><?php esc_html_e( 'Remove', 'kdna-ecommerce-insights' ); ?></button>
						</div>
						<p class="kdna-ei-help"><?php esc_html_e( 'A square or wide PNG or JPG works everywhere. SVG logos show in the dashboard, but emails show the store name instead, because most email apps cannot display SVG.', 'kdna-ecommerce-insights' ); ?></p>
						<?php $kdna_ei_error( 'branding.logo_id' ); ?>
					</div>

					<fieldset class="kdna-ei-field">
						<legend class="kdna-ei-field-label"><?php esc_html_e( 'Font', 'kdna-ecommerce-insights' ); ?></legend>
						<div class="kdna-ei-font-list">
							<?php foreach ( KDNA_EcommerceInsights_Settings::fonts() as $kdna_ei_key => $kdna_ei_family ) : ?>
								<?php $kdna_ei_stack = 'inherit' === $kdna_ei_key ? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' : '"' . $kdna_ei_family . '", sans-serif'; ?>
								<label class="kdna-ei-font" :class="{ 'is-selected': form.branding.font === '<?php echo esc_js( $kdna_ei_key ); ?>' }">
									<input type="radio" name="kdna-ei-font" value="<?php echo esc_attr( $kdna_ei_key ); ?>" x-model="form.branding.font" class="kdna-ei-visually-hidden" />
									<span class="kdna-ei-font__sample" style="font-family: <?php echo esc_attr( $kdna_ei_stack ); ?>;">Aa 1,234</span>
									<span class="kdna-ei-font__name"><?php echo esc_html( $kdna_ei_family ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="kdna-ei-help"><?php esc_html_e( 'Every font is stored on this website, so nothing is loaded from Google. "Inherit from site" uses the computer\'s own font here, and the theme\'s font in Elementor widgets.', 'kdna-ecommerce-insights' ); ?></p>
					</fieldset>

					<div class="kdna-ei-field">
						<p class="kdna-ei-field-label"><?php esc_html_e( 'Starting theme', 'kdna-ecommerce-insights' ); ?></p>
						<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'Starting theme', 'kdna-ecommerce-insights' ); ?>">
							<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.branding.default_theme === 'dark' ? 'true' : 'false'" @click="form.branding.default_theme = 'dark'; previewTheme = 'dark'"><?php esc_html_e( 'Dark', 'kdna-ecommerce-insights' ); ?></button>
							<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.branding.default_theme === 'light' ? 'true' : 'false'" @click="form.branding.default_theme = 'light'; previewTheme = 'light'"><?php esc_html_e( 'Light', 'kdna-ecommerce-insights' ); ?></button>
						</div>
						<p class="kdna-ei-help"><?php esc_html_e( 'What a new user sees first. Everyone can switch with the sun and moon button, and Insights remembers each person\'s choice.', 'kdna-ecommerce-insights' ); ?></p>
					</div>

					<?php foreach ( array( 'dark' => __( 'Colours in dark mode', 'kdna-ecommerce-insights' ), 'light' => __( 'Colours in light mode', 'kdna-ecommerce-insights' ) ) as $kdna_ei_theme => $kdna_ei_heading ) : ?>
						<fieldset class="kdna-ei-field kdna-ei-colours" @focusin="previewTheme = '<?php echo esc_js( $kdna_ei_theme ); ?>'">
							<legend class="kdna-ei-field-label"><?php echo esc_html( $kdna_ei_heading ); ?></legend>
							<template x-for="colour in colourKeys" :key="'<?php echo esc_js( $kdna_ei_theme ); ?>' + colour.key">
								<div class="kdna-ei-colour">
									<input type="color" class="kdna-ei-colour__swatch" :value="form.branding.colours.<?php echo esc_js( $kdna_ei_theme ); ?>[ colour.key ]" @input="form.branding.colours.<?php echo esc_js( $kdna_ei_theme ); ?>[ colour.key ] = $event.target.value.toUpperCase()" :aria-label="colour.label + ' (<?php echo esc_js( 'dark' === $kdna_ei_theme ? __( 'dark mode', 'kdna-ecommerce-insights' ) : __( 'light mode', 'kdna-ecommerce-insights' ) ); ?>)'" />
									<div class="kdna-ei-colour__text">
										<span class="kdna-ei-colour__label" x-text="colour.label"></span>
										<span class="kdna-ei-muted" x-text="colour.help"></span>
										<span class="kdna-ei-colour__warning" x-show="contrastWarning( '<?php echo esc_js( $kdna_ei_theme ); ?>', colour.key )" x-text="contrastWarning( '<?php echo esc_js( $kdna_ei_theme ); ?>', colour.key )"></span>
									</div>
									<input type="text" class="kdna-ei-input kdna-ei-colour__hex" maxlength="7" spellcheck="false" x-model="form.branding.colours.<?php echo esc_js( $kdna_ei_theme ); ?>[ colour.key ]" :class="{ 'is-invalid': err( 'branding.colours.<?php echo esc_js( $kdna_ei_theme ); ?>.' + colour.key ) }" :aria-label="colour.label + ' <?php echo esc_js( __( 'colour code', 'kdna-ecommerce-insights' ) ); ?>'" />
									<p class="kdna-ei-field-error kdna-ei-colour__error" x-show="err( 'branding.colours.<?php echo esc_js( $kdna_ei_theme ); ?>.' + colour.key )" x-text="err( 'branding.colours.<?php echo esc_js( $kdna_ei_theme ); ?>.' + colour.key )"></p>
								</div>
							</template>
						</fieldset>
					<?php endforeach; ?>

					<div class="kdna-ei-field">
						<label class="kdna-ei-field-label" for="kdna-ei-set-css"><?php esc_html_e( 'Custom CSS', 'kdna-ecommerce-insights' ); ?></label>
						<textarea id="kdna-ei-set-css" class="kdna-ei-input kdna-ei-code" rows="6" spellcheck="false" x-model="form.branding.custom_css" :disabled="! context.can_edit_css" :class="{ 'is-invalid': err( 'branding.custom_css' ) }" placeholder=".kdna-ei-root { --kdna-ei-radius: 12px; }"></textarea>
						<p class="kdna-ei-help" x-show="context.can_edit_css"><?php esc_html_e( 'For small finishing touches only, added after everything else. Changes to CSS variables such as --kdna-ei-radius flow through the whole dashboard.', 'kdna-ecommerce-insights' ); ?></p>
						<p class="kdna-ei-help" x-show="! context.can_edit_css"><?php esc_html_e( 'Only a site or network administrator who can add unfiltered HTML can change the custom CSS.', 'kdna-ecommerce-insights' ); ?></p>
						<?php $kdna_ei_error( 'branding.custom_css' ); ?>
					</div>
				</div>

				<?php // Live preview: a small copy of the dashboard in the chosen look. ?>
				<div class="kdna-ei-branding__preview">
					<div class="kdna-ei-preview-card__bar">
						<p class="kdna-ei-field-label"><?php esc_html_e( 'Live preview', 'kdna-ecommerce-insights' ); ?></p>
						<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'Preview theme', 'kdna-ecommerce-insights' ); ?>">
							<button type="button" class="kdna-ei-segmented__item" :aria-pressed="previewTheme === 'dark' ? 'true' : 'false'" @click="previewTheme = 'dark'"><?php esc_html_e( 'Dark', 'kdna-ecommerce-insights' ); ?></button>
							<button type="button" class="kdna-ei-segmented__item" :aria-pressed="previewTheme === 'light' ? 'true' : 'false'" @click="previewTheme = 'light'"><?php esc_html_e( 'Light', 'kdna-ecommerce-insights' ); ?></button>
						</div>
					</div>
					<div class="kdna-ei-preview-card" :style="previewStyle" role="img" :aria-label="t.previewLabel">
						<div class="kdna-ei-preview-card__side">
							<span class="kdna-ei-preview-card__logo">
								<template x-if="logo.url"><img :src="logo.thumb || logo.url" alt="" /></template>
								<template x-if="! logo.url"><span x-text="initial"></span></template>
							</span>
							<span class="kdna-ei-preview-card__nav is-active"></span>
							<span class="kdna-ei-preview-card__nav"></span>
							<span class="kdna-ei-preview-card__nav"></span>
						</div>
						<div class="kdna-ei-preview-card__main">
							<p class="kdna-ei-preview-card__eyebrow"><span x-text="displayName"></span> / <?php esc_html_e( 'Overview', 'kdna-ecommerce-insights' ); ?></p>
							<p class="kdna-ei-preview-card__title"><?php esc_html_e( 'Overview', 'kdna-ecommerce-insights' ); ?></p>
							<div class="kdna-ei-preview-card__kpis">
								<div>
									<span class="kdna-ei-preview-card__label"><?php esc_html_e( 'Net revenue', 'kdna-ecommerce-insights' ); ?></span>
									<span class="kdna-ei-preview-card__value">$24,850</span>
									<span class="kdna-ei-preview-card__up">&#9650; 12.4%</span>
								</div>
								<div>
									<span class="kdna-ei-preview-card__label"><?php esc_html_e( 'Net profit', 'kdna-ecommerce-insights' ); ?></span>
									<span class="kdna-ei-preview-card__value">$8,120</span>
									<span class="kdna-ei-preview-card__down">&#9660; 3.1%</span>
								</div>
							</div>
							<div class="kdna-ei-preview-card__panels">
								<svg class="kdna-ei-preview-card__chart" viewBox="0 0 220 90" aria-hidden="true">
									<defs>
										<linearGradient id="kdna-ei-preview-fill" x1="0" y1="0" x2="0" y2="1">
											<stop offset="0" stop-color="var(--kdna-ei-accent)" stop-opacity="0.35" />
											<stop offset="1" stop-color="var(--kdna-ei-accent)" stop-opacity="0" />
										</linearGradient>
									</defs>
									<line x1="0" x2="220" y1="22" y2="22" stroke="var(--kdna-ei-border)" />
									<line x1="0" x2="220" y1="52" y2="52" stroke="var(--kdna-ei-border)" />
									<line x1="0" x2="220" y1="82" y2="82" stroke="var(--kdna-ei-border)" />
									<path d="M0 64 C30 58 45 70 70 52 S120 40 140 44 S190 18 220 20 L220 90 L0 90 Z" fill="url(#kdna-ei-preview-fill)" />
									<path d="M0 70 C30 66 50 72 75 62 S125 58 145 54 S195 44 220 40" fill="none" stroke="var(--kdna-ei-accent-2)" stroke-width="2" stroke-dasharray="4 3" />
									<path d="M0 64 C30 58 45 70 70 52 S120 40 140 44 S190 18 220 20" fill="none" stroke="var(--kdna-ei-accent)" stroke-width="2.5" />
									<circle cx="220" cy="20" r="4" fill="var(--kdna-ei-surface)" stroke="var(--kdna-ei-accent)" stroke-width="2" />
								</svg>
								<svg class="kdna-ei-preview-card__donut" viewBox="0 0 42 42" aria-hidden="true">
									<circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--kdna-ei-accent)" stroke-width="6" stroke-dasharray="52 48" stroke-dashoffset="25" />
									<circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--kdna-ei-accent-2)" stroke-width="6" stroke-dasharray="22 78" stroke-dashoffset="73" />
									<circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--kdna-ei-positive)" stroke-width="6" stroke-dasharray="12 88" stroke-dashoffset="51" />
									<circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--kdna-ei-warning)" stroke-width="6" stroke-dasharray="8 92" stroke-dashoffset="39" />
									<circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--kdna-ei-negative)" stroke-width="6" stroke-dasharray="6 94" stroke-dashoffset="31" />
								</svg>
							</div>
							<div class="kdna-ei-preview-card__badges">
								<span class="kdna-ei-preview-card__badge is-positive"><?php esc_html_e( 'In stock', 'kdna-ecommerce-insights' ); ?></span>
								<span class="kdna-ei-preview-card__badge is-warning"><?php esc_html_e( 'Low stock', 'kdna-ecommerce-insights' ); ?></span>
								<span class="kdna-ei-preview-card__badge is-negative"><?php esc_html_e( 'Loss', 'kdna-ecommerce-insights' ); ?></span>
								<span class="kdna-ei-preview-card__button"><?php esc_html_e( 'Save', 'kdna-ecommerce-insights' ); ?></span>
							</div>
						</div>
					</div>
					<p class="kdna-ei-help"><?php esc_html_e( 'Saving applies the new look straight away everywhere in Insights, including the charts.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<?php $kdna_ei_footer( 'branding' ); ?>
		</section>
		</template>

		<?php // ================================================= Hero card and Goals. ?>
		<template x-if="loaded">
		<section class="kdna-ei-card kdna-ei-settings-panel kdna-ei-hero-settings" x-show="settingsTab === 'hero'" aria-labelledby="kdna-ei-set-hero">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-set-hero" class="kdna-ei-card__title"><?php esc_html_e( 'Hero card', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'The large card on the right of the Overview. Choose what it shows for everyone who uses Insights.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<?php
			$kdna_ei_choices(
				'form.hero.type',
				'kdna-ei-hero-type',
				array(
					'top_products'     => array( __( 'Top products', 'kdna-ecommerce-insights' ), __( 'Your five best sellers for the chosen dates, ranked by profit or revenue, with their margin.', 'kdna-ecommerce-insights' ) ),
					'profit_breakdown' => array( __( 'Profit breakdown', 'kdna-ecommerce-insights' ), __( 'How net revenue turns into net profit: product costs, fees, shipping, extra costs, ads and overheads.', 'kdna-ecommerce-insights' ) ),
					'goals'            => array( __( 'Goals tracker', 'kdna-ecommerce-insights' ), __( 'Progress towards a monthly target, whether you are on track and what is needed each day.', 'kdna-ecommerce-insights' ) ),
				)
			);
			?>

			<h3 class="kdna-ei-card__title kdna-ei-hero-settings__heading"><?php esc_html_e( 'Monthly goals', 'kdna-ecommerce-insights' ); ?></h3>
			<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Targets for each calendar month. The Goals tracker follows the one you choose to track, and shows whether you are on track and what is needed each day. Leave a target empty if you do not use it.', 'kdna-ecommerce-insights' ); ?></p>

			<div class="kdna-ei-hero-settings__grid">
				<label>
					<span class="kdna-ei-field-label"><?php esc_html_e( 'Track', 'kdna-ecommerce-insights' ); ?></span>
					<select class="kdna-ei-select" x-model="form.hero.goal_metric">
						<option value="revenue"><?php esc_html_e( 'Net revenue', 'kdna-ecommerce-insights' ); ?></option>
						<option value="profit"><?php esc_html_e( 'Net profit', 'kdna-ecommerce-insights' ); ?></option>
						<option value="orders"><?php esc_html_e( 'Orders', 'kdna-ecommerce-insights' ); ?></option>
					</select>
				</label>
				<div>
					<span class="kdna-ei-field-label"><?php esc_html_e( 'Revenue target', 'kdna-ecommerce-insights' ); ?></span>
					<label class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': err( 'hero.goal_targets.revenue' ) }">
						<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Monthly revenue target', 'kdna-ecommerce-insights' ); ?></span>
						<span class="kdna-ei-affix__before" aria-hidden="true" x-text="symbol"></span>
						<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0" x-model="form.hero.revenue" />
					</label>
					<?php $kdna_ei_error( 'hero.goal_targets.revenue' ); ?>
				</div>
				<div>
					<span class="kdna-ei-field-label"><?php esc_html_e( 'Profit target', 'kdna-ecommerce-insights' ); ?></span>
					<label class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': err( 'hero.goal_targets.profit' ) }">
						<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Monthly profit target', 'kdna-ecommerce-insights' ); ?></span>
						<span class="kdna-ei-affix__before" aria-hidden="true" x-text="symbol"></span>
						<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0" x-model="form.hero.profit" />
					</label>
					<?php $kdna_ei_error( 'hero.goal_targets.profit' ); ?>
				</div>
				<div>
					<label>
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Orders target', 'kdna-ecommerce-insights' ); ?></span>
						<input type="text" inputmode="numeric" class="kdna-ei-input" :class="{ 'is-invalid': err( 'hero.goal_targets.orders' ) }" placeholder="0" x-model="form.hero.orders" />
					</label>
					<?php $kdna_ei_error( 'hero.goal_targets.orders' ); ?>
				</div>
			</div>
			<p class="kdna-ei-notice kdna-ei-notice--warning kdna-ei-settings-panel__note" x-show="form.hero.type === 'goals' && ! goalSet" x-text="t.noGoal"></p>

			<?php $kdna_ei_footer( 'hero' ); ?>
		</section>
		</template>

		<?php // ================================================= Alerts and digests. ?>
		<template x-if="loaded">
		<section class="kdna-ei-card kdna-ei-settings-panel" x-show="settingsTab === 'alerts'" aria-labelledby="kdna-ei-set-alerts">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-set-alerts" class="kdna-ei-card__title"><?php esc_html_e( 'Alerts and digests', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'When stock counts as low, and which emails Insights sends.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<h3 class="kdna-ei-settings-panel__heading"><?php esc_html_e( 'Stock', 'kdna-ecommerce-insights' ); ?></h3>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-low"><?php esc_html_e( 'Low stock at', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help" x-text="sprintf( t.lowStockHelp, context.store_threshold )"></p>
				</div>
				<div>
					<span class="kdna-ei-affix kdna-ei-affix--after kdna-ei-setting__narrow" :class="{ 'is-invalid': err( 'alerts.low_stock_threshold' ) }">
						<input id="kdna-ei-set-low" type="text" inputmode="numeric" class="kdna-ei-input" x-model="form.alerts.low_stock_threshold" />
						<span class="kdna-ei-affix__after"><?php esc_html_e( 'units', 'kdna-ecommerce-insights' ); ?></span>
					</span>
					<?php $kdna_ei_error( 'alerts.low_stock_threshold' ); ?>
				</div>
			</div>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-dead"><?php esc_html_e( 'Dead stock after', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help"><?php esc_html_e( 'A product in stock with no sale for this long is listed as dead stock.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<span class="kdna-ei-affix kdna-ei-affix--after kdna-ei-setting__narrow" :class="{ 'is-invalid': err( 'alerts.dead_stock_days' ) }">
						<input id="kdna-ei-set-dead" type="text" inputmode="numeric" class="kdna-ei-input" x-model="form.alerts.dead_stock_days" />
						<span class="kdna-ei-affix__after"><?php esc_html_e( 'days', 'kdna-ecommerce-insights' ); ?></span>
					</span>
					<?php $kdna_ei_error( 'alerts.dead_stock_days' ); ?>
				</div>
			</div>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-lead"><?php esc_html_e( 'Reorder lead time', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help"><?php esc_html_e( 'How long a reorder takes to arrive. Suggested reorder dates are this many days before stock runs out.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<span class="kdna-ei-affix kdna-ei-affix--after kdna-ei-setting__narrow" :class="{ 'is-invalid': err( 'alerts.reorder_lead_days' ) }">
						<input id="kdna-ei-set-lead" type="text" inputmode="numeric" class="kdna-ei-input" x-model="form.alerts.reorder_lead_days" />
						<span class="kdna-ei-affix__after"><?php esc_html_e( 'days', 'kdna-ecommerce-insights' ); ?></span>
					</span>
					<?php $kdna_ei_error( 'alerts.reorder_lead_days' ); ?>
				</div>
			</div>

			<h3 class="kdna-ei-settings-panel__heading"><?php esc_html_e( 'Email alerts', 'kdna-ecommerce-insights' ); ?></h3>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'Email me when', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div class="kdna-ei-setting__stack">
					<label class="kdna-ei-switch">
						<input type="checkbox" x-model="form.alerts.low_stock_emails" />
						<span class="kdna-ei-switch__track" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Products become low or out of stock', 'kdna-ecommerce-insights' ); ?></span>
					</label>
					<label class="kdna-ei-switch">
						<input type="checkbox" x-model="form.alerts.sync_failure_emails" />
						<span class="kdna-ei-switch__track" aria-hidden="true"></span>
						<span><?php esc_html_e( 'A Meta or Google Ads sync stops working', 'kdna-ecommerce-insights' ); ?></span>
					</label>
					<p class="kdna-ei-help"><?php esc_html_e( 'Stock emails bundle everything that ran low in ten minutes. Sync emails are sent once when a connection starts failing, not on every retry.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-alert-to"><?php esc_html_e( 'Send alerts to', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help"><?php esc_html_e( 'Separate more than one address with commas.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<input id="kdna-ei-set-alert-to" type="text" class="kdna-ei-input" x-model="form.alerts.alert_recipients" placeholder="you@example.com" spellcheck="false" :class="{ 'is-invalid': err( 'alerts.alert_recipients' ) }" />
					<?php $kdna_ei_error( 'alerts.alert_recipients' ); ?>
					<div class="kdna-ei-setting__actions">
						<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="testAlert()" :disabled="busy !== '' || isDirty( 'alerts' )" x-text="busy === 'test-alert' ? t.sending : t.testAlert"></button>
						<span class="kdna-ei-muted" x-show="isDirty( 'alerts' )" x-text="t.saveFirst"></span>
					</div>
				</div>
			</div>

			<h3 class="kdna-ei-settings-panel__heading"><?php esc_html_e( 'Email digest', 'kdna-ecommerce-insights' ); ?></h3>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label"><?php esc_html_e( 'How often', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help"><?php esc_html_e( 'A branded summary: weekly on the first day of your week, or monthly on the 1st, at about 7am.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'How often', 'kdna-ecommerce-insights' ); ?>">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.alerts.digest_frequency === 'off' ? 'true' : 'false'" @click="form.alerts.digest_frequency = 'off'"><?php esc_html_e( 'Off', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.alerts.digest_frequency === 'weekly' ? 'true' : 'false'" @click="form.alerts.digest_frequency = 'weekly'"><?php esc_html_e( 'Weekly', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.alerts.digest_frequency === 'monthly' ? 'true' : 'false'" @click="form.alerts.digest_frequency = 'monthly'"><?php esc_html_e( 'Monthly', 'kdna-ecommerce-insights' ); ?></button>
					</div>
					<?php $kdna_ei_error( 'alerts.digest_frequency' ); ?>
				</div>
			</div>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<label class="kdna-ei-field-label" for="kdna-ei-set-digest-to"><?php esc_html_e( 'Send the digest to', 'kdna-ecommerce-insights' ); ?></label>
					<p class="kdna-ei-help"><?php esc_html_e( 'Your bookkeeper or business partner can get it too.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<input id="kdna-ei-set-digest-to" type="text" class="kdna-ei-input" x-model="form.alerts.digest_recipients" placeholder="you@example.com, team@example.com" spellcheck="false" :class="{ 'is-invalid': err( 'alerts.digest_recipients' ) }" />
					<?php $kdna_ei_error( 'alerts.digest_recipients' ); ?>
				</div>
			</div>
			<div class="kdna-ei-setting">
				<div class="kdna-ei-setting__label">
					<p class="kdna-ei-field-label" id="kdna-ei-set-sections"><?php esc_html_e( 'Include', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div>
					<div class="kdna-ei-checks kdna-ei-checks--two" role="group" aria-labelledby="kdna-ei-set-sections">
						<?php foreach ( KDNA_EcommerceInsights_Digest::sections() as $kdna_ei_key => $kdna_ei_label ) : ?>
							<label class="kdna-ei-check">
								<input type="checkbox" :checked="form.alerts.digest_sections.indexOf( '<?php echo esc_js( $kdna_ei_key ); ?>' ) !== -1" @change="toggleIn( form.alerts.digest_sections, '<?php echo esc_js( $kdna_ei_key ); ?>', sectionOrder )" />
								<span><?php echo esc_html( $kdna_ei_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<?php $kdna_ei_error( 'alerts.digest_sections' ); ?>
					<p class="kdna-ei-help" x-text="digestText"></p>
					<div class="kdna-ei-setting__actions">
						<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="testDigest()" :disabled="busy !== '' || ! form.alerts.digest_sections.length" x-text="busy === 'test-digest' ? t.sending : t.testDigest"></button>
						<a href="#/reports" class="kdna-ei-link"><?php esc_html_e( 'Preview it on Tax & Reports', 'kdna-ecommerce-insights' ); ?></a>
					</div>
				</div>
			</div>

			<?php $kdna_ei_footer( 'alerts' ); ?>
		</section>
		</template>

		<?php // ================================================= Data. ?>
		<div class="kdna-ei-stack" x-show="settingsTab === 'data'">
			<section class="kdna-ei-card">
				<div class="kdna-ei-card__header">
					<div>
						<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Order processing', 'kdna-ecommerce-insights' ); ?></h2>
						<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Insights works out the profit of every order in the background and keeps its own fast summary tables. New and changed orders are processed automatically within moments.', 'kdna-ecommerce-insights' ); ?></p>
					</div>
					<span class="kdna-ei-badge" :class="jobRunning ? 'kdna-ei-badge--warning' : ( status.job && status.job.status === 'complete' ? 'kdna-ei-badge--positive' : '' )" x-text="jobRunning ? i18nData.processing : ( status.job && status.job.status === 'complete' ? i18nData.upToDate : ( status.job && status.job.status === 'cancelled' ? i18nData.stopped : i18nData.notStarted ) )"></span>
				</div>

				<template x-if="jobRunning">
					<div class="kdna-ei-data-progress">
						<p><strong x-text="jobTitle"></strong></p>
						<div class="kdna-ei-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="status.job.percent">
							<span class="kdna-ei-progress__bar" :style="'width:' + status.job.percent + '%'"></span>
						</div>
						<p class="kdna-ei-muted kdna-ei-num" x-text="jobDetail"></p>
					</div>
				</template>

				<dl class="kdna-ei-facts">
					<div>
						<dt><?php esc_html_e( 'Orders processed', 'kdna-ecommerce-insights' ); ?></dt>
						<dd class="kdna-ei-num" x-text="( status.orders_processed || 0 ).toLocaleString()"></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Orders with products missing a cost', 'kdna-ecommerce-insights' ); ?></dt>
						<dd class="kdna-ei-num" :class="{ 'is-warning': status.orders_missing_costs > 0 }" x-text="( status.orders_missing_costs || 0 ).toLocaleString()"></dd>
					</div>
					<div x-show="status.orders_currency_flag > 0">
						<dt><?php esc_html_e( 'Orders in another currency without an exchange rate', 'kdna-ecommerce-insights' ); ?></dt>
						<dd class="kdna-ei-num is-warning" x-text="( status.orders_currency_flag || 0 ).toLocaleString()"></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Order storage', 'kdna-ecommerce-insights' ); ?></dt>
						<dd x-text="status.order_storage === 'hpos' ? i18nData.hpos : i18nData.legacy"></dd>
					</div>
					<div x-show="status.job && status.job.finished_at">
						<dt><?php esc_html_e( 'Last finished', 'kdna-ecommerce-insights' ); ?></dt>
						<dd x-text="status.job ? niceDateTime( status.job.finished_at ) : ''"></dd>
					</div>
				</dl>

				<template x-if="jobError">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="jobError"></p></div>
				</template>

				<div class="kdna-ei-card__footer">
					<template x-if="jobRunning">
						<button type="button" class="kdna-ei-btn" @click="cancelJob()"><?php esc_html_e( 'Stop processing', 'kdna-ecommerce-insights' ); ?></button>
					</template>
					<template x-if="! jobRunning && ! confirmRebuild">
						<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="confirmRebuild = true"><?php esc_html_e( 'Rebuild all data', 'kdna-ecommerce-insights' ); ?></button>
					</template>
					<template x-if="! jobRunning && confirmRebuild">
						<span class="kdna-ei-confirm">
							<span><?php esc_html_e( 'Work through every order again? The dashboard stays usable while it runs.', 'kdna-ecommerce-insights' ); ?></span>
							<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="startJob( 'all' ); confirmRebuild = false"><?php esc_html_e( 'Yes, rebuild', 'kdna-ecommerce-insights' ); ?></button>
							<button type="button" class="kdna-ei-link" @click="confirmRebuild = false"><?php esc_html_e( 'Cancel', 'kdna-ecommerce-insights' ); ?></button>
						</span>
					</template>
				</div>
			</section>

			<?php // Sync log viewer. ?>
			<section class="kdna-ei-card" aria-labelledby="kdna-ei-set-log">
				<div class="kdna-ei-card__header kdna-ei-log__header">
					<div>
						<h2 id="kdna-ei-set-log" class="kdna-ei-card__title"><?php esc_html_e( 'Sync log', 'kdna-ecommerce-insights' ); ?></h2>
						<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Everything Insights did in the background: order processing, ad syncs, imports, snapshots and emails. The latest 500 entries are kept.', 'kdna-ecommerce-insights' ); ?></p>
					</div>
					<div class="kdna-ei-log__filters">
						<label class="kdna-ei-visually-hidden" for="kdna-ei-log-type"><?php esc_html_e( 'Show', 'kdna-ecommerce-insights' ); ?></label>
						<select id="kdna-ei-log-type" class="kdna-ei-select kdna-ei-select--small" x-model="log.type" @change="loadLog( 1 )">
							<option value=""><?php esc_html_e( 'Everything', 'kdna-ecommerce-insights' ); ?></option>
							<template x-for="( label, key ) in log.types" :key="key">
								<option :value="key" x-text="label" :selected="log.type === key"></option>
							</template>
						</select>
						<label class="kdna-ei-visually-hidden" for="kdna-ei-log-status"><?php esc_html_e( 'Result', 'kdna-ecommerce-insights' ); ?></label>
						<select id="kdna-ei-log-status" class="kdna-ei-select kdna-ei-select--small" x-model="log.status" @change="loadLog( 1 )">
							<option value=""><?php esc_html_e( 'Any result', 'kdna-ecommerce-insights' ); ?></option>
							<option value="success"><?php esc_html_e( 'Done', 'kdna-ecommerce-insights' ); ?></option>
							<option value="warning"><?php esc_html_e( 'Done with problems', 'kdna-ecommerce-insights' ); ?></option>
							<option value="error"><?php esc_html_e( 'Problem', 'kdna-ecommerce-insights' ); ?></option>
						</select>
						<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="loadLog( log.page )" aria-label="<?php esc_attr_e( 'Refresh the log', 'kdna-ecommerce-insights' ); ?>" title="<?php esc_attr_e( 'Refresh the log', 'kdna-ecommerce-insights' ); ?>">&#8635;</button>
						<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="exportLog()" aria-label="<?php esc_attr_e( 'Export the log as CSV', 'kdna-ecommerce-insights' ); ?>" title="<?php esc_attr_e( 'Export the log as CSV', 'kdna-ecommerce-insights' ); ?>"><?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?></button>
					</div>
				</div>
				<div class="kdna-ei-table-wrap" tabindex="0">
					<table class="kdna-ei-table kdna-ei-table--compact kdna-ei-log__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'kdna-ecommerce-insights' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Type', 'kdna-ecommerce-insights' ); ?></th>
								<th scope="col"><?php esc_html_e( 'What happened', 'kdna-ecommerce-insights' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'kdna-ecommerce-insights' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<template x-for="entry in log.rows" :key="entry.id">
								<tr>
									<td class="kdna-ei-muted kdna-ei-num kdna-ei-log__when" x-text="niceDateTime( entry.finished_at )"></td>
									<td class="kdna-ei-muted" x-text="entry.type_label"></td>
									<td x-text="entry.message"></td>
									<td><span class="kdna-ei-badge" :class="{ 'kdna-ei-badge--positive': entry.status === 'success', 'kdna-ei-badge--warning': entry.status === 'warning', 'kdna-ei-badge--negative': entry.status === 'error' }" x-text="i18nData.logStatus[ entry.status ] || entry.status"></span></td>
								</tr>
							</template>
						</tbody>
					</table>
					<template x-if="log.loading && ! log.rows.length">
						<div class="kdna-ei-skel-stack" aria-hidden="true">
							<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
							<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
							<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
						</div>
					</template>
					<p class="kdna-ei-muted" x-show="log.loaded && ! log.rows.length"><?php esc_html_e( 'Nothing to show yet.', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-field-error" role="alert" x-show="log.error" x-text="log.error"></p>
				</div>
				<div class="kdna-ei-pager" x-show="log.pages > 1">
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="loadLog( log.page - 1 )" :disabled="log.page <= 1 || log.loading"><?php esc_html_e( 'Newer', 'kdna-ecommerce-insights' ); ?></button>
					<span class="kdna-ei-muted kdna-ei-num" x-text="sprintf( t.pageOf, log.page, log.pages )"></span>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="loadLog( log.page + 1 )" :disabled="log.page >= log.pages || log.loading"><?php esc_html_e( 'Older', 'kdna-ecommerce-insights' ); ?></button>
				</div>
			</section>

			<?php // Uninstall behaviour. ?>
			<template x-if="loaded">
			<section class="kdna-ei-card kdna-ei-settings-panel" aria-labelledby="kdna-ei-set-uninstall">
				<div class="kdna-ei-card__header">
					<div>
						<h2 id="kdna-ei-set-uninstall" class="kdna-ei-card__title"><?php esc_html_e( 'If Insights is deleted', 'kdna-ecommerce-insights' ); ?></h2>
						<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Deactivating Insights never removes anything. This setting only matters when the plugin is deleted from the Plugins screen.', 'kdna-ecommerce-insights' ); ?></p>
					</div>
				</div>
				<label class="kdna-ei-switch">
					<input type="checkbox" x-model="form.data.delete_on_uninstall" />
					<span class="kdna-ei-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Delete all plugin data on uninstall', 'kdna-ecommerce-insights' ); ?></span>
				</label>
				<div class="kdna-ei-notice kdna-ei-notice--negative kdna-ei-settings-panel__note" x-show="form.data.delete_on_uninstall" x-cloak>
					<p>
						<strong><?php esc_html_e( 'This cannot be undone.', 'kdna-ecommerce-insights' ); ?></strong>
						<?php esc_html_e( 'Deleting the plugin will then remove its tables (processed orders, summaries, ad spend, overheads, stock snapshots and the sync log), all settings and saved connection keys, cost prices entered in Insights, scheduled jobs and each person\'s preferences. Your WooCommerce orders and products are never touched, and cost prices kept in WooCommerce\'s own cost field stay.', 'kdna-ecommerce-insights' ); ?>
					</p>
				</div>
				<p class="kdna-ei-help" x-show="! form.data.delete_on_uninstall"><?php esc_html_e( 'Recommended for most stores. Everything is kept, so reinstalling Insights picks up exactly where it left off. For safety, the Meta and Google Ads access keys are always removed, so you would reconnect those.', 'kdna-ecommerce-insights' ); ?></p>
				<?php $kdna_ei_footer( 'data', false ); ?>
			</section>
			</template>
		</div>
	</div>
</div>
