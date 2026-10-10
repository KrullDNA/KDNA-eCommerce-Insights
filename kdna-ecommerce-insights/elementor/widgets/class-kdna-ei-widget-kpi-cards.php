<?php
/**
 * Elementor widget: Insights KPI Cards.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use KDNA_EcommerceInsights_Widget_Controls as Controls;

/**
 * Any metrics from the library as key figures (section 9): one card
 * across like the reference design, or a separate card for each figure.
 * Each figure shows its icon, value and change against the comparison.
 */
class KDNA_EcommerceInsights_Widget_Kpi_Cards extends KDNA_EcommerceInsights_Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kdna-ei-kpi-cards';
	}

	/**
	 * Widget title in the panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Insights KPI Cards', 'kdna-ecommerce-insights' );
	}

	/**
	 * Widget icon in the panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-counter';
	}

	/**
	 * Widget type for the script.
	 *
	 * @return string
	 */
	protected function widget_type(): string {
		return 'kpi-cards';
	}

	/**
	 * Every control.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'kdna_figures_section', array( 'label' => __( 'Figures', 'kdna-ecommerce-insights' ) ) );
		$this->add_control(
			'kdna_metrics',
			array(
				'label'       => __( 'Metrics', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::kpi_metric_options(),
				'default'     => array( 'net_revenue', 'net_profit', 'orders', 'net_margin', 'average_order_value' ),
				'label_block' => true,
				'description' => __( 'Shown in the order you pick them, up to 12.', 'kdna-ecommerce-insights' ),
			)
		);
		$this->add_control(
			'kdna_kpi_layout',
			array(
				'label'   => __( 'Show as', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'strip',
				'options' => array(
					'strip' => __( 'A strip (one card, like the reference)', 'kdna-ecommerce-insights' ),
					'cards' => __( 'Separate cards', 'kdna-ecommerce-insights' ),
				),
			)
		);
		$this->add_responsive_control(
			'kdna_columns',
			array(
				'label'       => __( 'Figures per row', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 12,
				'description' => __( 'Leave empty to fit them all on one row on wide screens.', 'kdna-ecommerce-insights' ),
				'selectors'   => array( Controls::in( '.kdna-ei-kpi-strip, .kdna-ei-kpi-cards' ) => 'grid-auto-flow: row; grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);
		Controls::hide_switch( $this, 'kdna_hide_change', __( 'Hide the change against the comparison', 'kdna-ecommerce-insights' ), 'kdna-ei-hide-change-' );
		Controls::theme( $this );
		$this->end_controls_section();

		$this->register_common_sections();
		$this->register_style_controls( array( 'kpis' ) );
	}

	/**
	 * The chosen metric keys, in order, without unknown ones.
	 *
	 * @param array $settings Widget settings.
	 * @return string[]
	 */
	private function metrics( array $settings ): array {
		$options = self::kpi_metric_options();
		$keys    = array_values( array_filter( (array) ( $settings['kdna_metrics'] ?? array() ), static fn( $key ) => isset( $options[ $key ] ) ) );
		return array_slice( $keys ? $keys : array( 'net_revenue', 'net_profit', 'orders', 'net_margin' ), 0, 12 );
	}

	/**
	 * Sample figures for the chosen metrics.
	 *
	 * @param array $settings Widget settings.
	 * @param array $data     Settings for the script.
	 * @return array
	 */
	protected function sample_data( array $settings, array $data ): array {
		return array_merge( KDNA_EcommerceInsights_Widget_Sample::basics(), array( 'summary' => KDNA_EcommerceInsights_Widget_Sample::summary( $data['metrics'] ) ) );
	}

	/**
	 * Prints the figures with loading placeholders.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 */
	protected function render_widget( array $settings, string $state ): void {
		$metrics = $this->metrics( $settings );
		$layout  = 'cards' === ( $settings['kdna_kpi_layout'] ?? 'strip' ) ? 'cards' : 'strip';
		$this->open_module( $settings, $state, array( 'metrics' => $metrics ) );
		echo '<div class="kdna-ei-w__body">';
		$this->render_kpis( count( $metrics ), $layout );
		echo '</div>';
		$this->close_module();
	}
}
