<?php
/**
 * Daily sync of ad spend from the live Meta and Google Ads connections.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brings live ad spend in, without ever counting anything twice.
 *
 * - A background job (Action Scheduler) runs for each connected platform
 *   once or twice a day, as set in Settings > Marketing, at about 5am site
 *   time, after the nightly order check.
 * - Each sync re-reads the last 7 days (platforms adjust recent figures)
 *   and replaces those days' synced rows. "Sync now" can also reach further
 *   back, for example to bring in the last 90 days when first connecting.
 * - Once a channel is connected, its spend from the first synced day on
 *   comes only from the platform: manual entries and CSV imports for those
 *   dates are refused with an explanation, and any added earlier are
 *   replaced by the live figures (the sync says how much it replaced).
 * - Every sync, good or bad, is written to sync_log, and the result shows
 *   on the Marketing screen's connection cards in plain English. A failed
 *   sync never touches the figures already saved.
 */
class KDNA_EcommerceInsights_Ad_Sync {

	/**
	 * Action Scheduler hook for a platform's sync.
	 */
	const HOOK = 'kdna_ei_ad_sync';

	/**
	 * Option holding each connection's status.
	 */
	const STATUS_OPTION = 'kdna_ei_ad_connections';

	/**
	 * Days each regular sync re-reads.
	 */
	const WINDOW_DAYS = 7;

	/**
	 * The platform objects, created once.
	 *
	 * @var KDNA_EcommerceInsights_Ad_Platform[]|null
	 */
	private static $platforms = null;

