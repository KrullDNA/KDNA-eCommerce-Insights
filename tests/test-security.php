<?php
/**
 * Tests for Stage 15: the security rules in section 10.6. Every REST route
 * needs an Administrator and a valid nonce, CSV imports have a size limit
 * checked before any work, the Meta access token is only ever sent to
 * Meta, cost prices are Administrators only, tokens never appear in REST
 * replies, and debug output needs both the flag and an Administrator.
 *
 * Runs inside WordPress with WooCommerce and the plugin active, on a test
 * site only:
 *
 *     wp eval-file tests/test-security.php
 *
 * This folder is not part of the plugin and is never included in the zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit( "Run with: wp eval-file tests/test-security.php\n" );

$GLOBALS['kdna_ei_tsec_count'] = 0;
$GLOBALS['kdna_ei_tsec_fail']  = 0;

/**
 * Prints PASS or FAIL for one check.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param mixed  $note  Shown on failure.
 */
function kdna_ei_tsec( string $label, bool $ok, $note = '' ): void {
	++$GLOBALS['kdna_ei_tsec_count'];
	if ( ! $ok ) {
		++$GLOBALS['kdna_ei_tsec_fail'];
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . ( $ok || '' === $note ? '' : ' (' . ( is_scalar( $note ) ? $note : wp_json_encode( $note ) ) . ')' ) . "\n";
}

/**
 * Calls a plugin REST route as the current user.
 *
 * @param string $method HTTP method.
 * @param string $route  Route after the namespace.
 * @param array  $params Parameters.
 * @param bool   $nonce  Whether to send a valid nonce.
 * @return WP_REST_Response
 */
function kdna_ei_tsec_rest( string $method, string $route, array $params = array(), bool $nonce = true ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/kdna-ei/v1' . $route );
	if ( $nonce ) {
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	}
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}
	return rest_do_request( $request );
}

$kdna_ei_tsec_users = array();
foreach ( array( 'shop_manager', 'editor', 'subscriber' ) as $role ) {
	$kdna_ei_tsec_users[ $role ] = wp_insert_user(
		array(
			'user_login' => 'kdna_ei_tsec_' . $role . '_' . wp_rand(),
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
		)
	);
}

/*
 * Every route: Administrators with a nonce only.
 */
$routes = array();
foreach ( rest_get_server()->get_routes( 'kdna-ei/v1' ) as $route => $handlers ) {
	if ( '/kdna-ei/v1' === $route ) {
		continue;
	}
	foreach ( $handlers as $handler ) {
		$routes[] = array( $route, array_keys( array_filter( (array) $handler['methods'] ) )[0] ?? 'GET', $handler['permission_callback'] ?? null );
	}
}
kdna_ei_tsec( 'There are plugin routes to check (' . count( $routes ) . ')', count( $routes ) > 30 );
kdna_ei_tsec( 'Every route has a permission check', ! array_filter( $routes, static fn( $r ) => ! $r[2] || '__return_true' === $r[2] ) );

$refused = static function ( string $label ) use ( $routes ) {
	$open = array();
	foreach ( $routes as $route ) {
		// Fill in the placeholders in routes such as /products/(?P<id>\d+).
		$path     = preg_replace( '/\(\?P<id>[^)]+\)/', '1', $route[0] );
		$path     = preg_replace( '/\(\?P<group>[^)]+\)/', 'abcdefgh', $path );
		$path     = preg_replace( '/\(\?P<platform>[^)]+\)/', 'meta', $path );
		$path     = substr( $path, strlen( '/kdna-ei/v1' ) );
		$response = kdna_ei_tsec_rest( $route[1], $path, array(), 'nonce' === $label );
		// WordPress checks required fields before permissions, so an empty
		// request can be turned away as incomplete; nothing runs either way.
		$code = (string) ( $response->get_data()['code'] ?? '' );
		if ( ! in_array( $response->get_status(), array( 401, 403 ), true ) && ! in_array( $code, array( 'rest_missing_callback_param', 'rest_invalid_param' ), true ) ) {
			$open[] = $route[1] . ' ' . $route[0] . ' ' . $response->get_status();
		}
	}
	return $open;
};

wp_set_current_user( 0 );
$open = $refused( 'nonce' );
kdna_ei_tsec( 'Logged out: every route is refused', ! $open, $open );
foreach ( $kdna_ei_tsec_users as $role => $user ) {
	wp_set_current_user( $user );
	$open = $refused( 'nonce' );
	kdna_ei_tsec( ucfirst( str_replace( '_', ' ', $role ) ) . ': every route is refused', ! $open, $open );
}
wp_set_current_user( 1 );
$open = $refused( 'no nonce' );
kdna_ei_tsec( 'Administrator without a nonce: every route is refused', ! $open, $open );
kdna_ei_tsec( 'Administrator with a nonce: summary works', 200 === kdna_ei_tsec_rest( 'GET', '/summary' )->get_status() );

/*
 * Admin screen and admin-post handlers.
 */
wp_set_current_user( $kdna_ei_tsec_users['shop_manager'] );
kdna_ei_tsec( 'Shop Managers cannot open Insights', ! current_user_can( 'manage_options' ) );
kdna_ei_tsec( 'Shop Managers do not get the Cost price field', ! KDNA_EcommerceInsights_Costs::can_edit_costs() );

