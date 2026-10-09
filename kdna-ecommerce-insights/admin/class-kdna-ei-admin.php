<?php
/**
 * The wp-admin dashboard app: menu item, app shell, assets and Focus Mode.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Insights" menu item and loads the full-width dashboard app.
 *
 * The app replaces the normal WordPress admin page look with the design in
 * section 4 of the brief. Its styles and scripts only load on the Insights
 * screen, never anywhere else in wp-admin.
 */
class KDNA_EcommerceInsights_Admin {

	/**
	 * Page slug used in the admin URL: admin.php?page=kdna-ei.
	 */
	const MENU_SLUG = 'kdna-ei';

	/**
	 * Capability needed to see anything in Insights.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * WordPress's internal name for the Insights screen, set when the menu is added.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Connects the admin app to WordPress.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'in_admin_header', array( $this, 'hide_other_notices' ), 1000 );
		add_action( 'current_screen', array( $this, 'tidy_screen' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Screens
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Lists every screen in the app sidebar (section 8 of the brief), in order.
	 * Each has a label, a sidebar icon and the skeleton layout shown while
	 * its content is being built.
	 *
	 * @return array<string, array{label: string, icon: string, layout: string}>
	 */
	public static function screens(): array {
		$screens = array(
			'overview'  => array(
				'label'  => __( 'Overview', 'kdna-ecommerce-insights' ),
				'icon'   => 'home',
				'layout' => 'overview',
			),
			'profit'    => array(
				'label'  => __( 'Profit & Loss', 'kdna-ecommerce-insights' ),
				'icon'   => 'chart',
				'layout' => 'report',
			),
			'products'  => array(
				'label'  => __( 'Products', 'kdna-ecommerce-insights' ),
				'icon'   => 'bag',
				'layout' => 'table',
			),
			'customers' => array(
				'label'  => __( 'Customers', 'kdna-ecommerce-insights' ),
				'icon'   => 'users',
				'layout' => 'report',
			),
			'inventory' => array(
				'label'  => __( 'Inventory', 'kdna-ecommerce-insights' ),
				'icon'   => 'box',
				'layout' => 'report',
			),
			'marketing' => array(
				'label'  => __( 'Marketing', 'kdna-ecommerce-insights' ),
				'icon'   => 'megaphone',
				'layout' => 'report',
			),
			'costs'     => array(
				'label'  => __( 'Costs', 'kdna-ecommerce-insights' ),
				'icon'   => 'tag',
				'layout' => 'table',
			),
			'reports'   => array(
				'label'  => __( 'Tax & Reports', 'kdna-ecommerce-insights' ),
				'icon'   => 'document',
				'layout' => 'report',
			),
			'settings'  => array(
				'label'  => __( 'Settings', 'kdna-ecommerce-insights' ),
				'icon'   => 'cog',
				'layout' => 'settings',
			),
		);

		/**
		 * Filters the screens listed in the Insights sidebar.
		 *
		 * @param array $screens Screens keyed by ID.
		 */
		return apply_filters( 'kdna_ei_admin_screens', $screens );
	}

