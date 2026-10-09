<?php
/**
 * The Insights app shell: sidebar, top bar and one section per screen.
 *
 * Variables provided by KDNA_EcommerceInsights_Admin::render_app():
 *
 * @var array   $screens    Screens keyed by ID (label, icon, layout).
 * @var array   $prefs      Current user's preferences.
 * @var string  $store_name Store name for the eyebrow.
 * @var string  $logo_url   Branding logo URL, or empty.
 * @var array   $ranges     Date range presets.
 * @var WP_User $user       Current user.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

$comparisons   = KDNA_EcommerceInsights_Settings::comparison_modes();
$first_screen  = (string) array_key_first( $screens );
$current_label = $screens[ $first_screen ]['label'] ?? '';
$initial       = function_exists( 'mb_substr' ) ? mb_substr( $store_name, 0, 1 ) : substr( $store_name, 0, 1 );
$layouts       = array( 'overview', 'report', 'table', 'settings' );
?>
<div
	class="kdna-ei-root kdna-ei-app"
	data-kdna-ei-theme="<?php echo esc_attr( $prefs['theme'] ); ?>"
	x-data="kdnaEiApp"
	:data-kdna-ei-theme="theme"
	@keydown.escape.window="closeMenus()"
>
	<?php KDNA_EcommerceInsights_Admin::render_icon_sprite(); ?>

	<nav class="kdna-ei-sidebar" aria-label="<?php esc_attr_e( 'Insights screens', 'kdna-ecommerce-insights' ); ?>">
		<div class="kdna-ei-sidebar__brand" title="<?php echo esc_attr( $store_name ); ?>">
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" />
			<?php else : ?>
				<span aria-hidden="true"><?php echo esc_html( strtoupper( $initial ) ); ?></span>
			<?php endif; ?>
		</div>

		<ul class="kdna-ei-nav">
			<?php foreach ( $screens as $id => $screen ) : ?>
				<li>
					<a
						href="#/<?php echo esc_attr( $id ); ?>"
						class="kdna-ei-nav__link<?php echo $id === $first_screen ? ' is-active' : ''; ?>"
						:class="{ 'is-active': isActive( '<?php echo esc_js( $id ); ?>' ) }"
						:aria-current="isActive( '<?php echo esc_js( $id ); ?>' ) ? 'page' : false"
						aria-label="<?php echo esc_attr( $screen['label'] ); ?>"
					>
						<?php KDNA_EcommerceInsights_Admin::icon( $screen['icon'] ); ?>
						<span class="kdna-ei-nav__tooltip" aria-hidden="true"><?php echo esc_html( $screen['label'] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="kdna-ei-sidebar__footer">
			<a href="<?php echo esc_url( admin_url() ); ?>" class="kdna-ei-nav__link" aria-label="<?php esc_attr_e( 'Back to the WordPress dashboard', 'kdna-ecommerce-insights' ); ?>">
				<?php KDNA_EcommerceInsights_Admin::icon( 'arrow-left' ); ?>
				<span class="kdna-ei-nav__tooltip" aria-hidden="true"><?php esc_html_e( 'WordPress dashboard', 'kdna-ecommerce-insights' ); ?></span>
			</a>
			<img class="kdna-ei-avatar" src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 56 ) ) ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>" title="<?php echo esc_attr( $user->display_name ); ?>" width="28" height="28" />
		</div>
	</nav>

	<main class="kdna-ei-main">
		<header class="kdna-ei-topbar">
			<div class="kdna-ei-topbar__heading">
				<p class="kdna-ei-eyebrow">
					<span><?php echo esc_html( $store_name ); ?></span>
					<span aria-hidden="true">&nbsp;/&nbsp;</span>
					<span x-text="screenLabel"><?php echo esc_html( $current_label ); ?></span>
				</p>
				<h1 class="kdna-ei-title" x-ref="title" tabindex="-1" x-text="screenLabel"><?php echo esc_html( $current_label ); ?></h1>
			</div>

			<div class="kdna-ei-topbar__actions">
				<div class="kdna-ei-dropdown" @click.outside="rangeOpen = false">
					<button
						type="button"
						class="kdna-ei-btn kdna-ei-dropdown__trigger"
						x-ref="rangeTrigger"
						aria-haspopup="menu"
						:aria-expanded="rangeOpen ? 'true' : 'false'"
						aria-expanded="false"
						aria-controls="kdna-ei-range-menu"
						@click="toggleRangeMenu()"
					>
						<?php KDNA_EcommerceInsights_Admin::icon( 'calendar', 'kdna-ei-icon--sm kdna-ei-dropdown__lead' ); ?>
						<span x-text="rangeLabel"><?php echo esc_html( $ranges[ $prefs['range'] ] ?? '' ); ?></span>
						<?php KDNA_EcommerceInsights_Admin::icon( 'chevron-down' ); ?>
					</button>

					<div id="kdna-ei-range-menu" class="kdna-ei-dropdown__menu" role="menu" x-show="rangeOpen" x-cloak x-transition.opacity.duration.150ms>
						<div class="kdna-ei-dropdown__group" role="group" aria-labelledby="kdna-ei-range-heading">
							<p id="kdna-ei-range-heading" class="kdna-ei-dropdown__heading"><?php esc_html_e( 'Date range', 'kdna-ecommerce-insights' ); ?></p>
							<?php foreach ( $ranges as $key => $label ) : ?>
								<button
									type="button"
									class="kdna-ei-dropdown__item"
									role="menuitemradio"
									:aria-checked="range === '<?php echo esc_js( $key ); ?>' ? 'true' : 'false'"
									@click="selectRange( '<?php echo esc_js( $key ); ?>' )"
								>
									<span><?php echo esc_html( $label ); ?></span>
									<?php KDNA_EcommerceInsights_Admin::icon( 'check' ); ?>
								</button>
							<?php endforeach; ?>

							<?php // Custom range: two dates and Apply. ?>
							<div class="kdna-ei-custom-range" x-show="customOpen" x-cloak>
								<label>
									<span class="kdna-ei-field-label"><?php esc_html_e( 'From', 'kdna-ecommerce-insights' ); ?></span>
									<input type="date" class="kdna-ei-input" x-model="customStart" :max="customEnd || undefined" />
								</label>
								<label>
									<span class="kdna-ei-field-label"><?php esc_html_e( 'To', 'kdna-ecommerce-insights' ); ?></span>
									<input type="date" class="kdna-ei-input" x-model="customEnd" :min="customStart || undefined" />
								</label>
								<p class="kdna-ei-field-error" x-show="customError" x-text="customError"></p>
								<button type="button" class="kdna-ei-btn kdna-ei-btn--primary" @click="applyCustom()"><?php esc_html_e( 'Apply', 'kdna-ecommerce-insights' ); ?></button>
							</div>
						</div>

						<div class="kdna-ei-dropdown__group" role="group" aria-labelledby="kdna-ei-compare-heading">
							<p id="kdna-ei-compare-heading" class="kdna-ei-dropdown__heading"><?php esc_html_e( 'Compare to', 'kdna-ecommerce-insights' ); ?></p>
							<?php foreach ( $comparisons as $key => $label ) : ?>
								<button
									type="button"
									class="kdna-ei-dropdown__item"
									role="menuitemradio"
									:aria-checked="comparison === '<?php echo esc_js( $key ); ?>' ? 'true' : 'false'"
									@click="selectComparison( '<?php echo esc_js( $key ); ?>' )"
								>
									<span><?php echo esc_html( $label ); ?></span>
									<?php KDNA_EcommerceInsights_Admin::icon( 'check' ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<?php // Export menu: every table on the current screen, plus the printable report. ?>
				<div class="kdna-ei-dropdown kdna-ei-export-menu" @click.outside="exportOpen = false">
					<button
						type="button"
						class="kdna-ei-btn kdna-ei-dropdown__trigger"
						x-ref="exportTrigger"
						aria-haspopup="menu"
						:aria-expanded="exportOpen ? 'true' : 'false'"
						aria-expanded="false"
						aria-controls="kdna-ei-export-menu"
						@click="toggleExportMenu()"
					>
						<?php KDNA_EcommerceInsights_Admin::icon( 'download', 'kdna-ei-icon--sm kdna-ei-dropdown__lead' ); ?>
						<span class="kdna-ei-export-menu__label"><?php esc_html_e( 'Export', 'kdna-ecommerce-insights' ); ?></span>
						<?php KDNA_EcommerceInsights_Admin::icon( 'chevron-down' ); ?>
					</button>

					<div id="kdna-ei-export-menu" class="kdna-ei-dropdown__menu kdna-ei-export-menu__list" role="menu" x-show="exportOpen" x-cloak x-transition.opacity.duration.150ms>
						<div class="kdna-ei-dropdown__group" role="group" aria-labelledby="kdna-ei-export-heading" x-show="exportList.length">
							<p id="kdna-ei-export-heading" class="kdna-ei-dropdown__heading"><?php esc_html_e( 'Download as CSV', 'kdna-ecommerce-insights' ); ?></p>
							<template x-for="table in exportList" :key="table.key">
								<button type="button" class="kdna-ei-dropdown__item" role="menuitem" @click="exportTable( table.key )" :disabled="exportBusy !== ''">
									<span x-text="table.label"></span>
									<span class="kdna-ei-muted kdna-ei-export-menu__busy" x-show="exportBusy === table.key"><?php esc_html_e( 'Preparing', 'kdna-ecommerce-insights' ); ?></span>
								</button>
							</template>
						</div>
						<div class="kdna-ei-dropdown__group" role="group" aria-labelledby="kdna-ei-print-heading">
							<p id="kdna-ei-print-heading" class="kdna-ei-dropdown__heading"><?php esc_html_e( 'Report', 'kdna-ecommerce-insights' ); ?></p>
							<button type="button" class="kdna-ei-dropdown__item" role="menuitem" @click="printReport()">
								<span><?php esc_html_e( 'Printable report (save as PDF)', 'kdna-ecommerce-insights' ); ?></span>
							</button>
							<a href="#/reports" class="kdna-ei-dropdown__item" role="menuitem" @click="exportOpen = false" x-show="route !== 'reports'">
								<span><?php esc_html_e( 'Every export, on Tax & Reports', 'kdna-ecommerce-insights' ); ?></span>
							</a>
						</div>
						<p class="kdna-ei-field-error kdna-ei-export-menu__error" role="alert" x-show="exportError" x-text="exportError"></p>
					</div>
				</div>

				<button
					type="button"
					class="kdna-ei-btn kdna-ei-btn--icon"
					@click="toggleTheme()"
					:aria-label="themeLabel"
					:title="themeLabel"
					aria-label="<?php esc_attr_e( 'Switch between light and dark mode', 'kdna-ecommerce-insights' ); ?>"
				>
					<span x-show="theme === 'dark'"<?php echo 'light' === $prefs['theme'] ? ' style="display: none;"' : ''; ?>><?php KDNA_EcommerceInsights_Admin::icon( 'sun' ); ?></span>
					<span x-show="theme === 'light'"<?php echo 'light' !== $prefs['theme'] ? ' style="display: none;"' : ''; ?>><?php KDNA_EcommerceInsights_Admin::icon( 'moon' ); ?></span>
				</button>

				<button
					type="button"
					class="kdna-ei-btn kdna-ei-btn--icon"
					@click="toggleFocus()"
					:aria-pressed="focus ? 'true' : 'false'"
					:aria-label="focusLabel"
					:title="focusLabel"
					aria-label="<?php esc_attr_e( 'Focus Mode', 'kdna-ecommerce-insights' ); ?>"
				>
					<span x-show="! focus"<?php echo $prefs['focus'] ? ' style="display: none;"' : ''; ?>><?php KDNA_EcommerceInsights_Admin::icon( 'maximise' ); ?></span>
					<span x-show="focus"<?php echo ! $prefs['focus'] ? ' style="display: none;"' : ''; ?>><?php KDNA_EcommerceInsights_Admin::icon( 'minimise' ); ?></span>
				</button>
			</div>
		</header>

		<?php // Background job progress, shown on every screen while orders are being processed. ?>
		<div class="kdna-ei-jobbar" x-show="jobRunning || jobJustFinished" x-cloak x-transition.opacity role="status" aria-live="polite">
			<div class="kdna-ei-jobbar__text">
				<template x-if="jobRunning">
					<span><strong x-text="jobTitle"></strong> <span class="kdna-ei-muted kdna-ei-num" x-text="jobDetail"></span></span>
				</template>
				<template x-if="! jobRunning && jobJustFinished">
					<strong><?php esc_html_e( 'All done. Your figures are up to date.', 'kdna-ecommerce-insights' ); ?></strong>
				</template>
			</div>
			<div class="kdna-ei-progress kdna-ei-jobbar__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="status.job ? status.job.percent : 0">
				<span class="kdna-ei-progress__bar" :style="'width:' + ( jobJustFinished && ! jobRunning ? 100 : ( status.job ? status.job.percent : 0 ) ) + '%'"></span>
			</div>
		</div>

		<?php foreach ( $screens as $id => $screen ) : ?>
			<?php
			$layout      = in_array( $screen['layout'], $layouts, true ) ? $screen['layout'] : 'report';
			$screen_view = KDNA_EI_PATH . 'admin/views/screens/' . sanitize_key( $id ) . '.php';
			$is_built    = is_readable( $screen_view );
			?>
			<section
				id="kdna-ei-screen-<?php echo esc_attr( $id ); ?>"
				class="kdna-ei-screen <?php echo $is_built ? 'kdna-ei-screen--' . esc_attr( $id ) : 'kdna-ei-layout-' . esc_attr( $layout ); ?>"
				aria-label="<?php echo esc_attr( $screen['label'] ); ?>"
				<?php echo $is_built ? '' : 'aria-busy="true"'; ?>
				x-show="isActive( '<?php echo esc_js( $id ); ?>' )"
				<?php echo $id !== $first_screen ? 'style="display: none;"' : ''; ?>
			>
				<?php if ( $is_built ) : ?>
					<?php include $screen_view; ?>
				<?php else : ?>
					<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Loading', 'kdna-ecommerce-insights' ); ?></span>
					<?php include KDNA_EI_PATH . 'admin/views/partials/skeleton-' . $layout . '.php'; ?>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
	</main>

	<?php // Debug panel: only with ?kdna_ei_debug=1 on the Insights page, Administrators only. ?>
	<template x-if="debug">
		<aside class="kdna-ei-debug" x-data="{ open: true, entries: [] }" @kdna:ei-debug.window="entries = [ $event.detail ].concat( entries ).slice( 0, 15 )" aria-label="<?php esc_attr_e( 'Debug panel', 'kdna-ecommerce-insights' ); ?>">
			<button type="button" class="kdna-ei-debug__toggle" @click="open = ! open">
				<?php esc_html_e( 'Debug', 'kdna-ecommerce-insights' ); ?>
				<span class="kdna-ei-num" x-text="entries.length ? entries[0].total_ms + ' ms' : ''"></span>
			</button>
			<div class="kdna-ei-debug__body" x-show="open">
				<p class="kdna-ei-muted" x-show="! entries.length"><?php esc_html_e( 'Query timings appear here as screens load data.', 'kdna-ecommerce-insights' ); ?></p>
				<template x-for="( entry, index ) in entries" :key="index + entry.route + entry.total_ms">
					<details class="kdna-ei-debug__entry" :open="index === 0">
						<summary><strong x-text="'/' + entry.route"></strong> <span class="kdna-ei-num" x-text="entry.total_ms + ' ms, ' + entry.queries.length + ' queries, ' + entry.memory"></span></summary>
						<table>
							<template x-for="( query, q ) in entry.queries" :key="q">
								<tr><td x-text="query.label"></td><td class="kdna-ei-num" x-text="query.ms + ' ms'"></td><td class="kdna-ei-num kdna-ei-muted" x-text="query.rows + ' rows'"></td></tr>
							</template>
						</table>
					</details>
				</template>
			</div>
		</aside>
	</template>
</div>
