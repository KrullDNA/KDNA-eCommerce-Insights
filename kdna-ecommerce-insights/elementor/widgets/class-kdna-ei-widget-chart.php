<?php
/**
 * Elementor widget: Insights Chart.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use KDNA_EcommerceInsights_Widget_Controls as Controls;

/**
 * A line, area or bar chart of any metrics over time (section 9), with the
 * comparison period drawn alongside. Several metrics can be shown one at a
 * time with buttons to switch, like the reference's Performance card, or
 * all together.
 */
class KDNA_EcommerceInsights_Widget_Chart extends KDNA_EcommerceInsights_Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kdna-ei-chart';
	}

	/**
	 * Widget title in the panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Insights Chart', 'kdna-ecommerce-insights' );
	}

	/**
	 * Widget icon in the panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-skill-bar';
	}

	/**
	 * Widget type for the script.
	 *
	 * @return string
	 */
	protected function widget_type(): string {
		return 'chart';
	}

	/**
	 * Every control.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'kdna_chart_section', array( 'label' => __( 'Chart', 'kdna-ecommerce-insights' ) ) );
		$this->register_title_control( __( 'Performance', 'kdna-ecommerce-insights' ) );
		$this->add_control(
			'kdna_chart_metrics',
			array(
				'label'       => __( 'Metrics', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::chart_metric_options(),
				'default'     => array( 'net_revenue', 'net_profit', 'orders' ),
				'label_block' => true,
				'description' => __( 'Up to five. The first one is shown first.', 'kdna-ecommerce-insights' ),
			)
		);
		$this->add_control(
			'kdna_chart_display',
			array(
				'label'   => __( 'With more than one metric', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'switch',
				'options' => array(
					'switch'   => __( 'Show one at a time, with buttons to switch', 'kdna-ecommerce-insights' ),
					'together' => __( 'Show them all together', 'kdna-ecommerce-insights' ),
				),
			)
		);
		$this->add_control(
			'kdna_chart_type',
			array(
				'label'   => __( 'Chart type', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'area',
				'options' => array(
					'area' => __( 'Area (line with a soft fill, like the reference)', 'kdna-ecommerce-insights' ),
					'line' => __( 'Line', 'kdna-ecommerce-insights' ),
					'bar'  => __( 'Bar', 'kdna-ecommerce-insights' ),
				),
			)
		);
		$this->add_control(
			'kdna_chart_compare',
			array(
				'label'        => __( 'Comparison series', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Draws the comparison period alongside. Not shown when several metrics are drawn together.', 'kdna-ecommerce-insights' ),
			)
		);
		$this->add_control(
			'kdna_chart_granularity',
			array(
				'label'   => __( 'Group by', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'auto',
				'options' => array(
					'auto'  => __( 'Automatic (days, weeks or months to suit the range)', 'kdna-ecommerce-insights' ),
					'day'   => __( 'Day', 'kdna-ecommerce-insights' ),
					'week'  => __( 'Week', 'kdna-ecommerce-insights' ),
					'month' => __( 'Month', 'kdna-ecommerce-insights' ),
				),
			)
		);
		$this->add_control(
			'kdna_chart_legend',
			array(
				'label'        => __( 'Legend', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		Controls::theme( $this );
		$this->end_controls_section();

		$this->register_common_sections();
		$this->register_style_controls( array( 'headings', 'charts', 'tooltip', 'buttons' ) );
	}

	/**
	 * The chosen metric keys, in order, without unknown ones.
	 *
	 * @param array $settings Widget settings.
	 * @return string[]
	 */
	private function metrics( array $settings ): array {
		$options = self::chart_metric_options();
		$keys    = array_values( array_filter( (array) ( $settings['kdna_chart_metrics'] ?? array() ), static fn( $key ) => isset( $options[ $key ] ) ) );
		return array_slice( $keys ? $keys : array( 'net_revenue' ), 0, 5 );
	}

	/**
	 * Sample daily figures for the chosen metrics.
	 *
	 * @param array $settings Widget settings.
	 * @param array $data     Settings for the script.
	 * @return array
	 */
	protected function sample_data( array $settings, array $data ): array {
		return array_merge( KDNA_EcommerceInsights_Widget_Sample::basics(), array( 'timeseries' => KDNA_EcommerceInsights_Widget_Sample::timeseries( $data['metrics'] ) ) );
	}

	/**
	 * Prints the chart card with a loading placeholder.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 */
	protected function render_widget( array $settings, string $state ): void {
		$metrics  = $this->metrics( $settings );
		$options  = self::chart_metric_options();
		$together = 'together' === ( $settings['kdna_chart_display'] ?? 'switch' );
		$type     = in_array( $settings['kdna_chart_type'] ?? 'area', array( 'area', 'line', 'bar' ), true ) ? $settings['kdna_chart_type'] : 'area';

		$this->open_module(
			$settings,
			$state,
			array(
				'metrics'     => $metrics,
				'display'     => $together ? 'together' : 'switch',
				'type'        => $type,
				'compare'     => 'yes' === ( $settings['kdna_chart_compare'] ?? 'yes' ),
				'granularity' => (string) ( $settings['kdna_chart_granularity'] ?? 'auto' ),
			)
		);
		echo '<div class="kdna-ei-w__body">';
		$this->render_chart_card(
			(string) ( $settings['kdna_card_title_text'] ?? '' ),
			$together ? array() : array_combine( $metrics, array_map( static fn( $key ) => $options[ $key ], $metrics ) ),
			'kdna-ei-chart-card' . ( $together ? ' is-together' : '' ),
			true,
			'yes' === ( $settings['kdna_chart_legend'] ?? 'yes' )
		);
		echo '</div>';
		$this->close_module();
	}
}
