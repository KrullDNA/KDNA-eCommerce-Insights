<?php
/**
 * Live Meta (Facebook and Instagram) ads connection.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads ad spend from the Meta Marketing API using "bring your own app":
 * each client creates a Meta app in their own Business Manager, adds a
 * system user with read-only access to the ad account, and pastes its
 * access token here (see docs/ad-connections-setup.md). No central KDNA app
 * is involved, so each client stays in control of their own access.
 *
 * Reads campaign-level daily spend, impressions, link clicks, purchases and
 * purchase value from the Insights endpoint. The token is encrypted and
 * never sent to the browser.
 */
class KDNA_EcommerceInsights_Meta_Ads extends KDNA_EcommerceInsights_Ad_Platform {

	/**
	 * The Graph API version used for every request. Meta releases a new
	 * version about every four months and supports each for about two years,
	 * so this is the one line to change when moving to a newer version.
	 */
	const GRAPH_VERSION = 'v26.0';

	/**
	 * Graph API address.
	 */
	const BASE_URL = 'https://graph.facebook.com/';

	/**
	 * Name of the encrypted token.
	 */
	const TOKEN = 'meta_token';

	/**
	 * Purchase action types, in order of preference. "omni_purchase" counts
	 * website, app and shop purchases once each.
	 */
	const PURCHASE_TYPES = array( 'omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase' );

	/**
	 * Channel key.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'meta';
	}

	/**
	 * Name shown to the owner.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Meta';
	}

	/**
	 * The ad account ID as the API wants it: "act_" and digits.
	 *
	 * @return string
	 */
	public function account(): string {
		$digits = self::digits( KDNA_EcommerceInsights_Settings::get( 'marketing.meta.ad_account_id', '' ) );
		return '' === $digits ? '' : 'act_' . $digits;
	}

	/**
	 * Non-secret settings, and whether a token is saved.
	 *
	 * @return array
	 */
	public function settings(): array {
		return array(
			'app_id'        => (string) KDNA_EcommerceInsights_Settings::get( 'marketing.meta.app_id', '' ),
			'ad_account_id' => self::digits( KDNA_EcommerceInsights_Settings::get( 'marketing.meta.ad_account_id', '' ) ),
			'has_token'     => KDNA_EcommerceInsights_Crypto::has( self::TOKEN ),
			'version'       => self::GRAPH_VERSION,
		);
	}

	/**
	 * Saves the app ID, ad account ID and (if typed) a new token.
	 *
	 * @param array $input app_id, ad_account_id, token.
	 * @return array<string, string> Problems keyed by field.
	 */
	public function save( array $input ): array {
		$errors  = array();
		$app_id  = self::digits( $input['app_id'] ?? '' );
		$account = self::digits( preg_replace( '/^act_/i', '', trim( (string) ( $input['ad_account_id'] ?? '' ) ) ) );
		$token   = trim( (string) ( $input['token'] ?? '' ) );

		if ( '' === $app_id ) {
			$errors['app_id'] = __( 'Enter the App ID from your Meta app, a long number such as 1234567890123456.', 'kdna-ecommerce-insights' );
		}
		if ( '' === $account ) {
			$errors['ad_account_id'] = __( 'Enter your ad account ID, the number after "act=" in Ads Manager\'s web address.', 'kdna-ecommerce-insights' );
		}
		if ( '' !== $token && ( strlen( $token ) < 40 || preg_match( '/\s/', $token ) ) ) {
			$errors['token'] = __( 'That does not look like a Meta access token. Copy the whole token, which is long and has no spaces.', 'kdna-ecommerce-insights' );
		}
		if ( '' === $token && ! KDNA_EcommerceInsights_Crypto::has( self::TOKEN ) ) {
			$errors['token'] = __( 'Paste the system user access token you generated in Business Settings.', 'kdna-ecommerce-insights' );
		}
		if ( $errors ) {
			return $errors;
		}

		KDNA_EcommerceInsights_Settings::update(
			array(
				'marketing' => array(
					'meta' => array(
						'app_id'        => $app_id,
						'ad_account_id' => $account,
					),
				),
			)
		);
		if ( '' !== $token ) {
			KDNA_EcommerceInsights_Crypto::set( self::TOKEN, $token );
		}
		return array();
	}

