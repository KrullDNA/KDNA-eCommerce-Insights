<?php
/**
 * Tests for Stage 13: the Elementor widgets. Category and widget
 * registration, Atomic markup, front-end privacy (Administrators only,
 * Restricted card or nothing for everyone else), noindex and no caching,
 * the editor-only Preview state, sample data adding up, and every style
 * control being scoped to its own widget instance.
 *
 * Runs inside WordPress with WooCommerce, Elementor and the plugin active,
 * on a test site only:
 *
 *     wp eval-file tests/test-elementor.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-elementor.php\n" );

$GLOBALS['kdna_ei_te_count'] = 0;
$GLOBALS['kdna_ei_te_fail']  = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Shown on failure.
 */
function kdna_ei_te( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_te_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_te_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : ' (' . ( is_scalar( $note ) ? $note : wp_json_encode( $note ) ) . ')' ) . "\n";
}

/**
 * Renders one widget as the current user and returns its HTML.
 *
 * @param string $type     Widget name.
 * @param array  $settings Widget settings.
 * @return string
 */
function kdna_ei_te_render( string $type, array $settings = array() ): string {
	$widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
		array(
			'id'         => substr( md5( wp_json_encode( $settings ) . $type . wp_rand() ), 0, 7 ),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
		)
	);
	ob_start();
	$widget->print_element();
	return (string) ob_get_clean();
}

/**
 * The settings JSON printed on a widget root, or null.
 *
 * @param string $html Widget HTML.
 * @return array|null
 */
function kdna_ei_te_settings( string $html ): ?array {
	if ( ! preg_match( '/data-kdna-ei-settings="([^"]*)"/', $html, $m ) ) {
		return null;
	}
	return json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}

if ( ! class_exists( '\Elementor\Plugin' ) ) {
	echo "Elementor is not active, so there is nothing to test.\n";
	return;
}

/**
 * Sets a private static property, to reset state between checks that a
 * real site would make in separate page loads.
 *
 * @param string $class    Class name.
 * @param string $property Property name.
 * @param mixed  $value    Value.
 */
function kdna_ei_te_static( string $class, string $property, $value ): void {
	$reflection = new ReflectionProperty( $class, $property );
	$reflection->setAccessible( true );
	$reflection->setValue( null, $value );
}

// Elementor leaves style controls out on the front end to save time; load
// them all, as the editor does, so they can be checked.
kdna_ei_te_static( '\Elementor\Core\Frontend\Performance', 'is_frontend', false );

