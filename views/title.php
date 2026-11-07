<?php /** @var array $post equipped title + credit balance (RetroBB #18) */
$uid = (int) ($post['user_id'] ?? 0);
$f = \ForumShop\Shop::flairFor($uid); ?>
<?php if ($f['title'] !== null): ?><div class="shop-ptitle"><?= e($f['title']) ?></div><?php endif; ?>
<div class="shop-pbal">💰 <?= (int) \ForumShop\Shop::balance($uid) ?></div>
