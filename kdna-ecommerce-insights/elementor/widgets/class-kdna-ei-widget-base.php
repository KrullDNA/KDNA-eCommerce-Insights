<?php
/**
 * Base class for every Insights Elementor widget.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Icons_Manager;

/**
 * What every Insights widget shares:
 *
 * - The KDNA Tools category and the shared stylesheet and script, which
 *   Elementor loads only on pages that use the widget.
 * - Atomic markup: one wrapper div per widget, and no extra inner wrapper
 *   when Elementor's optimised markup is on.
 * - Privacy (section 9.1): figures only for logged-in Administrators.
 *   Everyone else gets a Restricted card or nothing, and no store details
 *   are printed for them. The widget is never cached by Elementor's
 *   element cache, because what it shows depends on who is looking.
 * - The editor-only Preview state, and the theme choice.
 */
abstract class KDNA_EcommerceInsights_Widget_Base extends \Elementor\Widget_Base {

	/**
	 * The widget type used in data-kdna-ei-widget, such as "dashboard".
	 *
	 * @return string
	 */
	abstract protected function widget_type(): string;

	/**
	 * Prints the widget for someone allowed to see figures (or the editor).
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    live, sample, loading, empty or error.
	 */
	abstract protected function render_widget( array $settings, string $state ): void;

	/**
	 * Every Insights widget sits in the KDNA Tools category.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( KDNA_EcommerceInsights_Elementor::CATEGORY );
	}

	/**
	 * Search words for the widget panel.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'kdna', 'insights', 'woocommerce', 'profit', 'dashboard', 'report' );
	}

	/**
	 * The stylesheet, loaded only on pages that use an Insights widget.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'kdna-ei-widgets' );
	}

	/**
	 * The script, only for people allowed to see figures (and the editor).
	 *
	 * @return string[]
	 */
	public function get_script_depends(): array {
		return KDNA_EcommerceInsights_Elementor::script_handles();
	}

	/**
	 * Atomic markup: no inner wrapper div when optimised markup is on.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		$plugin = \Elementor\Plugin::$instance;
		return ! ( isset( $plugin->experiments ) && $plugin->experiments->is_feature_active( 'e_optimized_markup' ) );
	}

	/**
	 * What the widget shows depends on who is looking, so Elementor must
	 * never cache its output.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Which state to show: the editor's Preview state inside the editor,
	 * otherwise live figures for Administrators, and the Restricted card or
	 * nothing for everyone else.
	 *
	 * @param array $settings Widget settings.
	 * @return string live, sample, loading, empty, error, restricted or nothing.
	 */
	protected function state( array $settings ): string {
		$restricted = 'nothing' === ( $settings['kdna_restricted_mode'] ?? 'message' ) ? 'nothing' : 'restricted';

		if ( KDNA_EcommerceInsights_Elementor::is_editor() ) {
			$preview = (string) ( $settings['kdna_preview'] ?? 'live' );
			if ( 'restricted' === $preview ) {
				return $restricted;
			}
			if ( 'live' === $preview && ! KDNA_EcommerceInsights_Elementor::can_view() ) {
				return 'sample';
			}
			return in_array( $preview, array( 'live', 'sample', 'loading', 'empty', 'error' ), true ) ? $preview : 'live';
		}

		return KDNA_EcommerceInsights_Elementor::can_view() ? 'live' : $restricted;
	}

	/**
	 * The light or dark theme to start in.
	 *
	 * @param array $settings Widget settings.
	 * @return string dark or light.
	 */
	protected function theme( array $settings ): string {
		$mode = (string) ( $settings['kdna_theme'] ?? 'admin' );
		if ( in_array( $mode, array( 'dark', 'light' ), true ) ) {
			return $mode;
		}
		if ( KDNA_EcommerceInsights_Elementor::can_view() ) {
			return 'light' === KDNA_EcommerceInsights_Preferences::get()['theme'] ? 'light' : 'dark';
		}
		return 'light' === KDNA_EcommerceInsights_Settings::get( 'branding.default_theme', 'dark' ) ? 'light' : 'dark';
	}

