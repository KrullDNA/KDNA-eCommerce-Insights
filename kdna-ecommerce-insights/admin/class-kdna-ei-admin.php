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

		// Brand colours and font. Always printed (even when empty) so the
		// Branding tab can swap the rules in place after saving.
		wp_add_inline_style( 'kdna-ei-tokens', self::branding_css() . '/* kdna-ei-branding */' );

		// The client's own CSS from Settings > Branding, loaded last so it wins.
		wp_register_style( 'kdna-ei-custom', false, array( 'kdna-ei-admin' ), KDNA_EI_VERSION );
		wp_enqueue_style( 'kdna-ei-custom' );
		wp_add_inline_style( 'kdna-ei-custom', self::custom_css() . '/* kdna-ei-custom */' );

		// The Media Library window, for choosing a logo in Settings > Branding.
		wp_enqueue_media();

		wp_enqueue_script( 'kdna-ei-router', KDNA_EI_URL . 'admin/js/kdna-ei-router.js', array(), $this->asset_version( 'admin/js/kdna-ei-router.js' ), true );
		wp_enqueue_script( 'kdna-ei-format', KDNA_EI_URL . 'admin/js/kdna-ei-format.js', array( 'kdna-ei-router' ), $this->asset_version( 'admin/js/kdna-ei-format.js' ), true );
		wp_enqueue_script( 'kdna-ei-app', KDNA_EI_URL . 'admin/js/kdna-ei-app.js', array( 'kdna-ei-format' ), $this->asset_version( 'admin/js/kdna-ei-app.js' ), true );

		// Chart.js 4 (bundled, no CDN) and the Insights chart look.
		wp_enqueue_script( 'kdna-ei-chartjs', KDNA_EI_URL . 'assets/vendor/chartjs/chart.umd.min.js', array(), '4.4.4', true );
		wp_enqueue_script( 'kdna-ei-chart-theme', KDNA_EI_URL . 'assets/js/kdna-ei-chart-theme.js', array( 'kdna-ei-chartjs', 'kdna-ei-format' ), $this->asset_version( 'assets/js/kdna-ei-chart-theme.js' ), true );

		// One script per built screen, each registering its Alpine component.
		$screen_scripts = array( 'kdna-ei-app' );
		foreach ( array( 'overview', 'profit', 'products', 'customers', 'inventory', 'marketing', 'costs', 'cost-rules', 'overheads', 'reports', 'settings' ) as $screen_id ) {
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
				'alerts'       => KDNA_EcommerceInsights_Settings::get( 'alerts', array() ),
				'tax'          => KDNA_EcommerceInsights_Settings::get( 'tax', array() ),
				'taxSystems'   => KDNA_EcommerceInsights_Tax::systems(),
				'taxSpans'     => KDNA_EcommerceInsights_Tax::spans(),
				'exports'      => KDNA_EcommerceInsights_Rest_Export::catalogue(),
				'printUrl'     => KDNA_EcommerceInsights_Print_Report::base_url(),
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
					'profit'        => self::profit_strings(),
					'products'      => self::products_strings(),
					'customers'     => self::customers_strings(),
					'inventory'     => self::inventory_strings(),
					'marketing'     => self::marketing_strings(),
					'reports'       => self::reports_strings(),
					'settings'      => self::settings_strings(),
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
	 * Text used by the Profit & Loss screen script.
	 *
	 * @return array
	 */
	private static function profit_strings(): array {
		return array(
			'thisPeriod'        => __( 'This period', 'kdna-ecommerce-insights' ),
			'netMargin'         => __( 'Net margin', 'kdna-ecommerce-insights' ),
			'grossMargin'       => __( 'Gross margin', 'kdna-ecommerce-insights' ),
			/* translators: %s: net margin. */
			'marginSummary'     => __( 'Gross and net margin over the chosen dates. Net margin overall: %s.', 'kdna-ecommerce-insights' ),
			/* translators: 1: comparison name, 2: amount. */
			'previousAmount'    => __( '%s: %s', 'kdna-ecommerce-insights' ),
			'includesEstimates' => __( 'Includes estimates', 'kdna-ecommerce-insights' ),
			/* translators: 1: orders with an estimated fee, 2: all orders. */
			'feesEstimated'     => __( 'Estimated for %s of %s orders: the gateway did not record its fee, so your fee rule was used.', 'kdna-ecommerce-insights' ),
			/* translators: 1: orders with an estimated shipping cost, 2: all orders. */
			'shippingEstimated' => __( 'Estimated for %s of %s orders: no real postage cost was recorded, so your shipping rule was used.', 'kdna-ecommerce-insights' ),
			/* translators: 1: orders missing a product cost, 2: all orders. */
			'cogsIncomplete'    => __( 'Incomplete: %s of %s orders include products with no cost, so this is lower than it should be and profit is overstated.', 'kdna-ecommerce-insights' ),
			/* translators: %s: month, for example Oct 2026. */
			'partMonth'         => __( '%s (part)', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Marketing screen script.
	 *
	 * @return array
	 */
	private static function marketing_strings(): array {
		return array(
			'intro'             => __( 'Ad spend comes off net profit everywhere in Insights. Add it by hand or import a report from your ad platform.', 'kdna-ecommerce-insights' ),
			'emptyTitle'        => __( 'No ad spend in these dates', 'kdna-ecommerce-insights' ),
			'emptyText'         => __( 'Add what you spent on Meta, Google or anything else, or import the report your ad platform gives you. Net profit, ROAS and cost per new customer then fill in everywhere.', 'kdna-ecommerce-insights' ),
			'exGst'             => __( 'Excluding GST you can claim back.', 'kdna-ecommerce-insights' ),
			'asEntered'         => __( 'As entered.', 'kdna-ecommerce-insights' ),
			'roasHelp'          => __( 'Return on ad spend: the purchase value the platform says it brought in, divided by what you spent.', 'kdna-ecommerce-insights' ),
			'noValueHelp'       => __( 'No purchase value for this channel. Import a report with purchase value to see its ROAS.', 'kdna-ecommerce-insights' ),
			'campaignsNote'     => __( 'Purchases and value are as reported by each platform.', 'kdna-ecommerce-insights' ),
			'noCampaign'        => __( 'No campaign given', 'kdna-ecommerce-insights' ),
			'noMatches'         => __( 'No campaigns match that search.', 'kdna-ecommerce-insights' ),
			'noCampaigns'       => __( 'No campaigns in these dates.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of rows. */
			'showAll'           => __( 'Show all %s', 'kdna-ecommerce-insights' ),
			'showFewer'         => __( 'Show fewer', 'kdna-ecommerce-insights' ),
			'entriesNote'       => __( 'Everything added by hand or imported that overlaps these dates. A spend covering several days is spread evenly across them.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of campaigns. */
			'campaignCount'     => __( '%s campaigns', 'kdna-ecommerce-insights' ),
			'sources'           => array(
				'manual' => __( 'By hand', 'kdna-ecommerce-insights' ),
				'csv'    => __( 'CSV import', 'kdna-ecommerce-insights' ),
				'api'    => __( 'Live sync', 'kdna-ecommerce-insights' ),
			),
			'inclGst'           => __( 'incl. GST', 'kdna-ecommerce-insights' ),
			/* translators: 1: channel, 2: dates. */
			'editEntry'         => __( 'Edit %1$s spend, %2$s', 'kdna-ecommerce-insights' ),
			/* translators: 1: channel, 2: dates. */
			'deleteEntry'       => __( 'Delete %1$s spend, %2$s', 'kdna-ecommerce-insights' ),
			'confirmEntry'      => __( 'Delete this spend?', 'kdna-ecommerce-insights' ),
			'confirmImport'     => __( 'Delete everything from this import?', 'kdna-ecommerce-insights' ),
			'noEntries'         => __( 'Nothing added for these dates yet.', 'kdna-ecommerce-insights' ),
			'addTitle'          => __( 'Add ad spend', 'kdna-ecommerce-insights' ),
			'editTitle'         => __( 'Change ad spend', 'kdna-ecommerce-insights' ),
			'date'              => __( 'Date', 'kdna-ecommerce-insights' ),
			'from'              => __( 'From', 'kdna-ecommerce-insights' ),
			'to'                => __( 'To', 'kdna-ecommerce-insights' ),
			'month'             => __( 'Month', 'kdna-ecommerce-insights' ),
			'saving'            => __( 'Saving...', 'kdna-ecommerce-insights' ),
			'saveChanges'       => __( 'Save changes', 'kdna-ecommerce-insights' ),
			'addSpend'          => __( 'Add spend', 'kdna-ecommerce-insights' ),
			/* translators: 1: amount per day, 2: number of days. */
			'perDay'            => __( 'That is %1$s a day over %2$s days.', 'kdna-ecommerce-insights' ),
			'gstNotSetUp'       => __( 'Your store is not set up to report GST (Settings > Tax), so the full amount counts as ad spend either way.', 'kdna-ecommerce-insights' ),
			'gstNo'             => __( 'Tick this if the amount includes GST. Meta and Google usually show amounts before GST.', 'kdna-ecommerce-insights' ),
			/* translators: %s: GST rate. */
			'gstYesNoAmount'    => __( 'The %s%% GST will be taken off, as you can claim it back.', 'kdna-ecommerce-insights' ),
			/* translators: 1: GST amount, 2: amount counted. */
			'gstYes'            => __( '%1$s of GST will be taken off, as you can claim it back, so %2$s counts as ad spend.', 'kdna-ecommerce-insights' ),
			'amountError'       => __( 'Enter an amount of zero or more, for example 1500.', 'kdna-ecommerce-insights' ),
			'dateError'         => __( 'Choose the date this spend starts.', 'kdna-ecommerce-insights' ),
			'endError'          => __( 'The end date must be on or after the start date.', 'kdna-ecommerce-insights' ),
			'channelError'      => __( 'Type a name for the new channel.', 'kdna-ecommerce-insights' ),
			'checkFields'       => __( 'Please check the highlighted fields.', 'kdna-ecommerce-insights' ),
			/* translators: 1: amount, 2: channel. */
			'added'             => __( 'Added %1$s of %2$s spend. Net profit has been updated everywhere.', 'kdna-ecommerce-insights' ),
			/* translators: 1: amount, 2: channel. */
			'updated'           => __( 'Changed to %1$s of %2$s spend. Net profit has been updated everywhere.', 'kdna-ecommerce-insights' ),
			/* translators: 1: amount, 2: channel. */
			'deleted'           => __( 'Deleted %1$s of %2$s spend.', 'kdna-ecommerce-insights' ),
			/* translators: %s: channels and amounts. */
			'chartSummary'      => __( 'Ad spend by channel over the chosen dates: %s.', 'kdna-ecommerce-insights' ),
			/* translators: %s: total. */
			'dayTotal'          => __( 'Total %s', 'kdna-ecommerce-insights' ),
			'importIntro'       => __( 'Export a report from your ad platform and import it here. You will see exactly what will be added before anything is saved.', 'kdna-ecommerce-insights' ),
			'tipMeta'           => __( 'Meta Ads Manager: Reports, choose your dates, break down by Day, then Export as CSV.', 'kdna-ecommerce-insights' ),
			'tipGoogle'         => __( 'Google Ads: Campaigns, add the Day segment, then Download as CSV.', 'kdna-ecommerce-insights' ),
			'tipOther'          => __( 'Anything else: a spreadsheet with a date column and an amount column is enough.', 'kdna-ecommerce-insights' ),
			'tooLarge'          => __( 'That file is larger than 5 MB. Please export fewer dates at a time.', 'kdna-ecommerce-insights' ),
			/* translators: %s: file name. */
			'reading'           => __( 'Reading %s...', 'kdna-ecommerce-insights' ),
			/* translators: %s: preset name. */
			'detected'          => __( 'Recognised as a %s, so the columns are matched for you.', 'kdna-ecommerce-insights' ),
			'checkColumns'      => __( 'Check which column is which', 'kdna-ecommerce-insights' ),
			'chooseColumn'      => __( 'Choose a column', 'kdna-ecommerce-insights' ),
			'notInFile'         => __( 'Not in this file', 'kdna-ecommerce-insights' ),
			/* translators: 1: file currency, 2: store currency. */
			'rateLabel'         => __( '1 %1$s is worth how many %2$s?', 'kdna-ecommerce-insights' ),
			/* translators: 1: file currency, 2: store currency. */
			'rateHelp'          => __( 'This report is in %1$s but your store reports in %2$s, so amounts are converted with this rate.', 'kdna-ecommerce-insights' ),
			'totalSpend'        => __( 'Total spend', 'kdna-ecommerce-insights' ),
			'dates'             => __( 'Dates', 'kdna-ecommerce-insights' ),
			'rows'              => __( 'Rows', 'kdna-ecommerce-insights' ),
			'campaigns'         => __( 'Campaigns', 'kdna-ecommerce-insights' ),
			/* translators: 1: amount, 2: channel, 3: dates. */
			'replaces'          => __( '%1$s of %2$s spend was already imported for %3$s. Importing replaces it, so nothing is counted twice. Spend added by hand is kept.', 'kdna-ecommerce-insights' ),
			'problemOne'        => __( '1 row could not be read and will be skipped:', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of rows. */
			'problems'          => __( '%s rows could not be read and will be skipped:', 'kdna-ecommerce-insights' ),
			/* translators: 1: rows shown, 2: all rows. */
			'firstRows'         => __( 'Showing the first %1$s of %2$s rows.', 'kdna-ecommerce-insights' ),
			'presetPlaceholder' => __( 'Preset name, for example "Meta, main account"', 'kdna-ecommerce-insights' ),
			'presetNameError'   => __( 'Give the preset a name, or untick "Remember these columns".', 'kdna-ecommerce-insights' ),
			/* translators: %s: amount. */
			'importButton'      => __( 'Import %s', 'kdna-ecommerce-insights' ),
			'importPlain'       => __( 'Import', 'kdna-ecommerce-insights' ),
			'importing'         => __( 'Importing...', 'kdna-ecommerce-insights' ),
			/* translators: %s: amount. */
			'importedTitle'     => __( '%s of ad spend imported', 'kdna-ecommerce-insights' ),
			/* translators: 1: rows, 2: channel, 3: dates. */
			'importedText'      => __( '%1$s rows of %2$s spend for %3$s. Net profit has been updated everywhere.', 'kdna-ecommerce-insights' ),
			/* translators: %s: amount. */
			'importedReplaced'  => __( 'This replaced %s imported earlier for the same dates.', 'kdna-ecommerce-insights' ),
			'done'              => __( 'Done', 'kdna-ecommerce-insights' ),
			'cancel'            => __( 'Cancel', 'kdna-ecommerce-insights' ),

			// Live connections.
			'liveSubtitle'      => __( 'Live connection', 'kdna-ecommerce-insights' ),
			'states'            => array(
				'not_set_up' => __( 'Not set up', 'kdna-ecommerce-insights' ),
				'ready'      => __( 'Ready to test', 'kdna-ecommerce-insights' ),
				'connected'  => __( 'Connected', 'kdna-ecommerce-insights' ),
				'error'      => __( 'Needs attention', 'kdna-ecommerce-insights' ),
			),
			'metaIntro'         => __( 'Bring in Facebook and Instagram ad spend, purchases and purchase value automatically every day.', 'kdna-ecommerce-insights' ),
			'googleIntro'       => __( 'Bring in Google Ads spend, conversions and conversion value automatically every day.', 'kdna-ecommerce-insights' ),
			/* translators: %s: list of items. */
			'stillNeeded'       => __( 'Still needed: %s.', 'kdna-ecommerce-insights' ),
			/* translators: 1: date and time, 2: campaign days, 3: spend. */
			'lastSynced'        => __( 'Last synced %1$s: %2$s campaign days, %3$s spend.', 'kdna-ecommerce-insights' ),
			/* translators: %s: date and time. */
			'nextSync'          => __( 'Next sync %s.', 'kdna-ecommerce-insights' ),
			/* translators: %s: date. */
			'syncedFrom'        => __( 'Spend from %s onwards comes from this connection, so it cannot be added by hand.', 'kdna-ecommerce-insights' ),
			'setUp'             => __( 'Set up', 'kdna-ecommerce-insights' ),
			'changeDetails'     => __( 'Change details', 'kdna-ecommerce-insights' ),
			'signIn'            => __( 'Sign in with Google', 'kdna-ecommerce-insights' ),
			'howFarBack'        => __( 'How far back to sync', 'kdna-ecommerce-insights' ),
			'days7'             => __( 'Last 7 days', 'kdna-ecommerce-insights' ),
			'days30'            => __( 'Last 30 days', 'kdna-ecommerce-insights' ),
			'days90'            => __( 'Last 90 days', 'kdna-ecommerce-insights' ),
			'days365'           => __( 'Last 12 months', 'kdna-ecommerce-insights' ),
			'syncNow'           => __( 'Sync now', 'kdna-ecommerce-insights' ),
			'syncing'           => __( 'Syncing...', 'kdna-ecommerce-insights' ),
			'test'              => __( 'Test connection', 'kdna-ecommerce-insights' ),
			'testing'           => __( 'Testing...', 'kdna-ecommerce-insights' ),
			'disconnect'        => __( 'Disconnect', 'kdna-ecommerce-insights' ),
			'disconnectConfirm' => __( 'Disconnect? Spend already synced is kept.', 'kdna-ecommerce-insights' ),
			'yesDisconnect'     => __( 'Yes, disconnect', 'kdna-ecommerce-insights' ),
			'keep'              => __( 'Keep', 'kdna-ecommerce-insights' ),
			'liveSynced'        => __( 'Live', 'kdna-ecommerce-insights' ),
			'liveHelp'          => __( 'Synced from a live connection. It updates itself; disconnect the platform to remove it.', 'kdna-ecommerce-insights' ),
			'metaTitle'         => __( 'Connect Meta ads', 'kdna-ecommerce-insights' ),
			'googleTitle'       => __( 'Connect Google Ads', 'kdna-ecommerce-insights' ),
			'howToGet'          => __( 'Where do I find these?', 'kdna-ecommerce-insights' ),
			'metaSteps'         => array(
				__( 'In Meta for Developers, create an app of type Business, linked to your business portfolio. Copy its App ID.', 'kdna-ecommerce-insights' ),
				__( 'In Business Settings, go to Users > System users and add a system user with the Employee role.', 'kdna-ecommerce-insights' ),
				__( 'Assign the system user to your ad account with "View performance" access, and to the app.', 'kdna-ecommerce-insights' ),
				__( 'Press Generate new token, choose the app, set expiry to Never and tick only ads_read. Copy the token.', 'kdna-ecommerce-insights' ),
				__( 'Your ad account ID is the number after "act=" in the Ads Manager web address.', 'kdna-ecommerce-insights' ),
			),
			'googleSteps'       => array(
				__( 'In Google Cloud, create a project, enable the Google Ads API, and set up the OAuth consent screen (publish it so sign-in does not expire after 7 days).', 'kdna-ecommerce-insights' ),
				__( 'Create an OAuth client ID of type Web application and add the redirect address shown here. Copy the client ID and secret.', 'kdna-ecommerce-insights' ),
				__( 'In your Google Ads manager account, open Admin > API Centre, apply for a developer token (Explorer access is enough) and copy it.', 'kdna-ecommerce-insights' ),
				__( 'Copy the customer ID from the top of Google Ads, and the manager account ID if you reach the ad account through one.', 'kdna-ecommerce-insights' ),
				__( 'Save, then sign in with a Google account that can see the ad account.', 'kdna-ecommerce-insights' ),
			),
			'tokenSaved'        => __( 'Saved. Paste a new one to replace it.', 'kdna-ecommerce-insights' ),
			'tokenKeep'         => __( 'A token is saved and encrypted. Leave this empty to keep it.', 'kdna-ecommerce-insights' ),
			'tokenHelp'         => __( 'Stored encrypted on this site and never shown again, not even to administrators.', 'kdna-ecommerce-insights' ),
			'copy'              => __( 'Copy', 'kdna-ecommerce-insights' ),
			'copied'            => __( 'Copied', 'kdna-ecommerce-insights' ),
			'redirectHelp'      => __( 'Add this exact address under "Authorised redirect URIs" in your OAuth client in Google Cloud.', 'kdna-ecommerce-insights' ),
			'signedIn'          => __( 'Signed in with Google. Saving keeps you signed in unless the client ID changes.', 'kdna-ecommerce-insights' ),
			'signInAfter'       => __( 'After saving, you will be taken to Google to sign in and allow read-only access to your ads.', 'kdna-ecommerce-insights' ),
			'rateField'         => __( 'Ad account currency rate', 'kdna-ecommerce-insights' ),
			'rateFieldHelp'     => __( 'If your ad accounts spend in a different currency, how much 1 unit is worth in your store currency. Leave at 1 if they match.', 'kdna-ecommerce-insights' ),
			'frequency'         => __( 'Sync automatically', 'kdna-ecommerce-insights' ),
			'daily'             => __( 'Once a day (about 5am)', 'kdna-ecommerce-insights' ),
			'twiceDaily'        => __( 'Twice a day', 'kdna-ecommerce-insights' ),
			'manualOnly'        => __( 'Only when I press Sync now', 'kdna-ecommerce-insights' ),
			'saveAndTest'       => __( 'Save and test', 'kdna-ecommerce-insights' ),
			'saveAndSignIn'     => __( 'Save and sign in with Google', 'kdna-ecommerce-insights' ),
			'connectedOk'       => __( 'Connected. Press Sync now to bring spend in straight away.', 'kdna-ecommerce-insights' ),
			'savedOnly'         => __( 'Saved.', 'kdna-ecommerce-insights' ),
			/* translators: %s: platform. */
			'savedConnection'   => __( '%s details saved.', 'kdna-ecommerce-insights' ),
			/* translators: %s: ad account name. */
			'testOk'            => __( 'Connection works: %s.', 'kdna-ecommerce-insights' ),
			/* translators: 1: campaign days, 2: spend, 3: dates. */
			'syncOk'            => __( 'Synced %1$s campaign days, %2$s spend, for %3$s.', 'kdna-ecommerce-insights' ),
			/* translators: %s: amount. */
			'syncReplaced'      => __( 'This replaced %s added by hand or by CSV for the same dates, so nothing is counted twice.', 'kdna-ecommerce-insights' ),
			/* translators: %s: platform. */
			'disconnected'      => __( '%s disconnected. Spend already synced has been kept.', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Settings screen script.
	 *
	 * @return array
	 */
	private static function settings_strings(): array {
		return array(
			'save'             => __( 'Save', 'kdna-ecommerce-insights' ),
			'saving'           => __( 'Saving', 'kdna-ecommerce-insights' ),
			'sending'          => __( 'Sending', 'kdna-ecommerce-insights' ),
			'saved'            => __( 'Saved.', 'kdna-ecommerce-insights' ),
			'savedBranding'    => __( 'Saved. The new look is applied everywhere.', 'kdna-ecommerce-insights' ),
			'resetDone'        => __( 'Back to the defaults.', 'kdna-ecommerce-insights' ),
			'fixFields'        => __( 'Please check the highlighted fields.', 'kdna-ecommerce-insights' ),
			'statusError'      => __( 'Choose at least one order status that counts as a sale, usually Processing and Completed.', 'kdna-ecommerce-insights' ),
			'colourError'      => __( 'Enter a colour code like #5A6FE0.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of products. */
			'missingCosts'     => __( '%s products or variations have no cost price yet.', 'kdna-ecommerce-insights' ),
			'allCosts'         => __( 'Every product has a cost price.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of rules. */
			'ruleCount'        => __( '%s set up. Everything else uses the actual cost or none.', 'kdna-ecommerce-insights' ),
			/* translators: %s: order meta key. */
			'metaKey'          => __( 'Real label costs are read from %s.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of costs. */
			'extraCount'       => __( '%s set up, such as packaging or fulfilment.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of overheads. */
			'overheadCount'    => __( '%s running costs such as rent, software or wages.', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of ad spend rows. */
			'channelRows'      => __( '%s days of spend', 'kdna-ecommerce-insights' ),
			'channelUnused'    => __( 'No spend yet', 'kdna-ecommerce-insights' ),
			'channelNew'       => __( 'New', 'kdna-ecommerce-insights' ),
			'channelLocked'    => __( 'This channel has spend, so it cannot be removed. You can rename it.', 'kdna-ecommerce-insights' ),
			'removeChannel'    => __( 'Remove channel', 'kdna-ecommerce-insights' ),
			'anyChannel'       => __( 'Channel chosen when importing', 'kdna-ecommerce-insights' ),
			/* translators: %s: channel name. */
			'presetGst'        => __( '%s, amounts include GST', 'kdna-ecommerce-insights' ),
			/* translators: %s: layout name. */
			'presetDeleted'    => __( 'Deleted "%s".', 'kdna-ecommerce-insights' ),
			'notConnected'     => __( 'Not connected.', 'kdna-ecommerce-insights' ),
			/* translators: %s: date and time. */
			'lastSync'         => __( 'last synced %s', 'kdna-ecommerce-insights' ),
			'states'           => array(
				'not_set_up' => __( 'Not set up', 'kdna-ecommerce-insights' ),
				'ready'      => __( 'Ready', 'kdna-ecommerce-insights' ),
				'connected'  => __( 'Connected', 'kdna-ecommerce-insights' ),
				'error'      => __( 'Needs attention', 'kdna-ecommerce-insights' ),
			),
			/* translators: %s: store currency code, such as AUD. */
			'rateHelp'         => __( 'How much 1 unit of the ad account\'s currency is worth in %s. Use 1 when the ad account spends in the same currency as the store.', 'kdna-ecommerce-insights' ),
			'colours'          => array(
				'accent'       => __( 'Accent', 'kdna-ecommerce-insights' ),
				'accentHelp'   => __( 'Main chart lines, buttons and highlights', 'kdna-ecommerce-insights' ),
				'accent_2'     => __( 'Second accent', 'kdna-ecommerce-insights' ),
				'accent_2Help' => __( 'Comparison lines and second segments', 'kdna-ecommerce-insights' ),
				'positive'     => __( 'Positive', 'kdna-ecommerce-insights' ),
				'positiveHelp' => __( 'Good changes and healthy stock', 'kdna-ecommerce-insights' ),
				'warning'      => __( 'Warning', 'kdna-ecommerce-insights' ),
				'warningHelp'  => __( 'Low stock and missing costs', 'kdna-ecommerce-insights' ),
				'negative'     => __( 'Negative', 'kdna-ecommerce-insights' ),
				'negativeHelp' => __( 'Losses, bad changes and out of stock', 'kdna-ecommerce-insights' ),
			),
			'lowContrastDark'  => __( 'Hard to see on dark cards. Try a lighter shade.', 'kdna-ecommerce-insights' ),
			'lowContrastLight' => __( 'Hard to see on white cards. Try a darker shade.', 'kdna-ecommerce-insights' ),
			'chooseLogo'       => __( 'Choose a logo', 'kdna-ecommerce-insights' ),
			'changeLogo'       => __( 'Change logo', 'kdna-ecommerce-insights' ),
			'logoTitle'        => __( 'Choose a logo for Insights', 'kdna-ecommerce-insights' ),
			'logoButton'       => __( 'Use this logo', 'kdna-ecommerce-insights' ),
			'noMedia'          => __( 'The Media Library could not be opened. Please reload the page and try again.', 'kdna-ecommerce-insights' ),
			'previewLabel'     => __( 'Preview of the dashboard with the chosen name, logo, font and colours', 'kdna-ecommerce-insights' ),
			'noGoal'           => __( 'The Goals tracker needs a target for the goal you track. Add one above, or it will ask for one on the Overview.', 'kdna-ecommerce-insights' ),
			/* translators: %s: the store-wide WooCommerce low stock amount. */
			'lowStockHelp'     => __( 'Use 0 to follow each product\'s own low stock amount, or the WooCommerce store setting (%s units) where a product has none.', 'kdna-ecommerce-insights' ),
			'testAlert'        => __( 'Send a test stock alert', 'kdna-ecommerce-insights' ),
			'testDigest'       => __( 'Send a test digest', 'kdna-ecommerce-insights' ),
			'saveFirst'        => __( 'Save first, so the test goes to the new addresses.', 'kdna-ecommerce-insights' ),
			'digestOff'        => __( 'Digest emails are switched off.', 'kdna-ecommerce-insights' ),
			'digestSoon'       => __( 'The next digest will be scheduled within a minute or two.', 'kdna-ecommerce-insights' ),
			/* translators: %s: date and time. */
			'digestNext'       => __( 'The next digest goes out %s.', 'kdna-ecommerce-insights' ),
			/* translators: 1: page number, 2: number of pages. */
			'pageOf'           => __( 'Page %1$s of %2$s', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Tax & Reports screen script.
	 *
	 * @return array<string, string>
	 */
	private static function reports_strings(): array {
		return array(
			'gstTitle'       => __( 'GST summary', 'kdna-ecommerce-insights' ),
			'taxTitle'       => __( 'Tax summary', 'kdna-ecommerce-insights' ),
			'lastMonth'      => __( 'Last full month', 'kdna-ecommerce-insights' ),
			'lastQuarter'    => __( 'Last full quarter', 'kdna-ecommerce-insights' ),
			/* translators: 1: first day, 2: last day. */
			'covers'         => __( '%1$s to %2$s', 'kdna-ecommerce-insights' ),
			'salesNote'      => __( 'Everything customers paid, including tax and shipping, less refunds.', 'kdna-ecommerce-insights' ),
			'onSalesNote'    => __( 'Tax charged on orders, less tax refunded.', 'kdna-ecommerce-insights' ),
			/* translators: 1: tax on overheads, 2: tax on ad spend. */
			'onCostsNote'    => __( 'Estimate: %1$s from overheads and %2$s from ad spend marked as including tax.', 'kdna-ecommerce-insights' ),
			/* translators: %s: GST, VAT, Tax or Sales tax. */
			'toPay'          => __( '%s to pay', 'kdna-ecommerce-insights' ),
			/* translators: %s: GST, VAT, Tax or Sales tax. */
			'refundDue'      => __( '%s refund due', 'kdna-ecommerce-insights' ),
			'nothingDue'     => __( 'Nothing to pay or claim', 'kdna-ecommerce-insights' ),
			'inProgress'     => __( 'Still running', 'kdna-ecommerce-insights' ),
			'inProgressHelp' => __( 'This period has not finished yet, so its figures will change.', 'kdna-ecommerce-insights' ),
			'partial'        => __( 'Part period', 'kdna-ecommerce-insights' ),
			/* translators: 1: first day, 2: last day. */
			'partialHelp'    => __( 'Only %1$s to %2$s is included, because the chosen dates cover part of this period.', 'kdna-ecommerce-insights' ),
			'rateError'      => __( 'Enter a rate between 0 and 100, for example 10.', 'kdna-ecommerce-insights' ),
			'saved'          => __( 'Saved. The digest is scheduled.', 'kdna-ecommerce-insights' ),
			'savedOff'       => __( 'Saved. Digest emails are switched off.', 'kdna-ecommerce-insights' ),
			'digestOff'      => __( 'Digest emails are switched off.', 'kdna-ecommerce-insights' ),
			'notScheduled'   => __( 'Not scheduled yet. It will be scheduled within a minute or two.', 'kdna-ecommerce-insights' ),
			/* translators: 1: date and time, 2: first day covered, 3: last day covered. */
			'nextSend'       => __( 'Next one goes out %1$s, covering %2$s to %3$s.', 'kdna-ecommerce-insights' ),
			/* translators: %s: date and time. */
			'lastSent'       => __( 'Last sent %s.', 'kdna-ecommerce-insights' ),
			/* translators: %s: date and time. */
			'lastFailed'     => __( 'The last digest, due %s, could not be sent. Check that this site can send email.', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Customers screen script.
	 *
	 * @return array
	 */
	private static function customers_strings(): array {
		return array(
			'allTime'            => __( 'All time', 'kdna-ecommerce-insights' ),
			'allTimeHelp'        => __( 'Worked out from every order ever placed, so it does not change with the date range.', 'kdna-ecommerce-insights' ),
			'newCustomers'       => __( 'New customers', 'kdna-ecommerce-insights' ),
			'returningCustomers' => __( 'Returning customers', 'kdna-ecommerce-insights' ),
			/* translators: %s: revenue. */
			'revenueOf'          => __( '%s revenue', 'kdna-ecommerce-insights' ),
			/* translators: %s: percentage. */
			'shareOf'            => __( '%s%% of revenue', 'kdna-ecommerce-insights' ),
			/* translators: 1: new customers, 2: returning customers. */
			'chartSummary'       => __( 'New and returning customers over the chosen dates: %s new and %s returning.', 'kdna-ecommerce-insights' ),
			'cohortCaption'      => __( 'Share of customers who ordered again, by the month of their first order and the months since.', 'kdna-ecommerce-insights' ),
			'firstMonth'         => __( 'First month', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of months. */
			'monthN'             => __( 'Month %s', 'kdna-ecommerce-insights' ),
			/* translators: 1: customers, 2: cohort size, 3: month. */
			'cellTitle'          => __( '%s of %s customers ordered again in %s', 'kdna-ecommerce-insights' ),
			/* translators: 1: customers, 2: cohort size, 3: month. */
			'cellFirst'          => __( '%s of %s customers placed their first order in %s', 'kdna-ecommerce-insights' ),
			'noCohorts'          => __( 'No customers placed their first order in these dates.', 'kdna-ecommerce-insights' ),
			'noCustomers'        => __( 'No customers ordered in this period.', 'kdna-ecommerce-insights' ),
			'guest'              => __( 'Guest', 'kdna-ecommerce-insights' ),
			'unknownPlace'       => __( 'Not given', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of rows. */
			'showAll'            => __( 'Show all %s', 'kdna-ecommerce-insights' ),
			'showFewer'          => __( 'Show fewer', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Inventory screen script.
	 *
	 * @return array
	 */
	private static function inventory_strings(): array {
		return array(
			'stockIsNow'     => __( 'Stock figures are as of right now. The date range only changes the stock value chart.', 'kdna-ecommerce-insights' ),
			'inStock'        => __( 'In stock', 'kdna-ecommerce-insights' ),
			'lowStock'       => __( 'Low stock', 'kdna-ecommerce-insights' ),
			'outOfStock'     => __( 'Out of stock', 'kdna-ecommerce-insights' ),
			'atCost'         => __( 'At cost', 'kdna-ecommerce-insights' ),
			'atRetail'       => __( 'At retail', 'kdna-ecommerce-insights' ),
			'trendNote'      => __( 'Saved every night, excluding tax.', 'kdna-ecommerce-insights' ),
			'trendEmpty'     => __( 'Insights saves your stock value every night. The chart appears once there are two nights of history.', 'kdna-ecommerce-insights' ),
			/* translators: 1: value at cost, 2: value at retail. */
			'trendSummary'   => __( 'Stock value over the chosen dates. Latest: %s at cost and %s at retail.', 'kdna-ecommerce-insights' ),
			/* translators: %s: units. */
			'unitsInStock'   => __( '%s units in stock', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of products. */
			'noCostHelp'     => __( '%s products in stock have no cost price, so their value at cost is left out.', 'kdna-ecommerce-insights' ),
			/* translators: %s: lead time in days. */
			'coverNote'      => __( 'Based on how fast each product sold over the last 30 days, with a %s day supplier lead time.', 'kdna-ecommerce-insights' ),
			'reorderNow'     => __( 'Reorder now', 'kdna-ecommerce-insights' ),
			/* translators: %s: threshold. */
			'thresholdOwn'   => __( 'Products with %s or fewer left (your Insights setting).', 'kdna-ecommerce-insights' ),
			/* translators: %s: threshold. */
			'thresholdStore' => __( 'At or below each product\'s low stock amount, or %s for products without one (your WooCommerce setting).', 'kdna-ecommerce-insights' ),
			'outNote'        => __( 'Busiest sellers first, so you can see what is costing you sales.', 'kdna-ecommerce-insights' ),
			/* translators: 1: days, 2: value at cost. */
			'deadNote'       => __( 'In stock with no sale in %s days. %s tied up at cost.', 'kdna-ecommerce-insights' ),
			'noRecentSales'  => __( 'No recent sales', 'kdna-ecommerce-insights' ),
			'never'          => __( 'Never', 'kdna-ecommerce-insights' ),
			'notTracked'     => __( 'Not tracked', 'kdna-ecommerce-insights' ),
			/* translators: %s: number of rows. */
			'showAll'        => __( 'Show all %s', 'kdna-ecommerce-insights' ),
			'showFewer'      => __( 'Show fewer', 'kdna-ecommerce-insights' ),
			/* translators: %s: WooCommerce threshold. */
			'useStore'       => __( 'Use WooCommerce (%s)', 'kdna-ecommerce-insights' ),
			'thresholdHelp'  => __( 'Leave empty to use each product\'s own low stock amount from WooCommerce.', 'kdna-ecommerce-insights' ),
			'wholeNumber'    => __( 'Enter a whole number, for example 5, or leave it empty.', 'kdna-ecommerce-insights' ),
			'deadDaysError'  => __( 'Enter a whole number of days, 1 or more, for example 90.', 'kdna-ecommerce-insights' ),
			'leadDaysError'  => __( 'Enter a whole number of days from 0 to 365, for example 14.', 'kdna-ecommerce-insights' ),
			'emailError'     => __( 'Enter at least one email address, separating more than one with commas.', 'kdna-ecommerce-insights' ),
			'saved'          => __( 'Saved. Figures updated.', 'kdna-ecommerce-insights' ),
			'sending'        => __( 'Sending...', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Text used by the Products screen script.
	 *
	 * @return array
	 */
	private static function products_strings(): array {
		return array(
			'tableCaption'    => __( 'Product performance for the chosen dates. Column headings sort the table.', 'kdna-ecommerce-insights' ),
			'noSales'         => __( 'No products sold in this period.', 'kdna-ecommerce-insights' ),
			'noMatches'       => __( 'No products match these filters.', 'kdna-ecommerce-insights' ),
			/* translators: 1: first row, 2: last row, 3: total rows. */
			'showing'         => __( 'Showing %s to %s of %s', 'kdna-ecommerce-insights' ),
			/* translators: 1: page, 2: pages. */
			'pageOf'          => __( 'Page %s of %s', 'kdna-ecommerce-insights' ),
			'missingCost'     => __( 'No cost', 'kdna-ecommerce-insights' ),
			'missingCostHelp' => __( 'Some sales of this product had no cost price, so its profit is overstated.', 'kdna-ecommerce-insights' ),
			'revenue'         => __( 'Revenue', 'kdna-ecommerce-insights' ),
			'profit'          => __( 'Profit', 'kdna-ecommerce-insights' ),
			'margin'          => __( 'Margin', 'kdna-ecommerce-insights' ),
			'units'           => __( 'Units', 'kdna-ecommerce-insights' ),
			'orders'          => __( 'Orders', 'kdna-ecommerce-insights' ),
			'refundRate'      => __( 'Refund rate', 'kdna-ecommerce-insights' ),
			'variation'       => __( 'Variation', 'kdna-ecommerce-insights' ),
			'byVariation'     => __( 'By variation', 'kdna-ecommerce-insights' ),
			'allVariations'   => __( 'All variations', 'kdna-ecommerce-insights' ),
			'salesAndProfit'  => __( 'Sales and profit', 'kdna-ecommerce-insights' ),
			/* translators: 1: revenue, 2: profit. */
			'chartSummary'    => __( 'Revenue and profit over the chosen dates: %s revenue and %s profit in total.', 'kdna-ecommerce-insights' ),
			/* translators: %s: units. */
			'unitsSold'       => __( '%s sold', 'kdna-ecommerce-insights' ),
			/* translators: %s: SKU. */
			'sku'             => __( 'SKU %s', 'kdna-ecommerce-insights' ),
			'details'         => __( 'Details', 'kdna-ecommerce-insights' ),
			'price'           => __( 'Price (excluding tax)', 'kdna-ecommerce-insights' ),
			'costPrice'       => __( 'Cost price', 'kdna-ecommerce-insights' ),
			'noCost'          => __( 'Not set', 'kdna-ecommerce-insights' ),
			'stock'           => __( 'Stock', 'kdna-ecommerce-insights' ),
			/* translators: %s: stock quantity. */
			'inStockCount'    => __( '%s in stock', 'kdna-ecommerce-insights' ),
			'stockStatuses'   => array(
				'instock'     => __( 'In stock', 'kdna-ecommerce-insights' ),
				'outofstock'  => __( 'Out of stock', 'kdna-ecommerce-insights' ),
				'onbackorder' => __( 'On backorder', 'kdna-ecommerce-insights' ),
			),
			'editProduct'     => __( 'Edit product', 'kdna-ecommerce-insights' ),
			'addCost'         => __( 'Add a cost', 'kdna-ecommerce-insights' ),
		);
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

		// Every font in the Branding list is bundled (assets/fonts). "Inherit"
		// uses the computer's own interface font in wp-admin.
		$font  = (string) KDNA_EcommerceInsights_Settings::get( 'branding.font', 'figtree' );
		$fonts = KDNA_EcommerceInsights_Settings::fonts();
		if ( 'inherit' === $font ) {
			$css .= '.kdna-ei-root{--kdna-ei-font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;}';
		} elseif ( 'figtree' !== $font && isset( $fonts[ $font ] ) ) {
			$css .= '.kdna-ei-root{--kdna-ei-font:"' . esc_attr( $fonts[ $font ] ) . '",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;}';
		}

		return $css;
	}

	/**
	 * The client's custom CSS from Settings > Branding, cleaned again on the
	 * way out so it can never close the style block.
	 *
	 * @return string
	 */
	public static function custom_css(): string {
		return KDNA_EcommerceInsights_Settings::clean_css( (string) KDNA_EcommerceInsights_Settings::get( 'branding.custom_css', '' ) );
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
