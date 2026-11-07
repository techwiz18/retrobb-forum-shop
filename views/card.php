<?php /** @var array $profile member wallet card (RetroBB #17) */
$flair = \ForumShop\Shop::flairFor((int) ($profile['id'] ?? 0));
$bal = \ForumShop\Shop::balance((int) ($profile['id'] ?? 0)); ?>
<div class="subtitle shop-usercard">💰 <b class="bal"><?= (int) $bal ?> <?= e(\ForumShop\Shop::currency()) ?></b>
<?php foreach ($flair['badges'] as $b): ?><span class="shop-badge"<?php if ($flair['glow']): ?> style="color:<?= e($flair['glow']) ?>"<?php endif; ?> title="<?= e($b) ?>">★</span><?php endforeach; ?>
<?php if ($flair['title'] !== null): ?><span class="shop-ptitle"><?= e($flair['title']) ?></span><?php endif; ?>
</div>