	/**
	 * Checks whether the page being viewed is the Insights app.
	 *
	 * @return bool
	 */
	private function is_app_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && '' !== $this->hook_suffix && $screen->id === $this->hook_suffix;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Menu and page
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Adds the top-level "Insights" item to the WordPress menu, visible to
	 * Administrators only.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Insights', 'kdna-ecommerce-insights' ),
			__( 'Insights', 'kdna-ecommerce-insights' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_app' ),
			'dashicons-chart-area',
			56
		);
	}

	/**
	 * Prints the app shell. All the markup lives in admin/views/app.php.
	 */
	public function render_app(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, only administrators can use Insights.', 'kdna-ecommerce-insights' ), 403 );
		}

		$screens    = self::screens();
		$prefs      = KDNA_EcommerceInsights_Preferences::get();
		$store_name = KDNA_EcommerceInsights_Settings::store_name();
		$logo_id    = (int) KDNA_EcommerceInsights_Settings::get( 'branding.logo_id', 0 );
		$logo_url   = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$ranges     = KDNA_EcommerceInsights_Settings::range_presets();
		$user       = wp_get_current_user();

		include KDNA_EI_PATH . 'admin/views/app.php';
	}

	/**
	 * Prints the line icon sprite so icons can be used anywhere in the app
	 * with <use href="#kdna-ei-icon-name">.
	 */
	public static function render_icon_sprite(): void {
		$file = KDNA_EI_PATH . 'assets/icons/kdna-ei-icons.svg';
		if ( is_readable( $file ) ) {
			// The sprite is a static file shipped with the plugin, not user input.
			echo file_get_contents( $file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	/**
	 * Prints one icon from the sprite.
	 *
	 * @param string $name  Icon name, for example 'home'.
	 * @param string $class Extra CSS class.
	 */
	public static function icon( string $name, string $class = '' ): void {
		printf(
			'<svg class="kdna-ei-icon %1$s" aria-hidden="true" focusable="false"><use href="#kdna-ei-icon-%2$s"></use></svg>',
			esc_attr( $class ),
			esc_attr( $name )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Assets
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns a version string for an asset. Normally the plugin version, but
	 * the file's last-changed time while SCRIPT_DEBUG is on, so edits show
	 * straight away during development.
	 *
	 * @param string $relative_path Path inside the plugin folder.
	 * @return string
	 */
	private function asset_version( string $relative_path ): string {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$time = filemtime( KDNA_EI_PATH . $relative_path );
			return $time ? (string) $time : KDNA_EI_VERSION;
		}
		return KDNA_EI_VERSION;
	}

	/**
	 * Loads the app's fonts, styles and scripts, but only on the Insights screen.
	 *
	 * @param string $hook_suffix The admin screen being loaded.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$styles = array(
			'kdna-ei-fonts'      => array( 'assets/css/kdna-ei-fonts.css', array() ),
			'kdna-ei-tokens'     => array( 'assets/css/kdna-ei-tokens.css', array( 'kdna-ei-fonts' ) ),
			'kdna-ei-components' => array( 'assets/css/kdna-ei-components.css', array( 'kdna-ei-tokens' ) ),
			'kdna-ei-admin'      => array( 'admin/css/kdna-ei-admin.css', array( 'kdna-ei-components' ) ),
		);

		foreach ( $styles as $handle => $style ) {
			wp_enqueue_style( $handle, KDNA_EI_URL . $style[0], $style[1], $this->asset_version( $style[0] ) );
		}

		$branding_css = self::branding_css();
		if ( '' !== $branding_css ) {
			wp_add_inline_style( 'kdna-ei-tokens', $branding_css );
		}

		wp_enqueue_script( 'kdna-ei-router', KDNA_EI_URL . 'admin/js/kdna-ei-router.js', array(), $this->asset_version( 'admin/js/kdna-ei-router.js' ), true );
		wp_enqueue_script( 'kdna-ei-app', KDNA_EI_URL . 'admin/js/kdna-ei-app.js', array( 'kdna-ei-router' ), $this->asset_version( 'admin/js/kdna-ei-app.js' ), true );

		// Alpine must load after our app script, which registers the app with it.
		wp_enqueue_script(
			'kdna-ei-alpine',
			KDNA_EI_URL . 'assets/vendor/alpine/alpine.min.js',
			array( 'kdna-ei-app' ),
			'3.14.9',
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$screens = array();
		foreach ( self::screens() as $id => $screen ) {
			$screens[ $id ] = $screen['label'];
		}

		wp_localize_script(
			'kdna-ei-app',
			'kdnaEiApp',
			array(
				'restUrl'      => esc_url_raw( rest_url( KDNA_EI_REST_NAMESPACE . '/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'preferences'  => KDNA_EcommerceInsights_Preferences::get(),
				'screens'      => $screens,
				'defaultRoute' => 'overview',
				'ranges'       => KDNA_EcommerceInsights_Settings::range_presets(),
				'comparisons'  => KDNA_EcommerceInsights_Settings::comparison_modes(),
				'storeName'    => KDNA_EcommerceInsights_Settings::store_name(),
				'i18n'         => array(
					'switchToLight' => __( 'Switch to light mode', 'kdna-ecommerce-insights' ),
					'switchToDark'  => __( 'Switch to dark mode', 'kdna-ecommerce-insights' ),
					'enterFocus'    => __( 'Enter Focus Mode', 'kdna-ecommerce-insights' ),
					'exitFocus'     => __( 'Exit Focus Mode', 'kdna-ecommerce-insights' ),
					'pageTitle'     => __( 'Insights', 'kdna-ecommerce-insights' ),
				),
			)
		);
	}

	/**
	 * Builds CSS that applies the client's brand colours and font from
	 * Settings > Branding on top of the default design tokens. Only values
	 * that differ from the defaults are written out.
	 *
	 * @return string
	 */
	public static function branding_css(): string {
		$defaults = KDNA_EcommerceInsights_Settings::default_colours();
		$colours  = (array) KDNA_EcommerceInsights_Settings::get( 'branding.colours', array() );
		$tokens   = array(
			'accent'   => '--kdna-ei-accent',
			'accent_2' => '--kdna-ei-accent-2',
			'positive' => '--kdna-ei-positive',
			'warning'  => '--kdna-ei-warning',
			'negative' => '--kdna-ei-negative',
		);
		$selectors = array(
			'dark'  => '.kdna-ei-root',
			'light' => '.kdna-ei-root[data-kdna-ei-theme="light"]',
		);

		$css = '';
		foreach ( $selectors as $theme => $selector ) {
			$rules = '';
			foreach ( $tokens as $key => $variable ) {
				$value = sanitize_hex_color( (string) ( $colours[ $theme ][ $key ] ?? '' ) );
				if ( $value && strtoupper( $value ) !== strtoupper( $defaults[ $theme ][ $key ] ) ) {
					$rules .= $variable . ':' . $value . ';';
				}
			}
			if ( '' !== $rules ) {
				$css .= $selector . '{' . $rules . '}';
			}
		}

		// Figtree is bundled now. The other fonts in the Branding list are bundled in Stage 12.
		$font  = (string) KDNA_EcommerceInsights_Settings::get( 'branding.font', 'figtree' );
		$fonts = KDNA_EcommerceInsights_Settings::fonts();
		if ( 'figtree' !== $font && 'inherit' !== $font && isset( $fonts[ $font ] ) ) {
			$css .= '.kdna-ei-root{--kdna-ei-font:"' . esc_attr( $fonts[ $font ] ) . '",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;}';
		}

		return $css;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Screen tidy-up
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Adds classes to the admin <body> on the Insights screen so the app can
	 * take over the full width, and remembers Focus Mode between visits.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public function body_class( string $classes ): string {
		if ( ! $this->is_app_screen() ) {
			return $classes;
		}

		$classes .= ' kdna-ei-admin';

		$prefs = KDNA_EcommerceInsights_Preferences::get();
		if ( ! empty( $prefs['focus'] ) ) {
			$classes .= ' kdna-ei-focus';
		}

		return $classes;
	}

	/**
	 * Stops other plugins' admin notices appearing on top of the app. Our own
	 * alerts are shown inside the dashboard instead (from Stage 6).
	 */
	public function hide_other_notices(): void {
		if ( ! $this->is_app_screen() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Removes the Help and Screen Options tabs from the Insights screen,
	 * because they do nothing in the app.
	 *
	 * @param WP_Screen $screen The screen being loaded.
	 */
	public function tidy_screen( $screen ): void {
		if ( ! $screen || $screen->id !== $this->hook_suffix ) {
			return;
		}

		$screen->remove_help_tabs();
		add_filter( 'screen_options_show_screen', '__return_false' );
	}
}
