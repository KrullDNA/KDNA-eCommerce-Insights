<?php
/**
 * Live Google Ads connection.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads ad spend from the Google Ads API.
 *
 * What the client provides (see docs/ad-connections-setup.md):
 * - An OAuth client ID and secret from their own Google Cloud project.
 * - A developer token from their Google Ads manager account (Explorer
 *   access is enough for reading spend).
 * - The customer ID of the ad account, and the manager account ID if they
 *   reach the ad account through a manager.
 *
 * Then they press "Sign in with Google" once. Google sends back a refresh
 * token, which is encrypted and used for every daily sync. Reads campaign
 * spend, impressions, clicks, conversions and conversion value per day.
 */
class KDNA_EcommerceInsights_Google_Ads extends KDNA_EcommerceInsights_Ad_Platform {

	/**
	 * The Google Ads API version used for every request. Google releases
	 * new major versions several times a year and retires each after about
	 * a year, so this is the one line to change when moving to a newer one.
	 */
	const API_VERSION = 'v25';

	/**
	 * Google Ads API address.
	 */
	const API_URL = 'https://googleads.googleapis.com/';

	/**
	 * Google sign-in addresses and the read-only Google Ads permission.
	 */
	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const SCOPE     = 'https://www.googleapis.com/auth/adwords';

	/**
	 * Names of the encrypted secrets, and the short-lived access token.
	 */
	const SECRET          = 'google_client_secret';
	const DEVELOPER_TOKEN = 'google_developer_token';
	const REFRESH_TOKEN   = 'google_refresh_token';
	const ACCESS_CACHE    = 'kdna_ei_google_access';

	/**
	 * admin-post action Google returns to after sign-in.
	 */
	const CALLBACK_ACTION = 'kdna_ei_google_oauth';

