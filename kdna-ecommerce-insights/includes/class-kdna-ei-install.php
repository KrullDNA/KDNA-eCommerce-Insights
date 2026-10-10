<?php
/**
 * Database tables, activation and versioned migrations.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's own database tables.
 *
 * The plugin keeps small summary tables of its own (section 10.2 of the
 * brief) so the dashboard never has to read every order from scratch.
 * Each time the database layout changes, KDNA_EI_DB_VERSION goes up and any
 * one-off migration steps are added to migrations().
 */
class KDNA_EcommerceInsights_Install {

	/**
	 * Option that records which database version this site is on.
	 */
	const DB_VERSION_OPTION = 'kdna_ei_db_version';

	/**
	 * Option that records when the plugin was first activated.
	 */
	const INSTALLED_AT_OPTION = 'kdna_ei_installed_at';

	/**
	 * Short names of every plugin table, without the database prefix.
	 */
	const TABLES = array(
		'order_facts',
		'order_item_facts',
		'daily_summary',
		'ad_spend',
		'overheads',
		'cost_history',
		'stock_snapshots',
		'sync_log',
		'refund_facts',
	);

	/*
	 * ---------------------------------------------------------------------
	 * Activation and deactivation
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Runs when the plugin is activated: builds the tables, saves default
	 * settings and notes the install date. Safe to run more than once.
	 */
	public static function activate(): void {
		self::create_tables();
		KDNA_EcommerceInsights_Settings::install_defaults();

		add_option( self::INSTALLED_AT_OPTION, current_time( 'mysql', true ), '', false );
		update_option( self::DB_VERSION_OPTION, KDNA_EI_DB_VERSION, false );

		// Ask for the order history to be processed. It starts on the next page
		// load, once WooCommerce's background job queue is ready.
		update_option( KDNA_EcommerceInsights_Backfill::PENDING_OPTION, 1, false );
	}

