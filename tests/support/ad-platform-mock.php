<?php
/**
 * Simulated Meta Graph API and Google Ads API for tests.
 *
 * Answers every request to graph.facebook.com, oauth2.googleapis.com and
 * googleads.googleapis.com with replies shaped like the real APIs, so the
 * connections can be tested without accounts or internet access. Behaviour
 * can be switched with $GLOBALS['kdna_ei_t_mode'] (meta_expired,
 * meta_currency, google_revoked, google_dev_not_approved). Requests are
 * recorded in $GLOBALS['kdna_ei_t_log'].
 *
 * Accepted test credentials: Meta token str_repeat( 'EAAG', 15 ); Google
 * client secret 'test-secret-value', sign-in code 'GOODCODE', customer ID
 * 1234567890.
 *
 * Used by tests/test-ad-connections.php, and by a must-use plugin on the
 * local test site for browser tests. Never part of the plugin zip.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['kdna_ei_t_log']  = $GLOBALS['kdna_ei_t_log'] ?? array();
$GLOBALS['kdna_ei_t_mode'] = $GLOBALS['kdna_ei_t_mode'] ?? array();

/**
 * Builds a fake HTTP reply.
 *
 * @param int   $code Status.
 * @param array $body Body to send as JSON.
 * @return array
 */
function kdna_ei_mock_reply( int $code, array $body ): array {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * A day relative to today, Y-m-d.
 *
 * @param int $offset Days back.
 * @return string
 */
function kdna_ei_mock_day( int $offset ): string {
	return ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . $offset . ' days' )->format( 'Y-m-d' );
}

