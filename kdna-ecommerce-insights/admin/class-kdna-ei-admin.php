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
		wp_enqueue_script( 'kdna-ei-format', KDNA_EI_URL . 'admin/js/kdna-ei-format.js', array( 'kdna-ei-router' ), $this->asset_version( 'admin/js/kdna-ei-format.js' ), true );
		wp_enqueue_script( 'kdna-ei-app', KDNA_EI_URL . 'admin/js/kdna-ei-app.js', array( 'kdna-ei-format' ), $this->asset_version( 'admin/js/kdna-ei-app.js' ), true );

		// Chart.js 4 (bundled, no CDN) and the Insights chart look.
		wp_enqueue_script( 'kdna-ei-chartjs', KDNA_EI_URL . 'assets/vendor/chartjs/chart.umd.min.js', array(), '4.4.4', true );
		wp_enqueue_script( 'kdna-ei-chart-theme', KDNA_EI_URL . 'assets/js/kdna-ei-chart-theme.js', array( 'kdna-ei-chartjs', 'kdna-ei-format' ), $this->asset_version( 'assets/js/kdna-ei-chart-theme.js' ), true );

		// One script per built screen, each registering its Alpine component.
		$screen_scripts = array( 'kdna-ei-app' );
		foreach ( array( 'overview', 'costs', 'cost-rules', 'overheads', 'settings-hero' ) as $screen_id ) {
			$path   = 'admin/js/screens/' . $screen_id . '.js';
			$handle = 'kdna-ei-screen-' . $screen_id;
			wp_enqueue_script( $handle, KDNA_EI_URL . $path, array( 'kdna-ei-app', 'kdna-ei-chart-theme' ), $this->asset_version( $path ), true );
			$screen_scripts[] = $handle;
		}

		// Alpine must load after our app and screen scripts, which register themselves with it.
		wp_enqueue_script(
			'kdna-ei-alpine',
			KDNA_EI_URL . 'assets/vendor/alpine/alpine.min.js',
			$screen_scripts,
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
			'kdna-ei-router',
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
				'locale'       => str_replace( '_', '-', get_user_locale() ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch, Administrators only.
				'debug'        => isset( $_GET['kdna_ei_debug'] ) && '1' === $_GET['kdna_ei_debug'] && current_user_can( 'manage_options' ),
				'status'       => KDNA_EcommerceInsights_Rest_Status::data(),
				'hero'         => KDNA_EcommerceInsights_Settings::get( 'hero', array() ),
				'kpiOptions'   => self::kpi_options(),
				'currency'     => array(
					'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
					'position' => (string) get_option( 'woocommerce_currency_pos', 'left' ),
					'decimals' => wc_get_price_decimals(),
					'decimal'  => wc_get_price_decimal_separator(),
					'thousand' => wc_get_price_thousand_separator(),
				),
				'i18n'         => array(
					'switchToLight' => __( 'Switch to light mode', 'kdna-ecommerce-insights' ),
					'switchToDark'  => __( 'Switch to dark mode', 'kdna-ecommerce-insights' ),
					'enterFocus'    => __( 'Enter Focus Mode', 'kdna-ecommerce-insights' ),
					'exitFocus'     => __( 'Exit Focus Mode', 'kdna-ecommerce-insights' ),
					'pageTitle'     => __( 'Insights', 'kdna-ecommerce-insights' ),
					'requestFailed' => __( 'Something went wrong talking to the server. Please try again.', 'kdna-ecommerce-insights' ),
					/* translators: 1: start date, 2: end date. */
					'rangeTo'       => __( '%1$s to %2$s', 'kdna-ecommerce-insights' ),
					'customMissing' => __( 'Choose both a start and an end date.', 'kdna-ecommerce-insights' ),
					'customOrder'   => __( 'The end date must be on or after the start date.', 'kdna-ecommerce-insights' ),
					/* translators: %s: number of days. */
					'days'          => __( '%s days', 'kdna-ecommerce-insights' ),
					'overview'      => self::overview_strings(),
					'heroSettings'  => array(
						'saved'       => __( 'Saved. The Overview has been updated.', 'kdna-ecommerce-insights' ),
						'amountError' => __( 'Enter an amount of zero or more, for example 25000.', 'kdna-ecommerce-insights' ),
						'ordersError' => __( 'Enter a whole number of orders, for example 300.', 'kdna-ecommerce-insights' ),
					),
					'costs'         => self::costs_strings(),
					'jobs'          => array(
						'all'      => __( 'Processing your orders', 'kdna-ecommerce-insights' ),
						'missing'  => __( 'Recalculating orders missing costs', 'kdna-ecommerce-insights' ),
						/* translators: %s: date. */
						'after'    => __( 'Recalculating orders from %s', 'kdna-ecommerce-insights' ),
						/* translators: 1: orders done, 2: total orders, 3: percentage. */
						'progress' => __( '%1$s of %2$s orders (%3$s%)', 'kdna-ecommerce-insights' ),
					),
					'data'          => array(
						'processing'   => __( 'Processing', 'kdna-ecommerce-insights' ),
						'upToDate'     => __( 'Up to date', 'kdna-ecommerce-insights' ),
						'stopped'      => __( 'Stopped', 'kdna-ecommerce-insights' ),
						'notStarted'   => __( 'Not started', 'kdna-ecommerce-insights' ),
						'hpos'         => __( 'High-Performance Order Storage', 'kdna-ecommerce-insights' ),
						'legacy'       => __( 'WordPress posts (legacy)', 'kdna-ecommerce-insights' ),
						/* translators: %s: number of orders. */
						'missingCount' => __( '%s orders had products without a cost when they were processed.', 'kdna-ecommerce-insights' ),
						/* translators: %s: number of orders. */
						'allCount'     => __( 'Every order, %s in total. Use this after changing fee, shipping or extra cost rules.', 'kdna-ecommerce-insights' ),
						'logStatus'    => array(
							'success' => __( 'Done', 'kdna-ecommerce-insights' ),
							'warning' => __( 'Done with problems', 'kdna-ecommerce-insights' ),
							'error'   => __( 'Problem', 'kdna-ecommerce-insights' ),
						),
					),
					'rules'         => self::rules_strings(),
					'overheads'     => self::overheads_strings(),
				),
			)
		);
	}

	/**
	 * Metrics that can be chosen for the fifth KPI on the Overview, as
	 * key, label and help text. Leaves out raw tax and count lines that make
	 * poor headline figures.
	 *
	 * @return array
	 */
	private static function kpi_options(): array {
		$skip    = array( 'tax_collected', 'tax_refunded', 'shipping_tax', 'ad_spend_tax' );
		$options = array();
		foreach ( KDNA_EcommerceInsights_Metrics::describe() as $key => $metric ) {
			if ( ! in_array( $key, $skip, true ) ) {
				$options[] = array(
					'key'   => $key,
					'label' => $metric['label'],
				);
			}
		}
		return $options;
	}

	/**
	 * Text used by the Overview screen script. %s is replaced with numbers
	 * or names in the browser.
	 *
	 * @return array
	 */
	private static function overview_strings(): array {
		return array(
			'performance'          => __( 'Performance', 'kdna-ecommerce-insights' ),
			'showSeries'           => __( 'Show on chart', 'kdna-ecommerce-insights' ),
			'revenue'              => __( 'Revenue', 'kdna-ecommerce-insights' ),
			'profit'               => __( 'Profit', 'kdna-ecommerce-insights' ),
			'orders'               => __( 'Orders', 'kdna-ecommerce-insights' ),
			'thisPeriod'           => __( 'This period', 'kdna-ecommerce-insights' ),
			'previousPeriod'       => __( 'Previous period', 'kdna-ecommerce-insights' ),
			'lastYear'             => __( 'Same period last year', 'kdna-ecommerce-insights' ),
			/* translators: %s: date. */
			'weekOf'               => __( 'Week of %s', 'kdna-ecommerce-insights' ),
			/* translators: 1: series name, 2: total. */
			'chartSummary'         => __( '%s over the chosen dates, %s in total.', 'kdna-ecommerce-insights' ),
			'new'                  => __( 'New', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of percentage points. */
			'points'               => __( '%s pts', 'kdna-ecommerce-insights' ),
			/* translators: 1: comparison name, 2: value. */
			'previousWas'          => __( '%s: %s', 'kdna-ecommerce-insights' ),
			'changeMetric'         => __( 'Choose which figure to show here', 'kdna-ecommerce-insights' ),
			'estimated'            => __( 'Est.', 'kdna-ecommerce-insights' ),
			'estimatedHelp'        => __( 'Includes estimated payment fees or shipping costs, because some orders did not record the real amount.', 'kdna-ecommerce-insights' ),
			'incomplete'           => __( 'Incomplete', 'kdna-ecommerce-insights' ),
			'incompleteHelp'       => __( 'Some products sold in this period have no cost, so profit is overstated. Add the missing costs to fix this.', 'kdna-ecommerce-insights' ),
			'inventory'            => __( 'Inventory', 'kdna-ecommerce-insights' ),
			'viewInventory'        => __( 'View all', 'kdna-ecommerce-insights' ),
			'inStock'              => __( 'in stock', 'kdna-ecommerce-insights' ),
			'inStockLegend'        => __( 'In stock', 'kdna-ecommerce-insights' ),
			'lowStock'             => __( 'Low stock', 'kdna-ecommerce-insights' ),
			'outOfStock'           => __( 'Out of stock', 'kdna-ecommerce-insights' ),
			'heroMenu'             => __( 'Change what this card shows', 'kdna-ecommerce-insights' ),
			'heroShow'             => __( 'Show', 'kdna-ecommerce-insights' ),
			'heroTypes'            => array(
				'top_products'     => __( 'Top products', 'kdna-ecommerce-insights' ),
				'profit_breakdown' => __( 'Profit breakdown', 'kdna-ecommerce-insights' ),
				'goals'            => __( 'Goals tracker', 'kdna-ecommerce-insights' ),
			),
			'rankBy'               => __( 'Rank products by', 'kdna-ecommerce-insights' ),
			'byProfit'             => __( 'By profit', 'kdna-ecommerce-insights' ),
			'byRevenue'            => __( 'By revenue', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of units. */
			'unitsSold'            => __( '%s sold', 'kdna-ecommerce-insights' ),
			/* translators: %s: margin percentage. */
			'marginOf'             => __( '%s margin', 'kdna-ecommerce-insights' ),
			'noProducts'           => __( 'No products sold in this period.', 'kdna-ecommerce-insights' ),
			'goalEmpty'            => __( 'Set a monthly target to see how this month is tracking.', 'kdna-ecommerce-insights' ),
			'goalSet'              => __( 'Set a target', 'kdna-ecommerce-insights' ),
			/* translators: %s: percentage. */
			'goalProgress'         => __( '%s%% of this month\'s target reached', 'kdna-ecommerce-insights' ),
			'goalMetrics'          => array(
				'revenue' => __( 'of revenue target', 'kdna-ecommerce-insights' ),
				'profit'  => __( 'of profit target', 'kdna-ecommerce-insights' ),
				'orders'  => __( 'of orders target', 'kdna-ecommerce-insights' ),
			),
			'onTrack'              => __( 'On track', 'kdna-ecommerce-insights' ),
			'behind'               => __( 'Behind pace', 'kdna-ecommerce-insights' ),
			'soFar'                => __( 'So far this month', 'kdna-ecommerce-insights' ),
			'target'               => __( 'Monthly target', 'kdna-ecommerce-insights' ),
			'daysLeft'             => __( 'Days left', 'kdna-ecommerce-insights' ),
			'perDayNeeded'         => __( 'Needed per day', 'kdna-ecommerce-insights' ),
			'dismiss'              => __( 'Dismiss', 'kdna-ecommerce-insights' ),
			/* translators: %s: percentage. */
			'alertProcessing'      => __( 'Your orders are still being processed (%s%%). Figures will fill in as it finishes.', 'kdna-ecommerce-insights' ),
			'alertMissingOne'      => __( '1 product has no cost, so profit is overstated.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of products. */
			'alertMissing'         => __( '%s products have no cost, so profit is overstated.', 'kdna-ecommerce-insights' ),
			'addCosts'             => __( 'Add costs', 'kdna-ecommerce-insights' ),
			/* translators: 1: low stock count, 2: out of stock count. */
			'alertStock'           => __( 'Stock needs attention: %s low and %s out of stock.', 'kdna-ecommerce-insights' ),
			'viewStock'            => __( 'View stock', 'kdna-ecommerce-insights' ),
			'alertLossOne'         => __( '1 order in this period lost money.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of orders. */
			'alertLoss'            => __( '%s orders in this period lost money.', 'kdna-ecommerce-insights' ),
			'viewProfit'           => __( 'See why', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of orders. */
			'alertCurrency'        => __( '%s orders in another currency had no exchange rate, so they were counted at face value.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of problems. */
			'alertErrors'          => __( '%s background tasks had problems in the last 7 days.', 'kdna-ecommerce-insights' ),
			'viewActivity'         => __( 'View activity', 'kdna-ecommerce-insights' ),
			'emptyTitle'           => __( 'No orders yet', 'kdna-ecommerce-insights' ),
			'emptyText'            => __( 'Once your store takes its first order, your revenue, profit and stock figures will appear here. In the meantime, adding your product costs means profit is right from day one.', 'kdna-ecommerce-insights' ),
			'emptyProcessingTitle' => __( 'Getting your figures ready', 'kdna-ecommerce-insights' ),
			'emptyProcessingText'  => __( 'Insights is working through your past orders in the background. You can leave this page; it carries on without you.', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Costs screen script. %s and %d are replaced with
	 * numbers or names in the browser.
	 *
	 * @return array
	 */
	private static function costs_strings(): array {
		return array(
			'noCost'               => __( 'No cost set', 'kdna-ecommerce-insights' ),
			'noCostShort'          => __( 'No cost', 'kdna-ecommerce-insights' ),
			/* translators: %s: parent product cost. */
			'parentCost'           => __( 'Parent: %s', 'kdna-ecommerce-insights' ),
			'defaultForVariations' => __( 'Default for variations', 'kdna-ecommerce-insights' ),
			/* translators: %s: product name. */
			'costFor'              => __( 'Cost price for %s', 'kdna-ecommerce-insights' ),
			'oneVariation'         => __( '%d variation', 'kdna-ecommerce-insights' ),
			'variations'           => __( '%d variations', 'kdna-ecommerce-insights' ),
			'oneUnsaved'           => __( '%d unsaved change', 'kdna-ecommerce-insights' ),
			'unsaved'              => __( '%d unsaved changes', 'kdna-ecommerce-insights' ),
			'invalidCount'         => __( '%d need fixing: costs must be numbers of zero or more.', 'kdna-ecommerce-insights' ),
			'fixInvalid'           => __( 'Some costs are not valid numbers. They are outlined in red.', 'kdna-ecommerce-insights' ),
			/* translators: 1: changes saved so far, 2: total changes. */
			'saving'               => __( 'Saving %d of %d', 'kdna-ecommerce-insights' ),
			'saved'                => __( 'Saved %d cost changes.', 'kdna-ecommerce-insights' ),
			'savedOne'             => __( 'Saved %d cost change.', 'kdna-ecommerce-insights' ),
			/* translators: 1: changes saved, 2: changes that failed. */
			'savedWithErrors'      => __( 'Saved %d cost changes. %d could not be saved:', 'kdna-ecommerce-insights' ),
			/* translators: %s: error message. */
			'saveFailed'           => __( 'Your changes were not saved. %s', 'kdna-ecommerce-insights' ),
			/* translators: 1: first row shown, 2: last row shown, 3: total products. */
			'showing'              => __( '%d to %d of %d products', 'kdna-ecommerce-insights' ),
			/* translators: 1: current page, 2: total pages. */
			'pageOf'               => __( 'Page %d of %d', 'kdna-ecommerce-insights' ),
			/* translators: %s: formatted stock quantity. */
			'inStockCount'         => __( 'In stock (%s)', 'kdna-ecommerce-insights' ),
			'noMatches'            => __( 'No products match these filters.', 'kdna-ecommerce-insights' ),
			'noProducts'           => __( 'No products yet. Products you add in WooCommerce appear here.', 'kdna-ecommerce-insights' ),
			'fileTooLarge'         => __( 'That file is too large. Please upload a CSV under 5 MB.', 'kdna-ecommerce-insights' ),
			/* translators: %s: file name. */
			'checking'             => __( 'Checking %s', 'kdna-ecommerce-insights' ),
			'apply'                => __( 'Save %d changes', 'kdna-ecommerce-insights' ),
			'applyOne'             => __( 'Save %d change', 'kdna-ecommerce-insights' ),
			'problemsNote'         => __( '%d rows have problems and will not be imported. See the Problems tab.', 'kdna-ecommerce-insights' ),
			'problemsNoteOne'      => __( '%d row has a problem and will not be imported. See the Problems tab.', 'kdna-ecommerce-insights' ),
			'importDone'           => __( 'Done. %d product costs updated.', 'kdna-ecommerce-insights' ),
			'importDoneErrors'     => __( '%d could not be saved because the product no longer exists.', 'kdna-ecommerce-insights' ),
			'close'                => __( 'Close', 'kdna-ecommerce-insights' ),
			'cancel'               => __( 'Cancel', 'kdna-ecommerce-insights' ),
			'oneFound'             => __( 'Costs found for %d product', 'kdna-ecommerce-insights' ),
			'found'                => __( 'Costs found for %d products', 'kdna-ecommerce-insights' ),
			'noneFound'            => __( 'No costs found on this store', 'kdna-ecommerce-insights' ),
			'importCosts'          => __( 'Import costs', 'kdna-ecommerce-insights' ),
			/* translators: %d: percentage complete. */
			'importing'            => __( 'Importing %d%%', 'kdna-ecommerce-insights' ),
			/* translators: 1: costs imported, 2: products skipped. */
			'pluginDone'           => __( 'Imported %d costs. %d products were skipped because they already had a cost in Insights or the saved value was not a number.', 'kdna-ecommerce-insights' ),
			'stock'                => array(
				'instock'     => __( 'In stock', 'kdna-ecommerce-insights' ),
				'outofstock'  => __( 'Out of stock', 'kdna-ecommerce-insights' ),
				'onbackorder' => __( 'On backorder', 'kdna-ecommerce-insights' ),
			),
			'statuses'             => array(
				'draft'   => __( 'Draft', 'kdna-ecommerce-insights' ),
				'pending' => __( 'Pending', 'kdna-ecommerce-insights' ),
				'private' => __( 'Private', 'kdna-ecommerce-insights' ),
				'future'  => __( 'Scheduled', 'kdna-ecommerce-insights' ),
			),
		);
	}

	/**
	 * Text used by the payment fee, shipping and extra cost tabs.
	 *
	 * @return array
	 */
	private static function rules_strings(): array {
		return array(
			'disabled'          => __( 'Switched off', 'kdna-ecommerce-insights' ),
			'readsActual'       => __( 'Reads actual fee', 'kdna-ecommerce-insights' ),
			'estimatedOnly'     => __( 'Uses your rule', 'kdna-ecommerce-insights' ),
			/* translators: %s: payment method name. */
			'percentFor'        => __( 'Percentage fee for %s', 'kdna-ecommerce-insights' ),
			/* translators: %s: payment method name. */
			'fixedFor'          => __( 'Fixed fee per order for %s', 'kdna-ecommerce-insights' ),
			/* translators: %s: shipping method name. */
			'ruleTypeFor'       => __( 'How to work out the cost for %s', 'kdna-ecommerce-insights' ),
			/* translators: %s: shipping method name. */
			'amountFor'         => __( 'Amount for %s', 'kdna-ecommerce-insights' ),
			/* translators: %s: shipping method name. */
			'perKgFor'          => __( 'Cost per kg for %s', 'kdna-ecommerce-insights' ),
			'otherMethods'      => __( 'All other shipping methods', 'kdna-ecommerce-insights' ),
			'otherMethodsNote'  => __( 'Used when a method has no rule of its own', 'kdna-ecommerce-insights' ),
			'sameAsChargedNote' => __( 'Uses what the customer paid', 'kdna-ecommerce-insights' ),
			'shippingTypes'     => array(
				'none'            => __( 'No cost', 'kdna-ecommerce-insights' ),
				'fixed'           => __( 'Fixed amount', 'kdna-ecommerce-insights' ),
				'percent'         => __( 'Percentage of order', 'kdna-ecommerce-insights' ),
				'per_item'        => __( 'Per item', 'kdna-ecommerce-insights' ),
				'same_as_charged' => __( 'Same as charged', 'kdna-ecommerce-insights' ),
			),
			/* translators: 1: example order value, 2: extra costs total. */
			'extrasExample'     => __( 'On a %s order these add %s.', 'kdna-ecommerce-insights' ),
			/* translators: %s: cost name. */
			'removeCost'        => __( 'Remove %s', 'kdna-ecommerce-insights' ),
			'thisCost'          => __( 'this cost', 'kdna-ecommerce-insights' ),
			'saving'            => __( 'Saving', 'kdna-ecommerce-insights' ),
			'saveFees'          => __( 'Save payment fees', 'kdna-ecommerce-insights' ),
			'saveShipping'      => __( 'Save shipping costs', 'kdna-ecommerce-insights' ),
			'saveExtras'        => __( 'Save extra costs', 'kdna-ecommerce-insights' ),
			'saved'             => __( 'Saved. New orders use these rules straight away.', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Overheads tab.
	 *
	 * @return array
	 */
	private static function overheads_strings(): array {
		return array(
			'frequencies'      => KDNA_EcommerceInsights_Overheads::frequency_labels(),
			'includesTax'      => __( 'Includes tax', 'kdna-ecommerce-insights' ),
			/* translators: %s: tax amount. */
			'taxExcluded'      => __( 'Excludes %s of GST you can claim back.', 'kdna-ecommerce-insights' ),
			'edit'             => __( 'Edit', 'kdna-ecommerce-insights' ),
			'addTitle'         => __( 'Add an overhead', 'kdna-ecommerce-insights' ),
			'editTitle'        => __( 'Edit overhead', 'kdna-ecommerce-insights' ),
			'paidOn'           => __( 'Date paid', 'kdna-ecommerce-insights' ),
			'startsOn'         => __( 'Starts on', 'kdna-ecommerce-insights' ),
			/* translators: %s: date. */
			'paidDate'         => __( 'Paid %s', 'kdna-ecommerce-insights' ),
			/* translators: %s: date. */
			'from'             => __( 'From %s', 'kdna-ecommerce-insights' ),
			/* translators: 1: start date, 2: end date. */
			'fromTo'           => __( '%s to %s', 'kdna-ecommerce-insights' ),
			/* translators: %s: amount. */
			'previewOneOff'    => __( 'The full %s counts on the date paid.', 'kdna-ecommerce-insights' ),
			/* translators: %s: amount per day. */
			'previewRecurring' => __( 'Works out to about %s a day, spread across every day it runs.', 'kdna-ecommerce-insights' ),
			'delete'           => __( 'Delete', 'kdna-ecommerce-insights' ),
			'deleteConfirm'    => __( 'Delete this overhead?', 'kdna-ecommerce-insights' ),
			'deleteYes'        => __( 'Yes, delete', 'kdna-ecommerce-insights' ),
			'keep'             => __( 'Keep it', 'kdna-ecommerce-insights' ),
			'cancel'           => __( 'Cancel', 'kdna-ecommerce-insights' ),
			'saving'           => __( 'Saving', 'kdna-ecommerce-insights' ),
			'saveChanges'      => __( 'Save changes', 'kdna-ecommerce-insights' ),
			'add'              => __( 'Add overhead', 'kdna-ecommerce-insights' ),
			'added'            => __( 'Overhead added.', 'kdna-ecommerce-insights' ),
			'updated'          => __( 'Overhead updated.', 'kdna-ecommerce-insights' ),
			'deleted'          => __( 'Overhead deleted.', 'kdna-ecommerce-insights' ),
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
