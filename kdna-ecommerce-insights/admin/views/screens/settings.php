<?php
/**
 * Settings screen. Every tab from section 11 of the brief is listed; the
 * Data tab is built in Stage 4 (order processing status and rebuild), the
 * rest are completed in Stage 12 and show loading placeholders until then.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

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
?>
<div class="kdna-ei-settings" x-data="{ settingsTab: 'data', confirmRebuild: false }" @kdna:ei-settings-tab.window="settingsTab = $event.detail">
	<nav class="kdna-ei-card kdna-ei-settings__nav" aria-label="<?php esc_attr_e( 'Settings tabs', 'kdna-ecommerce-insights' ); ?>">
		<?php foreach ( $kdna_ei_settings_tabs as $kdna_ei_key => $kdna_ei_label ) : ?>
			<button
				type="button"
				class="kdna-ei-settings__tab"
				:class="{ 'is-active': settingsTab === '<?php echo esc_js( $kdna_ei_key ); ?>' }"
				:aria-current="settingsTab === '<?php echo esc_js( $kdna_ei_key ); ?>' ? 'page' : false"
				@click="settingsTab = '<?php echo esc_js( $kdna_ei_key ); ?>'"
			><?php echo esc_html( $kdna_ei_label ); ?></button>
		<?php endforeach; ?>
	</nav>

	<div class="kdna-ei-settings__panel">
		<?php // Tabs still to be built show placeholders. ?>
		<div class="kdna-ei-card" x-show="settingsTab !== 'data' && settingsTab !== 'hero'" x-cloak aria-hidden="true">
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

		<?php // Hero card and Goals tab (section 8.1). ?>
		<section class="kdna-ei-card kdna-ei-hero-settings" x-show="settingsTab === 'hero'" x-cloak x-data="kdnaEiHeroSettings">
			<div class="kdna-ei-card__header">
				<div>
					<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Hero card', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'The large card on the right of the Overview. Choose what it shows for everyone who uses Insights.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<fieldset class="kdna-ei-choice-list">
				<legend class="kdna-ei-visually-hidden"><?php esc_html_e( 'Hero card', 'kdna-ecommerce-insights' ); ?></legend>
				<?php
				$kdna_ei_hero_types = array(
					'top_products'     => array( __( 'Top products', 'kdna-ecommerce-insights' ), __( 'Your five best sellers for the chosen dates, ranked by profit or revenue, with their margin.', 'kdna-ecommerce-insights' ) ),
					'profit_breakdown' => array( __( 'Profit breakdown', 'kdna-ecommerce-insights' ), __( 'How net revenue turns into net profit: product costs, fees, shipping, extra costs, ads and overheads.', 'kdna-ecommerce-insights' ) ),
					'goals'            => array( __( 'Goals tracker', 'kdna-ecommerce-insights' ), __( 'Progress towards a monthly target, whether you are on track and what is needed each day.', 'kdna-ecommerce-insights' ) ),
				);
				foreach ( $kdna_ei_hero_types as $kdna_ei_key => $kdna_ei_type ) :
					?>
					<label class="kdna-ei-choice" :class="{ 'is-selected': form.type === '<?php echo esc_js( $kdna_ei_key ); ?>' }">
						<input type="radio" name="kdna-ei-hero-type" value="<?php echo esc_attr( $kdna_ei_key ); ?>" x-model="form.type" />
						<span class="kdna-ei-choice__text">
							<span class="kdna-ei-choice__label"><?php echo esc_html( $kdna_ei_type[0] ); ?></span>
							<span class="kdna-ei-muted"><?php echo esc_html( $kdna_ei_type[1] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<h3 class="kdna-ei-card__title kdna-ei-hero-settings__heading"><?php esc_html_e( 'Monthly goals', 'kdna-ecommerce-insights' ); ?></h3>
			<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Targets for each calendar month. The Goals tracker follows the one you choose to track.', 'kdna-ecommerce-insights' ); ?></p>

			<div class="kdna-ei-hero-settings__grid">
				<label>
					<span class="kdna-ei-field-label"><?php esc_html_e( 'Track', 'kdna-ecommerce-insights' ); ?></span>
					<select class="kdna-ei-select" x-model="form.goal_metric">
						<option value="revenue"><?php esc_html_e( 'Net revenue', 'kdna-ecommerce-insights' ); ?></option>
						<option value="profit"><?php esc_html_e( 'Net profit', 'kdna-ecommerce-insights' ); ?></option>
						<option value="orders"><?php esc_html_e( 'Orders', 'kdna-ecommerce-insights' ); ?></option>
					</select>
				</label>
				<div>
					<span class="kdna-ei-field-label"><?php esc_html_e( 'Revenue target', 'kdna-ecommerce-insights' ); ?></span>
					<label class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': errors.revenue }">
						<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Monthly revenue target', 'kdna-ecommerce-insights' ); ?></span>
						<span class="kdna-ei-affix__before" aria-hidden="true" x-text="symbol"></span>
						<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0" x-model="form.revenue" />
					</label>
					<p class="kdna-ei-field-error" x-show="errors.revenue" x-text="errors.revenue"></p>
				</div>
				<div>
					<span class="kdna-ei-field-label"><?php esc_html_e( 'Profit target', 'kdna-ecommerce-insights' ); ?></span>
					<label class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': errors.profit }">
						<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Monthly profit target', 'kdna-ecommerce-insights' ); ?></span>
						<span class="kdna-ei-affix__before" aria-hidden="true" x-text="symbol"></span>
						<input type="text" inputmode="decimal" class="kdna-ei-input" placeholder="0" x-model="form.profit" />
					</label>
					<p class="kdna-ei-field-error" x-show="errors.profit" x-text="errors.profit"></p>
				</div>
				<div>
					<label>
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Orders target', 'kdna-ecommerce-insights' ); ?></span>
						<input type="text" inputmode="numeric" class="kdna-ei-input" :class="{ 'is-invalid': errors.orders }" placeholder="0" x-model="form.orders" />
					</label>
					<p class="kdna-ei-field-error" x-show="errors.orders" x-text="errors.orders"></p>
				</div>
			</div>

			<div class="kdna-ei-card__footer">
				<p class="kdna-ei-muted" role="status" x-text="message"></p>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="save()" :disabled="saving"><?php esc_html_e( 'Save hero card and goals', 'kdna-ecommerce-insights' ); ?></button>
			</div>
		</section>

		<?php // Data tab. ?>
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

			<section class="kdna-ei-card">
				<div class="kdna-ei-card__header">
					<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Recent activity', 'kdna-ecommerce-insights' ); ?></h2>
				</div>
				<div class="kdna-ei-table-wrap">
					<table class="kdna-ei-table kdna-ei-table--compact">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'kdna-ecommerce-insights' ); ?></th>
								<th scope="col"><?php esc_html_e( 'What', 'kdna-ecommerce-insights' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'kdna-ecommerce-insights' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<template x-for="entry in ( status.recent_log || [] )" :key="entry.id">
								<tr>
									<td class="kdna-ei-muted kdna-ei-num" x-text="niceDateTime( entry.finished_at )"></td>
									<td x-text="entry.message"></td>
									<td><span class="kdna-ei-badge" :class="{ 'kdna-ei-badge--positive': entry.status === 'success', 'kdna-ei-badge--warning': entry.status === 'warning', 'kdna-ei-badge--negative': entry.status === 'error' }" x-text="i18nData.logStatus[ entry.status ] || entry.status"></span></td>
								</tr>
							</template>
						</tbody>
					</table>
					<p class="kdna-ei-muted" x-show="! ( status.recent_log || [] ).length"><?php esc_html_e( 'Nothing to show yet.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</section>
		</div>
	</div>
</div>