// The simulated Meta and Google APIs.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$GLOBALS['kdna_ei_t_log'][] = array( 'url' => $url, 'args' => $args );
		$mode    = $GLOBALS['kdna_ei_t_mode'];
		$headers = (array) ( $args['headers'] ?? array() );

		// Meta Graph API.
		if ( 0 === strpos( $url, 'https://graph.facebook.com/' ) ) {
			if ( false === strpos( $url, '/' . KDNA_EcommerceInsights_Meta_Ads::GRAPH_VERSION . '/' ) ) {
				return kdna_ei_mock_reply( 400, array( 'error' => array( 'code' => 2635, 'message' => 'Wrong version' ) ) );
			}
			if ( ! empty( $mode['meta_expired'] ) || 'Bearer ' . str_repeat( 'EAAG', 15 ) !== ( $headers['Authorization'] ?? '' ) ) {
				return kdna_ei_mock_reply( 400, array( 'error' => array( 'message' => 'Error validating access token: Session has expired.', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463 ) ) );
			}
			if ( false !== strpos( $url, '/insights' ) ) {
				$row = static fn( $day, $id, $name, $spend, $omni, $value ) => array(
					'date_start'         => kdna_ei_mock_day( $day ),
					'date_stop'          => kdna_ei_mock_day( $day ),
					'campaign_id'        => $id,
					'campaign_name'      => $name,
					'spend'              => (string) $spend,
					'impressions'        => '1500',
					'clicks'             => '90',
					'inline_link_clicks' => '60',
					'actions'            => array(
						array( 'action_type' => 'link_click', 'value' => '60' ),
						array( 'action_type' => 'omni_purchase', 'value' => (string) $omni ),
						array( 'action_type' => 'purchase', 'value' => (string) $omni ),
						array( 'action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => (string) $omni ),
					),
					'action_values'      => array(
						array( 'action_type' => 'omni_purchase', 'value' => (string) $value ),
						array( 'action_type' => 'purchase', 'value' => (string) $value ),
					),
				);
				if ( false === strpos( $url, 'after=PAGE2' ) ) {
					return kdna_ei_mock_reply(
						200,
						array(
							'data'   => array( $row( 1, '2385', 'Spring launch', 120.5, 3, 360 ), $row( 2, '2385', 'Spring launch', 100, 2, 240 ) ),
							'paging' => array( 'next' => 'https://graph.facebook.com/' . KDNA_EcommerceInsights_Meta_Ads::GRAPH_VERSION . '/act_123456789/insights?after=PAGE2' ),
						)
					);
				}
				return kdna_ei_mock_reply( 200, array( 'data' => array( $row( 1, '2399', 'Retargeting', 30, 1, 90 ) ) ) );
			}
			return kdna_ei_mock_reply( 200, array( 'id' => 'act_123456789', 'name' => 'Maison Commerce Ads', 'currency' => $mode['meta_currency'] ?? get_option( 'woocommerce_currency' ), 'timezone_name' => 'Australia/Sydney', 'account_status' => 1 ) );
		}

		// Google sign-in.
		if ( KDNA_EcommerceInsights_Google_Ads::TOKEN_URL === $url ) {
			$body = (array) $args['body'];
			if ( 'test-secret-value' !== ( $body['client_secret'] ?? '' ) ) {
				return kdna_ei_mock_reply( 401, array( 'error' => 'invalid_client' ) );
			}
			if ( 'authorization_code' === $body['grant_type'] ) {
				return 'GOODCODE' === $body['code']
					? kdna_ei_mock_reply( 200, array( 'access_token' => 'ya29.access-one', 'expires_in' => 3599, 'refresh_token' => '1//refresh-token-secret', 'scope' => KDNA_EcommerceInsights_Google_Ads::SCOPE ) )
					: kdna_ei_mock_reply( 400, array( 'error' => 'invalid_grant' ) );
			}
			if ( ! empty( $mode['google_revoked'] ) ) {
				return kdna_ei_mock_reply( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );
			}
			return kdna_ei_mock_reply( 200, array( 'access_token' => 'ya29.access-two', 'expires_in' => 3599 ) );
		}

		// Google Ads API.
		if ( 0 === strpos( $url, KDNA_EcommerceInsights_Google_Ads::API_URL ) ) {
			if ( false === strpos( $url, '/' . KDNA_EcommerceInsights_Google_Ads::API_VERSION . '/customers/1234567890/googleAds:search' ) ) {
				return kdna_ei_mock_reply( 404, array( 'error' => array( 'code' => 404, 'message' => 'Not found' ) ) );
			}
			if ( ! empty( $mode['google_dev_not_approved'] ) ) {
				return kdna_ei_mock_reply( 403, array( 'error' => array( 'code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED', 'details' => array( array( '@type' => 'type.googleapis.com/google.ads.googleads.' . KDNA_EcommerceInsights_Google_Ads::API_VERSION . '.errors.GoogleAdsFailure', 'errors' => array( array( 'errorCode' => array( 'authorizationError' => 'DEVELOPER_TOKEN_NOT_APPROVED' ), 'message' => 'The developer token is only approved for use with test accounts.' ) ) ) ) ) ) );
			}
			$query = (string) ( json_decode( (string) $args['body'], true )['query'] ?? '' );
			$page  = (string) ( json_decode( (string) $args['body'], true )['pageToken'] ?? '' );
			if ( false !== strpos( $query, 'FROM customer' ) ) {
				return kdna_ei_mock_reply( 200, array( 'results' => array( array( 'customer' => array( 'resourceName' => 'customers/1234567890', 'descriptiveName' => 'Maison Commerce Google', 'currencyCode' => get_option( 'woocommerce_currency' ), 'timeZone' => 'Australia/Sydney' ) ) ) ) );
			}
			$row = static fn( $day, $id, $name, $micros, $conv, $value ) => array(
				'campaign' => array( 'resourceName' => 'customers/1234567890/campaigns/' . $id, 'id' => $id, 'name' => $name ),
				'metrics'  => array( 'costMicros' => (string) $micros, 'impressions' => '4210', 'clicks' => '233', 'conversions' => $conv, 'conversionsValue' => $value ),
				'segments' => array( 'date' => kdna_ei_mock_day( $day ) ),
			);
			if ( '' === $page ) {
				return kdna_ei_mock_reply( 200, array( 'results' => array( $row( 1, '17123', 'Search - Brand', 45250000, 3.0, 412.5 ), $row( 2, '17123', 'Search - Brand', 40000000, 2.5, 300 ) ), 'nextPageToken' => 'p2', 'fieldMask' => 'segments.date' ) );
			}
			return kdna_ei_mock_reply( 200, array( 'results' => array( $row( 3, '17999', 'Shopping', 9990000, 1.0, 120 ) ), 'fieldMask' => 'segments.date' ) );
		}

		return $pre;
	},
	10,
	3
);
