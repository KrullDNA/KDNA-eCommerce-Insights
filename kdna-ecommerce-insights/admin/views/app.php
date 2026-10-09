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

// The custom range needs the calendar picker, which arrives with the Overview screen build.
unset( $ranges['custom'] );

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

		<?php foreach ( $screens as $id => $screen ) : ?>
			<?php $layout = in_array( $screen['layout'], $layouts, true ) ? $screen['layout'] : 'report'; ?>
			<section
				id="kdna-ei-screen-<?php echo esc_attr( $id ); ?>"
				class="kdna-ei-screen kdna-ei-layout-<?php echo esc_attr( $layout ); ?>"
				aria-label="<?php echo esc_attr( $screen['label'] ); ?>"
				aria-busy="true"
				x-show="isActive( '<?php echo esc_js( $id ); ?>' )"
				<?php echo $id !== $first_screen ? 'style="display: none;"' : ''; ?>
			>
				<span class="kdna-ei-visually-hidden"><?php esc_html_e( 'Loading', 'kdna-ecommerce-insights' ); ?></span>
				<?php include KDNA_EI_PATH . 'admin/views/partials/skeleton-' . $layout . '.php'; ?>
			</section>
		<?php endforeach; ?>
	</main>
</div>
