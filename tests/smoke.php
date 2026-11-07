<?php
declare(strict_types=1);

// forum-shop smoke test: stub RetroBB core, back Db with SQLite, drive the
// REAL bootstrap wiring (hooks + routes). Migration SQL is MySQL; the one
// AUTO_INCREMENT line is translated for SQLite. Run: docker run --rm -v
// $PWD:/p -w /p composer:2 php tests/smoke.php

namespace RetroBB\Core {
    class Hooks
    {
        /** @var array<string,callable[]> */
        public static array $actions = [];
        /** @var array<string,string[]> */
        public static array $templates = [];
        /** @var array<string,callable[]> */
        public static array $filters = [];
        public static function add_action(string $h, callable $cb, int $p = 10): void { self::$actions[$h][] = $cb; }
        public static function add_filter(string $h, callable $cb, int $p = 10): void { self::$filters[$h][] = $cb; }
        public static function add_template_hook(string $loc, string $file): void { self::$templates[$loc][] = $file; }
        public static function do_action(string $h, ...$args): void { foreach (self::$actions[$h] ?? [] as $cb) { $cb(...$args); } }
        public static function apply_filters(string $h, mixed $v, ...$args): mixed { foreach (self::$filters[$h] ?? [] as $cb) { $v = $cb($v, ...$args); } return $v; }
        public static function render_template_hook(string $loc, array $vars = []): void
        {
            foreach (self::$templates[$loc] ?? [] as $f) { extract($vars, EXTR_SKIP); require $f; }
        }
    }
    class Db
    {
        public static ?\PDO $pdo = null;
        public static function pdo(): \PDO { return self::$pdo; }
    }
    class Auth
    {
        public static ?array $user = null;
        public static function startSession(bool $c = true): bool { return true; }
        public static function user(): ?array { return self::$user; }
        public static function check(): bool { return self::$user !== null; }
        public static function isAdmin(): bool { return (self::$user['user_group'] ?? '') === 'admin'; }
        public static function isMod(): bool { return in_array(self::$user['user_group'] ?? '', ['admin', 'mod'], true); }
    }
    class Csrf
    {
        public static bool $ok = true;
        public static function field(): string { return '<input type="hidden" name="csrf" value="t">'; }
        public static function verify(?string $t): bool { return self::$ok; }
    }
    class Router
    {
        public static string $path = '/';
        public static function currentPath(): string { return self::$path; }
    }
    class View
    {
        public static function partial(string $t, array $v = []): void { echo '<nav class="adminnav"></nav>'; }
        public static function render(string $t, array $v = [], ?string $l = 'layout'): void {}
    }
}

namespace RetroBB\Models {
    class User
    {
        public static function findByUsername(string $u): ?array
        {
            $st = \RetroBB\Core\Db::pdo()->prepare('SELECT * FROM users WHERE username = ?');
            $st->execute([$u]);
            $r = $st->fetch();
            return $r ?: null;
        }
    }
    class Topic
    {
        /** @var array<int,array> */
        public static array $topics = [];
        public static function find(int $id): ?array { return self::$topics[$id] ?? null; }
    }
    class Board
    {
        public static function index(): array { return $GLOBALS['board_index'] ?? []; }
    }
}

namespace {
    use ForumShop\Shop;
    use RetroBB\Core\Auth;
    use RetroBB\Core\Csrf;
    use RetroBB\Core\Db;
    use RetroBB\Core\Hooks;
    use RetroBB\Core\Router;

    function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    function url(string $p): string { return $p; }
    function board_name(): string { return 'TestBB'; }
    function base_path(): string { return ''; }
    function plugin_setting(string $plugin, string $key, string $default = ''): string
    {
        return $GLOBALS['psettings'][$plugin . '.' . $key] ?? $default;
    }
    function redirect(string $u, int $c = 302): void { throw new TestRedirect($u); }
    class TestRedirect extends \Exception { public function __construct(public string $to) { parent::__construct('redirect:' . $to); } }
    class FakeRouter
    {
        /** @var array<int,array{0:string,1:string,2:callable}> */
        public array $routes = [];
        public function get(string $p, callable $h): void { $this->routes[] = ['GET', $p, $h]; }
        public function post(string $p, callable $h): void { $this->routes[] = ['POST', $p, $h]; }
    }

