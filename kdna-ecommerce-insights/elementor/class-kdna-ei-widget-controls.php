<?php
/**
 * Shared Elementor controls for every Insights widget.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;

/**
 * Builds the content and style controls from section 9.2 of the brief, so
 * every widget offers the same controls in the same order and none of them
 * are written twice.
 *
 * Every control is scoped to the one widget instance ({{WRAPPER}}). Colours,
 * sizes and chart settings write to the --kdna-ei- CSS variables on the
 * widget's own root, which the shared stylesheets and the chart layer read;
 * typography, backgrounds, borders and shadows are applied to the element
 * they style, still inside the widget. Nothing is written at global scope,
 * so two widgets on one page can look completely different.
 *
 * Defaults are left empty on purpose: an empty control means "use the
 * design system", which reproduces the reference design and follows
 * Settings > Branding.
 */
class KDNA_EcommerceInsights_Widget_Controls {

	/**
	 * The widget's root element. Two classes, so it outranks the light and
	 * dark theme rules in the shared tokens stylesheet.
	 */
	const ROOT = '{{WRAPPER}} .kdna-ei-w.kdna-ei-root';

	/**
	 * A selector inside the widget.
	 *
	 * @param string $selector Selector, or several separated by commas.
	 * @return string
	 */
	public static function in( string $selector ): string {
		$parts = array_map( 'trim', explode( ',', $selector ) );
		return implode( ', ', array_map( static fn( $part ) => self::ROOT . ' ' . $part, $parts ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Small helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A colour control.
	 *
	 * @param \Elementor\Widget_Base $w        Widget.
	 * @param string                 $id       Control ID.
	 * @param string                 $label    Label.
	 * @param array                  $selectors Selector => CSS (with {{VALUE}}).
	 */
	public static function colour( $w, string $id, string $label, array $selectors ): void {
		$w->add_control(
			$id,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => $selectors,
			)
		);
	}

	/**
	 * A colour control that sets one CSS variable on the widget root.
	 *
	 * @param \Elementor\Widget_Base $w     Widget.
	 * @param string                 $id    Control ID.
	 * @param string                 $label Label.
	 * @param string                 $var   Variable name, such as --kdna-ei-accent.
	 */
	public static function colour_var( $w, string $id, string $label, string $var ): void {
		self::colour( $w, $id, $label, array( self::ROOT => $var . ': {{VALUE}};' ) );
	}

	/**
	 * A responsive slider.
	 *
	 * @param \Elementor\Widget_Base $w         Widget.
	 * @param string                 $id        Control ID.
	 * @param string                 $label     Label.
	 * @param array                  $selectors Selector => CSS (with {{SIZE}}{{UNIT}}).
	 * @param array                  $units     Size units.
	 * @param array                  $range     Range per unit.
	 * @param bool                   $responsive Whether it changes per device.
	 */
	public static function slider( $w, string $id, string $label, array $selectors, array $units = array( 'px' ), array $range = array(), bool $responsive = true ): void {
		$args = array(
			'label'      => $label,
			'type'       => Controls_Manager::SLIDER,
			'size_units' => $units,
			'range'      => $range ? $range : array(
				'px'  => array( 'min' => 0, 'max' => 100 ),
				'em'  => array( 'min' => 0, 'max' => 10, 'step' => 0.1 ),
				'rem' => array( 'min' => 0, 'max' => 10, 'step' => 0.1 ),
				'%'   => array( 'min' => 0, 'max' => 100 ),
			),
			'selectors'  => $selectors,
		);
		if ( $responsive ) {
			$w->add_responsive_control( $id, $args );
		} else {
			$w->add_control( $id, $args );
		}
	}

	/**
	 * Responsive padding, margin or radius.
	 *
	 * @param \Elementor\Widget_Base $w        Widget.
	 * @param string                 $id       Control ID.
	 * @param string                 $label    Label.
	 * @param string                 $selector Selector.
	 * @param string                 $property padding, margin or border-radius.
	 */
	public static function box( $w, string $id, string $label, string $selector, string $property ): void {
		$w->add_responsive_control(
			$id,
			array(
				'label'      => $label,
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array( $selector => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
	}

	/**
	 * A typography group control.
	 *
	 * @param \Elementor\Widget_Base $w        Widget.
	 * @param string                 $id       Control ID.
	 * @param string                 $label    Label.
	 * @param string                 $selector Selector.
	 */
	public static function type( $w, string $id, string $label, string $selector ): void {
		$w->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $id,
				'label'    => $label,
				'selector' => $selector,
			)
		);
	}

	/**
	 * Typography and colour for one piece of text.
	 *
	 * @param \Elementor\Widget_Base $w        Widget.
	 * @param string                 $id       Control ID prefix.
	 * @param string                 $label    What the text is.
	 * @param string                 $selector Selector inside the widget.
	 */
	public static function text( $w, string $id, string $label, string $selector ): void {
		/* translators: %s: element name, such as "Label". */
		self::type( $w, $id . '_typography', sprintf( __( '%s typography', 'kdna-ecommerce-insights' ), $label ), self::in( $selector ) );
		/* translators: %s: element name, such as "Label". */
		self::colour( $w, $id . '_colour', sprintf( __( '%s colour', 'kdna-ecommerce-insights' ), $label ), array( self::in( $selector ) => 'color: {{VALUE}};' ) );
	}

	/**
	 * An on and off switch that adds a class to the widget's own wrapper
	 * (for example kdna-ei-hide-ring-yes) which the widget stylesheet uses.
	 * Used for "off" choices, because Elementor writes no CSS for a switch
	 * that is off.
	 *
	 * @param \Elementor\Widget_Base $w      Widget.
	 * @param string                 $id     Control ID.
	 * @param string                 $label  Label.
	 * @param string                 $prefix Class prefix.
	 */
	public static function hide_switch( $w, string $id, string $label, string $prefix ): void {
		$w->add_control(
			$id,
			array(
				'label'        => $label,
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'prefix_class' => $prefix,
			)
		);
	}

	/**
	 * A sub-heading inside a section.
	 *
	 * @param \Elementor\Widget_Base $w     Widget.
	 * @param string                 $id    Control ID.
	 * @param string                 $label Heading text.
	 */
	public static function heading( $w, string $id, string $label ): void {
		$w->add_control(
			$id,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
	}

	/**
	 * Starts a Style tab section.
	 *
	 * @param \Elementor\Widget_Base $w     Widget.
	 * @param string                 $id    Section ID.
	 * @param string                 $label Label.
	 * @param array                  $condition Optional condition.
	 */
	public static function style_section( $w, string $id, string $label, array $condition = array() ): void {
		$args = array(
			'label' => $label,
			'tab'   => Controls_Manager::TAB_STYLE,
		);
		if ( $condition ) {
			$args['condition'] = $condition;
		}
		$w->start_controls_section( $id, $args );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Content tab: theme, access and preview
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Theme control: follow the person's admin preference, force dark or
	 * light, or show a toggle on the page.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function theme( $w ): void {
		$w->add_control(
			'kdna_theme',
			array(
				'label'       => __( 'Theme', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'admin',
				'options'     => array(
					'admin'  => __( 'Follow admin preference', 'kdna-ecommerce-insights' ),
					'dark'   => __( 'Always dark', 'kdna-ecommerce-insights' ),
					'light'  => __( 'Always light', 'kdna-ecommerce-insights' ),
					'toggle' => __( 'Show a theme toggle', 'kdna-ecommerce-insights' ),
				),
				'description' => __( 'Follow admin preference uses the light or dark choice each Administrator made in Insights.', 'kdna-ecommerce-insights' ),
			)
		);
	}

	/**
	 * What people who cannot see figures get: a Restricted card (with an
	 * optional login button) or nothing at all.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function access( $w ): void {
		$w->start_controls_section(
			'kdna_access_section',
			array( 'label' => __( 'Visitors without access', 'kdna-ecommerce-insights' ) )
		);
		$w->add_control(
			'kdna_access_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Figures are only ever sent to logged-in Administrators. Everyone else sees what you choose here, and nothing about the store is added to the page for them.', 'kdna-ecommerce-insights' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);
		$w->add_control(
			'kdna_restricted_mode',
			array(
				'label'   => __( 'Show', 'kdna-ecommerce-insights' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'message',
				'options' => array(
					'message' => __( 'A Restricted message', 'kdna-ecommerce-insights' ),
					'nothing' => __( 'Nothing', 'kdna-ecommerce-insights' ),
				),
			)
		);
		$w->add_control(
			'kdna_restricted_title',
			array(
				'label'     => __( 'Title', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Store insights', 'kdna-ecommerce-insights' ),
				'condition' => array( 'kdna_restricted_mode' => 'message' ),
			)
		);
		$w->add_control(
			'kdna_restricted_message',
			array(
				'label'     => __( 'Message', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::TEXTAREA,
				'default'   => __( 'Please log in as an administrator to see this.', 'kdna-ecommerce-insights' ),
				'condition' => array( 'kdna_restricted_mode' => 'message' ),
			)
		);
		$w->add_control(
			'kdna_login_button',
			array(
				'label'        => __( 'Login button', 'kdna-ecommerce-insights' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'kdna_restricted_mode' => 'message' ),
			)
		);
		$w->add_control(
			'kdna_login_text',
			array(
				'label'     => __( 'Button text', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Log in', 'kdna-ecommerce-insights' ),
				'condition' => array(
					'kdna_restricted_mode' => 'message',
					'kdna_login_button'    => 'yes',
				),
			)
		);
		$w->add_control(
			'kdna_login_icon',
			array(
				'label'     => __( 'Button icon', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::ICONS,
				'condition' => array(
					'kdna_restricted_mode' => 'message',
					'kdna_login_button'    => 'yes',
				),
			)
		);
		$w->add_control(
			'kdna_login_icon_position',
			array(
				'label'     => __( 'Icon position', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'before',
				'options'   => array(
					'before' => array(
						'title' => __( 'Before', 'kdna-ecommerce-insights' ),
						'icon'  => 'eicon-h-align-left',
					),
					'after'  => array(
						'title' => __( 'After', 'kdna-ecommerce-insights' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'condition' => array(
					'kdna_restricted_mode' => 'message',
					'kdna_login_button'    => 'yes',
				),
			)
		);
		$w->end_controls_section();
	}

	/**
	 * The editor-only Preview state control. It only works inside the
	 * Elementor editor; visitors always get the real thing.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function preview( $w ): void {
		$w->start_controls_section(
			'kdna_preview_section',
			array( 'label' => __( 'Preview state (editor only)', 'kdna-ecommerce-insights' ) )
		);
		$w->add_control(
			'kdna_preview',
			array(
				'label'       => __( 'Show', 'kdna-ecommerce-insights' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'live',
				'options'     => array(
					'live'       => __( 'Live data', 'kdna-ecommerce-insights' ),
					'sample'     => __( 'Sample data', 'kdna-ecommerce-insights' ),
					'loading'    => __( 'Loading', 'kdna-ecommerce-insights' ),
					'empty'      => __( 'Empty', 'kdna-ecommerce-insights' ),
					'restricted' => __( 'Restricted', 'kdna-ecommerce-insights' ),
					'error'      => __( 'Error', 'kdna-ecommerce-insights' ),
				),
				'description' => __( 'Style every state without having to trigger it. Sample data also lets you design the page before the store has orders. This has no effect on the live page.', 'kdna-ecommerce-insights' ),
			)
		);
		$w->end_controls_section();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Style tab sections (section 9.2)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Theme colours: the design tokens every part of the widget is built
	 * from, set for this widget only.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function colours( $w ): void {
		self::style_section( $w, 'kdna_style_colours', __( 'Colours', 'kdna-ecommerce-insights' ) );
		$w->add_control(
			'kdna_colours_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Leave empty to use the brand colours from Insights > Settings > Branding. Colours set here apply to this widget only, in both light and dark mode.', 'kdna-ecommerce-insights' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		foreach ( array(
			'accent'         => array( __( 'Accent', 'kdna-ecommerce-insights' ), '--kdna-ei-accent' ),
			'accent_2'       => array( __( 'Second accent', 'kdna-ecommerce-insights' ), '--kdna-ei-accent-2' ),
			'positive'       => array( __( 'Positive', 'kdna-ecommerce-insights' ), '--kdna-ei-positive' ),
			'warning'        => array( __( 'Warning', 'kdna-ecommerce-insights' ), '--kdna-ei-warning' ),
			'negative'       => array( __( 'Negative', 'kdna-ecommerce-insights' ), '--kdna-ei-negative' ),
			'text'           => array( __( 'Text', 'kdna-ecommerce-insights' ), '--kdna-ei-text' ),
			'muted'          => array( __( 'Muted text', 'kdna-ecommerce-insights' ), '--kdna-ei-text-muted' ),
			'bg'             => array( __( 'Page background', 'kdna-ecommerce-insights' ), '--kdna-ei-bg' ),
			'surface'        => array( __( 'Card background', 'kdna-ecommerce-insights' ), '--kdna-ei-surface' ),
			'surface_raised' => array( __( 'Raised background (hover, menus)', 'kdna-ecommerce-insights' ), '--kdna-ei-surface-raised' ),
			'border'         => array( __( 'Hairlines and borders', 'kdna-ecommerce-insights' ), '--kdna-ei-border' ),
		) as $key => $colour ) {
			self::colour_var( $w, 'kdna_colour_' . $key, $colour[0], $colour[1] );
		}
		$w->end_controls_section();
	}

	/**
	 * Wrapper and cards.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function wrapper_and_cards( $w ): void {
		self::style_section( $w, 'kdna_style_wrapper', __( 'Wrapper and cards', 'kdna-ecommerce-insights' ) );

		self::heading( $w, 'kdna_wrapper_heading', __( 'Wrapper', 'kdna-ecommerce-insights' ) );
		$w->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'kdna_wrapper_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => self::ROOT,
			)
		);
		$w->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'kdna_wrapper_border',
				'selector' => self::ROOT,
			)
		);
		self::box( $w, 'kdna_wrapper_radius', __( 'Border radius', 'kdna-ecommerce-insights' ), self::ROOT, 'border-radius' );
		$w->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'kdna_wrapper_shadow',
				'selector' => self::ROOT,
			)
		);
		self::box( $w, 'kdna_wrapper_padding', __( 'Padding', 'kdna-ecommerce-insights' ), self::ROOT, 'padding' );
		self::box( $w, 'kdna_wrapper_margin', __( 'Margin', 'kdna-ecommerce-insights' ), self::ROOT, 'margin' );
		self::slider( $w, 'kdna_gap', __( 'Gap between cards', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-gap: {{SIZE}}{{UNIT}};' ), array( 'px', 'em', 'rem' ) );

		self::heading( $w, 'kdna_card_heading', __( 'Cards', 'kdna-ecommerce-insights' ) );
		$w->start_controls_tabs( 'kdna_card_tabs' );
		$w->start_controls_tab( 'kdna_card_normal', array( 'label' => __( 'Normal', 'kdna-ecommerce-insights' ) ) );
		$w->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'kdna_card_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => self::in( '.kdna-ei-card' ),
			)
		);
		$w->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'kdna_card_border',
				'selector' => self::in( '.kdna-ei-card' ),
			)
		);
		$w->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'kdna_card_shadow',
				'selector' => self::in( '.kdna-ei-card' ),
			)
		);
		$w->end_controls_tab();
		$w->start_controls_tab( 'kdna_card_hover', array( 'label' => __( 'Hover', 'kdna-ecommerce-insights' ) ) );
		self::colour( $w, 'kdna_card_hover_background', __( 'Background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-card:hover' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_card_hover_border', __( 'Border colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-card:hover' ) => 'border-color: {{VALUE}};' ) );
		$w->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'kdna_card_hover_shadow',
				'selector' => self::in( '.kdna-ei-card:hover' ),
			)
		);
		$w->end_controls_tab();
		$w->end_controls_tabs();
		self::slider( $w, 'kdna_card_radius', __( 'Card radius', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-radius: {{SIZE}}{{UNIT}};' ), array( 'px', 'em', 'rem' ) );
		$w->add_responsive_control(
			'kdna_card_padding',
			array(
				'label'      => __( 'Card padding', 'kdna-ecommerce-insights' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array( self::ROOT => '--kdna-ei-card-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$w->end_controls_section();
	}

	/**
	 * Eyebrow, page title and card titles, each styled separately.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function headings( $w ): void {
		self::style_section( $w, 'kdna_style_headings', __( 'Eyebrow and titles', 'kdna-ecommerce-insights' ) );
		foreach ( array(
			'eyebrow'    => array( __( 'Eyebrow', 'kdna-ecommerce-insights' ), '.kdna-ei-eyebrow' ),
			'title'      => array( __( 'Title', 'kdna-ecommerce-insights' ), '.kdna-ei-title' ),
			'card_title' => array( __( 'Card titles', 'kdna-ecommerce-insights' ), '.kdna-ei-card__title' ),
		) as $key => $part ) {
			self::heading( $w, 'kdna_' . $key . '_heading', $part[0] );
			self::text( $w, 'kdna_' . $key, $part[0], $part[1] );
			self::slider(
				$w,
				'kdna_' . $key . '_spacing',
				__( 'Spacing below', 'kdna-ecommerce-insights' ),
				array( self::in( 'eyebrow' === $key ? '.kdna-ei-eyebrow' : ( 'title' === $key ? '.kdna-ei-title' : '.kdna-ei-card__header' ) ) => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
				array( 'px', 'em', 'rem' )
			);
			$w->add_responsive_control(
				'kdna_' . $key . '_align',
				array(
					'label'     => __( 'Alignment', 'kdna-ecommerce-insights' ),
					'type'      => Controls_Manager::CHOOSE,
					'options'   => array(
						'left'   => array(
							'title' => __( 'Left', 'kdna-ecommerce-insights' ),
							'icon'  => 'eicon-text-align-left',
						),
						'center' => array(
							'title' => __( 'Centre', 'kdna-ecommerce-insights' ),
							'icon'  => 'eicon-text-align-center',
						),
						'right'  => array(
							'title' => __( 'Right', 'kdna-ecommerce-insights' ),
							'icon'  => 'eicon-text-align-right',
						),
					),
					'selectors' => array( self::in( $part[1] ) => 'text-align: {{VALUE}}; flex: 1;' ),
				)
			);
		}
		$w->end_controls_section();
	}

	/**
	 * KPI label, value and change, icons and dividers.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function kpis( $w ): void {
		self::style_section( $w, 'kdna_style_kpis', __( 'KPI figures', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_kpi_label', __( 'Label', 'kdna-ecommerce-insights' ), '.kdna-ei-kpi__label' );
		self::heading( $w, 'kdna_kpi_value_heading', __( 'Value', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_kpi_value', __( 'Value', 'kdna-ecommerce-insights' ), '.kdna-ei-kpi__value' );
		self::heading( $w, 'kdna_kpi_change_heading', __( 'Change', 'kdna-ecommerce-insights' ) );
		self::type( $w, 'kdna_kpi_change_typography', __( 'Change typography', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-change' ) );
		self::colour( $w, 'kdna_kpi_good', __( 'Good change colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-change--good' ) => 'color: {{VALUE}};' ) );
		self::colour( $w, 'kdna_kpi_bad', __( 'Bad change colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-change--bad' ) => 'color: {{VALUE}};' ) );
		self::colour( $w, 'kdna_kpi_neutral', __( 'No change colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-change--neutral' ) => 'color: {{VALUE}};' ) );
		self::slider( $w, 'kdna_kpi_change_icon', __( 'Change icon size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-change .kdna-ei-icon' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ) );
		self::heading( $w, 'kdna_kpi_icon_heading', __( 'Icon', 'kdna-ecommerce-insights' ) );
		self::hide_switch( $w, 'kdna_kpi_hide_icons', __( 'Hide icons', 'kdna-ecommerce-insights' ), 'kdna-ei-hide-kpi-icons-' );
		self::colour( $w, 'kdna_kpi_icon_colour', __( 'Icon colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-kpi__icon' ) => 'color: {{VALUE}};' ) );
		self::slider( $w, 'kdna_kpi_icon_size', __( 'Icon size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-kpi__icon .kdna-ei-icon' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ) );
		self::slider( $w, 'kdna_kpi_icon_box', __( 'Icon box size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-kpi__icon' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 120 ) ) );
		self::colour( $w, 'kdna_kpi_icon_bg', __( 'Icon box background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-kpi__icon' ) => 'background: {{VALUE}};' ) );
		self::slider( $w, 'kdna_kpi_icon_radius', __( 'Icon box radius', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-kpi__icon' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px', '%' ) );
		self::heading( $w, 'kdna_kpi_divider_heading', __( 'Dividers and spacing', 'kdna-ecommerce-insights' ) );
		self::colour( $w, 'kdna_kpi_divider', __( 'Divider colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-kpi' ) => 'border-left-color: {{VALUE}};' ) );
		self::slider( $w, 'kdna_kpi_divider_width', __( 'Divider width', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-kpi + .kdna-ei-kpi' ) => 'border-left-width: {{SIZE}}{{UNIT}}; border-left-style: solid;' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 10 ) ) );
		self::box( $w, 'kdna_kpi_padding', __( 'Figure padding', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-kpi' ), 'padding' );
		self::box( $w, 'kdna_kpi_strip_padding', __( 'Strip padding', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-kpi-strip' ), 'padding' );
		$w->end_controls_section();
	}

	/**
	 * Charts: series colours, lines, fill, points, gridlines, axes, legend
	 * and height. These write CSS variables the chart layer reads when it
	 * draws, so the charts follow the controls live in the editor.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function charts( $w ): void {
		self::style_section( $w, 'kdna_style_charts', __( 'Charts', 'kdna-ecommerce-insights' ) );
		self::colour_var( $w, 'kdna_series_1', __( 'Main line colour', 'kdna-ecommerce-insights' ), '--kdna-ei-series-1' );
		self::colour_var( $w, 'kdna_series_2', __( 'Comparison line colour', 'kdna-ecommerce-insights' ), '--kdna-ei-series-2' );
		self::slider( $w, 'kdna_chart_line', __( 'Line width', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-line-width: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 0.5, 'max' => 8, 'step' => 0.5 ) ), false );
		self::slider( $w, 'kdna_chart_compare_line', __( 'Comparison line width', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-compare-width: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 0.5, 'max' => 8, 'step' => 0.5 ) ), false );
		self::slider( $w, 'kdna_chart_tension', __( 'Curve smoothing', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-tension: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 0.5, 'step' => 0.05 ) ), false );
		self::slider( $w, 'kdna_chart_fill_start', __( 'Area fill opacity at the top', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-fill-start: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 1, 'step' => 0.05 ) ), false );
		self::slider( $w, 'kdna_chart_fill_end', __( 'Area fill opacity at the bottom', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-fill-end: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 1, 'step' => 0.05 ) ), false );
		$w->add_control(
			'kdna_chart_point_style',
			array(
				'label'     => __( 'Points', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					''         => __( 'Default (none)', 'kdna-ecommerce-insights' ),
					'circle'   => __( 'Circles', 'kdna-ecommerce-insights' ),
					'rect'     => __( 'Squares', 'kdna-ecommerce-insights' ),
					'rectRot'  => __( 'Diamonds', 'kdna-ecommerce-insights' ),
					'triangle' => __( 'Triangles', 'kdna-ecommerce-insights' ),
				),
				'selectors' => array( self::ROOT => '--kdna-ei-chart-point-style: {{VALUE}}; --kdna-ei-chart-point-size: var(--kdna-ei-chart-point-radius, 3);' ),
			)
		);
		self::slider( $w, 'kdna_chart_point_size', __( 'Point size', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-point-radius: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 1, 'max' => 10 ) ), false );
		self::hide_switch( $w, 'kdna_chart_hide_ring', __( 'Hide the ring on the latest point', 'kdna-ecommerce-insights' ), 'kdna-ei-hide-ring-' );
		self::slider( $w, 'kdna_chart_ring_size', __( 'Latest point ring size', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-ring-size: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 2, 'max' => 14 ) ), false );
		self::colour_var( $w, 'kdna_chart_grid', __( 'Gridline colour', 'kdna-ecommerce-insights' ), '--kdna-ei-chart-grid' );
		$w->add_control(
			'kdna_chart_grid_style',
			array(
				'label'     => __( 'Gridline style', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					''       => __( 'Default (dashed)', 'kdna-ecommerce-insights' ),
					'solid'  => __( 'Solid', 'kdna-ecommerce-insights' ),
					'dotted' => __( 'Dotted', 'kdna-ecommerce-insights' ),
					'none'   => __( 'None', 'kdna-ecommerce-insights' ),
				),
				'selectors' => array( self::ROOT => '--kdna-ei-chart-grid-style: {{VALUE}};' ),
			)
		);
		self::heading( $w, 'kdna_chart_axis_heading', __( 'Axis labels', 'kdna-ecommerce-insights' ) );
		self::slider( $w, 'kdna_chart_axis_size', __( 'Size', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-chart-axis-size: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 8, 'max' => 20 ) ), false );
		$w->add_control(
			'kdna_chart_axis_weight',
			array(
				'label'     => __( 'Weight', 'kdna-ecommerce-insights' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					''    => __( 'Default', 'kdna-ecommerce-insights' ),
					'300' => '300',
					'400' => '400',
					'500' => '500',
					'600' => '600',
					'700' => '700',
				),
				'selectors' => array( self::ROOT => '--kdna-ei-chart-axis-weight: {{VALUE}};' ),
			)
		);
		self::colour_var( $w, 'kdna_chart_axis_colour', __( 'Colour', 'kdna-ecommerce-insights' ), '--kdna-ei-chart-axis-colour' );
		self::heading( $w, 'kdna_chart_legend_heading', __( 'Legend', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_chart_legend', __( 'Legend', 'kdna-ecommerce-insights' ), '.kdna-ei-chart-legend' );
		self::slider( $w, 'kdna_chart_legend_dot', __( 'Legend dot size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-chart-legend__dot' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ) );
		self::heading( $w, 'kdna_chart_size_heading', __( 'Size', 'kdna-ecommerce-insights' ) );
		self::slider( $w, 'kdna_chart_height', __( 'Chart height', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-chart' ) => 'height: {{SIZE}}{{UNIT}};' ), array( 'px', 'vh' ), array( 'px' => array( 'min' => 120, 'max' => 700 ), 'vh' => array( 'min' => 10, 'max' => 90 ) ) );
		$w->end_controls_section();
	}

	/**
	 * The chart tooltip card.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function tooltip( $w ): void {
		self::style_section( $w, 'kdna_style_tooltip', __( 'Chart tooltip', 'kdna-ecommerce-insights' ) );
		self::colour( $w, 'kdna_tooltip_bg', __( 'Background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-tooltip' ) => 'background: {{VALUE}};' ) );
		$w->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'kdna_tooltip_border',
				'selector' => self::in( '.kdna-ei-tooltip' ),
			)
		);
		self::slider( $w, 'kdna_tooltip_radius', __( 'Radius', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-tooltip' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px' ) );
		$w->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'kdna_tooltip_shadow',
				'selector' => self::in( '.kdna-ei-tooltip' ),
			)
		);
		self::box( $w, 'kdna_tooltip_padding', __( 'Padding', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-tooltip' ), 'padding' );
		self::text( $w, 'kdna_tooltip_title', __( 'Title', 'kdna-ecommerce-insights' ), '.kdna-ei-tooltip__title' );
		self::text( $w, 'kdna_tooltip_label', __( 'Series name', 'kdna-ecommerce-insights' ), '.kdna-ei-tooltip__label' );
		self::text( $w, 'kdna_tooltip_value', __( 'Value', 'kdna-ecommerce-insights' ), '.kdna-ei-tooltip__value' );
		$w->end_controls_section();
	}

	/**
	 * Donut and legend.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function donut( $w ): void {
		self::style_section( $w, 'kdna_style_donut', __( 'Donut and legend', 'kdna-ecommerce-insights' ) );
		foreach ( range( 1, 5 ) as $n ) {
			/* translators: %d: segment number. */
			self::colour_var( $w, 'kdna_segment_' . $n, sprintf( __( 'Segment %d colour', 'kdna-ecommerce-insights' ), $n ), '--kdna-ei-segment-' . $n );
		}
		self::slider( $w, 'kdna_donut_size', __( 'Donut size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-donut' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};', self::in( '.kdna-ei-donut-row' ) => 'grid-template-columns: {{SIZE}}{{UNIT}} minmax(0, 1fr);' ), array( 'px' ), array( 'px' => array( 'min' => 80, 'max' => 400 ) ) );
		self::slider( $w, 'kdna_donut_cutout', __( 'Hole size (larger means a thinner ring)', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-donut-cutout: {{SIZE}}%;' ), array( '%' ), array( '%' => array( 'min' => 20, 'max' => 92 ) ), false );
		self::slider( $w, 'kdna_donut_gap', __( 'Gap between segments', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-donut-spacing: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 12 ) ), false );
		self::text( $w, 'kdna_donut_number', __( 'Centre number', 'kdna-ecommerce-insights' ), '.kdna-ei-donut__number' );
		self::text( $w, 'kdna_donut_label', __( 'Centre label', 'kdna-ecommerce-insights' ), '.kdna-ei-donut__label' );
		self::heading( $w, 'kdna_legend_heading', __( 'Legend', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_legend_row', __( 'Legend rows', 'kdna-ecommerce-insights' ), '.kdna-ei-legend th, .kdna-ei-legend td' );
		self::slider( $w, 'kdna_legend_dot', __( 'Dot size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-legend__dot' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ) );
		self::colour( $w, 'kdna_legend_divider', __( 'Divider colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-legend th, .kdna-ei-legend td' ) => 'border-bottom-color: {{VALUE}};' ) );
		self::slider( $w, 'kdna_legend_padding', __( 'Row padding', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-legend th, .kdna-ei-legend td' ) => 'padding-top: {{SIZE}}{{UNIT}}; padding-bottom: {{SIZE}}{{UNIT}};' ) );
		$w->end_controls_section();
	}

	/**
	 * Tables and ranked lists: header, rows, alternating rows, hover,
	 * borders, padding, sort icons, thumbnails and the empty message.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function tables( $w ): void {
		self::style_section( $w, 'kdna_style_tables', __( 'Tables and lists', 'kdna-ecommerce-insights' ) );
		self::heading( $w, 'kdna_table_head_heading', __( 'Header', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_table_head', __( 'Header', 'kdna-ecommerce-insights' ), '.kdna-ei-table thead th' );
		self::colour( $w, 'kdna_table_head_bg', __( 'Header background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-table thead th' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_table_head_border', __( 'Header border colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-table thead th' ) => 'border-bottom-color: {{VALUE}};' ) );
		self::heading( $w, 'kdna_table_row_heading', __( 'Rows', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_table_row', __( 'Row', 'kdna-ecommerce-insights' ), '.kdna-ei-table tbody td, .kdna-ei-table tbody th, .kdna-ei-rank__name' );
		self::colour( $w, 'kdna_table_row_bg', __( 'Row background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-table tbody td, .kdna-ei-table tbody th' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_table_alt_bg', __( 'Alternate row background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-table tbody tr:nth-child(even) td, .kdna-ei-table tbody tr:nth-child(even) th' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_table_hover_bg', __( 'Hover background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-table tbody tr:hover td, .kdna-ei-table tbody tr:hover th, .kdna-ei-rank__item:hover' ) => 'background: {{VALUE}};' ) );
		self::box( $w, 'kdna_table_cell_padding', __( 'Cell padding', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-table th, .kdna-ei-table td' ), 'padding' );
		self::colour( $w, 'kdna_table_border', __( 'Border colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-table th, .kdna-ei-table td' ) => 'border-bottom-color: {{VALUE}};' ) );
		self::slider( $w, 'kdna_table_border_width', __( 'Border width', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-table th, .kdna-ei-table td' ) => 'border-bottom-width: {{SIZE}}{{UNIT}}; border-bottom-style: solid;' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 6 ) ) );
		self::colour( $w, 'kdna_table_sort', __( 'Sort icon colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-sort-icon' ) => 'color: {{VALUE}};' ) );
		self::heading( $w, 'kdna_table_thumb_heading', __( 'Thumbnails', 'kdna-ecommerce-insights' ) );
		self::slider( $w, 'kdna_table_thumb', __( 'Thumbnail size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-thumb, .kdna-ei-rank__thumb' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ) );
		self::slider( $w, 'kdna_table_thumb_radius', __( 'Thumbnail radius', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-thumb, .kdna-ei-rank__thumb' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px', '%' ) );
		self::heading( $w, 'kdna_table_empty_heading', __( 'Empty message', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_table_empty', __( 'Empty message', 'kdna-ecommerce-insights' ), '.kdna-ei-list-empty' );
		$w->end_controls_section();
	}

	/**
	 * Buttons and tabs: normal, hover, active and disabled.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function buttons( $w ): void {
		$buttons = '.kdna-ei-btn, .kdna-ei-tab, .kdna-ei-segmented__item';
		self::style_section( $w, 'kdna_style_buttons', __( 'Buttons and tabs', 'kdna-ecommerce-insights' ) );
		self::type( $w, 'kdna_button_typography', __( 'Typography', 'kdna-ecommerce-insights' ), self::in( $buttons ) );
		self::box( $w, 'kdna_button_padding', __( 'Padding', 'kdna-ecommerce-insights' ), self::in( $buttons ), 'padding' );
		self::slider( $w, 'kdna_button_radius', __( 'Radius', 'kdna-ecommerce-insights' ), array( self::in( $buttons ) => 'border-radius: {{SIZE}}{{UNIT}};', self::in( '.kdna-ei-segmented' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px', '%' ) );
		$w->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'kdna_button_border',
				'selector' => self::in( '.kdna-ei-btn' ),
			)
		);
		self::slider( $w, 'kdna_button_icon_size', __( 'Icon size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-btn .kdna-ei-icon, .kdna-ei-btn svg, .kdna-ei-btn i' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; font-size: {{SIZE}}{{UNIT}};' ) );
		self::slider( $w, 'kdna_button_icon_gap', __( 'Icon gap', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-btn' ) => 'gap: {{SIZE}}{{UNIT}};' ) );
		self::slider( $w, 'kdna_button_transition', __( 'Transition duration', 'kdna-ecommerce-insights' ), array( self::in( $buttons ) => 'transition-duration: {{SIZE}}ms;' ), array( 'px' ), array( 'px' => array( 'min' => 0, 'max' => 1000, 'step' => 50 ) ), false );

		$states = array(
			'normal'   => array( __( 'Normal', 'kdna-ecommerce-insights' ), '.kdna-ei-btn, .kdna-ei-tab, .kdna-ei-segmented__item' ),
			'hover'    => array( __( 'Hover', 'kdna-ecommerce-insights' ), '.kdna-ei-btn:hover, .kdna-ei-tab:hover, .kdna-ei-segmented__item:hover' ),
			'active'   => array( __( 'Active', 'kdna-ecommerce-insights' ), '.kdna-ei-btn.is-active, .kdna-ei-btn--primary, .kdna-ei-tab[aria-selected="true"], .kdna-ei-segmented__item[aria-pressed="true"]' ),
			'disabled' => array( __( 'Disabled', 'kdna-ecommerce-insights' ), '.kdna-ei-btn:disabled, .kdna-ei-tab:disabled, .kdna-ei-segmented__item:disabled' ),
		);
		$w->start_controls_tabs( 'kdna_button_tabs' );
		foreach ( $states as $key => $state ) {
			$w->start_controls_tab( 'kdna_button_' . $key, array( 'label' => $state[0] ) );
			self::colour( $w, 'kdna_button_' . $key . '_text', __( 'Text colour', 'kdna-ecommerce-insights' ), array( self::in( $state[1] ) => 'color: {{VALUE}};' ) );
			self::colour( $w, 'kdna_button_' . $key . '_bg', __( 'Background', 'kdna-ecommerce-insights' ), array( self::in( $state[1] ) => 'background: {{VALUE}};' ) );
			self::colour( $w, 'kdna_button_' . $key . '_border', __( 'Border colour', 'kdna-ecommerce-insights' ), array( self::in( $state[1] ) => 'border-color: {{VALUE}};' ) );
			$w->add_group_control(
				Group_Control_Box_Shadow::get_type(),
				array(
					'name'     => 'kdna_button_' . $key . '_shadow',
					'selector' => self::in( $state[1] ),
				)
			);
			$w->end_controls_tab();
		}
		$w->end_controls_tabs();
		$w->end_controls_section();
	}

	/**
	 * Date range dropdown and calendar.
	 *
	 * @param \Elementor\Widget_Base $w         Widget.
	 * @param array                  $condition Optional condition.
	 */
	public static function dropdown( $w, array $condition = array() ): void {
		self::style_section( $w, 'kdna_style_dropdown', __( 'Dropdown and date picker', 'kdna-ecommerce-insights' ), $condition );
		self::heading( $w, 'kdna_trigger_heading', __( 'Button', 'kdna-ecommerce-insights' ) );
		self::type( $w, 'kdna_trigger_typography', __( 'Typography', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-range__trigger' ) );
		self::colour( $w, 'kdna_trigger_colour', __( 'Text colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__trigger' ) => 'color: {{VALUE}};' ) );
		self::colour( $w, 'kdna_trigger_bg', __( 'Background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__trigger' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_trigger_hover_bg', __( 'Hover background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__trigger:hover, .kdna-ei-range__trigger[aria-expanded="true"]' ) => 'background: {{VALUE}};' ) );
		self::box( $w, 'kdna_trigger_padding', __( 'Padding', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-range__trigger' ), 'padding' );
		$w->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'kdna_trigger_border',
				'selector' => self::in( '.kdna-ei-range__trigger' ),
			)
		);
		self::slider( $w, 'kdna_trigger_radius', __( 'Radius', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__trigger' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px', '%' ) );
		self::hide_switch( $w, 'kdna_trigger_hide_icon', __( 'Hide the calendar icon', 'kdna-ecommerce-insights' ), 'kdna-ei-hide-range-icon-' );
		self::slider( $w, 'kdna_trigger_icon_size', __( 'Icon size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__trigger .kdna-ei-icon' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ) );
		self::colour( $w, 'kdna_trigger_icon_colour', __( 'Icon colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__trigger .kdna-ei-icon' ) => 'color: {{VALUE}};' ) );

		self::heading( $w, 'kdna_menu_heading', __( 'Menu', 'kdna-ecommerce-insights' ) );
		self::colour( $w, 'kdna_menu_bg', __( 'Background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__menu' ) => 'background: {{VALUE}};' ) );
		$w->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'kdna_menu_border',
				'selector' => self::in( '.kdna-ei-range__menu' ),
			)
		);
		self::slider( $w, 'kdna_menu_radius', __( 'Radius', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__menu' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px' ) );
		$w->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'kdna_menu_shadow',
				'selector' => self::in( '.kdna-ei-range__menu' ),
			)
		);
		self::slider( $w, 'kdna_menu_width', __( 'Menu width', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__menu' ) => 'width: {{SIZE}}{{UNIT}};' ), array( 'px' ), array( 'px' => array( 'min' => 200, 'max' => 720 ) ) );
		self::text( $w, 'kdna_menu_heading_text', __( 'Group heading', 'kdna-ecommerce-insights' ), '.kdna-ei-range__heading' );
		self::text( $w, 'kdna_menu_item', __( 'Item', 'kdna-ecommerce-insights' ), '.kdna-ei-range__item' );
		self::colour( $w, 'kdna_menu_item_hover_bg', __( 'Item hover background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__item:hover' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_menu_item_hover_colour', __( 'Item hover colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__item:hover' ) => 'color: {{VALUE}};' ) );
		self::colour( $w, 'kdna_menu_item_selected_bg', __( 'Selected item background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__item[aria-checked="true"]' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_menu_item_selected_colour', __( 'Selected item colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-range__item[aria-checked="true"], .kdna-ei-range__item[aria-checked="true"] .kdna-ei-icon' ) => 'color: {{VALUE}};' ) );

		self::heading( $w, 'kdna_calendar_heading', __( 'Calendar', 'kdna-ecommerce-insights' ) );
		self::text( $w, 'kdna_calendar_month', __( 'Month name', 'kdna-ecommerce-insights' ), '.kdna-ei-cal__month' );
		self::text( $w, 'kdna_calendar_weekday', __( 'Weekday names', 'kdna-ecommerce-insights' ), '.kdna-ei-cal__weekday' );
		self::text( $w, 'kdna_calendar_day', __( 'Days', 'kdna-ecommerce-insights' ), '.kdna-ei-cal__day' );
		self::colour( $w, 'kdna_calendar_day_hover', __( 'Day hover background', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-cal__day:hover' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_calendar_range_bg', __( 'Range background', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-cal-range: {{VALUE}};' ) );
		self::colour( $w, 'kdna_calendar_range_text', __( 'Range text', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-cal__day.is-in-range' ) => 'color: {{VALUE}};' ) );
		self::colour( $w, 'kdna_calendar_selected_bg', __( 'Start and end background', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-cal-selected: {{VALUE}};' ) );
		self::colour( $w, 'kdna_calendar_selected_text', __( 'Start and end text', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-cal-selected-text: {{VALUE}};' ) );
		self::colour( $w, 'kdna_calendar_today', __( 'Today ring', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-cal-today: {{VALUE}};' ) );
		self::slider( $w, 'kdna_calendar_day_size', __( 'Day size', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-cal-day: {{SIZE}}{{UNIT}};' ), array( 'px' ), array( 'px' => array( 'min' => 24, 'max' => 60 ) ) );
		self::slider( $w, 'kdna_calendar_day_radius', __( 'Day radius', 'kdna-ecommerce-insights' ), array( self::ROOT => '--kdna-ei-cal-radius: {{SIZE}}{{UNIT}};' ), array( 'px', '%' ) );
		$w->end_controls_section();
	}

	/**
	 * Badges and alerts, with colours for each state.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function badges( $w ): void {
		self::style_section( $w, 'kdna_style_badges', __( 'Badges and alerts', 'kdna-ecommerce-insights' ) );
		self::heading( $w, 'kdna_badge_heading', __( 'Badges', 'kdna-ecommerce-insights' ) );
		self::type( $w, 'kdna_badge_typography', __( 'Typography', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-badge' ) );
		self::box( $w, 'kdna_badge_padding', __( 'Padding', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-badge' ), 'padding' );
		self::slider( $w, 'kdna_badge_radius', __( 'Radius', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-badge' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px', '%' ) );
		foreach ( array(
			'positive' => __( 'Positive', 'kdna-ecommerce-insights' ),
			'warning'  => __( 'Warning', 'kdna-ecommerce-insights' ),
			'negative' => __( 'Negative', 'kdna-ecommerce-insights' ),
			'neutral'  => __( 'Neutral', 'kdna-ecommerce-insights' ),
		) as $state => $label ) {
			/* translators: %s: state, such as Positive. */
			self::colour_var( $w, 'kdna_badge_' . $state . '_bg', sprintf( __( '%s badge background', 'kdna-ecommerce-insights' ), $label ), '--kdna-ei-badge-' . $state . '-bg' );
			/* translators: %s: state, such as Positive. */
			self::colour_var( $w, 'kdna_badge_' . $state . '_text', sprintf( __( '%s badge text', 'kdna-ecommerce-insights' ), $label ), '--kdna-ei-badge-' . $state . '-text' );
		}
		self::heading( $w, 'kdna_alert_heading', __( 'Alerts', 'kdna-ecommerce-insights' ) );
		self::type( $w, 'kdna_alert_typography', __( 'Typography', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-alert' ) );
		self::colour( $w, 'kdna_alert_text', __( 'Text colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-alert' ) => 'color: {{VALUE}};' ) );
		self::box( $w, 'kdna_alert_padding', __( 'Padding', 'kdna-ecommerce-insights' ), self::in( '.kdna-ei-alert' ), 'padding' );
		self::slider( $w, 'kdna_alert_radius', __( 'Radius', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-alert' ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px' ) );
		foreach ( array(
			'warning'  => __( 'Warning', 'kdna-ecommerce-insights' ),
			'negative' => __( 'Negative', 'kdna-ecommerce-insights' ),
			'info'     => __( 'Information', 'kdna-ecommerce-insights' ),
		) as $state => $label ) {
			/* translators: %s: state, such as Warning. */
			self::colour( $w, 'kdna_alert_' . $state . '_tone', sprintf( __( '%s alert colour', 'kdna-ecommerce-insights' ), $label ), array( self::in( '.kdna-ei-alert--' . $state ) => '--kdna-ei-alert-tone: {{VALUE}};' ) );
			/* translators: %s: state, such as Warning. */
			self::colour( $w, 'kdna_alert_' . $state . '_bg', sprintf( __( '%s alert background', 'kdna-ecommerce-insights' ), $label ), array( self::in( '.kdna-ei-alert--' . $state ) => 'background: {{VALUE}};' ) );
		}
		$w->end_controls_section();
	}

	/**
	 * Progress ring and bars.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function progress( $w ): void {
		self::style_section( $w, 'kdna_style_progress', __( 'Progress ring and bars', 'kdna-ecommerce-insights' ) );
		self::colour( $w, 'kdna_progress_track', __( 'Track colour', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-ring__track' ) => 'stroke: {{VALUE}};', self::in( '.kdna-ei-bar, .kdna-ei-waterfall__track' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_progress_fill', __( 'Fill colour (on track)', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-ring__fill' ) => 'stroke: {{VALUE}};', self::in( '.kdna-ei-bar__fill' ) => 'background: {{VALUE}};' ) );
		self::colour( $w, 'kdna_progress_behind', __( 'Fill colour (behind)', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-ring__fill.is-behind' ) => 'stroke: {{VALUE}};' ) );
		self::slider( $w, 'kdna_ring_size', __( 'Ring size', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-ring' ) => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ), array( 'px' ), array( 'px' => array( 'min' => 100, 'max' => 400 ) ) );
		self::slider( $w, 'kdna_ring_thickness', __( 'Ring thickness', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-ring circle' ) => 'stroke-width: {{SIZE}};' ), array( 'px' ), array( 'px' => array( 'min' => 2, 'max' => 24 ) ) );
		self::slider( $w, 'kdna_bar_thickness', __( 'Bar thickness', 'kdna-ecommerce-insights' ), array( self::in( '.kdna-ei-bar, .kdna-ei-waterfall__track' ) => 'height: {{SIZE}}{{UNIT}};' ), array( 'px' ), array( 'px' => array( 'min' => 1, 'max' => 24 ) ) );
		self::hide_switch( $w, 'kdna_progress_square', __( 'Square ends', 'kdna-ecommerce-insights' ), 'kdna-ei-square-ends-' );
		self::text( $w, 'kdna_ring_percent', __( 'Centre figure', 'kdna-ecommerce-insights' ), '.kdna-ei-ring__percent' );
		self::text( $w, 'kdna_ring_label', __( 'Centre label', 'kdna-ecommerce-insights' ), '.kdna-ei-ring__label' );
		$w->end_controls_section();
	}

	/**
	 * Loading, empty, restricted and error states.
	 *
	 * @param \Elementor\Widget_Base $w Widget.
	 */
	public static function states( $w ): void {
		self::style_section( $w, 'kdna_style_states', __( 'States', 'kdna-ecommerce-insights' ) );
		$w->add_control(
			'kdna_states_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Use Content > Preview state to show each state while you style it.', 'kdna-ecommerce-insights' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		self::heading( $w, 'kdna_state_loading_heading', __( 'Loading', 'kdna-ecommerce-insights' ) );
		self::colour_var( $w, 'kdna_state_skeleton', __( 'Placeholder colour', 'kdna-ecommerce-insights' ), '--kdna-ei-skeleton' );
		self::colour_var( $w, 'kdna_state_shine', __( 'Shimmer colour', 'kdna-ecommerce-insights' ), '--kdna-ei-skeleton-shine' );
		foreach ( array(
			'empty'      => __( 'Empty', 'kdna-ecommerce-insights' ),
			'restricted' => __( 'Restricted', 'kdna-ecommerce-insights' ),
			'error'      => __( 'Error', 'kdna-ecommerce-insights' ),
		) as $state => $label ) {
			self::heading( $w, 'kdna_state_' . $state . '_heading', $label );
			$box = '.kdna-ei-state--' . $state;
			self::colour( $w, 'kdna_state_' . $state . '_bg', __( 'Background', 'kdna-ecommerce-insights' ), array( self::in( $box ) => 'background: {{VALUE}};' ) );
			$w->add_group_control(
				Group_Control_Border::get_type(),
				array(
					'name'     => 'kdna_state_' . $state . '_border',
					'selector' => self::in( $box ),
				)
			);
			self::slider( $w, 'kdna_state_' . $state . '_radius', __( 'Radius', 'kdna-ecommerce-insights' ), array( self::in( $box ) => 'border-radius: {{SIZE}}{{UNIT}};' ), array( 'px' ) );
			self::box( $w, 'kdna_state_' . $state . '_padding', __( 'Padding', 'kdna-ecommerce-insights' ), self::in( $box ), 'padding' );
			self::colour( $w, 'kdna_state_' . $state . '_icon', __( 'Icon colour', 'kdna-ecommerce-insights' ), array( self::in( $box . ' .kdna-ei-state__icon' ) => 'color: {{VALUE}};' ) );
			self::text( $w, 'kdna_state_' . $state . '_title', __( 'Title', 'kdna-ecommerce-insights' ), $box . ' .kdna-ei-state__title' );
			self::text( $w, 'kdna_state_' . $state . '_message', __( 'Message', 'kdna-ecommerce-insights' ), $box . ' .kdna-ei-state__message' );
			$w->add_responsive_control(
				'kdna_state_' . $state . '_align',
				array(
					'label'     => __( 'Alignment', 'kdna-ecommerce-insights' ),
					'type'      => Controls_Manager::CHOOSE,
					'options'   => array(
						'start'  => array(
							'title' => __( 'Left', 'kdna-ecommerce-insights' ),
							'icon'  => 'eicon-text-align-left',
						),
						'center' => array(
							'title' => __( 'Centre', 'kdna-ecommerce-insights' ),
							'icon'  => 'eicon-text-align-center',
						),
						'end'    => array(
							'title' => __( 'Right', 'kdna-ecommerce-insights' ),
							'icon'  => 'eicon-text-align-right',
						),
					),
					'selectors' => array( self::in( $box ) => 'justify-items: {{VALUE}}; text-align: {{VALUE}};' ),
				)
			);
		}
		$w->end_controls_section();
	}
}
