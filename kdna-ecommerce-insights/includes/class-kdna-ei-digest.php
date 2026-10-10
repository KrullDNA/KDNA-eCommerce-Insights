<?php
/**
 * Weekly and monthly email digests.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends a branded summary email to the chosen recipients once a week or
 * once a month (Settings > Alerts and digests, also on Tax & Reports).
 *
 * - Weekly digests go out at about 7am on the first day of the week (from
 *   Settings > General) and cover the seven days before.
 * - Monthly digests go out at about 7am on the 1st and cover last month.
 * - Each covers its period compared with the period before it.
 *
 * Action Scheduler runs one single job for the next digest. Each run sends
 * the email, logs it, and schedules the one after. The period sent last is
 * remembered, so a retried job never sends the same digest twice.
 *
 * The layout lives in templates/emails/digest.php. A theme can override it
 * by copying it to kdna-ecommerce-insights/emails/digest.php in the theme.
 */
class KDNA_EcommerceInsights_Digest {

	/**
	 * Action Scheduler hook that sends the digest.
	 */
	const HOOK = 'kdna_ei_digest';

	/**
	 * Option remembering the last digest sent.
	 */
	const LAST_OPTION = 'kdna_ei_digest_last';

	/**
	 * Hour of the day (site time) digests are sent.
	 */
	const SEND_HOUR = 7;

