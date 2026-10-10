<?php
/**
 * Costs screen, Recalculate tab: work out past orders again after costs or
 * cost rules change. Uses the app's shared job controls (startJob,
 * cancelJob, status) from admin/js/kdna-ei-app.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="kdna-ei-card" x-data="{ recalcMode: 'missing', recalcAfter: '' }">
	<div class="kdna-ei-card__header">
		<div>
			<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Recalculate past orders', 'kdna-ecommerce-insights' ); ?></h2>
			<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Each order keeps the costs that applied when it was paid, so changing a cost or a rule does not rewrite history. If you entered costs late, or want new rules applied to past orders, recalculate them here. It runs in the background and the dashboard stays usable.', 'kdna-ecommerce-insights' ); ?></p>
		</div>
	</div>

	<fieldset class="kdna-ei-choice-list" :disabled="jobRunning">
		<legend class="kdna-ei-visually-hidden"><?php esc_html_e( 'Which orders', 'kdna-ecommerce-insights' ); ?></legend>

		<label class="kdna-ei-choice" :class="{ 'is-selected': recalcMode === 'missing' }">
			<input type="radio" name="kdna-ei-recalc" value="missing" x-model="recalcMode" />
			<span class="kdna-ei-choice__text">
				<span class="kdna-ei-choice__label"><?php esc_html_e( 'Only orders missing costs', 'kdna-ecommerce-insights' ); ?></span>
				<span class="kdna-ei-muted" x-text="sprintfJobs( i18nData.missingCount, ( status.orders_missing_costs || 0 ).toLocaleString() )"></span>
			</span>
		</label>

		<label class="kdna-ei-choice" :class="{ 'is-selected': recalcMode === 'after' }">
			<input type="radio" name="kdna-ei-recalc" value="after" x-model="recalcMode" />
			<span class="kdna-ei-choice__text">
				<span class="kdna-ei-choice__label"><?php esc_html_e( 'Orders placed on or after a date', 'kdna-ecommerce-insights' ); ?></span>
				<span class="kdna-ei-muted"><?php esc_html_e( 'For example from the day a supplier price or shipping rate changed.', 'kdna-ecommerce-insights' ); ?></span>
			</span>
			<input type="date" class="kdna-ei-input kdna-ei-choice__date" x-model="recalcAfter" @focus="recalcMode = 'after'" aria-label="<?php esc_attr_e( 'Recalculate orders placed on or after', 'kdna-ecommerce-insights' ); ?>" />
		</label>

		<label class="kdna-ei-choice" :class="{ 'is-selected': recalcMode === 'all' }">
			<input type="radio" name="kdna-ei-recalc" value="all" x-model="recalcMode" />
			<span class="kdna-ei-choice__text">
				<span class="kdna-ei-choice__label"><?php esc_html_e( 'All orders', 'kdna-ecommerce-insights' ); ?></span>
				<span class="kdna-ei-muted" x-text="sprintfJobs( i18nData.allCount, ( status.orders_processed || 0 ).toLocaleString() )"></span>
			</span>
		</label>
	</fieldset>

	<template x-if="jobRunning">
		<div class="kdna-ei-data-progress">
			<p><strong x-text="jobTitle"></strong></p>
			<div class="kdna-ei-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="status.job.percent">
				<span class="kdna-ei-progress__bar" :style="'width:' + status.job.percent + '%'"></span>
			</div>
			<p class="kdna-ei-muted kdna-ei-num" x-text="jobDetail"></p>
		</div>
	</template>

	<template x-if="jobError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="jobError"></p></div>
	</template>

	<div class="kdna-ei-card__footer">
		<template x-if="jobRunning">
			<button type="button" class="kdna-ei-btn" @click="cancelJob()"><?php esc_html_e( 'Stop', 'kdna-ecommerce-insights' ); ?></button>
		</template>
		<template x-if="! jobRunning">
			<button
				type="button"
				class="kdna-ei-btn kdna-ei-btn--primary"
				:disabled="recalcMode === 'after' && ! recalcAfter"
				@click="startJob( recalcMode, recalcAfter )"
			><?php esc_html_e( 'Recalculate', 'kdna-ecommerce-insights' ); ?></button>
		</template>
	</div>
</section>