$admin      = 1;
$subscriber = wp_insert_user(
	array(
		'user_login' => 'kdna_ei_te_sub_' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
$manager    = wp_insert_user(
	array(
		'user_login' => 'kdna_ei_te_mgr_' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'shop_manager',
	)
);

/*
 * Category and widgets.
 */
$elementor = \Elementor\Plugin::$instance;
$cats      = $elementor->elements_manager->get_categories();
kdna_ei_te( 'KDNA Tools category is registered', isset( $cats['kdna-tools'] ) );
$widgets = $elementor->widgets_manager->get_widget_types();
kdna_ei_te( 'Insights Dashboard widget is registered', isset( $widgets['kdna-ei-dashboard'] ) );
kdna_ei_te( 'Insights Date Range widget is registered', isset( $widgets['kdna-ei-date-range'] ) );
foreach ( array( 'kdna-ei-dashboard', 'kdna-ei-date-range' ) as $name ) {
	kdna_ei_te( $name . ' sits in the KDNA Tools category', in_array( 'kdna-tools', $widgets[ $name ]->get_categories(), true ) );
	$optimised = $elementor->experiments->is_feature_active( 'e_optimized_markup' );
	kdna_ei_te( $name . ' has no inner wrapper when optimised markup is on', $widgets[ $name ]->has_widget_inner_wrapper() === ! $optimised );
	kdna_ei_te( $name . ' loads the widget stylesheet', in_array( 'kdna-ei-widgets', $widgets[ $name ]->get_style_depends(), true ) );
}

// Registering the category twice must not throw or duplicate it.
KDNA_EcommerceInsights_Elementor::register_category( $elementor->elements_manager );
kdna_ei_te( 'Registering the category again is harmless', 1 === count( array_filter( array_keys( $elementor->elements_manager->get_categories() ), static fn( $k ) => 'kdna-tools' === $k ) ) );

/*
 * Every style control is scoped to its own widget instance.
 */
foreach ( array( 'kdna-ei-dashboard', 'kdna-ei-date-range' ) as $name ) {
	$unscoped = array();
	$inner    = array();
	$count    = 0;
	foreach ( $widgets[ $name ]->get_controls() as $id => $control ) {
		$selectors = (array) ( $control['selectors'] ?? array() );
		if ( ! empty( $control['selector'] ) ) {
			$selectors[ $control['selector'] ] = '';
		}
		foreach ( array_keys( $selectors ) as $selector ) {
			++$count;
			foreach ( explode( ',', $selector ) as $part ) {
				if ( false === strpos( $part, '{{WRAPPER}}' ) ) {
					$unscoped[] = $id;
				}
			}
			if ( false !== strpos( $selector, 'elementor-widget-container' ) ) {
				$inner[] = $id;
			}
		}
	}
	kdna_ei_te( $name . ' has style controls (' . $count . ' selectors)', $count > ( 'kdna-ei-dashboard' === $name ? 200 : 40 ) );
	kdna_ei_te( $name . ' scopes every selector to the widget instance', ! $unscoped, array_unique( $unscoped ) );
	kdna_ei_te( $name . ' never targets .elementor-widget-container', ! $inner, $inner );
	$responsive = 0;
	foreach ( $widgets[ $name ]->get_controls() as $control ) {
		if ( ! empty( $control['responsive'] ) ) {
			++$responsive;
		}
	}
	kdna_ei_te( $name . ' has responsive dimension controls', $responsive > 10, $responsive );
}

/*
 * Who can see figures.
 */
wp_set_current_user( 0 );
kdna_ei_te( 'Logged-out visitors cannot see figures', ! KDNA_EcommerceInsights_Elementor::can_view() );
kdna_ei_te( 'Logged-out visitors get no widget script', array() === KDNA_EcommerceInsights_Elementor::script_handles() );
wp_set_current_user( $subscriber );
kdna_ei_te( 'Subscribers cannot see figures', ! KDNA_EcommerceInsights_Elementor::can_view() );
wp_set_current_user( $manager );
kdna_ei_te( 'Shop Managers cannot see figures', ! KDNA_EcommerceInsights_Elementor::can_view() );
wp_set_current_user( $admin );
kdna_ei_te( 'Administrators can see figures', KDNA_EcommerceInsights_Elementor::can_view() );
kdna_ei_te( 'Administrators get the widget script', array( 'kdna-ei-widgets' ) === KDNA_EcommerceInsights_Elementor::script_handles() );

/*
 * What each person sees.
 */
$figure_words = array( 'net_revenue', 'data-kdna-ei-settings', 'wp_rest', 'Hydrating Face Serum' );

wp_set_current_user( 0 );
$html = kdna_ei_te_render( 'kdna-ei-dashboard', array( 'kdna_title' => 'Store' ) );
kdna_ei_te( 'Logged out: Restricted card by default', false !== strpos( $html, 'kdna-ei-state--restricted' ) );
kdna_ei_te( 'Logged out: no settings, figures or nonce in the markup', ! array_filter( $figure_words, static fn( $w ) => false !== strpos( $html, $w ) ) );
kdna_ei_te( 'Logged out: no inner widget container', false === strpos( $html, 'elementor-widget-container' ) || ! $elementor->experiments->is_feature_active( 'e_optimized_markup' ) );
$html = kdna_ei_te_render(
	'kdna-ei-dashboard',
	array(
		'kdna_restricted_title'   => 'Members only',
		'kdna_restricted_message' => 'Please log in.',
		'kdna_login_button'       => 'yes',
		'kdna_login_text'         => 'Sign in',
	)
);
kdna_ei_te( 'Logged out: Restricted card shows a log in button', false !== strpos( $html, 'wp-login.php' ) && false !== strpos( $html, 'Sign in' ) );
$html = kdna_ei_te_render( 'kdna-ei-dashboard', array( 'kdna_restricted_mode' => 'nothing' ) );
kdna_ei_te( 'Logged out: "Show nothing" leaves no Insights markup', false === strpos( $html, 'kdna-ei-root' ) );
$html = kdna_ei_te_render( 'kdna-ei-date-range' );
kdna_ei_te( 'Logged out: Date Range shows nothing by default', false === strpos( $html, 'kdna-ei-range' ) );
$html = kdna_ei_te_render( 'kdna-ei-dashboard', array( 'kdna_preview' => 'sample' ) );
kdna_ei_te( 'Logged out: the Preview state has no effect on the front end', false !== strpos( $html, 'kdna-ei-state--restricted' ) && false === strpos( $html, 'Hydrating' ) );

wp_set_current_user( $subscriber );
$html = kdna_ei_te_render( 'kdna-ei-dashboard' );
kdna_ei_te( 'Subscriber: Restricted card, no figures', false !== strpos( $html, 'kdna-ei-state--restricted' ) && false === strpos( $html, 'data-kdna-ei-settings' ) );

wp_set_current_user( $admin );
$html     = kdna_ei_te_render( 'kdna-ei-dashboard', array( 'kdna_preview' => 'error' ) );
$settings = kdna_ei_te_settings( $html );
kdna_ei_te( 'Administrator: the dashboard is set up for live figures', is_array( $settings ) && 'live' === $settings['state'] );
kdna_ei_te( 'Administrator: the Preview state has no effect on the front end', is_array( $settings ) && 'live' === $settings['state'] && ! isset( $settings['sample'] ) );
kdna_ei_te( 'Administrator: figures are not printed into the page', false === strpos( $html, 'Hydrating' ) );
kdna_ei_te( 'Administrator: starts in the loading state', false !== strpos( $html, 'data-kdna-ei-state="loading"' ) );
kdna_ei_te( 'Administrator: the KPI strip, chart, inventory and hero are all there', 4 === preg_match_all( '/kdna-ei-area-(kpis|chart|inventory|hero)/', $html ) );
$html = kdna_ei_te_render(
	'kdna-ei-dashboard',
	array(
		'kdna_show_chart'     => '',
		'kdna_show_inventory' => '',
		'kdna_layout'         => 'stacked',
		'kdna_hero'           => 'goals',
	)
);
$settings = kdna_ei_te_settings( $html );
kdna_ei_te( 'Panel toggles remove panels', false === strpos( $html, 'kdna-ei-area-chart' ) && false === strpos( $html, 'kdna-ei-area-inventory' ) && false === $settings['panels']['chart'] );
kdna_ei_te( 'Layout and hero choices are passed on', false !== strpos( $html, 'data-layout="stacked"' ) && 'goals' === $settings['hero'] );
$html     = kdna_ei_te_render( 'kdna-ei-dashboard', array( 'kdna_follow_range' => '', 'kdna_fixed_range' => 'last_7_days', 'kdna_fixed_compare' => 'none' ) );
$settings = kdna_ei_te_settings( $html );
kdna_ei_te( 'A fixed date range is passed on', false === $settings['range']['follow'] && 'last_7_days' === $settings['range']['preset'] && 'none' === $settings['range']['compare'] );
$html = kdna_ei_te_render( 'kdna-ei-date-range', array( 'kdna_presets' => array( 'today', 'last_7_days' ) ) );
kdna_ei_te( 'Date Range offers only the chosen ranges, plus custom dates', 3 === preg_match_all( '/data-kdna-ei-preset="/', $html ) && false !== strpos( $html, 'data-kdna-ei-calendar' ) );
kdna_ei_te( 'Date Range offers the comparison choice', false !== strpos( $html, 'data-kdna-ei-compare="previous_year"' ) );
$html = kdna_ei_te_render( 'kdna-ei-date-range', array( 'kdna_custom' => '', 'kdna_compare' => '' ) );
kdna_ei_te( 'Date Range can hide custom dates and comparison', false === strpos( $html, 'data-kdna-ei-calendar' ) && false === strpos( $html, 'data-kdna-ei-compare' ) );
$html = kdna_ei_te_render( 'kdna-ei-dashboard', array( 'kdna_theme' => 'toggle' ) );
kdna_ei_te( 'Theme control "visitor can switch" adds the toggle', false !== strpos( $html, 'data-kdna-ei-action="theme"' ) );
$html = kdna_ei_te_render( 'kdna-ei-dashboard', array( 'kdna_theme' => 'light' ) );
kdna_ei_te( 'Theme control can force light mode', false !== strpos( $html, 'data-kdna-ei-theme="light"' ) );

/*
 * Configuration printed for the script.
 */
wp_set_current_user( 0 );
$scripts = wp_scripts();
kdna_ei_te_static( 'KDNA_EcommerceInsights_Elementor', 'config_added', false );
$scripts->registered['kdna-ei-format']->extra['before'] = array();
KDNA_EcommerceInsights_Elementor::add_config();
$before = implode( '', (array) $scripts->get_data( 'kdna-ei-format', 'before' ) );
kdna_ei_te( 'Logged out: no settings object is printed', false === strpos( $before, 'kdnaEiApp' ) );
wp_set_current_user( $admin );
KDNA_EcommerceInsights_Elementor::add_config();
$before = implode( '', (array) $scripts->get_data( 'kdna-ei-format', 'before' ) );
kdna_ei_te( 'Administrator: settings include the REST address and nonce', false !== strpos( $before, 'kdnaEiApp' ) && false !== strpos( $before, '"nonce":"' ) );

/*
 * Pages with an Insights widget: noindex and no caching.
 */
$page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Elementor test ' . wp_rand(),
	)
);
update_post_meta( $page, '_elementor_edit_mode', 'builder' );
update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'a1', 'elType' => 'container', 'elements' => array( array( 'id' => 'a2', 'elType' => 'widget', 'widgetType' => 'kdna-ei-date-range', 'settings' => array() ) ) ) ) ) ) );
$plain = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Plain test ' . wp_rand(),
	)
);
kdna_ei_te( 'A page with an Insights widget is found', KDNA_EcommerceInsights_Elementor::post_has_widget( $page ) );
kdna_ei_te( 'A page without one is not', ! KDNA_EcommerceInsights_Elementor::post_has_widget( $plain ) );

