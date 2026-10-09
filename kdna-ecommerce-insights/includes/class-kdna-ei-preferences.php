<?php
/**
 * Per-user dashboard preferences.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remembers each administrator's own dashboard choices (light or dark theme,
 * Focus Mode, date range and comparison) in their user meta, so two people
 * sharing a store can each have it their way.
 */
class KDNA_EcommerceInsights_Preferences {

	/**
	 * User meta key holding the preferences.
	 */
	const META_KEY = 'kdna_ei_preferences';

	/**
	 * Returns the starting preferences for someone who has not chosen yet,
	 * taken from the store-wide settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'theme'      => KDNA_EcommerceInsights_Settings::get( 'branding.default_theme', 'dark' ),
			'focus'      => false,
			'range'      => KDNA_EcommerceInsights_Settings::get( 'general.default_range', 'this_month' ),
			'comparison' => KDNA_EcommerceInsights_Settings::get( 'general.comparison', 'previous_period' ),
		);
	}

	/**
	 * Returns a user's preferences, with defaults for anything not yet chosen.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return array
	 */
	public static function get( int $user_id = 0 ): array {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$saved   = $user_id ? get_user_meta( $user_id, self::META_KEY, true ) : array();

		return self::sanitise( array_merge( self::defaults(), is_array( $saved ) ? $saved : array() ) );
	}

	/**
	 * Saves changes to a user's preferences and returns the full, cleaned set.
	 *
	 * @param array $changes Preferences to change.
	 * @param int   $user_id User ID, or 0 for the current user.
	 * @return array
	 */
	public static function update( array $changes, int $user_id = 0 ): array {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$prefs   = self::sanitise( array_merge( self::get( $user_id ), $changes ) );

		update_user_meta( $user_id, self::META_KEY, $prefs );

		return $prefs;
	}

	/**
	 * Makes sure every preference holds an allowed value.
	 *
	 * @param array $prefs Preferences to clean.
	 * @return array
	 */
	private static function sanitise( array $prefs ): array {
		$defaults = self::defaults();

		return array(
			'theme'      => in_array( $prefs['theme'] ?? '', array( 'dark', 'light' ), true ) ? $prefs['theme'] : $defaults['theme'],
			'focus'      => (bool) ( $prefs['focus'] ?? false ),
			'range'      => array_key_exists( (string) ( $prefs['range'] ?? '' ), KDNA_EcommerceInsights_Settings::range_presets() ) ? $prefs['range'] : $defaults['range'],
			'comparison' => array_key_exists( (string) ( $prefs['comparison'] ?? '' ), KDNA_EcommerceInsights_Settings::comparison_modes() ) ? $prefs['comparison'] : $defaults['comparison'],
		);
	}
}
