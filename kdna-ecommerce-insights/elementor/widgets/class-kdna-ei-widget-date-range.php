<?php
/**
 * Elementor widget: Insights Date Range.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use KDNA_EcommerceInsights_Widget_Controls as Controls;

/**
 * The date range dropdown and comparison choice (section 9). Choosing a
 * range broadcasts kdna:ei-range-change, so every Insights widget on the
 * page set to "Follow page date range" updates together. The choice is
 * saved to the Administrator's own preferences, so the wp-admin app and
 * other pages open on the same range.
 */
class KDNA_EcommerceInsights_Widget_Date_Range extends KDNA_EcommerceInsights_Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kdna-ei-date-range';
	}

	/**
	 * Widget title in the panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Insights Date Range', 'kdna-ecommerce-insights' );
	}

	/**
	 * Widget icon in the panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-calendar';
	}

	/**
	 * Widget type for the script.
	 *
	 * @return string
	 */
	protected function widget_type(): string {
		return 'date-range';
	}

	/**
	 * Every control.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'kdna_range_content', array( 'label' => __( 'Date range', 'kdna-ecommerce-insights' ) ) );
		$presets = KDNA_EcommerceInsights_Settings::range_presets();
		unset( $presets['custom'] );
		$this->add_control(
			'kdna_presets',
			array(
				'label'       => __( 'Ranges to offer', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => $presets,
				'default'     => array_keys( $presets ),
				'label_block' => true,
			)
		);
		$this->add_control(
			'kdna_custom',
			array(
				'label'        => __( 'Custom dates with a calendar', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'kdna_compare',
			array(
				'label'        => __( 'Comparison choice', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'kdna_start',
			array(
				'label'       => __( 'Starts on', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'saved',
				'options'     => array_merge( array( 'saved' => __( 'Each Administrator\'s saved range', 'kdna-ecommerce-insights' ) ), $presets ),
				'description' => __( 'Changing the range on the page saves it for that Administrator, so Insights in wp-admin opens on it too.', 'kdna-ecommerce-insights' ),
			)
		);
		$this->add_responsive_control(
			'kdna_align',
			array(
				'label'     => __( 'Alignment', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'kdna-ecommerce-insights' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center'     => array(
						'title' => __( 'Centre', 'kdna-ecommerce-insights' ),
						'icon'  => 'eicon-h-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'kdna-ecommerce-insights' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'selectors' => array( Controls::ROOT => 'justify-content: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'kdna_menu_side',
			array(
				'label'   => __( 'Menu opens towards', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'end',
				'options' => array(
					'start' => __( 'The right (lines up with the left edge)', 'kdna-ecommerce-insights' ),
					'end'   => __( 'The left (lines up with the right edge)', 'kdna-ecommerce-insights' ),
				),
			)
		);
		Controls::theme( $this );
		$this->end_controls_section();

		Controls::access( $this );
		$this->update_control( 'kdna_restricted_mode', array( 'default' => 'nothing' ) );
		Controls::preview( $this );

		Controls::colours( $this );
		Controls::dropdown( $this );
		Controls::buttons( $this );
		Controls::states( $this );
	}

	/**
	 * Prints the dropdown.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 */
	protected function render_widget( array $settings, string $state ): void {
		$presets = array_values( array_intersect( array_keys( KDNA_EcommerceInsights_Settings::range_presets() ), (array) ( $settings['kdna_presets'] ?? array() ) ) );
		if ( ! $presets ) {
			$presets = array( 'this_month' );
		}
		$this->open_root(
			$settings,
			'loading' === $state || 'empty' === $state || 'error' === $state ? 'live' : $state,
			array(
				'start' => (string) ( $settings['kdna_start'] ?? 'saved' ),
			),
			'kdna-ei-w--menu-' . ( 'start' === ( $settings['kdna_menu_side'] ?? 'end' ) ? 'start' : 'end' )
		);
		$this->render_range_picker( $presets, 'yes' === ( $settings['kdna_compare'] ?? 'yes' ), 'yes' === ( $settings['kdna_custom'] ?? 'yes' ) );
		$this->render_theme_toggle( $settings );
		echo '</div>';
	}
}
