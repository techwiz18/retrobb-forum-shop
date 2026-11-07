<?php /** @var array $post equipped title under the name (RetroBB #18) */
$f = \ForumShop\Shop::flairFor((int) ($post['user_id'] ?? 0)); ?>
<?php if ($f['title'] !== null): ?><div class="shop-ptitle"><?= e($f['title']) ?></div><?php endif; ?>
