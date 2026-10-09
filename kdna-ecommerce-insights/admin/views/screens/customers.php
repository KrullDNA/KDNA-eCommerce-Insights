<?php
/**
 * Customers screen (section 8): KPI strip, new vs returning chart, revenue
 * split, cohort retention grid, top customers and locations. Guests are
 * recognised by billing email. Behaviour lives in admin/js/screens/customers.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;
?>
<div class="kdna-ei-customers" x-data="kdnaEiCustomers" x-effect="if ( route === 'customers' ) ensureLoaded()" :aria-busy="loading ? 'true' : 'false'">

	<template x-if="loadError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
			<p x-text="loadError"></p>
			<button type="button" class="kdna-ei-btn" @click="load( true )"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</template>

	<div class="kdna-ei-customers__grid">

		<?php // KPI strip. ?>
		<section class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" aria-label="<?php esc_attr_e( 'Customer figures', 'kdna-ecommerce-insights' ); ?>">
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
						</div>
						<div class="kdna-ei-kpi__row">
							<span class="kdna-ei-kpi__value" x-text="metric( kpi.value, kpi.format, kpi.decimals )"></span>
							<template x-if="isLifetime( kpi.key )">
								<span class="kdna-ei-kpi__note" :title="t.allTimeHelp" x-text="t.allTime"></span>
							</template>
							<template x-if="! isLifetime( kpi.key ) && kpi.change !== null">
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

		<?php // New vs returning chart. ?>
		<section class="kdna-ei-card kdna-ei-area-chart" aria-labelledby="kdna-ei-nvr-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-nvr-title" class="kdna-ei-card__title"><?php esc_html_e( 'New and returning customers', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Different customers who ordered in each period. Guests count once per email address.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<div class="kdna-ei-chart-legend" aria-hidden="true">
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><?php esc_html_e( 'New', 'kdna-ecommerce-insights' ); ?></span>
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--compare"></span><?php esc_html_e( 'Returning', 'kdna-ecommerce-insights' ); ?></span>
				</div>
			</div>
			<div class="kdna-ei-chart">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" x-show="! loaded" aria-hidden="true"></span>
				<canvas x-ref="nvr" x-show="loaded" role="img" :aria-label="nvrSummary"></canvas>
			</div>
		</section>

		<?php // Revenue split. ?>
		<section class="kdna-ei-card kdna-ei-area-side kdna-ei-split" aria-labelledby="kdna-ei-split-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-split-title" class="kdna-ei-card__title"><?php esc_html_e( 'This period', 'kdna-ecommerce-insights' ); ?></h2>
			</div>
			<template x-if="! loaded">
				<div class="kdna-ei-skel-stack" aria-hidden="true">
					<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 4; $kdna_ei_i++ ) : ?>
						<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
					<?php endfor; ?>
				</div>
			</template>
			<template x-if="loaded">
				<div class="kdna-ei-split__body">
					<template x-for="row in splitRows" :key="row.key">
						<div class="kdna-ei-split__row">
							<div class="kdna-ei-split__top">
								<span class="kdna-ei-split__label"><span class="kdna-ei-legend__dot" :style="'background: var(--kdna-ei-' + row.token + ')'"></span><span x-text="row.label"></span></span>
								<span class="kdna-ei-num kdna-ei-split__count" x-text="formatNumber( row.count )"></span>
							</div>
							<span class="kdna-ei-bar" aria-hidden="true"><span class="kdna-ei-bar__fill" :style="'width:' + row.share + '%; background: var(--kdna-ei-' + row.token + ')'"></span></span>
							<div class="kdna-ei-split__meta">
								<span x-text="sprintf( t.revenueOf, money( row.revenue, 0 ) )"></span>
								<span x-text="sprintf( t.shareOf, row.revenueShare )"></span>
							</div>
						</div>
					</template>
				</div>
			</template>
		</section>

		<?php // Cohort retention grid. ?>
		<section class="kdna-ei-card kdna-ei-area-cohorts" aria-labelledby="kdna-ei-cohort-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-cohort-title" class="kdna-ei-card__title"><?php esc_html_e( 'Customer retention by first order month', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Each row is the customers who first ordered that month. Each column shows the share who ordered again that many months later.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<button type="button" class="kdna-ei-btn" @click="exportCsv( 'cohorts' )" :disabled="exporting || ! loaded">
					<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
					<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
				</button>
			</div>
			<template x-if="! loaded">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block" style="height: 260px;" aria-hidden="true"></span>
			</template>
			<template x-if="loaded && ! cohorts.length">
				<p class="kdna-ei-muted" x-text="t.noCohorts"></p>
			</template>
			<div class="kdna-ei-table-wrap" x-show="loaded && cohorts.length" x-cloak>
				<table class="kdna-ei-table kdna-ei-cohort">
					<caption class="kdna-ei-visually-hidden" x-text="t.cohortCaption"></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'First order', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="is-numeric"><?php esc_html_e( 'Customers', 'kdna-ecommerce-insights' ); ?></th>
							<template x-for="i in cohortWidth" :key="i">
								<th scope="col" class="is-numeric" x-text="i === 1 ? t.firstMonth : sprintf( t.monthN, i - 1 )"></th>
							</template>
						</tr>
					</thead>
					<tbody>
						<template x-for="cohort in cohorts" :key="cohort.month">
							<tr>
								<th scope="row" x-text="monthName( cohort.month )"></th>
								<td class="is-numeric kdna-ei-num" x-text="formatNumber( cohort.size )"></td>
								<template x-for="i in cohortWidth" :key="i">
									<td class="kdna-ei-cohort__cell" :style="cellStyle( cohort.retention[ i - 1 ], i - 1 )" :title="cellTitle( cohort, i - 1 )">
										<span class="kdna-ei-num" x-text="cohort.retention[ i - 1 ] === undefined ? '' : percent( cohort.retention[ i - 1 ] )"></span>
									</td>
								</template>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</section>

		<?php // Top customers. ?>
		<section class="kdna-ei-card kdna-ei-area-table" aria-labelledby="kdna-ei-top-customers-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-top-customers-title" class="kdna-ei-card__title"><?php esc_html_e( 'Top customers', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Ranked by profit in this period, before ads and overheads.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<button type="button" class="kdna-ei-btn" @click="exportCsv( 'customers' )" :disabled="exporting || ! loaded">
					<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
					<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
				</button>
			</div>
			<div class="kdna-ei-table-wrap">
				<table class="kdna-ei-table kdna-ei-table--hover">
					<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Top customers by profit', 'kdna-ecommerce-insights' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Customer', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="is-numeric"><?php esc_html_e( 'Orders', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="is-numeric"><?php esc_html_e( 'Revenue', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="is-numeric"><?php esc_html_e( 'Profit', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="is-numeric"><?php esc_html_e( 'All-time orders', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Customer since', 'kdna-ecommerce-insights' ); ?></th>
						</tr>
					</thead>
					<tbody x-show="! loaded">
						<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 6; $kdna_ei_i++ ) : ?>
							<tr aria-hidden="true">
								<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 70%;"></span></td>
								<?php for ( $kdna_ei_j = 0; $kdna_ei_j < 5; $kdna_ei_j++ ) : ?>
									<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
								<?php endfor; ?>
							</tr>
						<?php endfor; ?>
					</tbody>
					<tbody x-show="loaded" x-cloak>
						<template x-for="( customer, index ) in visibleCustomers" :key="index + customer.email">
							<tr>
								<td>
									<div class="kdna-ei-customer">
										<span class="kdna-ei-avatar" aria-hidden="true" x-text="initials( customer.name, customer.email )"></span>
										<div class="kdna-ei-product__text">
											<span class="kdna-ei-product__name">
												<template x-if="customer.profile_url"><a :href="customer.profile_url" target="_blank" rel="noopener" x-text="customer.name"></a></template>
												<template x-if="! customer.profile_url"><span x-text="customer.name"></span></template>
											</span>
											<span class="kdna-ei-product__meta">
												<span x-text="customer.email"></span>
												<template x-if="customer.guest"><span class="kdna-ei-badge kdna-ei-badge--plain" x-text="t.guest"></span></template>
											</span>
										</div>
									</div>
								</td>
								<td class="is-numeric kdna-ei-num" x-text="formatNumber( customer.orders )"></td>
								<td class="is-numeric kdna-ei-num" x-text="money( customer.revenue )"></td>
								<td class="is-numeric kdna-ei-num" :class="{ 'is-negative': customer.profit < 0 }" x-text="money( customer.profit )"></td>
								<td class="is-numeric kdna-ei-num" x-text="formatNumber( customer.lifetime_orders )"></td>
								<td class="kdna-ei-muted" x-text="customer.first_order ? dateLabel( customer.first_order ) : ''"></td>
							</tr>
						</template>
					</tbody>
				</table>
				<template x-if="loaded && ! topCustomers.length">
					<div class="kdna-ei-empty"><p class="kdna-ei-empty__title" x-text="t.noCustomers"></p></div>
				</template>
			</div>
			<template x-if="loaded && topCustomers.length > 10">
				<div class="kdna-ei-card__footer">
					<button type="button" class="kdna-ei-link" @click="showAllCustomers = ! showAllCustomers" x-text="showAllCustomers ? t.showFewer : sprintf( t.showAll, topCustomers.length )"></button>
				</div>
			</template>
		</section>

		<?php // Locations. ?>
		<section class="kdna-ei-card kdna-ei-area-locations" aria-labelledby="kdna-ei-locations-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-locations-title" class="kdna-ei-card__title"><?php esc_html_e( 'Where customers are', 'kdna-ecommerce-insights' ); ?></h2>
				<div class="kdna-ei-locations__actions">
					<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'Group by', 'kdna-ecommerce-insights' ); ?>">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="locationLevel === 'country' ? 'true' : 'false'" @click="locationLevel = 'country'"><?php esc_html_e( 'Country', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="locationLevel === 'state' ? 'true' : 'false'" @click="locationLevel = 'state'"><?php esc_html_e( 'State', 'kdna-ecommerce-insights' ); ?></button>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="exportCsv( 'locations' )" :disabled="exporting" aria-label="<?php esc_attr_e( 'Export locations as CSV', 'kdna-ecommerce-insights' ); ?>" title="<?php esc_attr_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?>">
						<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
					</button>
				</div>
			</div>
			<template x-if="! loaded">
				<div class="kdna-ei-skel-stack" aria-hidden="true">
					<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 6; $kdna_ei_i++ ) : ?>
						<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
					<?php endfor; ?>
				</div>
			</template>
			<table class="kdna-ei-table kdna-ei-location-table" x-show="loaded" x-cloak>
				<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Revenue and customers by location', 'kdna-ecommerce-insights' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Location', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Customers', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Revenue', 'kdna-ecommerce-insights' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="place in locationRows" :key="place.key">
						<tr>
							<th scope="row">
								<span class="kdna-ei-location">
									<span x-text="place.name"></span>
									<span class="kdna-ei-bar" aria-hidden="true"><span class="kdna-ei-bar__fill kdna-ei-bar__fill--accent" :style="'width:' + place.share + '%'"></span></span>
								</span>
							</th>
							<td class="is-numeric kdna-ei-num" x-text="formatNumber( place.customers )"></td>
							<td class="is-numeric kdna-ei-num" x-text="money( place.revenue, 0 )"></td>
						</tr>
					</template>
				</tbody>
			</table>
			<p class="kdna-ei-muted" x-show="loaded && ! locationRows.length" x-cloak x-text="t.noCustomers"></p>
		</section>
	</div>
</div>
