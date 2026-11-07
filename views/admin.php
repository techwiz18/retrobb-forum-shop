<?php /** @var array<int,array> $items */ /** @var array<int,array> $ledger */ ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; <a href="<?= e(url('/admin')) ?>">AdminCP</a> &raquo; Shop</div>
<div class="maintitle">Shop admin</div>
<div class="maintitle">Add item</div>
<form class="shopform" method="post" action="<?= e(url('/shop/admin')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <input type="hidden" name="shop_action" value="add_item">
  <label>Name <input name="name" maxlength="60" size="30" required></label>
  <label>Kind <select name="kind"><option value="badge">badge</option><option value="title">title</option><option value="glow">glow</option></select></label>
  <label>Price <input name="price" inputmode="numeric" size="8" value="50" required></label>
  <label>Color (glow only) <input name="payload" placeholder="#c0392b" size="8" maxlength="7"></label>
  <button class="btn" type="submit">Add</button>
</form>
<div class="maintitle">Grant Credits</div>
<form class="shopform" method="post" action="<?= e(url('/shop/admin')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <input type="hidden" name="shop_action" value="grant">
  <label>User <input name="username" size="20" required></label>
  <label>Amount <input name="amount" inputmode="numeric" size="8" required></label>
  <button class="btn" type="submit">Grant</button>
</form>
<div class="maintitle">Items (<?= count($items) ?>)</div>
<?php foreach ($items as $it): ?>
<div class="actionrow"><span><b><?= e($it['name']) ?></b> [<?= e($it['kind']) ?>]</span><span class="muted"><?= (int) $it['price'] ?> Credits</span></div>
<?php endforeach; ?>
<div class="maintitle">Recent transactions</div>
<?php foreach ($ledger as $r): ?>
<div class="actionrow"><span><?= e($r['username'] ?? '?') ?> <?= ((int) $r['delta'] >= 0 ? '+' : '') . (int) $r['delta'] ?> — <?= e($r['reason']) ?></span><span class="muted"><?= e($r['created_at']) ?></span></div>
<?php endforeach; ?>
