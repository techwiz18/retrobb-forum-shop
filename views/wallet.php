<?php /** @var int $balance */ /** @var array<int,array> $ledger */ /** @var array{topic:int,reply:int,daily:int} $rates */ ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; Wallet</div>
<div class="maintitle">Wallet — <?= (int) $balance ?> Credits</div>
<div class="subtitle">Earn <?= (int) $rates['topic'] ?>/topic, <?= (int) $rates['reply'] ?>/reply, <?= (int) $rates['daily'] ?>/day. Spend them in the <a href="<?= e(url('/shop')) ?>">Shop</a>.</div>
<?php if ($ledger): ?>
<?php foreach ($ledger as $r): ?>
<div class="actionrow"><span><?= ((int) $r['delta'] >= 0 ? '+' : '') . (int) $r['delta'] ?> — <?= e($r['reason']) ?></span><span class="muted"><?= e($r['created_at']) ?></span></div>
<?php endforeach; ?>
<?php else: ?>
<p class="muted">No transactions yet.</p>
<?php endif; ?>
