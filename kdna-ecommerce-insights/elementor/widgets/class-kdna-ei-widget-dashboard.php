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
		$hero = (string) ( $settings['kdna_hero'] ?? 'settings' );
		if ( 'settings' === $hero ) {
			$hero = (string) KDNA_EcommerceInsights_Settings::get( 'hero.type', 'top_products' );
		}
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
		<div class="kdna-ei-dash" data-layout="<?php echo esc_attr( (string) ( $settings['kdna_layout'] ?? 'reference' ) ); ?>">
			<?php if ( $show( 'kdna_show_alerts' ) ) : ?>
				<div class="kdna-ei-area-alerts kdna-ei-alerts" data-kdna-ei-part="alerts" role="region" aria-label="<?php esc_attr_e( 'Things that need attention', 'kdna-ecommerce-insights' ); ?>"></div>
			<?php endif; ?>

			<?php if ( $show( 'kdna_show_kpis' ) ) : ?>
				<section class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" data-kdna-ei-part="kpis" aria-label="<?php esc_attr_e( 'Key figures', 'kdna-ecommerce-insights' ); ?>">
					<?php for ( $i = 0; $i < 5; $i++ ) : ?>
						<div class="kdna-ei-kpi kdna-ei-kpi--skeleton" aria-hidden="true">
							<span class="kdna-ei-skeleton kdna-ei-skeleton--icon"></span>
							<div class="kdna-ei-kpi__body kdna-ei-skel-stack">
								<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
								<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
							</div>
						</div>
					<?php endfor; ?>
				</section>
			<?php endif; ?>

			<?php if ( $show( 'kdna_show_chart' ) ) : ?>
				<section class="kdna-ei-card kdna-ei-area-chart kdna-ei-performance" aria-label="<?php echo esc_attr( $t['performance'] ); ?>">
					<div class="kdna-ei-card__header">
						<h3 class="kdna-ei-card__title"><?php echo esc_html( $t['performance'] ); ?></h3>
						<div class="kdna-ei-performance__controls">
							<div class="kdna-ei-chart-legend" aria-hidden="true">
								<span class="kdna-ei-chart-legend__item"><span class="kdna-ei-chart-legend__dot"></span><span data-kdna-ei-legend="current"></span></span>
								<span class="kdna-ei-chart-legend__item" data-kdna-ei-legend-compare hidden><span class="kdna-ei-chart-legend__dot kdna-ei-chart-legend__dot--compare"></span><span data-kdna-ei-legend="compare"></span></span>
							</div>
							<div class="kdna-ei-segmented" role="group" aria-label="<?php echo esc_attr( $t['showSeries'] ); ?>">
								<?php foreach ( array( 'net_revenue' => $t['revenue'], 'net_profit' => $t['profit'], 'orders' => $t['orders'] ) as $key => $label ) : ?>
									<button type="button" class="kdna-ei-segmented__item" aria-pressed="false" data-kdna-ei-series="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<div class="kdna-ei-chart">
						<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-chart__skeleton" aria-hidden="true"></span>
						<canvas data-kdna-ei-part="chart" role="img" aria-label="<?php echo esc_attr( $t['performance'] ); ?>"></canvas>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $show( 'kdna_show_inventory' ) ) : ?>
				<section class="kdna-ei-card kdna-ei-area-inventory kdna-ei-inventory-card" aria-label="<?php echo esc_attr( $t['inventory'] ); ?>">
					<div class="kdna-ei-card__header">
						<h3 class="kdna-ei-card__title"><?php echo esc_html( $t['inventory'] ); ?></h3>
						<?php if ( $links ) : ?>
							<a class="kdna-ei-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/inventory' ) ); ?>"><?php echo esc_html( $t['viewInventory'] ); ?></a>
						<?php endif; ?>
					</div>
					<div class="kdna-ei-donut-row">
						<div class="kdna-ei-donut">
							<span class="kdna-ei-skeleton kdna-ei-skeleton--circle kdna-ei-donut__skeleton" aria-hidden="true"></span>
							<canvas data-kdna-ei-part="donut" role="img" aria-label="<?php echo esc_attr( $t['inventory'] ); ?>"></canvas>
							<div class="kdna-ei-donut__centre" aria-hidden="true">
								<span class="kdna-ei-donut__number kdna-ei-num" data-kdna-ei-part="donut-number"></span>
								<span class="kdna-ei-donut__label"><?php echo esc_html( $t['inStock'] ); ?></span>
							</div>
						</div>
						<table class="kdna-ei-table kdna-ei-legend">
							<caption class="kdna-ei-visually-hidden"><?php echo esc_html( $t['inventory'] ); ?></caption>
							<tbody>
								<?php
								foreach ( array(
									'in_stock'     => array( $t['inStockLegend'], 'accent' ),
									'low_stock'    => array( $t['lowStock'], 'positive' ),
									'out_of_stock' => array( $t['outOfStock'], 'accent-2' ),
								) as $key => $row ) :
									$segment = array_search( $key, array( 'in_stock', 'low_stock', 'out_of_stock' ), true ) + 1;
									?>
									<tr data-kdna-ei-stock="<?php echo esc_attr( $key ); ?>">
										<th scope="row"><span class="kdna-ei-legend__dot" style="background: var(--kdna-ei-segment-<?php echo esc_attr( (string) $segment ); ?>, var(--kdna-ei-<?php echo esc_attr( $row[1] ); ?>))"></span><span><?php echo esc_html( $row[0] ); ?></span></th>
										<td class="is-numeric kdna-ei-legend__value"><span class="kdna-ei-skeleton kdna-ei-skeleton--text kdna-ei-legend__skeleton" aria-hidden="true"></span></td>
										<td class="is-numeric kdna-ei-legend__percent"></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $show( 'kdna_show_hero' ) ) : ?>
				<section class="kdna-ei-card kdna-ei-area-hero kdna-ei-hero" aria-label="<?php echo esc_attr( $t['heroTypes'][ $hero ] ?? '' ); ?>">
					<div class="kdna-ei-card__header">
						<h3 class="kdna-ei-card__title"><?php echo esc_html( $t['heroTypes'][ $hero ] ?? '' ); ?></h3>
					</div>
					<?php if ( 'top_products' === $hero ) : ?>
						<div class="kdna-ei-tabs kdna-ei-tabs--small" role="group" aria-label="<?php echo esc_attr( $t['rankBy'] ); ?>">
							<button type="button" class="kdna-ei-tab" aria-selected="false" data-kdna-ei-sort="profit"><?php echo esc_html( $t['byProfit'] ); ?></button>
							<button type="button" class="kdna-ei-tab" aria-selected="false" data-kdna-ei-sort="revenue"><?php echo esc_html( $t['byRevenue'] ); ?></button>
						</div>
					<?php endif; ?>
					<div class="kdna-ei-hero__body" data-kdna-ei-part="hero">
						<div class="kdna-ei-skel-stack kdna-ei-hero__skeleton" aria-hidden="true">
							<?php for ( $i = 0; $i < 5; $i++ ) : ?>
								<div class="kdna-ei-skel-row"><span class="kdna-ei-skeleton" style="width: 44px; height: 44px; flex: none;"></span><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></div>
							<?php endfor; ?>
						</div>
					</div>
				</section>
			<?php endif; ?>
		</div>
		<?php
		$this->render_state_blocks();
		echo '</div>';
	}
}
