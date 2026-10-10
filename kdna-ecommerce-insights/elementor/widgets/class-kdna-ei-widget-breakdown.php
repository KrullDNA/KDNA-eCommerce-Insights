<?php
/**
 * Elementor widget: Insights Breakdown.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;

/**
 * A donut with a legend table (section 9), showing how one total splits:
 * stock status, where the money went (cost breakdown), ad spend by
 * channel, or new and returning customers.
 */
class KDNA_EcommerceInsights_Widget_Breakdown extends KDNA_EcommerceInsights_Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kdna-ei-breakdown';
	}

	/**
	 * Widget title in the panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Insights Breakdown', 'kdna-ecommerce-insights' );
	}

	/**
	 * Widget icon in the panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-circle-o';
	}

	/**
	 * Widget type for the script.
	 *
	 * @return string
	 */
	protected function widget_type(): string {
		return 'breakdown';
	}

	/**
	 * What the breakdown can show, with its standard title, the label in
	 * the middle of the donut, the legend rows known up front (or how many
	 * placeholder rows to show) and the Insights screen it links to.
	 *
	 * @return array<string, array>
	 */
	public static function sources(): array {
		$t = KDNA_EcommerceInsights_Admin::overview_strings();
		return array(
			'inventory' => array(
				'label'  => __( 'Inventory status', 'kdna-ecommerce-insights' ),
				'title'  => $t['inventory'],
				'centre' => $t['inStock'],
				'rows'   => array( $t['inStockLegend'], $t['lowStock'], $t['outOfStock'] ),
				'screen' => 'inventory',
			),
			'costs'     => array(
				'label'  => __( 'Cost breakdown', 'kdna-ecommerce-insights' ),
				'title'  => __( 'Where the money went', 'kdna-ecommerce-insights' ),
				'centre' => __( 'total costs', 'kdna-ecommerce-insights' ),
				'rows'   => 6,
				'screen' => 'profit',
			),
			'channels'  => array(
				'label'  => __( 'Ad spend by channel', 'kdna-ecommerce-insights' ),
				'title'  => __( 'Ad spend by channel', 'kdna-ecommerce-insights' ),
				'centre' => __( 'ad spend', 'kdna-ecommerce-insights' ),
				'rows'   => 3,
				'screen' => 'marketing',
			),
			'customers' => array(
				'label'  => __( 'New and returning customers', 'kdna-ecommerce-insights' ),
				'title'  => __( 'New and returning', 'kdna-ecommerce-insights' ),
				'centre' => __( 'customers', 'kdna-ecommerce-insights' ),
				'rows'   => array( __( 'New customers', 'kdna-ecommerce-insights' ), __( 'Returning customers', 'kdna-ecommerce-insights' ) ),
				'screen' => 'customers',
			),
		);
	}

	/**
	 * Every control.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'kdna_breakdown_section', array( 'label' => __( 'Breakdown', 'kdna-ecommerce-insights' ) ) );
		$this->add_control(
			'kdna_source',
			array(
				'label'   => __( 'Show', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'inventory',
				'options' => wp_list_pluck( self::sources(), 'label' ),
			)
		);
		$this->add_control(
			'kdna_customer_measure',
			array(
				'label'     => __( 'Count', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'customers',
				'options'   => array(
					'customers' => __( 'Customers', 'kdna-ecommerce-insights' ),
					'revenue'   => __( 'Revenue', 'kdna-ecommerce-insights' ),
				),
				'condition' => array( 'kdna_source' => 'customers' ),
			)
		);
		$this->add_control(
			'kdna_card_title_text',
			array(
				'label'       => __( 'Card title', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Leave empty for the standard title', 'kdna-ecommerce-insights' ),
				'label_block' => true,
			)
		);
		$this->add_control(
			'kdna_show_percent',
			array(
				'label'        => __( 'Percentages in the legend', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->register_links_control();
		KDNA_EcommerceInsights_Widget_Controls::theme( $this );
		$this->end_controls_section();

		$this->register_common_sections();
		$this->register_style_controls( array( 'headings', 'donut' ) );
	}

	/**
	 * The chosen source, or inventory.
	 *
	 * @param array $settings Widget settings.
	 * @return string
	 */
	private function source( array $settings ): string {
		$source = (string) ( $settings['kdna_source'] ?? 'inventory' );
		return isset( self::sources()[ $source ] ) ? $source : 'inventory';
	}

	/**
	 * Sample figures for the chosen source.
	 *
	 * @param array $settings Widget settings.
	 * @param array $data     Settings for the script.
	 * @return array
	 */
	protected function sample_data( array $settings, array $data ): array {
		$sample = KDNA_EcommerceInsights_Widget_Sample::basics();
		switch ( $data['source'] ) {
			case 'costs':
				$sample['profit'] = KDNA_EcommerceInsights_Widget_Sample::profit();
				break;
			case 'channels':
				$sample['marketing'] = KDNA_EcommerceInsights_Widget_Sample::marketing();
				break;
			case 'customers':
				$sample['customers'] = KDNA_EcommerceInsights_Widget_Sample::customers();
				break;
			default:
				$sample['inventory'] = KDNA_EcommerceInsights_Widget_Sample::inventory();
		}
		return $sample;
	}

	/**
	 * Prints the donut card with loading placeholders.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 */
	protected function render_widget( array $settings, string $state ): void {
		$source = $this->source( $settings );
		$info   = self::sources()[ $source ];
		$title  = trim( (string) ( $settings['kdna_card_title_text'] ?? '' ) );
		$links  = 'yes' === ( $settings['kdna_show_links'] ?? 'yes' ) && KDNA_EcommerceInsights_Elementor::can_view();
		$centre = $info['centre'];
		if ( 'customers' === $source && 'revenue' === ( $settings['kdna_customer_measure'] ?? 'customers' ) ) {
			$centre = __( 'customer revenue', 'kdna-ecommerce-insights' );
		}

		$this->open_module(
			$settings,
			$state,
			array(
				'source'  => $source,
				'measure' => (string) ( $settings['kdna_customer_measure'] ?? 'customers' ),
			)
		);
		echo '<div class="kdna-ei-w__body">';
		$this->render_breakdown_card(
			'' !== $title ? $title : $info['title'],
			$centre,
			$info['rows'],
			$links ? admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/' . $info['screen'] ) : '',
			'yes' === ( $settings['kdna_show_percent'] ?? 'yes' ) ? '' : 'kdna-ei-no-percent'
		);
		echo '</div>';
		$this->close_module();
	}
}
