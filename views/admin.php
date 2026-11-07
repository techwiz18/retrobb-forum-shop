<?php /** @var array<int,array> $items */ /** @var array<int,array> $ledger */ ?>
<div class="breadcrumb"><a href="<?= e(url('/')) ?>">Index</a> &raquo; <a href="<?= e(url('/admin')) ?>">AdminCP</a> &raquo; Shop</div>
<div class="maintitle">AdminCP — Shop</div>
<?php \RetroBB\Core\View::partial('admin/_nav', ['active' => '']); ?>
<p class="muted">Flair items and Credit grants. Purchases and awards are journaled below.</p>
<div class="shop-grid">
<div class="shop-card">
<b>Add item</b>
<form class="shopform" method="post" action="<?= e(url('/shop/admin')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <input type="hidden" name="shop_action" value="add_item">
  <label>Name <input name="name" maxlength="60" size="24" required></label>
  <label>Kind <select name="kind"><option value="badge">badge</option><option value="title">title</option><option value="glow">glow</option></select></label>
  <label>Price <input name="price" inputmode="numeric" size="8" value="50" required></label>
  <label>Color (glow only) <input name="payload" placeholder="#c0392b" size="8" maxlength="7"></label>
  <button class="btn" type="submit">Add</button>
</form>
</div>
<div class="shop-card">
<b>Grant Credits</b>
<form class="shopform" method="post" action="<?= e(url('/shop/admin')) ?>">
  <?= \RetroBB\Core\Csrf::field() ?>
  <input type="hidden" name="shop_action" value="grant">
  <label>User <input name="username" size="20" required></label>
  <label>Amount <input name="amount" inputmode="numeric" size="8" required></label>
  <button class="btn" type="submit">Grant</button>
</form>
</div>
</div>
<div class="maintitle">Items (<?= count($items) ?>)</div>
<div class="recent-list">
<?php foreach ($items as $it): ?>
<div class="recent-row"><span><b><?= e($it['name']) ?></b> <span class="muted">[<?= e($it['kind']) ?>]</span><?php if ($it['kind'] === 'glow'): ?> <span class="swatch" style="background:<?= e($it['payload']) ?>"></span><?php endif; ?></span><span><span class="muted"><?= (int) $it['price'] ?> Credits</span> <form method="post" action="<?= e(url('/shop/admin')) ?>" style="display:inline" onsubmit="return confirm('Delete this item? Owners lose it with no refund.')"><?= \RetroBB\Core\Csrf::field() ?><input type="hidden" name="shop_action" value="delete_item"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"><button class="smallbtn danger" type="submit">Delete</button></form></span></div>
<?php endforeach; ?>
<?php if (!$items): ?><div class="empty">No items yet.</div><?php endif; ?>
</div>
<div class="maintitle">Recent transactions</div>
<div class="recent-list">
<?php foreach ($ledger as $r): ?>
<div class="recent-row"><span><?= e($r['username'] ?? '?') ?> <?= ((int) $r['delta'] >= 0 ? '+' : '') . (int) $r['delta'] ?> <span class="muted">— <?= e($r['reason']) ?></span></span><span class="muted"><?= e($r['created_at']) ?></span></div>
<?php endforeach; ?>
<?php if (!$ledger): ?><div class="empty">No transactions yet.</div><?php endif; ?>
</div>
