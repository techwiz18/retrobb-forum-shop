<?php
declare(strict_types=1);

// forum-shop smoke test: stub RetroBB core, back Db with SQLite, drive the
// REAL bootstrap wiring (hooks + routes). Run: docker run --rm -v
// $PWD:/p -w /p composer:2 php tests/smoke.php

namespace RetroBB\Core {
    class Hooks
    {
        /** @var array<string,callable[]> */
        public static array $actions = [];
        /** @var array<string,string[]> */
        public static array $templates = [];
        public static function add_action(string $h, callable $cb, int $p = 10): void { self::$actions[$h][] = $cb; }
        public static function add_filter(string $h, callable $cb, int $p = 10): void {}
        public static function add_template_hook(string $loc, string $file): void { self::$templates[$loc][] = $file; }
        public static function do_action(string $h, ...$args): void { foreach (self::$actions[$h] ?? [] as $cb) { $cb(...$args); } }
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
}

namespace {
    use ForumShop\Shop;
    use RetroBB\Core\Auth;
    use RetroBB\Core\Csrf;
    use RetroBB\Core\Db;
    use RetroBB\Core\Hooks;
    use RetroBB\Core\Router;

    define('FORUMSHOP_NO_EXIT', true);

    function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    function url(string $p): string { return $p; }
    function board_name(): string { return 'TestBB'; }
    function base_path(): string { return ''; }
    function redirect(string $u, int $c = 302): void { throw new TestRedirect($u); }
    class TestRedirect extends \Exception { public function __construct(public string $to) { parent::__construct('redirect:' . $to); } }

    $fail = 0;
    function check(string $name, bool $cond): void
    {
        global $fail;
        echo ($cond ? 'PASS' : 'FAIL') . " $name\n";
        if (!$cond) { $fail++; }
    }