	/**
	 * Connects the digest to Action Scheduler and settings changes.
	 */
	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 26 );
		add_action( 'kdna_ei_settings_updated', array( __CLASS__, 'reschedule' ) );
	}

	/**
	 * The sections a digest can include, in the order they appear.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'kpis'         => __( 'Key figures', 'kdna-ecommerce-insights' ),
			'profit'       => __( 'Profit breakdown', 'kdna-ecommerce-insights' ),
			'top_products' => __( 'Top products', 'kdna-ecommerce-insights' ),
			'inventory'    => __( 'Stock', 'kdna-ecommerce-insights' ),
			'marketing'    => __( 'Marketing', 'kdna-ecommerce-insights' ),
			'alerts'       => __( 'Things that need attention', 'kdna-ecommerce-insights' ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Timing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * When the next digest is due, after a given moment.
	 *
	 * Example: weekly, weeks starting Monday, asked on Wednesday 9 October
	 * 2026 gives Monday 14 October 2026 at 7am.
	 *
	 * @param string                 $frequency weekly or monthly.
	 * @param DateTimeImmutable|null $after     Moment to look after. Defaults to now.
	 * @return DateTimeImmutable Site time.
	 */
	public static function next_due( string $frequency, ?DateTimeImmutable $after = null ): DateTimeImmutable {
		$tz    = wp_timezone();
		$after = $after ? $after->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
		$today = $after->setTime( self::SEND_HOUR, 0 );

		if ( 'monthly' === $frequency ) {
			$due = $today->modify( 'first day of this month' );
			return $due > $after ? $due : $due->modify( 'first day of next month' );
		}

		$week   = (int) KDNA_EcommerceInsights_Settings::get( 'general.week_start', 1 );
		$offset = ( $week - (int) $today->format( 'w' ) + 7 ) % 7;
		$due    = $today->modify( '+' . $offset . ' days' );
		return $due > $after ? $due : $due->modify( '+7 days' );
	}

	/**
	 * The day the most recent digest was (or would have been) sent. Previews
	 * and test emails use it, so they always show a complete week or month.
	 *
	 * @param string $frequency weekly or monthly.
	 * @return string Y-m-d.
	 */
	public static function last_due( string $frequency ): string {
		$next = self::next_due( $frequency );
		$last = 'monthly' === $frequency ? $next->modify( 'first day of last month' ) : $next->modify( '-7 days' );
		return $last->format( 'Y-m-d' );
	}

	/**
	 * The period a digest sent on a given day covers: the seven days, or
	 * the calendar month, before it.
	 *
	 * @param string      $frequency weekly or monthly.
	 * @param string|null $day       Send day, Y-m-d. Defaults to today.
	 * @return array Range with preset, start, end and days.
	 */
	public static function period( string $frequency, ?string $day = null ): array {
		$tz  = wp_timezone();
		$day = new DateTimeImmutable( $day ? $day : 'today', $tz );

		if ( 'monthly' === $frequency ) {
			$from = $day->modify( 'first day of last month' );
			$to   = $from->modify( 'last day of this month' );
		} else {
			$from = $day->modify( '-7 days' );
			$to   = $day->modify( '-1 day' );
		}

		return array(
			'preset' => 'custom',
			'start'  => $from->format( 'Y-m-d' ),
			'end'    => $to->format( 'Y-m-d' ),
			'days'   => (int) $from->diff( $to )->days + 1,
		);
	}

	/**
	 * The period before, to compare with: the seven days before, or the
	 * month before.
	 *
	 * @param string $frequency weekly or monthly.
	 * @param array  $range     The digest's period.
	 * @return array
	 */
	public static function previous( string $frequency, array $range ): array {
		$tz    = wp_timezone();
		$start = new DateTimeImmutable( $range['start'], $tz );

		if ( 'monthly' === $frequency ) {
			$from = $start->modify( 'first day of last month' );
			$to   = $from->modify( 'last day of this month' );
		} else {
			$from = $start->modify( '-7 days' );
			$to   = $start->modify( '-1 day' );
		}

		return array(
			'preset' => 'custom',
			'start'  => $from->format( 'Y-m-d' ),
			'end'    => $to->format( 'Y-m-d' ),
			'days'   => (int) $from->diff( $to )->days + 1,
		);
	}

	/**
	 * Makes sure the next digest is scheduled at the right time, or that
	 * nothing is scheduled when digests are off or have no recipients.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$frequency = (string) KDNA_EcommerceInsights_Settings::get( 'alerts.digest_frequency', 'off' );
		$wanted    = in_array( $frequency, array( 'weekly', 'monthly' ), true ) && KDNA_EcommerceInsights_Mailer::recipients( KDNA_EcommerceInsights_Settings::get( 'alerts.digest_recipients', '' ) );
		$next      = as_next_scheduled_action( self::HOOK, null, KDNA_EcommerceInsights_Order_Processor::GROUP );

		if ( ! $wanted ) {
			if ( $next ) {
				as_unschedule_all_actions( self::HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
			}
			return;
		}

		// true means a digest is being sent right now; it schedules the next one itself.
		if ( true === $next ) {
			return;
		}

		$due = self::next_due( $frequency )->getTimestamp();
		if ( is_int( $next ) && $next === $due ) {
			return;
		}
		if ( $next ) {
			as_unschedule_all_actions( self::HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
		}
		as_schedule_single_action( $due, self::HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
	}

	/**
	 * Re-creates the schedule after settings change, so a new frequency or
	 * week start day applies straight away.
	 */
	public static function reschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
		}
		self::schedule();
	}

	/**
	 * When the next digest will be sent, as a GMT date and time, or empty.
	 *
	 * @return string
	 */
	public static function next_send(): string {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return '';
		}
		$next = as_next_scheduled_action( self::HOOK, null, KDNA_EcommerceInsights_Order_Processor::GROUP );
		return is_int( $next ) ? gmdate( 'Y-m-d H:i:s', $next ) : '';
	}

	/**
	 * Everything the Tax & Reports screen shows about digests.
	 *
	 * @return array
	 */
	public static function overview(): array {
		$frequency = (string) KDNA_EcommerceInsights_Settings::get( 'alerts.digest_frequency', 'off' );
		$preview   = 'monthly' === $frequency ? 'monthly' : 'weekly';
		$period    = self::period( $preview, self::next_due( $preview )->format( 'Y-m-d' ) );

		return array(
			'frequency'  => $frequency,
			'recipients' => (string) KDNA_EcommerceInsights_Settings::get( 'alerts.digest_recipients', '' ),
			'sections'   => array_values( (array) KDNA_EcommerceInsights_Settings::get( 'alerts.digest_sections', array() ) ),
			'next_send'  => self::next_send(),
			'next_range' => array(
				'start' => $period['start'],
				'end'   => $period['end'],
			),
			'last'       => get_option( self::LAST_OPTION, null ),
			'options'    => self::sections(),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sending
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Runs from Action Scheduler: sends the digest that is due (once), then
	 * schedules the next one.
	 */
	public static function run(): void {
		$frequency = (string) KDNA_EcommerceInsights_Settings::get( 'alerts.digest_frequency', 'off' );
		if ( ! in_array( $frequency, array( 'weekly', 'monthly' ), true ) ) {
			return;
		}

		$range = self::period( $frequency );
		$key   = $frequency . ':' . $range['start'] . ':' . $range['end'];
		$last  = (array) get_option( self::LAST_OPTION, array() );

		if ( ( $last['key'] ?? '' ) !== $key ) {
			self::send( $frequency );
		}

		// Next one, from a minute from now so this run is never repeated.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
			$due = self::next_due( $frequency, new DateTimeImmutable( '+1 minute', wp_timezone() ) );
			as_schedule_single_action( $due->getTimestamp(), self::HOOK, array(), KDNA_EcommerceInsights_Order_Processor::GROUP );
		}
	}

	/**
	 * Builds and sends a digest.
	 *
	 * @param string        $frequency  weekly or monthly.
	 * @param bool          $test       A test: marked as such in the subject,
	 *                                  not logged as the real digest.
	 * @param string[]|null $recipients Addresses, or null for the saved ones.
	 * @param string[]|null $sections   Sections, or null for the saved ones.
	 * @param string|null   $day        Send day, Y-m-d, which sets the period. Defaults to today.
	 * @return array{sent: bool, message: string}
	 */
	public static function send( string $frequency, bool $test = false, ?array $recipients = null, ?array $sections = null, ?string $day = null ): array {
		$to = KDNA_EcommerceInsights_Mailer::recipients( null === $recipients ? KDNA_EcommerceInsights_Settings::get( 'alerts.digest_recipients', '' ) : $recipients );
		if ( ! $to ) {
			return array(
				'sent'    => false,
				'message' => __( 'Add at least one email address to send the digest to.', 'kdna-ecommerce-insights' ),
			);
		}

		$data    = self::data( $frequency, $sections, $day );
		$html    = self::render( $data );
		$subject = $data['subject'];
		if ( $test ) {
			/* translators: %s: the digest's usual subject line. */
			$subject = sprintf( __( 'Test: %s', 'kdna-ecommerce-insights' ), $subject );
		}

		$sent = (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( ! $test ) {
			update_option(
				self::LAST_OPTION,
				array(
					'key'        => $frequency . ':' . $data['range']['start'] . ':' . $data['range']['end'],
					'frequency'  => $frequency,
					'start'      => $data['range']['start'],
					'end'        => $data['range']['end'],
					'sent_at'    => gmdate( 'Y-m-d H:i:s' ),
					'status'     => $sent ? 'success' : 'error',
					'recipients' => count( $to ),
				),
				false
			);
			KDNA_EcommerceInsights_Log::add(
				'digest',
				$sent ? 'success' : 'error',
				$sent
					/* translators: 1: weekly or monthly, 2: number of people. */
					? sprintf( _n( 'The %1$s digest was sent to %2$d person.', 'The %1$s digest was sent to %2$d people.', count( $to ), 'kdna-ecommerce-insights' ), 'monthly' === $frequency ? __( 'monthly', 'kdna-ecommerce-insights' ) : __( 'weekly', 'kdna-ecommerce-insights' ), count( $to ) )
					: __( 'The digest email could not be sent. Check that this site can send email.', 'kdna-ecommerce-insights' )
			);
		}

		return array(
			'sent'    => $sent,
			'message' => $sent
				/* translators: %s: email addresses. */
				? sprintf( __( 'Sent to %s.', 'kdna-ecommerce-insights' ), implode( ', ', $to ) )
				: __( 'The email could not be sent. Check the addresses, and that this site can send email (an SMTP plugin usually helps).', 'kdna-ecommerce-insights' ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Content
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Gathers everything a digest shows.
	 *
	 * @param string        $frequency weekly or monthly.
	 * @param string[]|null $sections  Sections, or null for the saved ones.
	 * @param string|null   $day       Send day, Y-m-d (for testing).
	 * @return array
	 */
	public static function data( string $frequency, ?array $sections = null, ?string $day = null ): array {
		$frequency = 'monthly' === $frequency ? 'monthly' : 'weekly';
		$sections  = null === $sections ? (array) KDNA_EcommerceInsights_Settings::get( 'alerts.digest_sections', array() ) : $sections;
		$sections  = array_values( array_intersect( array_keys( self::sections() ), $sections ) );
		$range     = self::period( $frequency, $day );
		$compare   = self::previous( $frequency, $range );
		$totals    = KDNA_EcommerceInsights_Report::totals( $range );
		$previous  = KDNA_EcommerceInsights_Report::totals( $compare );
		$estimates = KDNA_EcommerceInsights_Report::estimates( $range );
		$store     = KDNA_EcommerceInsights_Settings::store_name();
		$dates     = self::range_text( $range );

		$metric = static function ( string $key ) use ( $totals, $previous, $estimates ) {
			$result            = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $previous, $estimates );
			$result['display'] = KDNA_EcommerceInsights_Metrics::display( $result['value'], $result['format'], $result['decimals'] );
			$result['delta']   = KDNA_EcommerceInsights_Metrics::change_text( $result );
			return $result;
		};

		$data = array(
			'frequency' => $frequency,
			'range'     => $range,
			'compare'   => $compare,
			'store'     => $store,
			'brand'     => self::brand(),
			'sections'  => $sections,
			'title'     => 'monthly' === $frequency ? __( 'Your month in review', 'kdna-ecommerce-insights' ) : __( 'Your week in review', 'kdna-ecommerce-insights' ),
			'dates'     => $dates,
			'compared'  => 'monthly' === $frequency ? __( 'Compared with the month before.', 'kdna-ecommerce-insights' ) : __( 'Compared with the week before.', 'kdna-ecommerce-insights' ),
			/* translators: 1: store name, 2: weekly or monthly, 3: dates. */
			'subject'   => sprintf( __( '%1$s: your %2$s summary, %3$s', 'kdna-ecommerce-insights' ), $store, 'monthly' === $frequency ? __( 'monthly', 'kdna-ecommerce-insights' ) : __( 'weekly', 'kdna-ecommerce-insights' ), $dates ),
			'link'      => admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/overview' ),
			'settings'  => admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/reports' ),
			'headline'  => $metric( 'net_profit' ),
		);

		$data['preheader'] = sprintf(
			/* translators: 1: net profit, 2: net revenue, 3: number of orders. */
			__( 'Net profit %1$s from %2$s net revenue and %3$s orders.', 'kdna-ecommerce-insights' ),
			$data['headline']['display'],
			KDNA_EcommerceInsights_Metrics::display( KDNA_EcommerceInsights_Metrics::value( 'net_revenue', $totals ), 'currency' ),
			number_format_i18n( (float) ( $totals['orders'] ?? 0 ) )
		);

		if ( in_array( 'kpis', $sections, true ) ) {
			$data['kpis'] = array_map( $metric, array( 'net_revenue', 'net_profit', 'orders', 'net_margin', 'average_order_value', 'new_customers' ) );
		}

		if ( in_array( 'profit', $sections, true ) ) {
			$data['profit'] = array_map(
				static fn( $line ) => array_merge( $line, array( 'display' => KDNA_EcommerceInsights_Metrics::display( $line['amount'], 'currency', wc_get_price_decimals() ) ) ),
				array_values( array_filter( KDNA_EcommerceInsights_Report::waterfall( $totals ), static fn( $line ) => 'total' === $line['type'] || abs( $line['amount'] ) > 0.004 ) )
			);
		}

		if ( in_array( 'top_products', $sections, true ) ) {
			$products             = KDNA_EcommerceInsights_Report::products( $range, array( 'per_page' => 5, 'orderby' => 'profit', 'order' => 'desc' ) );
			$data['top_products'] = array_map(
				static fn( $item ) => array(
					'name'    => $item['name'] . ( empty( $item['variation'] ) ? '' : ', ' . $item['variation'] ),
					'units'   => number_format_i18n( (float) $item['units'] ),
					'revenue' => KDNA_EcommerceInsights_Metrics::display( $item['revenue'], 'currency' ),
					'profit'  => KDNA_EcommerceInsights_Metrics::display( $item['profit'], 'currency' ),
					'margin'  => null === $item['margin'] ? '' : KDNA_EcommerceInsights_Metrics::display( $item['margin'], 'percent', 0 ),
					'loss'    => $item['profit'] < 0,
				),
				$products['rows']
			);
		}

		$stock = null;
		if ( in_array( 'inventory', $sections, true ) || in_array( 'alerts', $sections, true ) ) {
			$stock = KDNA_EcommerceInsights_Inventory::report();
		}

		if ( in_array( 'inventory', $sections, true ) ) {
			$low               = array_slice( array_merge( $stock['out_of_stock'], $stock['low_stock'] ), 0, 5 );
			$data['inventory'] = array(
				'value'        => KDNA_EcommerceInsights_Metrics::display( (float) ( $stock['metrics'][1]['value'] ?? 0 ), 'currency' ),
				'low_stock'    => (int) $stock['status']['low_stock'],
				'out_of_stock' => (int) $stock['status']['out_of_stock'],
				'items'        => array_map(
					static fn( $row ) => array(
						'name'  => $row['name'],
						'stock' => null === $row['stock'] || $row['stock'] <= 0 ? __( 'Out of stock', 'kdna-ecommerce-insights' ) : sprintf( /* translators: %s: units left. */ __( '%s left', 'kdna-ecommerce-insights' ), wc_stock_amount( $row['stock'] ) ),
						'days'  => isset( $row['days'] ) && null !== $row['days'] && $row['stock'] > 0 ? sprintf( /* translators: %d: days. */ _n( 'about %d day', 'about %d days', (int) $row['days'], 'kdna-ecommerce-insights' ), (int) $row['days'] ) : '',
					),
					$low
				),
			);
		}

		if ( in_array( 'marketing', $sections, true ) && ( (float) ( $totals['ad_spend'] ?? 0 ) > 0 || (float) ( $previous['ad_spend'] ?? 0 ) > 0 ) ) {
			$data['marketing'] = array_map( $metric, array( 'ad_spend', 'roas', 'mer', 'cpa' ) );
		}

		if ( in_array( 'alerts', $sections, true ) ) {
			$data['alerts'] = self::alerts( $estimates, $stock, $frequency );
		}

		/**
		 * Filters the digest content before it is turned into an email.
		 *
		 * @param array $data Digest content.
		 */
		return apply_filters( 'kdna_ei_digest_data', $data );
	}

	/**
	 * Plain-English alerts for the digest: missing costs, stock, loss-making
	 * orders, ad connection problems and figures in another currency.
	 *
	 * @param array      $estimates From KDNA_EcommerceInsights_Report::estimates().
	 * @param array|null $stock     From KDNA_EcommerceInsights_Inventory::report().
	 * @param string     $frequency weekly or monthly.
	 * @return array[] Each: tone (warning or negative), text.
	 */
	public static function alerts( array $estimates, ?array $stock, string $frequency ): array {
		$alerts = array();
		$when   = 'monthly' === $frequency ? __( 'last month', 'kdna-ecommerce-insights' ) : __( 'last week', 'kdna-ecommerce-insights' );

		if ( $estimates['missing_costs'] > 0 ) {
			$alerts[] = array(
				'tone' => 'warning',
				/* translators: %d: number of products. */
				'text' => sprintf( _n( '%d product has no cost price, so its profit looks higher than it is.', '%d products have no cost price, so their profit looks higher than it is.', $estimates['missing_costs'], 'kdna-ecommerce-insights' ), $estimates['missing_costs'] ),
			);
		}

		if ( $stock && ( $stock['status']['low_stock'] > 0 || $stock['status']['out_of_stock'] > 0 ) ) {
			$alerts[] = array(
				'tone' => $stock['status']['out_of_stock'] > 0 ? 'negative' : 'warning',
				'text' => self::stock_sentence( (int) $stock['status']['low_stock'], (int) $stock['status']['out_of_stock'] ),
			);
		}

		if ( $estimates['loss_orders'] > 0 ) {
			$alerts[] = array(
				'tone' => 'negative',
				/* translators: 1: number of orders, 2: "last week" or "last month". */
				'text' => sprintf( _n( '%1$d order made a loss %2$s once product, payment and shipping costs were taken off.', '%1$d orders made a loss %2$s once product, payment and shipping costs were taken off.', $estimates['loss_orders'], 'kdna-ecommerce-insights' ), $estimates['loss_orders'], $when ),
			);
		}

		foreach ( KDNA_EcommerceInsights_Ad_Sync::overview() as $connection ) {
			if ( 'error' === $connection['state'] ) {
				$alerts[] = array(
					'tone' => 'negative',
					/* translators: 1: platform name, 2: the problem. */
					'text' => sprintf( __( '%1$s could not sync: %2$s', 'kdna-ecommerce-insights' ), $connection['label'], $connection['message'] ),
				);
			}
		}

		if ( $estimates['currency_flag_orders'] > 0 ) {
			$alerts[] = array(
				'tone' => 'warning',
				/* translators: %d: number of orders. */
				'text' => sprintf( _n( '%d order was in another currency without an exchange rate, so it may be counted at the wrong value.', '%d orders were in another currency without an exchange rate, so they may be counted at the wrong value.', $estimates['currency_flag_orders'], 'kdna-ecommerce-insights' ), $estimates['currency_flag_orders'] ),
			);
		}

		return $alerts;
	}

	/**
	 * "1 product is low on stock and 3 are out of stock", with the right
	 * singular or plural for each number.
	 *
	 * @param int $low Products low on stock.
	 * @param int $out Products out of stock.
	 * @return string
	 */
	public static function stock_sentence( int $low, int $out ): string {
		/* translators: %d: number of products. */
		$low_text = sprintf( _n( '%d product is low on stock', '%d products are low on stock', $low, 'kdna-ecommerce-insights' ), $low );
		/* translators: %d: number of products. */
		$out_text = sprintf( _n( '%d is out of stock', '%d are out of stock', $out, 'kdna-ecommerce-insights' ), $out );
		/* translators: 1: low stock part, 2: out of stock part. */
		return sprintf( __( '%1$s and %2$s.', 'kdna-ecommerce-insights' ), $low_text, $out_text );
	}

	/**
	 * A range as words, such as "1 to 30 September 2026" or
	 * "28 September to 4 October 2026".
	 *
	 * @param array $range Range.
	 * @return string
	 */
	public static function range_text( array $range ): string {
		$tz   = wp_timezone();
		$from = new DateTimeImmutable( $range['start'], $tz );
		$to   = new DateTimeImmutable( $range['end'], $tz );

		if ( $from->format( 'Y-m-d' ) === $to->format( 'Y-m-d' ) ) {
			return wp_date( 'j F Y', $to->getTimestamp(), $tz );
		}
		if ( $from->format( 'Y-m' ) === $to->format( 'Y-m' ) ) {
			$first = wp_date( 'j', $from->getTimestamp(), $tz );
		} elseif ( $from->format( 'Y' ) === $to->format( 'Y' ) ) {
			$first = wp_date( 'j F', $from->getTimestamp(), $tz );
		} else {
			$first = wp_date( 'j F Y', $from->getTimestamp(), $tz );
		}
		/* translators: 1: first day, 2: last day. */
		return sprintf( __( '%1$s to %2$s', 'kdna-ecommerce-insights' ), $first, wp_date( 'j F Y', $to->getTimestamp(), $tz ) );
	}

	/**
	 * Brand details for emails: store name, logo, and the light theme
	 * colours from Settings > Branding (emails are always light, so they
	 * read well in every email app).
	 *
	 * @return array{store: string, logo: string, accent: string, positive: string, negative: string, warning: string}
	 */
	public static function brand(): array {
		$defaults = KDNA_EcommerceInsights_Settings::default_colours()['light'];
		$colours  = (array) KDNA_EcommerceInsights_Settings::get( 'branding.colours.light', array() );
		$pick     = static function ( $key ) use ( $colours, $defaults ) {
			$value = sanitize_hex_color( (string) ( $colours[ $key ] ?? '' ) );
			return $value ? $value : $defaults[ $key ];
		};
		$logo_id = (int) KDNA_EcommerceInsights_Settings::get( 'branding.logo_id', 0 );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		// Email apps cannot show SVG images, so an SVG logo falls back to the store name.
		if ( $logo && preg_match( '/\.svg(\?|$)/i', $logo ) ) {
			$logo = '';
		}

		return array(
			'store'     => KDNA_EcommerceInsights_Settings::store_name(),
			'logo'      => (string) $logo,
			'accent'    => $pick( 'accent' ),
			// Readable versions of the accent for links, the eyebrow and the button (WCAG AA on white).
			'link'      => KDNA_EcommerceInsights_Admin::mix_colour( $pick( 'accent' ), '#000000', 0.8 ),
			'button'    => KDNA_EcommerceInsights_Admin::mix_colour( $pick( 'accent' ), '#000000', 0.85 ),
			'on_button' => KDNA_EcommerceInsights_Admin::contrast( KDNA_EcommerceInsights_Admin::mix_colour( $pick( 'accent' ), '#000000', 0.85 ), '#FFFFFF' ) >= 4.5 ? '#FFFFFF' : '#17181C',
			'positive'  => $pick( 'positive' ),
			'negative'  => $pick( 'negative' ),
			'warning'   => $pick( 'warning' ),
		);
	}

	/**
	 * Turns digest content into the email HTML using the template.
	 *
	 * @param array $data Content from data().
	 * @return string
	 */
	public static function render( array $data ): string {
		$template = locate_template( 'kdna-ecommerce-insights/emails/digest.php' );
		if ( ! $template ) {
			$template = KDNA_EI_PATH . 'templates/emails/digest.php';
		}

		ob_start();
		$digest = $data;
		include $template;
		return (string) ob_get_clean();
	}
}
