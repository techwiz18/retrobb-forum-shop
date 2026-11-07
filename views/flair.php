<?php /** @var array $post */ $flair = \ForumShop\Shop::flairFor((int) ($post['user_id'] ?? 0)); ?>
<?php if (!empty($flair['badges']) || $flair['title'] !== null): ?>
<div class="shop-flair"<?php if ($flair['glow']): ?> style="border-left:3px solid <?= e($flair['glow']) ?>;padding-left:4px"<?php endif; ?>>
  <?php foreach ($flair['badges'] as $b): ?><span class="shop-badge"<?php if ($flair['glow']): ?> style="color:<?= e($flair['glow']) ?>"<?php endif; ?>>★ <?= e($b) ?></span><?php endforeach; ?>
  <?php if ($flair['title'] !== null): ?><span class="shop-title"><?= e($flair['title']) ?></span><?php endif; ?>
</div>
<?php endif; ?>
