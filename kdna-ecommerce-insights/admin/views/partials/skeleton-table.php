<?php
/**
 * Loading skeleton for table screens (Products, Costs): three summary cards
 * and a large table with a search and filter toolbar.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;
?>
<?php for ( $i = 0; $i < 3; $i++ ) : ?>
	<div class="kdna-ei-card" aria-hidden="true">
		<div class="kdna-ei-skel-stack">
			<span class="kdna-ei-skeleton kdna-ei-skeleton--label"></span>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--value"></span>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 60%;"></span>
		</div>
	</div>
<?php endfor; ?>

<div class="kdna-ei-card kdna-ei-area-table" aria-hidden="true">
	<div class="kdna-ei-card__header">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--block" style="height: 40px; max-width: 320px; border-radius: 999px;"></span>
		<div class="kdna-ei-skel-row">
			<span class="kdna-ei-skeleton kdna-ei-skeleton--pill" style="width: 110px; height: 40px;"></span>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--pill" style="width: 110px; height: 40px;"></span>
		</div>
	</div>
	<?php for ( $i = 0; $i < 8; $i++ ) : ?>
		<div class="kdna-ei-skel-table-row">
			<div class="kdna-ei-skel-row">
				<span class="kdna-ei-skeleton" style="width: 36px; height: 36px; flex: none;"></span>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--text"></span>
			</div>
			<?php for ( $c = 0; $c < 4; $c++ ) : ?>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="align-self: center;"></span>
			<?php endfor; ?>
		</div>
	<?php endfor; ?>
</div>
