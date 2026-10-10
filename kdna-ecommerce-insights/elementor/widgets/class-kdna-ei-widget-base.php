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
			$data['sample'] = KDNA_EcommerceInsights_Widget_Sample::overview( (string) ( $data['fifth'] ?? 'average_order_value' ) );
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
