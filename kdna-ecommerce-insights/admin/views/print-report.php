<?php
/**
 * Printable report page (see KDNA_EcommerceInsights_Print_Report).
 *
 * A complete page of its own, styled by admin/css/kdna-ei-print.css for
 * A4 paper. On screen a small toolbar explains how to save it as a PDF;
 * the toolbar never prints.
 *
 * @var array $report Content from KDNA_EcommerceInsights_Print_Report::data().
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

$kdna_ei_b     = $report['brand'];
$kdna_ei_title = sprintf(
	/* translators: 1: store name, 2: dates. */
	__( '%1$s performance report, %2$s', 'kdna-ecommerce-insights' ),
	$kdna_ei_b['store'],
	$report['dates']
);
$kdna_ei_money = static fn( $amount ) => KDNA_EcommerceInsights_Metrics::display( (float) $amount, 'currency', wc_get_price_decimals() );
$kdna_ei_tone  = static fn( $metric ) => 'good' === $metric['sentiment'] ? 'is-good' : ( 'bad' === $metric['sentiment'] ? 'is-bad' : '' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked before this view loads.
$kdna_ei_auto = isset( $_GET['autoprint'] ) && '1' === $_GET['autoprint'];

wp_register_style( 'kdna-ei-fonts', KDNA_EI_URL . 'assets/css/kdna-ei-fonts.css', array(), KDNA_EI_VERSION );
wp_register_style( 'kdna-ei-print', KDNA_EI_URL . 'admin/css/kdna-ei-print.css', array( 'kdna-ei-fonts' ), KDNA_EI_VERSION );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<?php // The browser suggests this as the PDF file name. ?>
	<title><?php echo esc_html( $kdna_ei_title ); ?></title>
	<?php wp_print_styles( array( 'kdna-ei-print' ) ); ?>
	<style>
		:root {
			--kdna-ei-accent: <?php echo esc_html( $kdna_ei_b['accent'] ); ?>;
			--kdna-ei-positive: <?php echo esc_html( $kdna_ei_b['positive'] ); ?>;
			--kdna-ei-negative: <?php echo esc_html( $kdna_ei_b['negative'] ); ?>;
			--kdna-ei-warning: <?php echo esc_html( $kdna_ei_b['warning'] ); ?>;
		}
	</style>
</head>
<body class="kdna-ei-print">

	<?php // Screen-only toolbar. ?>
	<div class="kdna-ei-print__toolbar" role="region" aria-label="<?php esc_attr_e( 'Report tools', 'kdna-ecommerce-insights' ); ?>">
		<div>
			<strong><?php esc_html_e( 'Ready to save as a PDF', 'kdna-ecommerce-insights' ); ?></strong>
			<p><?php esc_html_e( 'Press the button, then choose "Save as PDF" as the destination (on a Mac it may say "PDF" at the bottom of the window). Keep the paper size at A4 and switch on "Background graphics" for the coloured bars.', 'kdna-ecommerce-insights' ); ?></p>
		</div>
		<button type="button" class="kdna-ei-print__button" onclick="window.print()"><?php esc_html_e( 'Save as PDF or print', 'kdna-ecommerce-insights' ); ?></button>
	</div>

	<main class="kdna-ei-print__page">

		<header class="kdna-ei-print__header">
			<div class="kdna-ei-print__brand">
				<?php if ( $kdna_ei_b['logo'] ) : ?>
					<img src="<?php echo esc_url( $kdna_ei_b['logo'] ); ?>" alt="<?php echo esc_attr( $kdna_ei_b['store'] ); ?>" />
				<?php else : ?>
					<span><?php echo esc_html( $kdna_ei_b['store'] ); ?></span>
				<?php endif; ?>
			</div>
			<div class="kdna-ei-print__meta">
				<p class="kdna-ei-print__eyebrow"><?php esc_html_e( 'Performance report', 'kdna-ecommerce-insights' ); ?></p>
				<h1><?php echo esc_html( $report['dates'] ); ?></h1>
				<p>
					<?php if ( $report['compared'] ) : ?>
						<?php
						/* translators: %s: comparison dates. */
						echo esc_html( sprintf( __( 'Compared with %s.', 'kdna-ecommerce-insights' ), $report['compared'] ) );
						?>
					<?php endif; ?>
					<?php
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'Prepared %s.', 'kdna-ecommerce-insights' ), $report['generated'] ) );
					?>
				</p>
			</div>
		</header>

		<?php // ---- Overview ---- ?>
		<section class="kdna-ei-print__section" aria-labelledby="kdna-ei-print-overview">
			<h2 id="kdna-ei-print-overview"><?php esc_html_e( 'Overview', 'kdna-ecommerce-insights' ); ?></h2>

			<div class="kdna-ei-print__kpis">
				<?php foreach ( $report['kpis'] as $kdna_ei_kpi ) : ?>
					<div class="kdna-ei-print__kpi">
						<span class="kdna-ei-print__label"><?php echo esc_html( $kdna_ei_kpi['label'] ); ?></span>
						<strong class="<?php echo esc_attr( null !== $kdna_ei_kpi['value'] && $kdna_ei_kpi['value'] < 0 ? 'is-bad' : '' ); ?>"><?php echo esc_html( $kdna_ei_kpi['display'] ); ?></strong>
						<span class="kdna-ei-print__change <?php echo esc_attr( $kdna_ei_tone( $kdna_ei_kpi ) ); ?>"><?php echo esc_html( $kdna_ei_kpi['delta'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="kdna-ei-print__card kdna-ei-print__keep">
				<div class="kdna-ei-print__card-head">
					<h3><?php esc_html_e( 'Performance', 'kdna-ecommerce-insights' ); ?></h3>
					<ul class="kdna-ei-print__legend">
						<li><span class="dot revenue"></span><?php esc_html_e( 'Net revenue', 'kdna-ecommerce-insights' ); ?></li>
						<li><span class="dot profit"></span><?php esc_html_e( 'Net profit', 'kdna-ecommerce-insights' ); ?></li>
						<?php if ( $report['compare'] ) : ?>
							<li><span class="dot compare"></span><?php esc_html_e( 'Net revenue, comparison period', 'kdna-ecommerce-insights' ); ?></li>
						<?php endif; ?>
					</ul>
				</div>
				<?php echo $report['chart']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from numbers and escaped text in line_chart(). ?>
			</div>

			<p class="kdna-ei-print__stock">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of products in stock, 2: stock value. */
						_n( 'Stock today: %1$d product in stock, worth %2$s at cost.', 'Stock today: %1$d products in stock, worth %2$s at cost.', $report['stock']['status']['in_stock'], 'kdna-ecommerce-insights' ),
						$report['stock']['status']['in_stock'],
						KDNA_EcommerceInsights_Metrics::display( $report['stock']['value'], 'currency' )
					) . ' ' . KDNA_EcommerceInsights_Digest::stock_sentence( $report['stock']['status']['low_stock'], $report['stock']['status']['out_of_stock'] )
				);
				?>
			</p>
		</section>

		<?php // ---- Profit & Loss ---- ?>
		<section class="kdna-ei-print__section kdna-ei-print__break" aria-labelledby="kdna-ei-print-pnl">
			<h2 id="kdna-ei-print-pnl"><?php esc_html_e( 'Profit & Loss', 'kdna-ecommerce-insights' ); ?></h2>

			<div class="kdna-ei-print__split">
				<table class="kdna-ei-print__table kdna-ei-print__statement">
					<caption class="kdna-ei-print__caption"><?php esc_html_e( 'Statement for the whole period', 'kdna-ecommerce-insights' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Line', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Amount', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( '% of revenue', 'kdna-ecommerce-insights' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['statement'] as $kdna_ei_line ) : ?>
							<tr class="<?php echo esc_attr( 'total' === $kdna_ei_line['type'] ? 'is-total' : '' ); ?>">
								<th scope="row"><?php echo esc_html( $kdna_ei_line['label'] ); ?></th>
								<td class="num <?php echo esc_attr( 'total' === $kdna_ei_line['type'] && $kdna_ei_line['amount'] < 0 ? 'is-bad' : '' ); ?>"><?php echo esc_html( $kdna_ei_line['display'] ); ?></td>
								<td class="num muted"><?php echo null === $kdna_ei_line['share'] ? '' : esc_html( number_format_i18n( $kdna_ei_line['share'], 1 ) . '%' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="kdna-ei-print__side">
					<div class="kdna-ei-print__card">
						<h3><?php esc_html_e( 'Where the money went', 'kdna-ecommerce-insights' ); ?></h3>
						<ul class="kdna-ei-print__bars">
							<?php foreach ( $report['costs'] as $kdna_ei_cost ) : ?>
								<li>
									<span class="kdna-ei-print__bar-label"><?php echo esc_html( $kdna_ei_cost['label'] ); ?></span>
									<span class="kdna-ei-print__bar-value"><?php echo esc_html( KDNA_EcommerceInsights_Metrics::display( $kdna_ei_cost['amount'], 'currency' ) ); ?> <span class="muted"><?php echo esc_html( number_format_i18n( $kdna_ei_cost['share'], 1 ) . '%' ); ?></span></span>
									<span class="kdna-ei-print__bar"><span style="width: <?php echo esc_attr( (string) min( 100, max( 0, (float) $kdna_ei_cost['share'] ) ) ); ?>%;"></span></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
					<div class="kdna-ei-print__card kdna-ei-print__margins">
						<div>
							<span class="kdna-ei-print__label"><?php esc_html_e( 'Gross margin', 'kdna-ecommerce-insights' ); ?></span>
							<strong><?php echo esc_html( KDNA_EcommerceInsights_Metrics::display( $report['margins']['gross'], 'percent' ) ); ?></strong>
						</div>
						<div>
							<span class="kdna-ei-print__label"><?php esc_html_e( 'Net margin', 'kdna-ecommerce-insights' ); ?></span>
							<strong class="<?php echo esc_attr( null !== $report['margins']['net'] && $report['margins']['net'] < 0 ? 'is-bad' : '' ); ?>"><?php echo esc_html( KDNA_EcommerceInsights_Metrics::display( $report['margins']['net'], 'percent' ) ); ?></strong>
						</div>
					</div>
				</div>
			</div>

			<?php if ( $report['columns']['columns'] ) : ?>
				<table class="kdna-ei-print__table kdna-ei-print__by-period kdna-ei-print__keep">
					<caption class="kdna-ei-print__caption">
						<?php
						$kdna_ei_units = array(
							'month'   => __( 'By month', 'kdna-ecommerce-insights' ),
							'quarter' => __( 'By quarter', 'kdna-ecommerce-insights' ),
							'year'    => __( 'By year', 'kdna-ecommerce-insights' ),
						);
						echo esc_html( $kdna_ei_units[ $report['columns']['unit'] ] ?? '' );
						?>
					</caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Line', 'kdna-ecommerce-insights' ); ?></th>
							<?php foreach ( $report['columns']['columns'] as $kdna_ei_column ) : ?>
								<th scope="col" class="num"><?php echo esc_html( $kdna_ei_column['label'] ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['statement'] as $kdna_ei_line ) : ?>
							<?php if ( 'total' !== $kdna_ei_line['type'] && ! array_filter( $report['columns']['columns'], static fn( $c ) => abs( (float) ( $c['lines'][ $kdna_ei_line['key'] ] ?? 0 ) ) > 0.004 ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<tr class="<?php echo esc_attr( 'total' === $kdna_ei_line['type'] ? 'is-total' : '' ); ?>">
								<th scope="row"><?php echo esc_html( $kdna_ei_line['label'] ); ?></th>
								<?php foreach ( $report['columns']['columns'] as $kdna_ei_column ) : ?>
									<?php $kdna_ei_amount = (float) ( $kdna_ei_column['lines'][ $kdna_ei_line['key'] ] ?? 0 ); ?>
									<td class="num <?php echo esc_attr( 'total' === $kdna_ei_line['type'] && $kdna_ei_amount < 0 ? 'is-bad' : '' ); ?>"><?php echo esc_html( KDNA_EcommerceInsights_Metrics::display( $kdna_ei_amount, 'currency', 0 ) ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<?php // ---- Top products ---- ?>
		<section class="kdna-ei-print__section" aria-labelledby="kdna-ei-print-products">
			<h2 id="kdna-ei-print-products"><?php esc_html_e( 'Top products by profit', 'kdna-ecommerce-insights' ); ?></h2>
			<?php if ( $report['products'] ) : ?>
				<table class="kdna-ei-print__table kdna-ei-print__products">
					<thead>
						<tr>
							<th scope="col" class="rank">#</th>
							<th scope="col"><?php esc_html_e( 'Product', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Units', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Revenue', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Cost', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Profit', 'kdna-ecommerce-insights' ); ?></th>
							<th scope="col" class="num margin"><?php esc_html_e( 'Margin', 'kdna-ecommerce-insights' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['products'] as $kdna_ei_rank => $kdna_ei_product ) : ?>
							<tr>
								<td class="rank muted"><?php echo esc_html( (string) ( $kdna_ei_rank + 1 ) ); ?></td>
								<th scope="row">
									<?php echo esc_html( $kdna_ei_product['name'] ); ?>
									<?php if ( ! empty( $kdna_ei_product['variation'] ) ) : ?>
										<span class="muted"><?php echo esc_html( $kdna_ei_product['variation'] ); ?></span>
									<?php endif; ?>
								</th>
								<td class="num"><?php echo esc_html( number_format_i18n( (float) $kdna_ei_product['units'] ) ); ?></td>
								<td class="num"><?php echo esc_html( $kdna_ei_money( $kdna_ei_product['revenue'] ) ); ?></td>
								<td class="num muted"><?php echo esc_html( $kdna_ei_money( $kdna_ei_product['cost'] ) ); ?></td>
								<td class="num strong <?php echo esc_attr( $kdna_ei_product['profit'] < 0 ? 'is-bad' : '' ); ?>"><?php echo esc_html( $kdna_ei_money( $kdna_ei_product['profit'] ) ); ?></td>
								<td class="num margin">
									<?php if ( null !== $kdna_ei_product['margin'] ) : ?>
										<span class="kdna-ei-print__mini"><span class="<?php echo esc_attr( $kdna_ei_product['margin'] < 0 ? 'is-bad' : '' ); ?>" style="width: <?php echo esc_attr( (string) min( 100, abs( (float) $kdna_ei_product['margin'] ) ) ); ?>%;"></span></span>
										<?php echo esc_html( number_format_i18n( (float) $kdna_ei_product['margin'], 1 ) . '%' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="muted"><?php esc_html_e( 'No products were sold in this period.', 'kdna-ecommerce-insights' ); ?></p>
			<?php endif; ?>
		</section>

		<?php // ---- Notes ---- ?>
		<footer class="kdna-ei-print__notes">
			<p><?php echo esc_html( $report['tax_note'] ); ?></p>
			<?php if ( $report['estimates']['fee_orders'] || $report['estimates']['shipping_orders'] ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: orders with estimated fees, 2: orders with estimated shipping. */
							__( 'Some costs are estimates from your rules: payment fees on %1$d orders and shipping on %2$d orders.', 'kdna-ecommerce-insights' ),
							$report['estimates']['fee_orders'],
							$report['estimates']['shipping_orders']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( $report['estimates']['missing_cost_orders'] ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of orders. */
							_n( '%d order includes products without a cost price, so profit is overstated.', '%d orders include products without a cost price, so profit is overstated.', $report['estimates']['missing_cost_orders'], 'kdna-ecommerce-insights' ),
							$report['estimates']['missing_cost_orders']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<?php
				/* translators: %s: store name. */
				echo esc_html( sprintf( __( 'Prepared for %s by KDNA eCommerce Insights.', 'kdna-ecommerce-insights' ), $kdna_ei_b['store'] ) );
				?>
			</p>
		</footer>
	</main>

	<?php if ( $kdna_ei_auto ) : ?>
		<script>
			// Opens the print window once the fonts have loaded, so the PDF uses them.
			( function () {
				var go = function () { window.setTimeout( function () { window.print(); }, 150 ); };
				if ( document.fonts && document.fonts.ready ) {
					document.fonts.ready.then( go );
				} else {
					window.addEventListener( 'load', go );
				}
			}() );
		</script>
	<?php endif; ?>
</body>
</html>
