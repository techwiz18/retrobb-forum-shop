<?php /** @var array<int,array> $items */ /** @var array<int,array> $ledger */ /** @var array{topic:int,reply:int,daily:int} $rates */ ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; <a href="<?= e(url('/admin')) ?>">AdminCP</a> &raquo; Shop</div>
<div class="maintitle">AdminCP — Shop</div>
<?php \RetroBB\Core\View::partial('admin/_nav', ['active' => '']); ?>
<p class="muted">Flair items and Credit grants. Purchases and awards are journaled below.</p>
<div class="shop-anchors"><a href="#shop-add">Add item</a> · <a href="#shop-grant">Grant Credits</a> · <a href="#shop-rates">Earn rates</a> · <a href="#shop-items">Items (<?= count($items) ?>)</a> · <a href="#shop-ledger">Transactions</a></div>
<div class="maintitle" id="shop-add">Add item</div>
<form class="shopform" method="post" action="<?= e(url('/shop/admin')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <input type="hidden" name="shop_action" value="add_item">
  <div class="frow"><label for="shop-in-name">Name</label><input id="shop-in-name" name="name" maxlength="60" size="30" required></div>
  <div class="frow"><label for="shop-in-kind">Kind</label><select id="shop-in-kind" name="kind"><option value="badge">badge</option><option value="title">title</option><option value="glow">glow</option></select></div>
  <div class="frow"><label for="shop-in-price">Price</label><input id="shop-in-price" name="price" inputmode="numeric" size="8" value="50" required></div>
  <div class="frow"><label for="shop-in-payload">Color (glow only)</label><input id="shop-in-payload" name="payload" placeholder="#c0392b" size="8" maxlength="7"></div>
  <button class="btn" type="submit">Add</button>
</form>
<div class="maintitle" id="shop-grant">Grant Credits</div>
<form class="shopform" method="post" action="<?= e(url('/shop/admin')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <input type="hidden" name="shop_action" value="grant">
  <div class="frow"><label for="shop-in-user">User</label><input id="shop-in-user" name="username" size="20" required></div>
  <div class="frow"><label for="shop-in-amount">Amount</label><input id="shop-in-amount" name="amount" inputmode="numeric" size="8" required></div>
  <button class="btn" type="submit">Grant</button>
</form>
<div class="maintitle" id="shop-rates">Earn rates</div>
<form class="shopform" method="post" action="<?= e(url('/shop/admin')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <input type="hidden" name="shop_action" value="save_rates">
  <div class="frow"><label for="shop-in-topic">Per topic</label><input id="shop-in-topic" name="earn_topic" inputmode="numeric" size="6" value="<?= (int) $rates['topic'] ?>" required></div>
  <div class="frow"><label for="shop-in-reply">Per reply</label><input id="shop-in-reply" name="earn_reply" inputmode="numeric" size="6" value="<?= (int) $rates['reply'] ?>" required></div>
  <div class="frow"><label for="shop-in-daily">Daily bonus</label><input id="shop-in-daily" name="earn_daily" inputmode="numeric" size="6" value="<?= (int) $rates['daily'] ?>" required></div>
  <button class="btn" type="submit">Save</button>
</form>
<div class="maintitle" id="shop-items">Items (<?= count($items) ?>)</div>
<?php if ($items): ?>
<table class="shop-table">
<thead><tr><th>Name</th><th>Kind</th><th class="num">Price</th><th></th></tr></thead>
<tbody>
<?php foreach ($items as $it): ?>
<tr><td><b><?= e($it['name']) ?></b><?php if ($it['kind'] === 'glow'): ?> <span class="swatch" style="background:<?= e($it['payload']) ?>"></span><?php endif; ?></td><td><?= e($it['kind']) ?></td><td class="num"><?= (int) $it['price'] ?></td><td><form method="post" action="<?= e(url('/shop/admin')) ?>" style="display:inline" onsubmit="return confirm('Delete this item? Owners lose it with no refund.')"><?= \RetroBB\Core\Csrf::field() ?><input type="hidden" name="shop_action" value="delete_item"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"><button class="smallbtn danger" type="submit">Delete</button></form></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?><div class="empty">No items yet.</div><?php endif; ?>
<div class="maintitle" id="shop-ledger">Recent transactions</div>
<?php if ($ledger): ?>
<table class="shop-table">
<thead><tr><th>User</th><th class="num">Change</th><th>Reason</th><th>When</th></tr></thead>
<tbody>
<?php foreach ($ledger as $r): ?>
<tr><td><?= e($r['username'] ?? '?') ?></td><td class="num"><?= ((int) $r['delta'] >= 0 ? '+' : '') . (int) $r['delta'] ?></td><td><?= e($r['reason']) ?></td><td class="muted"><?= e($r['created_at']) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?><div class="empty">No transactions yet.</div><?php endif; ?>
