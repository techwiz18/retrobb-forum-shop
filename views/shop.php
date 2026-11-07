<?php /** @var array<int,array> $items */ /** @var array<int,int> $owned */ /** @var int $balance */
$me = \RetroBB\Core\Auth::user(); ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; Shop</div>
<div class="maintitle">Flair Shop<?php if ($me): ?> — <?= (int) $balance ?> Credits<?php endif; ?></div>
<?php if (!$me): ?><p class="muted"><a href="<?= e(url('/login?next=' . urlencode('/shop'))) ?>">Log in</a> to buy flair. Posting earns Credits (<?= \ForumShop\Shop::EARN_TOPIC ?>/topic, <?= \ForumShop\Shop::EARN_REPLY ?>/reply, <?= \ForumShop\Shop::EARN_DAILY ?>/day).</p><?php endif; ?>
<?php $byKind = [];
foreach ($items as $it) { $byKind[$it['kind']][] = $it; } ?>
<?php foreach (['badge' => 'Badges', 'title' => 'Titles', 'glow' => 'Glows'] as $kind => $label): ?>
<?php if (empty($byKind[$kind])) continue; ?>
<div class="maintitle"><?= $label ?></div>
<?php foreach ($byKind[$kind] as $it): ?>
<?php $id = (int) $it['id']; $has = isset($owned[$id]); $on = ($owned[$id] ?? 0) === 1; ?>
<div class="actionrow">
  <b><?= e($it['name']) ?></b><?php if ($kind === 'glow'): ?> <span style="display:inline-block;width:12px;height:12px;background:<?= e($it['payload']) ?>"></span><?php endif; ?>
  — <?= (int) $it['price'] ?> Credits
  <?php if (!$me): ?><span class="muted">(log in to buy)</span>
  <?php elseif (!$has): ?>
    <form method="post" action="<?= e(url('/shop/buy')) ?>" style="display:inline"><?= \RetroBB\Core\Csrf::field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><button class="smallbtn" type="submit">Buy</button></form>
  <?php else: ?>
    <form method="post" action="<?= e(url('/shop/equip')) ?>" style="display:inline"><?= \RetroBB\Core\Csrf::field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><button class="smallbtn" type="submit"><?= $on ? 'Unequip' : 'Equip' ?></button></form>
    <?php if ($on): ?><span class="muted">equipped</span><?php endif; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