kdna_ei_te_static( 'KDNA_EcommerceInsights_Elementor', 'rendered', false );
$GLOBALS['wp_query']        = new WP_Query( array( 'page_id' => $page ) );
$GLOBALS['wp_the_query']    = $GLOBALS['wp_query'];
$robots                     = KDNA_EcommerceInsights_Elementor::robots( array() );
kdna_ei_te( 'Pages with an Insights widget are noindex, nofollow', ! empty( $robots['noindex'] ) && ! empty( $robots['nofollow'] ) );
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $plain ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$robots                  = KDNA_EcommerceInsights_Elementor::robots( array() );
kdna_ei_te( 'Other pages are left alone', empty( $robots['noindex'] ) );

// Over HTTP, when the test site is being served.
$response = wp_remote_get( get_permalink( $page ), array( 'timeout' => 20 ) );
if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
	$cache = (string) wp_remote_retrieve_header( $response, 'cache-control' );
	$tag   = (string) wp_remote_retrieve_header( $response, 'x-robots-tag' );
	$body  = wp_remote_retrieve_body( $response );
	kdna_ei_te( 'HTTP: page is sent with no-store', false !== strpos( $cache, 'no-store' ), $cache );
	kdna_ei_te( 'HTTP: page is sent with X-Robots-Tag noindex', false !== strpos( $tag, 'noindex' ), $tag );
	kdna_ei_te( 'HTTP: robots meta tag says noindex', (bool) preg_match( '/<meta name=.robots. content=.noindex/', $body ) );
	kdna_ei_te( 'HTTP: no Insights settings or nonce for visitors', false === strpos( $body, 'kdnaEiApp' ) && false === strpos( $body, 'kdna-ei-widgets.js' ) );
	$plain_response = wp_remote_get( get_permalink( $plain ), array( 'timeout' => 20 ) );
	kdna_ei_te( 'HTTP: other pages are not marked noindex by Insights', false === strpos( (string) wp_remote_retrieve_header( $plain_response, 'x-robots-tag' ), 'noindex' ) );
} else {
	echo "SKIP  HTTP checks (the test site is not being served)\n";
}

