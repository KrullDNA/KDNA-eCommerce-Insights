<?php
/**
 * Plugin Name:          KDNA eCommerce Insights
 * Plugin URI:           https://krulldna.com
 * Description:          Shows what a WooCommerce store actually made, not just what it sold. Profit, inventory, customer and marketing insights in a designed wp-admin dashboard and Elementor widgets.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Krull Design & Advertising (KDNA)
 * Author URI:           https://krulldna.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          kdna-ecommerce-insights
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      10.2
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/*
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 */

define( 'KDNA_EI_VERSION', '1.0.0' );
define( 'KDNA_EI_DB_VERSION', '1.0.2' );
define( 'KDNA_EI_FILE', __FILE__ );
define( 'KDNA_EI_PATH', plugin_dir_path( __FILE__ ) );
define( 'KDNA_EI_URL', plugin_dir_url( __FILE__ ) );
define( 'KDNA_EI_BASENAME', plugin_basename( __FILE__ ) );
define( 'KDNA_EI_REST_NAMESPACE', 'kdna-ei/v1' );
define( 'KDNA_EI_MIN_WC_VERSION', '9.0' );

/*
 * -------------------------------------------------------------------------
 * Class autoloader
 * -------------------------------------------------------------------------
 */

/**
 * Loads a plugin class file the first time the class is used.
 *
 * Class names follow the pattern KDNA_EcommerceInsights_Order_Processor and
 * live in files named class-kdna-ei-order-processor.php, so we can work out
 * the file name from the class name and look in each plugin folder for it.
 *
 * @param string $class_name The class PHP is trying to load.
 */
function kdna_ei_autoload( $class_name ) {
	$prefix = 'KDNA_EcommerceInsights_';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$slug = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
	$file = 'class-kdna-ei-' . $slug . '.php';

	$folders = array(
		'includes/',
		'includes/rest/',
		'includes/integrations/',
		'admin/',
		'elementor/',
		'elementor/widgets/',
	);

	foreach ( $folders as $folder ) {
		$path = KDNA_EI_PATH . $folder . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'kdna_ei_autoload' );

/*
 * -------------------------------------------------------------------------
 * WooCommerce feature compatibility
 * -------------------------------------------------------------------------
 */

/**
 * Tells WooCommerce this plugin works with High Performance Order Storage
 * (HPOS) and with the native Cost of Goods Sold feature, so WooCommerce does
 * not show an incompatibility warning when either is switched on.
 */
function kdna_ei_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KDNA_EI_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cost_of_goods_sold', KDNA_EI_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'kdna_ei_declare_wc_compatibility' );

/*
 * -------------------------------------------------------------------------
 * Activation and start-up
 * -------------------------------------------------------------------------
 */

register_activation_hook( __FILE__, array( 'KDNA_EcommerceInsights_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KDNA_EcommerceInsights_Install', 'deactivate' ) );

/**
 * Starts the plugin once all plugins have loaded.
 *
 * Database upgrades always run, so tables stay current even while
 * WooCommerce is switched off. Everything else only starts when WooCommerce
 * is active and new enough, otherwise a friendly notice explains why.
 */
function kdna_ei_boot() {
	KDNA_EcommerceInsights_Install::maybe_upgrade();

	$problem = KDNA_EcommerceInsights_Requirements::problem();
	if ( $problem ) {
		add_action( 'admin_notices', array( 'KDNA_EcommerceInsights_Requirements', 'render_notice' ) );
		return;
	}

	KDNA_EcommerceInsights_Plugin::instance();
}
add_action( 'plugins_loaded', 'kdna_ei_boot', 20 );
