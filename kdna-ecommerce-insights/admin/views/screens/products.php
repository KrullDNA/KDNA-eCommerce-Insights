<?php
/**
 * Products screen (section 8): best and worst performers, category
 * breakdown, the sortable product performance table and the drill-down
 * drawer. Behaviour lives in admin/js/screens/products.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;

$kdna_ei_columns = array(
	'units'       => __( 'Units', 'kdna-ecommerce-insights' ),
	'revenue'     => __( 'Revenue', 'kdna-ecommerce-insights' ),
	'cost'        => __( 'Cost', 'kdna-ecommerce-insights' ),
	'profit'      => __( 'Profit', 'kdna-ecommerce-insights' ),
	'margin'      => __( 'Margin', 'kdna-ecommerce-insights' ),
	'refund_rate' => __( 'Refund rate', 'kdna-ecommerce-insights' ),
);
?>
<div class="kdna-ei-products" x-data="kdnaEiProducts" x-effect="if ( route === 'products' ) ensureLoaded()" @keydown.escape.window="drawerOpen && closeDrawer()">

	<template x-if="loadError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
			<p x-text="loadError"></p>
			<button type="button" class="kdna-ei-btn" @click="load( true )"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</template>

	<div class="kdna-ei-products__top">
		<?php
		// Best and worst performer cards share one layout.
		$kdna_ei_performers = array(
			'best'  => array( __( 'Best performers', 'kdna-ecommerce-insights' ), __( 'Most profit in this period.', 'kdna-ecommerce-insights' ) ),
			'worst' => array( __( 'Worst performers', 'kdna-ecommerce-insights' ), __( 'Least profit in this period.', 'kdna-ecommerce-insights' ) ),
		);
		foreach ( $kdna_ei_performers as $kdna_ei_key => $kdna_ei_card ) :
			?>
			<section class="kdna-ei-card kdna-ei-performers" aria-labelledby="kdna-ei-<?php echo esc_attr( $kdna_ei_key ); ?>-title">
				<div class="kdna-ei-card__header">
					<div>
						<h2 id="kdna-ei-<?php echo esc_attr( $kdna_ei_key ); ?>-title" class="kdna-ei-card__title"><?php echo esc_html( $kdna_ei_card[0] ); ?></h2>
						<p class="kdna-ei-card__subtitle"><?php echo esc_html( $kdna_ei_card[1] ); ?></p>
					</div>
				</div>
				<template x-if="! loaded">
					<div class="kdna-ei-skel-stack" aria-hidden="true">
						<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 5; $kdna_ei_i++ ) : ?>
							<div class="kdna-ei-skel-row"><span class="kdna-ei-skeleton" style="width: 36px; height: 36px; flex: none;"></span><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></div>
						<?php endfor; ?>
					</div>
				</template>
				<ol class="kdna-ei-performers__list" x-show="loaded" x-cloak>
					<template x-for="item in <?php echo esc_attr( $kdna_ei_key ); ?>" :key="rowKey( item )">
						<li>
							<button type="button" class="kdna-ei-performer" @click="openDrawer( item, $event.currentTarget )">
								<span class="kdna-ei-thumb kdna-ei-thumb--sm">
									<template x-if="item.thumbnail"><img :src="item.thumbnail" alt="" loading="lazy" /></template>
									<template x-if="! item.thumbnail"><svg class="kdna-ei-icon kdna-ei-icon--sm" aria-hidden="true"><use href="#kdna-ei-icon-box"></use></svg></template>
								</span>
								<span class="kdna-ei-performer__name" x-text="item.name"></span>
								<span class="kdna-ei-performer__figures">
									<span class="kdna-ei-num" :class="{ 'is-negative': item.profit < 0 }" x-text="money( item.profit, 0 )"></span>
									<span class="kdna-ei-muted kdna-ei-num" x-text="item.margin === null ? '' : percent( item.margin )"></span>
								</span>
							</button>
						</li>
					</template>
				</ol>
				<p class="kdna-ei-muted" x-show="loaded && ! <?php echo esc_attr( $kdna_ei_key ); ?>.length" x-cloak x-text="t.noSales"></p>
			</section>
		<?php endforeach; ?>

		<?php // Category breakdown. ?>
		<section class="kdna-ei-card kdna-ei-categories" aria-labelledby="kdna-ei-categories-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-categories-title" class="kdna-ei-card__title"><?php esc_html_e( 'Categories', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Revenue by category. Choose one to filter the table.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="exportCsv( 'categories' )" :disabled="exporting" aria-label="<?php esc_attr_e( 'Export categories as CSV', 'kdna-ecommerce-insights' ); ?>" title="<?php esc_attr_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?>">
					<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
				</button>
			</div>
			<template x-if="! loaded">
				<div class="kdna-ei-skel-stack" aria-hidden="true">
					<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 5; $kdna_ei_i++ ) : ?>
						<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
					<?php endfor; ?>
				</div>
			</template>
			<table class="kdna-ei-table kdna-ei-category-table" x-show="loaded" x-cloak>
				<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Revenue, profit and margin by category', 'kdna-ecommerce-insights' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Category', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Revenue', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Margin', 'kdna-ecommerce-insights' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="category in topCategories" :key="category.id">
						<tr :class="{ 'is-active': String( category.id ) === filters.category }">
							<th scope="row">
								<button type="button" class="kdna-ei-category" @click="filterCategory( category.id )" :aria-pressed="String( category.id ) === filters.category ? 'true' : 'false'">
									<span x-text="category.name"></span>
									<span class="kdna-ei-bar" aria-hidden="true"><span class="kdna-ei-bar__fill kdna-ei-bar__fill--accent" :style="'width:' + category.share + '%'"></span></span>
								</button>
							</th>
							<td class="is-numeric kdna-ei-num" x-text="money( category.revenue, 0 )"></td>
							<td class="is-numeric kdna-ei-num" :class="{ 'is-negative': category.margin < 0 }" x-text="category.margin === null ? '–' : percent( category.margin )"></td>
						</tr>
					</template>
				</tbody>
			</table>
			<p class="kdna-ei-muted" x-show="loaded && ! categories.length" x-cloak x-text="t.noSales"></p>
		</section>
	</div>

	<?php // Product performance table. ?>
	<section class="kdna-ei-card kdna-ei-product-table-card" aria-labelledby="kdna-ei-product-table-title">
		<div class="kdna-ei-card__header">
			<div>
				<h2 id="kdna-ei-product-table-title" class="kdna-ei-card__title"><?php esc_html_e( 'Product performance', 'kdna-ecommerce-insights' ); ?></h2>
				<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Revenue and profit after refunds, excluding tax. Choose a product to see its own chart.', 'kdna-ecommerce-insights' ); ?></p>
			</div>
			<button type="button" class="kdna-ei-btn" @click="exportCsv( 'products' )" :disabled="exporting || ! loaded">
				<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
				<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
			</button>
		</div>

		<div class="kdna-ei-toolbar">
			<label class="kdna-ei-search">
				<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Search products', 'kdna-ecommerce-insights' ); ?></span>
				<?php Admin::icon( 'search', 'kdna-ei-icon--sm' ); ?>
				<input type="search" class="kdna-ei-input" placeholder="<?php esc_attr_e( 'Search products', 'kdna-ecommerce-insights' ); ?>" x-model="filters.search" @input.debounce.350ms="applyFilters()" />
			</label>

			<label class="kdna-ei-field-inline">
				<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Category', 'kdna-ecommerce-insights' ); ?></span>
				<select class="kdna-ei-select" x-model="filters.category" @change="applyFilters()">
					<option value=""><?php esc_html_e( 'All categories', 'kdna-ecommerce-insights' ); ?></option>
					<template x-for="category in categoryOptions" :key="category.id">
						<option :value="String( category.id )" x-text="category.name" :selected="filters.category === String( category.id )"></option>
					</template>
				</select>
			</label>

			<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'Show', 'kdna-ecommerce-insights' ); ?>">
				<button type="button" class="kdna-ei-segmented__item" :aria-pressed="filters.group === 'product' ? 'true' : 'false'" @click="setGroup( 'product' )"><?php esc_html_e( 'Products', 'kdna-ecommerce-insights' ); ?></button>
				<button type="button" class="kdna-ei-segmented__item" :aria-pressed="filters.group === 'variation' ? 'true' : 'false'" @click="setGroup( 'variation' )"><?php esc_html_e( 'Variations', 'kdna-ecommerce-insights' ); ?></button>
			</div>

			<label class="kdna-ei-switch">
				<input type="checkbox" x-model="filters.loss_only" @change="applyFilters()" />
				<span class="kdna-ei-switch__track" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Loss-making only', 'kdna-ecommerce-insights' ); ?></span>
				<span class="kdna-ei-badge kdna-ei-badge--negative" x-show="lossCount > 0" x-cloak x-text="formatNumber( lossCount )"></span>
			</label>

			<button type="button" class="kdna-ei-link" x-show="filtered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear filters', 'kdna-ecommerce-insights' ); ?></button>
		</div>

		<div class="kdna-ei-table-wrap" :class="{ 'is-loading': loading && loaded }">
			<table class="kdna-ei-table kdna-ei-table--hover kdna-ei-performance-table">
				<caption class="kdna-ei-visually-hidden" x-text="t.tableCaption"></caption>
				<thead>
					<tr>
						<th scope="col" :aria-sort="ariaSort( 'name' )">
							<button type="button" class="kdna-ei-sort" @click="sortBy( 'name' )">
								<span><?php esc_html_e( 'Product', 'kdna-ecommerce-insights' ); ?></span>
								<svg class="kdna-ei-icon kdna-ei-sort__icon" aria-hidden="true"><use href="#kdna-ei-icon-chevron-down"></use></svg>
							</button>
						</th>
						<?php foreach ( $kdna_ei_columns as $kdna_ei_key => $kdna_ei_label ) : ?>
							<th scope="col" class="is-numeric" :aria-sort="ariaSort( '<?php echo esc_js( $kdna_ei_key ); ?>' )">
								<button type="button" class="kdna-ei-sort" @click="sortBy( '<?php echo esc_js( $kdna_ei_key ); ?>' )">
									<span><?php echo esc_html( $kdna_ei_label ); ?></span>
									<svg class="kdna-ei-icon kdna-ei-sort__icon" aria-hidden="true"><use href="#kdna-ei-icon-chevron-down"></use></svg>
								</button>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>

				<tbody x-show="! loaded">
					<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 8; $kdna_ei_i++ ) : ?>
						<tr aria-hidden="true">
							<td><div class="kdna-ei-skel-row"><span class="kdna-ei-skeleton" style="width: 40px; height: 40px; flex: none;"></span><span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 60%;"></span></div></td>
							<?php foreach ( $kdna_ei_columns as $kdna_ei_key => $kdna_ei_label ) : ?>
								<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
							<?php endforeach; ?>
						</tr>
					<?php endfor; ?>
				</tbody>

				<tbody x-show="loaded" x-cloak>
					<template x-for="row in rows" :key="rowKey( row )">
						<tr :class="{ 'is-loss': row.profit < 0 }">
							<td>
								<div class="kdna-ei-product">
									<span class="kdna-ei-thumb">
										<template x-if="row.thumbnail"><img :src="row.thumbnail" alt="" loading="lazy" /></template>
										<template x-if="! row.thumbnail"><svg class="kdna-ei-icon kdna-ei-icon--sm" aria-hidden="true"><use href="#kdna-ei-icon-box"></use></svg></template>
									</span>
									<div class="kdna-ei-product__text">
										<button type="button" class="kdna-ei-product__name kdna-ei-product__open" @click="openDrawer( row, $event.currentTarget )" x-text="row.name"></button>
										<span class="kdna-ei-product__meta">
											<span x-show="row.variation" x-text="row.variation"></span>
											<template x-if="row.missing_cost">
												<span class="kdna-ei-badge kdna-ei-badge--warning" :title="t.missingCostHelp" x-text="t.missingCost"></span>
											</template>
										</span>
									</div>
								</div>
							</td>
							<td class="is-numeric kdna-ei-num" x-text="formatNumber( row.units )"></td>
							<td class="is-numeric kdna-ei-num" x-text="money( row.revenue )"></td>
							<td class="is-numeric kdna-ei-num" x-text="money( row.cost )"></td>
							<td class="is-numeric kdna-ei-num" :class="{ 'is-negative': row.profit < 0 }" x-text="money( row.profit )"></td>
							<td class="is-numeric">
								<span class="kdna-ei-margin kdna-ei-num" :class="row.missing_cost ? 'kdna-ei-change--neutral' : marginClass( row.margin )" :title="row.missing_cost ? t.missingCostHelp : null" x-text="row.margin === null ? '–' : percent( row.margin )"></span>
							</td>
							<td class="is-numeric kdna-ei-num" :class="{ 'is-warning': row.refund_rate >= 10 }" x-text="row.refund_rate === null ? '–' : percent( row.refund_rate )"></td>
						</tr>
					</template>
				</tbody>
			</table>

			<template x-if="loaded && ! rows.length">
				<div class="kdna-ei-empty">
					<p class="kdna-ei-empty__title" x-text="filtered ? t.noMatches : t.noSales"></p>
					<button type="button" class="kdna-ei-btn" x-show="filtered" @click="resetFilters()"><?php esc_html_e( 'Clear filters', 'kdna-ecommerce-insights' ); ?></button>
				</div>
			</template>
		</div>

		<div class="kdna-ei-pagination" x-show="loaded && total > 0" x-cloak>
			<p class="kdna-ei-muted" x-text="rangeText"></p>
			<div class="kdna-ei-pagination__buttons">
				<label class="kdna-ei-field-inline">
					<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Rows per page', 'kdna-ecommerce-insights' ); ?></span>
					<select class="kdna-ei-select kdna-ei-select--small" x-model.number="perPage" @change="goToPage( 1 )">
						<option value="25">25</option>
						<option value="50">50</option>
						<option value="100">100</option>
					</select>
				</label>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" :disabled="page <= 1" @click="goToPage( page - 1 )" aria-label="<?php esc_attr_e( 'Previous page', 'kdna-ecommerce-insights' ); ?>">
					<?php Admin::icon( 'chevron-left', 'kdna-ei-icon--sm' ); ?>
				</button>
				<span class="kdna-ei-pagination__page kdna-ei-num" x-text="sprintf( t.pageOf, page, pages )"></span>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" :disabled="page >= pages" @click="goToPage( page + 1 )" aria-label="<?php esc_attr_e( 'Next page', 'kdna-ecommerce-insights' ); ?>">
					<?php Admin::icon( 'chevron-right', 'kdna-ei-icon--sm' ); ?>
				</button>
			</div>
		</div>
	</section>

	<?php // Product drill-down drawer. ?>
	<div class="kdna-ei-drawer-backdrop" x-show="drawerOpen" x-cloak x-transition.opacity.duration.200ms @click="closeDrawer()"></div>
	<aside
		class="kdna-ei-drawer"
		x-show="drawerOpen"
		x-cloak
		x-ref="drawer"
		role="dialog"
		aria-modal="true"
		aria-labelledby="kdna-ei-drawer-title"
		@keydown.tab="trapFocus( $event )"
		x-transition:enter="kdna-ei-drawer--enter"
		x-transition:enter-start="kdna-ei-drawer--from"
		x-transition:enter-end="kdna-ei-drawer--to"
		x-transition:leave="kdna-ei-drawer--leave"
		x-transition:leave-start="kdna-ei-drawer--to"
		x-transition:leave-end="kdna-ei-drawer--from"
	>
		<div class="kdna-ei-drawer__header">
			<span class="kdna-ei-thumb kdna-ei-thumb--lg">
				<template x-if="detail && detail.product.thumbnail"><img :src="detail.product.thumbnail" alt="" /></template>
				<template x-if="! ( detail && detail.product.thumbnail )"><svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-box"></use></svg></template>
			</span>
			<div class="kdna-ei-drawer__heading">
				<h2 id="kdna-ei-drawer-title" class="kdna-ei-card__title" x-text="drawerItem ? drawerItem.name : ''"></h2>
				<p class="kdna-ei-muted">
					<span x-show="drawerVariation" x-text="drawerVariation"></span>
					<span x-show="detail && detail.product.sku" x-text="detail ? sprintf( t.sku, detail.product.sku ) : ''"></span>
				</p>
			</div>
			<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost kdna-ei-drawer__close" x-ref="drawerClose" @click="closeDrawer()" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">
				<?php Admin::icon( 'close', 'kdna-ei-icon--sm' ); ?>
			</button>
		</div>

		<div class="kdna-ei-drawer__body" :aria-busy="detailLoading ? 'true' : 'false'">
			<template x-if="detailError">
				<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="detailError"></p></div>
			</template>

			<template x-if="drawerVariationId && detail && ! detailLoading">
				<button type="button" class="kdna-ei-link kdna-ei-drawer__back" @click="showVariation( 0 )">
					<?php Admin::icon( 'chevron-left', 'kdna-ei-icon--sm' ); ?>
					<span x-text="t.allVariations"></span>
				</button>
			</template>

			<dl class="kdna-ei-drawer__stats">
				<template x-for="stat in drawerStats" :key="stat.key">
					<div class="kdna-ei-drawer__stat">
						<dt x-text="stat.label"></dt>
						<dd>
							<span class="kdna-ei-num" x-show="! detailLoading" :class="{ 'is-negative': stat.negative }" x-text="stat.value"></span>
							<span class="kdna-ei-skeleton kdna-ei-skeleton--text" x-show="detailLoading" aria-hidden="true"></span>
							<template x-if="! detailLoading && stat.change">
								<span class="kdna-ei-change" :class="'kdna-ei-change--' + stat.change.sentiment" :title="stat.change.title">
									<template x-if="stat.change.direction === 'up'"><svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-arrow-up"></use></svg></template>
									<template x-if="stat.change.direction === 'down'"><svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-arrow-down"></use></svg></template>
									<span x-text="stat.change.text"></span>
									<span class="kdna-ei-visually-hidden" x-text="stat.change.title"></span>
								</span>
							</template>
						</dd>
					</div>
				</template>
			</dl>

			<section class="kdna-ei-drawer__section" aria-labelledby="kdna-ei-drawer-chart-title">
				<div class="kdna-ei-card__header">
					<h3 id="kdna-ei-drawer-chart-title" class="kdna-ei-drawer__subtitle" x-text="t.salesAndProfit"></h3>
					<div class="kdna-ei-chart-legend" aria-hidden="true">
						<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><span x-text="t.revenue"></span></span>
						<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--positive"></span><span x-text="t.profit"></span></span>
					</div>
				</div>
				<div class="kdna-ei-chart kdna-ei-chart--drawer">
					<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" x-show="detailLoading" aria-hidden="true"></span>
					<canvas x-ref="drawerChart" role="img" :aria-label="drawerChartSummary"></canvas>
				</div>
			</section>

			<template x-if="detail && detail.variations.length">
				<section class="kdna-ei-drawer__section" aria-labelledby="kdna-ei-drawer-variations-title">
					<h3 id="kdna-ei-drawer-variations-title" class="kdna-ei-drawer__subtitle" x-text="t.byVariation"></h3>
					<table class="kdna-ei-table kdna-ei-table--hover kdna-ei-table--compact">
						<thead>
							<tr>
								<th scope="col" x-text="t.variation"></th>
								<th scope="col" class="is-numeric" x-text="t.units"></th>
								<th scope="col" class="is-numeric" x-text="t.revenue"></th>
								<th scope="col" class="is-numeric" x-text="t.profit"></th>
								<th scope="col" class="is-numeric" x-text="t.margin"></th>
							</tr>
						</thead>
						<tbody>
							<template x-for="variation in detail.variations" :key="variation.variation_id">
								<tr>
									<th scope="row"><button type="button" class="kdna-ei-link" @click="showVariation( variation.variation_id, variation.name )" x-text="variation.name"></button></th>
									<td class="is-numeric kdna-ei-num" x-text="formatNumber( variation.units )"></td>
									<td class="is-numeric kdna-ei-num" x-text="money( variation.revenue, 0 )"></td>
									<td class="is-numeric kdna-ei-num" :class="{ 'is-negative': variation.profit < 0 }" x-text="money( variation.profit, 0 )"></td>
									<td class="is-numeric kdna-ei-num" x-text="variation.margin === null ? '–' : percent( variation.margin )"></td>
								</tr>
							</template>
						</tbody>
					</table>
				</section>
			</template>

			<template x-if="detail">
				<section class="kdna-ei-drawer__section" aria-labelledby="kdna-ei-drawer-details-title">
					<h3 id="kdna-ei-drawer-details-title" class="kdna-ei-drawer__subtitle" x-text="t.details"></h3>
					<dl class="kdna-ei-facts kdna-ei-facts--compact">
						<div><dt x-text="t.price"></dt><dd class="kdna-ei-num" x-text="detail.product.price === null ? '–' : money( detail.product.price )"></dd></div>
						<div><dt x-text="t.costPrice"></dt><dd class="kdna-ei-num" :class="{ 'is-warning': detail.product.cost === null }" x-text="detail.product.cost === null ? t.noCost : money( detail.product.cost )"></dd></div>
						<div><dt x-text="t.stock"></dt><dd class="kdna-ei-num" x-text="stockText"></dd></div>
					</dl>
					<div class="kdna-ei-drawer__links">
						<a class="kdna-ei-btn" :href="detail.product.edit_url" target="_blank" rel="noopener">
							<span x-text="t.editProduct"></span>
						</a>
						<template x-if="detail.product.cost === null">
							<a class="kdna-ei-btn" href="#/costs" @click="closeDrawer()" x-text="t.addCost"></a>
						</template>
					</div>
				</section>
			</template>
		</div>
	</aside>
</div>
