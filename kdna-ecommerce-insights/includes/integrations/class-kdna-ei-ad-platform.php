<?php
/**
 * Shared base for live ad platform connections (Meta and Google Ads).
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * What every live connection does, so the sync, the REST routes and the
 * Marketing screen can treat Meta and Google the same way:
 *
 * - settings(): the non-secret settings, safe to show in the browser.
 * - save(): saves settings and secrets (secrets are encrypted).
 * - missing(): what still needs filling in, in plain English.
 * - test(): checks the details work and returns the ad account's name and currency.
 * - fetch(): campaign-level daily figures for a date range.
 *
 * Every error is turned into a plain-English sentence for the owner.
 */
abstract class KDNA_EcommerceInsights_Ad_Platform {

	/**
	 * Channel key, for example 'meta'.
	 *
	 * @return string
	 */
	abstract public function key(): string;

	/**
	 * Name shown to the owner, for example 'Meta'.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Non-secret settings and which secrets are saved, for the browser.
	 *
	 * @return array
	 */
	abstract public function settings(): array;

	/**
	 * Saves settings and any secrets typed in. Empty secret fields keep the
	 * saved value.
	 *
	 * @param array $input Submitted values.
	 * @return array<string, string> Problems keyed by field, empty when saved.
	 */
	abstract public function save( array $input ): array;

	/**
	 * What still needs filling in before the connection can work.
	 *
	 * @return string[] Plain-English items.
	 */
	abstract public function missing(): array;

	/**
	 * Checks the connection and reads the ad account's details.
	 *
	 * @return array{name: string, currency: string, timezone: string}|WP_Error
	 */
	abstract public function test();

	/**
	 * Reads campaign-level daily figures for a date range, in the ad
	 * account's own currency.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array[]|WP_Error Rows: spend_date, campaign_id, campaign_name,
	 *                          spend, impressions, clicks, conversions, conversion_value.
	 */
	abstract public function fetch( string $start, string $end );

	/**
	 * Whether everything needed is filled in.
	 *
	 * @return bool
	 */
	public function configured(): bool {
		return ! $this->missing();
	}

	/**
	 * Removes every saved secret and setting for this connection.
	 */
	abstract public function disconnect(): void;

	/**
	 * Sends a request and decodes the JSON reply. Network problems become a
	 * plain-English error; replies with an error status are passed to
	 * explain() so each platform can describe them.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args   wp_remote_request arguments (headers, body).
	 * @return array|WP_Error Decoded reply.
	 */
	protected function request( string $method, string $url, array $args = array() ) {
		$response = wp_remote_request(
			$url,
			array_merge(
				array(
					'method'  => $method,
					'timeout' => 30,
				),
				$args
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'kdna_ei_' . $this->key() . '_unreachable',
				/* translators: 1: platform, 2: technical reason. */
				sprintf( __( 'Could not reach %1$s from this website (%2$s). This is usually temporary; the next sync will try again.', 'kdna-ecommerce-insights' ), $this->label(), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $body ) || isset( $body['error'] ) ) {
			return $this->explain( $code, is_array( $body ) ? $body : array() );
		}

		return $body;
	}

	/**
	 * Turns an error reply into a plain-English WP_Error.
	 *
	 * @param int   $code HTTP status.
	 * @param array $body Decoded reply.
	 * @return WP_Error
	 */
	abstract protected function explain( int $code, array $body ): WP_Error;

	/**
	 * Keeps only digits, for account and customer IDs typed with dashes.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected static function digits( $value ): string {
		return preg_replace( '/\D/', '', (string) $value );
	}
}
