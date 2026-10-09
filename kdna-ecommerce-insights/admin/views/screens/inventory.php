<?php
/**
 * Inventory screen (section 8): KPI strip, stock status donut, stock value
 * trend from the nightly snapshots, reorder list with days of stock left,
 * low stock, out of stock and dead stock tables, and stock settings with
 * the optional low stock emails. Behaviour lives in admin/js/screens/inventory.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;

/**
 * Prints one inventory list table: a card with a title, note, CSV button,
 * the table (first ten rows, with Show all) and an empty message.
 *
 * @param string $key     List key in the report, for example 'low_stock'.
 * @param string $title   Card title.
 * @param string $note    Alpine expression for the note under the title.
 * @param array  $columns Each: label, cell (Alpine expression), numeric (bool), html (bool, cell is a template).
 * @param string $empty   Message when the list is empty.
 */
$kdna_ei_stock_table = static function ( string $key, string $title, string $note, array $columns, string $empty ): void {
	?>
	<section class="kdna-ei-card kdna-ei-stock-list kdna-ei-area-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>" aria-labelledby="kdna-ei-<?php echo esc_attr( $key ); ?>-title">
		<div class="kdna-ei-card__header">
			<div>
				<h2 id="kdna-ei-<?php echo esc_attr( $key ); ?>-title" class="kdna-ei-card__title">
					<?php echo esc_html( $title ); ?>
					<span class="kdna-ei-card__count kdna-ei-num" x-show="loaded" x-text="formatNumber( report.counts.<?php echo esc_attr( $key ); ?> )"></span>
				</h2>
				<p class="kdna-ei-card__subtitle" x-text="<?php echo esc_attr( $note ); ?>"></p>
			</div>
			<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="exportCsv( '<?php echo esc_js( $key ); ?>' )" :disabled="exporting || ! loaded" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: table name. */ __( 'Export %s as CSV', 'kdna-ecommerce-insights' ), $title ) ); ?>" title="<?php esc_attr_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?>">
				<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
			</button>
		</div>
		<div class="kdna-ei-table-wrap">
			<table class="kdna-ei-table kdna-ei-table--hover kdna-ei-table--compact">
				<caption class="kdna-ei-visually-hidden"><?php echo esc_html( $title ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Product', 'kdna-ecommerce-insights' ); ?></th>
						<?php foreach ( $columns as $column ) : ?>
							<th scope="col" class="<?php echo ! empty( $column['numeric'] ) ? 'is-numeric' : ''; ?>"><?php echo esc_html( $column['label'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody x-show="! loaded">
					<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 4; $kdna_ei_i++ ) : ?>
						<tr aria-hidden="true">
							<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 70%;"></span></td>
							<?php foreach ( $columns as $column ) : ?>
								<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
							<?php endforeach; ?>
						</tr>
					<?php endfor; ?>
				</tbody>
				<tbody x-show="loaded" x-cloak>
					<template x-for="row in rowsOf( '<?php echo esc_js( $key ); ?>' )" :key="row.id">
						<tr>
							<td>
								<div class="kdna-ei-product">
									<span class="kdna-ei-thumb kdna-ei-thumb--sm">
										<template x-if="row.thumbnail"><img :src="row.thumbnail" alt="" loading="lazy" /></template>
										<template x-if="! row.thumbnail"><svg class="kdna-ei-icon kdna-ei-icon--sm" aria-hidden="true"><use href="#kdna-ei-icon-box"></use></svg></template>
									</span>
									<div class="kdna-ei-product__text">
										<a class="kdna-ei-product__name" :href="row.edit_url" target="_blank" rel="noopener" x-text="row.name"></a>
										<span class="kdna-ei-product__meta" x-show="row.sku" x-text="row.sku"></span>
									</div>
								</div>
							</td>
							<?php foreach ( $columns as $column ) : ?>
								<?php if ( ! empty( $column['html'] ) ) : ?>
									<td class="<?php echo ! empty( $column['numeric'] ) ? 'is-numeric' : ''; ?>"><?php echo $column['cell']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed template markup from this file. ?></td>
								<?php else : ?>
									<td class="<?php echo ! empty( $column['numeric'] ) ? 'is-numeric kdna-ei-num' : ''; ?>" x-text="<?php echo esc_attr( $column['cell'] ); ?>"></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					</template>
				</tbody>
			</table>
			<template x-if="loaded && ! report.<?php echo esc_attr( $key ); ?>.length">
				<div class="kdna-ei-empty"><p class="kdna-ei-empty__title"><?php echo esc_html( $empty ); ?></p></div>
			</template>
		</div>
		<template x-if="loaded && report.<?php echo esc_attr( $key ); ?>.length > 8">
			<div class="kdna-ei-card__footer">
				<button type="button" class="kdna-ei-link" @click="toggleList( '<?php echo esc_js( $key ); ?>' )" x-text="expanded.<?php echo esc_attr( $key ); ?> ? t.showFewer : sprintf( t.showAll, formatNumber( report.<?php echo esc_attr( $key ); ?>.length ) )"></button>
			</div>
		</template>
	</section>
	<?php
};
?>
<div class="kdna-ei-inventory" x-data="kdnaEiInventory" x-effect="if ( route === 'inventory' ) ensureLoaded()" :aria-busy="loading ? 'true' : 'false'">

	<template x-if="loadError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
			<p x-text="loadError"></p>
			<button type="button" class="kdna-ei-btn" @click="load( true )"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</template>

	<div class="kdna-ei-inventory__bar">
		<p class="kdna-ei-muted" x-text="t.stockIsNow"></p>
		<button type="button" class="kdna-ei-btn" @click="settingsOpen = ! settingsOpen" :aria-expanded="settingsOpen ? 'true' : 'false'" aria-controls="kdna-ei-stock-settings">
			<?php Admin::icon( 'cog', 'kdna-ei-icon--sm' ); ?>
			<span><?php esc_html_e( 'Stock settings and alerts', 'kdna-ecommerce-insights' ); ?></span>
		</button>
	</div>

	<?php // Stock settings and low stock emails. ?>
	<section id="kdna-ei-stock-settings" class="kdna-ei-card kdna-ei-stock-settings" x-show="settingsOpen" x-cloak x-transition.opacity.duration.150ms aria-labelledby="kdna-ei-stock-settings-title">
		<div class="kdna-ei-card__header">
			<div>
				<h2 id="kdna-ei-stock-settings-title" class="kdna-ei-card__title"><?php esc_html_e( 'Stock settings and alerts', 'kdna-ecommerce-insights' ); ?></h2>
				<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'These are also under Settings > Alerts and digests.', 'kdna-ecommerce-insights' ); ?></p>
			</div>
		</div>
		<div class="kdna-ei-stock-settings__grid">
			<div>
				<label for="kdna-ei-low-threshold" class="kdna-ei-field-label"><?php esc_html_e( 'Low stock at or below', 'kdna-ecommerce-insights' ); ?></label>
				<input id="kdna-ei-low-threshold" type="text" inputmode="numeric" class="kdna-ei-input" x-model="form.low_stock_threshold" :placeholder="sprintf( t.useStore, formatNumber( report.store_threshold ) )" :class="{ 'is-invalid': errors.low_stock_threshold }" />
				<p class="kdna-ei-help" x-show="! errors.low_stock_threshold" x-text="t.thresholdHelp"></p>
				<p class="kdna-ei-field-error" x-show="errors.low_stock_threshold" x-text="errors.low_stock_threshold"></p>
			</div>
			<div>
				<label for="kdna-ei-dead-days" class="kdna-ei-field-label"><?php esc_html_e( 'Dead stock after (days without a sale)', 'kdna-ecommerce-insights' ); ?></label>
				<input id="kdna-ei-dead-days" type="text" inputmode="numeric" class="kdna-ei-input" x-model="form.dead_stock_days" :class="{ 'is-invalid': errors.dead_stock_days }" />
				<p class="kdna-ei-field-error" x-show="errors.dead_stock_days" x-text="errors.dead_stock_days"></p>
			</div>
			<div>
				<label for="kdna-ei-lead-days" class="kdna-ei-field-label"><?php esc_html_e( 'Supplier lead time (days)', 'kdna-ecommerce-insights' ); ?></label>
				<input id="kdna-ei-lead-days" type="text" inputmode="numeric" class="kdna-ei-input" x-model="form.reorder_lead_days" :class="{ 'is-invalid': errors.reorder_lead_days }" />
				<p class="kdna-ei-help" x-show="! errors.reorder_lead_days"><?php esc_html_e( 'How long a reorder takes to arrive. Reorder dates are this many days before stock runs out.', 'kdna-ecommerce-insights' ); ?></p>
				<p class="kdna-ei-field-error" x-show="errors.reorder_lead_days" x-text="errors.reorder_lead_days"></p>
			</div>
		</div>

		<div class="kdna-ei-stock-settings__alerts">
			<label class="kdna-ei-switch">
				<input type="checkbox" x-model="form.low_stock_emails" />
				<span class="kdna-ei-switch__track" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Email me when products become low or out of stock', 'kdna-ecommerce-insights' ); ?></span>
			</label>
			<p class="kdna-ei-help"><?php esc_html_e( 'One email bundles everything that ran low in a ten minute window, and each product is only mentioned again after it has been restocked.', 'kdna-ecommerce-insights' ); ?></p>
			<div class="kdna-ei-stock-settings__recipients" x-show="form.low_stock_emails" x-cloak>
				<label for="kdna-ei-alert-recipients" class="kdna-ei-field-label"><?php esc_html_e( 'Send to', 'kdna-ecommerce-insights' ); ?></label>
				<input id="kdna-ei-alert-recipients" type="text" class="kdna-ei-input" x-model="form.alert_recipients" placeholder="you@example.com, team@example.com" :class="{ 'is-invalid': errors.alert_recipients }" />
				<p class="kdna-ei-help" x-show="! errors.alert_recipients"><?php esc_html_e( 'Separate more than one address with commas.', 'kdna-ecommerce-insights' ); ?></p>
				<p class="kdna-ei-field-error" x-show="errors.alert_recipients" x-text="errors.alert_recipients"></p>
			</div>
		</div>

		<div class="kdna-ei-card__footer">
			<p class="kdna-ei-muted" role="status" x-text="settingsMessage"></p>
			<div class="kdna-ei-toolbar__actions">
				<button type="button" class="kdna-ei-btn" @click="sendTest()" :disabled="testing"><?php esc_html_e( 'Send a test email', 'kdna-ecommerce-insights' ); ?></button>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="saveSettings()" :disabled="saving"><?php esc_html_e( 'Save', 'kdna-ecommerce-insights' ); ?></button>
			</div>
		</div>
	</section>

	<div class="kdna-ei-inventory__grid">

		<?php // KPI strip. ?>
		<section class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" aria-label="<?php esc_attr_e( 'Stock figures', 'kdna-ecommerce-insights' ); ?>">
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
						<svg class="kdna-ei-icon"><use :href="'#kdna-ei-icon-' + kpi.icon"></use></svg>
					</span>
					<div class="kdna-ei-kpi__body">
						<div class="kdna-ei-kpi__label-row">
							<span class="kdna-ei-kpi__label" x-text="kpi.label" :title="kpi.help"></span>
							<template x-if="kpi.key === 'stock_value_cost' && report.no_cost > 0">
								<span class="kdna-ei-estimate" tabindex="0" :title="sprintf( t.noCostHelp, formatNumber( report.no_cost ) )">
									<svg class="kdna-ei-icon" aria-hidden="true"><use href="#kdna-ei-icon-info"></use></svg>
									<span class="kdna-ei-visually-hidden" x-text="sprintf( t.noCostHelp, formatNumber( report.no_cost ) )"></span>
								</span>
							</template>
						</div>
						<div class="kdna-ei-kpi__row">
							<span class="kdna-ei-kpi__value" :class="kpi.tone" x-text="metric( kpi.value, kpi.format, kpi.decimals )"></span>
						</div>
					</div>
				</div>
			</template>
		</section>

		<?php // Stock status donut. ?>
		<section class="kdna-ei-card kdna-ei-area-status" aria-labelledby="kdna-ei-stock-status-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-stock-status-title" class="kdna-ei-card__title"><?php esc_html_e( 'Stock status', 'kdna-ecommerce-insights' ); ?></h2>
			</div>
			<div class="kdna-ei-cost-donut">
				<div class="kdna-ei-donut" x-show="loaded">
					<canvas x-ref="status" role="img" :aria-label="statusSummary"></canvas>
					<div class="kdna-ei-donut__centre" aria-hidden="true">
						<span class="kdna-ei-donut__number kdna-ei-num" x-text="formatNumber( report.status ? report.status.in_stock : 0 )"></span>
						<span class="kdna-ei-donut__label"><?php esc_html_e( 'in stock', 'kdna-ecommerce-insights' ); ?></span>
					</div>
				</div>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--circle kdna-ei-donut" x-show="! loaded" aria-hidden="true"></span>
				<table class="kdna-ei-table kdna-ei-legend kdna-ei-legend--compact">
					<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Stock status', 'kdna-ecommerce-insights' ); ?></caption>
					<tbody>
						<template x-for="row in statusRows" :key="row.key">
							<tr>
								<th scope="row"><span class="kdna-ei-legend__dot" :style="'background: var(--kdna-ei-' + row.token + ')'"></span><span x-text="row.label"></span></th>
								<td class="is-numeric kdna-ei-legend__value" x-text="loaded ? formatNumber( row.value ) : ''"></td>
								<td class="is-numeric kdna-ei-legend__percent" x-text="loaded ? row.percent : ''"></td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</section>

		<?php // Stock value trend. ?>
		<section class="kdna-ei-card kdna-ei-area-trend" aria-labelledby="kdna-ei-stock-trend-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-stock-trend-title" class="kdna-ei-card__title"><?php esc_html_e( 'Stock value', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle" x-text="t.trendNote"></p>
				</div>
				<div class="kdna-ei-chart-legend" aria-hidden="true">
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><?php esc_html_e( 'At cost', 'kdna-ecommerce-insights' ); ?></span>
					<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--compare"></span><?php esc_html_e( 'At retail', 'kdna-ecommerce-insights' ); ?></span>
				</div>
			</div>
			<div class="kdna-ei-chart">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" x-show="! loaded" aria-hidden="true"></span>
				<canvas x-ref="trend" x-show="loaded && report.trend.length > 1" role="img" :aria-label="trendSummary"></canvas>
				<div class="kdna-ei-chart__empty" x-show="loaded && report.trend.length <= 1" x-cloak>
					<?php Admin::icon( 'calendar' ); ?>
					<p x-text="t.trendEmpty"></p>
				</div>
			</div>
		</section>

		<?php
		// Reorder list: days of stock left and reorder dates.
		$kdna_ei_stock_table(
			'days_of_cover',
			__( 'Reorder planner', 'kdna-ecommerce-insights' ),
			'sprintf( t.coverNote, formatNumber( report.lead_days ) )',
			array(
				array( 'label' => __( 'In stock', 'kdna-ecommerce-insights' ), 'cell' => 'formatNumber( row.stock )', 'numeric' => true ),
				array( 'label' => __( 'Sells per day', 'kdna-ecommerce-insights' ), 'cell' => 'perDay( row.per_day )', 'numeric' => true ),
				array( 'label' => __( 'Days left', 'kdna-ecommerce-insights' ), 'cell' => '<span class="kdna-ei-days kdna-ei-num" :class="daysClass( row )" x-text="formatNumber( row.days )"></span>', 'numeric' => true, 'html' => true ),
				array( 'label' => __( 'Runs out', 'kdna-ecommerce-insights' ), 'cell' => 'dateLabel( row.runs_out )' ),
				array( 'label' => __( 'Reorder by', 'kdna-ecommerce-insights' ), 'cell' => '<template x-if="row.reorder_now"><span class="kdna-ei-badge kdna-ei-badge--warning" x-text="t.reorderNow"></span></template><template x-if="! row.reorder_now"><span x-text="dateLabel( row.reorder )"></span></template>', 'html' => true ),
			),
			__( 'Nothing with stock tracking sold in the last 30 days.', 'kdna-ecommerce-insights' )
		);

		$kdna_ei_stock_table(
			'low_stock',
			__( 'Low stock', 'kdna-ecommerce-insights' ),
			'thresholdNote',
			array(
				array( 'label' => __( 'In stock', 'kdna-ecommerce-insights' ), 'cell' => '<span class="kdna-ei-num is-warning-text" x-text="formatNumber( row.stock )"></span>', 'numeric' => true, 'html' => true ),
				array( 'label' => __( 'Sold in 30 days', 'kdna-ecommerce-insights' ), 'cell' => 'formatNumber( row.sold_30 )', 'numeric' => true ),
				array( 'label' => __( 'Days left', 'kdna-ecommerce-insights' ), 'cell' => 'row.days === null ? t.noRecentSales : formatNumber( row.days )', 'numeric' => true ),
			),
			__( 'Nothing is low on stock.', 'kdna-ecommerce-insights' )
		);

		$kdna_ei_stock_table(
			'out_of_stock',
			__( 'Out of stock', 'kdna-ecommerce-insights' ),
			't.outNote',
			array(
				array( 'label' => __( 'Sold in 30 days', 'kdna-ecommerce-insights' ), 'cell' => 'formatNumber( row.sold_30 )', 'numeric' => true ),
				array( 'label' => __( 'Last sale', 'kdna-ecommerce-insights' ), 'cell' => 'row.last_sale ? dateLabel( row.last_sale ) : t.never' ),
			),
			__( 'Nothing is out of stock.', 'kdna-ecommerce-insights' )
		);

		$kdna_ei_stock_table(
			'dead_stock',
			__( 'Dead stock', 'kdna-ecommerce-insights' ),
			'deadNote',
			array(
				array( 'label' => __( 'In stock', 'kdna-ecommerce-insights' ), 'cell' => 'row.stock === null ? t.notTracked : formatNumber( row.stock )', 'numeric' => true ),
				array( 'label' => __( 'Value at cost', 'kdna-ecommerce-insights' ), 'cell' => 'row.value_cost === null ? \'–\' : money( row.value_cost, 0 )', 'numeric' => true ),
				array( 'label' => __( 'Last sale', 'kdna-ecommerce-insights' ), 'cell' => 'row.last_sale ? dateLabel( row.last_sale ) : t.never' ),
			),
			__( 'Everything in stock has sold recently. Nice.', 'kdna-ecommerce-insights' )
		);
		?>
	</div>
</div>
