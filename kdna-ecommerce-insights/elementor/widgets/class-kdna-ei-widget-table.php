<?php
/**
 * Elementor widget: Insights Table.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Icons_Manager;

/**
 * A report table (section 9): products, customers, campaigns, low stock
 * or the profit and loss statement by month. Columns can be sorted by
 * clicking their headings, and an optional button downloads the same CSV
 * the matching wp-admin screen exports, for the widget's date range.
 */
class KDNA_EcommerceInsights_Widget_Table extends KDNA_EcommerceInsights_Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kdna-ei-table';
	}

	/**
	 * Widget title in the panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Insights Table', 'kdna-ecommerce-insights' );
	}

	/**
	 * Widget icon in the panel.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-table';
	}

	/**
	 * Widget type for the script.
	 *
	 * @return string
	 */
	protected function widget_type(): string {
		return 'table';
	}

	/**
	 * Every table the widget can show: its name, standard title, the CSV
	 * export it matches, the Insights screen it links to, and its columns
	 * (key => label and number format). The profit and loss statement has
	 * one column per month, worked out by the script.
	 *
	 * @return array<string, array>
	 */
	public static function sources(): array {
		$col = static fn( string $label, string $format, bool $sort = true ) => array(
			'label'  => $label,
			'format' => $format,
			'sort'   => $sort,
		);
		return array(
			'products'  => array(
				'label'   => __( 'Products', 'kdna-ecommerce-insights' ),
				'title'   => __( 'Product performance', 'kdna-ecommerce-insights' ),
				'export'  => 'products',
				'screen'  => 'products',
				'sort'    => 'profit',
				'columns' => array(
					'name'        => $col( __( 'Product', 'kdna-ecommerce-insights' ), 'text' ),
					'units'       => $col( __( 'Units', 'kdna-ecommerce-insights' ), 'number' ),
					'orders'      => $col( __( 'Orders', 'kdna-ecommerce-insights' ), 'number' ),
					'revenue'     => $col( __( 'Revenue', 'kdna-ecommerce-insights' ), 'currency' ),
					'cost'        => $col( __( 'Cost', 'kdna-ecommerce-insights' ), 'currency' ),
					'profit'      => $col( __( 'Profit', 'kdna-ecommerce-insights' ), 'currency' ),
					'margin'      => $col( __( 'Margin', 'kdna-ecommerce-insights' ), 'percent' ),
					'refund_rate' => $col( __( 'Refund rate', 'kdna-ecommerce-insights' ), 'percent' ),
				),
				'default' => array( 'name', 'units', 'revenue', 'profit', 'margin' ),
			),
			'customers' => array(
				'label'   => __( 'Top customers', 'kdna-ecommerce-insights' ),
				'title'   => __( 'Top customers', 'kdna-ecommerce-insights' ),
				'export'  => 'customers',
				'screen'  => 'customers',
				'sort'    => 'revenue',
				'columns' => array(
					'name'             => $col( __( 'Customer', 'kdna-ecommerce-insights' ), 'text' ),
					'orders'           => $col( __( 'Orders', 'kdna-ecommerce-insights' ), 'number' ),
					'revenue'          => $col( __( 'Revenue', 'kdna-ecommerce-insights' ), 'currency' ),
					'profit'           => $col( __( 'Profit', 'kdna-ecommerce-insights' ), 'currency' ),
					'lifetime_orders'  => $col( __( 'Lifetime orders', 'kdna-ecommerce-insights' ), 'number' ),
					'lifetime_revenue' => $col( __( 'Lifetime revenue', 'kdna-ecommerce-insights' ), 'currency' ),
					'first_order'      => $col( __( 'First order', 'kdna-ecommerce-insights' ), 'date' ),
				),
				'default' => array( 'name', 'orders', 'revenue', 'profit', 'lifetime_revenue' ),
			),
			'campaigns' => array(
				'label'   => __( 'Ad campaigns', 'kdna-ecommerce-insights' ),
				'title'   => __( 'Campaigns', 'kdna-ecommerce-insights' ),
				'export'  => 'campaigns',
				'screen'  => 'marketing',
				'sort'    => 'spend',
				'columns' => array(
					'campaign_name'    => $col( __( 'Campaign', 'kdna-ecommerce-insights' ), 'text' ),
					'label'            => $col( __( 'Channel', 'kdna-ecommerce-insights' ), 'text' ),
					'spend'            => $col( __( 'Spend', 'kdna-ecommerce-insights' ), 'currency' ),
					'clicks'           => $col( __( 'Clicks', 'kdna-ecommerce-insights' ), 'number' ),
					'conversions'      => $col( __( 'Conversions', 'kdna-ecommerce-insights' ), 'number' ),
					'conversion_value' => $col( __( 'Conversion value', 'kdna-ecommerce-insights' ), 'currency' ),
					'roas'             => $col( __( 'ROAS', 'kdna-ecommerce-insights' ), 'ratio' ),
					'cpa'              => $col( __( 'Cost per sale', 'kdna-ecommerce-insights' ), 'currency' ),
				),
				'default' => array( 'campaign_name', 'label', 'spend', 'conversion_value', 'roas' ),
			),
			'low_stock' => array(
				'label'   => __( 'Low stock', 'kdna-ecommerce-insights' ),
				'title'   => __( 'Low stock', 'kdna-ecommerce-insights' ),
				'export'  => 'low_stock',
				'screen'  => 'inventory',
				'sort'    => 'days',
				'columns' => array(
					'name'     => $col( __( 'Product', 'kdna-ecommerce-insights' ), 'text' ),
					'sku'      => $col( __( 'SKU', 'kdna-ecommerce-insights' ), 'text' ),
					'stock'    => $col( __( 'In stock', 'kdna-ecommerce-insights' ), 'number' ),
					'sold_30'  => $col( __( 'Sold in 30 days', 'kdna-ecommerce-insights' ), 'number' ),
					'days'     => $col( __( 'Days left', 'kdna-ecommerce-insights' ), 'days' ),
					'runs_out' => $col( __( 'Runs out', 'kdna-ecommerce-insights' ), 'date' ),
					'reorder'  => $col( __( 'Reorder by', 'kdna-ecommerce-insights' ), 'date' ),
				),
				'default' => array( 'name', 'stock', 'sold_30', 'days', 'reorder' ),
			),
			'pnl'       => array(
				'label'   => __( 'Profit and loss by month', 'kdna-ecommerce-insights' ),
				'title'   => __( 'Profit and loss', 'kdna-ecommerce-insights' ),
				'export'  => 'pnl',
				'screen'  => 'profit',
				'sort'    => '',
				'columns' => array(),
				'default' => array(),
			),
		);
	}

	/**
	 * Every control.
	 */
	protected function register_controls(): void {
		$sources = self::sources();

		$this->start_controls_section( 'kdna_table_section', array( 'label' => __( 'Table', 'kdna-ecommerce-insights' ) ) );
		$this->add_control(
			'kdna_table_source',
			array(
				'label'   => __( 'Show', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'products',
				'options' => wp_list_pluck( $sources, 'label' ),
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
		foreach ( $sources as $key => $source ) {
			if ( ! $source['columns'] ) {
				continue;
			}
			$this->add_control(
				'kdna_columns_' . $key,
				array(
					'label'       => __( 'Columns', 'kdna-ecommerce-insights' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => wp_list_pluck( $source['columns'], 'label' ),
					'default'     => $source['default'],
					'label_block' => true,
					'condition'   => array( 'kdna_table_source' => $key ),
				)
			);
			$sortable = array_filter( $source['columns'], static fn( $column ) => $column['sort'] );
			$this->add_control(
				'kdna_sort_' . $key,
				array(
					'label'     => __( 'Sorted by', 'kdna-ecommerce-insights' ),
					'type'      => Controls_Manager::SELECT,
					'default'   => $source['sort'],
					'options'   => wp_list_pluck( $sortable, 'label' ),
					'condition' => array( 'kdna_table_source' => $key ),
				)
			);
		}
		$this->add_control(
			'kdna_order',
			array(
				'label'     => __( 'Order', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'desc',
				'options'   => array(
					'desc' => __( 'Highest first', 'kdna-ecommerce-insights' ),
					'asc'  => __( 'Lowest first', 'kdna-ecommerce-insights' ),
				),
				'condition' => array( 'kdna_table_source!' => 'pnl' ),
			)
		);
		$this->add_control(
			'kdna_rows',
			array(
				'label'     => __( 'Rows', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 50,
				'default'   => 10,
				'condition' => array( 'kdna_table_source!' => 'pnl' ),
			)
		);
		$this->add_control(
			'kdna_sortable',
			array(
				'label'        => __( 'Sort by clicking a column heading', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'kdna_table_source!' => 'pnl' ),
			)
		);
		$this->add_control(
			'kdna_thumbs',
			array(
				'label'        => __( 'Product images', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'kdna_table_source' => array( 'products', 'low_stock' ) ),
			)
		);
		$this->register_links_control();
		KDNA_EcommerceInsights_Widget_Controls::theme( $this );
		$this->end_controls_section();

		$this->start_controls_section( 'kdna_export_section', array( 'label' => __( 'Export button', 'kdna-ecommerce-insights' ) ) );
		$this->add_control(
			'kdna_export',
			array(
				'label'        => __( 'CSV export button', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'Downloads the same CSV as the matching Insights screen, for this widget\'s dates. Only Administrators ever see it.', 'kdna-ecommerce-insights' ),
			)
		);
		$this->add_control(
			'kdna_export_text',
			array(
				'label'     => __( 'Button text', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Export CSV', 'kdna-ecommerce-insights' ),
				'condition' => array( 'kdna_export' => 'yes' ),
			)
		);
		$this->add_control(
			'kdna_export_icon',
			array(
				'label'     => __( 'Icon', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => array(
					'value'   => 'fas fa-download',
					'library' => 'fa-solid',
				),
				'condition' => array( 'kdna_export' => 'yes' ),
			)
		);
		$this->add_control(
			'kdna_export_icon_position',
			array(
				'label'     => __( 'Icon position', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'before',
				'options'   => array(
					'before' => __( 'Before the text', 'kdna-ecommerce-insights' ),
					'after'  => __( 'After the text', 'kdna-ecommerce-insights' ),
				),
				'condition' => array( 'kdna_export' => 'yes' ),
			)
		);
		$this->end_controls_section();

		$this->register_common_sections();
		$this->register_style_controls( array( 'headings', 'tables', 'buttons', 'badges' ) );
	}

	/**
	 * The chosen source, or products.
	 *
	 * @param array $settings Widget settings.
	 * @return string
	 */
	private function source( array $settings ): string {
		$source = (string) ( $settings['kdna_table_source'] ?? 'products' );
		return isset( self::sources()[ $source ] ) ? $source : 'products';
	}

	/**
	 * Sample rows for the chosen table.
	 *
	 * @param array $settings Widget settings.
	 * @param array $data     Settings for the script.
	 * @return array
	 */
	protected function sample_data( array $settings, array $data ): array {
		$sample = KDNA_EcommerceInsights_Widget_Sample::basics();
		switch ( $data['source'] ) {
			case 'customers':
				$sample['customers'] = KDNA_EcommerceInsights_Widget_Sample::customers();
				break;
			case 'campaigns':
				$sample['marketing'] = KDNA_EcommerceInsights_Widget_Sample::marketing();
				break;
			case 'low_stock':
				$sample['inventory'] = KDNA_EcommerceInsights_Widget_Sample::inventory();
				break;
			case 'pnl':
				$sample['profit'] = KDNA_EcommerceInsights_Widget_Sample::profit();
				break;
			default:
				$sample['products'] = array( 'rows' => KDNA_EcommerceInsights_Widget_Sample::products( 10 ) );
		}
		return $sample;
	}

	/**
	 * Prints the table card with loading placeholders.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $state    State.
	 */
	protected function render_widget( array $settings, string $state ): void {
		$key     = $this->source( $settings );
		$source  = self::sources()[ $key ];
		$title   = trim( (string) ( $settings['kdna_card_title_text'] ?? '' ) );
		$chosen  = array_values( array_intersect( (array) ( $settings[ 'kdna_columns_' . $key ] ?? $source['default'] ), array_keys( $source['columns'] ) ) );
		$chosen  = $chosen ? $chosen : $source['default'];
		$columns = array();
		foreach ( $chosen as $column ) {
			$columns[] = array_merge( array( 'key' => $column ), $source['columns'][ $column ] );
		}
		$sort    = (string) ( $settings[ 'kdna_sort_' . $key ] ?? $source['sort'] );
		$sort    = isset( $source['columns'][ $sort ] ) ? $sort : $source['sort'];
		$rows    = max( 1, min( 50, (int) ( $settings['kdna_rows'] ?? 10 ) ) );
		$export  = 'yes' === ( $settings['kdna_export'] ?? '' ) && ( KDNA_EcommerceInsights_Elementor::can_view() || KDNA_EcommerceInsights_Elementor::is_editor() );
		$links   = 'yes' === ( $settings['kdna_show_links'] ?? 'yes' ) && KDNA_EcommerceInsights_Elementor::can_view();

		$this->open_module(
			$settings,
			$state,
			array(
				'source'   => $key,
				'columns'  => $columns,
				'sort'     => $sort,
				'order'    => 'asc' === ( $settings['kdna_order'] ?? 'desc' ) ? 'asc' : 'desc',
				'rows'     => $rows,
				'sortable' => 'yes' === ( $settings['kdna_sortable'] ?? 'yes' ),
				'thumbs'   => 'yes' === ( $settings['kdna_thumbs'] ?? 'yes' ),
				'links'    => $links,
				'export'   => $source['export'],
			)
		);
		?>
		<div class="kdna-ei-w__body">
			<section class="kdna-ei-card kdna-ei-table-card" aria-label="<?php echo esc_attr( '' !== $title ? $title : $source['title'] ); ?>">
				<?php
				$this->render_card_header(
					'' !== $title ? $title : $source['title'],
					$export || $links ? function () use ( $export, $links, $settings, $source ) {
						if ( $links ) {
							echo '<a class="kdna-ei-link" href="' . esc_url( admin_url( 'admin.php?page=' . KDNA_EcommerceInsights_Admin::MENU_SLUG . '#/' . $source['screen'] ) ) . '">' . esc_html__( 'View all', 'kdna-ecommerce-insights' ) . '</a>';
						}
						if ( $export ) {
							$this->render_export_button( $settings );
						}
					} : null
				);
				?>
				<div class="kdna-ei-table-wrap" tabindex="0" data-kdna-ei-part="table">
					<table class="kdna-ei-table kdna-ei-data-table">
						<caption class="kdna-ei-visually-hidden"><?php echo esc_html( '' !== $title ? $title : $source['title'] ); ?></caption>
						<thead>
							<tr>
								<?php foreach ( $columns ? $columns : array( array( 'label' => '' ), array( 'label' => '' ), array( 'label' => '' ) ) as $i => $column ) : ?>
									<th scope="col"<?php echo $i > 0 ? ' class="is-numeric"' : ''; ?>><?php echo esc_html( $column['label'] ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php for ( $r = 0; $r < min( $rows, 6 ); $r++ ) : ?>
								<tr class="kdna-ei-table__skeleton" aria-hidden="true">
									<?php foreach ( $columns ? $columns : array( 1, 2, 3 ) as $column ) : ?>
										<td><span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span></td>
									<?php endforeach; ?>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
		$this->close_module();
	}

	/**
	 * The CSV export button, with the chosen icon before or after the text.
	 *
	 * @param array $settings Widget settings.
	 */
	private function render_export_button( array $settings ): void {
		$after = 'after' === ( $settings['kdna_export_icon_position'] ?? 'before' );
		?>
		<button type="button" class="kdna-ei-btn kdna-ei-btn--small kdna-ei-export-btn<?php echo $after ? ' is-icon-after' : ''; ?>" data-kdna-ei-action="export">
			<?php if ( ! empty( $settings['kdna_export_icon']['value'] ) ) : ?>
				<span class="kdna-ei-btn__icon" aria-hidden="true"><?php Icons_Manager::render_icon( $settings['kdna_export_icon'], array( 'aria-hidden' => 'true' ) ); ?></span>
			<?php endif; ?>
			<span><?php echo esc_html( (string) ( $settings['kdna_export_text'] ?? __( 'Export CSV', 'kdna-ecommerce-insights' ) ) ); ?></span>
		</button>
		<?php
	}
}