	/**
	 * Connects the sync job and its schedule to WordPress.
	 */
	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 25 );
		add_action( 'kdna_ei_settings_updated', array( __CLASS__, 'reschedule' ) );
		self::platforms();
	}

	/**
	 * The live connections, keyed by channel.
	 *
	 * @return KDNA_EcommerceInsights_Ad_Platform[]
	 */
	public static function platforms(): array {
		if ( null === self::$platforms ) {
			self::$platforms = array(
				'meta'   => new KDNA_EcommerceInsights_Meta_Ads(),
				'google' => new KDNA_EcommerceInsights_Google_Ads(),
			);
		}
		return self::$platforms;
	}

	/**
	 * One platform by key, or null.
	 *
	 * @param string $key meta or google.
	 * @return KDNA_EcommerceInsights_Ad_Platform|null
	 */
	public static function platform( string $key ): ?KDNA_EcommerceInsights_Ad_Platform {
		return self::platforms()[ $key ] ?? null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Status
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A connection's saved status.
	 *
	 * @param string $key Platform key.
	 * @return array state (not_set_up, ready, connected, error), message,
	 *               account, currency, last_sync, last_success, rows, spend,
	 *               replaced, synced_from, synced_to.
	 */
	public static function status( string $key ): array {
		$all = (array) get_option( self::STATUS_OPTION, array() );
		return array_merge(
			array(
				'state'        => 'not_set_up',
				'message'      => '',
				'account'      => '',
				'currency'     => '',
				'warning'      => '',
				'last_sync'    => '',
				'last_success' => '',
				'rows'         => 0,
				'spend'        => 0.0,
				'replaced'     => 0.0,
				'synced_from'  => '',
				'synced_to'    => '',
			),
			(array) ( $all[ $key ] ?? array() )
		);
	}

	/**
	 * Saves changes to a connection's status.
	 *
	 * @param string $key     Platform key.
	 * @param array  $changes Fields to change.
	 */
	private static function update_status( string $key, array $changes ): void {
		$all         = (array) get_option( self::STATUS_OPTION, array() );
		$all[ $key ] = array_merge( self::status( $key ), $changes );
		update_option( self::STATUS_OPTION, $all, false );
	}

	/**
	 * Everything the Marketing screen shows for each connection. Never
	 * includes a secret.
	 *
	 * @return array[]
	 */
	public static function overview(): array {
		$list = array();
		foreach ( self::platforms() as $key => $platform ) {
			$status = self::status( $key );
			if ( ! $platform->configured() ) {
				$status['state'] = 'not_set_up';
			} elseif ( 'not_set_up' === $status['state'] ) {
				$status['state'] = 'ready';
			}
			$list[ $key ] = array_merge(
				$status,
				array(
					'key'       => $key,
					'label'     => $platform->label(),
					'settings'  => $platform->settings(),
					'missing'   => $platform->missing(),
					'next_sync' => self::next_sync( $key ),
				)
			);
		}
		return $list;
	}

	/**
	 * When the next scheduled sync runs, as a GMT date and time, or empty.
	 *
	 * @param string $key Platform key.
	 * @return string
	 */
	private static function next_sync( string $key ): string {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return '';
		}
		$next = as_next_scheduled_action( self::HOOK, array( $key ), KDNA_EcommerceInsights_Order_Processor::GROUP );
		return is_int( $next ) ? gmdate( 'Y-m-d H:i:s', $next ) : '';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scheduling
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Makes sure each set-up platform has its regular sync scheduled (and
	 * nothing is scheduled for platforms that are not set up, or when sync
	 * is set to manual only).
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$frequency = (string) KDNA_EcommerceInsights_Settings::get( 'marketing.sync_frequency', 'daily' );
		$interval  = 'twice_daily' === $frequency ? 12 * HOUR_IN_SECONDS : DAY_IN_SECONDS;

		foreach ( self::platforms() as $key => $platform ) {
			$args      = array( $key );
			$scheduled = as_has_scheduled_action( self::HOOK, $args, KDNA_EcommerceInsights_Order_Processor::GROUP );
			$wanted    = 'manual' !== $frequency && $platform->configured();

			if ( $wanted && ! $scheduled ) {
				// First run at about 5am site time, after the nightly order check.
				$first = new DateTimeImmutable( 'tomorrow 05:00', wp_timezone() );
				as_schedule_recurring_action( $first->getTimestamp(), $interval, self::HOOK, $args, KDNA_EcommerceInsights_Order_Processor::GROUP );
			} elseif ( ! $wanted && $scheduled ) {
				as_unschedule_all_actions( self::HOOK, $args, KDNA_EcommerceInsights_Order_Processor::GROUP );
			}
		}
	}

	/**
	 * Re-creates the schedules after settings change (for example the sync
	 * frequency), so the new timing applies straight away.
	 */
	public static function reschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array_keys( self::platforms() ) as $key ) {
				as_unschedule_all_actions( self::HOOK, array( $key ), KDNA_EcommerceInsights_Order_Processor::GROUP );
			}
		}
		self::schedule();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Testing and syncing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tests a connection and saves the account's name and currency.
	 *
	 * @param string $key Platform key.
	 * @return array|WP_Error Account details.
	 */
	public static function test( string $key ) {
		$platform = self::platform( $key );
		if ( ! $platform ) {
			return new WP_Error( 'kdna_ei_unknown_platform', __( 'Unknown ad platform.', 'kdna-ecommerce-insights' ), array( 'status' => 404 ) );
		}

		$result = $platform->test();
		if ( is_wp_error( $result ) ) {
			self::update_status( $key, array( 'state' => 'error', 'message' => $result->get_error_message() ) );
			return $result;
		}

		self::update_status(
			$key,
			array(
				'state'    => 'connected',
				'message'  => '',
				'account'  => $result['name'],
				'currency' => $result['currency'],
				'warning'  => self::currency_warning( $result['currency'] ) . ( '' !== $result['warning'] ? ' ' . $result['warning'] : '' ),
			)
		);
		return $result;
	}

	/**
	 * A plain-English warning when the ad account's currency differs from
	 * the store's and no conversion rate is set.
	 *
	 * @param string $currency Ad account currency.
	 * @return string
	 */
	private static function currency_warning( string $currency ): string {
		$store = (string) get_option( 'woocommerce_currency' );
		$rate  = (float) KDNA_EcommerceInsights_Settings::get( 'marketing.ad_currency_rate', 1 );
		if ( '' === $currency || $currency === $store || 1.0 !== $rate ) {
			return '';
		}
		/* translators: 1: ad account currency, 2: store currency. */
		return sprintf( __( 'This ad account spends in %1$s but your store reports in %2$s. Set the conversion rate so spend is counted correctly.', 'kdna-ecommerce-insights' ), $currency, $store );
	}

	/**
	 * Runs a scheduled sync (Action Scheduler calls this).
	 *
	 * @param string $key Platform key.
	 */
	public static function run( string $key ): void {
		$before = self::status( $key )['state'];
		$result = self::sync( $key );

		// Email once when a working connection starts failing, not on every retry.
		if ( is_wp_error( $result ) && 'error' !== $before ) {
			self::email_failure( $key, $result );
		}
	}

	/**
	 * Emails the alert recipients that a scheduled sync failed, when
	 * Settings > Alerts and digests asks for it.
	 *
	 * @param string   $key   Platform key.
	 * @param WP_Error $error What went wrong.
	 * @return bool Whether an email was sent.
	 */
	public static function email_failure( string $key, WP_Error $error ): bool {
		if ( ! KDNA_EcommerceInsights_Settings::get( 'alerts.sync_failure_emails', false ) ) {
			return false;
		}
		$platform = self::platform( $key );
		$label    = $platform ? $platform->label() : $key;
		$store    = KDNA_EcommerceInsights_Settings::store_name();

		$sent = KDNA_EcommerceInsights_Mailer::send(
			KDNA_EcommerceInsights_Settings::get( 'alerts.alert_recipients', '' ),
			/* translators: 1: store name, 2: platform name. */
			sprintf( __( '[%1$s] %2$s ad spend could not sync', 'kdna-ecommerce-insights' ), $store, $label ),
			/* translators: %s: platform name. */
			sprintf( __( '%s needs attention', 'kdna-ecommerce-insights' ), $label ),
			__( 'The daily ad spend sync did not work, so the latest spend is missing from your profit figures. Nothing already saved has changed, and Insights will try again at the next sync.', 'kdna-ecommerce-insights' ),
			'<p style="margin:0;padding:14px 16px;background:#F7F8FA;border-left:3px solid #C13030;font-size:15px;line-height:1.5;">' . esc_html( $error->get_error_message() ) . '</p>',
			admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/marketing' ),
			__( 'Open Marketing', 'kdna-ecommerce-insights' )
		);

		KDNA_EcommerceInsights_Log::add(
			'sync_alert',
			$sent ? 'success' : 'error',
			$sent
				/* translators: %s: platform name. */
				? sprintf( __( 'Sync failure email sent for %s.', 'kdna-ecommerce-insights' ), $label )
				: __( 'Sync failure email could not be sent. Check that this site can send email.', 'kdna-ecommerce-insights' )
		);
		return $sent;
	}

	/**
	 * Reads the platform's figures for the last few days and replaces the
	 * synced rows for those days. Manual and CSV spend for the same channel
	 * on those days is replaced by the live figures, so nothing is counted
	 * twice. A failed read changes nothing.
	 *
	 * @param string $key  Platform key.
	 * @param int    $days How many days back to read (7 normally, up to 365).
	 * @return array|WP_Error rows, spend, replaced, start, end.
	 */
	public static function sync( string $key, int $days = self::WINDOW_DAYS ) {
		$platform = self::platform( $key );
		if ( ! $platform ) {
			return new WP_Error( 'kdna_ei_unknown_platform', __( 'Unknown ad platform.', 'kdna-ecommerce-insights' ), array( 'status' => 404 ) );
		}

		$started = current_time( 'mysql', true );
		$days    = max( 1, min( 365, $days ) );
		$today   = new DateTimeImmutable( 'today', wp_timezone() );
		$start   = $today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
		$end     = $today->format( 'Y-m-d' );

		if ( ! $platform->configured() ) {
			/* translators: 1: platform, 2: missing items. */
			$error = new WP_Error( 'kdna_ei_not_set_up', sprintf( __( '%1$s is not set up yet. Still needed: %2$s.', 'kdna-ecommerce-insights' ), $platform->label(), implode( ', ', $platform->missing() ) ), array( 'status' => 400 ) );
			return self::failed( $key, $error, $started );
		}

		// Read in chunks of up to 31 days to keep each request small.
		$rows   = array();
		$cursor = new DateTimeImmutable( $start );
		while ( $cursor->format( 'Y-m-d' ) <= $end ) {
			$chunk_end = min( $end, $cursor->modify( '+30 days' )->format( 'Y-m-d' ) );
			$fetched   = $platform->fetch( $cursor->format( 'Y-m-d' ), $chunk_end );
			if ( is_wp_error( $fetched ) ) {
				return self::failed( $key, $fetched, $started );
			}
			$rows   = array_merge( $rows, $fetched );
			$cursor = ( new DateTimeImmutable( $chunk_end ) )->modify( '+1 day' );
		}

		// Convert to the store's currency when the ad account uses another.
		$rate = (float) KDNA_EcommerceInsights_Settings::get( 'marketing.ad_currency_rate', 1 );
		$rate = $rate > 0 ? $rate : 1.0;

		$clean = array();
		foreach ( $rows as $row ) {
			if ( $row['spend_date'] < $start || $row['spend_date'] > $end ) {
				continue;
			}
			$clean[] = array_merge(
				$row,
				array(
					'channel'          => $key,
					'spend'            => $row['spend'] * $rate,
					'conversion_value' => $row['conversion_value'] * $rate,
					'includes_gst'     => false,
				)
			);
		}

		$replaced = self::replace( $key, $start, $end, $clean );
		$spend    = round( array_sum( array_column( $clean, 'spend' ) ), 2 );
		$status   = self::status( $key );

		self::update_status(
			$key,
			array(
				'state'        => 'connected',
				'message'      => '',
				'last_sync'    => current_time( 'mysql', true ),
				'last_success' => current_time( 'mysql', true ),
				'rows'         => count( $clean ),
				'spend'        => $spend,
				'replaced'     => $replaced,
				'synced_from'  => '' === $status['synced_from'] ? $start : min( $status['synced_from'], $start ),
				'synced_to'    => $end,
			)
		);

		KDNA_EcommerceInsights_Log::add(
			'sync_' . $key,
			'success',
			sprintf(
				/* translators: 1: platform, 2: rows, 3: start date, 4: end date, 5: spend. */
				__( '%1$s sync: %2$d campaign days from %3$s to %4$s, %5$s spend.', 'kdna-ecommerce-insights' ),
				$platform->label(),
				count( $clean ),
				$start,
				$end,
				number_format_i18n( $spend, 2 )
			) . ( $replaced > 0 ? ' ' . sprintf(
				/* translators: %s: amount. */
				__( 'Replaced %s of manual or CSV spend for the same dates.', 'kdna-ecommerce-insights' ),
				number_format_i18n( $replaced, 2 )
			) : '' ),
			$started
		);

		do_action( 'kdna_ei_ad_spend_changed' );

		return array(
			'rows'     => count( $clean ),
			'spend'    => $spend,
			'replaced' => round( $replaced, 2 ),
			'start'    => $start,
			'end'      => $end,
		);
	}

	/**
	 * Records a failed sync: the status shows the reason, the log keeps it,
	 * and the figures already saved are left exactly as they were.
	 *
	 * @param string   $key     Platform key.
	 * @param WP_Error $error   What went wrong.
	 * @param string   $started When the sync started (GMT).
	 * @return WP_Error
	 */
	private static function failed( string $key, WP_Error $error, string $started ): WP_Error {
		self::update_status(
			$key,
			array(
				'state'     => 'error',
				'message'   => $error->get_error_message(),
				'last_sync' => current_time( 'mysql', true ),
			)
		);

		$platform = self::platform( $key );
		/* translators: 1: platform, 2: reason. */
		KDNA_EcommerceInsights_Log::add( 'sync_' . $key, 'error', sprintf( __( '%1$s sync failed: %2$s', 'kdna-ecommerce-insights' ), $platform ? $platform->label() : $key, $error->get_error_message() ), $started );

		if ( ! $error->get_error_data() ) {
			$error->add_data( array( 'status' => 400 ) );
		}
		return $error;
	}

	/**
	 * Swaps the synced rows for a channel and date range for new ones, and
	 * removes manual or CSV spend for the same channel and dates.
	 *
	 * @param string  $channel Channel key.
	 * @param string  $start   Y-m-d.
	 * @param string  $end     Y-m-d.
	 * @param array[] $rows    New rows.
	 * @return float Manual and CSV spend that was replaced.
	 */
	private static function replace( string $channel, string $start, string $end, array $rows ): float {
		global $wpdb;
		$table = KDNA_EcommerceInsights_Install::table( 'ad_spend' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$replaced = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( spend ), 0 ) FROM {$table} WHERE channel = %s AND source <> 'api' AND spend_date BETWEEN %s AND %s", $channel, $start, $end ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE channel = %s AND spend_date BETWEEN %s AND %s", $channel, $start, $end ) );
		// phpcs:enable

		// One entry per channel and month, so the entries list stays short.
		$by_month = array();
		foreach ( $rows as $row ) {
			$by_month[ substr( $row['spend_date'], 0, 7 ) ][] = $row;
		}
		foreach ( $by_month as $month => $month_rows ) {
			KDNA_EcommerceInsights_Ad_Spend::insert_rows( $month_rows, 'api', 'api-' . $channel . '-' . $month );
		}

		return round( $replaced, 2 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * No double counting
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether manual or CSV spend for a channel and dates would overlap the
	 * live connection, and if so, a plain-English reason.
	 *
	 * @param string $channel Channel key.
	 * @param string $start   Y-m-d.
	 * @param string $end     Y-m-d.
	 * @return string Empty when allowed.
	 */
	public static function blocked( string $channel, string $start, string $end ): string {
		$platform = self::platform( $channel );
		if ( ! $platform || ! $platform->configured() ) {
			return '';
		}
		$status = self::status( $channel );
		if ( '' === $status['synced_from'] || $end < $status['synced_from'] ) {
			return '';
		}

		return sprintf(
			/* translators: 1: platform, 2: date. */
			__( '%1$s is connected, so its spend from %2$s onwards comes in automatically. Adding it here as well would count it twice. Only spend from before that date can be added here, or disconnect %1$s first.', 'kdna-ecommerce-insights' ),
			$platform->label(),
			date_i18n( get_option( 'date_format' ), strtotime( $status['synced_from'] ) )
		);
	}

	/**
	 * Disconnects a platform: removes its secrets and schedule. Spend
	 * already synced stays, as it is real spend; it can then be deleted or
	 * replaced from the entries list like an import.
	 *
	 * @param string $key Platform key.
	 */
	public static function disconnect( string $key ): void {
		$platform = self::platform( $key );
		if ( ! $platform ) {
			return;
		}
		$platform->disconnect();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $key ), KDNA_EcommerceInsights_Order_Processor::GROUP );
		}
		$all = (array) get_option( self::STATUS_OPTION, array() );
		unset( $all[ $key ] );
		update_option( self::STATUS_OPTION, $all, false );

		/* translators: %s: platform. */
		KDNA_EcommerceInsights_Log::add( 'sync_' . $key, 'success', sprintf( __( '%s disconnected. Spend already synced has been kept.', 'kdna-ecommerce-insights' ), $platform->label() ) );
	}
}
