<?php /** @var int $balance */ /** @var array<int,array> $ledger */ /** @var array{topic:int,reply:int,daily:int} $rates */ /** @var string $currency */ ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; Wallet</div>
<div class="maintitle">Wallet — <?= (int) $balance ?> <?= e($currency) ?></div>
<div class="subtitle">Earn <?= (int) $rates['topic'] ?>/topic, <?= (int) $rates['reply'] ?>/reply, <?= (int) $rates['daily'] ?>/day. Spend them in the <a href="<?= e(url('/shop')) ?>">Shop</a>.</div>
<div class="maintitle">Send a gift</div>
<form class="shopform" method="post" action="<?= e(url('/wallet/gift')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <div class="frow"><label for="shop-g-to">To user</label><input id="shop-g-to" name="username" size="20" required></div>
  <div class="frow"><label for="shop-g-amt">Amount</label><input id="shop-g-amt" name="amount" inputmode="numeric" size="8" required></div>
  <button class="btn" type="submit">Send gift</button>
</form>
<?php if ($ledger): ?>
<?php foreach ($ledger as $r): ?>
<div class="actionrow"><span><?= ((int) $r['delta'] >= 0 ? '+' : '') . (int) $r['delta'] ?> — <?= e($r['reason']) ?></span><span class="muted"><?= e($r['created_at']) ?></span></div>
<?php endforeach; ?>
<?php else: ?>
<p class="muted">No transactions yet.</p>
<?php endif; ?>
