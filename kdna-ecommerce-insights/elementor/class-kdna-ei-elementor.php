<?php
/**
 * Elementor integration: category, widgets, assets and page privacy.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connects Insights to Elementor (section 9 of the brief).
 *
 * - Adds the "KDNA Tools" widget category, unless another KDNA plugin has
 *   already added it.
 * - Registers the Insights widgets.
 * - Registers (but does not load) the widget CSS and JavaScript. Elementor
 *   loads them only on pages that use an Insights widget, through each
 *   widget's get_style_depends() and get_script_depends().
 * - Privacy (section 9.1): pages with an Insights widget are marked
 *   noindex and excluded from page caching (WP Rocket and other caches
 *   respect DONOTCACHEPAGE). Figures are only ever fetched by logged-in
 *   Administrators through the REST API, which checks every request.
 *
 * Does nothing when Elementor is not active.
 */
class KDNA_EcommerceInsights_Elementor {

	/**
	 * The widget category slug, shared by every KDNA plugin.
	 */
	const CATEGORY = 'kdna-tools';

	/**
	 * Widget classes, keyed by widget name.
	 */
	const WIDGETS = array(
		'kdna-ei-dashboard'  => 'KDNA_EcommerceInsights_Widget_Dashboard',
		'kdna-ei-date-range' => 'KDNA_EcommerceInsights_Widget_Date_Range',
	);

	/**
	 * Whether an Insights widget has been printed on this page.
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Whether the front-end settings have been printed on this page.
	 *
	 * @var bool
	 */
	private static $config_added = false;

	/**
	 * Hooks into Elementor and WordPress.
	 */
	public function __construct() {
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 20 );
		add_action( 'elementor/preview/enqueue_styles', array( __CLASS__, 'enqueue_for_editor' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( __CLASS__, 'enqueue_for_editor' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_page' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_sprite' ) );
	}

