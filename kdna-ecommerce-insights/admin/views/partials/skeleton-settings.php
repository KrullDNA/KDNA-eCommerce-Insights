<?php
/**
 * Loading skeleton for the Settings screen: tab list on the left and a
 * form card on the right.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="kdna-ei-card kdna-ei-skel-tabs" aria-hidden="true">
	<?php for ( $i = 0; $i < 8; $i++ ) : ?>
		<span class="kdna-ei-skeleton"></span>
	<?php endfor; ?>
</div>

<div class="kdna-ei-card" aria-hidden="true">
	<div class="kdna-ei-card__header">
		<span class="kdna-ei-skeleton kdna-ei-skeleton--title"></span>
	</div>
	<?php for ( $i = 0; $i < 6; $i++ ) : ?>
		<div class="kdna-ei-skel-field">
			<div class="kdna-ei-skel-stack">
				<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 50%;"></span>
				<span class="kdna-ei-skeleton kdna-ei-skeleton--text" style="width: 80%; height: 10px;"></span>
			</div>
			<span class="kdna-ei-skeleton kdna-ei-skeleton--block"></span>
		</div>
	<?php endfor; ?>
</div>
