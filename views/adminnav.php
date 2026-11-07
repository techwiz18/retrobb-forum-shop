<?php /** admin_nav: Shop link inside the AdminCP nav (no vars passed) */
$__p = \RetroBB\Core\Router::currentPath();
$__bp = base_path();
if ($__bp !== '' && ($__p === $__bp || str_starts_with($__p, $__bp . '/'))) {
    $__p = substr($__p, strlen($__bp)) ?: '/';
} ?>
<a href="<?= e(url('/shop/admin')) ?>" class="<?= str_starts_with($__p, '/shop/admin') ? 'on' : '' ?>">Shop</a>
