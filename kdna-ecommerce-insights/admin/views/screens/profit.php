<?php
/**
 * Profit & Loss screen (section 8): KPI strip, profit waterfall, margin
 * trend, cost breakdown donut and the monthly P&L statement with every line
 * from section 6. Behaviour lives in admin/js/screens/profit.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;
?>
<div class="kdna-ei-profit" x-data="kdnaEiProfit" x-effect="if ( route === 'profit' ) ensureLoaded()" :aria-busy="loading ? 'true' : 'false'">

	<template x-if="loadError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
			<p x-text="loadError"></p>
			<button type="button" class="kdna-ei-btn" @click="load( true )"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</template>

	<div class="kdna-ei-profit__grid">

		<?php // KPI strip: the P&L totals. ?>
		<section class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" aria-label="<?php esc_attr_e( 'Profit and loss totals', 'kdna-ecommerce-insights' ); ?>">
			<template x-for="n in ( loaded ? 0 : 5 )" :key="'skeleton' + n">
				<div class="kdna-ei-kpi" aria-hidden="true">
					<span class="kdna-ei-skeleton kdna-ei-skeleton--icon"></span>
					<div class="kdna-ei-kpi__body kdna-ei-skel-stack">
						<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
						<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
					</div>
				</div>
			</template>
			<template x-for="kpi in kpis" :key="kpi.key">
				<div class="kdna-ei-kpi">
					<span class="kdna-ei-kpi__icon" aria-hidden="true">
						<svg class="kdna-ei-icon"><use :href="'#kdna-ei-icon-' + kpiIcon( kpi.key )"></use></svg>
					</span>
					<div class="kdna-ei-kpi__body">
						<div class="kdna-ei-kpi__label-row">
							<span class="kdna-ei-kpi__label" x-text="kpi.label" :title="kpi.help"></span>
							<template x-if="kpi.estimated || kpi.incomplete">
								<span class="kdna-ei-estimate" tabindex="0" :title="kpi.incomplete ? t.incompleteHelp : t.estimatedHelp">
									<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-info"></use></svg>
									<span class="kdna-ei-visually-hidden" x-text="kpi.incomplete ? t.incompleteHelp : t.estimatedHelp"></span>
								</span>
							</template>
						</div>
						<div class="kdna-ei-kpi__row">
							<span class="kdna-ei-kpi__value" x-text="metric( kpi.value, kpi.format, kpi.decimals )"></span>
							<template x-if="kpi.change !== null">
								<span class="kdna-ei-change" :class="'kdna-ei-change--' + ( kpi.sentiment === 'good' ? 'good' : ( kpi.sentiment === 'bad' ? 'bad' : 'neutral' ) )" :title="previousText( kpi )">
									<template x-if="kpi.direction === 'up'"><svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-arrow-up"></use></svg></template>
									<template x-if="kpi.direction === 'down'"><svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-arrow-down"></use></svg></template>
									<span x-text="changeText( kpi )"></span>
									<span class="kdna-ei-visually-hidden" x-text="previousText( kpi )"></span>
								</span>
							</template>
						</div>
					</div>
				</div>
			</template>
		</section>

		<?php // Profit waterfall. ?>
		<section class="kdna-ei-card kdna-ei-area-waterfall" aria-labelledby="kdna-ei-waterfall-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-waterfall-title" class="kdna-ei-card__title"><?php esc_html_e( 'Where the money went', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'From what customers paid, step by step down to net profit.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div class="kdna-ei-chart-legend" aria-hidden="true">
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--compare"></span><?php esc_html_e( 'Added', 'kdna-ecommerce-insights' ); ?></span>
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--negative"></span><?php esc_html_e( 'Taken off', 'kdna-ecommerce-insights' ); ?></span>
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><?php esc_html_e( 'Subtotal', 'kdna-ecommerce-insights' ); ?></span>
				</div>
			</div>
			<div class="kdna-ei-chart kdna-ei-chart--tall">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" x-show="! loaded" aria-hidden="true"></span>
				<canvas x-ref="waterfall" x-show="loaded" role="img" :aria-label="waterfallSummary"></canvas>
			</div>
		</section>

		<?php // Margin trend. ?>
		<section class="kdna-ei-card kdna-ei-area-margin" aria-labelledby="kdna-ei-margin-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-margin-title" class="kdna-ei-card__title"><?php esc_html_e( 'Margin trend', 'kdna-ecommerce-insights' ); ?></h2>
				<div class="kdna-ei-chart-legend" aria-hidden="true">
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><?php esc_html_e( 'Net margin', 'kdna-ecommerce-insights' ); ?></span>
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--positive"></span><?php esc_html_e( 'Gross margin', 'kdna-ecommerce-insights' ); ?></span>
				</div>
			</div>
			<div class="kdna-ei-chart">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" x-show="! loaded" aria-hidden="true"></span>
				<canvas x-ref="margin" x-show="loaded" role="img" :aria-label="marginSummary"></canvas>
			</div>
		</section>

		<?php // Cost breakdown donut. ?>
		<section class="kdna-ei-card kdna-ei-area-costs" aria-labelledby="kdna-ei-costs-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-costs-title" class="kdna-ei-card__title"><?php esc_html_e( 'Cost breakdown', 'kdna-ecommerce-insights' ); ?></h2>
			</div>
			<div class="kdna-ei-cost-donut">
				<div class="kdna-ei-donut" x-show="loaded">
					<canvas x-ref="costs" role="img" :aria-label="costSummary"></canvas>
					<div class="kdna-ei-donut__centre" aria-hidden="true">
						<span class="kdna-ei-donut__number kdna-ei-num" x-text="money( totalCosts, 0 )"></span>
						<span class="kdna-ei-donut__label"><?php esc_html_e( 'total costs', 'kdna-ecommerce-insights' ); ?></span>
					</div>
				</div>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--circle kdna-ei-donut" x-show="! loaded" aria-hidden="true"></span>
				<table class="kdna-ei-table kdna-ei-legend kdna-ei-legend--compact">
					<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Cost breakdown', 'kdna-ecommerce-insights' ); ?></caption>
					<tbody>
						<template x-for="cost in costRows" :key="cost.key">
							<tr>
								<th scope="row">
									<span class="kdna-ei-legend__dot" :style="'background: var(--kdna-ei-' + cost.token + ')'"></span><span x-text="cost.label"></span>
									<template x-if="lineFlag( cost.key )">
										<span class="kdna-ei-estimate" tabindex="0" :title="lineFlag( cost.key )">
											<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-info"></use></svg>
											<span class="kdna-ei-visually-hidden" x-text="lineFlag( cost.key )"></span>
										</span>
									</template>
								</th>
								<td class="is-numeric kdna-ei-legend__value" x-text="money( cost.amount, 0 )"></td>
								<td class="is-numeric kdna-ei-legend__percent" x-text="cost.share + '%'"></td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</section>

		<?php // Monthly P&L statement. ?>
		<section class="kdna-ei-card kdna-ei-area-statement" aria-labelledby="kdna-ei-statement-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-statement-title" class="kdna-ei-card__title"><?php esc_html_e( 'Profit and loss statement', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Every line by month, excluding tax. Lines marked with an i include estimates; hover for details.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<button type="button" class="kdna-ei-btn" @click="exportCsv()" :disabled="exporting || ! loaded">
					<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
					<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
				</button>
			</div>

			<div class="kdna-ei-table-wrap kdna-ei-statement-wrap" tabindex="0" aria-labelledby="kdna-ei-statement-title">
				<table class="kdna-ei-table kdna-ei-statement">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Line', 'kdna-ecommerce-insights' ); ?></th>
							<template x-for="month in months" :key="month.start">
								<th scope="col" class="is-numeric" x-text="monthLabel( month )"></th>
							</template>
							<th scope="col" class="is-numeric kdna-ei-statement__total"><?php esc_html_e( 'Total', 'kdna-ecommerce-insights' ); ?></th>
						</tr>
					</thead>
					<tbody x-show="! loaded">
						<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 8; $kdna_ei_i++ ) : ?>
							<tr aria-hidden="true">
								<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 60%;"></span></td>
								<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
								<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
							</tr>
						<?php endfor; ?>
					</tbody>
					<tbody x-show="loaded" x-cloak>
						<template x-for="line in statement" :key="line.key">
							<tr :class="'is-' + line.type">
								<th scope="row">
									<span class="kdna-ei-statement__label">
										<span x-text="line.label"></span>
										<template x-if="lineFlag( line.key )">
											<span class="kdna-ei-estimate" tabindex="0" :title="lineFlag( line.key )">
												<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-info"></use></svg>
												<span class="kdna-ei-visually-hidden" x-text="lineFlag( line.key )"></span>
											</span>
										</template>
									</span>
								</th>
								<template x-for="month in months" :key="month.start">
									<td class="is-numeric kdna-ei-num" :class="{ 'is-negative': month.lines[ line.key ] < 0 }" x-text="money( month.lines[ line.key ] || 0, 0 )"></td>
								</template>
								<td class="is-numeric kdna-ei-num kdna-ei-statement__total" :class="{ 'is-negative': line.amount < 0 }" x-text="money( line.amount, 0 )"></td>
							</tr>
						</template>
						<tr class="is-margin">
							<th scope="row"><?php esc_html_e( 'Net margin', 'kdna-ecommerce-insights' ); ?></th>
							<template x-for="month in months" :key="month.start">
								<td class="is-numeric kdna-ei-num" x-text="month.lines.net_margin === null ? '–' : percent( month.lines.net_margin )"></td>
							</template>
							<td class="is-numeric kdna-ei-num kdna-ei-statement__total" x-text="netMargin === null ? '–' : percent( netMargin )"></td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</div>
