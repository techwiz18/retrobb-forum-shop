<?php /** @var array|null $user userbox chip: balance at a glance (RetroBB #17) */
if (empty($user)) return;
$bal = \ForumShop\Shop::balance((int) $user['id']); ?>
<span class="shop-usercard"> 💰 <a href="<?= e(url('/wallet')) ?>" title="Your wallet"><b class="bal"><?= (int) $bal ?></b></a></span>
