<?php /** @var array<int,array> $items */ /** @var array<int,int> $owned */ /** @var int $balance */
$me = \RetroBB\Core\Auth::user(); ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; Shop</div>
<div class="maintitle">Flair Shop<?php if ($me): ?> — <?= (int) $balance ?> Credits<?php endif; ?></div>
<div class="subtitle"><?php if ($me): ?>Earn <?= \ForumShop\Shop::EARN_TOPIC ?>/topic, <?= \ForumShop\Shop::EARN_REPLY ?>/reply, <?= \ForumShop\Shop::EARN_DAILY ?>/day.<?php else: ?><a href="<?= e(url('/login?next=' . urlencode('/shop'))) ?>">Log in</a> to buy flair. Posting earns Credits (<?= \ForumShop\Shop::EARN_TOPIC ?>/topic, <?= \ForumShop\Shop::EARN_REPLY ?>/reply, <?= \ForumShop\Shop::EARN_DAILY ?>/day).<?php endif; ?></div>
<?php $byKind = [];
foreach ($items as $it) { $byKind[$it['kind']][] = $it; } ?>
<?php foreach (['badge' => 'Badges', 'title' => 'Titles', 'glow' => 'Glows'] as $kind => $label): ?>
<?php if (empty($byKind[$kind])) continue; ?>
<div class="maintitle"><?= $label ?></div>
<div class="shop-grid">
<?php foreach ($byKind[$kind] as $it): ?>
<?php $id = (int) $it['id']; $has = isset($owned[$id]); $on = ($owned[$id] ?? 0) === 1; ?>
<div class="shop-card">
  <b><?= e($it['name']) ?></b><?php if ($kind === 'glow'): ?> <span class="swatch" style="background:<?= e($it['payload']) ?>"></span><?php endif; ?><br>
  <span class="price"><?= (int) $it['price'] ?> Credits<?php if ($on): ?> · equipped<?php endif; ?></span><br>
  <?php if (!$me): ?><span class="price">(log in to buy)</span>
  <?php elseif (!$has): ?>
    <form method="post" action="<?= e(url('/shop/buy')) ?>" style="margin-top:4px"><?= \RetroBB\Core\Csrf::field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><button class="smallbtn" type="submit">Buy</button></form>
  <?php else: ?>
    <form method="post" action="<?= e(url('/shop/equip')) ?>" style="margin-top:4px"><?= \RetroBB\Core\Csrf::field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><button class="smallbtn" type="submit"><?= $on ? 'Unequip' : 'Equip' ?></button></form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
