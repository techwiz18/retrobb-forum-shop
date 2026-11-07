<?php /** @var int $balance */ /** @var array<int,array> $ledger */ ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; Wallet</div>
<div class="maintitle">Wallet — <?= (int) $balance ?> Credits</div>
<div class="subtitle">Earn <?= \ForumShop\Shop::EARN_TOPIC ?>/topic, <?= \ForumShop\Shop::EARN_REPLY ?>/reply, <?= \ForumShop\Shop::EARN_DAILY ?>/day. Spend them in the <a href="<?= e(url('/shop')) ?>">Shop</a>.</div>
<?php if ($ledger): ?>
<?php foreach ($ledger as $r): ?>
<div class="actionrow"><span><?= ((int) $r['delta'] >= 0 ? '+' : '') . (int) $r['delta'] ?> — <?= e($r['reason']) ?></span><span class="muted"><?= e($r['created_at']) ?></span></div>
<?php endforeach; ?>
<?php else: ?>
<p class="muted">No transactions yet.</p>
<?php endif; ?>
