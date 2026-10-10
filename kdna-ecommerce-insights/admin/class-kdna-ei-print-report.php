<?php
/**
 * Printable report.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * A stand-alone page laying out Overview, Profit & Loss and top products
 * for a date range, styled for A4 paper in light colours. It opens in a new
 * tab from the Tax & Reports screen (or the top bar Export menu), and the
 * browser's own print window turns it into a PDF.
 *
 * Address: wp-admin/admin-post.php?action=kdna_ei_print_report with the
 * date range (preset, start, end, compare) and a nonce. Administrators only.
 */
class KDNA_EcommerceInsights_Print_Report {

	/**
	 * admin-post action name, also used for the nonce.
	 */
	const ACTION = 'kdna_ei_print_report';

	/**
	 * Connects the page to WordPress.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'render' ) );
	}

	/**
	 * The address of the printable report, without a date range (the app
	 * adds the range the person is looking at).
	 *
	 * @return string
	 */
	public static function base_url(): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Works out the range and comparison from the address, falling back to
	 * the administrator's saved date range.
	 *
	 * @return array{0: array, 1: array|null}
	 */
	private static function ranges(): array {
		$prefs = KDNA_EcommerceInsights_Preferences::get();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked in render().
		$preset  = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : (string) $prefs['range'];
		$start   = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : (string) $prefs['start'];
		$end     = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : (string) $prefs['end'];
		$compare = isset( $_GET['compare'] ) ? sanitize_key( wp_unslash( $_GET['compare'] ) ) : (string) $prefs['comparison'];
		// phpcs:enable

		if ( ! array_key_exists( $preset, KDNA_EcommerceInsights_Settings::range_presets() ) ) {
			$preset = 'this_month';
		}
		if ( ! array_key_exists( $compare, KDNA_EcommerceInsights_Settings::comparison_modes() ) ) {
			$compare = 'previous_period';
		}

		$range = KDNA_EcommerceInsights_Dates::resolve( $preset, $start, $end );
		if ( is_wp_error( $range ) ) {
			$range = KDNA_EcommerceInsights_Dates::resolve( 'this_month' );
		}

		return array( $range, KDNA_EcommerceInsights_Dates::comparison( $range, $compare ) );
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		if ( ! current_user_can( KDNA_EcommerceInsights_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, only administrators can use Insights.', 'kdna-ecommerce-insights' ), 403 );
		}
		check_admin_referer( self::ACTION );

		list( $range, $compare ) = self::ranges();
		$report                  = self::data( $range, $compare );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		include KDNA_EI_PATH . 'admin/views/print-report.php';
		exit;
	}

	/**
	 * Gathers everything the report shows.
	 *
	 * @param array      $range   Range.
	 * @param array|null $compare Comparison range, or null.
	 * @return array
	 */
	public static function data( array $range, ?array $compare ): array {
		$totals    = KDNA_EcommerceInsights_Report::totals( $range );
		$previous  = $compare ? KDNA_EcommerceInsights_Report::totals( $compare ) : null;
		$estimates = KDNA_EcommerceInsights_Report::estimates( $range );
		$profit    = KDNA_EcommerceInsights_Report::profit( $range );

		$kpis = array();
		foreach ( array( 'net_revenue', 'net_profit', 'orders', 'net_margin', 'average_order_value', 'gross_profit', 'ad_spend', 'new_customers' ) as $key ) {
			$result            = KDNA_EcommerceInsights_Metrics::evaluate( $key, $totals, $previous, $estimates );
			$result['display'] = KDNA_EcommerceInsights_Metrics::display( $result['value'], $result['format'], $result['decimals'] );
			$result['delta']   = KDNA_EcommerceInsights_Metrics::change_text( $result );
			$kpis[]            = $result;
		}

		// Performance chart: revenue and profit, with last period's revenue.
		$granularity = KDNA_EcommerceInsights_Dates::auto_granularity( $range );
		$series      = KDNA_EcommerceInsights_Report::series( $range, $granularity, array( 'net_revenue', 'net_profit' ) );
		$lines       = array(
			array(
				'label'  => __( 'Net revenue', 'kdna-ecommerce-insights' ),
				'values' => $series['series']['net_revenue'],
				'class'  => 'revenue',
			),
			array(
				'label'  => __( 'Net profit', 'kdna-ecommerce-insights' ),
				'values' => $series['series']['net_profit'],
				'class'  => 'profit',
			),
		);
		if ( $compare ) {
			$before  = KDNA_EcommerceInsights_Report::series( $compare, $granularity, array( 'net_revenue' ) );
			$lines[] = array(
				'label'  => __( 'Net revenue, comparison period', 'kdna-ecommerce-insights' ),
				'values' => array_slice( $before['series']['net_revenue'], 0, count( $series['buckets'] ) ),
				'class'  => 'compare',
			);
		}

		// Profit and loss by month, quarter or year, whichever fits A4 (six columns at most).
		$columns = self::statement_columns( $range, $profit );

		$net_revenue = (float) ( $totals['net_revenue'] ?? 0 );
		$statement   = array_map(
			static function ( $line ) use ( $net_revenue ) {
				$line['display'] = KDNA_EcommerceInsights_Metrics::display( $line['amount'], 'currency', wc_get_price_decimals() );
				$line['share']   = abs( $net_revenue ) > 0.004 ? round( $line['amount'] / $net_revenue * 100, 1 ) : null;
				return $line;
			},
			$profit['waterfall']
		);

		$products = KDNA_EcommerceInsights_Report::products( $range, array( 'per_page' => 10, 'orderby' => 'profit', 'order' => 'desc' ) );
		$stock    = KDNA_EcommerceInsights_Inventory::report();

		return array(
			'range'       => $range,
			'compare'     => $compare,
			'brand'       => KDNA_EcommerceInsights_Digest::brand(),
			'dates'       => KDNA_EcommerceInsights_Digest::range_text( $range ),
			'compared'    => $compare ? KDNA_EcommerceInsights_Digest::range_text( $compare ) : '',
			'generated'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'kpis'        => $kpis,
			'chart'       => self::line_chart( $series['buckets'], $lines, $granularity ),
			'granularity' => $granularity,
			'statement'   => $statement,
			'columns'     => $columns,
			'costs'       => $profit['cost_breakdown'],
			'margins'     => $profit['margins'],
			'products'    => $products['rows'],
			'stock'       => array(
				'status' => $stock['status'],
				'value'  => (float) ( $stock['metrics'][1]['value'] ?? 0 ),
			),
			'estimates'   => $estimates,
			'tax_note'    => KDNA_EcommerceInsights_Metrics::revenue_includes_tax()
				? __( 'Revenue figures include tax, as chosen in Settings, General. Profit always excludes tax.', 'kdna-ecommerce-insights' )
				: __( 'All figures exclude tax.', 'kdna-ecommerce-insights' ),
		);
	}

	/**
	 * Splits the profit and loss statement into at most six columns: by
	 * month, or by quarter or year for longer ranges.
	 *
	 * @param array $range  Range.
	 * @param array $profit From KDNA_EcommerceInsights_Report::profit().
	 * @return array{unit: string, columns: array[]} Each column: label, lines (key => amount).
	 */
	private static function statement_columns( array $range, array $profit ): array {
		$months = $profit['months'];
		if ( count( $months ) < 2 ) {
			return array(
				'unit'    => 'none',
				'columns' => array(),
			);
		}

		$unit = 'month';
		$key  = static fn( $start ) => substr( $start, 0, 7 );
		if ( count( $months ) > 6 ) {
			$unit = 'quarter';
			$key  = static fn( $start ) => substr( $start, 0, 4 ) . '-Q' . (int) ceil( (int) substr( $start, 5, 2 ) / 3 );
			$quarters = array_unique( array_map( static fn( $m ) => $key( $m['start'] ), $months ) );
			if ( count( $quarters ) > 6 ) {
				$unit = 'year';
				$key  = static fn( $start ) => substr( $start, 0, 4 );
			}
		}

		$columns = array();
		foreach ( $months as $month ) {
			$id = $key( $month['start'] );
			if ( ! isset( $columns[ $id ] ) ) {
				$columns[ $id ] = array(
					'start' => $month['start'],
					'end'   => $month['end'],
					'lines' => array(),
				);
			}
			$columns[ $id ]['end'] = $month['end'];
			foreach ( $month['lines'] as $line => $amount ) {
				if ( 'net_margin' !== $line ) {
					$columns[ $id ]['lines'][ $line ] = ( $columns[ $id ]['lines'][ $line ] ?? 0 ) + (float) $amount;
				}
			}
		}

		$tz = wp_timezone();
		foreach ( $columns as $id => $column ) {
			$start = new DateTimeImmutable( $column['start'], $tz );
			$end   = new DateTimeImmutable( $column['end'], $tz );
			if ( 'month' === $unit ) {
				$label = wp_date( 'M Y', $start->getTimestamp(), $tz );
			} elseif ( 'quarter' === $unit ) {
				/* translators: 1: first month, 2: last month, 3: year. */
				$label = sprintf( __( '%1$s to %2$s %3$s', 'kdna-ecommerce-insights' ), wp_date( 'M', $start->getTimestamp(), $tz ), wp_date( 'M', $end->getTimestamp(), $tz ), $end->format( 'Y' ) );
			} else {
				$label = $start->format( 'Y' );
			}
			$columns[ $id ]['label'] = $label;
		}

		return array(
			'unit'    => $unit,
			'columns' => array_values( $columns ),
		);
	}

	/**
	 * Draws a simple line chart as SVG, which prints crisply at any size.
	 *
	 * @param array[] $buckets     Chart buckets (start, end).
	 * @param array[] $lines       Each: label, values, class.
	 * @param string  $granularity day, week or month.
	 * @return string SVG markup (built from numbers and escaped text only).
	 */
	public static function line_chart( array $buckets, array $lines, string $granularity ): string {
		$width  = 720;
		$height = 230;
		$left   = 64;
		$right  = 12;
		$top    = 12;
		$bottom = 30;
		$plot_w = $width - $left - $right;
		$plot_h = $height - $top - $bottom;
		$count  = count( $buckets );

		$values = array();
		foreach ( $lines as $line ) {
			foreach ( $line['values'] as $value ) {
				if ( null !== $value ) {
					$values[] = (float) $value;
				}
			}
		}
		$max = $values ? max( 0.0, max( $values ) ) : 0.0;
		$min = $values ? min( 0.0, min( $values ) ) : 0.0;
		if ( $max - $min < 0.01 ) {
			$max = $min + 1;
		}

		// Round the scale to tidy steps, for example 0, 500, 1,000.
		$step = self::nice_step( ( $max - $min ) / 4 );
		$min  = floor( $min / $step ) * $step;
		$max  = ceil( $max / $step ) * $step;

		$x = static fn( $i ) => $left + ( $count > 1 ? $i / ( $count - 1 ) * $plot_w : $plot_w / 2 );
		$y = static fn( $v ) => $top + ( 1 - ( $v - $min ) / ( $max - $min ) ) * $plot_h;

		$svg = '<svg class="kdna-ei-print-chart" viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" role="img" aria-label="' . esc_attr__( 'Net revenue and net profit over time', 'kdna-ecommerce-insights' ) . '" xmlns="http://www.w3.org/2000/svg">';

		// Gridlines and amounts.
		for ( $v = $min; $v <= $max + $step / 2; $v += $step ) {
			$gy   = round( $y( $v ), 1 );
			$svg .= '<line class="grid' . ( abs( $v ) < $step / 1000 ? ' zero' : '' ) . '" x1="' . $left . '" x2="' . ( $width - $right ) . '" y1="' . $gy . '" y2="' . $gy . '" />';
			$svg .= '<text class="axis" x="' . ( $left - 8 ) . '" y="' . ( $gy + 4 ) . '" text-anchor="end">' . esc_html( self::short_money( $v ) ) . '</text>';
		}

		// Dates along the bottom, about six of them.
		$every = max( 1, (int) ceil( $count / 6 ) );
		$tz    = wp_timezone();
		foreach ( $buckets as $i => $bucket ) {
			if ( 0 !== $i % $every && $i !== $count - 1 ) {
				continue;
			}
			if ( $i === $count - 1 && 0 !== $i % $every && ( $count - 1 ) % $every < $every / 2 ) {
				continue;
			}
			$day   = new DateTimeImmutable( $bucket['start'], $tz );
			$label = 'month' === $granularity ? wp_date( 'M Y', $day->getTimestamp(), $tz ) : wp_date( 'j M', $day->getTimestamp(), $tz );
			$svg  .= '<text class="axis" x="' . round( $x( $i ), 1 ) . '" y="' . ( $height - 8 ) . '" text-anchor="middle">' . esc_html( $label ) . '</text>';
		}

		// The lines, comparison first so it sits underneath.
		foreach ( array_reverse( $lines ) as $line ) {
			$points = array();
			foreach ( $line['values'] as $i => $value ) {
				if ( null !== $value ) {
					$points[] = round( $x( $i ), 1 ) . ',' . round( $y( (float) $value ), 1 );
				}
			}
			if ( count( $points ) === 1 ) {
				list( $px, $py ) = explode( ',', $points[0] );
				$svg            .= '<circle class="line ' . esc_attr( $line['class'] ) . '" cx="' . $px . '" cy="' . $py . '" r="3" />';
			} elseif ( $points ) {
				$svg .= '<polyline class="line ' . esc_attr( $line['class'] ) . '" points="' . implode( ' ', $points ) . '" />';
			}
		}

		return $svg . '</svg>';
	}

	/**
	 * A tidy gap between chart gridlines: 1, 2, 2.5 or 5 times a power of ten.
	 *
	 * @param float $rough Rough gap.
	 * @return float
	 */
	private static function nice_step( float $rough ): float {
		if ( $rough <= 0 ) {
			return 1.0;
		}
		$power = pow( 10, floor( log10( $rough ) ) );
		foreach ( array( 1, 2, 2.5, 5, 10 ) as $multiple ) {
			if ( $rough <= $multiple * $power ) {
				return $multiple * $power;
			}
		}
		return 10 * $power;
	}

	/**
	 * Short money for chart axes, such as "$1.2k" or "$3m".
	 *
	 * @param float $value Amount.
	 * @return string
	 */
	private static function short_money( float $value ): string {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$abs    = abs( $value );
		$sign   = $value < 0 ? '-' : '';
		$short  = static function ( float $number ): string {
			$number = round( $number, 1 );
			return number_format_i18n( $number, fmod( $number, 1.0 ) ? 1 : 0 );
		};
		if ( $abs >= 1000000 ) {
			$text = $short( $abs / 1000000 ) . 'm';
		} elseif ( $abs >= 1000 ) {
			$text = $short( $abs / 1000 ) . 'k';
		} else {
			$text = number_format_i18n( $abs );
		}
		return $sign . $symbol . $text;
	}
}
