<?php
declare(strict_types=1);

// forum-shop bootstrap — loaded by RetroBB's plugin loader (core/Plugins.php).
// Never fatal: a broken shop must not whitescreen the board.

namespace ForumShop;

use RetroBB\Core\Hooks;

define('FORUMSHOP_DIR', __DIR__);
define('FORUMSHOP_ROOT', dirname(__DIR__, 2));

require_once __DIR__ . '/src/Shop.php';

try {
    Shop::install();
} catch (\Throwable $t) {
    error_log('forum-shop install failed: ' . $t->getMessage());
}

Hooks::add_action('boot', [Shop::class, 'route']);
Hooks::add_action('topic_created', [Shop::class, 'onTopicCreated']);
Hooks::add_action('post_created', [Shop::class, 'onPostCreated']);
Hooks::add_action('header', [Shop::class, 'headerChip']);
Hooks::add_action('head', [Shop::class, 'headCss']);
Hooks::add_template_hook('postbit_after', __DIR__ . '/views/flair.php');
