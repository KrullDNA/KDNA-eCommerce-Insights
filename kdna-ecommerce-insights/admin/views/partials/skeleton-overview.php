<?php
/**
 * Loading skeleton for the Overview screen, laid out like the reference
 * design: KPI strip, performance chart, inventory donut and hero card.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" aria-hidden="true">
	<?php for ( $i = 0; $i < 4; $i++ ) : ?>
		<div class="kdna-ei-kpi">
			<span class="kdna-ei-skeleton kdna-ei-skeleton--icon"></span>
			<div class="kdna-ei-kpi__body kdna-ei-skel-stack">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
			</div>
		</div>
	<?php endfor; ?>
</div>

<div class="kdna-ei-card kdna-ei-area-chart" aria-hidden="true">
	<div class="kdna-ei-card__header">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span>
		<div class="kdna-ei-skel-row">
			<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
		</div>
	</div>
	<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-skel-chart"></span>
</div>

<div class="kdna-ei-card kdna-ei-area-hero" aria-hidden="true">
	<div class="kdna-ei-card__header">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span>
	</div>
	<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-skel-hero"></span>
	<div class="kdna-ei-skel-row" style="justify-content: space-between;">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span>
		<span class="kdna-ei-skeleton kdna-ei-skeleton--pill"></span>
	</div>
	<div class="kdna-ei-skel-row" style="margin-top: 12px;">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
	</div>
</div>

<div class="kdna-ei-card kdna-ei-area-inventory" aria-hidden="true">
	<div class="kdna-ei-card__header">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span>
	</div>
	<div class="kdna-ei-skel-donut">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--circle"></span>
		<div class="kdna-ei-skel-stack">
			<?php for ( $i = 0; $i < 3; $i++ ) : ?>
				<div class="kdna-ei-skel-legend-row">
					<span class="kdna-ei-skeleton kdna-ei-skeleton--circle" style="width: 12px; height: 12px;"></span>
					<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 40%;"></span>
					<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
					<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
				</div>
			<?php endfor; ?>
		</div>
	</div>
</div>
