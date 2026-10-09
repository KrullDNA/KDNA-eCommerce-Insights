<?php
/**
 * Digest email template.
 *
 * Built for email apps rather than browsers: layout tables, inline styles,
 * web-safe fonts after Figtree, a fixed 600px width for Outlook (inside
 * [if mso] comments) and a "bulletproof" button that Outlook draws itself.
 * Always light, so it reads the same in Gmail, Outlook and Apple Mail.
 *
 * To change the layout for one site, copy this file to
 * kdna-ecommerce-insights/emails/digest.php inside the active theme.
 *
 * @var array $digest Content from KDNA_EcommerceInsights_Digest::data().
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

$kdna_ei_b      = $digest['brand'];
$kdna_ei_font   = "Figtree, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$kdna_ei_text   = '#15161A';
$kdna_ei_muted  = '#6B6E78';
$kdna_ei_line   = '#E4E5EA';
$kdna_ei_tone   = static function ( array $metric ) use ( $kdna_ei_b, $kdna_ei_muted ) {
	if ( 'good' === $metric['sentiment'] ) {
		return $kdna_ei_b['positive'];
	}
	return 'bad' === $metric['sentiment'] ? $kdna_ei_b['negative'] : $kdna_ei_muted;
};
$kdna_ei_heading = static function ( string $text ) use ( $kdna_ei_muted ) {
	return '<p style="margin:0 0 12px;font-size:12px;line-height:16px;letter-spacing:1.5px;text-transform:uppercase;color:' . esc_attr( $kdna_ei_muted ) . ';font-weight:600;">' . esc_html( $text ) . '</p>';
};
$kdna_ei_eyebrow = 'monthly' === $digest['frequency'] ? __( 'Monthly summary', 'kdna-ecommerce-insights' ) : __( 'Weekly summary', 'kdna-ecommerce-insights' );
?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="x-apple-disable-message-reformatting">
	<meta name="color-scheme" content="light only">
	<meta name="supported-color-schemes" content="light only">
	<title><?php echo esc_html( $digest['subject'] ); ?></title>
	<!--[if mso]>
	<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
	<style>table, td, p, a, span { font-family: Arial, sans-serif !important; }</style>
	<![endif]-->
	<style>
		:root { color-scheme: light only; supported-color-schemes: light only; }
		body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
		table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
		img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
		a { color: <?php echo esc_html( $kdna_ei_b['accent'] ); ?>; }
		@media screen and (max-width: 620px) {
			.kdna-ei-pad { padding-left: 20px !important; padding-right: 20px !important; }
			.kdna-ei-col { display: block !important; width: 100% !important; box-sizing: border-box; }
			.kdna-ei-col-gap { display: none !important; }
			.kdna-ei-kpi { margin-bottom: 10px; }
			.kdna-ei-headline { font-size: 34px !important; line-height: 40px !important; }
			.kdna-ei-hide-mobile { display: none !important; }
		}
	</style>
</head>
<body style="margin:0;padding:0;background-color:#F4F5F7;" bgcolor="#F4F5F7">
	<?php // Preview text shown after the subject in the inbox list. ?>
	<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F4F5F7;"><?php echo esc_html( $digest['preheader'] ); ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>

	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F4F5F7" style="background-color:#F4F5F7;">
		<tr>
			<td align="center" style="padding:32px 12px;">
				<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="max-width:600px;background-color:#FFFFFF;border-radius:16px;font-family:<?php echo esc_attr( $kdna_ei_font ); ?>;color:<?php echo esc_attr( $kdna_ei_text ); ?>;">
					<tr>
						<td height="6" bgcolor="<?php echo esc_attr( $kdna_ei_b['accent'] ); ?>" style="height:6px;font-size:0;line-height:0;background-color:<?php echo esc_attr( $kdna_ei_b['accent'] ); ?>;border-radius:16px 16px 0 0;">&nbsp;</td>
					</tr>

					<?php // Brand and title. ?>
					<tr>
						<td class="kdna-ei-pad" style="padding:28px 36px 0;">
							<?php if ( $kdna_ei_b['logo'] ) : ?>
								<img src="<?php echo esc_url( $kdna_ei_b['logo'] ); ?>" alt="<?php echo esc_attr( $kdna_ei_b['store'] ); ?>" height="40" style="display:block;height:40px;width:auto;max-width:220px;border:0;" />
							<?php else : ?>
								<p style="margin:0;font-size:14px;line-height:20px;letter-spacing:2px;text-transform:uppercase;font-weight:600;color:<?php echo esc_attr( $kdna_ei_text ); ?>;"><?php echo esc_html( $kdna_ei_b['store'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td class="kdna-ei-pad" style="padding:24px 36px 0;">
							<p style="margin:0 0 6px;font-size:12px;line-height:16px;letter-spacing:1.5px;text-transform:uppercase;font-weight:600;color:<?php echo esc_attr( $kdna_ei_b['accent'] ); ?>;"><?php echo esc_html( $kdna_ei_eyebrow ); ?></p>
							<h1 style="margin:0;font-size:26px;line-height:32px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_text ); ?>;"><?php echo esc_html( $digest['title'] ); ?></h1>
							<p style="margin:6px 0 0;font-size:15px;line-height:22px;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;"><?php echo esc_html( $digest['dates'] ); ?></p>
						</td>
					</tr>

					<?php // Headline: net profit. ?>
					<tr>
						<td class="kdna-ei-pad" style="padding:24px 36px 8px;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F7F8FA" style="background-color:#F7F8FA;border-radius:12px;">
								<tr>
									<td style="padding:20px 24px;">
										<p style="margin:0;font-size:14px;line-height:20px;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;"><?php echo esc_html( $digest['headline']['label'] ); ?></p>
										<p class="kdna-ei-headline" style="margin:4px 0 0;font-size:40px;line-height:46px;font-weight:600;color:<?php echo esc_attr( (float) $digest['headline']['value'] < 0 ? $kdna_ei_b['negative'] : $kdna_ei_text ); ?>;"><?php echo esc_html( $digest['headline']['display'] ); ?></p>
										<?php if ( '' !== $digest['headline']['delta'] ) : ?>
											<p style="margin:6px 0 0;font-size:14px;line-height:20px;color:<?php echo esc_attr( $kdna_ei_tone( $digest['headline'] ) ); ?>;font-weight:600;">
												<?php echo esc_html( $digest['headline']['delta'] ); ?>
												<span style="font-weight:400;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;"><?php echo esc_html( $digest['compared'] ); ?></span>
											</p>
										<?php endif; ?>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<?php // Key figures, three to a row (one per row on phones). ?>
					<?php if ( ! empty( $digest['kpis'] ) ) : ?>
						<tr>
							<td class="kdna-ei-pad" style="padding:24px 36px 0;">
								<?php echo $kdna_ei_heading( __( 'Key figures', 'kdna-ecommerce-insights' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
									<?php foreach ( array_chunk( $digest['kpis'], 3 ) as $kdna_ei_row ) : ?>
										<tr>
											<?php foreach ( $kdna_ei_row as $kdna_ei_i => $kdna_ei_kpi ) : ?>
												<?php if ( $kdna_ei_i > 0 ) : ?>
													<td class="kdna-ei-col-gap" width="12" style="width:12px;font-size:0;line-height:0;">&nbsp;</td>
												<?php endif; ?>
												<td class="kdna-ei-col kdna-ei-kpi" width="176" valign="top" style="width:176px;padding:14px 16px;border:1px solid <?php echo esc_attr( $kdna_ei_line ); ?>;border-radius:10px;">
													<p style="margin:0;font-size:13px;line-height:18px;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;"><?php echo esc_html( $kdna_ei_kpi['label'] ); ?></p>
													<p style="margin:4px 0 0;font-size:20px;line-height:26px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_text ); ?>;"><?php echo esc_html( $kdna_ei_kpi['display'] ); ?></p>
													<p style="margin:2px 0 0;font-size:13px;line-height:18px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_tone( $kdna_ei_kpi ) ); ?>;"><?php echo '' !== $kdna_ei_kpi['delta'] ? esc_html( $kdna_ei_kpi['delta'] ) : '&nbsp;'; ?></p>
												</td>
											<?php endforeach; ?>
										</tr>
										<tr><td colspan="5" height="12" style="height:12px;font-size:0;line-height:0;">&nbsp;</td></tr>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<?php // Profit breakdown: net revenue down to net profit. ?>
					<?php if ( ! empty( $digest['profit'] ) ) : ?>
						<tr>
							<td class="kdna-ei-pad" style="padding:20px 36px 0;">
								<?php echo $kdna_ei_heading( __( 'Profit breakdown', 'kdna-ecommerce-insights' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
									<?php foreach ( $digest['profit'] as $kdna_ei_line_item ) : ?>
										<?php
										$kdna_ei_total = 'total' === $kdna_ei_line_item['type'];
										$kdna_ei_neg   = $kdna_ei_line_item['amount'] < 0;
										$kdna_ei_style = $kdna_ei_total ? 'font-weight:600;border-top:1px solid ' . $kdna_ei_line . ';' : '';
										?>
										<tr>
											<td style="padding:7px 0;font-size:14px;line-height:20px;<?php echo esc_attr( $kdna_ei_style ); ?>color:<?php echo esc_attr( $kdna_ei_total ? $kdna_ei_text : $kdna_ei_muted ); ?>;"><?php echo esc_html( $kdna_ei_line_item['label'] ); ?></td>
											<td align="right" style="padding:7px 0;font-size:14px;line-height:20px;white-space:nowrap;<?php echo esc_attr( $kdna_ei_style ); ?>color:<?php echo esc_attr( $kdna_ei_neg && $kdna_ei_total ? $kdna_ei_b['negative'] : $kdna_ei_text ); ?>;"><?php echo esc_html( $kdna_ei_line_item['display'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<?php // Top products by profit. ?>
					<?php if ( isset( $digest['top_products'] ) ) : ?>
						<tr>
							<td class="kdna-ei-pad" style="padding:24px 36px 0;">
								<?php echo $kdna_ei_heading( __( 'Top products by profit', 'kdna-ecommerce-insights' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
								<?php if ( $digest['top_products'] ) : ?>
									<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
										<tr>
											<th align="left" style="padding:0 8px 8px 0;font-size:12px;line-height:16px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;border-bottom:1px solid <?php echo esc_attr( $kdna_ei_line ); ?>;"><?php esc_html_e( 'Product', 'kdna-ecommerce-insights' ); ?></th>
											<th align="right" class="kdna-ei-hide-mobile" style="padding:0 8px 8px;font-size:12px;line-height:16px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;border-bottom:1px solid <?php echo esc_attr( $kdna_ei_line ); ?>;"><?php esc_html_e( 'Units', 'kdna-ecommerce-insights' ); ?></th>
											<th align="right" style="padding:0 8px 8px;font-size:12px;line-height:16px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;border-bottom:1px solid <?php echo esc_attr( $kdna_ei_line ); ?>;"><?php esc_html_e( 'Profit', 'kdna-ecommerce-insights' ); ?></th>
											<th align="right" style="padding:0 0 8px 8px;font-size:12px;line-height:16px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;border-bottom:1px solid <?php echo esc_attr( $kdna_ei_line ); ?>;"><?php esc_html_e( 'Margin', 'kdna-ecommerce-insights' ); ?></th>
										</tr>
										<?php foreach ( $digest['top_products'] as $kdna_ei_product ) : ?>
											<tr>
												<td style="padding:10px 8px 10px 0;font-size:14px;line-height:20px;border-bottom:1px solid #F0F1F4;"><?php echo esc_html( $kdna_ei_product['name'] ); ?></td>
												<td align="right" class="kdna-ei-hide-mobile" style="padding:10px 8px;font-size:14px;line-height:20px;border-bottom:1px solid #F0F1F4;white-space:nowrap;"><?php echo esc_html( $kdna_ei_product['units'] ); ?></td>
												<td align="right" style="padding:10px 8px;font-size:14px;line-height:20px;border-bottom:1px solid #F0F1F4;white-space:nowrap;font-weight:600;color:<?php echo esc_attr( $kdna_ei_product['loss'] ? $kdna_ei_b['negative'] : $kdna_ei_text ); ?>;"><?php echo esc_html( $kdna_ei_product['profit'] ); ?></td>
												<td align="right" style="padding:10px 0 10px 8px;font-size:14px;line-height:20px;border-bottom:1px solid #F0F1F4;white-space:nowrap;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;"><?php echo esc_html( $kdna_ei_product['margin'] ); ?></td>
											</tr>
										<?php endforeach; ?>
									</table>
								<?php else : ?>
									<p style="margin:0;font-size:14px;line-height:20px;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;"><?php esc_html_e( 'No products were sold in this period.', 'kdna-ecommerce-insights' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>

					<?php // Stock. ?>
					<?php if ( ! empty( $digest['inventory'] ) ) : ?>
						<tr>
							<td class="kdna-ei-pad" style="padding:24px 36px 0;">
								<?php echo $kdna_ei_heading( __( 'Stock', 'kdna-ecommerce-insights' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
								<p style="margin:0 0 10px;font-size:14px;line-height:22px;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: stock value. */
											__( 'Stock worth %s at cost.', 'kdna-ecommerce-insights' ),
											$digest['inventory']['value']
										) . ' ' . KDNA_EcommerceInsights_Digest::stock_sentence( $digest['inventory']['low_stock'], $digest['inventory']['out_of_stock'] )
									);
									?>
								</p>
								<?php if ( $digest['inventory']['items'] ) : ?>
									<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
										<?php foreach ( $digest['inventory']['items'] as $kdna_ei_item ) : ?>
											<tr>
												<td style="padding:8px 8px 8px 0;font-size:14px;line-height:20px;border-bottom:1px solid #F0F1F4;"><?php echo esc_html( $kdna_ei_item['name'] ); ?></td>
												<td align="right" style="padding:8px 0;font-size:14px;line-height:20px;border-bottom:1px solid #F0F1F4;white-space:nowrap;">
													<?php echo esc_html( $kdna_ei_item['stock'] ); ?>
													<?php if ( $kdna_ei_item['days'] ) : ?>
														<span style="color:<?php echo esc_attr( $kdna_ei_muted ); ?>;">, <?php echo esc_html( $kdna_ei_item['days'] ); ?></span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</table>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>

					<?php // Marketing (only when there was ad spend). ?>
					<?php if ( ! empty( $digest['marketing'] ) ) : ?>
						<tr>
							<td class="kdna-ei-pad" style="padding:24px 36px 0;">
								<?php echo $kdna_ei_heading( __( 'Marketing', 'kdna-ecommerce-insights' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
									<tr>
										<?php foreach ( $digest['marketing'] as $kdna_ei_i => $kdna_ei_kpi ) : ?>
											<td class="kdna-ei-col" width="25%" valign="top" style="padding:0 8px 12px 0;">
												<p style="margin:0;font-size:13px;line-height:18px;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;"><?php echo esc_html( $kdna_ei_kpi['label'] ); ?></p>
												<p style="margin:2px 0 0;font-size:17px;line-height:24px;font-weight:600;"><?php echo esc_html( $kdna_ei_kpi['display'] ); ?></p>
												<?php if ( '' !== $kdna_ei_kpi['delta'] ) : ?>
													<p style="margin:0;font-size:12px;line-height:18px;font-weight:600;color:<?php echo esc_attr( $kdna_ei_tone( $kdna_ei_kpi ) ); ?>;"><?php echo esc_html( $kdna_ei_kpi['delta'] ); ?></p>
												<?php endif; ?>
											</td>
										<?php endforeach; ?>
									</tr>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<?php // Alerts. ?>
					<?php if ( isset( $digest['alerts'] ) ) : ?>
						<tr>
							<td class="kdna-ei-pad" style="padding:24px 36px 0;">
								<?php echo $kdna_ei_heading( __( 'Things that need attention', 'kdna-ecommerce-insights' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
								<?php if ( $digest['alerts'] ) : ?>
									<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
										<?php foreach ( $digest['alerts'] as $kdna_ei_alert ) : ?>
											<?php $kdna_ei_colour = 'negative' === $kdna_ei_alert['tone'] ? $kdna_ei_b['negative'] : $kdna_ei_b['warning']; ?>
											<tr>
												<td width="4" bgcolor="<?php echo esc_attr( $kdna_ei_colour ); ?>" style="width:4px;background-color:<?php echo esc_attr( $kdna_ei_colour ); ?>;font-size:0;line-height:0;">&nbsp;</td>
												<td bgcolor="#F7F8FA" style="padding:10px 14px;font-size:14px;line-height:20px;background-color:#F7F8FA;"><?php echo esc_html( $kdna_ei_alert['text'] ); ?></td>
											</tr>
											<tr><td colspan="2" height="8" style="height:8px;font-size:0;line-height:0;">&nbsp;</td></tr>
										<?php endforeach; ?>
									</table>
								<?php else : ?>
									<p style="margin:0;font-size:14px;line-height:20px;color:<?php echo esc_attr( $kdna_ei_b['positive'] ); ?>;font-weight:600;"><?php esc_html_e( 'All clear. Nothing needs your attention.', 'kdna-ecommerce-insights' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>

					<?php // Button: Outlook draws the VML version, everything else the link. ?>
					<tr>
						<td class="kdna-ei-pad" align="left" style="padding:28px 36px 36px;">
							<!--[if mso]>
							<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?php echo esc_url( $digest['link'] ); ?>" style="height:46px;v-text-anchor:middle;width:220px;" arcsize="50%" stroke="f" fillcolor="<?php echo esc_attr( $kdna_ei_b['accent'] ); ?>">
							<w:anchorlock/>
							<center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;"><?php esc_html_e( 'Open Insights', 'kdna-ecommerce-insights' ); ?></center>
							</v:roundrect>
							<![endif]-->
							<!--[if !mso]><!-->
							<a href="<?php echo esc_url( $digest['link'] ); ?>" style="display:inline-block;padding:13px 28px;border-radius:999px;background-color:<?php echo esc_attr( $kdna_ei_b['accent'] ); ?>;color:#FFFFFF;font-size:15px;line-height:20px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Open Insights', 'kdna-ecommerce-insights' ); ?></a>
							<!--<![endif]-->
						</td>
					</tr>
				</table>

				<?php // Footer. ?>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
					<tr>
						<td class="kdna-ei-pad" style="padding:18px 36px 0;font-family:<?php echo esc_attr( $kdna_ei_font ); ?>;font-size:12px;line-height:18px;color:<?php echo esc_attr( $kdna_ei_muted ); ?>;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: store name. */
									__( 'Sent by KDNA eCommerce Insights for %s. Profit is worked out from your orders, product costs, fees, shipping, ad spend and overheads, excluding tax.', 'kdna-ecommerce-insights' ),
									$kdna_ei_b['store']
								)
							);
							?>
							<a href="<?php echo esc_url( $digest['settings'] ); ?>" style="color:<?php echo esc_attr( $kdna_ei_muted ); ?>;text-decoration:underline;"><?php esc_html_e( 'Change who gets this email, or switch it off.', 'kdna-ecommerce-insights' ); ?></a>
						</td>
					</tr>
				</table>
				<!--[if mso]></td></tr></table><![endif]-->
			</td>
		</tr>
	</table>
</body>
</html>
