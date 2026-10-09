<?php
/**
 * Costs screen: product cost editor, CSV import and export, and import
 * from another cost plugin. Behaviour lives in admin/js/screens/costs.js.
 *
 * Stage 3 adds payment fee, shipping and overhead tools to this screen.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;
?>
<div class="kdna-ei-costs" x-data="kdnaEiCosts" x-effect="if ( route === 'costs' ) ensureLoaded()">

	<?php // Summary cards. ?>
	<div class="kdna-ei-stat-grid">
		<div class="kdna-ei-card kdna-ei-stat">
			<p class="kdna-ei-stat__label"><?php esc_html_e( 'Products and variations', 'kdna-ecommerce-insights' ); ?></p>
			<template x-if="loaded">
				<p class="kdna-ei-stat__value kdna-ei-num" x-text="summary.products.toLocaleString()"></p>
			</template>
			<template x-if="! loaded">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
			</template>
			<p class="kdna-ei-stat__note"><?php esc_html_e( 'Everything you can sell, including each variation.', 'kdna-ecommerce-insights' ); ?></p>
		</div>

		<div class="kdna-ei-card kdna-ei-stat" :class="{ 'is-warning': summary.missing > 0 }">
			<p class="kdna-ei-stat__label"><?php esc_html_e( 'Missing a cost price', 'kdna-ecommerce-insights' ); ?></p>
			<template x-if="loaded">
				<p class="kdna-ei-stat__value kdna-ei-num" x-text="summary.missing.toLocaleString()"></p>
			</template>
			<template x-if="! loaded">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
			</template>
			<p class="kdna-ei-stat__note">
				<template x-if="summary.missing > 0">
					<button type="button" class="kdna-ei-link" @click="showMissing()"><?php esc_html_e( 'Show products missing a cost', 'kdna-ecommerce-insights' ); ?></button>
				</template>
				<template x-if="loaded && summary.missing === 0">
					<span><?php esc_html_e( 'Every product has a cost. Profit figures are complete.', 'kdna-ecommerce-insights' ); ?></span>
				</template>
			</p>
		</div>

		<div class="kdna-ei-card kdna-ei-stat">
			<p class="kdna-ei-stat__label"><?php esc_html_e( 'Average product margin', 'kdna-ecommerce-insights' ); ?></p>
			<template x-if="loaded">
				<p class="kdna-ei-stat__value kdna-ei-num" x-text="summary.average_margin === null ? '–' : percent( summary.average_margin )"></p>
			</template>
			<template x-if="! loaded">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
			</template>
			<p class="kdna-ei-stat__note"><?php esc_html_e( 'Price before tax, less cost price, across products with a cost.', 'kdna-ecommerce-insights' ); ?></p>
		</div>
	</div>

	<?php // Product cost editor. ?>
	<div class="kdna-ei-card kdna-ei-cost-editor">
		<div class="kdna-ei-card__header kdna-ei-cost-editor__header">
			<div>
				<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Product costs', 'kdna-ecommerce-insights' ); ?></h2>
				<p class="kdna-ei-card__subtitle">
					<span x-show="! native"><?php esc_html_e( 'Type a cost into any row. Variations left empty use their parent product cost.', 'kdna-ecommerce-insights' ); ?></span>
					<span x-show="native" x-cloak><?php esc_html_e( 'Costs are shared with WooCommerce Cost of Goods Sold, so there is only ever one cost per product.', 'kdna-ecommerce-insights' ); ?></span>
				</p>
			</div>
			<div class="kdna-ei-toolbar__actions">
				<button type="button" class="kdna-ei-btn" @click="exportCsv()">
					<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
					<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
				</button>
				<button type="button" class="kdna-ei-btn" @click="openCsv()">
					<?php Admin::icon( 'upload', 'kdna-ei-icon--sm' ); ?>
					<span><?php esc_html_e( 'Import CSV', 'kdna-ecommerce-insights' ); ?></span>
				</button>
				<button type="button" class="kdna-ei-btn" @click="openPlugins()">
					<?php Admin::icon( 'plug', 'kdna-ei-icon--sm' ); ?>
					<span><?php esc_html_e( 'Import from another cost plugin', 'kdna-ecommerce-insights' ); ?></span>
				</button>
			</div>
		</div>

		<div class="kdna-ei-toolbar">
			<label class="kdna-ei-search">
				<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Search products', 'kdna-ecommerce-insights' ); ?></span>
				<?php Admin::icon( 'search', 'kdna-ei-icon--sm' ); ?>
				<input
					type="search"
					class="kdna-ei-input"
					placeholder="<?php esc_attr_e( 'Search by name, SKU or ID', 'kdna-ecommerce-insights' ); ?>"
					x-model="filters.search"
					@input.debounce.350ms="applyFilters()"
				/>
			</label>

			<label class="kdna-ei-field-inline">
				<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Category', 'kdna-ecommerce-insights' ); ?></span>
				<select class="kdna-ei-select" x-model="filters.category" @change="applyFilters()">
					<option value=""><?php esc_html_e( 'All categories', 'kdna-ecommerce-insights' ); ?></option>
					<template x-for="category in categories" :key="category.id">
						<option :value="String( category.id )" x-text="'  '.repeat( category.depth ) + category.name.trim()" :selected="filters.category === String( category.id )"></option>
					</template>
				</select>
			</label>

			<label class="kdna-ei-field-inline">
				<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Stock status', 'kdna-ecommerce-insights' ); ?></span>
				<select class="kdna-ei-select" x-model="filters.stock_status" @change="applyFilters()">
					<option value=""><?php esc_html_e( 'Any stock status', 'kdna-ecommerce-insights' ); ?></option>
					<option value="instock"><?php esc_html_e( 'In stock', 'kdna-ecommerce-insights' ); ?></option>
					<option value="outofstock"><?php esc_html_e( 'Out of stock', 'kdna-ecommerce-insights' ); ?></option>
					<option value="onbackorder"><?php esc_html_e( 'On backorder', 'kdna-ecommerce-insights' ); ?></option>
				</select>
			</label>

			<label class="kdna-ei-switch">
				<input type="checkbox" x-model="filters.missing" @change="applyFilters()" />
				<span class="kdna-ei-switch__track" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Missing cost only', 'kdna-ecommerce-insights' ); ?></span>
			</label>

			<button type="button" class="kdna-ei-link" x-show="filtered" x-cloak @click="resetFilters()"><?php esc_html_e( 'Clear filters', 'kdna-ecommerce-insights' ); ?></button>
		</div>

		<template x-if="notice">
			<div class="kdna-ei-notice" :class="'kdna-ei-notice--' + notice.type" role="status">
				<p x-text="notice.text"></p>
				<template x-if="notice.errors && notice.errors.length">
					<ul class="kdna-ei-notice__list">
						<template x-for="error in notice.errors.slice( 0, 10 )" :key="error.id">
							<li x-text="'#' + error.id + ': ' + error.message"></li>
						</template>
					</ul>
				</template>
				<button type="button" class="kdna-ei-notice__close" @click="notice = null" aria-label="<?php esc_attr_e( 'Dismiss', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>
		</template>

		<template x-if="loadError">
			<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
				<p x-text="loadError"></p>
				<button type="button" class="kdna-ei-btn" @click="load()"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
			</div>
		</template>

		<div class="kdna-ei-table-wrap" :class="{ 'is-loading': loading && loaded }">
			<table class="kdna-ei-table kdna-ei-table--hover kdna-ei-cost-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Product', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'SKU', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Price', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="kdna-ei-cost-table__cost"><?php esc_html_e( 'Cost price', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Margin', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Stock', 'kdna-ecommerce-insights' ); ?></th>
					</tr>
				</thead>

				<tbody x-show="! loaded">
					<?php for ( $i = 0; $i < 8; $i++ ) : ?>
						<tr aria-hidden="true">
							<td><div class="kdna-ei-skel-row"><span class="kdna-ei-skeleton" style="width: 40px; height: 40px; flex: none;"></span><span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 60%;"></span></div></td>
							<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
							<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
							<td><span class="kdna-ei-skeleton kdna-ei-skeleton--block" style="height: 38px;"></span></td>
							<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
							<td><span class="kdna-ei-skeleton kdna-ei-skeleton--pill"></span></td>
						</tr>
					<?php endfor; ?>
				</tbody>

				<tbody x-show="loaded" x-cloak>
					<template x-for="row in rows" :key="row.id">
						<tr :class="{ 'is-variation': row.type === 'variation', 'is-parent': row.children_count > 0, 'is-dirty': isDirty( row ), 'is-missing': isMissing( row ) }">
							<td class="kdna-ei-cost-table__product">
								<div class="kdna-ei-product">
									<template x-if="row.type !== 'variation'">
										<span class="kdna-ei-thumb">
											<template x-if="row.thumbnail"><img :src="row.thumbnail" alt="" loading="lazy" /></template>
										</span>
									</template>
									<div class="kdna-ei-product__text">
										<template x-if="row.type !== 'variation'">
											<a class="kdna-ei-product__name" :href="row.edit_url" target="_blank" rel="noopener" x-text="row.name"></a>
										</template>
										<template x-if="row.type === 'variation'">
											<span class="kdna-ei-product__name" x-text="row.attributes || ( '#' + row.id )"></span>
										</template>
										<span class="kdna-ei-product__meta">
											<template x-if="row.children_count > 0">
												<span x-text="sprintf( row.children_count === 1 ? i18nCosts.oneVariation : i18nCosts.variations, row.children_count )"></span>
											</template>
											<template x-if="row.status !== 'publish' && row.type !== 'variation'">
												<span class="kdna-ei-badge kdna-ei-badge--plain" x-text="i18nCosts.statuses[ row.status ] || row.status"></span>
											</template>
										</span>
									</div>
								</div>
							</td>
							<td class="kdna-ei-muted" x-text="row.sku || '–'"></td>
							<td class="is-numeric" x-text="row.price === null ? '–' : money( row.price )"></td>
							<td class="kdna-ei-cost-table__cost">
								<label class="kdna-ei-cost-field" :class="{ 'is-invalid': isInvalid( row ), 'is-inherited': ! isDirty( row ) && row.own_cost === null && effectiveCost( row ) !== null }">
									<span class="kdna-ei-visually-hidden" x-text="sprintf( i18nCosts.costFor, row.name + ( row.attributes ? ' ' + row.attributes : '' ) )"></span>
									<span class="kdna-ei-cost-field__symbol" aria-hidden="true"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ) ); ?></span>
									<input
										type="text"
										inputmode="decimal"
										autocomplete="off"
										class="kdna-ei-input kdna-ei-cost-input"
										:value="inputValue( row )"
										:placeholder="placeholder( row )"
										:aria-invalid="isInvalid( row ) ? 'true' : 'false'"
										@input="setCost( row, $event.target.value )"
										@keydown.enter.prevent="nextInput( $event )"
									/>
								</label>
							</td>
							<td class="is-numeric">
								<template x-if="row.sellable">
									<span class="kdna-ei-margin kdna-ei-num" :class="marginClass( margin( row ) )">
										<span x-text="margin( row ) === null ? ( isMissing( row ) ? i18nCosts.noCostShort : '–' ) : percent( margin( row ) )"></span>
									</span>
								</template>
							</td>
							<td>
								<template x-if="row.sellable || row.type === 'variable'">
									<span class="kdna-ei-badge" :class="stock( row ).cls" x-text="stock( row ).label"></span>
								</template>
							</td>
						</tr>
					</template>
				</tbody>
			</table>

			<template x-if="loaded && ! rows.length">
				<div class="kdna-ei-empty">
					<p class="kdna-ei-empty__title" x-text="filtered ? i18nCosts.noMatches : i18nCosts.noProducts"></p>
					<button type="button" class="kdna-ei-btn" x-show="filtered" @click="resetFilters()"><?php esc_html_e( 'Clear filters', 'kdna-ecommerce-insights' ); ?></button>
				</div>
			</template>
		</div>

		<div class="kdna-ei-pagination" x-show="loaded && total > 0" x-cloak>
			<p class="kdna-ei-muted" x-text="rangeText"></p>
			<div class="kdna-ei-pagination__buttons" x-show="pages > 1">
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" :disabled="page <= 1" @click="goToPage( page - 1 )" aria-label="<?php esc_attr_e( 'Previous page', 'kdna-ecommerce-insights' ); ?>">
					<?php Admin::icon( 'chevron-left', 'kdna-ei-icon--sm' ); ?>
				</button>
				<span class="kdna-ei-pagination__page kdna-ei-num" x-text="sprintf( i18nCosts.pageOf, page, pages )"></span>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" :disabled="page >= pages" @click="goToPage( page + 1 )" aria-label="<?php esc_attr_e( 'Next page', 'kdna-ecommerce-insights' ); ?>">
					<?php Admin::icon( 'chevron-right', 'kdna-ei-icon--sm' ); ?>
				</button>
			</div>
		</div>
	</div>

	<?php // Save bar, appears while there are unsaved changes. ?>
	<div class="kdna-ei-savebar" x-show="dirtyCount > 0 || saving" x-cloak x-transition.opacity role="region" aria-label="<?php esc_attr_e( 'Unsaved changes', 'kdna-ecommerce-insights' ); ?>">
		<div class="kdna-ei-savebar__text">
			<template x-if="! saving">
				<span>
					<strong x-text="sprintf( dirtyCount === 1 ? i18nCosts.oneUnsaved : i18nCosts.unsaved, dirtyCount )"></strong>
					<template x-if="invalidCount > 0">
						<span class="kdna-ei-savebar__error" x-text="sprintf( i18nCosts.invalidCount, invalidCount )"></span>
					</template>
				</span>
			</template>
			<template x-if="saving">
				<span x-text="sprintf( i18nCosts.saving, saveDone, saveTotal )"></span>
			</template>
		</div>
		<div class="kdna-ei-savebar__actions">
			<button type="button" class="kdna-ei-btn" @click="discard()" :disabled="saving"><?php esc_html_e( 'Discard', 'kdna-ecommerce-insights' ); ?></button>
			<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="save()" :disabled="saving || invalidCount > 0"><?php esc_html_e( 'Save changes', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</div>

	<?php // CSV import window. ?>
	<div class="kdna-ei-modal" x-show="csv.open" x-cloak x-transition.opacity @keydown.escape.stop="closeCsv()">
		<div class="kdna-ei-modal__backdrop" @click="closeCsv()"></div>
		<div class="kdna-ei-modal__dialog kdna-ei-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-csv-title" tabindex="-1" x-ref="csvDialog">
			<div class="kdna-ei-modal__header">
				<h2 id="kdna-ei-csv-title" class="kdna-ei-card__title"><?php esc_html_e( 'Import costs from a CSV', 'kdna-ecommerce-insights' ); ?></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closeCsv()" :disabled="csv.busy" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>

			<div class="kdna-ei-modal__body">
				<template x-if="csv.error">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="csv.error"></p></div>
				</template>

				<div x-show="csv.step === 'choose'">
					<p class="kdna-ei-muted"><?php esc_html_e( 'Use a CSV with an "id" or "sku" column and a "cost" column. The easiest way is to export your products, fill in the cost column, then import the file here. You will see exactly what changes before anything is saved.', 'kdna-ecommerce-insights' ); ?></p>
					<label class="kdna-ei-dropzone" :class="{ 'is-busy': csv.busy }">
						<input type="file" accept=".csv,text/csv" class="kdna-ei-visually-hidden" @change="previewCsv( $event )" :disabled="csv.busy" />
						<?php Admin::icon( 'upload', 'kdna-ei-icon--lg' ); ?>
						<span x-show="! csv.busy"><?php esc_html_e( 'Choose a CSV file', 'kdna-ecommerce-insights' ); ?></span>
						<span x-show="csv.busy" x-text="sprintf( i18nCosts.checking, csv.fileName )"></span>
						<span class="kdna-ei-muted"><?php esc_html_e( 'Up to 5 MB', 'kdna-ecommerce-insights' ); ?></span>
					</label>
				</div>

				<template x-if="csv.step === 'preview' && csv.result">
					<div>
						<div class="kdna-ei-tabs" role="tablist">
							<button type="button" role="tab" class="kdna-ei-tab" :aria-selected="csv.view === 'change' ? 'true' : 'false'" @click="csv.view = 'change'">
								<?php esc_html_e( 'Changes', 'kdna-ecommerce-insights' ); ?> <span class="kdna-ei-tab__count" x-text="csv.result.counts.change"></span>
							</button>
							<button type="button" role="tab" class="kdna-ei-tab" :aria-selected="csv.view === 'error' ? 'true' : 'false'" @click="csv.view = 'error'">
								<?php esc_html_e( 'Problems', 'kdna-ecommerce-insights' ); ?> <span class="kdna-ei-tab__count" :class="{ 'is-negative': csv.result.counts.error > 0 }" x-text="csv.result.counts.error"></span>
							</button>
							<button type="button" role="tab" class="kdna-ei-tab" :aria-selected="csv.view === 'unchanged' ? 'true' : 'false'" @click="csv.view = 'unchanged'">
								<?php esc_html_e( 'No change', 'kdna-ecommerce-insights' ); ?> <span class="kdna-ei-tab__count" x-text="csv.result.counts.unchanged"></span>
							</button>
							<button type="button" role="tab" class="kdna-ei-tab" :aria-selected="csv.view === 'skipped' ? 'true' : 'false'" @click="csv.view = 'skipped'">
								<?php esc_html_e( 'Left blank', 'kdna-ecommerce-insights' ); ?> <span class="kdna-ei-tab__count" x-text="csv.result.counts.skipped"></span>
							</button>
						</div>

						<div class="kdna-ei-table-wrap kdna-ei-modal__scroll">
							<table class="kdna-ei-table kdna-ei-table--compact">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Line', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Product', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col"><?php esc_html_e( 'SKU', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col" class="is-numeric"><?php esc_html_e( 'Current cost', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col" class="is-numeric"><?php esc_html_e( 'New cost', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Notes', 'kdna-ecommerce-insights' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<template x-for="row in csvRows" :key="row.line">
										<tr :class="'is-' + row.status">
											<td class="kdna-ei-num kdna-ei-muted" x-text="row.line"></td>
											<td x-text="row.name || '–'"></td>
											<td class="kdna-ei-muted" x-text="row.sku || '–'"></td>
											<td class="is-numeric" x-text="row.old === null ? '–' : money( row.old )"></td>
											<td class="is-numeric" x-text="row.new === null ? '–' : money( row.new )"></td>
											<td class="kdna-ei-csv-note" x-text="row.message"></td>
										</tr>
									</template>
								</tbody>
							</table>
							<p class="kdna-ei-empty__title kdna-ei-muted" x-show="! csvRows.length"><?php esc_html_e( 'Nothing in this list.', 'kdna-ecommerce-insights' ); ?></p>
						</div>
					</div>
				</template>

				<template x-if="csv.step === 'done'">
					<div class="kdna-ei-done">
						<p class="kdna-ei-done__title" x-text="sprintf( i18nCosts.importDone, csv.saved )"></p>
						<template x-if="csv.saveErrors && csv.saveErrors.length">
							<p class="kdna-ei-muted" x-text="sprintf( i18nCosts.importDoneErrors, csv.saveErrors.length )"></p>
						</template>
					</div>
				</template>
			</div>

			<div class="kdna-ei-modal__footer">
				<template x-if="csv.step === 'preview'">
					<p class="kdna-ei-muted" x-text="csv.result.counts.error ? sprintf( csv.result.counts.error === 1 ? i18nCosts.problemsNoteOne : i18nCosts.problemsNote, csv.result.counts.error ) : ''"></p>
				</template>
				<div class="kdna-ei-modal__actions">
					<button type="button" class="kdna-ei-btn" x-show="csv.step === 'preview'" @click="csv.step = 'choose'; csv.result = null" :disabled="csv.busy"><?php esc_html_e( 'Choose another file', 'kdna-ecommerce-insights' ); ?></button>
					<button type="button" class="kdna-ei-btn" x-show="csv.step !== 'preview'" @click="closeCsv()" :disabled="csv.busy" x-text="csv.step === 'done' ? i18nCosts.close : i18nCosts.cancel"></button>
					<button
						type="button"
						class="kdna-ei-btn kdna-ei-btn--primary"
						x-show="csv.step === 'preview'"
						:disabled="csv.busy || ! csv.result || ! csv.result.counts.change"
						@click="applyCsv()"
						x-text="csv.busy ? sprintf( i18nCosts.saving, saveDone, saveTotal ) : sprintf( csv.result && csv.result.counts.change === 1 ? i18nCosts.applyOne : i18nCosts.apply, csv.result ? csv.result.counts.change : 0 )"
					></button>
				</div>
			</div>
		</div>
	</div>

	<?php // Import from another cost plugin window. ?>
	<div class="kdna-ei-modal" x-show="plugins.open" x-cloak x-transition.opacity @keydown.escape.stop="closePlugins()">
		<div class="kdna-ei-modal__backdrop" @click="closePlugins()"></div>
		<div class="kdna-ei-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-plugins-title" tabindex="-1" x-ref="pluginsDialog">
			<div class="kdna-ei-modal__header">
				<h2 id="kdna-ei-plugins-title" class="kdna-ei-card__title"><?php esc_html_e( 'Import from another cost plugin', 'kdna-ecommerce-insights' ); ?></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closePlugins()" :disabled="plugins.busy" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>

			<div class="kdna-ei-modal__body">
				<p class="kdna-ei-muted"><?php esc_html_e( 'Moving from another cost plugin? Insights can copy the costs it saved, so nothing needs typing in again. The other plugin does not need to be active.', 'kdna-ecommerce-insights' ); ?></p>

				<template x-if="plugins.error">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="plugins.error"></p></div>
				</template>

				<fieldset class="kdna-ei-choice-list" :disabled="plugins.busy">
					<legend class="kdna-ei-visually-hidden"><?php esc_html_e( 'Cost plugin', 'kdna-ecommerce-insights' ); ?></legend>
					<template x-for="source in plugins.sources" :key="source.key">
						<label class="kdna-ei-choice" :class="{ 'is-selected': plugins.source === source.key, 'is-disabled': ! source.count }">
							<input type="radio" name="kdna-ei-plugin-source" :value="source.key" x-model="plugins.source" :disabled="! source.count" />
							<span class="kdna-ei-choice__text">
								<span class="kdna-ei-choice__label" x-text="source.label"></span>
								<span class="kdna-ei-muted" x-text="source.count ? sprintf( source.count === 1 ? i18nCosts.oneFound : i18nCosts.found, source.count ) : i18nCosts.noneFound"></span>
							</span>
						</label>
					</template>
				</fieldset>

				<label class="kdna-ei-switch" x-show="plugins.sources.length">
					<input type="checkbox" x-model="plugins.overwrite" :disabled="plugins.busy" />
					<span class="kdna-ei-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Replace costs already entered in Insights', 'kdna-ecommerce-insights' ); ?></span>
				</label>

				<template x-if="plugins.busy && plugins.total">
					<div class="kdna-ei-progress" role="progressbar" :aria-valuenow="pluginPercent" aria-valuemin="0" aria-valuemax="100">
						<span class="kdna-ei-progress__bar" :style="'width:' + pluginPercent + '%'"></span>
					</div>
				</template>

				<template x-if="plugins.done">
					<div class="kdna-ei-notice kdna-ei-notice--positive" role="status">
						<p x-text="sprintf( i18nCosts.pluginDone, plugins.imported, plugins.skipped )"></p>
					</div>
				</template>
			</div>

			<div class="kdna-ei-modal__footer">
				<span></span>
				<div class="kdna-ei-modal__actions">
					<button type="button" class="kdna-ei-btn" @click="closePlugins()" :disabled="plugins.busy" x-text="plugins.done ? i18nCosts.close : i18nCosts.cancel"></button>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" x-show="! plugins.done" @click="runPluginImport()" :disabled="plugins.busy || ! plugins.source" x-text="plugins.busy && plugins.total ? sprintf( i18nCosts.importing, pluginPercent ) : i18nCosts.importCosts"></button>
				</div>
			</div>
		</div>
	</div>
</div>
