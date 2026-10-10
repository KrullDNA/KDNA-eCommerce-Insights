<?php
/**
 * Elementor widget: Insights Dashboard.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use KDNA_EcommerceInsights_Widget_Controls as Controls;

/**
 * The complete Overview in one widget (section 9): alerts, the KPI strip,
 * the performance chart, the inventory donut and the hero card, each of
 * which can be switched off, in the reference, stacked or two column
 * layout. The quickest way to build a dashboard page.
 *
 * The figures are loaded in the browser by assets/js/kdna-ei-widgets.js,
 * only for logged-in Administrators.
 */
class KDNA_EcommerceInsights_Widget_Dashboard extends KDNA_EcommerceInsights_Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kdna-ei-dashboard';
	}

	/**
	 * Widget title in the panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Insights Dashboard', 'kdna-ecommerce-insights' );
	}

	/**
	 * Widget icon in the panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-dashboard';
	}

	/**
	 * Widget type for the script.
	 *
	 * @return string
	 */
	protected function widget_type(): string {
		return 'dashboard';
	}

	/**
	 * Every control: content first, then the style sections from 9.2.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'kdna_panels_section', array( 'label' => __( 'Panels', 'kdna-ecommerce-insights' ) ) );
		$panels = array(
			'kdna_show_header'    => __( 'Eyebrow and title', 'kdna-ecommerce-insights' ),
			'kdna_show_alerts'    => __( 'Alerts', 'kdna-ecommerce-insights' ),
			'kdna_show_kpis'      => __( 'KPI strip', 'kdna-ecommerce-insights' ),
			'kdna_show_chart'     => __( 'Performance chart', 'kdna-ecommerce-insights' ),
			'kdna_show_inventory' => __( 'Inventory donut', 'kdna-ecommerce-insights' ),
			'kdna_show_hero'      => __( 'Hero card', 'kdna-ecommerce-insights' ),
		);
		foreach ( $panels as $id => $label ) {
			$this->add_control(
				$id,
				array(
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'default'      => 'yes',
					'return_value' => 'yes',
				)
			);
		}
		$this->add_control(
			'kdna_eyebrow',
			array(
				'label'       => __( 'Eyebrow', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Store name / Overview', 'kdna-ecommerce-insights' ),
				'description' => __( 'Leave empty to show the store name and "Overview".', 'kdna-ecommerce-insights' ),
				'condition'   => array( 'kdna_show_header' => 'yes' ),
			)
		);
		$this->add_control(
			'kdna_title',
			array(
				'label'     => __( 'Title', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Overview', 'kdna-ecommerce-insights' ),
				'condition' => array( 'kdna_show_header' => 'yes' ),
			)
		);
		$this->add_control(
			'kdna_show_picker',
			array(
				'label'        => __( 'Date range picker in the header', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'Or add a separate Insights Date Range widget anywhere on the page.', 'kdna-ecommerce-insights' ),
				'condition'    => array( 'kdna_show_header' => 'yes' ),
			)
		);
		$this->add_control(
			'kdna_show_links',
			array(
				'label'        => __( 'Links into Insights', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Shows "View all" and alert links that open the matching Insights screen.', 'kdna-ecommerce-insights' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'kdna_layout_section', array( 'label' => __( 'Layout', 'kdna-ecommerce-insights' ) ) );
		$this->add_control(
			'kdna_layout',
			array(
				'label'   => __( 'Layout', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'reference',
				'options' => array(
					'reference'  => __( 'Reference (chart and stock on the left, hero card on the right)', 'kdna-ecommerce-insights' ),
					'stacked'    => __( 'Stacked (one panel under another)', 'kdna-ecommerce-insights' ),
					'two_column' => __( 'Two column (panels in pairs)', 'kdna-ecommerce-insights' ),
				),
			)
		);
		$this->add_control(
			'kdna_hero',
			array(
				'label'   => __( 'Hero card', 'kdna-ecommerce-insights' ),
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
			'kdna_hero_sort',
			array(
				'label'   => __( 'Top products ranked by', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'profit',
				'options' => array(
					'profit'  => __( 'Profit', 'kdna-ecommerce-insights' ),
					'revenue' => __( 'Revenue', 'kdna-ecommerce-insights' ),
				),
			)
		);
		$options = array();
		foreach ( KDNA_EcommerceInsights_Admin::kpi_options() as $option ) {
			$options[ $option['key'] ] = $option['label'];
		}
		$this->add_control(
			'kdna_fifth',
			array(
				'label'   => __( 'Fifth KPI', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'average_order_value',
				'options' => $options,
			)
		);
		$this->add_control(
			'kdna_series',
			array(
				'label'   => __( 'Chart starts on', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'net_revenue',
				'options' => array(
					'net_revenue' => __( 'Revenue', 'kdna-ecommerce-insights' ),
					'net_profit'  => __( 'Profit', 'kdna-ecommerce-insights' ),
					'orders'      => __( 'Orders', 'kdna-ecommerce-insights' ),
				),
			)
		);
		Controls::theme( $this );
		$this->end_controls_section();

		$this->start_controls_section( 'kdna_range_section', array( 'label' => __( 'Date range', 'kdna-ecommerce-insights' ) ) );
		$this->register_range_controls();
		$this->end_controls_section();

		Controls::access( $this );
		Controls::preview( $this );

		Controls::colours( $this );
		Controls::wrapper_and_cards( $this );
		Controls::headings( $this );
		Controls::kpis( $this );
		Controls::charts( $this );
		Controls::tooltip( $this );
		Controls::donut( $this );
		Controls::tables( $this );
		Controls::buttons( $this );
		Controls::dropdown( $this, array( 'kdna_show_picker' => 'yes' ) );
		Controls::badges( $this );
		Controls::progress( $this );
		Controls::states( $this );
	}

	/**
	 * Prints the dashboard's layout, with loading placeholders that the
	 * script replaces with figures.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 */
	protected function render_widget( array $settings, string $state ): void {
		$t    = KDNA_EcommerceInsights_Admin::overview_strings();
		$show = static fn( $key ) => 'yes' === ( $settings[ $key ] ?? 'yes' );
		$hero = $this->hero_type( (string) ( $settings['kdna_hero'] ?? 'settings' ) );
		$links = $show( 'kdna_show_links' ) && KDNA_EcommerceInsights_Elementor::can_view();

		$this->open_root(
			$settings,
			$state,
			array(
				'range'  => $this->range_data( $settings ),
				'hero'   => $hero,
				'sort'   => (string) ( $settings['kdna_hero_sort'] ?? 'profit' ),
				'fifth'  => (string) ( $settings['kdna_fifth'] ?? 'average_order_value' ),
				'series' => (string) ( $settings['kdna_series'] ?? 'net_revenue' ),
				'links'  => $links,
				'panels' => array(
					'alerts'    => $show( 'kdna_show_alerts' ),
					'kpis'      => $show( 'kdna_show_kpis' ),
					'chart'     => $show( 'kdna_show_chart' ),
					'inventory' => $show( 'kdna_show_inventory' ),
					'hero'      => $show( 'kdna_show_hero' ),
				),
			)
		);

		if ( $show( 'kdna_show_header' ) ) {
			$eyebrow = trim( (string) ( $settings['kdna_eyebrow'] ?? '' ) );
			if ( '' === $eyebrow ) {
				$eyebrow = KDNA_EcommerceInsights_Settings::store_name() . ' / ' . __( 'Overview', 'kdna-ecommerce-insights' );
			}
			?>
			<header class="kdna-ei-w__header">
				<div class="kdna-ei-w__heading">
					<p class="kdna-ei-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
					<h2 class="kdna-ei-title"><?php echo esc_html( (string) ( $settings['kdna_title'] ?? '' ) ); ?></h2>
				</div>
				<div class="kdna-ei-w__actions">
					<?php
					if ( 'yes' === ( $settings['kdna_show_picker'] ?? '' ) ) {
						$presets = array_keys( KDNA_EcommerceInsights_Settings::range_presets() );
						$this->render_range_picker( array_diff( $presets, array( 'custom' ) ), true, true );
					}
					$this->render_theme_toggle( $settings );
					?>
				</div>
			</header>
			<?php
		} else {
			echo '<div class="kdna-ei-w__actions kdna-ei-w__actions--floating">';
			$this->render_theme_toggle( $settings );
			echo '</div>';
		}
		?>
		<div class="kdna-ei-dash kdna-ei-w__body" data-layout="<?php echo esc_attr( (string) ( $settings['kdna_layout'] ?? 'reference' ) ); ?>">
			<?php if ( $show( 'kdna_show_alerts' ) ) : ?>
				<div class="kdna-ei-area-alerts kdna-ei-alerts" data-kdna-ei-part="alerts" role="region" aria-label="<?php esc_attr_e( 'Things that need attention', 'kdna-ecommerce-insights' ); ?>"></div>
			<?php endif; ?>
			<?php
			if ( $show( 'kdna_show_kpis' ) ) {
				$this->render_kpis( 5, 'strip', 'kdna-ei-area-kpis' );
			}
			if ( $show( 'kdna_show_chart' ) ) {
				$this->render_chart_card(
					$t['performance'],
					array(
						'net_revenue' => $t['revenue'],
						'net_profit'  => $t['profit'],
						'orders'      => $t['orders'],
					),
					'kdna-ei-area-chart'
				);
			}
			if ( $show( 'kdna_show_inventory' ) ) {
				$this->render_breakdown_card(
					$t['inventory'],
					$t['inStock'],
					array( $t['inStockLegend'], $t['lowStock'], $t['outOfStock'] ),
					$links ? admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/inventory' ) : '',
					'kdna-ei-area-inventory kdna-ei-inventory-card'
				);
			}
			if ( $show( 'kdna_show_hero' ) ) {
				$this->render_hero_card( $hero, '', true, 5, 'kdna-ei-area-hero' );
			}
			?>
		</div>
		<?php
		$this->render_state_blocks();
		echo '</div>';
	}
}
