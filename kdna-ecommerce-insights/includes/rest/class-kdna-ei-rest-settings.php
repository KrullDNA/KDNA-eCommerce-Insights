<?php
/**
 * REST routes for reading and saving Insights settings.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the dashboard read and save settings without a page reload.
 *
 * Routes (Administrators only):
 * - GET  /settings          Every setting.
 * - POST /settings          Save changes to one or more tabs, checked first.
 * - POST /settings/reset    Put one tab back to its defaults.
 * - GET  /settings/context  What the Settings screen shows alongside the
 *                           settings: order statuses, fonts, logo, channel
 *                           use, connections, cost rule counts and the
 *                           brand CSS now in use.
 * - GET  /log               The sync log, newest first, a page at a time.
 * - GET  /costs/setup   What the Costs screen needs to build its rule
 *                       tables: installed gateways and shipping methods.
 */
class KDNA_EcommerceInsights_Rest_Settings {

	/**
	 * Tabs that have a Reset to defaults button. Costs and Marketing are
	 * left out on purpose: resetting them would wipe cost rules and channels.
	 */
	const RESETTABLE = array( 'general', 'tax', 'branding', 'hero', 'alerts' );

	/**
	 * Registers the settings routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $permission,
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/settings/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_tab' ),
				'permission_callback' => $permission,
				'args'                => array(
					'tab' => array(
						'type'     => 'string',
						'enum'     => self::RESETTABLE,
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/settings/context',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_context' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_log' ),
				'permission_callback' => $permission,
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 5,
						'maximum' => 100,
						'default' => 25,
					),
					'type'     => array( 'type' => 'string' ),
					'status'   => array(
						'type' => 'string',
						'enum' => array( '', 'success', 'warning', 'error' ),
					),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/costs/setup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_costs_setup' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Only Administrators with a valid REST nonce may use these routes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		return KDNA_EcommerceInsights_Rest_Report_Base::check_access( $request );
	}

	/**
	 * Returns every setting.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( KDNA_EcommerceInsights_Settings::all() );
	}

	/**
	 * Checks and saves changes. If anything needs fixing, nothing is saved and
	 * each problem is returned in plain English against its field.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ) {
		$changes = (array) $request['settings'];

		// Only known tabs can be changed.
		$changes = array_intersect_key( $changes, KDNA_EcommerceInsights_Settings::defaults() );

		$errors = KDNA_EcommerceInsights_Settings::validate( $changes );

		// Only people trusted with unfiltered HTML may change the custom CSS
		// (on a multisite network that is the network administrators).
		if ( isset( $changes['branding']['custom_css'] ) && (string) $changes['branding']['custom_css'] !== (string) KDNA_EcommerceInsights_Settings::get( 'branding.custom_css', '' ) && ! current_user_can( 'unfiltered_html' ) ) {
			$errors['branding.custom_css'] = __( 'Only a site or network administrator who can add unfiltered HTML can change the custom CSS.', 'kdna-ecommerce-insights' );
		}
		if ( $errors ) {
			return new WP_Error(
				'kdna_ei_invalid_settings',
				__( 'Some settings need fixing before they can be saved.', 'kdna-ecommerce-insights' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return rest_ensure_response( KDNA_EcommerceInsights_Settings::update( $changes ) );
	}

	/**
	 * Puts one tab back to its defaults.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function reset_tab( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( KDNA_EcommerceInsights_Settings::reset( (string) $request['tab'] ) );
	}

	/**
	 * Everything the Settings screen shows alongside the settings themselves.
	 *
	 * @return WP_REST_Response
	 */
	public function get_context(): WP_REST_Response {
		global $wpdb;

		$statuses = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $key => $label ) {
				$statuses[] = array(
					'key'   => str_starts_with( $key, 'wc-' ) ? substr( $key, 3 ) : $key,
					'label' => $label,
				);
			}
		}

		$days = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$days[] = array(
				'key'   => $i,
				'label' => $GLOBALS['wp_locale']->get_weekday( $i ),
			);
		}

		// Ad spend rows per channel, so channels in use cannot be removed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$usage = $wpdb->get_results( 'SELECT channel, COUNT(*) AS rows_count FROM ' . KDNA_EcommerceInsights_Install::table( 'ad_spend' ) . ' GROUP BY channel', ARRAY_A );
		$used  = array();
		foreach ( (array) $usage as $row ) {
			$used[ $row['channel'] ] = (int) $row['rows_count'];
		}

		$connections = array();
		foreach ( KDNA_EcommerceInsights_Ad_Sync::overview() as $key => $connection ) {
			$connections[] = array(
				'key'       => $key,
				'label'     => $connection['label'],
				'state'     => $connection['state'],
				'account'   => $connection['account'],
				'message'   => $connection['message'],
				'last_sync' => $connection['last_sync'],
			);
		}

		$logo_id = (int) KDNA_EcommerceInsights_Settings::get( 'branding.logo_id', 0 );
		$costs   = (array) KDNA_EcommerceInsights_Settings::get( 'costs', array() );

		return rest_ensure_response(
			array(
				'order_statuses' => $statuses,
				'weekdays'       => $days,
				'fonts'          => KDNA_EcommerceInsights_Settings::fonts(),
				'default_colours' => KDNA_EcommerceInsights_Settings::default_colours(),
				'logo'           => array(
					'id'    => $logo_id,
					'url'   => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
					'thumb' => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
				),
				'site_name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'branding_css'   => KDNA_EcommerceInsights_Admin::branding_css(),
				'custom_css'     => KDNA_EcommerceInsights_Admin::custom_css(),
				'can_edit_css'   => current_user_can( 'unfiltered_html' ),
				'channel_usage'  => $used,
				'presets'        => KDNA_EcommerceInsights_Ad_Spend_Import::presets(),
				'connections'    => $connections,
				'costs'          => array(
					'gateway_rules'  => count( array_filter( (array) ( $costs['gateway_fees'] ?? array() ), static fn( $r ) => (float) ( $r['percent'] ?? 0 ) > 0 || (float) ( $r['fixed'] ?? 0 ) > 0 ) ),
					'shipping_rules' => count( array_filter( (array) ( $costs['shipping_rules'] ?? array() ), static fn( $r ) => 'none' !== ( $r['type'] ?? 'none' ) ) ),
					'extra_costs'    => count( (array) ( $costs['extra_costs'] ?? array() ) ),
					'meta_key'       => (string) ( $costs['shipping_cost_meta_key'] ?? '' ),
					'missing_costs'  => KDNA_EcommerceInsights_Cost_Catalogue::missing_count(),
					'overheads'      => count( KDNA_EcommerceInsights_Overheads::all() ),
				),
				'currency'       => (string) get_option( 'woocommerce_currency' ),
				'store_threshold' => (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ),
				'digest'         => KDNA_EcommerceInsights_Digest::overview(),
			)
		);
	}

	/**
	 * The sync log, newest first, a page at a time, optionally filtered by
	 * type (such as sync_meta) or result.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_log( WP_REST_Request $request ): WP_REST_Response {
		$result = KDNA_EcommerceInsights_Log::page( (int) $request['page'], (int) $request['per_page'], (string) $request['type'], (string) $request['status'] );
		return rest_ensure_response( $result );
	}

	/**
	 * Returns the payment gateways and shipping methods on this store, with
	 * the current cost rules, for the Costs screen.
	 *
	 * @return WP_REST_Response
	 */
	public function get_costs_setup(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'gateways'         => KDNA_EcommerceInsights_Fees::installed_gateways(),
				'shipping_methods' => KDNA_EcommerceInsights_Shipping::configured_methods(),
				'costs'            => KDNA_EcommerceInsights_Settings::get( 'costs' ),
				'weight_unit'      => get_option( 'woocommerce_weight_unit', 'kg' ),
			)
		);
	}
}
