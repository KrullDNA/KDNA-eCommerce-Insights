<?php
/**
 * Overview screen (section 8 of the brief), laid out like the reference
 * design: alerts, KPI strip, performance chart, inventory donut and the
 * hero card. Behaviour lives in admin/js/screens/overview.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;
?>
<div class="kdna-ei-overview" x-data="kdnaEiOverview" x-effect="if ( route === 'overview' ) ensureLoaded()">

	<?php // Empty state: a store with no orders yet. ?>
	<template x-if="isEmpty">
		<div class="kdna-ei-card kdna-ei-empty-state">
			<span class="kdna-ei-empty-state__icon" aria-hidden="true"><?php Admin::icon( 'chart', 'kdna-ei-icon--lg' ); ?></span>
			<h2 class="kdna-ei-card__title" x-text="jobRunning ? t.emptyProcessingTitle : t.emptyTitle"></h2>
			<p class="kdna-ei-muted" x-text="jobRunning ? t.emptyProcessingText : t.emptyText"></p>
			<a class="kdna-ei-btn" href="#/costs" x-show="! jobRunning"><?php esc_html_e( 'Add your product costs', 'kdna-ecommerce-insights' ); ?></a>
		</div>
	</template>

	<div class="kdna-ei-overview__grid" x-show="! isEmpty" :aria-busy="loading ? 'true' : 'false'">

		<?php // Alerts strip (section 8.3). ?>
		<div class="kdna-ei-area-alerts kdna-ei-alerts" x-show="visibleAlerts.length" x-cloak role="region" aria-label="<?php esc_attr_e( 'Things that need attention', 'kdna-ecommerce-insights' ); ?>">
			<template x-for="alert in visibleAlerts" :key="alert.key">
				<div class="kdna-ei-alert" :class="'kdna-ei-alert--' + alert.tone">
					<span class="kdna-ei-alert__icon" aria-hidden="true">
						<svg class="kdna-ei-icon kdna-ei-icon--sm"><use :href="'#kdna-ei-icon-' + alert.icon"></use></svg>
					</span>
					<span class="kdna-ei-alert__text" x-text="alert.text"></span>
					<template x-if="alert.action">
						<button type="button" class="kdna-ei-link" @click="alert.action.run()" x-text="alert.action.label"></button>
					</template>
					<button type="button" class="kdna-ei-alert__close" @click="dismiss( alert.key )" :aria-label="t.dismiss">&times;</button>
				</div>
			</template>
		</div>

		<?php // KPI strip. ?>
		<section class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" aria-label="<?php esc_attr_e( 'Key figures', 'kdna-ecommerce-insights' ); ?>">
			<template x-for="n in ( loaded ? 0 : 5 )" :key="'skeleton' + n">
				<div class="kdna-ei-kpi" aria-hidden="true">
					<span class="kdna-ei-skeleton kdna-ei-skeleton--icon"></span>
					<div class="kdna-ei-kpi__body kdna-ei-skel-stack">
						<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
						<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
					</div>
				</div>
			</template>
			<template x-for="( kpi, index ) in kpis" :key="kpi.key">
				<div class="kdna-ei-kpi" x-show="loaded">
					<span class="kdna-ei-kpi__icon" aria-hidden="true">
						<svg class="kdna-ei-icon"><use :href="'#kdna-ei-icon-' + kpiIcon( kpi.key, index )"></use></svg>
					</span>
					<div class="kdna-ei-kpi__body">
						<div class="kdna-ei-kpi__label-row">
							<template x-if="index < 4">
								<span class="kdna-ei-kpi__label" x-text="kpi.label" :title="kpi.help"></span>
							</template>
							<template x-if="index === 4">
								<div class="kdna-ei-dropdown kdna-ei-kpi__picker" @click.outside="kpiMenuOpen = false">
									<button type="button" class="kdna-ei-kpi__label kdna-ei-kpi__label--button" @click="kpiMenuOpen = ! kpiMenuOpen" :aria-expanded="kpiMenuOpen ? 'true' : 'false'" :title="t.changeMetric">
										<span x-text="kpi.label"></span>
										<svg class="kdna-ei-icon kdna-ei-icon--sm" aria-hidden="true"><use href="#kdna-ei-icon-chevron-down"></use></svg>
									</button>
									<div class="kdna-ei-dropdown__menu kdna-ei-kpi__menu" x-show="kpiMenuOpen" x-cloak role="menu">
										<template x-for="option in fifthOptions" :key="option.key">
											<button type="button" class="kdna-ei-dropdown__item" role="menuitemradio" :aria-checked="option.key === kpi.key ? 'true' : 'false'" @click="chooseFifth( option.key )">
												<span x-text="option.label"></span>
												<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-check"></use></svg>
											</button>
										</template>
									</div>
								</div>
							</template>
							<template x-if="kpi.estimated || kpi.incomplete">
								<span class="kdna-ei-estimate" :class="{ 'is-incomplete': kpi.incomplete }" tabindex="0" :title="kpi.incomplete ? t.incompleteHelp : t.estimatedHelp">
									<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-info"></use></svg>
									<span class="kdna-ei-visually-hidden" x-text="( kpi.incomplete ? t.incomplete : t.estimated ) + ': ' + ( kpi.incomplete ? t.incompleteHelp : t.estimatedHelp )"></span>
								</span>
							</template>
						</div>
						<div class="kdna-ei-kpi__row">
							<span class="kdna-ei-kpi__value" x-text="display( kpi )"></span>
							<template x-if="kpi.direction !== 'flat' || kpi.change !== null">
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

		<?php // Performance chart. ?>
		<section class="kdna-ei-card kdna-ei-area-chart kdna-ei-performance" aria-labelledby="kdna-ei-performance-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-performance-title" class="kdna-ei-card__title" x-text="t.performance"></h2>
				<div class="kdna-ei-performance__controls">
					<div class="kdna-ei-chart-legend" aria-hidden="true">
						<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><span x-text="seriesLabel"></span></span>
						<template x-if="hasComparison">
							<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--compare"></span><span x-text="comparisonLabel"></span></span>
						</template>
					</div>
					<div class="kdna-ei-segmented" role="group" :aria-label="t.showSeries">
						<template x-for="option in seriesOptions" :key="option.key">
							<button type="button" class="kdna-ei-segmented__item" :aria-pressed="seriesKey === option.key ? 'true' : 'false'" @click="chooseSeries( option.key )" x-text="option.label"></button>
						</template>
					</div>
				</div>
			</div>
			<div class="kdna-ei-chart" :class="{ 'is-loading': ! loaded }">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" x-show="! loaded" aria-hidden="true"></span>
				<canvas x-ref="performance" x-show="loaded" role="img" :aria-label="chartSummary"></canvas>
			</div>
			<?php // Screen readers get the figures as a table. ?>
			<table class="kdna-ei-visually-hidden">
				<caption x-text="t.performance"></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Period', 'kdna-ecommerce-insights' ); ?></th><th scope="col" x-text="seriesLabel"></th></tr></thead>
				<tbody>
					<template x-for="( bucket, i ) in ( series ? series.buckets : [] )" :key="bucket.key">
						<tr><td x-text="bucketTitle( i )"></td><td x-text="formatValue( currentSeries[ i ] )"></td></tr>
					</template>
				</tbody>
			</table>
		</section>

		<?php // Inventory donut. ?>
		<section class="kdna-ei-card kdna-ei-area-inventory kdna-ei-inventory-card" aria-labelledby="kdna-ei-inventory-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-inventory-title" class="kdna-ei-card__title" x-text="t.inventory"></h2>
				<a class="kdna-ei-link" href="#/inventory" x-text="t.viewInventory"></a>
			</div>
			<div class="kdna-ei-donut-row">
				<div class="kdna-ei-donut" x-show="loaded">
					<canvas x-ref="donut" role="img" :aria-label="inventorySummary"></canvas>
					<div class="kdna-ei-donut__centre" aria-hidden="true">
						<span class="kdna-ei-donut__number kdna-ei-num" x-text="formatNumber( inventoryCentre )"></span>
						<span class="kdna-ei-donut__label" x-text="t.inStock"></span>
					</div>
				</div>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--circle kdna-ei-donut" x-show="! loaded" aria-hidden="true"></span>
				<table class="kdna-ei-table kdna-ei-legend">
					<caption class="kdna-ei-visually-hidden" x-text="t.inventory"></caption>
					<tbody>
						<template x-for="row in inventoryRows" :key="row.key">
							<tr>
								<th scope="row"><span class="kdna-ei-legend__dot" :style="'background: var(--kdna-ei-' + row.token + ')'"></span><span x-text="row.label"></span></th>
								<td class="is-numeric kdna-ei-legend__value" x-text="loaded ? formatNumber( row.value ) : ''"></td>
								<td class="is-numeric kdna-ei-legend__percent" x-text="loaded ? ( row.value > 0 && row.percent === 0 ? '<1' : row.percent ) + '%' : ''"></td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</section>

		<?php // Hero card: Top Products, Profit Breakdown or Goals Tracker (section 8.1). ?>
		<section class="kdna-ei-card kdna-ei-area-hero kdna-ei-hero" :aria-label="heroTitle">
			<div class="kdna-ei-card__header">
				<h2 class="kdna-ei-card__title" x-text="heroTitle"></h2>
				<div class="kdna-ei-dropdown" @click.outside="heroMenuOpen = false">
					<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="heroMenuOpen = ! heroMenuOpen" :aria-expanded="heroMenuOpen ? 'true' : 'false'" :aria-label="t.heroMenu">
						<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-more"></use></svg>
					</button>
					<div class="kdna-ei-dropdown__menu" x-show="heroMenuOpen" x-cloak role="menu">
						<p class="kdna-ei-dropdown__heading" x-text="t.heroShow"></p>
						<template x-for="( label, type ) in t.heroTypes" :key="type">
							<button type="button" class="kdna-ei-dropdown__item" role="menuitemradio" :aria-checked="heroType === type ? 'true' : 'false'" @click="chooseHero( type )">
								<span x-text="label"></span>
								<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-check"></use></svg>
							</button>
						</template>
					</div>
				</div>
			</div>

			<template x-if="! heroLoaded">
				<div class="kdna-ei-skel-stack" aria-hidden="true">
					<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 5; $kdna_ei_i++ ) : ?>
						<div class="kdna-ei-skel-row"><span class="kdna-ei-skeleton" style="width: 44px; height: 44px; flex: none;"></span><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></div>
					<?php endfor; ?>
				</div>
			</template>

			<?php // Top Products. ?>
			<template x-if="heroLoaded && heroType === 'top_products'">
				<div class="kdna-ei-top-products">
					<div class="kdna-ei-tabs kdna-ei-tabs--small" role="group" :aria-label="t.rankBy">
						<button type="button" class="kdna-ei-tab" :aria-selected="heroSort === 'profit' ? 'true' : 'false'" @click="chooseHeroSort( 'profit' )" x-text="t.byProfit"></button>
						<button type="button" class="kdna-ei-tab" :aria-selected="heroSort === 'revenue' ? 'true' : 'false'" @click="chooseHeroSort( 'revenue' )" x-text="t.byRevenue"></button>
					</div>
					<ol class="kdna-ei-rank">
						<template x-for="( item, i ) in heroProducts" :key="item.product_id + ':' + item.variation_id">
							<li class="kdna-ei-rank__item">
								<span class="kdna-ei-thumb kdna-ei-rank__thumb">
									<template x-if="item.thumbnail"><img :src="item.thumbnail" alt="" loading="lazy" /></template>
									<template x-if="! item.thumbnail"><svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-box"></use></svg></template>
								</span>
								<span class="kdna-ei-rank__body">
									<span class="kdna-ei-rank__top">
										<span class="kdna-ei-rank__name" x-text="item.name"></span>
										<span class="kdna-ei-rank__amount kdna-ei-num" x-text="money( heroSort === 'profit' ? item.profit : item.revenue, 0 )"></span>
									</span>
									<span class="kdna-ei-rank__meta">
										<span x-text="sprintf( t.unitsSold, formatNumber( item.units ) )"></span>
										<span x-text="item.margin === null ? '' : sprintf( t.marginOf, percent( item.margin ) )"></span>
									</span>
									<span class="kdna-ei-bar" aria-hidden="true">
										<span class="kdna-ei-bar__fill" :class="{ 'is-negative': item.margin < 0 }" :style="'width:' + Math.max( 2, Math.min( 100, Math.abs( item.margin || 0 ) ) ) + '%'"></span>
									</span>
								</span>
							</li>
						</template>
					</ol>
					<p class="kdna-ei-muted" x-show="! heroProducts.length" x-text="t.noProducts"></p>
				</div>
			</template>

			<?php // Profit Breakdown. ?>
			<template x-if="heroLoaded && heroType === 'profit_breakdown'">
				<div class="kdna-ei-waterfall">
					<template x-for="step in waterfallSteps" :key="step.key">
						<div class="kdna-ei-waterfall__row" :class="'is-' + step.type">
							<span class="kdna-ei-waterfall__label" x-text="step.label"></span>
							<span class="kdna-ei-waterfall__amount kdna-ei-num" :class="{ 'is-negative': step.amount < 0 }" x-text="money( step.amount, 0 )"></span>
							<span class="kdna-ei-waterfall__track" aria-hidden="true">
								<span class="kdna-ei-waterfall__bar" :style="'left:' + step.left + '%; width:' + step.width + '%'"></span>
							</span>
						</div>
					</template>
				</div>
			</template>

			<?php // Goals Tracker. ?>
			<template x-if="heroLoaded && heroType === 'goals'">
				<div class="kdna-ei-goal">
					<template x-if="! goal.target">
						<div class="kdna-ei-goal__empty">
							<p x-text="t.goalEmpty"></p>
							<a class="kdna-ei-btn" href="#/settings" @click="openGoalSettings()" x-text="t.goalSet"></a>
						</div>
					</template>
					<template x-if="goal.target">
						<div class="kdna-ei-goal__body">
							<div class="kdna-ei-ring" role="img" :aria-label="sprintf( t.goalProgress, goal.percent )">
								<svg viewBox="0 0 120 120" aria-hidden="true">
									<circle class="kdna-ei-ring__track" cx="60" cy="60" r="52"></circle>
									<circle class="kdna-ei-ring__fill" :class="{ 'is-behind': ! goal.onTrack }" cx="60" cy="60" r="52" :style="'stroke-dashoffset:' + goal.offset"></circle>
								</svg>
								<span class="kdna-ei-ring__centre">
									<span class="kdna-ei-ring__percent kdna-ei-num" x-text="goal.percent + '%'"></span>
									<span class="kdna-ei-ring__label" x-text="goal.label"></span>
								</span>
							</div>
							<span class="kdna-ei-badge" :class="goal.onTrack ? 'kdna-ei-badge--positive' : 'kdna-ei-badge--warning'" x-text="goal.onTrack ? t.onTrack : t.behind"></span>
							<dl class="kdna-ei-goal__facts">
								<div><dt x-text="t.soFar"></dt><dd class="kdna-ei-num" x-text="goal.valueText"></dd></div>
								<div><dt x-text="t.target"></dt><dd class="kdna-ei-num" x-text="goal.targetText"></dd></div>
								<div><dt x-text="t.daysLeft"></dt><dd class="kdna-ei-num" x-text="goal.daysLeft"></dd></div>
								<div><dt x-text="t.perDayNeeded"></dt><dd class="kdna-ei-num" x-text="goal.neededText"></dd></div>
							</dl>
						</div>
					</template>
				</div>
			</template>
		</section>
	</div>

	<template x-if="loadError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
			<p x-text="loadError"></p>
			<button type="button" class="kdna-ei-btn" @click="load( true )"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</template>
</div>
