<?php
/**
 * Checks that WooCommerce is installed, active and new enough.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out whether the plugin can run, and shows a friendly notice when it cannot.
 */
class KDNA_EcommerceInsights_Requirements {

	/**
	 * Path of the WooCommerce main file, relative to the plugins folder.
	 */
	const WC_PLUGIN_FILE = 'woocommerce/woocommerce.php';

	/**
	 * Returns a short code describing what is missing, or an empty string
	 * when everything the plugin needs is in place.
	 *
	 * @return string One of 'wc_missing', 'wc_inactive', 'wc_outdated' or ''.
	 */
	public static function problem(): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return file_exists( WP_PLUGIN_DIR . '/' . self::WC_PLUGIN_FILE ) ? 'wc_inactive' : 'wc_missing';
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, KDNA_EI_MIN_WC_VERSION, '<' ) ) {
			return 'wc_outdated';
		}

		return '';
	}

	/**
	 * Prints the admin notice explaining what is missing, with a button that
	 * fixes it in one click where the current user is allowed to.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$problem = self::problem();
		$button  = '';

		switch ( $problem ) {
			case 'wc_inactive':
				$message = __( 'KDNA eCommerce Insights needs WooCommerce to be active. WooCommerce is installed, it just needs switching on.', 'kdna-ecommerce-insights' );
				$url     = wp_nonce_url(
					admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( self::WC_PLUGIN_FILE ) ),
					'activate-plugin_' . self::WC_PLUGIN_FILE
				);
				$button  = sprintf( '<a class="button button-primary" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Activate WooCommerce', 'kdna-ecommerce-insights' ) );
				break;

			case 'wc_outdated':
				/* translators: 1: required WooCommerce version, 2: installed WooCommerce version. */
				$message = sprintf( __( 'KDNA eCommerce Insights needs WooCommerce %1$s or newer. This site is running WooCommerce %2$s. Please update WooCommerce to start using Insights.', 'kdna-ecommerce-insights' ), KDNA_EI_MIN_WC_VERSION, WC_VERSION );
				if ( current_user_can( 'update_plugins' ) ) {
					$button = sprintf( '<a class="button button-primary" href="%s">%s</a>', esc_url( admin_url( 'update-core.php' ) ), esc_html__( 'Go to updates', 'kdna-ecommerce-insights' ) );
				}
				break;

			case 'wc_missing':
				$message = __( 'KDNA eCommerce Insights works with WooCommerce, which is not installed on this site yet. Install and activate WooCommerce to start seeing your store insights.', 'kdna-ecommerce-insights' );
				if ( current_user_can( 'install_plugins' ) ) {
					$button = sprintf( '<a class="button button-primary" href="%s">%s</a>', esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ), esc_html__( 'Install WooCommerce', 'kdna-ecommerce-insights' ) );
				}
				break;

			default:
				return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p>%3$s</div>',
			esc_html__( 'Insights is waiting for WooCommerce', 'kdna-ecommerce-insights' ),
			esc_html( $message ),
			$button ? '<p>' . wp_kses_post( $button ) . '</p>' : ''
		);
	}
}