	/**
	 * Listens for Google's reply after sign-in.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::CALLBACK_ACTION, array( $this, 'oauth_callback' ) );
	}

	/**
	 * Channel key.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'google';
	}

	/**
	 * Name shown to the owner.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Google Ads';
	}

	/**
	 * The address Google sends people back to after signing in. It must be
	 * added to the OAuth client in Google Cloud exactly as shown.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	/**
	 * Non-secret settings, and which secrets are saved.
	 *
	 * @return array
	 */
	public function settings(): array {
		return array(
			'client_id'           => (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.client_id', '' ),
			'customer_id'         => (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.customer_id', '' ),
			'login_customer_id'   => (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.login_customer_id', '' ),
			'has_secret'          => KDNA_EcommerceInsights_Crypto::has( self::SECRET ),
			'has_developer_token' => KDNA_EcommerceInsights_Crypto::has( self::DEVELOPER_TOKEN ),
			'signed_in'           => KDNA_EcommerceInsights_Crypto::has( self::REFRESH_TOKEN ),
			'redirect_uri'        => self::redirect_uri(),
			'version'             => self::API_VERSION,
		);
	}

	/**
	 * Saves the IDs and any secrets typed in. Changing the OAuth client
	 * means signing in again.
	 *
	 * @param array $input client_id, client_secret, developer_token, customer_id, login_customer_id.
	 * @return array<string, string> Problems keyed by field.
	 */
	public function save( array $input ): array {
		$errors    = array();
		$client_id = trim( sanitize_text_field( (string) ( $input['client_id'] ?? '' ) ) );
		$secret    = trim( (string) ( $input['client_secret'] ?? '' ) );
		$dev_token = trim( (string) ( $input['developer_token'] ?? '' ) );
		$customer  = self::digits( $input['customer_id'] ?? '' );
		$login     = self::digits( $input['login_customer_id'] ?? '' );

		if ( ! preg_match( '/\.apps\.googleusercontent\.com$/', $client_id ) ) {
			$errors['client_id'] = __( 'Enter the OAuth client ID from Google Cloud. It ends in .apps.googleusercontent.com.', 'kdna-ecommerce-insights' );
		}
		if ( '' === $secret && ! KDNA_EcommerceInsights_Crypto::has( self::SECRET ) ) {
			$errors['client_secret'] = __( 'Paste the client secret shown with the OAuth client ID.', 'kdna-ecommerce-insights' );
		}
		if ( '' === $dev_token && ! KDNA_EcommerceInsights_Crypto::has( self::DEVELOPER_TOKEN ) ) {
			$errors['developer_token'] = __( 'Paste the developer token from the API Centre of your Google Ads manager account.', 'kdna-ecommerce-insights' );
		} elseif ( '' !== $dev_token && ! preg_match( '/^[A-Za-z0-9_\-]{16,}$/', $dev_token ) ) {
			$errors['developer_token'] = __( 'That does not look like a developer token. It is a string of about 22 letters, numbers, dashes and underscores.', 'kdna-ecommerce-insights' );
		}
		if ( 10 !== strlen( $customer ) ) {
			$errors['customer_id'] = __( 'Enter the 10-digit customer ID shown at the top of Google Ads, for example 123-456-7890.', 'kdna-ecommerce-insights' );
		}
		if ( '' !== $login && 10 !== strlen( $login ) ) {
			$errors['login_customer_id'] = __( 'The manager account ID is also 10 digits. Leave it empty if you do not use a manager account.', 'kdna-ecommerce-insights' );
		}
		if ( $errors ) {
			return $errors;
		}

		$old_client = (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.client_id', '' );
		KDNA_EcommerceInsights_Settings::update(
			array(
				'marketing' => array(
					'google' => array(
						'client_id'         => $client_id,
						'customer_id'       => $customer,
						'login_customer_id' => $login,
					),
				),
			)
		);
		if ( '' !== $secret ) {
			KDNA_EcommerceInsights_Crypto::set( self::SECRET, $secret );
		}
		if ( '' !== $dev_token ) {
			KDNA_EcommerceInsights_Crypto::set( self::DEVELOPER_TOKEN, $dev_token );
		}

		// A different OAuth client cannot use the old sign-in.
		if ( '' !== $old_client && $old_client !== $client_id ) {
			KDNA_EcommerceInsights_Crypto::set( self::REFRESH_TOKEN, '' );
			delete_transient( self::ACCESS_CACHE );
		}
		return array();
	}

	/**
	 * What still needs doing.
	 *
	 * @return string[]
	 */
	public function missing(): array {
		$missing = array();
		if ( '' === (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.client_id', '' ) || ! KDNA_EcommerceInsights_Crypto::has( self::SECRET ) ) {
			$missing[] = __( 'OAuth client ID and secret', 'kdna-ecommerce-insights' );
		}
		if ( ! KDNA_EcommerceInsights_Crypto::has( self::DEVELOPER_TOKEN ) ) {
			$missing[] = __( 'Developer token', 'kdna-ecommerce-insights' );
		}
		if ( '' === (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.customer_id', '' ) ) {
			$missing[] = __( 'Customer ID', 'kdna-ecommerce-insights' );
		}
		if ( ! KDNA_EcommerceInsights_Crypto::has( self::REFRESH_TOKEN ) ) {
			$missing[] = __( 'Sign in with Google', 'kdna-ecommerce-insights' );
		}
		return $missing;
	}

	/**
	 * Removes every secret and setting, and signs out.
	 */
	public function disconnect(): void {
		foreach ( array( self::SECRET, self::DEVELOPER_TOKEN, self::REFRESH_TOKEN ) as $name ) {
			KDNA_EcommerceInsights_Crypto::set( $name, '' );
		}
		delete_transient( self::ACCESS_CACHE );
		KDNA_EcommerceInsights_Settings::update(
			array(
				'marketing' => array(
					'google' => array(
						'client_id'         => '',
						'customer_id'       => '',
						'login_customer_id' => '',
					),
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Signing in with Google
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The Google sign-in address, with a one-time check value tied to the
	 * current user so the reply cannot be forged.
	 *
	 * @return string|WP_Error
	 */
	public function auth_url() {
		$client_id = (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.client_id', '' );
		if ( '' === $client_id || ! KDNA_EcommerceInsights_Crypto::has( self::SECRET ) ) {
			return new WP_Error( 'kdna_ei_google_no_client', __( 'Save the OAuth client ID and secret first, then sign in.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
		}

		$state = wp_generate_password( 32, false );
		set_transient( 'kdna_ei_google_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'client_id'              => rawurlencode( $client_id ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPE ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Handles Google's reply after sign-in: checks it, swaps the one-time
	 * code for a refresh token, saves it encrypted, then returns to the
	 * Marketing screen with a message.
	 */
	public function oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, only administrators can connect Google Ads.', 'kdna-ecommerce-insights' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Google's reply is checked with the one-time state value below.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		$result = $this->complete_sign_in( $state, $code, $error );

		set_transient(
			'kdna_ei_google_notice_' . get_current_user_id(),
			is_wp_error( $result )
				? array( 'type' => 'negative', 'text' => $result->get_error_message() )
				: array( 'type' => 'positive', 'text' => __( 'Signed in with Google. Insights can now read your Google Ads spend. Press Sync now to bring it in.', 'kdna-ecommerce-insights' ) ),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/marketing' ) );
		exit;
	}

	/**
	 * The checking and code swap behind oauth_callback(), separate so it can
	 * be tested without a redirect.
	 *
	 * @param string $state State returned by Google.
	 * @param string $code  One-time code returned by Google.
	 * @param string $error Error returned by Google, if any.
	 * @return true|WP_Error
	 */
	public function complete_sign_in( string $state, string $code, string $error = '' ) {
		$key      = 'kdna_ei_google_state_' . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );

		if ( 'access_denied' === $error ) {
			return new WP_Error( 'kdna_ei_google_denied', __( 'Google sign-in was cancelled, so Google Ads is not connected yet.', 'kdna-ecommerce-insights' ) );
		}
		if ( '' !== $error ) {
			/* translators: %s: Google's error code. */
			return new WP_Error( 'kdna_ei_google_auth_error', sprintf( __( 'Google sign-in did not work (%s). Check the OAuth client and that the redirect address is added to it exactly.', 'kdna-ecommerce-insights' ), $error ) );
		}
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			return new WP_Error( 'kdna_ei_google_state', __( 'That sign-in link has expired or was not started from this site. Please press Sign in with Google again.', 'kdna-ecommerce-insights' ) );
		}

		$reply = $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		if ( empty( $reply['refresh_token'] ) ) {
			return new WP_Error( 'kdna_ei_google_no_refresh', __( 'Google did not give Insights lasting access. Remove Insights from your Google account\'s third-party access, then sign in again.', 'kdna-ecommerce-insights' ) );
		}

		KDNA_EcommerceInsights_Crypto::set( self::REFRESH_TOKEN, (string) $reply['refresh_token'] );
		$this->cache_access( $reply );
		return true;
	}

	/**
	 * Posts to Google's token address with the client ID and secret.
	 *
	 * @param array $fields Form fields.
	 * @return array|WP_Error
	 */
	private function token_request( array $fields ) {
		$secret = KDNA_EcommerceInsights_Crypto::get( self::SECRET );
		if ( null === $secret ) {
			return new WP_Error( 'kdna_ei_google_secret_unreadable', __( 'The saved Google client secret can no longer be read, usually because this site\'s security keys changed. Please paste it in again.', 'kdna-ecommerce-insights' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array_merge(
					$fields,
					array(
						'client_id'     => (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.client_id', '' ),
						'client_secret' => $secret,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: technical reason. */
			return new WP_Error( 'kdna_ei_google_unreachable', sprintf( __( 'Could not reach Google from this website (%s). The next sync will try again.', 'kdna-ecommerce-insights' ), $response->get_error_message() ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || isset( $body['error'] ) ) {
			$error = is_array( $body ) ? (string) $body['error'] : '';
			if ( 'invalid_grant' === $error ) {
				return new WP_Error( 'kdna_ei_google_invalid_grant', __( 'Google sign-in has expired or was removed. Press Sign in with Google to connect again. (Sign-ins for OAuth apps in "Testing" mode in Google Cloud last 7 days; publish the app to keep it signed in.)', 'kdna-ecommerce-insights' ) );
			}
			if ( 'invalid_client' === $error || 'unauthorized_client' === $error ) {
				return new WP_Error( 'kdna_ei_google_invalid_client', __( 'Google does not recognise the OAuth client ID or secret. Copy both again from Google Cloud.', 'kdna-ecommerce-insights' ) );
			}
			/* translators: %s: Google's error code. */
			return new WP_Error( 'kdna_ei_google_token', sprintf( __( 'Google sign-in failed (%s).', 'kdna-ecommerce-insights' ), '' !== $error ? $error : wp_remote_retrieve_response_code( $response ) ) );
		}
		return $body;
	}

	/**
	 * Keeps the short-lived access token (encrypted) until just before it expires.
	 *
	 * @param array $reply Token reply with access_token and expires_in.
	 */
	private function cache_access( array $reply ): void {
		if ( ! empty( $reply['access_token'] ) ) {
			set_transient( self::ACCESS_CACHE, KDNA_EcommerceInsights_Crypto::encrypt( (string) $reply['access_token'] ), max( 60, (int) ( $reply['expires_in'] ?? 3600 ) - 120 ) );
		}
	}

	/**
	 * A current access token, from the cache or by using the refresh token.
	 *
	 * @return string|WP_Error
	 */
	private function access_token() {
		$cached = get_transient( self::ACCESS_CACHE );
		if ( $cached ) {
			$token = KDNA_EcommerceInsights_Crypto::decrypt( (string) $cached );
			if ( $token ) {
				return $token;
			}
		}

		$refresh = KDNA_EcommerceInsights_Crypto::get( self::REFRESH_TOKEN );
		if ( null === $refresh ) {
			return new WP_Error( 'kdna_ei_google_refresh_unreadable', __( 'The saved Google sign-in can no longer be read, usually because this site\'s security keys changed. Please sign in with Google again.', 'kdna-ecommerce-insights' ) );
		}
		if ( '' === $refresh ) {
			return new WP_Error( 'kdna_ei_google_not_signed_in', __( 'Google Ads is not signed in yet. Press Sign in with Google.', 'kdna-ecommerce-insights' ) );
		}

		$reply = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			)
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$this->cache_access( $reply );
		return (string) $reply['access_token'];
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading reports
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Runs a Google Ads Query Language search, following every page.
	 *
	 * @param string $query GAQL query.
	 * @return array[]|WP_Error Result rows.
	 */
	private function search( string $query ) {
		$access = $this->access_token();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$developer = KDNA_EcommerceInsights_Crypto::get( self::DEVELOPER_TOKEN );
		if ( null === $developer ) {
			return new WP_Error( 'kdna_ei_google_dev_unreadable', __( 'The saved developer token can no longer be read, usually because this site\'s security keys changed. Please paste it in again.', 'kdna-ecommerce-insights' ) );
		}

		$headers = array(
			'Authorization'   => 'Bearer ' . $access,
			'developer-token' => $developer,
			'Content-Type'    => 'application/json',
		);
		$login = (string) KDNA_EcommerceInsights_Settings::get( 'marketing.google.login_customer_id', '' );
		if ( '' !== $login ) {
			$headers['login-customer-id'] = $login;
		}

		$url     = self::API_URL . self::API_VERSION . '/customers/' . KDNA_EcommerceInsights_Settings::get( 'marketing.google.customer_id', '' ) . '/googleAds:search';
		$results = array();
		$token   = '';
		$pages   = 0;

		do {
			++$pages;
			$body  = array_filter( array( 'query' => $query, 'pageToken' => $token ) );
			$reply = $this->request( 'POST', $url, array( 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			$results = array_merge( $results, (array) ( $reply['results'] ?? array() ) );
			$token   = (string) ( $reply['nextPageToken'] ?? '' );
		} while ( '' !== $token && $pages < 50 );

		return $results;
	}

	/**
	 * Checks everything works and reads the account's name and currency.
	 *
	 * @return array{name: string, currency: string, timezone: string}|WP_Error
	 */
	public function test() {
		if ( ! $this->configured() ) {
			/* translators: %s: list of missing items. */
			return new WP_Error( 'kdna_ei_google_missing', sprintf( __( 'Google Ads is not set up yet. Still needed: %s.', 'kdna-ecommerce-insights' ), implode( ', ', $this->missing() ) ) );
		}

		$rows = $this->search( 'SELECT customer.descriptive_name, customer.currency_code, customer.time_zone FROM customer LIMIT 1' );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$customer = (array) ( $rows[0]['customer'] ?? array() );
		return array(
			'name'     => (string) ( $customer['descriptiveName'] ?? KDNA_EcommerceInsights_Settings::get( 'marketing.google.customer_id', '' ) ),
			'currency' => strtoupper( (string) ( $customer['currencyCode'] ?? '' ) ),
			'timezone' => (string) ( $customer['timeZone'] ?? '' ),
			'warning'  => '',
		);
	}

	/**
	 * Reads campaign-level daily figures. Google reports cost in millionths
	 * of the currency ("micros"), so it is divided by a million.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array[]|WP_Error
	 */
	public function fetch( string $start, string $end ) {
		$results = $this->search(
			sprintf(
				"SELECT segments.date, campaign.id, campaign.name, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '%s' AND '%s'",
				preg_replace( '/[^0-9\-]/', '', $start ),
				preg_replace( '/[^0-9\-]/', '', $end )
			)
		);
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$rows = array();
		foreach ( $results as $result ) {
			$metrics = (array) ( $result['metrics'] ?? array() );
			$rows[]  = array(
				'spend_date'       => (string) ( $result['segments']['date'] ?? '' ),
				'campaign_id'      => (string) ( $result['campaign']['id'] ?? '' ),
				'campaign_name'    => (string) ( $result['campaign']['name'] ?? '' ),
				'spend'            => (float) ( $metrics['costMicros'] ?? 0 ) / 1000000,
				'impressions'      => (int) ( $metrics['impressions'] ?? 0 ),
				'clicks'           => (int) ( $metrics['clicks'] ?? 0 ),
				'conversions'      => (float) ( $metrics['conversions'] ?? 0 ),
				'conversion_value' => (float) ( $metrics['conversionsValue'] ?? 0 ),
			);
		}
		return $rows;
	}

	/**
	 * Turns a Google Ads error into plain English, using the error codes in
	 * Google's reply.
	 *
	 * @param int   $code HTTP status.
	 * @param array $body Decoded reply.
	 * @return WP_Error
	 */
	protected function explain( int $code, array $body ): WP_Error {
		$codes = array();
		foreach ( (array) ( $body['error']['details'] ?? array() ) as $detail ) {
			foreach ( (array) ( $detail['errors'] ?? array() ) as $error ) {
				foreach ( (array) ( $error['errorCode'] ?? array() ) as $value ) {
					$codes[] = (string) $value;
				}
			}
		}
		$has = static fn( $value ) => in_array( $value, $codes, true );

		if ( $has( 'DEVELOPER_TOKEN_NOT_APPROVED' ) ) {
			$message = __( 'The developer token only has test account access. Apply for Explorer or Basic access in the API Centre of your Google Ads manager account; Explorer is usually granted quickly.', 'kdna-ecommerce-insights' );
		} elseif ( $has( 'DEVELOPER_TOKEN_INVALID' ) || $has( 'DEVELOPER_TOKEN_PROHIBITED' ) ) {
			$message = __( 'Google does not accept the developer token. Copy it again from the API Centre in your Google Ads manager account.', 'kdna-ecommerce-insights' );
		} elseif ( $has( 'USER_PERMISSION_DENIED' ) ) {
			$message = __( 'The Google account you signed in with cannot see this ad account. If you reach it through a manager account, fill in the manager account ID, or sign in with an account that has access.', 'kdna-ecommerce-insights' );
		} elseif ( $has( 'CUSTOMER_NOT_FOUND' ) || $has( 'INVALID_CUSTOMER_ID' ) || $has( 'CUSTOMER_NOT_ENABLED' ) ) {
			$message = __( 'Google could not find an active ad account with that customer ID. Check the 10-digit number at the top of Google Ads.', 'kdna-ecommerce-insights' );
		} elseif ( $has( 'INVALID_LOGIN_CUSTOMER_ID' ) ) {
			$message = __( 'The manager account ID is not right for this ad account. Check it, or leave it empty if you do not use a manager account.', 'kdna-ecommerce-insights' );
		} elseif ( $has( 'NOT_ADS_USER' ) ) {
			$message = __( 'The Google account you signed in with is not linked to Google Ads. Sign in with the account you use for Google Ads.', 'kdna-ecommerce-insights' );
		} elseif ( $has( 'RESOURCE_EXHAUSTED' ) || $has( 'RESOURCE_TEMPORARILY_EXHAUSTED' ) || 429 === $code ) {
			$message = __( 'Google asked us to slow down because of too many requests. Nothing is lost; the next sync will catch up.', 'kdna-ecommerce-insights' );
		} elseif ( 401 === $code ) {
			delete_transient( self::ACCESS_CACHE );
			$message = __( 'Google did not accept the sign-in. Press Sign in with Google to connect again.', 'kdna-ecommerce-insights' );
		} elseif ( $code >= 500 ) {
			$message = __( 'Google Ads is having problems right now. Nothing is lost; the next sync will try again.', 'kdna-ecommerce-insights' );
		} else {
			/* translators: %s: Google's own message. */
			$message = sprintf( __( 'Google Ads returned an error: %s', 'kdna-ecommerce-insights' ), (string) ( $body['error']['message'] ?? $code ) );
		}

		return new WP_Error( 'kdna_ei_google_' . ( $codes ? strtolower( $codes[0] ) : $code ), $message, array( 'status' => 400, 'google_codes' => $codes ) );
	}
}