$product = new WC_Product_Simple();
$product->set_name( 'Security test product' );
$product->set_regular_price( '30' );
$product->save();
KDNA_EcommerceInsights_Costs::set_cost( $product, 10.0 );
$_POST['kdna_ei_cost'] = '1.00';
( new ReflectionClass( 'KDNA_EcommerceInsights_Costs' ) )->newInstanceWithoutConstructor()->save_product_field( $product );
kdna_ei_tsec( 'A Shop Manager saving a product cannot change its cost', 10.0 === (float) KDNA_EcommerceInsights_Costs::get_own_cost( $product ) );
ob_start();
( new ReflectionClass( 'KDNA_EcommerceInsights_Costs' ) )->newInstanceWithoutConstructor()->render_simple_field();
kdna_ei_tsec( 'A Shop Manager editing a product does not see the cost', false === strpos( (string) ob_get_clean(), 'kdna_ei_cost' ) );
wp_set_current_user( 1 );
( new ReflectionClass( 'KDNA_EcommerceInsights_Costs' ) )->newInstanceWithoutConstructor()->save_product_field( $product );
kdna_ei_tsec( 'An Administrator saving a product can change its cost', 1.0 === (float) KDNA_EcommerceInsights_Costs::get_own_cost( $product ) );
unset( $_POST['kdna_ei_cost'] );
$product->delete( true );

/*
 * CSV imports: size limit checked first.
 */
$big    = str_repeat( 'a,b,c' . "\n", (int) ( KDNA_EcommerceInsights_Csv_Import::MAX_BYTES / 6 ) + 10 );
$result = KDNA_EcommerceInsights_Ad_Spend_Import::read( "\xFF\xFE" . $big );
kdna_ei_tsec( 'A large ad spend file is refused before it is converted', is_wp_error( $result ) && 'kdna_ei_csv_too_large' === $result->get_error_code() );
$response = kdna_ei_tsec_rest( 'POST', '/costs/import/preview', array( 'csv' => $big ) );
kdna_ei_tsec( 'The cost import refuses files over 5 MB', 400 === $response->get_status() );
$response = kdna_ei_tsec_rest( 'POST', '/adspend/import/preview', array( 'csv' => $big ) );
kdna_ei_tsec( 'The ad spend import refuses files over 5 MB', 400 === $response->get_status() );

/*
 * The Meta access token only ever goes to Meta.
 */
$meta = new KDNA_EcommerceInsights_Meta_Ads();
$get  = new ReflectionMethod( $meta, 'get' );
$get->setAccessible( true );
$sent = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$sent ) {
		$sent[] = $url;
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
			'headers'  => array(),
		);
	},
	1,
	3
);
foreach ( array( 'https://evil.example.com/v20.0/act_1/insights', 'http://graph.facebook.com/v20.0/me', 'https://graph.facebook.com.evil.example/x' ) as $url ) {
	$result = $get->invoke( $meta, $url );
	kdna_ei_tsec( 'Meta token is not sent to ' . $url, is_wp_error( $result ) && 'kdna_ei_meta_bad_address' === $result->get_error_code() );
}
kdna_ei_tsec( 'Nothing was sent to those addresses', ! $sent, $sent );

/*
 * No secrets in REST replies.
 */
$kdna_ei_tsec_old_token = (string) KDNA_EcommerceInsights_Crypto::get( 'meta_token' );
KDNA_EcommerceInsights_Crypto::set( 'meta_token', 'SECRET-TOKEN-1234567890' );
foreach ( array( '/connections', '/settings', '/settings/context' ) as $route ) {
	$body = wp_json_encode( kdna_ei_tsec_rest( 'GET', $route )->get_data() );
	kdna_ei_tsec( 'No token in ' . $route, false === strpos( (string) $body, 'SECRET-TOKEN' ) );
}
$raw = wp_json_encode( get_option( KDNA_EcommerceInsights_Crypto::OPTION ) );
kdna_ei_tsec( 'The token is stored encrypted', false === strpos( (string) $raw, 'SECRET-TOKEN' ) );
kdna_ei_tsec( 'The token can be read back by Insights', 'SECRET-TOKEN-1234567890' === KDNA_EcommerceInsights_Crypto::get( 'meta_token' ) );
KDNA_EcommerceInsights_Crypto::set( 'meta_token', $kdna_ei_tsec_old_token );

/*
 * Debug output needs both the flag and an Administrator.
 */
wp_set_current_user( $kdna_ei_tsec_users['editor'] );
$response = kdna_ei_tsec_rest( 'GET', '/summary', array( 'kdna_ei_debug' => 1 ) );
kdna_ei_tsec( 'An Editor asking for debug output is refused', 403 === $response->get_status() || 401 === $response->get_status() );
wp_set_current_user( 1 );
$meta = kdna_ei_tsec_rest( 'GET', '/summary', array( 'preset' => 'last_7_days' ) )->get_data()['meta'];
kdna_ei_tsec( 'An Administrator without the flag gets no debug output', empty( $meta['debug'] ) );
$meta = kdna_ei_tsec_rest( 'GET', '/summary', array( 'preset' => 'last_7_days', 'kdna_ei_debug' => 1 ) )->get_data()['meta'];
kdna_ei_tsec( 'An Administrator with ?kdna_ei_debug=1 gets query timings', ! empty( $meta['debug'] ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $kdna_ei_tsec_users as $user ) {
	wp_delete_user( $user );
}

printf( "\n%d checks, %d failed\n", $GLOBALS['kdna_ei_tsec_count'], $GLOBALS['kdna_ei_tsec_fail'] );
