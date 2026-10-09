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
				'positive' => '#1F9D62',
				'warning'  => '#B7791F',
				'negative' => '#D64545',
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
			'custom_css'    => wp_strip_all_tags( (string) ( $b['custom_css'] ?? '' ) ),
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
	 * Only the Costs tab is checked in detail so far. Stage 12 adds the rest.
	 *
	 * @param array $changes Settings changes, grouped by tab.
	 * @return array<string, string> Problems keyed by field path. Empty when all is well.
	 */
	public static function validate( array $changes ): array {
		$errors = array();
		$costs  = $changes['costs'] ?? null;

		if ( ! is_array( $costs ) ) {
			return $errors;
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
		$list = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
		$list = array_filter( array_map( 'sanitize_email', array_map( 'trim', $list ) ), 'is_email' );
		return implode( ', ', array_unique( $list ) );
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