	/**
	 * Prints the widget: the one wrapper div, then the right state.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$state    = $this->state( $settings );

		KDNA_EcommerceInsights_Elementor::widget_rendered();

		if ( 'nothing' === $state ) {
			// Visitors see nothing at all; the editor gets a note so the widget can be found.
			if ( KDNA_EcommerceInsights_Elementor::is_editor() ) {
				printf(
					'<div class="kdna-ei-root kdna-ei-w kdna-ei-w--note" data-kdna-ei-theme="%1$s"><p class="kdna-ei-w__note">%2$s</p></div>',
					esc_attr( $this->theme( $settings ) ),
					esc_html__( 'Visitors without access see nothing here.', 'kdna-ecommerce-insights' )
				);
			}
			return;
		}

		if ( 'restricted' === $state ) {
			printf(
				'<div class="kdna-ei-root kdna-ei-w kdna-ei-w--%1$s" data-kdna-ei-theme="%2$s" data-kdna-ei-state="restricted">',
				esc_attr( $this->widget_type() ),
				esc_attr( $this->theme( $settings ) )
			);
			$this->render_restricted( $settings );
			echo '</div>';
			return;
		}

		$this->render_widget( $settings, $state );
	}

	/**
	 * Opens the widget's single wrapper div with everything the script needs.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 * @param array  $data     Settings for the script (no store figures).
	 * @param string $class    Extra classes.
	 */
	protected function open_root( array $settings, string $state, array $data, string $class = '' ): void {
		$data['state']  = $state;
		$data['editor'] = KDNA_EcommerceInsights_Elementor::is_editor();
		$data['theme']  = (string) ( $settings['kdna_theme'] ?? 'admin' );
		if ( 'sample' === $state ) {
			$data['sample'] = $this->sample_data( $settings, $data );
		}

		printf(
			'<div class="kdna-ei-root kdna-ei-w kdna-ei-w--%1$s %2$s" data-kdna-ei-widget="%1$s" data-kdna-ei-theme="%3$s" data-kdna-ei-state="%4$s" data-kdna-ei-settings="%5$s">',
			esc_attr( $this->widget_type() ),
			esc_attr( $class ),
			esc_attr( $this->theme( $settings ) ),
			esc_attr( 'live' === $state || 'sample' === $state ? 'loading' : $state ),
			esc_attr( (string) wp_json_encode( $data ) )
		);
	}

	/**
	 * The theme toggle button, when the Theme control asks for one.
	 *
	 * @param array $settings Widget settings.
	 */
	protected function render_theme_toggle( array $settings ): void {
		if ( 'toggle' !== ( $settings['kdna_theme'] ?? 'admin' ) ) {
			return;
		}
		?>
		<button type="button" class="kdna-ei-btn kdna-ei-btn--icon kdna-ei-theme-toggle" data-kdna-ei-action="theme" aria-label="<?php esc_attr_e( 'Switch between light and dark mode', 'kdna-ecommerce-insights' ); ?>">
			<span class="kdna-ei-theme-toggle__sun"><?php KDNA_EcommerceInsights_Admin::icon( 'sun' ); ?></span>
			<span class="kdna-ei-theme-toggle__moon"><?php KDNA_EcommerceInsights_Admin::icon( 'moon' ); ?></span>
		</button>
		<?php
	}

