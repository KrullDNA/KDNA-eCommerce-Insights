<?php
/**
 * REST routes: ad spend entries.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes (Administrators only):
 * - GET    /adspend                  Entries overlapping the range.
 * - POST   /adspend                  Add spend for a day or a date range, spread evenly by day.
 * - PUT    /adspend/{group}          Change a manual entry.
 * - DELETE /adspend/{group}          Delete an entry (all its days, or a whole import).
 * - GET    /adspend/setup            Channels, CSV fields and presets for the forms.
 * - POST   /adspend/import/preview   Preview a CSV with a preset or column mapping.
 * - POST   /adspend/import           Import a CSV (replacing earlier CSV spend for the same channel and dates).
 * - POST   /adspend/presets          Save the current column mapping as a preset.
 * - DELETE /adspend/presets          Delete a saved preset by name.
 */
class KDNA_EcommerceInsights_Rest_Adspend extends KDNA_EcommerceInsights_Rest_Report_Base {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_entries' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->range_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_entry' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->entry_args(),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend/(?P<group>[a-z0-9\-]{8,64})',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_entry' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_entry' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->entry_args(),
				),
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend/setup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'setup' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		$import_args = array(
			'csv'          => array( 'type' => 'string', 'required' => true ),
			'channel'      => array( 'type' => 'string', 'default' => '' ),
			'preset'       => array( 'type' => 'string', 'default' => '' ),
			'mapping'      => array( 'type' => 'object', 'default' => array() ),
			'rate'         => array( 'type' => array( 'number', 'string' ), 'default' => 1 ),
			'includes_gst' => array( 'type' => 'boolean', 'default' => false ),
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend/import/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_import' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $import_args,
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_import' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $import_args,
			)
		);

		register_rest_route(
			KDNA_EI_REST_NAMESPACE,
			'/adspend/presets',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_preset' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'name'         => array( 'type' => 'string', 'required' => true ),
						'channel'      => array( 'type' => 'string', 'default' => '' ),
						'mapping'      => array( 'type' => 'object', 'required' => true ),
						'includes_gst' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_preset' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'name' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);
	}

	/**
	 * Arguments for adding or changing a manual entry.
	 *
	 * @return array
	 */
	private function entry_args(): array {
		return array(
			'start'         => array( 'type' => 'string', 'required' => true ),
			'end'           => array( 'type' => 'string' ),
			'channel'       => array( 'type' => 'string', 'required' => true ),
			'campaign_name' => array( 'type' => 'string' ),
			'amount'        => array( 'type' => array( 'number', 'string' ), 'required' => true ),
			'includes_gst'  => array( 'type' => 'boolean', 'default' => false ),
		);
	}

	/**
	 * Reads a manual entry from a request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	private function entry_from( WP_REST_Request $request ): array {
		return array(
			'start'         => (string) $request['start'],
			'end'           => (string) $request['end'],
			'channel'       => (string) $request['channel'],
			'campaign_name' => (string) $request['campaign_name'],
			'amount'        => $request['amount'],
			'includes_gst'  => (bool) $request['includes_gst'],
		);
	}

	/**
	 * A plain-English error with each problem against its field.
	 *
	 * @param array $errors Problems keyed by field.
	 * @return WP_Error
	 */
	private function invalid( array $errors ): WP_Error {
		return new WP_Error(
			'kdna_ei_invalid_ad_spend',
			__( 'Please check the highlighted fields.', 'kdna-ecommerce-insights' ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/**
	 * Changes a manual entry.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_entry( WP_REST_Request $request ) {
		$data   = $this->entry_from( $request );
		$errors = KDNA_EcommerceInsights_Ad_Spend::validate( $data );
		if ( $errors ) {
			return $this->invalid( $errors );
		}
		$result = KDNA_EcommerceInsights_Ad_Spend::update( (string) $request['group'], $data );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'data' => array( 'entry_group' => (string) $request['group'] ) ) );
	}

	/**
	 * Everything the Marketing screen forms need: channels, CSV fields,
	 * presets, the store currency and whether GST can be claimed back.
	 *
	 * @return WP_REST_Response
	 */
	public function setup(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'channels' => (array) KDNA_EcommerceInsights_Settings::get( 'marketing.channels', array() ),
				'fields'   => KDNA_EcommerceInsights_Ad_Spend_Import::fields(),
				'presets'  => KDNA_EcommerceInsights_Ad_Spend_Import::presets(),
				'currency' => (string) get_option( 'woocommerce_currency' ),
				'rate'     => (float) KDNA_EcommerceInsights_Settings::get( 'marketing.ad_currency_rate', 1 ),
				'gst_rate' => KDNA_EcommerceInsights_Ad_Spend::claimable_tax_rate(),
			)
		);
	}

	/**
	 * Reads the import options from a request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	private function import_options( WP_REST_Request $request ): array {
		return array(
			'channel'      => (string) $request['channel'],
			'preset'       => (string) $request['preset'],
			'mapping'      => (array) $request['mapping'],
			'rate'         => (float) KDNA_EcommerceInsights_Csv_Import::parse_amount( (string) $request['rate'] ),
			'includes_gst' => (bool) $request['includes_gst'],
		);
	}

	/**
	 * Previews a CSV import.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_import( WP_REST_Request $request ) {
		$result = KDNA_EcommerceInsights_Ad_Spend_Import::preview( (string) $request['csv'], $this->import_options( $request ) );
		return is_wp_error( $result ) ? $this->bad_request( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Runs a CSV import.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_import( WP_REST_Request $request ) {
		$result = KDNA_EcommerceInsights_Ad_Spend_Import::import( (string) $request['csv'], $this->import_options( $request ) );
		return is_wp_error( $result ) ? $this->bad_request( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Makes sure an import error is sent as a 400 with its plain message.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private function bad_request( WP_Error $error ): WP_Error {
		$data = (array) $error->get_error_data();
		if ( empty( $data['status'] ) ) {
			$error->add_data( array_merge( $data, array( 'status' => 400 ) ) );
		}
		return $error;
	}

	/**
	 * Saves a column mapping as a preset.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_preset( WP_REST_Request $request ) {
		$name = trim( sanitize_text_field( (string) $request['name'] ) );
		if ( '' === $name ) {
			return $this->invalid( array( 'preset_name' => __( 'Give the preset a name, for example "Meta, client account".', 'kdna-ecommerce-insights' ) ) );
		}
		return rest_ensure_response( array( 'presets' => KDNA_EcommerceInsights_Ad_Spend_Import::save_preset( $name, (string) $request['channel'], (array) $request['mapping'], (bool) $request['includes_gst'] ) ) );
	}

	/**
	 * Deletes a saved preset.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_preset( WP_REST_Request $request ): WP_REST_Response {
		$name    = strtolower( (string) $request['name'] );
		$presets = array_values( array_filter( (array) KDNA_EcommerceInsights_Settings::get( 'marketing.csv_presets', array() ), static fn( $p ) => strtolower( $p['name'] ) !== $name ) );
		KDNA_EcommerceInsights_Settings::update( array( 'marketing' => array( 'csv_presets' => $presets ) ) );
		return rest_ensure_response( array( 'presets' => KDNA_EcommerceInsights_Ad_Spend_Import::presets() ) );
	}

	/**
	 * Lists entries overlapping the range.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_entries( WP_REST_Request $request ) {
		$ranges = $this->ranges( $request );
		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}
		list( $range ) = $ranges;

		return rest_ensure_response(
			array(
				'data' => KDNA_EcommerceInsights_Ad_Spend::entries( $range['start'], $range['end'] ),
				'meta' => array( 'range' => $range ),
			)
		);
	}

	/**
	 * Adds an entry after checking it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_entry( WP_REST_Request $request ) {
		$data   = $this->entry_from( $request );
		$errors = KDNA_EcommerceInsights_Ad_Spend::validate( $data );
		if ( $errors ) {
			return $this->invalid( $errors );
		}

		return rest_ensure_response( array( 'data' => array( 'entry_group' => KDNA_EcommerceInsights_Ad_Spend::create( $data ) ) ) );
	}

	/**
	 * Deletes an entry.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_entry( WP_REST_Request $request ) {
		$entry = KDNA_EcommerceInsights_Ad_Spend::entry( (string) $request['group'] );
		if ( $entry && 'api' === $entry['source'] && KDNA_EcommerceInsights_Ad_Sync::platform( $entry['channel'] ) && KDNA_EcommerceInsights_Ad_Sync::platform( $entry['channel'] )->configured() ) {
			return new WP_Error( 'kdna_ei_live_entry', __( 'This spend comes from a live connection and would come straight back at the next sync. Disconnect the platform first if you want to remove it.', 'kdna-ecommerce-insights' ), array( 'status' => 400 ) );
		}
		if ( ! KDNA_EcommerceInsights_Ad_Spend::delete_group( (string) $request['group'] ) ) {
			return new WP_Error( 'kdna_ei_not_found', __( 'That ad spend entry no longer exists.', 'kdna-ecommerce-insights' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'data' => array( 'deleted' => true ) ) );
	}
}
