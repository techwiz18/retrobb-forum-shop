<?php /** @var array $post equipped title + balance + gift link (RetroBB #18) */
$uid = (int) ($post['user_id'] ?? 0);
$f = \ForumShop\Shop::flairFor($uid);
$uname = (string) ($post['username'] ?? ''); ?>
<?php if ($f['title'] !== null): ?><div class="shop-ptitle"><?= e($f['title']) ?></div><?php endif; ?>
<div class="shop-pbal">💰 <?= (int) \ForumShop\Shop::balance($uid) ?><?php if ($uname !== ''): ?> · <a href="<?= e(url('/wallet?to=' . urlencode($uname))) ?>" title="Send <?= e($uname) ?> a gift">🎁</a><?php endif; ?></div>