	/**
	 * Whether Elementor is running.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Category and widgets
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Adds the KDNA Tools category if it is not already there.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor's element manager.
	 */
	public static function register_category( $elements_manager ): void {
		$categories = method_exists( $elements_manager, 'get_categories' ) ? $elements_manager->get_categories() : array();
		if ( isset( $categories[ self::CATEGORY ] ) ) {
			return;
		}
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'KDNA Tools', 'kdna-ecommerce-insights' ),
				'icon'  => 'eicon-apps',
			)
		);
	}

	/**
	 * Registers every Insights widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's widget manager.
	 */
	public static function register_widgets( $widgets_manager ): void {
		foreach ( self::WIDGETS as $class ) {
			$widgets_manager->register( new $class() );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Assets
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Registers the widget styles and scripts so Elementor can load them on
	 * the pages that need them. Nothing is loaded here.
	 */
	public static function register_assets(): void {
		$version = static function ( string $path ): string {
			if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && is_readable( KDNA_EI_PATH . $path ) ) {
				return (string) filemtime( KDNA_EI_PATH . $path );
			}
			return KDNA_EI_VERSION;
		};

		wp_register_style( 'kdna-ei-fonts', KDNA_EI_URL . 'assets/css/kdna-ei-fonts.css', array(), $version( 'assets/css/kdna-ei-fonts.css' ) );
		wp_register_style( 'kdna-ei-tokens', KDNA_EI_URL . 'assets/css/kdna-ei-tokens.css', array( 'kdna-ei-fonts' ), $version( 'assets/css/kdna-ei-tokens.css' ) );
		wp_register_style( 'kdna-ei-components', KDNA_EI_URL . 'assets/css/kdna-ei-components.css', array( 'kdna-ei-tokens' ), $version( 'assets/css/kdna-ei-components.css' ) );
		wp_register_style( 'kdna-ei-widgets', KDNA_EI_URL . 'assets/css/kdna-ei-widgets.css', array( 'kdna-ei-components' ), $version( 'assets/css/kdna-ei-widgets.css' ) );

		// The site's brand colours and font from Settings > Branding apply to the widgets too.
		$branding = is_admin() ? '' : KDNA_EcommerceInsights_Admin::branding_css();
		if ( '' !== $branding ) {
			wp_add_inline_style( 'kdna-ei-tokens', $branding );
		}

		wp_register_script( 'kdna-ei-format', KDNA_EI_URL . 'admin/js/kdna-ei-format.js', array(), $version( 'admin/js/kdna-ei-format.js' ), true );
		wp_register_script( 'kdna-ei-chartjs', KDNA_EI_URL . 'assets/vendor/chartjs/chart.umd.min.js', array(), '4.4.4', true );
		wp_register_script( 'kdna-ei-chart-theme', KDNA_EI_URL . 'assets/js/kdna-ei-chart-theme.js', array( 'kdna-ei-chartjs', 'kdna-ei-format' ), $version( 'assets/js/kdna-ei-chart-theme.js' ), true );
		wp_register_script( 'kdna-ei-widgets', KDNA_EI_URL . 'assets/js/kdna-ei-widgets.js', array( 'kdna-ei-chart-theme' ), $version( 'assets/js/kdna-ei-widgets.js' ), true );
	}

	/**
	 * The script handles a widget needs. Visitors who cannot see figures get
	 * none at all: their widget is plain HTML.
	 *
	 * @return string[]
	 */
	public static function script_handles(): array {
		return self::can_view() || self::is_editor() ? array( 'kdna-ei-widgets' ) : array();
	}

	/**
	 * In the Elementor editor's preview, loads the widget assets up front
	 * (widgets can be dropped in at any time) with their settings.
	 */
	public static function enqueue_for_editor(): void {
		if ( doing_action( 'elementor/preview/enqueue_styles' ) ) {
			wp_enqueue_style( 'kdna-ei-widgets' );
			return;
		}
		wp_enqueue_script( 'kdna-ei-widgets' );
		self::add_config();
	}

	/**
	 * Prints the front-end settings for the widget script once per page:
	 * where the REST API is, the security nonce, the store's number format,
	 * the person's saved date range and theme, and the text.
	 *
	 * Only for people allowed to see figures (and the editor). Nothing about
	 * the store is printed for anyone else.
	 */
	public static function add_config(): void {
		if ( self::$config_added || ! ( self::can_view() || self::is_editor() ) ) {
			return;
		}
		self::$config_added = true;

		$can   = self::can_view();
		$prefs = $can ? KDNA_EcommerceInsights_Preferences::get() : array(
			'theme'      => 'dark',
			'range'      => 'last_30_days',
			'comparison' => 'previous_period',
		);

		$config = array(
			'restUrl'     => $can ? esc_url_raw( rest_url( KDNA_EI_REST_NAMESPACE . '/' ) ) : '',
			'nonce'       => $can ? wp_create_nonce( 'wp_rest' ) : '',
			'canView'     => $can,
			'preferences' => $prefs,
			'ranges'      => KDNA_EcommerceInsights_Settings::range_presets(),
			'comparisons' => KDNA_EcommerceInsights_Settings::comparison_modes(),
			'storeName'   => KDNA_EcommerceInsights_Settings::store_name(),
			'hero'        => $can ? KDNA_EcommerceInsights_Settings::get( 'hero', array() ) : array( 'type' => 'top_products' ),
			'kpiOptions'  => KDNA_EcommerceInsights_Admin::kpi_options(),
			'locale'      => str_replace( '_', '-', get_user_locale() ),
			'weekStart'   => (int) KDNA_EcommerceInsights_Settings::get( 'general.week_start', 1 ),
			'today'       => wp_date( 'Y-m-d' ),
			'adminUrl'    => $can ? admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG ) : '',
			'currency'    => array(
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'position' => (string) get_option( 'woocommerce_currency_pos', 'left' ),
				'decimals' => wc_get_price_decimals(),
				'decimal'  => wc_get_price_decimal_separator(),
				'thousand' => wc_get_price_thousand_separator(),
			),
			'i18n'        => array(
				'requestFailed' => __( 'Something went wrong talking to the server. Please try again.', 'kdna-ecommerce-insights' ),
				/* translators: %s: number of days. */
				'days'          => __( '%s days', 'kdna-ecommerce-insights' ),
				/* translators: 1: start date, 2: end date. */
				'rangeTo'       => __( '%1$s to %2$s', 'kdna-ecommerce-insights' ),
				'overview'      => KDNA_EcommerceInsights_Admin::overview_strings(),
				'widgets'       => self::strings(),
			),
		);

		// The format helpers read window.kdnaEiApp, the same settings object as the admin app.
		wp_add_inline_script( 'kdna-ei-format', 'window.kdnaEiApp = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Text used by the widget script.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'apply'          => __( 'Apply', 'kdna-ecommerce-insights' ),
			'cancel'         => __( 'Cancel', 'kdna-ecommerce-insights' ),
			'dateRange'      => __( 'Date range', 'kdna-ecommerce-insights' ),
			'compareTo'      => __( 'Compare to', 'kdna-ecommerce-insights' ),
			'chooseStart'    => __( 'Choose the first day.', 'kdna-ecommerce-insights' ),
			'chooseEnd'      => __( 'Now choose the last day.', 'kdna-ecommerce-insights' ),
			'previousMonth'  => __( 'Previous month', 'kdna-ecommerce-insights' ),
			'nextMonth'      => __( 'Next month', 'kdna-ecommerce-insights' ),
			'today'          => __( 'Today', 'kdna-ecommerce-insights' ),
			'switchToLight'  => __( 'Switch to light mode', 'kdna-ecommerce-insights' ),
			'switchToDark'   => __( 'Switch to dark mode', 'kdna-ecommerce-insights' ),
			'sample'         => __( 'Sample data', 'kdna-ecommerce-insights' ),
			'noOrders'       => __( 'No orders in these dates yet.', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Prints the line icon sprite once, on pages where a widget was shown to
	 * someone allowed to see it, and always in the Elementor editor's
	 * preview, where widgets are drawn after the page has loaded.
	 */
	public static function print_sprite(): void {
		if ( ! self::$rendered && ! self::is_editor() ) {
			return;
		}
		echo '<div class="kdna-ei-sprite" hidden>';
		KDNA_EcommerceInsights_Admin::render_icon_sprite();
		echo '</div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Who can see figures
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether the person viewing may see figures: logged-in Administrators
	 * only, the same rule as the REST API.
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return is_user_logged_in() && current_user_can( KDNA_EcommerceInsights_Admin::CAPABILITY );
	}

	/**
	 * Whether the page is being shown inside the Elementor editor (or its
	 * preview frame), where the Preview state control applies.
	 *
	 * @return bool
	 */
	public static function is_editor(): bool {
		if ( ! self::active() ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance;
		return ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() ) || ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Privacy: noindex and no caching
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether a post's Elementor layout contains an Insights widget.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function post_has_widget( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $data ) ) {
			$data = wp_json_encode( $data );
		}
		return is_string( $data ) && (bool) preg_match( '/"widgetType"\s*:\s*"kdna-ei-/', $data );
	}

	/**
	 * Whether the page being viewed contains an Insights widget, or one has
	 * already been printed.
	 *
	 * @return bool
	 */
	public static function page_has_widget(): bool {
		if ( self::$rendered ) {
			return true;
		}
		/**
		 * Filters whether the current page has an Insights widget, for
		 * layouts Insights cannot see, such as some theme templates.
		 *
		 * @param bool $has Whether the page has a widget.
		 */
		return (bool) apply_filters( 'kdna_ei_page_has_widget', is_singular() && self::post_has_widget( (int) get_queried_object_id() ) );
	}

	/**
	 * Before the page is sent: keeps it out of every page cache.
	 */
	public static function protect_page(): void {
		if ( self::page_has_widget() ) {
			self::no_cache();
		}
	}

	/**
	 * Tells caches not to keep this page: the WordPress no-cache headers and
	 * DONOTCACHEPAGE, which WP Rocket, W3 Total Cache, WP Super Cache and
	 * LiteSpeed Cache all respect.
	 */
	public static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		do_action( 'litespeed_control_set_nocache', 'KDNA eCommerce Insights widget' );
	}

	/**
	 * Adds noindex to pages with an Insights widget, so search engines never
	 * list them.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( array $robots ): array {
		if ( self::page_has_widget() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	/**
	 * Called by every widget as it is printed: catches pages whose widget is
	 * in a template Insights could not see in advance, and remembers to
	 * print the icons and settings.
	 */
	public static function widget_rendered(): void {
		self::$rendered = true;
		self::no_cache();
		if ( self::can_view() || self::is_editor() ) {
			self::add_config();
		}
	}
}
