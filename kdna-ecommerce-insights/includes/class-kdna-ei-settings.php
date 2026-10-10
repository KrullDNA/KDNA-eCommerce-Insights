<?php
/**
 * Plugin settings store.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saves and reads every setting from section 11 of the brief in one option,
 * kdna_ei_settings, grouped by Settings tab.
 *
 * Secrets such as ad platform access tokens are deliberately not stored
 * here. They are encrypted and kept in their own options from Stage 10, so
 * this array can be safely shared with the dashboard.
 */
class KDNA_EcommerceInsights_Settings {

	/**
	 * Name of the option holding every setting.
	 */
	const OPTION = 'kdna_ei_settings';

	/**
	 * In-memory copy of the settings for the current page load.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/*
	 * ---------------------------------------------------------------------
	 * Choice lists shared by settings, preferences and the dashboard
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Date range presets from section 8.2, with their display labels.
	 *
	 * @return array<string, string>
	 */
	public static function range_presets(): array {
		return array(
			'today'        => __( 'Today', 'kdna-ecommerce-insights' ),
			'yesterday'    => __( 'Yesterday', 'kdna-ecommerce-insights' ),
			'last_7_days'  => __( 'Last 7 days', 'kdna-ecommerce-insights' ),
			'last_30_days' => __( 'Last 30 days', 'kdna-ecommerce-insights' ),
			'this_month'   => __( 'This month', 'kdna-ecommerce-insights' ),
			'last_month'   => __( 'Last month', 'kdna-ecommerce-insights' ),
			'this_quarter' => __( 'This quarter', 'kdna-ecommerce-insights' ),
			'this_year'    => __( 'This year', 'kdna-ecommerce-insights' ),
			'last_year'    => __( 'Last year', 'kdna-ecommerce-insights' ),
			'custom'       => __( 'Custom', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Comparison options from section 8.2, with their display labels.
	 *
	 * @return array<string, string>
	 */
	public static function comparison_modes(): array {
		return array(
			'previous_period' => __( 'Previous period', 'kdna-ecommerce-insights' ),
			'previous_year'   => __( 'Same period last year', 'kdna-ecommerce-insights' ),
			'none'            => __( 'No comparison', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Fonts a client can choose in Settings > Branding (section 4.3).
	 * The value is the CSS font family name.
	 *
	 * @return array<string, string>
	 */
	public static function fonts(): array {
		return array(
			'figtree'    => 'Figtree',
			'inter'      => 'Inter',
			'montserrat' => 'Montserrat',
			'dm-sans'    => 'DM Sans',
			'manrope'    => 'Manrope',
			'inherit'    => __( 'Inherit from site', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Default brand colours for each theme, matching the design tokens in
	 * section 4.2 of the brief.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function default_colours(): array {
		return array(
			'dark'  => array(
				'accent'   => '#7188EE',
				'accent_2' => '#C9C2F8',
				'positive' => '#B6F2D0',
				'warning'  => '#F5D58A',
				'negative' => '#F2A7A7',
			),
			'light' => array(
				'accent'   => '#5A6FE0',
				'accent_2' => '#9C92E8',
				'positive' => '#16794A',
				'warning'  => '#8F5C12',
				'negative' => '#C13030',
			),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Defaults
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns every setting with its default value, grouped by Settings tab.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$base_country = function_exists( 'wc_get_base_location' ) ? wc_get_base_location()['country'] : '';
		$admin_email  = (string) get_option( 'admin_email', '' );

		return array(

			// General: which orders count and which date they belong to (section 6.2).
			'general'   => array(
				'order_statuses'    => array( 'processing', 'completed' ),
				'date_basis'        => 'paid',
				'refund_dating'     => 'refund_date',
				'restock_treatment' => 'restocked',
				'revenue_display'   => 'excl_tax',
				'week_start'        => (int) get_option( 'start_of_week', 1 ),
				'default_range'     => 'this_month',
				'comparison'        => 'previous_period',
			),

			// Costs: how payment fees, shipping and extra order costs are worked out (section 7).
			'costs'     => array(
				'cost_field_placement'   => 'after_regular_price',
				'gateway_fees'           => array(),
				'shipping_rules'         => array(),
				'shipping_cost_meta_key' => '',
				'extra_costs'            => array(),
			),

			// Marketing: ad channels, CSV presets and live connection details (section 7.4).
			'marketing' => array(
				'channels'         => array(
					array( 'key' => 'meta', 'label' => 'Meta' ),
					array( 'key' => 'google', 'label' => 'Google' ),
					array( 'key' => 'tiktok', 'label' => 'TikTok' ),
					array( 'key' => 'pinterest', 'label' => 'Pinterest' ),
					array( 'key' => 'email', 'label' => 'Email' ),
					array( 'key' => 'influencer', 'label' => 'Influencer' ),
					array( 'key' => 'other', 'label' => 'Other' ),
				),
				'csv_presets'      => array(),
				'meta'             => array(
					'app_id'        => '',
					'ad_account_id' => '',
				),
				'google'           => array(
					'client_id'         => '',
					'customer_id'       => '',
					'login_customer_id' => '',
				),
				'ad_currency_rate' => 1.0,
				'sync_frequency'   => 'daily',
			),

			// Tax: which tax system the store reports under.
			'tax'       => array(
				'system'           => 'AU' === $base_country ? 'au_gst' : 'none',
				'rate'             => 10.0,
				'reporting_period' => 'quarterly',
			),

			// Branding: per-client look of the dashboard, widgets and emails.
			'branding'  => array(
				'store_name'    => '',
				'logo_id'       => 0,
				'font'          => 'figtree',
				'colours'       => self::default_colours(),
				'default_theme' => 'dark',
				'custom_css'    => '',
			),

			// Hero card and Goals (section 8.1).
			'hero'      => array(
				'type'         => 'top_products',
				'goal_metric'  => 'revenue',
				'goal_targets' => array(
					'revenue' => 0.0,
					'profit'  => 0.0,
					'orders'  => 0,
				),
			),

			// Alerts and digests (section 8.3).
			'alerts'    => array(
				'low_stock_threshold' => 0,
				'dead_stock_days'     => 90,
				'reorder_lead_days'   => 14,
				'alert_recipients'    => $admin_email,
				'low_stock_emails'    => false,
				'sync_failure_emails' => false,
				'digest_frequency'    => 'off',
				'digest_recipients'   => $admin_email,
				'digest_sections'     => array( 'kpis', 'top_products', 'alerts' ),
			),

			// Data: what happens to plugin data on uninstall.
			'data'      => array(
				'delete_on_uninstall' => false,
			),
		);
	}

	/**
	 * Saves the default settings the first time the plugin is activated.
	 * Never overwrites settings a site already has.
	 */
	public static function install_defaults(): void {
		add_option( self::OPTION, self::defaults(), '', true );
		self::$cache = null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns every setting, with defaults filled in for anything missing
	 * (for example settings added in a newer version of the plugin).
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * Returns one setting using a dotted path, for example
	 * get( 'general.date_basis' ) or get( 'branding.colours.dark.accent' ).
	 *
	 * @param string $path     Dotted path to the setting.
	 * @param mixed  $fallback Value returned if the setting does not exist.
	 * @return mixed
	 */
	public static function get( string $path, $fallback = null ) {
		$value = self::all();

		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $fallback;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Returns the store name shown in the eyebrow, falling back to the
	 * WordPress site title when Branding has no name set.
	 *
	 * @return string
	 */
	public static function store_name(): string {
		$name = trim( (string) self::get( 'branding.store_name', '' ) );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Saving
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Saves changes to some or all settings. Anything not included keeps its
	 * current value. Every value is cleaned before it is stored.
	 *
	 * @param array $changes Settings to change, grouped by tab like defaults().
	 * @return array The full, cleaned settings now saved.
	 */
	public static function update( array $changes ): array {
		$clean = self::sanitise( self::merge( self::all(), $changes ) );

		update_option( self::OPTION, $clean, true );
		self::$cache = null;

		/**
		 * Fires after Insights settings are saved.
		 *
		 * @param array $clean The full saved settings.
		 */
		do_action( 'kdna_ei_settings_updated', $clean );

		return $clean;
	}

	/**
	 * Puts one tab, or every tab, back to its default values.
	 *
	 * @param string|null $tab Tab key such as 'branding', or null for everything.
	 * @return array The full settings now saved.
	 */
	public static function reset( ?string $tab = null ): array {
		$defaults = self::defaults();

		if ( null === $tab ) {
			$settings = $defaults;
		} else {
			$settings = self::all();
			if ( isset( $defaults[ $tab ] ) ) {
				$settings[ $tab ] = $defaults[ $tab ];
			}
		}

		update_option( self::OPTION, $settings, true );
		self::$cache = null;

		/** This action is documented in update(). */
		do_action( 'kdna_ei_settings_updated', self::all() );

		return self::all();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Cleaning
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Cleans a complete settings array so only safe, expected values are
	 * stored. Anything unrecognised falls back to its default.
	 *
	 * @param array $input Full settings array, already merged with defaults.
	 * @return array
	 */
	public static function sanitise( array $input ): array {
		$d   = self::defaults();
		$out = $d;

		// General.
		$g                     = $input['general'] ?? array();
		$statuses              = array_values( array_filter( array_map( array( __CLASS__, 'clean_status' ), (array) ( $g['order_statuses'] ?? array() ) ) ) );
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			// Keep only statuses WooCommerce (or another plugin) has actually registered.
			$known    = array_map( array( __CLASS__, 'clean_status' ), array_keys( wc_get_order_statuses() ) );
			$statuses = array_values( array_intersect( $statuses, $known ) );
		}
		$out['general']        = array(
			'order_statuses'    => $statuses ? array_values( array_unique( $statuses ) ) : $d['general']['order_statuses'],
			'date_basis'        => self::choice( $g['date_basis'] ?? '', array( 'paid', 'created', 'completed' ), $d['general']['date_basis'] ),
			'refund_dating'     => self::choice( $g['refund_dating'] ?? '', array( 'refund_date', 'order_date' ), $d['general']['refund_dating'] ),
			'restock_treatment' => self::choice( $g['restock_treatment'] ?? '', array( 'restocked', 'written_off' ), $d['general']['restock_treatment'] ),
			'revenue_display'   => self::choice( $g['revenue_display'] ?? '', array( 'excl_tax', 'incl_tax' ), $d['general']['revenue_display'] ),
			'week_start'        => min( 6, max( 0, (int) ( $g['week_start'] ?? $d['general']['week_start'] ) ) ),
			'default_range'     => self::choice( $g['default_range'] ?? '', array_keys( self::range_presets() ), $d['general']['default_range'] ),
			'comparison'        => self::choice( $g['comparison'] ?? '', array_keys( self::comparison_modes() ), $d['general']['comparison'] ),
		);

		// Costs.
		$c            = $input['costs'] ?? array();
		$gateway_fees = array();
		foreach ( (array) ( $c['gateway_fees'] ?? array() ) as $gateway_id => $rule ) {
			$key = sanitize_key( (string) $gateway_id );
			if ( '' !== $key && is_array( $rule ) ) {
				$gateway_fees[ $key ] = array(
					'percent' => self::number( $rule['percent'] ?? 0, 0, 100 ),
					'fixed'   => self::number( $rule['fixed'] ?? 0, 0 ),
				);
			}
		}

		// Shipping rules are keyed by method: "flat_rate:3" (method ID and its
		// instance in a zone) or "*" for the rule used by every other method.
		$shipping_rules = array();
		foreach ( (array) ( $c['shipping_rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$method = (string) ( $rule['method'] ?? '' );
			$method = '*' === $method ? '*' : preg_replace( '/[^a-z0-9_\-:]/', '', strtolower( $method ) );
			if ( '' === $method ) {
				continue;
			}
			$shipping_rules[ $method ] = array(
				'method' => $method,
				'type'   => self::choice( $rule['type'] ?? '', array( 'none', 'fixed', 'percent', 'per_item', 'same_as_charged' ), 'none' ),
				'amount' => self::number( $rule['amount'] ?? 0, 0 ),
				'per_kg' => self::number( $rule['per_kg'] ?? 0, 0 ),
			);
		}
		$shipping_rules = array_values( $shipping_rules );

		$extra_costs = array();
		foreach ( (array) ( $c['extra_costs'] ?? array() ) as $cost ) {
			if ( is_array( $cost ) ) {
				$extra_costs[] = array(
					'label'  => sanitize_text_field( (string) ( $cost['label'] ?? '' ) ),
					'type'   => self::choice( $cost['type'] ?? '', array( 'fixed', 'percent' ), 'fixed' ),
					'amount' => self::number( $cost['amount'] ?? 0, 0 ),
				);
			}
		}

		$out['costs'] = array(
			'cost_field_placement'   => self::choice( $c['cost_field_placement'] ?? '', array( 'after_regular_price', 'after_sale_price' ), $d['costs']['cost_field_placement'] ),
			'gateway_fees'           => $gateway_fees,
			'shipping_rules'         => $shipping_rules,
			'shipping_cost_meta_key' => sanitize_text_field( (string) ( $c['shipping_cost_meta_key'] ?? '' ) ),
			'extra_costs'            => $extra_costs,
		);

		// Marketing.
		$m        = $input['marketing'] ?? array();
		$channels = array();
		foreach ( (array) ( $m['channels'] ?? array() ) as $channel ) {
			$key = sanitize_key( (string) ( $channel['key'] ?? '' ) );
			if ( '' !== $key ) {
				$channels[ $key ] = array(
					'key'   => $key,
					'label' => sanitize_text_field( (string) ( $channel['label'] ?? $key ) ),
				);
			}
		}

		$presets = array();
		foreach ( (array) ( $m['csv_presets'] ?? array() ) as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$mapping = array();
			foreach ( (array) ( $preset['mapping'] ?? array() ) as $field => $column ) {
				$mapping[ sanitize_key( (string) $field ) ] = sanitize_text_field( (string) $column );
			}
			$name = sanitize_text_field( (string) ( $preset['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$presets[] = array(
				'name'         => mb_substr( $name, 0, 80 ),
				'channel'      => sanitize_key( (string) ( $preset['channel'] ?? $preset['platform'] ?? '' ) ),
				'mapping'      => $mapping,
				'includes_gst' => ! empty( $preset['includes_gst'] ),
			);
		}

		$out['marketing'] = array(
			'channels'         => $channels ? array_values( $channels ) : $d['marketing']['channels'],
			'csv_presets'      => $presets,
			'meta'             => array(
				'app_id'        => self::digits( $m['meta']['app_id'] ?? '' ),
				'ad_account_id' => sanitize_text_field( (string) ( $m['meta']['ad_account_id'] ?? '' ) ),
			),
			'google'           => array(
				'client_id'         => sanitize_text_field( (string) ( $m['google']['client_id'] ?? '' ) ),
				'customer_id'       => self::digits( $m['google']['customer_id'] ?? '' ),
				'login_customer_id' => self::digits( $m['google']['login_customer_id'] ?? '' ),
			),
			'ad_currency_rate' => self::number( $m['ad_currency_rate'] ?? 1, 0.000001 ),
			'sync_frequency'   => self::choice( $m['sync_frequency'] ?? '', array( 'daily', 'twice_daily', 'manual' ), $d['marketing']['sync_frequency'] ),
		);

		// Tax.
		$t          = $input['tax'] ?? array();
		$out['tax'] = array(
			'system'           => self::choice( $t['system'] ?? '', array( 'au_gst', 'vat', 'sales_tax', 'none' ), $d['tax']['system'] ),
			'rate'             => self::number( $t['rate'] ?? $d['tax']['rate'], 0, 100 ),
			'reporting_period' => self::choice( $t['reporting_period'] ?? '', array( 'monthly', 'quarterly' ), $d['tax']['reporting_period'] ),
		);

		// Branding.
		$b       = $input['branding'] ?? array();
		$colours = array();
		foreach ( self::default_colours() as $theme => $set ) {
			foreach ( $set as $key => $default ) {
				$colour                     = sanitize_hex_color( (string) ( $b['colours'][ $theme ][ $key ] ?? '' ) );
				$colours[ $theme ][ $key ] = $colour ? strtoupper( $colour ) : $default;
			}
		}

		$out['branding'] = array(
			'store_name'    => sanitize_text_field( (string) ( $b['store_name'] ?? '' ) ),
			'logo_id'       => absint( $b['logo_id'] ?? 0 ),
			'font'          => self::choice( $b['font'] ?? '', array_keys( self::fonts() ), $d['branding']['font'] ),
			'colours'       => $colours,
			'default_theme' => self::choice( $b['default_theme'] ?? '', array( 'dark', 'light' ), $d['branding']['default_theme'] ),
			'custom_css'    => self::clean_css( (string) ( $b['custom_css'] ?? '' ) ),
		);

		// Hero card and Goals.
		$h           = $input['hero'] ?? array();
		$out['hero'] = array(
			'type'         => self::choice( $h['type'] ?? '', array( 'top_products', 'profit_breakdown', 'goals' ), $d['hero']['type'] ),
			'goal_metric'  => self::choice( $h['goal_metric'] ?? '', array( 'revenue', 'profit', 'orders' ), $d['hero']['goal_metric'] ),
			'goal_targets' => array(
				'revenue' => self::number( $h['goal_targets']['revenue'] ?? 0, 0 ),
				'profit'  => self::number( $h['goal_targets']['profit'] ?? 0, 0 ),
				'orders'  => absint( $h['goal_targets']['orders'] ?? 0 ),
			),
		);

		// Alerts and digests.
		$a             = $input['alerts'] ?? array();
		$sections      = array_values( array_intersect( (array) ( $a['digest_sections'] ?? array() ), array( 'kpis', 'profit', 'top_products', 'inventory', 'marketing', 'alerts' ) ) );
		$out['alerts'] = array(
			'low_stock_threshold' => absint( $a['low_stock_threshold'] ?? 0 ),
			'dead_stock_days'     => max( 1, absint( $a['dead_stock_days'] ?? $d['alerts']['dead_stock_days'] ) ),
			'reorder_lead_days'   => min( 365, absint( $a['reorder_lead_days'] ?? $d['alerts']['reorder_lead_days'] ) ),
			'alert_recipients'    => self::emails( $a['alert_recipients'] ?? '' ),
			'low_stock_emails'    => ! empty( $a['low_stock_emails'] ),
			'sync_failure_emails' => ! empty( $a['sync_failure_emails'] ),
			'digest_frequency'    => self::choice( $a['digest_frequency'] ?? '', array( 'off', 'weekly', 'monthly' ), $d['alerts']['digest_frequency'] ),
			'digest_recipients'   => self::emails( $a['digest_recipients'] ?? '' ),
			'digest_sections'     => $sections,
		);

		// Data.
		$out['data'] = array(
			'delete_on_uninstall' => ! empty( $input['data']['delete_on_uninstall'] ),
		);

		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Validation with plain-English messages
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Checks submitted changes before they are saved and explains, in plain
	 * English, anything that needs fixing. sanitise() would quietly correct
	 * bad values; this tells the person instead, so nothing surprises them.
	 *
	 * Only the tabs included in $changes are checked, and within a tab only
	 * the fields sent, so each tab can be saved on its own.
	 *
	 * @param array $changes Settings changes, grouped by tab.
	 * @return array<string, string> Problems keyed by field path, such as
	 *                               "branding.colours.dark.accent". Empty when all is well.
	 */
	public static function validate( array $changes ): array {
		$errors = array();
		$checks = array(
			'general'   => 'validate_general',
			'costs'     => 'validate_costs',
			'marketing' => 'validate_marketing',
			'tax'       => 'validate_tax',
			'branding'  => 'validate_branding',
			'hero'      => 'validate_hero',
			'alerts'    => 'validate_alerts',
		);
		foreach ( $checks as $tab => $method ) {
			if ( isset( $changes[ $tab ] ) ) {
				$errors = array_merge( $errors, is_array( $changes[ $tab ] ) ? self::$method( $changes[ $tab ] ) : array( $tab => __( 'These settings could not be read. Please reload the page and try again.', 'kdna-ecommerce-insights' ) ) );
			}
		}
		return $errors;
	}

	/**
	 * Whether a value is a plain number (as text or a number), optionally
	 * within limits.
	 *
	 * @param mixed      $value Value.
	 * @param float      $min   Lowest allowed.
	 * @param float|null $max   Highest allowed, or null for no limit.
	 * @return bool
	 */
	private static function is_number( $value, float $min = 0, ?float $max = null ): bool {
		if ( is_bool( $value ) || ! is_numeric( $value ) ) {
			return false;
		}
		$value = (float) $value;
		return $value >= $min && ( null === $max || $value <= $max );
	}

	/**
	 * Whether a value is a whole number within limits.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Lowest allowed.
	 * @param int   $max   Highest allowed.
	 * @return bool
	 */
	private static function is_whole( $value, int $min, int $max ): bool {
		return self::is_number( $value, $min, $max ) && (float) $value === floor( (float) $value );
	}

	/**
	 * Finds the entries in a comma separated list that are not email
	 * addresses.
	 *
	 * @param mixed $value Addresses, as a list or comma separated text.
	 * @return array{0: string[], 1: string[]} Good addresses and bad ones.
	 */
	public static function split_emails( $value ): array {
		$list = is_array( $value ) ? $value : explode( ',', str_replace( array( ';', "\n" ), ',', is_scalar( $value ) ? (string) $value : '' ) );
		$list = array_values( array_filter( array_map( 'trim', array_map( 'strval', $list ) ), 'strlen' ) );
		$bad  = array_values( array_filter( $list, static fn( $email ) => ! is_email( $email ) ) );
		return array( array_values( array_diff( $list, $bad ) ), $bad );
	}

	/**
	 * Checks the General tab.
	 *
	 * @param array $g Submitted General settings.
	 * @return array<string, string>
	 */
	private static function validate_general( array $g ): array {
		$errors = array();
		$choose = __( 'Choose one of the options.', 'kdna-ecommerce-insights' );

		if ( array_key_exists( 'order_statuses', $g ) ) {
			$statuses = array_filter( array_map( array( __CLASS__, 'clean_status' ), (array) $g['order_statuses'] ) );
			$known    = function_exists( 'wc_get_order_statuses' ) ? array_map( array( __CLASS__, 'clean_status' ), array_keys( wc_get_order_statuses() ) ) : $statuses;
			if ( ! $statuses ) {
				$errors['general.order_statuses'] = __( 'Choose at least one order status that counts as a sale, usually Processing and Completed.', 'kdna-ecommerce-insights' );
			} elseif ( array_diff( $statuses, $known ) ) {
				$errors['general.order_statuses'] = __( 'One of the chosen order statuses no longer exists on this store. Please choose again.', 'kdna-ecommerce-insights' );
			}
		}

		$choices = array(
			'date_basis'        => array( 'paid', 'created', 'completed' ),
			'refund_dating'     => array( 'refund_date', 'order_date' ),
			'restock_treatment' => array( 'restocked', 'written_off' ),
			'revenue_display'   => array( 'excl_tax', 'incl_tax' ),
			'comparison'        => array_keys( self::comparison_modes() ),
		);
		foreach ( $choices as $key => $allowed ) {
			if ( array_key_exists( $key, $g ) && ! in_array( (string) $g[ $key ], $allowed, true ) ) {
				$errors[ 'general.' . $key ] = $choose;
			}
		}

		if ( array_key_exists( 'week_start', $g ) && ! self::is_whole( $g['week_start'], 0, 6 ) ) {
			$errors['general.week_start'] = __( 'Choose the day your week starts on.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'default_range', $g ) ) {
			if ( 'custom' === $g['default_range'] ) {
				$errors['general.default_range'] = __( 'Custom dates cannot be the default. Choose a preset such as This month.', 'kdna-ecommerce-insights' );
			} elseif ( ! array_key_exists( (string) $g['default_range'], self::range_presets() ) ) {
				$errors['general.default_range'] = $choose;
			}
		}

		return $errors;
	}

	/**
	 * Checks the Marketing tab: channel names, the currency rate and how
	 * often to sync. Connection details are checked by the connection routes.
	 *
	 * @param array $m Submitted Marketing settings.
	 * @return array<string, string>
	 */
	private static function validate_marketing( array $m ): array {
		$errors = array();

		if ( array_key_exists( 'channels', $m ) ) {
			$seen = array();
			foreach ( array_values( (array) $m['channels'] ) as $i => $channel ) {
				$label = trim( (string) ( $channel['label'] ?? '' ) );
				$key   = sanitize_key( (string) ( $channel['key'] ?? '' ) );
				if ( '' === $label ) {
					$errors[ "marketing.channels.$i.label" ] = __( 'Give this channel a name, for example "Snapchat".', 'kdna-ecommerce-insights' );
				} elseif ( mb_strlen( $label ) > 40 ) {
					$errors[ "marketing.channels.$i.label" ] = __( 'Keep channel names to 40 characters or fewer.', 'kdna-ecommerce-insights' );
				} elseif ( isset( $seen[ strtolower( $label ) ] ) ) {
					$errors[ "marketing.channels.$i.label" ] = __( 'Another channel already has this name.', 'kdna-ecommerce-insights' );
				}
				if ( '' === $key ) {
					$errors[ "marketing.channels.$i.label" ] = $errors[ "marketing.channels.$i.label" ] ?? __( 'This channel name needs at least one letter or number.', 'kdna-ecommerce-insights' );
				}
				$seen[ strtolower( $label ) ] = true;
			}
			if ( ! $m['channels'] ) {
				$errors['marketing.channels'] = __( 'Keep at least one channel.', 'kdna-ecommerce-insights' );
			}
		}

		if ( array_key_exists( 'ad_currency_rate', $m ) && ( ! self::is_number( $m['ad_currency_rate'], 0.000001, 100000 ) ) ) {
			$errors['marketing.ad_currency_rate'] = __( 'Enter a rate above zero, for example 1.52. Use 1 when the ad account spends in your store currency.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'sync_frequency', $m ) && ! in_array( (string) $m['sync_frequency'], array( 'daily', 'twice_daily', 'manual' ), true ) ) {
			$errors['marketing.sync_frequency'] = __( 'Choose one of the options.', 'kdna-ecommerce-insights' );
		}

		return $errors;
	}

	/**
	 * Checks the Tax tab.
	 *
	 * @param array $t Submitted Tax settings.
	 * @return array<string, string>
	 */
	private static function validate_tax( array $t ): array {
		$errors = array();
		if ( array_key_exists( 'system', $t ) && ! in_array( (string) $t['system'], array( 'au_gst', 'vat', 'sales_tax', 'none' ), true ) ) {
			$errors['tax.system'] = __( 'Choose one of the options.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'rate', $t ) && ! self::is_number( $t['rate'], 0, 100 ) ) {
			$errors['tax.rate'] = __( 'Enter a rate between 0 and 100, for example 10.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'reporting_period', $t ) && ! in_array( (string) $t['reporting_period'], array( 'monthly', 'quarterly' ), true ) ) {
			$errors['tax.reporting_period'] = __( 'Choose monthly or quarterly.', 'kdna-ecommerce-insights' );
		}
		return $errors;
	}

	/**
	 * Checks the Branding tab: name, logo, font, colours, theme and custom CSS.
	 *
	 * @param array $b Submitted Branding settings.
	 * @return array<string, string>
	 */
	private static function validate_branding( array $b ): array {
		$errors = array();

		if ( array_key_exists( 'store_name', $b ) && mb_strlen( trim( (string) $b['store_name'] ) ) > 60 ) {
			$errors['branding.store_name'] = __( 'Keep the display name to 60 characters or fewer.', 'kdna-ecommerce-insights' );
		}
		if ( ! empty( $b['logo_id'] ) && ( ! self::is_whole( $b['logo_id'], 1, PHP_INT_MAX ) || ! wp_attachment_is_image( (int) $b['logo_id'] ) ) ) {
			$errors['branding.logo_id'] = __( 'Choose an image from the Media Library for the logo.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'font', $b ) && ! array_key_exists( (string) $b['font'], self::fonts() ) ) {
			$errors['branding.font'] = __( 'Choose one of the fonts in the list.', 'kdna-ecommerce-insights' );
		}
		foreach ( self::default_colours() as $theme => $set ) {
			foreach ( array_keys( $set ) as $key ) {
				if ( isset( $b['colours'][ $theme ][ $key ] ) && ! sanitize_hex_color( (string) $b['colours'][ $theme ][ $key ] ) ) {
					$errors[ "branding.colours.$theme.$key" ] = __( 'Enter a colour code like #5A6FE0.', 'kdna-ecommerce-insights' );
				}
			}
		}
		if ( array_key_exists( 'default_theme', $b ) && ! in_array( (string) $b['default_theme'], array( 'dark', 'light' ), true ) ) {
			$errors['branding.default_theme'] = __( 'Choose dark or light.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'custom_css', $b ) ) {
			$css = (string) $b['custom_css'];
			if ( strlen( $css ) > 20000 ) {
				$errors['branding.custom_css'] = __( 'Custom CSS is limited to 20,000 characters.', 'kdna-ecommerce-insights' );
			} elseif ( false !== strpos( $css, '<' ) ) {
				$errors['branding.custom_css'] = __( 'Custom CSS cannot contain HTML tags such as <style>. Paste only the CSS rules.', 'kdna-ecommerce-insights' );
			}
		}

		return $errors;
	}

	/**
	 * Checks the Hero card and Goals tab.
	 *
	 * @param array $h Submitted hero settings.
	 * @return array<string, string>
	 */
	private static function validate_hero( array $h ): array {
		$errors = array();
		if ( array_key_exists( 'type', $h ) && ! in_array( (string) $h['type'], array( 'top_products', 'profit_breakdown', 'goals' ), true ) ) {
			$errors['hero.type'] = __( 'Choose one of the options.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'goal_metric', $h ) && ! in_array( (string) $h['goal_metric'], array( 'revenue', 'profit', 'orders' ), true ) ) {
			$errors['hero.goal_metric'] = __( 'Choose one of the options.', 'kdna-ecommerce-insights' );
		}
		foreach ( array( 'revenue', 'profit' ) as $key ) {
			if ( isset( $h['goal_targets'][ $key ] ) && '' !== $h['goal_targets'][ $key ] && ! self::is_number( $h['goal_targets'][ $key ], 0 ) ) {
				$errors[ "hero.goal_targets.$key" ] = __( 'Enter an amount of zero or more, for example 25000.', 'kdna-ecommerce-insights' );
			}
		}
		if ( isset( $h['goal_targets']['orders'] ) && '' !== $h['goal_targets']['orders'] && ! self::is_whole( $h['goal_targets']['orders'], 0, PHP_INT_MAX ) ) {
			$errors['hero.goal_targets.orders'] = __( 'Enter a whole number of orders, for example 300.', 'kdna-ecommerce-insights' );
		}
		return $errors;
	}

	/**
	 * Checks the Alerts and digests tab.
	 *
	 * @param array $a Submitted alert settings.
	 * @return array<string, string>
	 */
	private static function validate_alerts( array $a ): array {
		$errors = array();

		if ( array_key_exists( 'low_stock_threshold', $a ) && '' !== $a['low_stock_threshold'] && ! self::is_whole( $a['low_stock_threshold'], 0, 100000 ) ) {
			$errors['alerts.low_stock_threshold'] = __( 'Enter a whole number of units, or 0 to use each product\'s own low stock amount.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'dead_stock_days', $a ) && ! self::is_whole( $a['dead_stock_days'], 1, 3650 ) ) {
			$errors['alerts.dead_stock_days'] = __( 'Enter a number of days between 1 and 3650, for example 90.', 'kdna-ecommerce-insights' );
		}
		if ( array_key_exists( 'reorder_lead_days', $a ) && ! self::is_whole( $a['reorder_lead_days'], 0, 365 ) ) {
			$errors['alerts.reorder_lead_days'] = __( 'Enter a number of days between 0 and 365, for example 14.', 'kdna-ecommerce-insights' );
		}

		foreach ( array( 'alert_recipients', 'digest_recipients' ) as $key ) {
			if ( array_key_exists( $key, $a ) ) {
				list( , $bad ) = self::split_emails( $a[ $key ] );
				if ( $bad ) {
					/* translators: %s: the entries that are not email addresses. */
					$errors[ 'alerts.' . $key ] = sprintf( __( 'These are not email addresses: %s. Separate addresses with commas.', 'kdna-ecommerce-insights' ), implode( ', ', $bad ) );
				}
			}
		}

		$alerts_on = ! empty( $a['low_stock_emails'] ) || ! empty( $a['sync_failure_emails'] );
		if ( $alerts_on && array_key_exists( 'alert_recipients', $a ) && ! isset( $errors['alerts.alert_recipients'] ) && ! self::split_emails( $a['alert_recipients'] )[0] ) {
			$errors['alerts.alert_recipients'] = __( 'Add at least one email address for the alerts to go to.', 'kdna-ecommerce-insights' );
		}

		if ( array_key_exists( 'digest_frequency', $a ) ) {
			if ( ! in_array( (string) $a['digest_frequency'], array( 'off', 'weekly', 'monthly' ), true ) ) {
				$errors['alerts.digest_frequency'] = __( 'Choose one of the options.', 'kdna-ecommerce-insights' );
			} elseif ( 'off' !== $a['digest_frequency'] ) {
				if ( array_key_exists( 'digest_recipients', $a ) && ! isset( $errors['alerts.digest_recipients'] ) && ! self::split_emails( $a['digest_recipients'] )[0] ) {
					$errors['alerts.digest_recipients'] = __( 'Add at least one email address for the digest to go to.', 'kdna-ecommerce-insights' );
				}
				if ( array_key_exists( 'digest_sections', $a ) && ! array_intersect( (array) $a['digest_sections'], array( 'kpis', 'profit', 'top_products', 'inventory', 'marketing', 'alerts' ) ) ) {
					$errors['alerts.digest_sections'] = __( 'Choose at least one thing to include.', 'kdna-ecommerce-insights' );
				}
			}
		}

		return $errors;
	}

	/**
	 * Checks the Costs tab.
	 *
	 * @param array $costs Submitted Costs settings.
	 * @return array<string, string>
	 */
	private static function validate_costs( array $costs ): array {
		$errors = array();

		if ( array_key_exists( 'cost_field_placement', $costs ) && ! in_array( (string) $costs['cost_field_placement'], array( 'after_regular_price', 'after_sale_price' ), true ) ) {
			$errors['costs.cost_field_placement'] = __( 'Choose one of the options.', 'kdna-ecommerce-insights' );
		}

		foreach ( (array) ( $costs['gateway_fees'] ?? array() ) as $gateway => $rule ) {
			$percent = $rule['percent'] ?? 0;
			$fixed   = $rule['fixed'] ?? 0;
			if ( '' !== $percent && ( ! is_numeric( $percent ) || $percent < 0 || $percent > 100 ) ) {
				$errors[ "costs.gateway_fees.$gateway.percent" ] = __( 'The percentage must be a number between 0 and 100.', 'kdna-ecommerce-insights' );
			}
			if ( '' !== $fixed && ( ! is_numeric( $fixed ) || $fixed < 0 ) ) {
				$errors[ "costs.gateway_fees.$gateway.fixed" ] = __( 'The fixed fee must be a number of zero or more.', 'kdna-ecommerce-insights' );
			}
		}

		foreach ( (array) ( $costs['shipping_rules'] ?? array() ) as $i => $rule ) {
			$method = (string) ( $rule['method'] ?? $i );
			foreach ( array( 'amount', 'per_kg' ) as $field ) {
				$value = $rule[ $field ] ?? 0;
				if ( '' !== $value && ( ! is_numeric( $value ) || $value < 0 ) ) {
					$errors[ "costs.shipping_rules.$method.$field" ] = __( 'Enter a number of zero or more.', 'kdna-ecommerce-insights' );
				}
			}
			if ( 'percent' === ( $rule['type'] ?? '' ) && is_numeric( $rule['amount'] ?? 0 ) && $rule['amount'] > 100 ) {
				$errors[ "costs.shipping_rules.$method.amount" ] = __( 'A percentage cannot be more than 100.', 'kdna-ecommerce-insights' );
			}
		}

		if ( isset( $costs['shipping_cost_meta_key'] ) && '' !== $costs['shipping_cost_meta_key'] && ! preg_match( '/^[A-Za-z0-9_\-]{1,191}$/', (string) $costs['shipping_cost_meta_key'] ) ) {
			$errors['costs.shipping_cost_meta_key'] = __( 'A meta key can only contain letters, numbers, underscores and hyphens, with no spaces.', 'kdna-ecommerce-insights' );
		}

		foreach ( (array) ( $costs['extra_costs'] ?? array() ) as $i => $cost ) {
			if ( '' === trim( (string) ( $cost['label'] ?? '' ) ) ) {
				$errors[ "costs.extra_costs.$i.label" ] = __( 'Give this cost a name, for example "Packaging".', 'kdna-ecommerce-insights' );
			}
			$amount = $cost['amount'] ?? '';
			if ( ! is_numeric( $amount ) || $amount < 0 ) {
				$errors[ "costs.extra_costs.$i.amount" ] = __( 'Enter an amount of zero or more.', 'kdna-ecommerce-insights' );
			} elseif ( 'percent' === ( $cost['type'] ?? '' ) && $amount > 100 ) {
				$errors[ "costs.extra_costs.$i.amount" ] = __( 'A percentage cannot be more than 100.', 'kdna-ecommerce-insights' );
			}
		}

		return $errors;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Lays saved settings over the defaults, tab by tab, so new settings get
	 * their default while existing ones keep their saved value. Lists (such
	 * as order statuses) are replaced as a whole rather than merged.
	 *
	 * @param array $base    Defaults or current settings.
	 * @param array $changes Values to lay on top.
	 * @return array
	 */
	private static function merge( array $base, array $changes ): array {
		foreach ( $changes as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $base[ $key ] ) && ! array_is_list( $value ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Returns the value if it is one of the allowed choices, otherwise the default.
	 *
	 * @param mixed  $value   Submitted value.
	 * @param array  $allowed Allowed values.
	 * @param string $default Value to use when the submitted one is not allowed.
	 * @return string
	 */
	private static function choice( $value, array $allowed, string $default ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Turns a value into a number and keeps it within a minimum and optional maximum.
	 *
	 * @param mixed      $value Submitted value.
	 * @param float      $min   Lowest allowed value.
	 * @param float|null $max   Highest allowed value, or null for no limit.
	 * @return float
	 */
	private static function number( $value, float $min, ?float $max = null ): float {
		$number = is_numeric( $value ) ? (float) $value : $min;
		$number = max( $min, $number );
		return null === $max ? $number : min( $max, $number );
	}

	/**
	 * Keeps only the digits of an ID such as a Google Ads customer ID
	 * (people often paste them with dashes, like 123-456-7890).
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private static function digits( $value ): string {
		return preg_replace( '/\D+/', '', is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Cleans a comma separated list of email addresses, dropping any that
	 * are not valid.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private static function emails( $value ): string {
		$list = array_filter( array_map( 'sanitize_email', self::split_emails( $value )[0] ), 'is_email' );
		return implode( ', ', array_unique( $list ) );
	}

	/**
	 * Cleans custom CSS: no HTML tags, nothing that could close the style
	 * block, no old Internet Explorer expressions or script addresses, and at
	 * most 20,000 characters.
	 *
	 * @param string $css Submitted CSS.
	 * @return string
	 */
	public static function clean_css( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = str_replace( '<', '', $css );
		$css = preg_replace( '/expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding/i', '', $css );
		return trim( mb_substr( (string) $css, 0, 20000 ) );
	}

	/**
	 * Cleans an order status key, removing the "wc-" prefix WooCommerce
	 * sometimes adds, so statuses are always stored the same way.
	 *
	 * @param mixed $status Submitted status.
	 * @return string
	 */
	private static function clean_status( $status ): string {
		$status = sanitize_key( is_scalar( $status ) ? (string) $status : '' );
		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}
}
