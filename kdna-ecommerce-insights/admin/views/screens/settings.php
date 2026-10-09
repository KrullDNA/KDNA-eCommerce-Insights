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
<div class="kdna-ei-settings" x-data="{ settingsTab: 'data', confirmRebuild: false }">
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
		<div class="kdna-ei-card" x-show="settingsTab !== 'data'" x-cloak aria-hidden="true">
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
