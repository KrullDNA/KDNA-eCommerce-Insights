<?php
/**
 * Marketing screen (section 8): ad spend KPIs (ROAS, MER, cost per new
 * customer, profit after ads), spend by channel over time, channel and
 * campaign tables, the entries list, and the forms to add spend by hand or
 * import a CSV from Meta, Google or anywhere else. Behaviour lives in
 * admin/js/screens/marketing.js.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use KDNA_EcommerceInsights_Admin as Admin;

$kdna_ei_symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
?>
<div class="kdna-ei-marketing" x-data="kdnaEiMarketing" x-effect="if ( route === 'marketing' ) ensureLoaded()" :aria-busy="loading ? 'true' : 'false'">

	<div class="kdna-ei-marketing__bar">
		<p class="kdna-ei-muted" x-text="t.intro"></p>
		<div class="kdna-ei-toolbar__actions">
			<button type="button" class="kdna-ei-btn" @click="openImport( $event.currentTarget )">
				<?php Admin::icon( 'upload', 'kdna-ei-icon--sm' ); ?>
				<span><?php esc_html_e( 'Import CSV', 'kdna-ecommerce-insights' ); ?></span>
			</button>
			<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="openForm( null, $event.currentTarget )">
				<span aria-hidden="true">+</span>
				<span><?php esc_html_e( 'Add spend', 'kdna-ecommerce-insights' ); ?></span>
			</button>
		</div>
	</div>

	<template x-if="notice">
		<div class="kdna-ei-notice" :class="'kdna-ei-notice--' + notice.type" role="status">
			<p x-text="notice.text"></p>
			<button type="button" class="kdna-ei-notice__close" @click="notice = null" aria-label="<?php esc_attr_e( 'Dismiss', 'kdna-ecommerce-insights' ); ?>">&times;</button>
		</div>
	</template>

	<template x-if="loadError">
		<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert">
			<p x-text="loadError"></p>
			<button type="button" class="kdna-ei-btn" @click="load( true )"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
	</template>

	<?php // Live connections (section 7.4). ?>
	<section class="kdna-ei-connections" aria-labelledby="kdna-ei-connections-title">
		<h2 id="kdna-ei-connections-title" class="kdna-ei-visually-hidden"><?php esc_html_e( 'Live connections', 'kdna-ecommerce-insights' ); ?></h2>
		<template x-for="card in connectionList" :key="card.key">
			<article class="kdna-ei-card kdna-ei-connection" :class="'is-' + card.state" :aria-label="card.label">
				<div class="kdna-ei-connection__head">
					<span class="kdna-ei-connection__logo" :class="'is-' + card.key" aria-hidden="true" x-text="card.key === 'meta' ? 'M' : 'G'"></span>
					<div class="kdna-ei-connection__title">
						<h3 class="kdna-ei-card__title" x-text="card.label"></h3>
						<p class="kdna-ei-muted" x-text="card.account ? card.account + ( card.currency ? ' · ' + card.currency : '' ) : t.liveSubtitle"></p>
					</div>
					<span class="kdna-ei-badge" :class="stateBadge( card.state )" x-text="t.states[ card.state ]"></span>
				</div>

				<div class="kdna-ei-connection__body">
					<template x-if="card.state === 'not_set_up'">
						<p class="kdna-ei-muted" x-text="card.key === 'meta' ? t.metaIntro : t.googleIntro"></p>
					</template>
					<template x-if="card.state !== 'not_set_up' && card.missing.length">
						<p class="kdna-ei-muted" x-text="sprintf( t.stillNeeded, card.missing.join( ', ' ) )"></p>
					</template>
					<template x-if="card.last_success">
						<p x-text="sprintf( t.lastSynced, dateTime( card.last_success ), formatNumber( card.rows ), money( card.spend ) )"></p>
					</template>
					<template x-if="card.next_sync && card.state === 'connected'">
						<p class="kdna-ei-muted" x-text="sprintf( t.nextSync, dateTime( card.next_sync ) )"></p>
					</template>
					<template x-if="card.synced_from && card.state !== 'not_set_up'">
						<p class="kdna-ei-muted" x-text="sprintf( t.syncedFrom, dateLabel( card.synced_from ) )"></p>
					</template>
					<template x-if="card.message">
						<p class="kdna-ei-connection__error" role="alert" x-text="card.message"></p>
					</template>
					<template x-if="card.warning">
						<p class="kdna-ei-connection__warning" x-text="card.warning"></p>
					</template>
					<template x-if="connResult[ card.key ]">
						<p class="kdna-ei-connection__result" role="status" x-text="connResult[ card.key ]"></p>
					</template>
				</div>

				<div class="kdna-ei-connection__actions">
					<button type="button" class="kdna-ei-btn" :class="{ 'kdna-ei-btn--primary': card.state === 'not_set_up' }" @click="openConnection( card.key, $event.currentTarget )" x-text="card.state === 'not_set_up' ? t.setUp : t.changeDetails"></button>
					<template x-if="card.key === 'google' && card.settings.client_id && ! card.settings.signed_in">
						<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="googleSignIn()" :disabled="connBusy.google" x-text="t.signIn"></button>
					</template>
					<template x-if="! card.missing.length">
						<div class="kdna-ei-connection__sync">
							<label class="kdna-ei-visually-hidden" :for="'kdna-ei-sync-days-' + card.key" x-text="t.howFarBack"></label>
							<select class="kdna-ei-select kdna-ei-select--small" :id="'kdna-ei-sync-days-' + card.key" x-model.number="syncDays[ card.key ]">
								<option value="7" x-text="t.days7"></option>
								<option value="30" x-text="t.days30"></option>
								<option value="90" x-text="t.days90"></option>
								<option value="365" x-text="t.days365"></option>
							</select>
							<button type="button" class="kdna-ei-btn" @click="syncNow( card.key )" :disabled="connBusy[ card.key ]" x-text="connBusy[ card.key ] === 'sync' ? t.syncing : t.syncNow"></button>
							<button type="button" class="kdna-ei-link" @click="testConnection( card.key )" :disabled="connBusy[ card.key ]" x-text="connBusy[ card.key ] === 'test' ? t.testing : t.test"></button>
						</div>
					</template>
					<template x-if="card.state !== 'not_set_up' || card.settings.has_token || card.settings.has_secret">
						<span class="kdna-ei-connection__disconnect">
							<template x-if="confirmDisconnect !== card.key">
								<button type="button" class="kdna-ei-link kdna-ei-link--danger" @click="confirmDisconnect = card.key" x-text="t.disconnect"></button>
							</template>
							<template x-if="confirmDisconnect === card.key">
								<span class="kdna-ei-confirm">
									<span x-text="t.disconnectConfirm"></span>
									<button type="button" class="kdna-ei-link kdna-ei-link--danger" @click="disconnect( card.key )" x-text="t.yesDisconnect"></button>
									<button type="button" class="kdna-ei-link" @click="confirmDisconnect = ''" x-text="t.keep"></button>
								</span>
							</template>
						</span>
					</template>
				</div>
			</article>
		</template>
	</section>

	<?php // Nothing spent yet in this period. ?>
	<template x-if="loaded && ! hasSpend">
		<div class="kdna-ei-card kdna-ei-empty-state">
			<span class="kdna-ei-empty-state__icon" aria-hidden="true"><?php Admin::icon( 'megaphone', 'kdna-ei-icon--lg' ); ?></span>
			<h2 class="kdna-ei-card__title" x-text="t.emptyTitle"></h2>
			<p class="kdna-ei-muted" x-text="t.emptyText"></p>
			<div class="kdna-ei-toolbar__actions">
				<button type="button" class="kdna-ei-btn" @click="openImport( $event.currentTarget )"><?php esc_html_e( 'Import a CSV', 'kdna-ecommerce-insights' ); ?></button>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="openForm( null, $event.currentTarget )"><?php esc_html_e( 'Add spend', 'kdna-ecommerce-insights' ); ?></button>
			</div>
		</div>
	</template>

	<div class="kdna-ei-marketing__grid" x-show="! loaded || hasSpend">

		<?php // KPI strip. ?>
		<section class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" aria-label="<?php esc_attr_e( 'Marketing figures', 'kdna-ecommerce-insights' ); ?>">
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
						</div>
						<div class="kdna-ei-kpi__row">
							<span class="kdna-ei-kpi__value" x-text="kpi.value === null ? '–' : metric( kpi.value, kpi.format, kpi.decimals )"></span>
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

		<?php // Spend by channel over time. ?>
		<section class="kdna-ei-card kdna-ei-area-chart" aria-labelledby="kdna-ei-spend-chart-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-spend-chart-title" class="kdna-ei-card__title"><?php esc_html_e( 'Spend by channel', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle" x-text="report.gst_rate > 0 ? t.exGst : t.asEntered"></p>
				</div>
				<div class="kdna-ei-chart-legend" aria-hidden="true">
					<template x-for="row in channelRows" :key="row.channel">
						<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot" :style="'background: var(--kdna-ei-' + row.css + ')'"></span><span x-text="row.label"></span></span>
					</template>
				</div>
			</div>
			<div class="kdna-ei-chart">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" x-show="! loaded" aria-hidden="true"></span>
				<canvas x-ref="spend" x-show="loaded" role="img" :aria-label="chartSummary"></canvas>
			</div>
		</section>

		<?php // Channels. ?>
		<section class="kdna-ei-card kdna-ei-area-side" aria-labelledby="kdna-ei-channels-title">
			<div class="kdna-ei-card__header">
				<h2 id="kdna-ei-channels-title" class="kdna-ei-card__title"><?php esc_html_e( 'Channels', 'kdna-ecommerce-insights' ); ?></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-btn--ghost" @click="exportCsv( 'channels' )" :disabled="exporting || ! loaded" aria-label="<?php esc_attr_e( 'Export channels as CSV', 'kdna-ecommerce-insights' ); ?>" title="<?php esc_attr_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?>">
					<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
				</button>
			</div>
			<table class="kdna-ei-table kdna-ei-channel-table">
				<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Spend and return by channel', 'kdna-ecommerce-insights' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Channel', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Spend', 'kdna-ecommerce-insights' ); ?></th>
						<th scope="col" class="is-numeric" :title="t.roasHelp"><?php esc_html_e( 'ROAS', 'kdna-ecommerce-insights' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="row in channelRows" :key="row.channel">
						<tr>
							<th scope="row">
								<span class="kdna-ei-location">
									<span><span class="kdna-ei-legend__dot" :style="'background: var(--kdna-ei-' + row.css + ')'"></span><span x-text="row.label"></span></span>
									<span class="kdna-ei-bar" aria-hidden="true"><span class="kdna-ei-bar__fill" :style="'width:' + row.share + '%; background: var(--kdna-ei-' + row.css + ')'"></span></span>
								</span>
							</th>
							<td class="is-numeric kdna-ei-num" x-text="money( row.spend, 0 )"></td>
							<td class="is-numeric kdna-ei-num" :title="row.roas === null ? t.noValueHelp : ''" x-text="row.roas === null ? '–' : ratio( row.roas )"></td>
						</tr>
					</template>
				</tbody>
			</table>
		</section>

		<?php // Campaigns. ?>
		<section class="kdna-ei-card kdna-ei-area-campaigns" aria-labelledby="kdna-ei-campaigns-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-campaigns-title" class="kdna-ei-card__title"><?php esc_html_e( 'Campaigns', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle" x-text="t.campaignsNote"></p>
				</div>
				<div class="kdna-ei-toolbar__actions">
					<label class="kdna-ei-search kdna-ei-search--small">
						<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Search campaigns', 'kdna-ecommerce-insights' ); ?></span>
						<?php Admin::icon( 'search', 'kdna-ei-icon--sm' ); ?>
						<input type="search" class="kdna-ei-input" placeholder="<?php esc_attr_e( 'Search campaigns', 'kdna-ecommerce-insights' ); ?>" x-model="campaignSearch" />
					</label>
					<button type="button" class="kdna-ei-btn" @click="exportCsv( 'campaigns' )" :disabled="exporting || ! loaded">
						<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
						<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
					</button>
				</div>
			</div>
			<div class="kdna-ei-table-wrap" tabindex="0">
				<table class="kdna-ei-table kdna-ei-table--hover kdna-ei-table--compact">
					<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Campaigns. Column headings sort the table.', 'kdna-ecommerce-insights' ); ?></caption>
					<thead>
						<tr>
							<?php
							$kdna_ei_campaign_columns = array(
								'campaign_name'    => array( __( 'Campaign', 'kdna-ecommerce-insights' ), false ),
								'spend'            => array( __( 'Spend', 'kdna-ecommerce-insights' ), true ),
								'impressions'      => array( __( 'Impressions', 'kdna-ecommerce-insights' ), true ),
								'clicks'           => array( __( 'Clicks', 'kdna-ecommerce-insights' ), true ),
								'cpc'              => array( __( 'Cost per click', 'kdna-ecommerce-insights' ), true ),
								'conversions'      => array( __( 'Purchases', 'kdna-ecommerce-insights' ), true ),
								'conversion_value' => array( __( 'Purchase value', 'kdna-ecommerce-insights' ), true ),
								'roas'             => array( __( 'ROAS', 'kdna-ecommerce-insights' ), true ),
							);
							foreach ( $kdna_ei_campaign_columns as $kdna_ei_key => $kdna_ei_column ) :
								?>
								<th scope="col" class="<?php echo $kdna_ei_column[1] ? 'is-numeric' : ''; ?>" :aria-sort="ariaSort( '<?php echo esc_js( $kdna_ei_key ); ?>' )">
									<button type="button" class="kdna-ei-sort" @click="sortCampaigns( '<?php echo esc_js( $kdna_ei_key ); ?>' )">
										<span><?php echo esc_html( $kdna_ei_column[0] ); ?></span>
										<svg class="kdna-ei-icon kdna-ei-sort__icon" aria-hidden="true"><use href="#kdna-ei-icon-chevron-down"></use></svg>
									</button>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody x-show="! loaded">
						<?php for ( $kdna_ei_i = 0; $kdna_ei_i < 5; $kdna_ei_i++ ) : ?>
							<tr aria-hidden="true">
								<?php foreach ( $kdna_ei_campaign_columns as $kdna_ei_column ) : ?>
									<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
								<?php endforeach; ?>
							</tr>
						<?php endfor; ?>
					</tbody>
					<tbody x-show="loaded" x-cloak>
						<template x-for="row in visibleCampaigns" :key="row.channel + row.campaign_id + row.campaign_name">
							<tr>
								<td>
									<div class="kdna-ei-product__text">
										<span class="kdna-ei-product__name" x-text="row.campaign_name || t.noCampaign"></span>
										<span class="kdna-ei-product__meta"><span class="kdna-ei-legend__dot" :style="'background: var(--kdna-ei-' + colourOf( row.channel ).css + ')'"></span><span x-text="row.label"></span></span>
									</div>
								</td>
								<td class="is-numeric kdna-ei-num" x-text="money( row.spend )"></td>
								<td class="is-numeric kdna-ei-num" x-text="row.impressions ? formatNumber( row.impressions ) : '–'"></td>
								<td class="is-numeric kdna-ei-num" x-text="row.clicks ? formatNumber( row.clicks ) : '–'"></td>
								<td class="is-numeric kdna-ei-num" x-text="row.cpc === null ? '–' : money( row.cpc )"></td>
								<td class="is-numeric kdna-ei-num" x-text="row.conversions ? formatNumber( row.conversions ) : '–'"></td>
								<td class="is-numeric kdna-ei-num" x-text="row.conversion_value ? money( row.conversion_value ) : '–'"></td>
								<td class="is-numeric kdna-ei-num" :class="roasClass( row.roas )" x-text="row.roas === null ? '–' : ratio( row.roas )"></td>
							</tr>
						</template>
					</tbody>
				</table>
				<template x-if="loaded && ! filteredCampaigns.length">
					<div class="kdna-ei-empty"><p class="kdna-ei-empty__title" x-text="campaignSearch ? t.noMatches : t.noCampaigns"></p></div>
				</template>
			</div>
			<template x-if="loaded && filteredCampaigns.length > 10">
				<div class="kdna-ei-card__footer">
					<button type="button" class="kdna-ei-link" @click="showAllCampaigns = ! showAllCampaigns" x-text="showAllCampaigns ? t.showFewer : sprintf( t.showAll, formatNumber( filteredCampaigns.length ) )"></button>
				</div>
			</template>
		</section>

		<?php // Entries. ?>
		<section class="kdna-ei-card kdna-ei-area-entries" aria-labelledby="kdna-ei-entries-title">
			<div class="kdna-ei-card__header">
				<div>
					<h2 id="kdna-ei-entries-title" class="kdna-ei-card__title"><?php esc_html_e( 'Spend entries', 'kdna-ecommerce-insights' ); ?></h2>
					<p class="kdna-ei-card__subtitle" x-text="t.entriesNote"></p>
				</div>
				<button type="button" class="kdna-ei-btn" @click="exportCsv( 'adspend' )" :disabled="exporting || ! loaded">
					<?php Admin::icon( 'download', 'kdna-ei-icon--sm' ); ?>
					<span><?php esc_html_e( 'Export CSV', 'kdna-ecommerce-insights' ); ?></span>
				</button>
			</div>
			<div class="kdna-ei-table-wrap" tabindex="0">
				<table class="kdna-ei-table kdna-ei-table--hover kdna-ei-table--compact">
					<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'Ad spend entries overlapping these dates', 'kdna-ecommerce-insights' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Dates', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Channel', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Campaign', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Added by', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="is-numeric"><?php esc_html_e( 'Amount', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col"><span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Actions', 'kdna-ecommerce-insights' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<template x-for="entry in visibleEntries" :key="entry.entry_group">
							<tr>
								<td class="kdna-ei-nowrap" x-text="dateRange( entry.start, entry.end )"></td>
								<td><span class="kdna-ei-legend__dot" :style="'background: var(--kdna-ei-' + colourOf( entry.channel ).css + ')'"></span><span x-text="channelLabel( entry.channel )"></span></td>
								<td x-text="entry.campaigns > 1 ? sprintf( t.campaignCount, entry.campaigns ) : ( entry.campaign_name || '–' )"></td>
								<td><span class="kdna-ei-badge kdna-ei-badge--plain" x-text="t.sources[ entry.source ] || entry.source"></span></td>
								<td class="is-numeric kdna-ei-num">
									<span x-text="money( entry.amount )"></span>
									<span class="kdna-ei-muted kdna-ei-entry-gst" x-show="entry.includes_gst" x-text="t.inclGst"></span>
								</td>
								<td class="kdna-ei-row-actions">
									<template x-if="entry.editable">
										<button type="button" class="kdna-ei-link" @click="openForm( entry, $event.currentTarget )" :aria-label="sprintf( t.editEntry, channelLabel( entry.channel ), dateRange( entry.start, entry.end ) )"><?php esc_html_e( 'Edit', 'kdna-ecommerce-insights' ); ?></button>
									</template>
									<template x-if="entry.live">
										<span class="kdna-ei-muted" :title="t.liveHelp" x-text="t.liveSynced"></span>
									</template>
									<template x-if="! entry.live && confirmDelete !== entry.entry_group">
										<button type="button" class="kdna-ei-link kdna-ei-link--danger" @click="confirmDelete = entry.entry_group" :aria-label="sprintf( t.deleteEntry, channelLabel( entry.channel ), dateRange( entry.start, entry.end ) )"><?php esc_html_e( 'Delete', 'kdna-ecommerce-insights' ); ?></button>
									</template>
									<template x-if="confirmDelete === entry.entry_group">
										<span class="kdna-ei-confirm">
											<span x-text="entry.source === 'manual' ? t.confirmEntry : t.confirmImport"></span>
											<button type="button" class="kdna-ei-link kdna-ei-link--danger" @click="deleteEntry( entry )" :disabled="deleting"><?php esc_html_e( 'Yes, delete', 'kdna-ecommerce-insights' ); ?></button>
											<button type="button" class="kdna-ei-link" @click="confirmDelete = ''"><?php esc_html_e( 'Keep', 'kdna-ecommerce-insights' ); ?></button>
										</span>
									</template>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
				<template x-if="loaded && ! entries.length">
					<div class="kdna-ei-empty"><p class="kdna-ei-empty__title" x-text="t.noEntries"></p></div>
				</template>
			</div>
			<template x-if="loaded && entries.length > 10">
				<div class="kdna-ei-card__footer">
					<button type="button" class="kdna-ei-link" @click="showAllEntries = ! showAllEntries" x-text="showAllEntries ? t.showFewer : sprintf( t.showAll, formatNumber( entries.length ) )"></button>
				</div>
			</template>
		</section>
	</div>

	<?php // Add or edit spend. ?>
	<div class="kdna-ei-modal" x-show="form.open" x-cloak x-transition.opacity @keydown.escape.window="form.open && closeForm()">
		<div class="kdna-ei-modal__backdrop" @click="closeForm()"></div>
		<div class="kdna-ei-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-spend-form-title" tabindex="-1" x-ref="formDialog" @keydown.tab="trap( $event, 'formDialog' )">
			<div class="kdna-ei-modal__header">
				<h2 id="kdna-ei-spend-form-title" class="kdna-ei-card__title" x-text="form.group ? t.editTitle : t.addTitle"></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closeForm()" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>
			<div class="kdna-ei-modal__body kdna-ei-spend-form">
				<template x-if="form.error">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="form.error"></p></div>
				</template>

				<div class="kdna-ei-spend-form__row">
					<div>
						<label for="kdna-ei-spend-channel" class="kdna-ei-field-label"><?php esc_html_e( 'Channel', 'kdna-ecommerce-insights' ); ?></label>
						<select id="kdna-ei-spend-channel" class="kdna-ei-select" x-model="form.channel" :class="{ 'is-invalid': form.errors.channel }">
							<template x-for="channel in channels" :key="channel.key">
								<option :value="channel.key" x-text="channel.label" :selected="form.channel === channel.key"></option>
							</template>
							<option value="__new"><?php esc_html_e( 'Add a channel...', 'kdna-ecommerce-insights' ); ?></option>
						</select>
						<p class="kdna-ei-field-error" x-show="form.errors.channel" x-text="form.errors.channel"></p>
					</div>
					<div x-show="form.channel === '__new'" x-cloak>
						<label for="kdna-ei-spend-new-channel" class="kdna-ei-field-label"><?php esc_html_e( 'New channel name', 'kdna-ecommerce-insights' ); ?></label>
						<input id="kdna-ei-spend-new-channel" type="text" class="kdna-ei-input" x-model="form.newChannel" placeholder="<?php esc_attr_e( 'For example Snapchat', 'kdna-ecommerce-insights' ); ?>" />
					</div>
				</div>

				<fieldset class="kdna-ei-spend-form__period">
					<legend class="kdna-ei-field-label"><?php esc_html_e( 'When', 'kdna-ecommerce-insights' ); ?></legend>
					<div class="kdna-ei-segmented" role="group">
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.period === 'day' ? 'true' : 'false'" @click="form.period = 'day'"><?php esc_html_e( 'One day', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.period === 'month' ? 'true' : 'false'" @click="form.period = 'month'"><?php esc_html_e( 'A month', 'kdna-ecommerce-insights' ); ?></button>
						<button type="button" class="kdna-ei-segmented__item" :aria-pressed="form.period === 'range' ? 'true' : 'false'" @click="form.period = 'range'"><?php esc_html_e( 'Date range', 'kdna-ecommerce-insights' ); ?></button>
					</div>
					<div class="kdna-ei-spend-form__row">
						<div x-show="form.period !== 'month'">
							<label for="kdna-ei-spend-start" class="kdna-ei-field-label" x-text="form.period === 'range' ? t.from : t.date"></label>
							<input id="kdna-ei-spend-start" type="date" class="kdna-ei-input" x-model="form.start" :class="{ 'is-invalid': form.errors.start }" />
						</div>
						<div x-show="form.period === 'range'" x-cloak>
							<label for="kdna-ei-spend-end" class="kdna-ei-field-label" x-text="t.to"></label>
							<input id="kdna-ei-spend-end" type="date" class="kdna-ei-input" x-model="form.end" :min="form.start" :class="{ 'is-invalid': form.errors.end }" />
						</div>
						<div x-show="form.period === 'month'" x-cloak>
							<label for="kdna-ei-spend-month" class="kdna-ei-field-label" x-text="t.month"></label>
							<input id="kdna-ei-spend-month" type="month" class="kdna-ei-input" x-model="form.month" />
						</div>
					</div>
					<p class="kdna-ei-field-error" x-show="form.errors.start || form.errors.end" x-text="form.errors.start || form.errors.end"></p>
				</fieldset>

				<div>
					<label for="kdna-ei-spend-campaign" class="kdna-ei-field-label"><?php esc_html_e( 'Campaign (optional)', 'kdna-ecommerce-insights' ); ?></label>
					<input id="kdna-ei-spend-campaign" type="text" class="kdna-ei-input" x-model="form.campaign" placeholder="<?php esc_attr_e( 'For example Spring launch', 'kdna-ecommerce-insights' ); ?>" />
				</div>

				<div>
					<label for="kdna-ei-spend-amount" class="kdna-ei-field-label"><?php esc_html_e( 'Amount spent', 'kdna-ecommerce-insights' ); ?></label>
					<label class="kdna-ei-affix kdna-ei-affix--before" :class="{ 'is-invalid': form.errors.amount }">
						<span class="kdna-ei-affix__before" aria-hidden="true"><?php echo esc_html( $kdna_ei_symbol ); ?></span>
						<input id="kdna-ei-spend-amount" type="text" inputmode="decimal" class="kdna-ei-input" x-model="form.amount" placeholder="0.00" />
					</label>
					<p class="kdna-ei-field-error" x-show="form.errors.amount" x-text="form.errors.amount"></p>
					<p class="kdna-ei-help" x-show="! form.errors.amount && spreadText" x-text="spreadText"></p>
				</div>

				<label class="kdna-ei-switch">
					<input type="checkbox" x-model="form.includesGst" />
					<span class="kdna-ei-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'This amount includes GST', 'kdna-ecommerce-insights' ); ?></span>
				</label>
				<p class="kdna-ei-help kdna-ei-spend-form__gst" x-text="gstText"></p>
			</div>
			<div class="kdna-ei-modal__footer">
				<button type="button" class="kdna-ei-btn" @click="closeForm()"><?php esc_html_e( 'Cancel', 'kdna-ecommerce-insights' ); ?></button>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="saveForm()" :disabled="form.busy" x-text="form.busy ? t.saving : ( form.group ? t.saveChanges : t.addSpend )"></button>
			</div>
		</div>
	</div>

	<?php // Set up a live connection. ?>
	<div class="kdna-ei-modal" x-show="conn.open" x-cloak x-transition.opacity @keydown.escape.window="conn.open && closeConnection()">
		<div class="kdna-ei-modal__backdrop" @click="closeConnection()"></div>
		<div class="kdna-ei-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-conn-title" tabindex="-1" x-ref="connDialog" @keydown.tab="trap( $event, 'connDialog' )">
			<div class="kdna-ei-modal__header">
				<h2 id="kdna-ei-conn-title" class="kdna-ei-card__title" x-text="conn.key === 'meta' ? t.metaTitle : t.googleTitle"></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closeConnection()" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>
			<div class="kdna-ei-modal__body kdna-ei-spend-form">
				<template x-if="conn.error">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="conn.error"></p></div>
				</template>

				<details class="kdna-ei-import-columns">
					<summary x-text="t.howToGet"></summary>
					<ol class="kdna-ei-steps" x-show="conn.key === 'meta'">
						<template x-for="( step, i ) in t.metaSteps" :key="i"><li x-text="step"></li></template>
					</ol>
					<ol class="kdna-ei-steps" x-show="conn.key === 'google'">
						<template x-for="( step, i ) in t.googleSteps" :key="i"><li x-text="step"></li></template>
					</ol>
				</details>

				<?php // Meta fields. ?>
				<template x-if="conn.key === 'meta'">
					<div class="kdna-ei-spend-form">
						<div class="kdna-ei-spend-form__row">
							<div>
								<label for="kdna-ei-meta-app" class="kdna-ei-field-label"><?php esc_html_e( 'App ID', 'kdna-ecommerce-insights' ); ?></label>
								<input id="kdna-ei-meta-app" type="text" inputmode="numeric" class="kdna-ei-input" x-model="conn.form.app_id" :class="{ 'is-invalid': conn.errors.app_id }" autocomplete="off" />
								<p class="kdna-ei-field-error" x-show="conn.errors.app_id" x-text="conn.errors.app_id"></p>
							</div>
							<div>
								<label for="kdna-ei-meta-account" class="kdna-ei-field-label"><?php esc_html_e( 'Ad account ID', 'kdna-ecommerce-insights' ); ?></label>
								<input id="kdna-ei-meta-account" type="text" class="kdna-ei-input" x-model="conn.form.ad_account_id" placeholder="act_1234567890" :class="{ 'is-invalid': conn.errors.ad_account_id }" autocomplete="off" />
								<p class="kdna-ei-field-error" x-show="conn.errors.ad_account_id" x-text="conn.errors.ad_account_id"></p>
							</div>
						</div>
						<div>
							<label for="kdna-ei-meta-token" class="kdna-ei-field-label"><?php esc_html_e( 'System user access token', 'kdna-ecommerce-insights' ); ?></label>
							<input id="kdna-ei-meta-token" type="password" class="kdna-ei-input" x-model="conn.form.token" :placeholder="conn.saved.has_token ? t.tokenSaved : ''" :class="{ 'is-invalid': conn.errors.token }" autocomplete="new-password" spellcheck="false" />
							<p class="kdna-ei-field-error" x-show="conn.errors.token" x-text="conn.errors.token"></p>
							<p class="kdna-ei-help" x-show="! conn.errors.token" x-text="conn.saved.has_token ? t.tokenKeep : t.tokenHelp"></p>
						</div>
					</div>
				</template>

				<?php // Google fields. ?>
				<template x-if="conn.key === 'google'">
					<div class="kdna-ei-spend-form">
						<div>
							<span class="kdna-ei-field-label"><?php esc_html_e( 'Redirect address for your OAuth client', 'kdna-ecommerce-insights' ); ?></span>
							<div class="kdna-ei-copy">
								<code x-text="conn.saved.redirect_uri"></code>
								<button type="button" class="kdna-ei-btn kdna-ei-btn--small" @click="copy( conn.saved.redirect_uri )" x-text="copied ? t.copied : t.copy"></button>
							</div>
							<p class="kdna-ei-help" x-text="t.redirectHelp"></p>
						</div>
						<div>
							<label for="kdna-ei-google-client" class="kdna-ei-field-label"><?php esc_html_e( 'OAuth client ID', 'kdna-ecommerce-insights' ); ?></label>
							<input id="kdna-ei-google-client" type="text" class="kdna-ei-input" x-model="conn.form.client_id" placeholder="123456789-abc.apps.googleusercontent.com" :class="{ 'is-invalid': conn.errors.client_id }" autocomplete="off" />
							<p class="kdna-ei-field-error" x-show="conn.errors.client_id" x-text="conn.errors.client_id"></p>
						</div>
						<div class="kdna-ei-spend-form__row">
							<div>
								<label for="kdna-ei-google-secret" class="kdna-ei-field-label"><?php esc_html_e( 'Client secret', 'kdna-ecommerce-insights' ); ?></label>
								<input id="kdna-ei-google-secret" type="password" class="kdna-ei-input" x-model="conn.form.client_secret" :placeholder="conn.saved.has_secret ? t.tokenSaved : ''" :class="{ 'is-invalid': conn.errors.client_secret }" autocomplete="new-password" spellcheck="false" />
								<p class="kdna-ei-field-error" x-show="conn.errors.client_secret" x-text="conn.errors.client_secret"></p>
							</div>
							<div>
								<label for="kdna-ei-google-dev" class="kdna-ei-field-label"><?php esc_html_e( 'Developer token', 'kdna-ecommerce-insights' ); ?></label>
								<input id="kdna-ei-google-dev" type="password" class="kdna-ei-input" x-model="conn.form.developer_token" :placeholder="conn.saved.has_developer_token ? t.tokenSaved : ''" :class="{ 'is-invalid': conn.errors.developer_token }" autocomplete="new-password" spellcheck="false" />
								<p class="kdna-ei-field-error" x-show="conn.errors.developer_token" x-text="conn.errors.developer_token"></p>
							</div>
						</div>
						<div class="kdna-ei-spend-form__row">
							<div>
								<label for="kdna-ei-google-customer" class="kdna-ei-field-label"><?php esc_html_e( 'Customer ID', 'kdna-ecommerce-insights' ); ?></label>
								<input id="kdna-ei-google-customer" type="text" class="kdna-ei-input" x-model="conn.form.customer_id" placeholder="123-456-7890" :class="{ 'is-invalid': conn.errors.customer_id }" autocomplete="off" />
								<p class="kdna-ei-field-error" x-show="conn.errors.customer_id" x-text="conn.errors.customer_id"></p>
							</div>
							<div>
								<label for="kdna-ei-google-login" class="kdna-ei-field-label"><?php esc_html_e( 'Manager account ID (optional)', 'kdna-ecommerce-insights' ); ?></label>
								<input id="kdna-ei-google-login" type="text" class="kdna-ei-input" x-model="conn.form.login_customer_id" placeholder="987-654-3210" :class="{ 'is-invalid': conn.errors.login_customer_id }" autocomplete="off" />
								<p class="kdna-ei-field-error" x-show="conn.errors.login_customer_id" x-text="conn.errors.login_customer_id"></p>
							</div>
						</div>
						<p class="kdna-ei-help" x-text="conn.saved.signed_in ? t.signedIn : t.signInAfter"></p>
					</div>
				</template>

				<?php // Shared: currency rate and how often to sync. ?>
				<div class="kdna-ei-spend-form__row kdna-ei-conn-shared">
					<div>
						<label for="kdna-ei-conn-rate" class="kdna-ei-field-label" x-text="t.rateField"></label>
						<input id="kdna-ei-conn-rate" type="text" inputmode="decimal" class="kdna-ei-input" x-model="conn.rate" :class="{ 'is-invalid': conn.errors.rate }" />
						<p class="kdna-ei-field-error" x-show="conn.errors.rate" x-text="conn.errors.rate"></p>
						<p class="kdna-ei-help" x-show="! conn.errors.rate" x-text="t.rateFieldHelp"></p>
					</div>
					<div>
						<label for="kdna-ei-conn-frequency" class="kdna-ei-field-label" x-text="t.frequency"></label>
						<select id="kdna-ei-conn-frequency" class="kdna-ei-select" x-model="conn.frequency">
							<option value="daily" x-text="t.daily"></option>
							<option value="twice_daily" x-text="t.twiceDaily"></option>
							<option value="manual" x-text="t.manualOnly"></option>
						</select>
					</div>
				</div>
			</div>
			<div class="kdna-ei-modal__footer">
				<button type="button" class="kdna-ei-btn" @click="closeConnection()"><?php esc_html_e( 'Cancel', 'kdna-ecommerce-insights' ); ?></button>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="saveConnection()" :disabled="conn.busy" x-text="conn.busy ? t.saving : ( conn.key === 'google' && ! conn.saved.signed_in ? t.saveAndSignIn : t.saveAndTest )"></button>
			</div>
		</div>
	</div>

	<?php // CSV import: choose a file, check the columns and preview, done. ?>
	<div class="kdna-ei-modal" x-show="csv.open" x-cloak x-transition.opacity @keydown.escape.window="csv.open && closeImport()">
		<div class="kdna-ei-modal__backdrop" @click="closeImport()"></div>
		<div class="kdna-ei-modal__dialog kdna-ei-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="kdna-ei-spend-import-title" tabindex="-1" x-ref="importDialog" @keydown.tab="trap( $event, 'importDialog' )">
			<div class="kdna-ei-modal__header">
				<h2 id="kdna-ei-spend-import-title" class="kdna-ei-card__title"><?php esc_html_e( 'Import ad spend from a CSV', 'kdna-ecommerce-insights' ); ?></h2>
				<button type="button" class="kdna-ei-btn kdna-ei-btn--icon" @click="closeImport()" :disabled="csv.busy" aria-label="<?php esc_attr_e( 'Close', 'kdna-ecommerce-insights' ); ?>">&times;</button>
			</div>

			<div class="kdna-ei-modal__body">
				<template x-if="csv.error">
					<div class="kdna-ei-notice kdna-ei-notice--negative" role="alert"><p x-text="csv.error"></p></div>
				</template>

				<?php // Step 1: the file. ?>
				<div x-show="csv.step === 'choose'" class="kdna-ei-import-choose">
					<p class="kdna-ei-muted" x-text="t.importIntro"></p>
					<ul class="kdna-ei-import-tips">
						<li x-text="t.tipMeta"></li>
						<li x-text="t.tipGoogle"></li>
						<li x-text="t.tipOther"></li>
					</ul>
					<label class="kdna-ei-dropzone" :class="{ 'is-busy': csv.busy }">
						<input type="file" accept=".csv,.tsv,.txt,text/csv" class="kdna-ei-visually-hidden" @change="chooseFile( $event )" :disabled="csv.busy" />
						<?php Admin::icon( 'upload', 'kdna-ei-icon--lg' ); ?>
						<span x-show="! csv.busy"><?php esc_html_e( 'Choose a CSV file', 'kdna-ecommerce-insights' ); ?></span>
						<span x-show="csv.busy" x-text="sprintf( t.reading, csv.fileName )"></span>
						<span class="kdna-ei-muted"><?php esc_html_e( 'Up to 5 MB', 'kdna-ecommerce-insights' ); ?></span>
					</label>
				</div>

				<?php // Step 2: channel, columns and preview. ?>
				<template x-if="csv.step === 'map' && csv.preview">
					<div class="kdna-ei-import-map">
						<div class="kdna-ei-import-map__top">
							<div>
								<label for="kdna-ei-import-channel" class="kdna-ei-field-label"><?php esc_html_e( 'Channel', 'kdna-ecommerce-insights' ); ?></label>
								<select id="kdna-ei-import-channel" class="kdna-ei-select" x-model="csv.channel" @change="refreshPreview()">
									<option value=""><?php esc_html_e( 'Choose a channel', 'kdna-ecommerce-insights' ); ?></option>
									<template x-for="channel in channels" :key="channel.key">
										<option :value="channel.key" x-text="channel.label" :selected="csv.channel === channel.key"></option>
									</template>
								</select>
							</div>
							<div>
								<label for="kdna-ei-import-preset" class="kdna-ei-field-label"><?php esc_html_e( 'Column layout', 'kdna-ecommerce-insights' ); ?></label>
								<select id="kdna-ei-import-preset" class="kdna-ei-select" x-model="csv.preset" @change="applyPreset()">
									<option value=""><?php esc_html_e( 'Match columns myself', 'kdna-ecommerce-insights' ); ?></option>
									<template x-for="preset in presets" :key="preset.key">
										<option :value="preset.key" x-text="preset.name" :selected="csv.preset === preset.key"></option>
									</template>
								</select>
							</div>
							<div class="kdna-ei-import-map__file">
								<span class="kdna-ei-field-label"><?php esc_html_e( 'File', 'kdna-ecommerce-insights' ); ?></span>
								<span class="kdna-ei-import-map__name" x-text="csv.fileName"></span>
							</div>
						</div>

						<template x-if="csv.preview.preset && csv.preset === csv.preview.preset && isBuiltin( csv.preset )">
							<p class="kdna-ei-notice kdna-ei-notice--positive kdna-ei-import-detected" x-text="sprintf( t.detected, presetName( csv.preset ) )"></p>
						</template>

						<details class="kdna-ei-import-columns" :open="csv.showColumns || needsMapping">
							<summary x-text="t.checkColumns"></summary>
							<div class="kdna-ei-import-columns__grid">
								<template x-for="( field, key ) in fields" :key="key">
									<label class="kdna-ei-import-column">
										<span class="kdna-ei-field-label"><span x-text="field.label"></span><span x-show="field.required" class="kdna-ei-required" aria-hidden="true"> *</span></span>
										<select class="kdna-ei-select" x-model="csv.mapping[ key ]" @change="mappingChanged()">
											<option value="" x-text="field.required ? t.chooseColumn : t.notInFile"></option>
											<template x-for="column in csv.preview.header" :key="column">
												<option :value="column" x-text="column" :selected="csv.mapping[ key ] === column"></option>
											</template>
										</select>
									</label>
								</template>
							</div>
						</details>

						<div class="kdna-ei-import-options">
							<template x-if="csv.preview.currency && csv.preview.currency !== csv.preview.store_currency">
								<div class="kdna-ei-import-rate">
									<label for="kdna-ei-import-rate" class="kdna-ei-field-label" x-text="sprintf( t.rateLabel, csv.preview.currency, csv.preview.store_currency )"></label>
									<input id="kdna-ei-import-rate" type="text" inputmode="decimal" class="kdna-ei-input" x-model="csv.rate" @input.debounce.500ms="refreshPreview()" />
									<p class="kdna-ei-help" x-text="sprintf( t.rateHelp, csv.preview.currency, csv.preview.store_currency )"></p>
								</div>
							</template>
							<label class="kdna-ei-switch">
								<input type="checkbox" x-model="csv.includesGst" />
								<span class="kdna-ei-switch__track" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Amounts in this file include GST', 'kdna-ecommerce-insights' ); ?></span>
							</label>
						</div>

						<?php // Summary and preview. ?>
						<div class="kdna-ei-import-summary" x-show="csv.preview.totals.rows">
							<div><span class="kdna-ei-muted" x-text="t.totalSpend"></span><strong class="kdna-ei-num" x-text="money( csv.preview.totals.spend )"></strong></div>
							<div><span class="kdna-ei-muted" x-text="t.dates"></span><strong x-text="dateRange( csv.preview.start, csv.preview.end )"></strong></div>
							<div><span class="kdna-ei-muted" x-text="t.rows"></span><strong class="kdna-ei-num" x-text="formatNumber( csv.preview.totals.rows )"></strong></div>
							<div><span class="kdna-ei-muted" x-text="t.campaigns"></span><strong class="kdna-ei-num" x-text="formatNumber( csv.preview.totals.campaigns )"></strong></div>
						</div>

						<template x-if="csv.preview.blocked">
							<p class="kdna-ei-notice kdna-ei-notice--negative" role="alert" x-text="csv.preview.blocked"></p>
						</template>
						<template x-if="csv.preview.replaces > 0">
							<p class="kdna-ei-notice kdna-ei-notice--warning" x-text="sprintf( t.replaces, money( csv.preview.replaces ), channelLabel( csv.channel ), dateRange( csv.preview.start, csv.preview.end ) )"></p>
						</template>

						<template x-if="csv.preview.error_count">
							<div class="kdna-ei-notice kdna-ei-notice--warning">
								<p x-text="sprintf( csv.preview.error_count === 1 ? t.problemOne : t.problems, csv.preview.error_count )"></p>
								<ul class="kdna-ei-notice__list">
									<template x-for="( problem, i ) in csv.preview.errors" :key="i">
										<li x-text="problem.message"></li>
									</template>
								</ul>
							</div>
						</template>

						<div class="kdna-ei-table-wrap" x-show="csv.preview.lines.length">
							<table class="kdna-ei-table kdna-ei-table--compact">
								<caption class="kdna-ei-visually-hidden"><?php esc_html_e( 'First rows of the file as they will be imported', 'kdna-ecommerce-insights' ); ?></caption>
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Dates', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Campaign', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col" class="is-numeric"><?php esc_html_e( 'Spend', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col" class="is-numeric"><?php esc_html_e( 'Clicks', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col" class="is-numeric"><?php esc_html_e( 'Purchases', 'kdna-ecommerce-insights' ); ?></th>
										<th scope="col" class="is-numeric"><?php esc_html_e( 'Purchase value', 'kdna-ecommerce-insights' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<template x-for="line in csv.preview.lines" :key="line.line">
										<tr>
											<td class="kdna-ei-nowrap" x-text="dateRange( line.start, line.end )"></td>
											<td x-text="line.campaign_name || '–'"></td>
											<td class="is-numeric kdna-ei-num" x-text="money( line.spend )"></td>
											<td class="is-numeric kdna-ei-num" x-text="formatNumber( line.clicks )"></td>
											<td class="is-numeric kdna-ei-num" x-text="formatNumber( line.conversions )"></td>
											<td class="is-numeric kdna-ei-num" x-text="money( line.conversion_value )"></td>
										</tr>
									</template>
								</tbody>
							</table>
							<p class="kdna-ei-help" x-show="csv.preview.totals.rows > csv.preview.lines.length" x-text="sprintf( t.firstRows, csv.preview.lines.length, formatNumber( csv.preview.totals.rows ) )"></p>
						</div>

						<div class="kdna-ei-import-preset-save" x-show="! isBuiltin( csv.preset ) || mappingEdited">
							<label class="kdna-ei-switch">
								<input type="checkbox" x-model="csv.savePreset" />
								<span class="kdna-ei-switch__track" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Remember these columns as a preset', 'kdna-ecommerce-insights' ); ?></span>
							</label>
							<input type="text" class="kdna-ei-input" x-show="csv.savePreset" x-model="csv.presetName" :placeholder="t.presetPlaceholder" aria-label="<?php esc_attr_e( 'Preset name', 'kdna-ecommerce-insights' ); ?>" />
						</div>
					</div>
				</template>

				<?php // Step 3: done. ?>
				<template x-if="csv.step === 'done' && csv.result">
					<div class="kdna-ei-import-done">
						<span class="kdna-ei-empty-state__icon" aria-hidden="true"><?php Admin::icon( 'check', 'kdna-ei-icon--lg' ); ?></span>
						<h3 class="kdna-ei-card__title" x-text="sprintf( t.importedTitle, money( csv.result.spend ) )"></h3>
						<p class="kdna-ei-muted" x-text="sprintf( t.importedText, formatNumber( csv.result.rows ), channelLabel( csv.channel ), dateRange( csv.result.start, csv.result.end ) )"></p>
						<p class="kdna-ei-muted" x-show="csv.result.replaced > 0" x-text="sprintf( t.importedReplaced, money( csv.result.replaced ) )"></p>
					</div>
				</template>
			</div>

			<div class="kdna-ei-modal__footer">
				<template x-if="csv.step === 'map'">
					<button type="button" class="kdna-ei-btn" @click="csv.step = 'choose'; csv.preview = null" :disabled="csv.busy"><?php esc_html_e( 'Choose another file', 'kdna-ecommerce-insights' ); ?></button>
				</template>
				<template x-if="csv.step === 'map'">
					<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="runImport()" :disabled="csv.busy || ! canImport" x-text="csv.busy ? t.importing : ( csv.preview && csv.preview.totals.rows ? sprintf( t.importButton, money( csv.preview.totals.spend ) ) : t.importPlain )"></button>
				</template>
				<template x-if="csv.step !== 'map'">
					<button type="button" class="kdna-ei-btn" :class="{ 'kdna-ei-btn--primary': csv.step === 'done' }" @click="closeImport()" :disabled="csv.busy" x-text="csv.step === 'done' ? t.done : t.cancel"></button>
				</template>
			</div>
		</div>
	</div>
</div>