    // --- fixture DB ---
    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    Db::$pdo = $pdo;
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(50), user_group VARCHAR(20) DEFAULT 'member')");
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INT, user_id INT, body_bbcode TEXT)');
    $pdo->exec("INSERT INTO users (username, user_group) VALUES ('alice','member'),('bob','member'),('root','admin')");
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (1,1,'hello')");

    require dirname(__DIR__) . '/bootstrap.php';

    check('tables created', (bool) $pdo->query("SELECT name FROM sqlite_master WHERE name='shop_wallet'")->fetch());
    check('seed items', count(Shop::allItems()) === 8);

    // --- earn: topic ---
    Hooks::do_action('topic_created', 1);
    check('topic earn +10', Shop::balance(1) === 10);
    Hooks::do_action('topic_created', 1);
    check('topic earn idempotent', Shop::balance(1) === 10);

    // --- earn: reply goes to the reply author, once ---
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (1,2,'reply')");
    Hooks::do_action('post_created', 1);
    check('reply earn +5 to bob', Shop::balance(2) === 5);
    Hooks::do_action('post_created', 1);
    check('reply earn idempotent', Shop::balance(2) === 5);
    $pdo->exec("INSERT INTO posts (topic_id, user_id, body_bbcode) VALUES (1,1,'again')");
    Hooks::do_action('post_created', 1);
    check('second reply pays alice', Shop::balance(1) === 15);

    // --- daily ---
    $_SERVER['REQUEST_METHOD'] = 'GET';
    Auth::$user = ['id' => 1, 'username' => 'alice', 'user_group' => 'member'];
    Shop::maybeDaily();
    check('daily +2', Shop::balance(1) === 17);
    Shop::maybeDaily();
    check('daily once per day', Shop::balance(1) === 17);

    // --- buy ---
    $items = Shop::allItems();
    $cheap = $items[0];
    check('grant +100', Shop::award(1, 100, 'grant') && Shop::balance(1) === 117);
    $r = Shop::buy(1, (int) $cheap['id']);
    check('buy ok, deducts', $r['ok'] && Shop::balance(1) === 117 - (int) $cheap['price']);
    $r = Shop::buy(1, (int) $cheap['id']);
    check('no double-buy, no charge', !$r['ok'] && Shop::balance(1) === 117 - (int) $cheap['price']);
    $spendy = ['kind' => 'badge', 'name' => 'x', 'price' => 0, 'payload' => ''];
    $pdo->prepare('INSERT INTO shop_items (kind,name,price,payload) VALUES (?,?,?,?)')->execute(['badge', 'Pricey', 999999, '']);
    $pid = (int) $pdo->lastInsertId();
    check('insufficient funds', !Shop::buy(2, $pid)['ok'] && Shop::balance(2) === 5);

    // --- equip: one per kind ---
    $b1 = (int) $cheap['id'];
    $pdo->prepare('INSERT INTO shop_items (kind,name,price,payload) VALUES (?,?,?,?)')->execute(['badge', 'Second', 1, '']);
    $b2 = (int) $pdo->lastInsertId();
    Shop::buy(1, $b2);
    Shop::equip(1, $b1);
    Shop::equip(1, $b2);
    Router::$path = '/topic/welcome-to-test.t1';
    Shop::resetCache();
    $map = Shop::pageFlair();
    check('equip replaces same kind', ($map['alice']['badges'] ?? []) === ['Second']);
    Shop::equip(1, $b2);
    Shop::resetCache();
    check('equip toggles off', (Shop::pageFlair()['alice']['badges'] ?? []) === []);

    // --- footer script decorates postbits in place ---
    Shop::equip(1, $b1);
    Shop::resetCache();
    Router::$path = '/topic/welcome-to-test.t1';
    ob_start();
    Shop::footerFlair();
    $html = (string) ob_get_clean();
    check('flair script carries badge data', str_contains($html, 'Gold Star') && str_contains($html, 'postbit[data-username]'));
    Router::$path = '/members/alice.u1';
    Shop::resetCache();
    ob_start();
    Shop::footerFlair();
    check('no flair script off topic pages', (string) ob_get_clean() === '');

    // --- routes ---
    Router::$path = '/shop';
    Auth::$user = ['id' => 1, 'username' => 'alice', 'user_group' => 'member'];
    ob_start();
    Shop::route();
    $html = (string) ob_get_clean();
    check('GET /shop renders items', str_contains($html, 'Gold Star') && str_contains($html, 'Buy'));

    Router::$path = '/wallet';
    ob_start();
    Shop::route();
    $html = (string) ob_get_clean();
    check('GET /wallet shows balance+history', str_contains($html, 'Credits') && str_contains($html, 'purchase'));

    Router::$path = '/nope';
    ob_start();
    Shop::route();
    check('unknown path falls through', (string) ob_get_clean() === '');

    Router::$path = '/shop/admin';
    ob_start();
    Shop::route();
    check('admin page forbidden for members', http_response_code() === 403 || str_contains((string) ob_get_clean(), 'Forbidden'));
    Auth::$user = ['id' => 3, 'username' => 'root', 'user_group' => 'admin'];
    ob_start();
    Shop::route();
    check('admin page renders for admins', str_contains((string) ob_get_clean(), 'Grant Credits'));

    // --- CSRF fail on buy posts, no charge ---
    Router::$path = '/shop/buy';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'bad', 'item_id' => $b2];
    Csrf::$ok = false;
    $before = Shop::balance(1);
    ob_start();
    Shop::route();
    $out = (string) ob_get_clean();
    check('CSRF mismatch, no charge', str_contains($out, 'CSRF mismatch') && Shop::balance(1) === $before);
    Csrf::$ok = true;

    // --- grant validation ---
    check('grant unknown user fails', !Shop::grant('nobody', 10)['ok']);
    check('grant zero fails', !Shop::grant('alice', 0)['ok']);
    check('bad glow rejected', !Shop::addItem('X', 'glow', 5, 'red')['ok']);
    check('add item ok', Shop::addItem('Tester', 'title', 5, '')['ok']);

    echo $fail === 0 ? "ALL PASS\n" : "$fail FAILURES\n";
    exit($fail === 0 ? 0 : 1);
}