/*
 * Sample data adds up like live figures.
 */
$sample  = KDNA_EcommerceInsights_Widget_Sample::overview( 'average_order_value' );
$metrics = array_column( $sample['summary']['metrics'], null, 'key' );
kdna_ei_te( 'Sample data has five KPIs', 5 === count( $metrics ) );
$lines = array_column( $sample['waterfall'], 'amount', 'key' );
kdna_ei_te( 'Sample net profit matches the waterfall', abs( $metrics['net_profit']['value'] - $lines['net_profit'] ) < 0.01, array( $metrics['net_profit']['value'], $lines['net_profit'] ) );
$costs = 0;
foreach ( array( 'cogs', 'payment_fees', 'shipping_costs', 'extra_costs', 'ad_spend', 'overheads' ) as $key ) {
	$costs += abs( $lines[ $key ] ?? 0 );
}
kdna_ei_te( 'Sample revenue less every cost is net profit', abs( $lines['net_revenue'] - $costs - $lines['net_profit'] ) < 0.05 );
kdna_ei_te( 'Sample chart has 30 days with a comparison', 30 === count( $sample['timeseries']['buckets'] ) && 30 === count( $sample['timeseries']['series']['net_revenue']['previous'] ) );
kdna_ei_te( 'Sample chart adds up to the revenue KPI', abs( array_sum( $sample['timeseries']['series']['net_revenue']['current'] ) - $metrics['net_revenue']['value'] ) < 1 );
kdna_ei_te( 'Sample data is the same each time', wp_json_encode( $sample ) === wp_json_encode( KDNA_EcommerceInsights_Widget_Sample::overview( 'average_order_value' ) ) );
kdna_ei_te( 'Sample data has five top products', 5 === count( $sample['products'] ) );

/*
 * No em dashes in any widget text.
 */
$dash = array();
foreach ( glob( KDNA_EI_PATH . 'elementor/{,widgets/}*.php', GLOB_BRACE ) as $file ) {
	if ( false !== strpos( (string) file_get_contents( $file ), "\u{2014}" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$dash[] = basename( $file );
	}
}
kdna_ei_te( 'No em dashes in the widget files', ! $dash, $dash );

// Tidy up.
wp_delete_post( $page, true );
wp_delete_post( $plain, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $subscriber );
wp_delete_user( $manager );

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_te_count'], $GLOBALS['kdna_ei_te_fail'] );