	/**
	 * What still needs filling in.
	 *
	 * @return string[]
	 */
	public function missing(): array {
		$missing = array();
		if ( '' === (string) KDNA_EcommerceInsights_Settings::get( 'marketing.meta.app_id', '' ) ) {
			$missing[] = __( 'App ID', 'kdna-ecommerce-insights' );
		}
		if ( '' === $this->account() ) {
			$missing[] = __( 'Ad account ID', 'kdna-ecommerce-insights' );
		}
		if ( ! KDNA_EcommerceInsights_Crypto::has( self::TOKEN ) ) {
			$missing[] = __( 'Access token', 'kdna-ecommerce-insights' );
		}
		return $missing;
	}

	/**
	 * Removes the token and settings.
	 */
	public function disconnect(): void {
		KDNA_EcommerceInsights_Crypto::set( self::TOKEN, '' );
		KDNA_EcommerceInsights_Settings::update(
			array(
				'marketing' => array(
					'meta' => array(
						'app_id'        => '',
						'ad_account_id' => '',
					),
				),
			)
		);
	}

	/**
	 * The decrypted token, or an error if it cannot be read.
	 *
	 * @return string|WP_Error
	 */
	private function token() {
		$token = KDNA_EcommerceInsights_Crypto::get( self::TOKEN );
		if ( null === $token ) {
			return new WP_Error( 'kdna_ei_meta_token_unreadable', __( 'The saved Meta token can no longer be read, usually because this site\'s security keys changed. Please paste the token in again.', 'kdna-ecommerce-insights' ) );
		}
		if ( '' === $token ) {
			return new WP_Error( 'kdna_ei_meta_no_token', __( 'No Meta access token is saved yet.', 'kdna-ecommerce-insights' ) );
		}
		return $token;
	}

