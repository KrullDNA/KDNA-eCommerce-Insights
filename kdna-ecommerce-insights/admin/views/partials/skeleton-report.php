<?php
/**
 * Loading skeleton for report screens (Profit & Loss, Customers, Inventory,
 * Marketing, Tax & Reports): KPI strip, chart, breakdown and table.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="kdna-ei-card kdna-ei-kpi-strip kdna-ei-area-kpis" aria-hidden="true">
	<?php for ( $i = 0; $i < 4; $i++ ) : ?>
		<div class="kdna-ei-kpi">
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
	</div>
	<span class="kdna-ei-skeleton kdna-ei-skeleton--block kdna-ei-skel-chart"></span>
</div>

<div class="kdna-ei-card kdna-ei-area-side" aria-hidden="true">
	<div class="kdna-ei-card__header">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span>
	</div>
	<div class="kdna-ei-skel-stack" style="justify-items: center; gap: 24px;">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--circle" style="width: 150px; height: 150px;"></span>
		<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
		<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
	</div>
</div>

<div class="kdna-ei-card kdna-ei-area-table" aria-hidden="true">
	<div class="kdna-ei-card__header">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span>
	</div>
	<?php for ( $i = 0; $i < 5; $i++ ) : ?>
		<div class="kdna-ei-skel-table-row">
			<?php for ( $c = 0; $c < 5; $c++ ) : ?>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
			<?php endfor; ?>
		</div>
	<?php endfor; ?>
</div>
