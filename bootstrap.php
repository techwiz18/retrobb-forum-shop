<?php
declare(strict_types=1);

// forum-shop bootstrap — loaded by RetroBB's plugin loader (core/Plugins.php).
// Tables come from migrations/*.sql (bin/migrate.php); seeding happens on
// plugin_enabled. Never fatal: a broken shop must not whitescreen the board.

namespace ForumShop;

use RetroBB\Core\Hooks;

define('FORUMSHOP_DIR', __DIR__);
define('FORUMSHOP_ROOT', dirname(__DIR__, 2));

require_once __DIR__ . '/src/Shop.php';

try {
    Hooks::add_action('routes', [Shop::class, 'registerRoutes']);
    Hooks::add_action('topic_created', [Shop::class, 'onTopicCreated']);
    Hooks::add_action('post_created', [Shop::class, 'onPostCreated']);
    Hooks::add_action('header', [Shop::class, 'maybeDaily']);
    Hooks::add_action('plugin_enabled', function ($name) { Shop::onPluginToggled((string) $name, true); });
    Hooks::add_action('plugin_disabled', function ($name) { Shop::onPluginToggled($name, false); });
    Hooks::add_filter('post_username_html', [Shop::class, 'usernameFlair']);
    Hooks::add_action('head', [Shop::class, 'headCss']);
    Hooks::add_template_hook('nav_links', __DIR__ . '/views/nav.php');
    Hooks::add_template_hook('userbox', __DIR__ . '/views/chip.php');
    Hooks::add_template_hook('profile_after', __DIR__ . '/views/card.php');
    Hooks::add_template_hook('postbit_user', __DIR__ . '/views/title.php');
    Hooks::add_template_hook('admin_nav', __DIR__ . '/views/adminnav.php');
    Hooks::add_template_hook('admin_dashboard', __DIR__ . '/views/dashcard.php');
} catch (\Throwable $t) {
    error_log('forum-shop bootstrap failed: ' . $t->getMessage());
}
