<?php
/**
 * Elementor widget: Insights Hero Card.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;

/**
 * The Overview's hero card on its own (section 9): Top products, Profit
 * breakdown or Goals tracker. Drawn by the same code as the Dashboard's
 * hero card.
 */
class KDNA_EcommerceInsights_Widget_Hero_Card extends KDNA_EcommerceInsights_Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kdna-ei-hero-card';
	}

	/**
	 * Widget title in the panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Insights Hero Card', 'kdna-ecommerce-insights' );
	}

	/**
	 * Widget icon in the panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-call-to-action';
	}

	/**
	 * Widget type for the script.
	 *
	 * @return string
	 */
	protected function widget_type(): string {
		return 'hero-card';
	}

	/**
	 * Every control.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'kdna_hero_section', array( 'label' => __( 'Hero card', 'kdna-ecommerce-insights' ) ) );
		$this->add_control(
			'kdna_hero',
			array(
				'label'   => __( 'Show', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'settings',
				'options' => array(
					'settings'         => __( 'Follow Insights settings', 'kdna-ecommerce-insights' ),
					'top_products'     => __( 'Top products', 'kdna-ecommerce-insights' ),
					'profit_breakdown' => __( 'Profit breakdown', 'kdna-ecommerce-insights' ),
					'goals'            => __( 'Goals tracker', 'kdna-ecommerce-insights' ),
				),
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
			'kdna_hero_sort',
			array(
				'label'     => __( 'Top products ranked by', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'profit',
				'options'   => array(
					'profit'  => __( 'Profit', 'kdna-ecommerce-insights' ),
					'revenue' => __( 'Revenue', 'kdna-ecommerce-insights' ),
				),
				'condition' => array( 'kdna_hero!' => array( 'profit_breakdown', 'goals' ) ),
			)
		);
		$this->add_control(
			'kdna_hero_tabs',
			array(
				'label'        => __( 'By profit and By revenue tabs', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'kdna_hero!' => array( 'profit_breakdown', 'goals' ) ),
			)
		);
		$this->add_control(
			'kdna_hero_count',
			array(
				'label'     => __( 'Products', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 10,
				'default'   => 5,
				'condition' => array( 'kdna_hero!' => array( 'profit_breakdown', 'goals' ) ),
			)
		);
		$this->add_control(
			'kdna_goal_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The goals tracker always shows this month, against the targets in Insights > Settings > Overview.', 'kdna-ecommerce-insights' ),
				'content_classes' => 'elementor-descriptor',
				'condition'       => array( 'kdna_hero' => 'goals' ),
			)
		);
		$this->register_links_control();
		KDNA_EcommerceInsights_Widget_Controls::theme( $this );
		$this->end_controls_section();

		$this->register_common_sections();
		$this->register_style_controls( array( 'headings', 'tables', 'buttons', 'badges', 'progress' ) );
	}

	/**
	 * Sample figures for the hero card.
	 *
	 * @param array $settings Widget settings.
	 * @param array $data     Settings for the script.
	 * @return array
	 */
	protected function sample_data( array $settings, array $data ): array {
		return array_merge(
			KDNA_EcommerceInsights_Widget_Sample::basics(),
			array(
				'products'  => KDNA_EcommerceInsights_Widget_Sample::products( $data['count'] ),
				'waterfall' => KDNA_EcommerceInsights_Widget_Sample::profit()['waterfall'],
				'goal'      => KDNA_EcommerceInsights_Widget_Sample::goal(),
			)
		);
	}

	/**
	 * Prints the hero card with loading placeholders.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 */
	protected function render_widget( array $settings, string $state ): void {
		$hero  = $this->hero_type( (string) ( $settings['kdna_hero'] ?? 'settings' ) );
		$count = max( 1, min( 10, (int) ( $settings['kdna_hero_count'] ?? 5 ) ) );
		$this->open_module(
			$settings,
			$state,
			array(
				'hero'  => $hero,
				'sort'  => 'revenue' === ( $settings['kdna_hero_sort'] ?? 'profit' ) ? 'revenue' : 'profit',
				'count' => $count,
				'links' => 'yes' === ( $settings['kdna_show_links'] ?? 'yes' ) && KDNA_EcommerceInsights_Elementor::can_view(),
			)
		);
		echo '<div class="kdna-ei-w__body">';
		$this->render_hero_card( $hero, trim( (string) ( $settings['kdna_card_title_text'] ?? '' ) ), 'yes' === ( $settings['kdna_hero_tabs'] ?? 'yes' ), 'top_products' === $hero ? $count : 5 );
		echo '</div>';
		$this->close_module();
	}
}
