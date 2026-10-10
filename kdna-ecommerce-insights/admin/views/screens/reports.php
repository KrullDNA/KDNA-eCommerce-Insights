<?php
/**
 * Tax & Reports screen (section 8): tax summary with the BAS-style view,
 * printable report, weekly and monthly email digests, and every CSV export
 * in one place. Behaviour lives in admin/js/screens/reports.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;
?>
<div class="kdna-ei-reports" x-data="kdnaEiReports" x-effect="if ( route === 'reports' ) ensureLoaded()" :aria-busy="taxLoading ? 'true' : 'false'">

	<div class="kdna-ei-reports__grid">

		<?php // ---------------------------------------------------------- Tax summary. ?>
		<section class="kdna-ei-card kdna-ei-tax kdna-ei-area-tax" aria-labelledby="kdna-ei-tax-title">
			<div class="kdna-ei-card__header kdna-ei-tax__header">
				<div>
					<h2 id="kdna-ei-tax-title" class="kdna-ei-card__title" x-text="taxTitle"><?php esc_html_e( 'Tax summary', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle">
						<span x-text="taxRangeText"></span>
						<template x-if="tax && taxOn">
							<span> &middot; <span x-text="systems[ taxSystem ]"></span><span x-show="tax.rate > 0" x-text="', ' + tax.rate + '%'"></span></span>
						</template>
					</p>
				</div>
				<div class="kdna-ei-tax__controls" x-show="taxOn">
					<label class="kdna-ei-visually-hidden" for="kdna-ei-tax-span"><?php esc_html_e( 'Dates covered', 'kdna-ecommerce-insights' ); ?></label>
					<select id="kdna-ei-tax-span" class="kdna-ei-select kdna-ei-select--small" :value="taxSpan" @change="setTaxSpan( $event.target.value )">
						<template x-for="option in spanOptions" :key="option.key">
							<option :value="option.key" x-text="option.label" :selected="option.key === taxSpan"></option>
						</template>
					</select>
					<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'Group by', 'kdna-ecommerce-insights' ); ?>">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="taxPeriod === 'monthly' ? 'true' : 'false'" @click="setTaxPeriod( 'monthly' )"><?php esc_html_e( 'Monthly', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="taxPeriod === 'quarterly' ? 'true' : 'false'" @click="setTaxPeriod( 'quarterly' )"><?php esc_html_e( 'Quarterly', 'kdna-ecommerce-insights' ); ?></button>
					</div>
					<button type="button" class="kdna-ei-btn" @click="exportTax()" :disabled="exportBusy === 'tax' || ! taxLoaded">
						<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
						<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
					</button>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" x-ref="taxSettingsButton" @click="openTaxSettings()" aria-label="<?php esc_attr_e( 'Tax settings', 'kdna-ecommerce-insights' ); ?>" title="<?php esc_attr_e( 'Tax settings', 'kdna-ecommerce-insights' ); ?>">
						<?php Admin::icon( 'cog' ); ?>
					</button>
				</div>
			</div>

			<template x-if="taxError">
				<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
					<p x-text="taxError"></p>
					<button type="button" class="kdna-ei-btn" @click="loadTax( true )"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
				</div>
			</template>

			<?php // Tax reporting switched off. ?>
			<div class="kdna-ei-empty kdna-ei-tax__off" x-show="! taxOn" x-cloak>
				<p class="kdna-ei-empty__title"><?php esc_html_e( 'Tax reporting is switched off', 'kdna-ecommerce-insights' ); ?></p>
				<p class="kdna-ei-muted"><?php esc_html_e( 'Choose the tax your store charges, such as Australian GST, to see what you collected and an estimate of what you can claim back, by month or quarter.', 'kdna-ecommerce-insights' ); ?></p>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="openTaxSettings()"><?php esc_html_e( 'Choose a tax system', 'kdna-ecommerce-insights' ); ?></button>
			</div>

			<div x-show="taxOn">
				<?php // Guide, not a lodgement. ?>
				<div class="kdna-ei-notice kdna-ei-notice--info kdna-ei-tax__guide" role="note">
					<svg class="kdna-ei-icon kdna-ei-icon--sm" aria-hidden="true"><use href="#kdna-ei-icon-info"></use></svg>
					<p>
						<strong><?php esc_html_e( 'A guide for your bookkeeper, not a lodgement.', 'kdna-ecommerce-insights' ); ?></strong>
						<span x-text="tax ? tax.note.replace( /^[^.]*\.\s*/, '' ) : ''"></span>
					</p>
				</div>

				<?php // Headline tiles. ?>
				<div class="kdna-ei-tax__tiles" :class="{ 'kdna-ei-tax__tiles--three': tax && ! claimsCosts }">
					<template x-for="n in ( taxLoaded ? 0 : 4 )" :key="'skeleton' + n">
						<div class="kdna-ei-tax__tile" aria-hidden="true">
							<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
							<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
						</div>
					</template>
					<template x-for="tile in ( taxLoaded ? taxTiles : [] )" :key="tile.key">
						<div class="kdna-ei-tax__tile" :class="{ 'kdna-ei-tax__tile--net': tile.key === 'net' }">
							<p class="kdna-ei-tax__tile-label">
								<template x-if="tile.short"><span class="kdna-ei-tax__code" x-text="tile.short"></span></template>
								<span x-text="tile.short ? tile.label.replace( tile.short + ' ', '' ) : tile.label"></span>
							</p>
							<p class="kdna-ei-tax__tile-value kdna-ei-num" x-text="money( tile.value )"></p>
							<template x-if="tile.key === 'net'">
								<span class="kdna-ei-badge" :class="{ 'kdna-ei-badge--warning': tile.position === 'payable', 'kdna-ei-badge--positive': tile.position === 'refundable' }" x-text="tile.note"></span>
							</template>
							<template x-if="tile.key !== 'net'">
								<p class="kdna-ei-help" x-text="tile.note"></p>
							</template>
						</div>
					</template>
				</div>

				<?php // By month or quarter. ?>
				<div class="kdna-ei-table-wrap" tabindex="0">
					<table class="kdna-ei-table kdna-ei-table--hover kdna-ei-tax__table" x-show="taxLoaded" x-cloak>
						<caption class="kdna-ei-visually-hidden" x-text="taxTitle"></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Period', 'kdna-ecommerce-insights' ); ?></th>
								<th scope="col" class="is-numeric" x-text="tax ? tax.labels.sales : ''"></th>
								<th scope="col" class="is-numeric" x-text="tax ? tax.labels.on_sales : ''"></th>
								<th scope="col" class="is-numeric" x-show="claimsCosts" x-text="tax ? tax.labels.on_costs : ''"></th>
								<th scope="col" class="is-numeric" x-text="tax ? tax.labels.net : ''"></th>
							</tr>
						</thead>
						<tbody>
							<template x-for="row in ( tax ? tax.periods : [] )" :key="row.start">
								<tr>
									<th scope="row">
										<span x-text="row.period"></span>
										<template x-if="periodBadge( row )">
											<span class="kdna-ei-badge kdna-ei-tax__badge" tabindex="0" :title="periodBadgeHelp( row )" x-text="periodBadge( row )"></span>
										</template>
										<span class="kdna-ei-visually-hidden" x-text="periodBadgeHelp( row )"></span>
									</th>
									<td class="is-numeric kdna-ei-num" x-text="money( row.sales )"></td>
									<td class="is-numeric kdna-ei-num" x-text="money( row.on_sales )"></td>
									<td class="is-numeric kdna-ei-num" x-show="claimsCosts" x-text="money( row.on_costs )"></td>
									<td class="is-numeric kdna-ei-num" :class="{ 'kdna-ei-text-positive': row.net < 0 }" x-text="money( row.net )"></td>
								</tr>
							</template>
						</tbody>
						<tfoot x-show="tax && tax.periods.length > 1">
							<tr>
								<th scope="row"><?php esc_html_e( 'Total', 'kdna-ecommerce-insights' ); ?></th>
								<td class="is-numeric kdna-ei-num" x-text="tax ? money( tax.totals.sales ) : ''"></td>
								<td class="is-numeric kdna-ei-num" x-text="tax ? money( tax.totals.on_sales ) : ''"></td>
								<td class="is-numeric kdna-ei-num" x-show="claimsCosts" x-text="tax ? money( tax.totals.on_costs ) : ''"></td>
								<td class="is-numeric kdna-ei-num" x-text="tax ? money( tax.totals.net ) : ''"></td>
							</tr>
						</tfoot>
					</table>
				</div>
				<p class="kdna-ei-help kdna-ei-tax__basis">
					<?php esc_html_e( 'Orders count on the date set in Settings, General (paid date unless you changed it), using the order statuses chosen there. If your bookkeeper reports on a cash basis, the paid date matches best.', 'kdna-ecommerce-insights' ); ?>
				</p>
			</div>
		</section>

		<?php // ---------------------------------------------------------- Printable report. ?>
		<section class="kdna-ei-card kdna-ei-print-card kdna-ei-area-print" aria-labelledby="kdna-ei-print-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-print-title" class="kdna-ei-card__title"><?php esc_html_e( 'Printable report', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Overview, Profit & Loss and top products on clean A4 pages, ready to save as a PDF and share.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>
			<div class="kdna-ei-print-card__preview" aria-hidden="true">
				<span class="kdna-ei-print-card__sheet"><span></span><span></span><span></span><span></span></span>
				<span class="kdna-ei-print-card__sheet"><span></span><span></span><span></span></span>
			</div>
			<p class="kdna-ei-print-card__range">
				<?php esc_html_e( 'For', 'kdna-ecommerce-insights' ); ?> <strong x-text="rangeLabel"></strong>
			</p>
			<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="openPrint()">
				<?php Admin::icon( 'document', 'kdna-ei-icon--sm' ); ?>
				<span><?php esc_html_e( 'Open printable report', 'kdna-ecommerce-insights' ); ?></span>
			</button>
			<p class="kdna-ei-help"><?php esc_html_e( 'It opens in a new tab with the print window ready. Choose "Save as PDF" as the destination. Change the dates with the date range at the top of the page.', 'kdna-ecommerce-insights' ); ?></p>
		</section>

		<?php // ---------------------------------------------------------- Email digest. ?>
		<section class="kdna-ei-card kdna-ei-digest kdna-ei-area-digest" aria-labelledby="kdna-ei-digest-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-digest-title" class="kdna-ei-card__title"><?php esc_html_e( 'Email digest', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'A branded summary in your inbox: key figures, top products and anything that needs attention.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</div>

			<template x-if="digestNotice">
				<div class="kdna-ei-notice" :class="'kdna-ei-notice--' + digestNotice.type" role="status">
					<p x-text="digestNotice.text"></p>
					<button type="button" class="kdna-ei-notice__close" @click="digestNotice = null" aria-label="<?php esc_attr_e( 'Dismiss', 'kdna-ecommerce-insights' ); ?>">&times;</button>
				</div>
			</template>

			<template x-if="! digest">
				<div class="kdna-ei-skel-stack" aria-hidden="true">
					<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
					<span class="kdna-ei-skeleton kdna-ei-skeleton--block" style="height: 44px;"></span>
					<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 70%;"></span>
				</div>
			</template>

			<form class="kdna-ei-digest__form" x-show="digest" x-cloak @submit.prevent="saveDigest()" novalidate>
				<fieldset class="kdna-ei-digest__field">
					<legend class="kdna-ei-field-label"><?php esc_html_e( 'How often', 'kdna-ecommerce-insights' ); ?></legend>
					<div class="kdna-ei-segmented" role="group">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="digestForm.frequency === 'off' ? 'true' : 'false'" @click="digestForm.frequency = 'off'"><?php esc_html_e( 'Off', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="digestForm.frequency === 'weekly' ? 'true' : 'false'" @click="digestForm.frequency = 'weekly'"><?php esc_html_e( 'Weekly', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="digestForm.frequency === 'monthly' ? 'true' : 'false'" @click="digestForm.frequency = 'monthly'"><?php esc_html_e( 'Monthly', 'kdna-ecommerce-insights' ); ?></button>
					</div>
					<p class="kdna-ei-help" x-show="digestForm.frequency === 'weekly'"><?php esc_html_e( 'Arrives about 7am on the first day of your week, covering the seven days before.', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help" x-show="digestForm.frequency === 'monthly'"><?php esc_html_e( 'Arrives about 7am on the 1st, covering last month.', 'kdna-ecommerce-insights' ); ?></p>
				</fieldset>

				<div class="kdna-ei-digest__field">
					<label for="kdna-ei-digest-recipients" class="kdna-ei-field-label"><?php esc_html_e( 'Send to', 'kdna-ecommerce-insights' ); ?></label>
					<input id="kdna-ei-digest-recipients" type="text" class="kdna-ei-input" x-model="digestForm.recipients" @input="delete digestErrors.recipients" placeholder="you@example.com, team@example.com" :class="{ 'is-invalid': digestErrors.recipients }" :aria-invalid="digestErrors.recipients ? 'true' : 'false'" aria-describedby="kdna-ei-digest-recipients-help" autocomplete="off" spellcheck="false" />
					<p id="kdna-ei-digest-recipients-help" class="kdna-ei-help" x-show="! digestErrors.recipients"><?php esc_html_e( 'Separate more than one address with commas. Your bookkeeper or business partner can get it too.', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-field-error" x-show="digestErrors.recipients" x-text="digestErrors.recipients"></p>
				</div>

				<fieldset class="kdna-ei-digest__field">
					<legend class="kdna-ei-field-label"><?php esc_html_e( 'Include', 'kdna-ecommerce-insights' ); ?></legend>
					<div class="kdna-ei-digest__sections">
						<template x-for="option in sectionOptions" :key="option.key">
							<label class="kdna-ei-check">
								<input type="checkbox" :checked="digestForm.sections.indexOf( option.key ) !== -1" @change="toggleSection( option.key )" />
								<span x-text="option.label"></span>
							</label>
						</template>
					</div>
					<p class="kdna-ei-field-error" x-show="digestErrors.sections" x-text="digestErrors.sections"></p>
				</fieldset>

				<p class="kdna-ei-digest__status">
					<span x-text="nextSendText"></span>
					<span class="kdna-ei-muted" x-text="lastSendText"></span>
				</p>

				<div class="kdna-ei-digest__actions">
					<button type="submit" class="kdna-ei-btn kdna-ei-btn--primary" :disabled="digestBusy !== '' || ! digestDirty" x-text="digestBusy === 'save' ? '<?php echo esc_js( __( 'Saving', 'kdna-ecommerce-insights' ) ); ?>' : '<?php echo esc_js( __( 'Save', 'kdna-ecommerce-insights' ) ); ?>'"></button>
					<button type="button" class="kdna-ei-btn" x-ref="previewButton" @click="openPreview()" :disabled="! digestForm.sections.length">
						<?php Admin::icon( 'eye', 'kdna-ei-icon--sm' ); ?>
						<span><?php esc_html_e( 'Preview', 'kdna-ecommerce-insights' ); ?></span>
					</button>
					<button type="button" class="kdna-ei-btn" @click="sendTest()" :disabled="digestBusy !== '' || ! digestForm.sections.length" x-text="digestBusy === 'test' ? '<?php echo esc_js( __( 'Sending', 'kdna-ecommerce-insights' ) ); ?>' : '<?php echo esc_js( __( 'Send test', 'kdna-ecommerce-insights' ) ); ?>'"></button>
				</div>
				<p class="kdna-ei-help" x-show="digestForm.frequency === 'off'"><?php esc_html_e( 'Preview and Send test show the weekly version while digests are off.', 'kdna-ecommerce-insights' ); ?></p>
			</form>
		</section>

		<?php // ---------------------------------------------------------- Every export. ?>
		<section class="kdna-ei-card kdna-ei-exports kdna-ei-area-exports" aria-labelledby="kdna-ei-exports-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-exports-title" class="kdna-ei-card__title"><?php esc_html_e( 'Every export', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle">
						<?php esc_html_e( 'Every table in Insights as a CSV file for Excel, Numbers or Google Sheets. Report tables use the date range at the top of the page:', 'kdna-ecommerce-insights' ); ?>
						<strong x-text="rangeLabel"></strong>.
					</p>
				</div>
			</div>
			<p class="kdna-ei-field-error" role="alert" x-show="exportError" x-text="exportError"></p>
			<div class="kdna-ei-exports__groups">
				<template x-for="group in exportList" :key="group.screen">
					<div class="kdna-ei-exports__group">
						<h3 class="kdna-ei-exports__heading" x-text="group.label"></h3>
						<ul class="kdna-ei-exports__list">
							<template x-for="table in group.tables" :key="group.screen + table.key">
								<li>
									<button type="button" class="kdna-ei-exports__item" @click="exportOne( table.key )" :disabled="exportBusy !== ''">
										<svg class="kdna-ei-icon kdna-ei-icon--sm" aria-hidden="true"><use href="#kdna-ei-icon-download"></use></svg>
										<span x-text="table.label"></span>
										<span class="kdna-ei-muted" x-show="exportBusy === table.key"><?php esc_html_e( 'Preparing', 'kdna-ecommerce-insights' ); ?></span>
									</button>
								</li>
							</template>
						</ul>
					</div>
				</template>
			</div>
		</section>
	</div>

	<?php // ---------------------------------------------------------- Tax settings window. ?>
	<div class="kdna-ei-modal" x-show="taxForm.open" x-cloak x-transition.opacity @keydown.escape.window="taxForm.open && closeTaxSettings()">
		<div class="kdna-ei-modal__backdrop" @click="closeTaxSettings()"></div>
		<div class="kdna-ei-modal__dialog kdna-ei-modal__dialog--narrow" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-tax-settings-title" tabindex="-1" x-ref="taxDialog" @keydown.tab="trap( $event, 'taxDialog' )">
			<div class="kdna-ei-modal__header">
				<h2 id="kdna-ei-tax-settings-title" class="kdna-ei-card__title"><?php esc_html_e( 'Tax settings', 'kdna-ecommerce-insights' ); ?></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closeTaxSettings()" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>
			<form class="kdna-ei-modal__body kdna-ei-tax-form" @submit.prevent="saveTaxSettings()" novalidate>
				<template x-if="taxForm.error">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="taxForm.error"></p></div>
				</template>

				<div>
					<label for="kdna-ei-tax-system" class="kdna-ei-field-label"><?php esc_html_e( 'Tax system', 'kdna-ecommerce-insights' ); ?></label>
					<select id="kdna-ei-tax-system" class="kdna-ei-select" x-ref="taxSystem" x-model="taxForm.system">
						<template x-for="( label, key ) in systems" :key="key">
							<option :value="key" x-text="label" :selected="key === taxForm.system"></option>
						</template>
					</select>
					<p class="kdna-ei-help" x-show="taxForm.system === 'au_gst'"><?php esc_html_e( 'Shows the BAS lines G1, 1A and 1B. Financial years run July to June.', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help" x-show="taxForm.system === 'vat'"><?php esc_html_e( 'For VAT, or GST outside Australia. Financial years run January to December.', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-help" x-show="taxForm.system === 'sales_tax'"><?php esc_html_e( 'Shows the tax collected on orders. Tax on costs is not claimed back.', 'kdna-ecommerce-insights' ); ?></p>
				</div>

				<div x-show="taxForm.system !== 'none' && taxForm.system !== 'sales_tax'">
					<label for="kdna-ei-tax-rate" class="kdna-ei-field-label"><?php esc_html_e( 'Rate (%)', 'kdna-ecommerce-insights' ); ?></label>
					<input id="kdna-ei-tax-rate" type="text" inputmode="decimal" class="kdna-ei-input kdna-ei-input--short" x-model="taxForm.rate" :class="{ 'is-invalid': taxForm.fields.rate }" :aria-invalid="taxForm.fields.rate ? 'true' : 'false'" />
					<p class="kdna-ei-help" x-show="! taxForm.fields.rate"><?php esc_html_e( 'Used to work out the tax inside overheads and ad spend marked as including tax. 10 for Australian GST.', 'kdna-ecommerce-insights' ); ?></p>
					<p class="kdna-ei-field-error" x-show="taxForm.fields.rate" x-text="taxForm.fields.rate"></p>
				</div>

				<fieldset x-show="taxForm.system !== 'none'">
					<legend class="kdna-ei-field-label"><?php esc_html_e( 'You report', 'kdna-ecommerce-insights' ); ?></legend>
					<div class="kdna-ei-segmented" role="group">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="taxForm.reporting_period === 'monthly' ? 'true' : 'false'" @click="taxForm.reporting_period = 'monthly'"><?php esc_html_e( 'Monthly', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="taxForm.reporting_period === 'quarterly' ? 'true' : 'false'" @click="taxForm.reporting_period = 'quarterly'"><?php esc_html_e( 'Quarterly', 'kdna-ecommerce-insights' ); ?></button>
					</div>
				</fieldset>

				<div class="kdna-ei-modal__footer kdna-ei-modal__footer--inline">
					<button type="button" class="kdna-ei-btn" @click="closeTaxSettings()"><?php esc_html_e( 'Cancel', 'kdna-ecommerce-insights' ); ?></button>
					<button type="submit" class="kdna-ei-btn kdna-ei-btn--primary" :disabled="taxForm.busy" x-text="taxForm.busy ? '<?php echo esc_js( __( 'Saving', 'kdna-ecommerce-insights' ) ); ?>' : '<?php echo esc_js( __( 'Save', 'kdna-ecommerce-insights' ) ); ?>'"></button>
				</div>
			</form>
		</div>
	</div>

	<?php // ---------------------------------------------------------- Digest preview window. ?>
	<div class="kdna-ei-modal" x-show="preview.open" x-cloak x-transition.opacity @keydown.escape.window="preview.open && closePreview()">
		<div class="kdna-ei-modal__backdrop" @click="closePreview()"></div>
		<div class="kdna-ei-modal__dialog kdna-ei-modal__dialog--wide kdna-ei-preview" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-preview-title" tabindex="-1" x-ref="previewDialog" @keydown.tab="trap( $event, 'previewDialog' )">
			<div class="kdna-ei-modal__header">
				<div>
					<h2 id="kdna-ei-preview-title" class="kdna-ei-card__title"><?php esc_html_e( 'Digest preview', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle" x-show="preview.subject">
						<?php esc_html_e( 'Subject:', 'kdna-ecommerce-insights' ); ?> <span x-text="preview.subject"></span>
					</p>
				</div>
				<div class="kdna-ei-preview__tools">
					<div class="kdna-ei-segmented" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'kdna-ecommerce-insights' ); ?>">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="preview.width === 'desktop' ? 'true' : 'false'" @click="preview.width = 'desktop'"><?php esc_html_e( 'Desktop', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="preview.width === 'phone' ? 'true' : 'false'" @click="preview.width = 'phone'"><?php esc_html_e( 'Phone', 'kdna-ecommerce-insights' ); ?></button>
					</div>
					<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closePreview()" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
				</div>
			</div>
			<div class="kdna-ei-modal__body kdna-ei-preview__body">
				<p class="kdna-ei-help kdna-ei-preview__note"><?php esc_html_e( 'Showing the last complete period with your current choices. Email apps differ a little, so use Send test to see it in your own inbox.', 'kdna-ecommerce-insights' ); ?></p>
				<template x-if="preview.error">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="preview.error"></p></div>
				</template>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-preview__skeleton" x-show="preview.loading" aria-hidden="true"></span>
				<div class="kdna-ei-preview__frame" :class="'kdna-ei-preview__frame--' + preview.width" x-show="! preview.loading && preview.html">
					<iframe title="<?php esc_attr_e( 'Digest email preview', 'kdna-ecommerce-insights' ); ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" :srcdoc="preview.html"></iframe>
				</div>
			</div>
		</div>
	</div>
</div>