	/**
	 * The Restricted card: a lock, title, message and optional login button.
	 * Plain HTML with no store details, for visitors without access.
	 *
	 * @param array $settings Widget settings.
	 */
	protected function render_restricted( array $settings ): void {
		$title   = (string) ( $settings['kdna_restricted_title'] ?? '' );
		$message = (string) ( $settings['kdna_restricted_message'] ?? '' );
		?>
		<div class="kdna-ei-state kdna-ei-state--restricted" role="note">
			<span class="kdna-ei-state__icon" aria-hidden="true">
				<svg class="kdna-ei-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
			</span>
			<?php if ( '' !== $title ) : ?>
				<p class="kdna-ei-state__title"><?php echo esc_html( $title ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $message ) : ?>
				<p class="kdna-ei-state__message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
			<?php if ( 'yes' === ( $settings['kdna_login_button'] ?? '' ) && ! is_user_logged_in() ) : ?>
				<?php $after = 'after' === ( $settings['kdna_login_icon_position'] ?? 'before' ); ?>
				<a class="kdna-ei-btn kdna-ei-btn--primary<?php echo $after ? ' is-icon-after' : ''; ?>" href="<?php echo esc_url( wp_login_url( get_permalink() ? get_permalink() : home_url( '/' ) ) ); ?>">
					<?php if ( ! empty( $settings['kdna_login_icon']['value'] ) ) : ?>
						<span class="kdna-ei-btn__icon" aria-hidden="true"><?php Icons_Manager::render_icon( $settings['kdna_login_icon'], array( 'aria-hidden' => 'true' ) ); ?></span>
					<?php endif; ?>
					<span><?php echo esc_html( (string) ( $settings['kdna_login_text'] ?? __( 'Log in', 'kdna-ecommerce-insights' ) ) ); ?></span>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Empty and Error state blocks. The stylesheet shows the one that
	 * matches the widget's data-kdna-ei-state.
	 */
	protected function render_state_blocks(): void {
		?>
		<div class="kdna-ei-state kdna-ei-state--empty" role="status">
			<span class="kdna-ei-state__icon" aria-hidden="true"><?php KDNA_EcommerceInsights_Admin::icon( 'chart', 'kdna-ei-icon--lg' ); ?></span>
			<p class="kdna-ei-state__title"><?php esc_html_e( 'No figures yet', 'kdna-ecommerce-insights' ); ?></p>
			<p class="kdna-ei-state__message"><?php esc_html_e( 'There are no orders in these dates. Try a longer date range, or come back once the first orders arrive.', 'kdna-ecommerce-insights' ); ?></p>
		</div>
		<div class="kdna-ei-state kdna-ei-state--error" role="alert">
			<span class="kdna-ei-state__icon" aria-hidden="true"><?php KDNA_EcommerceInsights_Admin::icon( 'alert', 'kdna-ei-icon--lg' ); ?></span>
			<p class="kdna-ei-state__title"><?php esc_html_e( 'The figures could not be loaded', 'kdna-ecommerce-insights' ); ?></p>
			<p class="kdna-ei-state__message" data-kdna-ei-error><?php esc_html_e( 'Something went wrong talking to the server. Please try again.', 'kdna-ecommerce-insights' ); ?></p>
			<button type="button" class="kdna-ei-btn" data-kdna-ei-action="retry"><?php esc_html_e( 'Try again', 'kdna-ecommerce-insights' ); ?></button>
		</div>
		<?php
	}

	/**
	 * The date range dropdown with presets, comparison and a calendar for
	 * custom dates. Shared by the Date Range widget and the Dashboard's
	 * optional header picker. The script fills in the calendar.
	 *
	 * @param array $presets   Preset keys to offer, in order.
	 * @param bool  $compare   Whether to offer the comparison choice.
	 * @param bool  $calendar  Whether to offer custom dates.
	 */
	protected function render_range_picker( array $presets, bool $compare, bool $calendar ): void {
		$all    = KDNA_EcommerceInsights_Settings::range_presets();
		$id     = 'kdna-ei-range-' . $this->get_id();
		$labels = array_intersect_key( $all, array_flip( $presets ) );
		if ( $calendar ) {
			$labels['custom'] = $all['custom'];
		}
		?>
		<div class="kdna-ei-range" data-kdna-ei-range>
			<button type="button" class="kdna-ei-range__trigger" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>" data-kdna-ei-action="range-toggle">
				<span class="kdna-ei-range__lead"><?php KDNA_EcommerceInsights_Admin::icon( 'calendar', 'kdna-ei-icon--sm' ); ?></span>
				<span class="kdna-ei-range__label" data-kdna-ei-range-label><?php echo esc_html( reset( $labels ) ); ?></span>
				<?php KDNA_EcommerceInsights_Admin::icon( 'chevron-down', 'kdna-ei-icon--sm kdna-ei-range__chevron' ); ?>
			</button>
			<div class="kdna-ei-range__menu" id="<?php echo esc_attr( $id ); ?>" role="dialog" aria-label="<?php esc_attr_e( 'Date range', 'kdna-ecommerce-insights' ); ?>" hidden>
				<div class="kdna-ei-range__group" role="radiogroup" aria-label="<?php esc_attr_e( 'Date range', 'kdna-ecommerce-insights' ); ?>">
					<p class="kdna-ei-range__heading"><?php esc_html_e( 'Date range', 'kdna-ecommerce-insights' ); ?></p>
					<?php foreach ( $labels as $key => $label ) : ?>
						<button type="button" class="kdna-ei-range__item" role="radio" aria-checked="false" data-kdna-ei-preset="<?php echo esc_attr( $key ); ?>">
							<span><?php echo esc_html( $label ); ?></span>
							<?php KDNA_EcommerceInsights_Admin::icon( 'check', 'kdna-ei-icon--sm' ); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<?php if ( $calendar ) : ?>
					<div class="kdna-ei-range__custom" data-kdna-ei-calendar hidden>
						<div class="kdna-ei-cal">
							<div class="kdna-ei-cal__head">
								<button type="button" class="kdna-ei-cal__nav" data-kdna-ei-action="cal-prev" aria-label="<?php esc_attr_e( 'Previous month', 'kdna-ecommerce-insights' ); ?>"><?php KDNA_EcommerceInsights_Admin::icon( 'chevron-left', 'kdna-ei-icon--sm' ); ?></button>
								<p class="kdna-ei-cal__month" data-kdna-ei-cal-month aria-live="polite"></p>
								<button type="button" class="kdna-ei-cal__nav" data-kdna-ei-action="cal-next" aria-label="<?php esc_attr_e( 'Next month', 'kdna-ecommerce-insights' ); ?>"><?php KDNA_EcommerceInsights_Admin::icon( 'chevron-right', 'kdna-ei-icon--sm' ); ?></button>
							</div>
							<div class="kdna-ei-cal__grid" role="grid" data-kdna-ei-cal-grid></div>
						</div>
						<p class="kdna-ei-range__hint" data-kdna-ei-cal-hint></p>
						<div class="kdna-ei-range__actions">
							<button type="button" class="kdna-ei-btn kdna-ei-btn--small" data-kdna-ei-action="range-cancel"><?php esc_html_e( 'Cancel', 'kdna-ecommerce-insights' ); ?></button>
							<button type="button" class="kdna-ei-btn kdna-ei-btn--primary kdna-ei-btn--small" data-kdna-ei-action="range-apply" disabled><?php esc_html_e( 'Apply', 'kdna-ecommerce-insights' ); ?></button>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( $compare ) : ?>
					<div class="kdna-ei-range__group" role="radiogroup" aria-label="<?php esc_attr_e( 'Compare to', 'kdna-ecommerce-insights' ); ?>">
						<p class="kdna-ei-range__heading"><?php esc_html_e( 'Compare to', 'kdna-ecommerce-insights' ); ?></p>
						<?php foreach ( KDNA_EcommerceInsights_Settings::comparison_modes() as $key => $label ) : ?>
							<button type="button" class="kdna-ei-range__item" role="radio" aria-checked="false" data-kdna-ei-compare="<?php echo esc_attr( $key ); ?>">
								<span><?php echo esc_html( $label ); ?></span>
								<?php KDNA_EcommerceInsights_Admin::icon( 'check', 'kdna-ei-icon--sm' ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Made-up figures for the editor's "Sample data" preview, in the same
	 * shape the script gets from the REST API. Widgets that show something
	 * other than the Overview replace this.
	 *
	 * @param array $settings Widget settings.
	 * @param array $data     Settings being handed to the script.
	 * @return array
	 */
	protected function sample_data( array $settings, array $data ): array {
		return KDNA_EcommerceInsights_Widget_Sample::overview( (string) ( $data['fifth'] ?? 'average_order_value' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shared cards. The Dashboard and the modular widgets print the same
	 * markup through these, and the script fills them in the same way.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A card header: the card title, and anything to its right.
	 *
	 * @param string   $title   Card title (no header when empty and no actions).
	 * @param callable $actions Optional function that prints buttons on the right.
	 */
	protected function render_card_header( string $title, ?callable $actions = null ): void {
		if ( '' === $title && ! $actions ) {
			return;
		}
		echo '<div class="kdna-ei-card__header">';
		if ( '' !== $title ) {
			echo '<h3 class="kdna-ei-card__title">' . esc_html( $title ) . '</h3>';
		}
		if ( $actions ) {
			echo '<div class="kdna-ei-card__actions">';
			$actions();
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * The KPI figures with loading placeholders: one card across like the
	 * reference (strip), or a separate card for each figure (cards).
	 *
	 * @param int    $count  How many figures.
	 * @param string $layout strip or cards.
	 * @param string $class  Extra classes.
	 */
	protected function render_kpis( int $count, string $layout = 'strip', string $class = '' ): void {
		$cards = 'cards' === $layout;
		printf(
			'<section class="%1$s" data-kdna-ei-part="kpis" data-kdna-ei-layout="%2$s" aria-label="%3$s">',
			esc_attr( trim( ( $cards ? 'kdna-ei-kpi-cards' : 'kdna-ei-card kdna-ei-kpi-strip' ) . ' ' . $class ) ),
			esc_attr( $cards ? 'cards' : 'strip' ),
			esc_attr__( 'Key figures', 'kdna-ecommerce-insights' )
		);
		for ( $i = 0; $i < max( 1, $count ); $i++ ) {
			?>
			<div class="kdna-ei-kpi kdna-ei-kpi--skeleton<?php echo $cards ? ' kdna-ei-card' : ''; ?>" aria-hidden="true">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--icon"></span>
				<div class="kdna-ei-kpi__body kdna-ei-skel-stack">
					<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
					<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
				</div>
			</div>
			<?php
		}
		echo '</section>';
	}

	/**
	 * A chart card: title, legend, an optional switch between metrics, and
	 * the chart with a loading placeholder.
	 *
	 * @param string $title    Card title.
	 * @param array  $switch   Metric key => button label. No switch with fewer than two.
	 * @param string $class    Extra classes.
	 * @param bool   $compare  Whether the legend can show the comparison.
	 * @param bool   $legend   Whether to show the legend at all.
	 */
	protected function render_chart_card( string $title, array $switch, string $class = '', bool $compare = true, bool $legend = true ): void {
		$t = KDNA_EcommerceInsights_Admin::overview_strings();
		?>
		<section class="kdna-ei-card kdna-ei-performance <?php echo esc_attr( $class ); ?>" aria-label="<?php echo esc_attr( '' !== $title ? $title : $t['performance'] ); ?>">
			<?php
			$this->render_card_header(
				$title,
				static function () use ( $switch, $t, $compare, $legend ) {
					?>
					<div class="kdna-ei-performance__controls">
						<div class="kdna-ei-chart-legend" aria-hidden="true" data-kdna-ei-part="legend"<?php echo $legend ? '' : ' hidden'; ?>>
							<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><span data-kdna-ei-legend="current"></span></span>
							<?php if ( $compare ) : ?>
								<span class="kdna-ei-chart-legend__item" data-kdna-ei-legend-compare hidden><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--compare"></span><span data-kdna-ei-legend="compare"></span></span>
							<?php endif; ?>
						</div>
						<?php if ( count( $switch ) > 1 ) : ?>
							<div class="kdna-ei-segmented" role="group" aria-label="<?php echo esc_attr( $t['showSeries'] ); ?>">
								<?php foreach ( $switch as $key => $label ) : ?>
									<button type="button" class="kdna-ei-segmented__item" aria-pressed="false" data-kdna-ei-series="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<?php
				}
			);
			?>
			<div class="kdna-ei-chart">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" aria-hidden="true"></span>
				<canvas data-kdna-ei-part="chart" role="img" aria-label="<?php echo esc_attr( '' !== $title ? $title : $t['performance'] ); ?>"></canvas>
			</div>
		</section>
		<?php
	}

	/**
	 * A donut and legend card. The legend rows are printed as loading
	 * placeholders and replaced by the script.
	 *
	 * @param string $title  Card title.
	 * @param string $centre Label under the number in the middle of the donut.
	 * @param array  $rows   Legend labels known up front, or a number of placeholder rows.
	 * @param string $link   Optional "View all" address.
	 * @param string $class  Extra classes.
	 */
	protected function render_breakdown_card( string $title, string $centre, $rows, string $link = '', string $class = '' ): void {
		$t      = KDNA_EcommerceInsights_Admin::overview_strings();
		$tokens = self::segment_tokens();
		$labels = is_array( $rows ) ? array_values( $rows ) : array_fill( 0, max( 1, (int) $rows ), '' );
		?>
		<section class="kdna-ei-card kdna-ei-breakdown-card <?php echo esc_attr( $class ); ?>" aria-label="<?php echo esc_attr( $title ); ?>">
			<?php
			$this->render_card_header(
				$title,
				'' === $link ? null : static function () use ( $link, $t ) {
					echo '<a class="kdna-ei-link" href="' . esc_url( $link ) . '">' . esc_html( $t['viewInventory'] ) . '</a>';
				}
			);
			?>
			<div class="kdna-ei-donut-row">
				<div class="kdna-ei-donut">
					<span class="kdna-ei-skeleton kdna-ei-skeleton--circle kdna-ei-donut__skeleton" aria-hidden="true"></span>
					<canvas data-kdna-ei-part="donut" role="img" aria-label="<?php echo esc_attr( $title ); ?>"></canvas>
					<div class="kdna-ei-donut__centre" aria-hidden="true">
						<span class="kdna-ei-donut__number kdna-ei-num" data-kdna-ei-part="donut-number"></span>
						<span class="kdna-ei-donut__label" data-kdna-ei-part="donut-label"><?php echo esc_html( $centre ); ?></span>
					</div>
				</div>
				<table class="kdna-ei-table kdna-ei-legend">
					<caption class="kdna-ei-visually-hidden"><?php echo esc_html( $title ); ?></caption>
					<tbody data-kdna-ei-part="legend-rows">
						<?php foreach ( $labels as $i => $label ) : ?>
							<tr>
								<th scope="row"><span class="kdna-ei-legend__dot" style="background: var(--kdna-ei-segment-<?php echo esc_attr( (string) ( $i + 1 ) ); ?>, var(--kdna-ei-<?php echo esc_attr( $tokens[ $i % count( $tokens ) ] ); ?>))"></span><span><?php echo '' === $label ? '<span class="kdna-ei-skeleton kdna-ei-skeleton--text kdna-ei-legend__skeleton" aria-hidden="true"></span>' : esc_html( $label ); ?></span></th>
								<td class="is-numeric kdna-ei-legend__value"><span class="kdna-ei-skeleton kdna-ei-skeleton--text kdna-ei-legend__skeleton" aria-hidden="true"></span></td>
								<td class="is-numeric kdna-ei-legend__percent"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * The design tokens donut segments use, in order (the same order the
	 * script and chart layer use).
	 *
	 * @return string[]
	 */
	public static function segment_tokens(): array {
		return array( 'accent', 'positive', 'accent-2', 'warning', 'negative', 'text-muted' );
	}

	/**
	 * The hero card: Top products (with By profit and By revenue tabs),
	 * Profit breakdown or Goals tracker.
	 *
	 * @param string $hero  top_products, profit_breakdown or goals.
	 * @param string $title Card title; empty for the standard name.
	 * @param bool   $tabs  Whether to show the ranking tabs for top products.
	 * @param int    $rows  Placeholder rows while loading.
	 * @param string $class Extra classes.
	 */
	protected function render_hero_card( string $hero, string $title = '', bool $tabs = true, int $rows = 5, string $class = '' ): void {
		$t     = KDNA_EcommerceInsights_Admin::overview_strings();
		$title = '' !== $title ? $title : (string) ( $t['heroTypes'][ $hero ] ?? '' );
		?>
		<section class="kdna-ei-card kdna-ei-hero <?php echo esc_attr( $class ); ?>" aria-label="<?php echo esc_attr( $title ); ?>">
			<?php $this->render_card_header( $title ); ?>
			<?php if ( 'top_products' === $hero && $tabs ) : ?>
				<div class="kdna-ei-tabs kdna-ei-tabs--small" role="tablist" aria-label="<?php echo esc_attr( $t['rankBy'] ); ?>">
					<button type="button" class="kdna-ei-tab" role="tab" aria-selected="false" data-kdna-ei-sort="profit"><?php echo esc_html( $t['byProfit'] ); ?></button>
					<button type="button" class="kdna-ei-tab" role="tab" aria-selected="false" data-kdna-ei-sort="revenue"><?php echo esc_html( $t['byRevenue'] ); ?></button>
				</div>
			<?php endif; ?>
			<div class="kdna-ei-hero__body" data-kdna-ei-part="hero">
				<div class="kdna-ei-skel-stack kdna-ei-hero__skeleton" aria-hidden="true">
					<?php for ( $i = 0; $i < max( 1, $rows ); $i++ ) : ?>
						<div class="kdna-ei-skel-row"><span class="kdna-ei-skeleton" style="width: 44px; height: 44px; flex: none;"></span><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></div>
					<?php endfor; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * The hero type to show, turning "Follow Insights settings" into the
	 * choice saved in Insights > Settings.
	 *
	 * @param string $choice settings, top_products, profit_breakdown or goals.
	 * @return string
	 */
	protected function hero_type( string $choice ): string {
		if ( 'settings' === $choice ) {
			$choice = (string) KDNA_EcommerceInsights_Settings::get( 'hero.type', 'top_products' );
		}
		return in_array( $choice, array( 'top_products', 'profit_breakdown', 'goals' ), true ) ? $choice : 'top_products';
	}

	/**
	 * Optional card title control shared by the modular widgets.
	 *
	 * @param string $default Default title.
	 */
	protected function register_title_control( string $default ): void {
		$this->add_control(
			'kdna_card_title_text',
			array(
				'label'       => __( 'Card title', 'kdna-ecommerce-insights' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $default,
				'placeholder' => __( 'Leave empty for no title', 'kdna-ecommerce-insights' ),
				'label_block' => true,
			)
		);
	}

	/**
	 * Opens the root for a modular widget, with the theme toggle (if any)
	 * floating at the top.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 * @param array  $data     Settings for the script.
	 */
	protected function open_module( array $settings, string $state, array $data ): void {
		$data['range'] = $this->range_data( $settings );
		$this->open_root( $settings, $state, $data, 'kdna-ei-w--module' );
		if ( 'toggle' === ( $settings['kdna_theme'] ?? 'admin' ) ) {
			echo '<div class="kdna-ei-w__actions kdna-ei-w__actions--floating">';
			$this->render_theme_toggle( $settings );
			echo '</div>';
		}
	}

	/**
	 * Closes a modular widget: the empty and error states, then the root.
	 */
	protected function close_module(): void {
		$this->render_state_blocks();
		echo '</div>';
	}

	/**
	 * The style sections for a widget that shows figures: colours, wrapper
	 * and cards first, then the ones that widget needs, then the loading,
	 * empty, restricted and error states last.
	 *
	 * @param string[] $sections Controls methods to add, in order, such as "kpis".
	 */
	protected function register_style_controls( array $sections ): void {
		KDNA_EcommerceInsights_Widget_Controls::colours( $this );
		KDNA_EcommerceInsights_Widget_Controls::wrapper_and_cards( $this );
		foreach ( $sections as $method ) {
			call_user_func( array( KDNA_EcommerceInsights_Widget_Controls::class, $method ), $this );
		}
		KDNA_EcommerceInsights_Widget_Controls::states( $this );
	}

	/**
	 * The content sections every modular widget ends with: its date range,
	 * who can see it, and the editor's preview state.
	 */
	protected function register_common_sections(): void {
		$this->start_controls_section( 'kdna_range_section', array( 'label' => __( 'Date range', 'kdna-ecommerce-insights' ) ) );
		$this->register_range_controls();
		$this->end_controls_section();
		KDNA_EcommerceInsights_Widget_Controls::access( $this );
		KDNA_EcommerceInsights_Widget_Controls::preview( $this );
	}

	/**
	 * Metrics that can be drawn over time on a chart: sales, profit,
	 * marketing and customer counts.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function chart_metric_options(): array {
		$options = array();
		$extra   = array( 'customers', 'new_customers', 'returning_customers', 'new_customer_revenue', 'returning_customer_revenue' );
		foreach ( KDNA_EcommerceInsights_Metrics::all() as $key => $metric ) {
			if ( in_array( $metric['group'], array( 'sales', 'profit', 'marketing' ), true ) || in_array( $key, $extra, true ) ) {
				$options[ $key ] = $metric['label'];
			}
		}
		return $options;
	}

	/**
	 * Metrics that can be shown as a KPI figure.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function kpi_metric_options(): array {
		$options = array();
		foreach ( KDNA_EcommerceInsights_Admin::kpi_options() as $option ) {
			$options[ $option['key'] ] = $option['label'];
		}
		return $options;
	}

	/**
	 * A "Links into Insights" switch, for widgets with View all links.
	 */
	protected function register_links_control(): void {
		$this->add_control(
			'kdna_show_links',
			array(
				'label'        => __( 'Links into Insights', 'kdna-ecommerce-insights' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Shows links that open the matching Insights screen in wp-admin.', 'kdna-ecommerce-insights' ),
			)
		);
	}

	/**
	 * Range controls shared by widgets that show figures: follow the page's
	 * Date Range widget, or a fixed range of their own.
	 */
	protected function register_range_controls(): void {
		$this->add_control(
			'kdna_follow_range',
			array(
				'label'        => __( 'Follow page date range', 'kdna-ecommerce-insights' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Updates when a Date Range widget on the page changes, and starts with each Administrator\'s own saved range.', 'kdna-ecommerce-insights' ),
			)
		);
		$presets = KDNA_EcommerceInsights_Settings::range_presets();
		unset( $presets['custom'] );
		$this->add_control(
			'kdna_fixed_range',
			array(
				'label'     => __( 'Date range', 'kdna-ecommerce-insights' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'this_month',
				'options'   => $presets,
				'condition' => array( 'kdna_follow_range!' => 'yes' ),
			)
		);
		$this->add_control(
			'kdna_fixed_compare',
			array(
				'label'     => __( 'Compare to', 'kdna-ecommerce-insights' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'previous_period',
				'options'   => KDNA_EcommerceInsights_Settings::comparison_modes(),
				'condition' => array( 'kdna_follow_range!' => 'yes' ),
			)
		);
	}

	/**
	 * The range settings to hand to the script.
	 *
	 * @param array $settings Widget settings.
	 * @return array{follow: bool, preset: string, compare: string}
	 */
	protected function range_data( array $settings ): array {
		return array(
			'follow'  => 'yes' === ( $settings['kdna_follow_range'] ?? 'yes' ),
			'preset'  => (string) ( $settings['kdna_fixed_range'] ?? 'this_month' ),
			'compare' => (string) ( $settings['kdna_fixed_compare'] ?? 'previous_period' ),
		);
	}
}