	/**
	 * Runs when the plugin is deactivated. Data and settings are always kept;
	 * later stages use this to stop background jobs.
	 */
	public static function deactivate(): void {
		// Stop every Insights background job. Data and settings are kept, and
		// any unfinished history processing picks up again on reactivation.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
		}
	}

	/**
	 * Checks whether the database is behind the plugin version and, if so,
	 * upgrades the tables and runs any one-off migration steps.
	 *
	 * This runs on every page load but costs a single cached option read
	 * when nothing needs doing.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::DB_VERSION_OPTION, '' );

		if ( KDNA_EI_DB_VERSION === $installed ) {
			return;
		}

		self::create_tables();
		KDNA_EcommerceInsights_Settings::install_defaults();
		self::run_migrations( (string) $installed );

		update_option( self::DB_VERSION_OPTION, KDNA_EI_DB_VERSION, false );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Migrations
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Lists one-off upgrade steps, keyed by the database version that
	 * introduced them. Table structure changes do not need a step here,
	 * because create_tables() adds new columns automatically. Steps are for
	 * moving or reshaping existing data.
	 *
	 * Example for a future release:
	 * '1.1.0' => array( __CLASS__, 'migrate_1_1_0' ),
	 *
	 * @return array<string, callable>
	 */
	private static function migrations(): array {
		return array(
			'1.0.2' => array( __CLASS__, 'migrate_1_0_2' ),
		);
	}

	/**
	 * 1.0.2: the light theme's green, amber and red were deepened so text
	 * in them passes the WCAG AA contrast check. Sites that saved Branding
	 * with the old default colours move to the new ones; any colour the
	 * site chose itself is left alone.
	 */
	public static function migrate_1_0_2(): void {
		$settings = get_option( 'kdna_ei_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['branding']['colours']['light'] ) ) {
			return;
		}
		$retired = array(
			'positive' => array( '#1F9D62', '#16794A' ),
			'warning'  => array( '#B7791F', '#8F5C12' ),
			'negative' => array( '#D64545', '#C13030' ),
		);
		foreach ( $retired as $key => $swap ) {
			if ( strtoupper( (string) ( $settings['branding']['colours']['light'][ $key ] ?? '' ) ) === $swap[0] ) {
				$settings['branding']['colours']['light'][ $key ] = $swap[1];
			}
		}
		update_option( 'kdna_ei_settings', $settings );
	}

	/**
	 * Runs, in order, every migration step newer than the version the site
	 * was on. A brand new install has nothing to migrate.
	 *
	 * @param string $from_version Database version before this upgrade.
	 */
	private static function run_migrations( string $from_version ): void {
		if ( '' === $from_version ) {
			return;
		}

		$steps = self::migrations();
		uksort( $steps, 'version_compare' );

		foreach ( $steps as $version => $callback ) {
			if ( version_compare( $from_version, $version, '<' ) && version_compare( $version, KDNA_EI_DB_VERSION, '<=' ) && is_callable( $callback ) ) {
				call_user_func( $callback );
			}
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Tables
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns the full database name of a plugin table, for example
	 * wp_kdna_ei_order_facts.
	 *
	 * @param string $name Short table name from TABLES.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'kdna_ei_' . $name;
	}

	/**
	 * Creates every table, or updates existing tables to match the layout
	 * below. Uses WordPress's dbDelta, which only adds what is missing and
	 * never deletes data.
	 */
	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );
	}

	/**
	 * Returns the CREATE TABLE statements for every plugin table.
	 *
	 * Money columns use DECIMAL(19,4) so amounts are exact, never rounded the
	 * way floating point numbers are. dbDelta is strict about formatting, so
	 * keep one column per line and two spaces after PRIMARY KEY.
	 *
	 * @return string[]
	 */
	private static function schema(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$money   = 'DECIMAL(19,4) NOT NULL DEFAULT 0';

		$t = array();
		foreach ( self::TABLES as $name ) {
			$t[ $name ] = self::table( $name );
		}

		return array(

			// One row per order: every profit line from section 6 of the brief.
			"CREATE TABLE {$t['order_facts']} (
				order_id BIGINT UNSIGNED NOT NULL,
				report_date DATE NULL,
				date_paid DATETIME NULL,
				date_paid_gmt DATETIME NULL,
				date_created DATETIME NULL,
				date_created_gmt DATETIME NULL,
				date_completed DATETIME NULL,
				date_completed_gmt DATETIME NULL,
				status VARCHAR(40) NOT NULL DEFAULT '',
				currency CHAR(3) NOT NULL DEFAULT '',
				exchange_rate DECIMAL(19,8) NOT NULL DEFAULT 1,
				currency_flag TINYINT(1) NOT NULL DEFAULT 0,
				gross_sales $money,
				discounts $money,
				refunds $money,
				shipping_charged $money,
				tax $money,
				net_revenue $money,
				cogs $money,
				payment_fee $money,
				fee_source VARCHAR(20) NOT NULL DEFAULT '',
				shipping_cost $money,
				shipping_source VARCHAR(20) NOT NULL DEFAULT '',
				extra_costs $money,
				gross_profit $money,
				contribution_profit $money,
				customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				customer_key VARCHAR(64) NOT NULL DEFAULT '',
				is_first_order TINYINT(1) NOT NULL DEFAULT 0,
				payment_method VARCHAR(100) NOT NULL DEFAULT '',
				country CHAR(2) NOT NULL DEFAULT '',
				state VARCHAR(100) NOT NULL DEFAULT '',
				items_count INT UNSIGNED NOT NULL DEFAULT 0,
				missing_cost_flag TINYINT(1) NOT NULL DEFAULT 0,
				cogs_returned $money,
				tax_refunded $money,
				calculated_at DATETIME NULL,
				PRIMARY KEY  (order_id),
				KEY report_date (report_date),
				KEY date_paid (date_paid),
				KEY customer_key (customer_key),
				KEY status (status),
				KEY missing_cost_flag (missing_cost_flag)
			) $collate;",

			// One row per order line, with the cost locked in on the order date.
			"CREATE TABLE {$t['order_item_facts']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL,
				order_item_id BIGINT UNSIGNED NOT NULL,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				qty DECIMAL(19,4) NOT NULL DEFAULT 0,
				line_net $money,
				unit_cost DECIMAL(19,4) NULL,
				line_cost $money,
				refunded_qty DECIMAL(19,4) NOT NULL DEFAULT 0,
				refunded_amount $money,
				missing_cost TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY order_item_id (order_item_id),
				KEY order_id (order_id),
				KEY product_id (product_id),
				KEY variation_id (variation_id)
			) $collate;",

			// One row per day: pre-added totals so charts load instantly.
			"CREATE TABLE {$t['daily_summary']} (
				summary_date DATE NOT NULL,
				orders INT UNSIGNED NOT NULL DEFAULT 0,
				items_sold DECIMAL(19,4) NOT NULL DEFAULT 0,
				gross_sales $money,
				discounts $money,
				refunds $money,
				shipping_charged $money,
				tax $money,
				net_revenue $money,
				cogs $money,
				gross_profit $money,
				payment_fees $money,
				shipping_costs $money,
				extra_costs $money,
				contribution_profit $money,
				new_customers INT UNSIGNED NOT NULL DEFAULT 0,
				returning_customers INT UNSIGNED NOT NULL DEFAULT 0,
				new_customer_revenue $money,
				estimated_fee_orders INT UNSIGNED NOT NULL DEFAULT 0,
				estimated_shipping_orders INT UNSIGNED NOT NULL DEFAULT 0,
				missing_cost_orders INT UNSIGNED NOT NULL DEFAULT 0,
				updated_at DATETIME NULL,
				PRIMARY KEY  (summary_date)
			) $collate;",

			// One row per day, channel and campaign of advertising spend.
			"CREATE TABLE {$t['ad_spend']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				spend_date DATE NOT NULL,
				channel VARCHAR(50) NOT NULL DEFAULT '',
				campaign_id VARCHAR(100) NOT NULL DEFAULT '',
				campaign_name VARCHAR(255) NOT NULL DEFAULT '',
				spend $money,
				impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
				clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
				conversions DECIMAL(19,4) NOT NULL DEFAULT 0,
				conversion_value $money,
				includes_gst TINYINT(1) NOT NULL DEFAULT 0,
				source VARCHAR(10) NOT NULL DEFAULT 'manual',
				currency CHAR(3) NOT NULL DEFAULT '',
				entry_group VARCHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY spend_date (spend_date),
				KEY channel_date (channel,spend_date),
				KEY entry_group (entry_group)
			) $collate;",

			// One row per recurring or one-off overhead cost.
			"CREATE TABLE {$t['overheads']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(255) NOT NULL DEFAULT '',
				category VARCHAR(50) NOT NULL DEFAULT '',
				amount $money,
				frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
				start_date DATE NOT NULL,
				end_date DATE NULL,
				includes_gst TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY start_date (start_date)
			) $collate;",

			// One row per product cost change, so old orders keep their old cost.
			"CREATE TABLE {$t['cost_history']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				old_cost DECIMAL(19,4) NULL,
				new_cost DECIMAL(19,4) NULL,
				changed_at DATETIME NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY product_lookup (product_id,variation_id,changed_at)
			) $collate;",

			// One row per day and product: nightly stock levels and values.
			"CREATE TABLE {$t['stock_snapshots']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				snapshot_date DATE NOT NULL,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				stock_qty DECIMAL(19,4) NOT NULL DEFAULT 0,
				value_at_cost $money,
				value_at_retail $money,
				PRIMARY KEY  (id),
				UNIQUE KEY snapshot_product (snapshot_date,product_id,variation_id),
				KEY product_id (product_id)
			) $collate;",

			// One row per refund, so refunds can be counted on the day they happened.
			"CREATE TABLE {$t['refund_facts']} (
				refund_id BIGINT UNSIGNED NOT NULL,
				order_id BIGINT UNSIGNED NOT NULL,
				refund_date DATE NULL,
				refund_date_gmt DATETIME NULL,
				amount $money,
				tax $money,
				shipping $money,
				cogs_returned $money,
				items_qty DECIMAL(19,4) NOT NULL DEFAULT 0,
				PRIMARY KEY  (refund_id),
				KEY order_id (order_id),
				KEY refund_date (refund_date)
			) $collate;",

			// One row per background job or ad platform sync.
			"CREATE TABLE {$t['sync_log']} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(50) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT '',
				message TEXT NULL,
				started_at DATETIME NULL,
				finished_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY type_started (type,started_at)
			) $collate;",
		);
	}
}
