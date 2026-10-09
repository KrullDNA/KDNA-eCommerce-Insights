<?php
/**
 * Main plugin loader.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts each part of the plugin and connects it to WordPress.
 *
 * Only one copy of this class ever exists. Later build stages add their own
 * modules here (order processing, REST routes, Elementor widgets and so on).
 */
class KDNA_EcommerceInsights_Plugin {

	/**
	 * The single shared copy of this class.
	 *
	 * @var KDNA_EcommerceInsights_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared copy of the plugin, creating it the first time.
	 *
	 * @return KDNA_EcommerceInsights_Plugin
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Connects the plugin to WordPress. Kept private so the plugin can only be
	 * started once, through instance().
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'plugin_action_links_' . KDNA_EI_BASENAME, array( $this, 'plugin_action_links' ) );

		// Product costs: Cost price field, native COGS bridge and cost history.
		new KDNA_EcommerceInsights_Costs();
		new KDNA_EcommerceInsights_Cost_Catalogue();

		// Shipping costs, including the Insights box on the order screen.
		new KDNA_EcommerceInsights_Shipping();

		// Order processing engine, history backfill and daily summary.
		new KDNA_EcommerceInsights_Order_Processor();
		new KDNA_EcommerceInsights_Backfill();
		new KDNA_EcommerceInsights_Summary();
		new KDNA_EcommerceInsights_Inventory();

		// Report cache, cleared whenever figures change.
		new KDNA_EcommerceInsights_Cache();

		if ( is_admin() ) {
			new KDNA_EcommerceInsights_Admin();
		}
	}

	/**
	 * Loads translations from the plugin's languages folder.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'kdna-ecommerce-insights', false, dirname( KDNA_EI_BASENAME ) . '/languages' );
	}

	/**
	 * Registers the plugin's private REST API routes under kdna-ei/v1.
	 */
	public function register_rest_routes(): void {
		( new KDNA_EcommerceInsights_Rest_Preferences() )->register_routes();
		( new KDNA_EcommerceInsights_Rest_Status() )->register_routes();
		( new KDNA_EcommerceInsights_Rest_Costs() )->register_routes();
		( new KDNA_EcommerceInsights_Rest_Settings() )->register_routes();
		( new KDNA_EcommerceInsights_Rest_Overheads() )->register_routes();
		( new KDNA_EcommerceInsights_Rest_Jobs() )->register_routes();

		// Report routes from section 10.4 of the brief.
		$reports = array( 'Summary', 'Timeseries', 'Profit', 'Products', 'Customers', 'Inventory', 'Marketing', 'Tax', 'Export', 'Adspend' );
		foreach ( $reports as $report ) {
			$class = 'KDNA_EcommerceInsights_Rest_' . $report;
			( new $class() )->register_routes();
		}
	}

	/**
	 * Adds an "Open Insights" shortcut beside Deactivate on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG ) ),
					esc_html__( 'Open Insights', 'kdna-ecommerce-insights' )
				)
			);
		}
		return $links;
	}
}
