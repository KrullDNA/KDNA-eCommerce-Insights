<?php
/**
 * Costs screen, Overheads tab: add, edit and delete recurring and one-off
 * overheads. Behaviour lives in admin/js/screens/overheads.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

$kdna_ei_symbol  = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
$kdna_ei_tax_sys = (string) KDNA_EcommerceInsights_Settings::get( 'tax.system', 'none' );
$kdna_ei_tax     = 'vat' === $kdna_ei_tax_sys ? __( 'VAT', 'kdna-ecommerce-insights' ) : __( 'GST', 'kdna-ecommerce-insights' );
?>
<div class="kdna-ei-overheads" x-data="kdnaEiOverheads" x-effect="if ( route === 'costs' && tab === 'overheads' ) ensureLoaded()">

	<div class="kdna-ei-stat-grid">
		<div class="kdna-ei-card kdna-ei-stat">
			<p class="kdna-ei-stat__label"><?php esc_html_e( 'Overheads this month', 'kdna-ecommerce-insights' ); ?></p>
			<p class="kdna-ei-stat__value kdna-ei-num" x-show="loaded" x-text="money( thisMonth.net )"></p>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--value" x-show="! loaded"></span>
			<p class="kdna-ei-stat__note"><?php esc_html_e( 'Spread evenly by day, so any date range carries its fair share.', 'kdna-ecommerce-insights' ); ?></p>
		</div>
		<div class="kdna-ei-card kdna-ei-stat">
			<p class="kdna-ei-stat__label"><?php esc_html_e( 'Overheads this year', 'kdna-ecommerce-insights' ); ?></p>
			<p class="kdna-ei-stat__value kdna-ei-num" x-show="loaded" x-text="money( thisYear.net )"></p>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--value" x-show="! loaded"></span>
			<p class="kdna-ei-stat__note" x-show="thisYear.tax > 0" x-text="sprintf( t.taxExcluded, money( thisYear.tax ) )"></p>
		</div>
		<div class="kdna-ei-card kdna-ei-stat">
			<p class="kdna-ei-stat__label"><?php esc_html_e( 'Active overheads', 'kdna-ecommerce-insights' ); ?></p>
			<p class="kdna-ei-stat__value kdna-ei-num" x-show="loaded" x-text="activeCount"></p>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--value" x-show="! loaded"></span>
			<p class="kdna-ei-stat__note"><?php esc_html_e( 'Recurring costs running today.', 'kdna-ecommerce-insights' ); ?></p>
		</div>
	</div>

	<div class="kdna-ei-card">
		<div class="kdna-ei-card__header">
			<div>
				<h2 class="kdna-ei-card__title"><?php esc_html_e( 'Overheads', 'kdna-ecommerce-insights' ); ?></h2>
				<p class="kdna-ei-card__subtitle"><?php esc_html_e( 'Running costs that are not tied to one order, such as rent, software, wages or an agency retainer. They come off net profit.', 'kdna-ecommerce-insights' ); ?></p>
			</div>
			<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="openForm()">+ <?php esc_html_e( 'Add overhead', 'kdna-ecommerce-insights' ); ?></button>
		</div>

		<template x-if="notice">
			<div class="kdna-ei-notice" :class="'kdna-ei-notice--' + notice.type" role="status">
				<p x-text="notice.text"></p>
				<button type="button" class="kdna-ei-notice__close" @click="notice = null" aria-label="<?php esc_attr_e( 'Dismiss', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>
		</template>

		<div class="kdna-ei-table-wrap">
			<table class="kdna-ei-table kdna-ei-table--hover">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Category', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Amount', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Repeats', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Dates', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Per month', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col"><span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Actions', 'kdna-ecommerce-insights' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<template x-if="! loaded">
						<tr><td colspan="7"><span class="kdna-ei-skeleton kdna-ei-skeleton--block" style="height: 120px;"></span></td></tr>
					</template>
					<template x-for="item in overheads" :key="item.id">
						<tr :class="{ 'is-ended': isEnded( item ) }">
							<td>
								<span class="kdna-ei-product__text">
									<span class="kdna-ei-product__name" x-text="item.name"></span>
									<span class="kdna-ei-product__meta" x-show="item.includes_gst" x-text="t.includesTax"></span>
								</span>
							</td>
							<td x-text="categoryLabel( item.category )"></td>
							<td class="is-numeric kdna-ei-num" x-text="money( item.amount )"></td>
							<td>
								<span class="kdna-ei-badge" :class="isEnded( item ) ? '' : 'kdna-ei-badge--positive'" x-text="frequencyLabel( item.frequency )"></span>
							</td>
							<td class="kdna-ei-muted" x-text="dateText( item )"></td>
							<td class="is-numeric kdna-ei-num" x-text="item.monthly === null ? '–' : money( item.monthly )"></td>
							<td class="is-numeric">
								<button type="button" class="kdna-ei-link" @click="openForm( item )" x-text="t.edit"></button>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
			<template x-if="loaded && ! overheads.length">
				<div class="kdna-ei-empty">
					<p class="kdna-ei-empty__title"><?php esc_html_e( 'No overheads yet. Add your regular running costs to see your true net profit.', 'kdna-ecommerce-insights' ); ?></p>
				</div>
			</template>
		</div>
	</div>

	<?php // Add and edit window. ?>
	<div class="kdna-ei-modal" x-show="formOpen" x-cloak x-transition.opacity @keydown.escape.stop="closeForm()">
		<div class="kdna-ei-modal__backdrop" @click="closeForm()"></div>
		<form class="kdna-ei-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-overhead-title" tabindex="-1" x-ref="dialog" @submit.prevent="submit()" @input="clearError( $event )" @change="clearError( $event )">
			<div class="kdna-ei-modal__header">
				<h2 id="kdna-ei-overhead-title" class="kdna-ei-card__title" x-text="form.id ? t.editTitle : t.addTitle"></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closeForm()" :disabled="busy" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>

			<div class="kdna-ei-modal__body kdna-ei-form">
				<template x-if="formError">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="formError"></p></div>
				</template>

				<label class="kdna-ei-form__field">
					<span class="kdna-ei-field-label"><?php esc_html_e( 'Name', 'kdna-ecommerce-insights' ); ?></span>
					<input type="text" class="kdna-ei-input" :class="{ 'is-invalid': errors.name }" x-model="form.name" x-ref="nameInput" placeholder="<?php esc_attr_e( 'For example Studio rent', 'kdna-ecommerce-insights' ); ?>" />
					<span class="kdna-ei-field-error" x-show="errors.name" x-text="errors.name"></span>
				</label>

				<div class="kdna-ei-form__row">
					<label class="kdna-ei-form__field">
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Category', 'kdna-ecommerce-insights' ); ?></span>
						<select class="kdna-ei-select" x-model="form.categoryChoice">
							<template x-for="( label, key ) in categories" :key="key">
								<option :value="key" x-text="label" :selected="form.categoryChoice === key"></option>
							</template>
							<option value="__custom"><?php esc_html_e( 'Your own category', 'kdna-ecommerce-insights' ); ?></option>
						</select>
					</label>
					<label class="kdna-ei-form__field" x-show="form.categoryChoice === '__custom'">
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Category name', 'kdna-ecommerce-insights' ); ?></span>
						<input type="text" class="kdna-ei-input" x-model="form.customCategory" maxlength="50" />
					</label>
				</div>

				<div class="kdna-ei-form__row">
					<label class="kdna-ei-form__field">
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Amount', 'kdna-ecommerce-insights' ); ?></span>
						<span class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': errors.amount }">
							<span class="kdna-ei-affix__before" aria-hidden="true"><?php echo esc_html( $kdna_ei_symbol ); ?></span>
							<input type="text" inputmode="decimal" class="kdna-ei-input" x-model="form.amount" placeholder="0.00" />
						</span>
						<span class="kdna-ei-field-error" x-show="errors.amount" x-text="errors.amount"></span>
					</label>
					<label class="kdna-ei-form__field">
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Repeats', 'kdna-ecommerce-insights' ); ?></span>
						<select class="kdna-ei-select" :class="{ 'is-invalid': errors.frequency }" x-model="form.frequency">
							<template x-for="( label, key ) in frequencies" :key="key">
								<option :value="key" x-text="label" :selected="form.frequency === key"></option>
							</template>
						</select>
					</label>
				</div>

				<div class="kdna-ei-form__row">
					<label class="kdna-ei-form__field">
						<span class="kdna-ei-field-label" x-text="form.frequency === 'one_off' ? t.paidOn : t.startsOn"></span>
						<input type="date" class="kdna-ei-input" :class="{ 'is-invalid': errors.start_date }" x-model="form.start_date" />
						<span class="kdna-ei-field-error" x-show="errors.start_date" x-text="errors.start_date"></span>
					</label>
					<label class="kdna-ei-form__field" x-show="form.frequency !== 'one_off'">
						<span class="kdna-ei-field-label"><?php esc_html_e( 'Ends on (optional)', 'kdna-ecommerce-insights' ); ?></span>
						<input type="date" class="kdna-ei-input" :class="{ 'is-invalid': errors.end_date }" x-model="form.end_date" :min="form.start_date" />
						<span class="kdna-ei-field-error" x-show="errors.end_date" x-text="errors.end_date"></span>
					</label>
				</div>

				<label class="kdna-ei-switch">
					<input type="checkbox" x-model="form.includes_gst" />
					<span class="kdna-ei-switch__track" aria-hidden="true"></span>
					<span>
						<?php
						/* translators: %s: GST or VAT. */
						printf( esc_html__( 'This amount includes %s', 'kdna-ecommerce-insights' ), esc_html( $kdna_ei_tax ) );
						?>
					</span>
				</label>

				<p class="kdna-ei-help" x-text="preview"></p>
			</div>

			<div class="kdna-ei-modal__footer">
				<div>
					<template x-if="form.id && ! confirmDelete">
						<button type="button" class="kdna-ei-link kdna-ei-link--danger" @click="confirmDelete = true" :disabled="busy" x-text="t.delete"></button>
					</template>
					<template x-if="form.id && confirmDelete">
						<span class="kdna-ei-confirm">
							<span x-text="t.deleteConfirm"></span>
							<button type="button" class="kdna-ei-btn kdna-ei-btn--danger" @click="remove()" :disabled="busy" x-text="t.deleteYes"></button>
							<button type="button" class="kdna-ei-link" @click="confirmDelete = false" x-text="t.keep"></button>
						</span>
					</template>
				</div>
				<div class="kdna-ei-modal__actions">
					<button type="button" class="kdna-ei-btn" @click="closeForm()" :disabled="busy" x-text="t.cancel"></button>
					<button type="submit" class="kdna-ei-btn kdna-ei-btn--primary" :disabled="busy" x-text="busy ? t.saving : ( form.id ? t.saveChanges : t.add )"></button>
				</div>
			</div>
		</form>
	</div>
</div>
