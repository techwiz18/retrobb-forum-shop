<?php /** @var array $profile member wallet card (RetroBB #17) */
$flair = \ForumShop\Shop::flairFor((int) ($profile['id'] ?? 0));
$bal = \ForumShop\Shop::balance((int) ($profile['id'] ?? 0));
$pname = (string) ($profile['username'] ?? '');
$meShop = \RetroBB\Core\Auth::user();
$selfShop = $meShop && (int) $meShop['id'] === (int) ($profile['id'] ?? 0); ?>
<div class="subtitle shop-usercard">💰 <b class="bal"><?= (int) $bal ?> <?= e(\ForumShop\Shop::currency()) ?></b>
<?php foreach ($flair['badges'] as $b): ?><span class="shop-badge"<?php if ($flair['glow']): ?> style="color:<?= e($flair['glow']) ?>"<?php endif; ?> title="<?= e($b) ?>">★</span><?php endforeach; ?>
<?php if ($flair['title'] !== null): ?><span class="shop-ptitle"><?= e($flair['title']) ?></span><?php endif; ?>
<?php if ($pname !== '' && !$selfShop): ?><a class="smallbtn" href="<?= e(url('/wallet?to=' . urlencode($pname))) ?>" title="Send <?= e($pname) ?> a gift">🎁 Gift</a><?php endif; ?>
</div>