    $fail = 0;
    function check(string $name, bool $cond): void
    {
        global $fail;
        echo ($cond ? 'PASS' : 'FAIL') . " $name\n";
        if (!$cond) { $fail++; }
    }

    // --- fixture DB: core tables + plugin migration (translated) ---
    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    Db::$pdo = $pdo;
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(50), user_group VARCHAR(20) DEFAULT 'member')");
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INT, user_id INT, body_bbcode TEXT)');
    $pdo->exec('CREATE TABLE settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT NOT NULL)');
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/001_shop.sql');
    $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO users (username, user_group) VALUES ('alice','member'),('bob','member'),('root','admin'),('carol','member')");
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (1,1,'hello')");
    \RetroBB\Models\Topic::$topics = [1 => ['id' => 1, 'forum_id' => 5]];

    require dirname(__DIR__) . '/bootstrap.php';

    check('routes registered', isset(Hooks::$actions['routes'][0]));
    $fr = new FakeRouter();
    Hooks::do_action('routes', $fr);
    $patterns = array_map(fn($r) => $r[0] . ' ' . $r[1], $fr->routes);
    check('shop+wallet+admin routes', in_array('GET #^/shop$#', $patterns) && in_array('GET #^/wallet$#', $patterns) && in_array('POST #^/wallet/gift$#', $patterns) && in_array('GET #^/shop/admin$#', $patterns) && in_array('POST #^/shop/buy$#', $patterns) && in_array('POST #^/shop/equip$#', $patterns) && in_array('POST #^/shop/admin$#', $patterns));
    check('display hooks registered', isset(Hooks::$templates['nav_links'], Hooks::$templates['userbox'], Hooks::$templates['profile_after'], Hooks::$templates['postbit_user']) && isset(Hooks::$filters['post_username_html']));
    check('lifecycle hooks registered', isset(Hooks::$actions['plugin_enabled'], Hooks::$actions['plugin_disabled']));

    // --- lifecycle: seed on enable ---
    check('starts unseeded', count(Shop::allItems()) === 0);
    Hooks::do_action('plugin_enabled', basename(dirname(__DIR__)));
    check('seeded on enable', count(Shop::allItems()) === 8);

    // --- earn: topic ---
    Hooks::do_action('topic_created', 1);
    check('topic earn +10', Shop::balance(1) === 10);
    Hooks::do_action('topic_created', 1);
    check('topic earn idempotent', Shop::balance(1) === 10);

    // --- earn: reply with new rich args ---
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (1,2,'reply')");
    $pid2 = (int) $pdo->lastInsertId();
    Hooks::do_action('post_created', 1, $pid2, 2);
    check('reply earn +5 to bob', Shop::balance(2) === 5);
    Hooks::do_action('post_created', 1, $pid2, 2);
    check('reply earn idempotent', Shop::balance(2) === 5);
    // legacy single-arg call still pays the newest unawarded post
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (1,1,'again')");
    Hooks::do_action('post_created', 1);
    check('legacy fallback pays alice', Shop::balance(1) === 15);

    // --- daily ---
    $_SERVER['REQUEST_METHOD'] = 'GET';
    Auth::$user = ['id' => 1, 'username' => 'alice', 'user_group' => 'member'];
    Shop::maybeDaily();
    check('daily +2', Shop::balance(1) === 17);
    Shop::maybeDaily();
    check('daily once per day', Shop::balance(1) === 17);

    // --- configurable rates + currency ---
    $GLOBALS['psettings'] = ['forum-shop.earn_topic' => '25'];
    check('rate override', Shop::rates()['topic'] === 25 && Shop::rates()['reply'] === 5);
    $GLOBALS['psettings'] = [];
    $GLOBALS['psettings']['forum-shop.currency'] = 'Coins';
    check('currency override', Shop::currency() === 'Coins');
    $GLOBALS['psettings'] = [];
    check('currency falls back', Shop::currency() === 'Credits');
    check('saveRates stores currency', Shop::saveRates(11, 6, 3, 'Coins')['ok'] && $pdo->query("SELECT `value` FROM settings WHERE `key`='forum-shop.currency'")->fetchColumn() === 'Coins');
    check('saveRates clears currency', Shop::saveRates(10, 5, 2, '')['ok'] && Shop::currency() === 'Credits');
    check('currency too long rejected', !Shop::saveRates(10, 5, 2, str_repeat('x', 21))['ok']);

    // --- per-forum rates (isolated user carol, forum 7) ---
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (2,4,'carol here')");
    \RetroBB\Models\Topic::$topics[2] = ['id' => 2, 'forum_id' => 7];
    $GLOBALS['psettings'] = ['forum-shop.forum_7_topic' => '30'];
    check('forum topic rate override', Shop::forumRate(7, 'topic') === 30 && Shop::forumRate(7, 'reply') === 5);
    Hooks::do_action('topic_created', 2);
    check('forum topic rate paid', Shop::balance(4) === 30);
    $GLOBALS['psettings']['forum-shop.forum_7_reply'] = '9';
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (2,4,'carol again')");
    $carolReply = (int) $pdo->lastInsertId();
    Hooks::do_action('post_created', 2, $carolReply, 4);
    check('forum reply rate paid', Shop::balance(4) === 39);
    $GLOBALS['psettings'] = [];
    check('forum rates fall back', Shop::forumRate(7, 'topic') === 10);
    check('saveForumRates stores override', Shop::saveForumRates([7 => 12], [])['ok']);
    check('saveForumRates rejects junk', !Shop::saveForumRates([7 => 9999], [])['ok']);

    // --- buy ---
    $items = Shop::allItems();
    $cheap = $items[0];
    check('grant +100', Shop::award(1, 100, 'grant') && Shop::balance(1) === 117);
    $r = Shop::buy(1, (int) $cheap['id']);
    check('buy ok, deducts', $r['ok'] && Shop::balance(1) === 117 - (int) $cheap['price']);
    $r = Shop::buy(1, (int) $cheap['id']);
    check('no double-buy, no charge', !$r['ok'] && Shop::balance(1) === 117 - (int) $cheap['price']);
    $pdo->prepare('INSERT INTO shop_items (kind,name,price,payload) VALUES (?,?,?,?)')->execute(['badge', 'Pricey', 999999, '']);
    $pid = (int) $pdo->lastInsertId();
    check('insufficient funds', !Shop::buy(2, $pid)['ok'] && Shop::balance(2) === 5);

    // --- equip: one per kind, server-side flair ---
    $b1 = (int) $cheap['id'];
    $pdo->prepare('INSERT INTO shop_items (kind,name,price,payload) VALUES (?,?,?,?)')->execute(['badge', 'Second', 1, '']);
    $b2 = (int) $pdo->lastInsertId();
    Shop::buy(1, $b2);
    Shop::equip(1, $b1);
    Shop::equip(1, $b2);
    $map = Shop::flairFor(1);
    check('equip replaces same kind', $map['badges'] === ['Second']);
    Shop::equip(1, $b2);
    check('equip toggles off', Shop::flairFor(1)['badges'] === []);

    Shop::equip(1, $b1);
    $html = Shop::usernameFlair('<a href="/members/alice.u1">alice</a>', ['user_id' => 1]);
    check('username filter adds star, keeps name', str_contains($html, 'shop-badge') && str_contains($html, '>alice</a>'));
    $html = Shop::usernameFlair('<a href="/members/alice.u1">alice</a>', ['id' => 1, 'username' => 'alice']);
    check('username filter accepts id-key rows', str_contains($html, 'shop-badge'));
    ob_start();
    $post = ['user_id' => 1, 'username' => 'alice'];
    require dirname(__DIR__) . '/views/title.php';
    $html = (string) ob_get_clean();
    check('title partial shows balance without title', !str_contains($html, 'Sage') && str_contains($html, 'shop-pbal') && str_contains($html, '/wallet?to=alice'));

    $pdo->prepare('INSERT INTO shop_items (kind,name,price,payload) VALUES (?,?,?,?)')->execute(['title', 'Sage', 1, '']);
    $t1 = (int) $pdo->lastInsertId();
    Shop::buy(1, $t1);
    Shop::equip(1, $t1);
    $pdo->prepare('INSERT INTO shop_items (kind,name,price,payload) VALUES (?,?,?,?)')->execute(['glow', 'Red', 1, '#ff0000']);
    $g1 = (int) $pdo->lastInsertId();
    Shop::buy(1, $g1);
    Shop::equip(1, $g1);
    $html = Shop::usernameFlair('<a href="/members/alice.u1">alice</a>', ['user_id' => 1]);
    check('glow colors name', str_contains($html, 'color:#ff0000'));
    ob_start();
    $post = ['user_id' => 1];
    require dirname(__DIR__) . '/views/title.php';
    check('title partial shows equipped title', str_contains((string) ob_get_clean(), 'Sage'));

    // --- display partials ---
    Router::$path = '/topic/x.t1';
    ob_start();
    Hooks::render_template_hook('nav_links', []);
    check('nav links partial', str_contains((string) ob_get_clean(), '/shop'));
    ob_start();
    Hooks::render_template_hook('userbox', ['user' => ['id' => 1]]);
    check('userbox chip shows balance', str_contains((string) ob_get_clean(), (string) Shop::balance(1)));
    ob_start();
    Hooks::render_template_hook('userbox', ['user' => null]);
    check('userbox chip hides for guests', trim((string) ob_get_clean()) === '');
    ob_start();
    Hooks::render_template_hook('profile_after', ['profile' => ['id' => 1, 'username' => 'alice']]);
    $html = (string) ob_get_clean();
    check('profile card shows balance+flair', str_contains($html, 'Credits') && str_contains($html, 'Sage') && str_contains($html, '/wallet?to=alice'));
    ob_start();
    Hooks::render_template_hook('admin_nav', []);
    check('admin nav partial links shop admin', str_contains((string) ob_get_clean(), '/shop/admin'));
    Router::$path = '/shop/admin';
    ob_start();
    Hooks::render_template_hook('admin_nav', []);
    check('admin nav active on shop pages', str_contains((string) ob_get_clean(), 'class="on"'));
    Router::$path = '/admin';
    ob_start();
    Hooks::render_template_hook('admin_nav', []);
    check('admin nav inactive elsewhere', !str_contains((string) ob_get_clean(), 'class="on"'));
    Router::$path = '/';
    ob_start();
    Hooks::render_template_hook('admin_dashboard', []);
    $html = (string) ob_get_clean();
    check('dashboard card shows totals', str_contains($html, 'dash-card') && str_contains($html, 'Credits in circulation'));

    // --- page handlers (no core layout in harness: raw content) ---
    Auth::$user = ['id' => 1, 'username' => 'alice', 'user_group' => 'member'];
    ob_start();
    Shop::shopPage();
    check('shop page renders items', str_contains((string) ob_get_clean(), 'Gold Star'));
    ob_start();
    Shop::walletPage();
    check('wallet page shows history', str_contains((string) ob_get_clean(), 'purchase'));
    ob_start();
    $_GET['to'] = 'bob';
    Shop::walletPage();
    $html = (string) ob_get_clean();
    unset($_GET['to']);
    check('wallet prefills gift recipient + headings', str_contains($html, 'Send a gift') && str_contains($html, 'Recent transactions') && str_contains($html, 'value="bob"'));
    Auth::$user = ['id' => 2, 'username' => 'bob', 'user_group' => 'member'];
    ob_start();
    Shop::adminPage();
    check('admin forbidden for members', http_response_code() === 403 || str_contains((string) ob_get_clean(), 'Forbidden'));
    Auth::$user = ['id' => 3, 'username' => 'root', 'user_group' => 'admin'];
    ob_start();
    Shop::adminPage();
    check('admin page renders', str_contains((string) ob_get_clean(), 'Manage Credits'));

    // --- CSRF fail on buy submit: no charge ---
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'bad', 'item_id' => $b2];
    Csrf::$ok = false;
    $before = Shop::balance(1);
    ob_start();
    Shop::buySubmit();
    $out = (string) ob_get_clean();
    check('CSRF mismatch, no charge', str_contains($out, 'CSRF mismatch') && Shop::balance(1) === $before);
    Csrf::$ok = true;

    // --- admin submit: grant + save_rates ---
    $_POST = ['csrf' => 't', 'shop_action' => 'grant', 'username' => 'bob', 'amount' => 40];
    try {
        Shop::adminSubmit();
        check('grant submit redirects', false);
    } catch (TestRedirect $e) {
        check('grant submit redirects', $e->to === '/shop/admin' && Shop::balance(2) === 45);
    }
    $_POST = ['csrf' => 't', 'shop_action' => 'grant', 'username' => 'bob', 'direction' => 'deduct', 'amount' => 5];
    try {
        Shop::adminSubmit();
        check('deduct submit redirects', false);
    } catch (TestRedirect $e) {
        check('deduct submit deducts', $e->to === '/shop/admin' && Shop::balance(2) === 40);
    }
    $ledger2 = Shop::ledgerFor(2);
    check('deduct logs deduct reason', ($ledger2[0]['reason'] ?? '') === 'deduct' && ($ledger2[1]['reason'] ?? '') === 'grant');
    check('deduct overdraft refused', !Shop::grant('bob', -1000)['ok'] && Shop::balance(2) === 40);

    // --- gifts: alice (64) -> bob (40) ---
    check('gift moves funds both ways', Shop::gift(1, 'bob', 10)['ok'] && Shop::balance(1) === 54 && Shop::balance(2) === 50);
    check('gift to self refused', !Shop::gift(1, 'alice', 1)['ok'] && Shop::balance(1) === 54);
    check('gift overdraft refused', !Shop::gift(1, 'bob', 99999)['ok'] && Shop::balance(1) === 54);
    check('gift to nobody refused', !Shop::gift(1, 'ghost', 1)['ok'] && Shop::balance(1) === 54);
    $_POST = ['csrf' => 't', 'username' => 'carol', 'amount' => 4];
    try {
        Auth::$user = ['id' => 1, 'username' => 'alice', 'user_group' => 'member'];
        Shop::giftSubmit();
        check('gift submit redirects', false);
    } catch (TestRedirect $e) {
        check('gift submit sends gift', $e->to === '/wallet' && Shop::balance(1) === 50 && Shop::balance(4) === 43);
    }
    Auth::$user = ['id' => 3, 'username' => 'root', 'user_group' => 'admin'];
    $_POST = ['csrf' => 't', 'shop_action' => 'save_rates', 'earn_topic' => 11, 'earn_reply' => 6, 'earn_daily' => 3];
    try {
        Shop::adminSubmit();
    } catch (TestRedirect) {
    }
    check('rates persist to settings', $pdo->query("SELECT `value` FROM settings WHERE `key`='forum-shop.earn_topic'")->fetchColumn() === '11');

    // --- delete ---
    check('delete missing fails', !Shop::deleteItem(999999)['ok']);
    Shop::addItem('Deleteme', 'badge', 5, '');
    $delId = (int) $pdo->query("SELECT id FROM shop_items WHERE name='Deleteme'")->fetchColumn();
    check('buy doomed item', Shop::buy(2, $delId)['ok']);
    check('delete strips owners', Shop::deleteItem($delId)['ok'] && !isset(Shop::ownedMap(2)[$delId]));
    check('deleted item unbuyable', Shop::buy(2, $delId)['error'] === 'No such item.');
    check('bad glow rejected', !Shop::addItem('X', 'glow', 5, 'red')['ok']);

    echo $fail === 0 ? "ALL PASS\n" : "$fail FAILURES\n";
    exit($fail === 0 ? 0 : 1);
}