	/**
	 * Builds a Graph API address. The token goes in a header, never in the
	 * address, so it cannot end up in server logs.
	 *
	 * @param string $path  Path after the version, for example "act_123/insights".
	 * @param array  $query Query parameters.
	 * @return string
	 */
	private function url( string $path, array $query = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', $query ), self::BASE_URL . self::GRAPH_VERSION . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Sends an authorised GET request.
	 *
	 * @param string $url Full URL.
	 * @return array|WP_Error
	 */
	private function get( string $url ) {
		// The token only ever goes to Meta's own API over HTTPS, even when
		// following a "next page" address that came back from Meta.
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'graph.facebook.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new WP_Error( 'kdna_ei_meta_bad_address', __( 'Meta sent back an address Insights does not recognise, so the sync stopped to keep your access token safe.', 'kdna-ecommerce-insights' ) );
		}
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return $this->request( 'GET', $url, array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
	}

	/**
	 * Checks the token can read the ad account and returns its details.
	 *
	 * @return array{name: string, currency: string, timezone: string}|WP_Error
	 */
	public function test() {
		if ( ! $this->configured() ) {
			/* translators: %s: list of missing items. */
			return new WP_Error( 'kdna_ei_meta_missing', sprintf( __( 'Meta is not set up yet. Still needed: %s.', 'kdna-ecommerce-insights' ), implode( ', ', $this->missing() ) ) );
		}

		$account = $this->get( $this->url( $this->account(), array( 'fields' => 'name,currency,timezone_name,account_status' ) ) );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		// 1 is active; 2 disabled; 3 unsettled; 101 closed. Reading still works for most, so only warn.
		$status = (int) ( $account['account_status'] ?? 1 );
		return array(
			'name'     => (string) ( $account['name'] ?? $this->account() ),
			'currency' => strtoupper( (string) ( $account['currency'] ?? '' ) ),
			'timezone' => (string) ( $account['timezone_name'] ?? '' ),
			'warning'  => in_array( $status, array( 2, 3, 101 ), true )
				/* translators: %d: Meta account status number. */
				? sprintf( __( 'Meta says this ad account is not active (status %d), so there may be no new spend to read.', 'kdna-ecommerce-insights' ), $status )
				: '',
		);
	}

	/**
	 * Reads campaign-level daily figures, following Meta's pages of results.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array[]|WP_Error
	 */
	public function fetch( string $start, string $end ) {
		$url = $this->url(
			$this->account() . '/insights',
			array(
				'level'          => 'campaign',
				'time_increment' => '1',
				'time_range'     => wp_json_encode( array( 'since' => $start, 'until' => $end ) ),
				'fields'         => 'campaign_id,campaign_name,spend,impressions,clicks,inline_link_clicks,actions,action_values',
				'limit'          => '500',
			)
		);

		$rows  = array();
		$pages = 0;
		while ( $url && $pages < 50 ) {
			++$pages;
			$page = $this->get( $url );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( (array) ( $page['data'] ?? array() ) as $item ) {
				$rows[] = array(
					'spend_date'       => (string) $item['date_start'],
					'campaign_id'      => (string) ( $item['campaign_id'] ?? '' ),
					'campaign_name'    => (string) ( $item['campaign_name'] ?? '' ),
					'spend'            => (float) ( $item['spend'] ?? 0 ),
					'impressions'      => (int) ( $item['impressions'] ?? 0 ),
					'clicks'           => (int) ( $item['inline_link_clicks'] ?? $item['clicks'] ?? 0 ),
					'conversions'      => self::purchase( (array) ( $item['actions'] ?? array() ) ),
					'conversion_value' => self::purchase( (array) ( $item['action_values'] ?? array() ) ),
				);
			}
			$url = (string) ( $page['paging']['next'] ?? '' );
		}

		return $rows;
	}

	/**
	 * Picks the purchase figure from Meta's list of actions, using the first
	 * purchase type present so a purchase is never counted twice.
	 *
	 * @param array[] $actions Items with action_type and value.
	 * @return float
	 */
	private static function purchase( array $actions ): float {
		$by_type = array();
		foreach ( $actions as $action ) {
			$by_type[ (string) ( $action['action_type'] ?? '' ) ] = (float) ( $action['value'] ?? 0 );
		}
		foreach ( self::PURCHASE_TYPES as $type ) {
			if ( isset( $by_type[ $type ] ) ) {
				return $by_type[ $type ];
			}
		}
		return 0.0;
	}

	/**
	 * Turns a Meta error into plain English. Meta's codes: 190 the token is
	 * invalid or expired; 100 a bad ID; 10, 200 to 299 missing permission;
	 * 4, 17, 32, 613 and 80000 to 80014 rate limits.
	 *
	 * @param int   $code HTTP status.
	 * @param array $body Decoded reply.
	 * @return WP_Error
	 */
	protected function explain( int $code, array $body ): WP_Error {
		$error   = (array) ( $body['error'] ?? array() );
		$api     = (int) ( $error['code'] ?? 0 );
		$details = (string) ( $error['message'] ?? '' );

		if ( 190 === $api || 102 === $api ) {
			$message = __( 'Meta says the access token is no longer valid. It may have been reset or the system user removed. Generate a new token in Business Settings and paste it in again.', 'kdna-ecommerce-insights' );
		} elseif ( 100 === $api && false !== stripos( $details, 'act_' ) ) {
			$message = __( 'Meta could not find that ad account. Check the ad account ID, and that the system user has been given access to it in Business Settings.', 'kdna-ecommerce-insights' );
		} elseif ( 10 === $api || ( $api >= 200 && $api <= 299 ) ) {
			$message = __( 'The token does not have permission to read this ad account. In Business Settings, give the system user access to the ad account and generate the token with the ads_read permission.', 'kdna-ecommerce-insights' );
		} elseif ( in_array( $api, array( 4, 17, 32, 613 ), true ) || ( $api >= 80000 && $api <= 80014 ) ) {
			$message = __( 'Meta asked us to slow down because of too many requests. Nothing is lost; the next sync will catch up.', 'kdna-ecommerce-insights' );
		} elseif ( $code >= 500 ) {
			$message = __( 'Meta is having problems right now. Nothing is lost; the next sync will try again.', 'kdna-ecommerce-insights' );
		} else {
			/* translators: %s: Meta's own message. */
			$message = sprintf( __( 'Meta returned an error: %s', 'kdna-ecommerce-insights' ), '' !== $details ? $details : __( 'no reason given', 'kdna-ecommerce-insights' ) );
		}

		return new WP_Error( 'kdna_ei_meta_' . ( $api ? $api : $code ), $message, array( 'status' => 400, 'meta_code' => $api ) );
	}
}
