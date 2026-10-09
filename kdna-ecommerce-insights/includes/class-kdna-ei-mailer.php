<?php
/**
 * Branded HTML emails.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends Insights emails (low stock alerts now, digests later) in one simple,
 * light layout that works in every email app: the store's logo or name, a
 * heading, a short introduction, the content and a link back to Insights.
 * Colours come from Settings > Branding.
 */
class KDNA_EcommerceInsights_Mailer {

	/**
	 * Sends an email to one or more addresses.
	 *
	 * @param string[]|string $to      Addresses, as a list or comma separated.
	 * @param string          $subject Subject line.
	 * @param string          $heading Heading inside the email.
	 * @param string          $intro   Short introduction (plain text).
	 * @param string          $content Body HTML (already escaped).
	 * @param string          $link    Link for the button, or empty for none.
	 * @param string          $button  Button text.
	 * @return bool Whether WordPress accepted the email for sending.
	 */
	public static function send( $to, string $subject, string $heading, string $intro, string $content, string $link = '', string $button = '' ): bool {
		$to = self::recipients( $to );
		if ( ! $to ) {
			return false;
		}

		$html    = self::render( $heading, $intro, $content, $link, $button );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return (bool) wp_mail( $to, $subject, $html, $headers );
	}

	/**
	 * Cleans a list of email addresses, dropping anything that is not one.
	 *
	 * @param string[]|string $to Addresses.
	 * @return string[]
	 */
	public static function recipients( $to ): array {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );
		return array_values( array_filter( array_map( static fn( $email ) => sanitize_email( trim( (string) $email ) ), $list ), 'is_email' ) );
	}

	/**
	 * Builds the email HTML with inline styles, as email apps ignore
	 * stylesheets.
	 *
	 * @param string $heading Heading.
	 * @param string $intro   Introduction (plain text).
	 * @param string $content Body HTML (already escaped).
	 * @param string $link    Button link.
	 * @param string $button  Button text.
	 * @return string
	 */
	public static function render( string $heading, string $intro, string $content, string $link = '', string $button = '' ): string {
		$colours = (array) KDNA_EcommerceInsights_Settings::get( 'branding.colours', KDNA_EcommerceInsights_Settings::default_colours() );
		$accent  = sanitize_hex_color( $colours['light']['accent'] ?? '' ) ? $colours['light']['accent'] : '#5A6FE0';
		$store   = KDNA_EcommerceInsights_Settings::store_name();
		$logo_id = (int) KDNA_EcommerceInsights_Settings::get( 'branding.logo_id', 0 );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		$brand = $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $store ) . '" style="max-height:40px;max-width:200px;border:0;" />'
			: '<span style="font-size:13px;letter-spacing:0.2em;text-transform:uppercase;color:#6B6E78;">' . esc_html( $store ) . '</span>';

		$cta = '' !== $link && '' !== $button
			? '<p style="margin:28px 0 0;"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;background:' . esc_attr( $accent ) . ';color:#FFFFFF;text-decoration:none;font-weight:600;">' . esc_html( $button ) . '</a></p>'
			: '';

		$footer = sprintf(
			/* translators: %s: store name. */
			esc_html__( 'Sent by KDNA eCommerce Insights for %s. You can change these emails in Insights > Settings > Alerts and digests.', 'kdna-ecommerce-insights' ),
			esc_html( $store )
		);

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
			. '<body style="margin:0;padding:0;background:#F4F5F7;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;"><tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:16px;font-family:Figtree,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#15161A;">'
			. '<tr><td style="padding:28px 32px 0;">' . $brand . '</td></tr>'
			. '<tr><td style="padding:20px 32px 32px;">'
			. '<h1 style="margin:0 0 8px;font-size:24px;font-weight:500;line-height:1.3;">' . esc_html( $heading ) . '</h1>'
			. '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#6B6E78;">' . esc_html( $intro ) . '</p>'
			. $content
			. $cta
			. '</td></tr></table>'
			. '<p style="max-width:600px;margin:16px auto 0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#6B6E78;">' . $footer . '</p>'
			. '</td></tr></table></body></html>';
	}

	/**
	 * Builds a simple email table from a header row and data rows. Cells are
	 * escaped here. Columns listed in $right are right-aligned (numbers).
	 *
	 * @param string[] $header Column headings.
	 * @param array[]  $rows   Rows of cell text.
	 * @param int[]    $right  Positions of right-aligned columns.
	 * @return string
	 */
	public static function table( array $header, array $rows, array $right = array() ): string {
		$cell = static function ( $text, $index, $tag ) use ( $right ) {
			$align = in_array( $index, $right, true ) ? 'right' : 'left';
			$style = 'th' === $tag
				? 'padding:8px 6px;font-size:11px;letter-spacing:0.04em;text-transform:uppercase;color:#6B6E78;font-weight:500;border-bottom:1px solid #E4E5EA;text-align:' . $align . ';'
				: 'padding:10px 6px;font-size:14px;border-bottom:1px solid #EEEFF2;text-align:' . $align . ';';
			return '<' . $tag . ' style="' . $style . '">' . esc_html( (string) $text ) . '</' . $tag . '>';
		};

		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>';
		foreach ( $header as $i => $text ) {
			$html .= $cell( $text, $i, 'th' );
		}
		$html .= '</tr>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( array_values( $row ) as $i => $text ) {
				$html .= $cell( $text, $i, 'td' );
			}
			$html .= '</tr>';
		}
		return $html . '</table>';
	}
}
