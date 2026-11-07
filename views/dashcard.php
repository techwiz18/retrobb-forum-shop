<?php /** admin_dashboard: Shop card with live totals (no vars passed) */
$s = \ForumShop\Shop::stats(); ?>
<a class="dash-card" href="<?= e(url('/shop/admin')) ?>"><b>💰 Shop (<?= (int) $s['items'] ?> items)</b><small><?= (int) $s['circulation'] ?> Credits in circulation · rates, grants, deletes</small></a>
