<?php
declare(strict_types=1);

namespace ForumShop;

use PDO;
use RetroBB\Core\Auth;
use RetroBB\Core\Csrf;
use RetroBB\Core\Db;
use RetroBB\Core\Router;

/**
 * Forum currency ("Credits") + flair shop.
 *
 * Earn: new topic / reply / daily login. Spend: badges, titles, glows that
 * render under the postbit. No core changes needed: pages are served from the
 * `boot` hook (before the core router runs) and flair uses `postbit_after`.
 */
class Shop
{
    public const EARN_TOPIC = 10;
    public const EARN_REPLY = 5;
    public const EARN_DAILY = 2;

    public const KINDS = ['badge', 'title', 'glow'];

    /** @var array<int,int> per-request balance cache */
    private static array $balanceCache = [];
    /** @var array<string,array>|null topic flair map for this request */
    private static ?array $pageFlairCache = null;

    public static function resetCache(): void
    {
        self::$balanceCache = [];
        self::$pageFlairCache = null;
    }

    // ---------------- install ----------------

    public static function install(): void
    {
        $pdo = Db::pdo();
        $auto = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'INT AUTO_INCREMENT PRIMARY KEY';
        $pdo->exec('CREATE TABLE IF NOT EXISTS shop_wallet (user_id INT PRIMARY KEY, balance INT NOT NULL DEFAULT 0)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_items (id $auto, kind VARCHAR(20) NOT NULL, name VARCHAR(100) NOT NULL, price INT NOT NULL DEFAULT 0, payload VARCHAR(200) NOT NULL DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_inventory (id $auto, user_id INT NOT NULL, item_id INT NOT NULL, equipped INT NOT NULL DEFAULT 0, UNIQUE(user_id, item_id))");
        // shortcut: UNIQUE allows multiple NULLs (MySQL + SQLite), so grants/purchases share the table without colliding; upgrade to partial indexes if a second nullable unique is ever needed
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_ledger (id $auto, post_id INT NULL UNIQUE, user_id INT NOT NULL, delta INT NOT NULL, reason VARCHAR(30) NOT NULL, day VARCHAR(10) NULL, created_at VARCHAR(32) NOT NULL)");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_ledger_daily ON shop_ledger (user_id, reason, day)');
        $n = (int) $pdo->query('SELECT COUNT(*) FROM shop_items')->fetchColumn();
        if ($n === 0) {
            $st = $pdo->prepare('INSERT INTO shop_items (kind, name, price, payload) VALUES (?,?,?,?)');
            foreach (self::defaultItems() as $d) {
                $st->execute($d);
            }
        }
    }

    /** @return array<int,array{0:string,1:string,2:int,3:string}> */
    private static function defaultItems(): array
    {
        return [
            ['badge', 'Gold Star', 50, ''],
            ['badge', 'Hot Topic', 100, ''],
            ['badge', 'Diamond', 250, ''],
            ['title', 'Resident Philosopher', 75, ''],
            ['title', 'Pixel Legend', 150, ''],
            ['glow', 'Crimson Glow', 60, '#c0392b'],
            ['glow', 'Ocean Glow', 60, '#2471a3'],
            ['glow', 'Gold Glow', 120, '#b7950b'],
        ];
    }

    // ---------------- earn ----------------

    public static function onTopicCreated(int $tid): void
    {
        try {
            $pdo = Db::pdo();
            $st = $pdo->prepare('SELECT id, user_id FROM posts WHERE topic_id = ? ORDER BY id ASC LIMIT 1');
            $st->execute([$tid]);
            $row = $st->fetch();
            if (!$row || (int) $row['user_id'] <= 0) {
                return;
            }
            self::award((int) $row['user_id'], self::EARN_TOPIC, 'new_topic', (int) $row['id'], null);
        } catch (\Throwable $t) {
            error_log('forum-shop topic earn failed: ' . $t->getMessage());
        }
    }

    public static function onPostCreated(int $topicId): void
    {
        try {
            $pdo = Db::pdo();
            // Newest post in the topic nobody was paid for yet: each post pays
            // its real author exactly once, even if events race or repeat.
            $st = $pdo->prepare('SELECT p.id, p.user_id FROM posts p LEFT JOIN shop_ledger l ON l.post_id = p.id WHERE p.topic_id = ? AND l.id IS NULL ORDER BY p.id DESC LIMIT 1');
            $st->execute([$topicId]);
            $row = $st->fetch();
            if (!$row || (int) $row['user_id'] <= 0) {
                return;
            }
            self::award((int) $row['user_id'], self::EARN_REPLY, 'reply', (int) $row['id'], null);
        } catch (\Throwable $t) {
            error_log('forum-shop reply earn failed: ' . $t->getMessage());
        }
    }

    public static function maybeDaily(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        $me = Auth::user();
        if (!$me) {
            return;
        }
        // INSERT races on the daily unique key collapse to one win (23000);
        // the SELECT keeps the common already-paid case to one cheap query.
        try {
            $st = Db::pdo()->prepare("SELECT id FROM shop_ledger WHERE user_id = ? AND reason = 'daily' AND day = ?");
            $st->execute([(int) $me['id'], date('Y-m-d')]);
            if ($st->fetch()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        self::award((int) $me['id'], self::EARN_DAILY, 'daily', null, date('Y-m-d'));
    }

    /** Idempotent credit. Returns true when the balance moved. */
    public static function award(int $uid, int $delta, string $reason, ?int $postId = null, ?string $day = null): bool
    {
        if ($uid <= 0 || $delta === 0) {
            return false;
        }
        try {
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO shop_ledger (post_id, user_id, delta, reason, day, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$postId, $uid, $delta, $reason, $day, date('Y-m-d H:i:s')]);
            self::addBalance($pdo, $uid, $delta);
            $pdo->commit();
            unset(self::$balanceCache[$uid]);
            return true;
        } catch (\PDOException $e) {
            try {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
            }
            if ($e->getCode() === '23000') {
                return false; // already awarded
            }
            error_log('forum-shop award failed: ' . $e->getMessage());
            return false;
        } catch (\Throwable $t) {
            try {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
            }
            error_log('forum-shop award failed: ' . $t->getMessage());
            return false;
        }
    }

    private static function addBalance(PDO $pdo, int $uid, int $delta): void
    {
        $st = $pdo->prepare('UPDATE shop_wallet SET balance = balance + ? WHERE user_id = ?');
        $st->execute([$delta, $uid]);
        if ($st->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO shop_wallet (user_id, balance) VALUES (?, ?)')->execute([$uid, $delta]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $pdo->prepare('UPDATE shop_wallet SET balance = balance + ? WHERE user_id = ?')->execute([$delta, $uid]);
            }
        }
    }

    public static function balance(int $uid): int
    {
        if (isset(self::$balanceCache[$uid])) {
            return self::$balanceCache[$uid];
        }
        try {
            $st = Db::pdo()->prepare('SELECT balance FROM shop_wallet WHERE user_id = ?');
            $st->execute([$uid]);
            $b = (int) ($st->fetchColumn() ?: 0);
        } catch (\Throwable) {
            $b = 0;
        }
        return self::$balanceCache[$uid] = $b;
    }

    // ---------------- items / inventory ----------------

    /** @return array<int,array> */
    public static function allItems(): array
    {
        try {
            return Db::pdo()->query('SELECT * FROM shop_items ORDER BY price ASC, id ASC')->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,int> item_id => equipped */
    public static function ownedMap(int $uid): array
    {
        $out = [];
        try {
            $st = Db::pdo()->prepare('SELECT item_id, equipped FROM shop_inventory WHERE user_id = ?');
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $r) {
                $out[(int) $r['item_id']] = (int) $r['equipped'];
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    /**
     * Equipped flair for every poster on the current topic page, keyed by
     * username. One query per page; the footer script matches it to each
     * `.postbit[data-username]` in the DOM.
     * @return array<string,array{badges:array<int,string>,title:?string,glow:?string}>
     */
    public static function pageFlair(?string $path = null): array
    {
        if (self::$pageFlairCache !== null) {
            return self::$pageFlairCache;
        }
        $out = [];
        try {
            $path ??= self::baseStripped(Router::currentPath());
            if (!preg_match('#\.t(\d+)(?:/page-\d+)?$#', $path, $m)) {
                return self::$pageFlairCache = $out;
            }
            $st = Db::pdo()->prepare('SELECT DISTINCT u.username, i.kind, i.name, i.payload FROM posts p JOIN users u ON u.id = p.user_id JOIN shop_inventory v ON v.user_id = u.id AND v.equipped = 1 JOIN shop_items i ON i.id = v.item_id WHERE p.topic_id = ?');
            $st->execute([(int) $m[1]]);
            foreach ($st->fetchAll() as $r) {
                $u = (string) $r['username'];
                $out[$u] ??= ['badges' => [], 'title' => null, 'glow' => null];
                if ($r['kind'] === 'badge') {
                    $out[$u]['badges'][] = (string) $r['name'];
                } elseif ($r['kind'] === 'title' && $out[$u]['title'] === null) {
                    $out[$u]['title'] = (string) $r['name'];
                } elseif ($r['kind'] === 'glow' && $out[$u]['glow'] === null && preg_match('/^#[0-9a-f]{6}$/i', (string) $r['payload'])) {
                    $out[$u]['glow'] = (string) $r['payload'];
                }
            }
        } catch (\Throwable) {
        }
        return self::$pageFlairCache = $out;
    }

    /** Decorate each postbit in place: glow on the name, badges beside it, title under it. */
    public static function footerFlair(): void
    {
        $map = self::pageFlair();
        if (!$map) {
            return;
        }
        $json = json_encode($map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        if (!is_string($json) || $json === '') {
            return;
        }
        echo '<script>(function(d){document.querySelectorAll(\'.postbit[data-username]\').forEach(function(pb){var f=d[pb.dataset.username];if(!f)return;var left=pb.querySelector(\'.postleft\');if(!left)return;var pu=left.querySelector(\'.postuser\');if(!pu)return;if(f.glow){var a=pu.querySelector(\'a\');if(a)a.style.color=f.glow;}if(f.badges&&f.badges.length){var fr=document.createDocumentFragment();f.badges.forEach(function(b,i){if(i>0)fr.appendChild(document.createTextNode(\' \'));var s=document.createElement(\'span\');s.className=\'shop-badge\';s.textContent=\'\\u2605\';s.title=b;if(f.glow)s.style.color=f.glow;fr.appendChild(s);});pu.insertBefore(fr,pu.firstChild);}if(f.title){var t=document.createElement(\'div\');t.className=\'shop-title\';t.textContent=f.title;var g=left.querySelector(\'.group\');if(g)g.before(t);else pu.after(t);}});})(' . $json . ');</script>';
    }

    /** @return array{ok:bool,error?:string} */
    public static function buy(int $uid, int $itemId): array
    {
        if ($uid <= 0) {
            return ['ok' => false, 'error' => 'Log in to buy flair.'];
        }
        try {
            $pdo = Db::pdo();
            $st = $pdo->prepare('SELECT price FROM shop_items WHERE id = ?');
            $st->execute([$itemId]);
            $row = $st->fetch();
            if (!$row) {
                return ['ok' => false, 'error' => 'No such item.'];
            }
            $price = max(0, (int) $row['price']);
            $st = $pdo->prepare('SELECT id FROM shop_inventory WHERE user_id = ? AND item_id = ?');
            $st->execute([$uid, $itemId]);
            if ($st->fetch()) {
                return ['ok' => false, 'error' => 'You already own this.'];
            }
            $pdo->beginTransaction();
            // Atomic deduct: exactly one concurrent buyer can win the funds.
            $st = $pdo->prepare('UPDATE shop_wallet SET balance = balance - ? WHERE user_id = ? AND balance >= ?');
            $st->execute([$price, $uid, $price]);
            if ($st->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Not enough Credits.'];
            }
            try {
                $pdo->prepare('INSERT INTO shop_inventory (user_id, item_id, equipped) VALUES (?,?,0)')->execute([$uid, $itemId]);
                $pdo->prepare("INSERT INTO shop_ledger (post_id, user_id, delta, reason, day, created_at) VALUES (NULL,?,?,'purchase',NULL,?)")
                    ->execute([$uid, -$price, date('Y-m-d H:i:s')]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $pdo->rollBack();
                self::addBalance($pdo, $uid, $price); // refund the lost race
                unset(self::$balanceCache[$uid]);
                return ['ok' => false, 'error' => 'You already own this.'];
            }
            $pdo->commit();
            unset(self::$balanceCache[$uid]);
            self::$pageFlairCache = null;
            return ['ok' => true];
        } catch (\Throwable $t) {
            error_log('forum-shop buy failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Purchase failed.'];
        }
    }

    /** @return array{ok:bool,error?:string} */
    public static function equip(int $uid, int $itemId): array
    {
        if ($uid <= 0) {
            return ['ok' => false, 'error' => 'Log in first.'];
        }
        try {
            $pdo = Db::pdo();
            $st = $pdo->prepare('SELECT i.kind, v.equipped FROM shop_inventory v JOIN shop_items i ON i.id = v.item_id WHERE v.user_id = ? AND v.item_id = ?');
            $st->execute([$uid, $itemId]);
            $row = $st->fetch();
            if (!$row) {
                return ['ok' => false, 'error' => 'You do not own this.'];
            }
            $pdo->beginTransaction();
            if ((int) $row['equipped'] === 1) {
                $pdo->prepare('UPDATE shop_inventory SET equipped = 0 WHERE user_id = ? AND item_id = ?')->execute([$uid, $itemId]);
                $msg = 'Unequipped.';
            } else {
                $pdo->prepare('UPDATE shop_inventory SET equipped = 0 WHERE user_id = ? AND item_id IN (SELECT id FROM shop_items WHERE kind = ?)')->execute([$uid, (string) $row['kind']]);
                $pdo->prepare('UPDATE shop_inventory SET equipped = 1 WHERE user_id = ? AND item_id = ?')->execute([$uid, $itemId]);
                $msg = 'Equipped.';
            }
            $pdo->commit();
            self::$pageFlairCache = null;
            return ['ok' => true, 'error' => $msg];
        } catch (\Throwable $t) {
            error_log('forum-shop equip failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Could not change flair.'];
        }
    }

    // ---------------- admin ----------------

    /** @return array{ok:bool,error?:string} */
    public static function addItem(string $name, string $kind, int $price, string $payload): array
    {
        $name = trim($name);
        if (!in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'error' => 'Kind must be badge, title or glow.'];
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            return ['ok' => false, 'error' => 'Name must be 2–60 characters.'];
        }
        if ($price < 0 || $price > 100000) {
            return ['ok' => false, 'error' => 'Price must be 0–100000.'];
        }
        $payload = trim($payload);
        if ($kind === 'glow') {
            if (!preg_match('/^#[0-9a-f]{6}$/i', $payload)) {
                return ['ok' => false, 'error' => 'Glow needs a hex color like #c0392b.'];
            }
        } else {
            $payload = '';
        }
        try {
            Db::pdo()->prepare('INSERT INTO shop_items (kind, name, price, payload) VALUES (?,?,?,?)')->execute([$kind, $name, $price, $payload]);
            return ['ok' => true];
        } catch (\Throwable $t) {
            error_log('forum-shop addItem failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Could not add item.'];
        }
    }

    /** @return array{ok:bool,error?:string} */
    public static function grant(string $username, int $amount): array
    {
        if ($amount === 0 || abs($amount) > 100000) {
            return ['ok' => false, 'error' => 'Amount must be ±1–100000.'];
        }
        try {
            $u = \RetroBB\Models\User::findByUsername(trim($username));
            if (!$u) {
                return ['ok' => false, 'error' => 'No such user.'];
            }
            return self::award((int) $u['id'], $amount, 'grant') ? ['ok' => true] : ['ok' => false, 'error' => 'Grant failed.'];
        } catch (\Throwable $t) {
            error_log('forum-shop grant failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Grant failed.'];
        }
    }

    /** @return array<int,array> */
    public static function ledgerFor(int $uid, int $limit = 25): array
    {
        try {
            $st = Db::pdo()->prepare('SELECT delta, reason, created_at FROM shop_ledger WHERE user_id = ? ORDER BY id DESC LIMIT ?');
            $st->bindValue(1, $uid, PDO::PARAM_INT);
            $st->bindValue(2, max(1, $limit), PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array> */
    public static function recentLedger(int $limit = 20): array
    {
        try {
            $st = Db::pdo()->prepare('SELECT l.delta, l.reason, l.created_at, u.username FROM shop_ledger l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT ?');
            $st->bindValue(1, max(1, $limit), PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    // ---------------- display hooks ----------------

    public static function headCss(): void
    {
        echo '<style>' .
            '.shopbar{text-align:center;font-size:12px;padding:4px 8px;background:var(--bar-bg);border-bottom:1px solid var(--border);color:var(--text)}' .
            '.shopbar a{color:var(--link)}' .
            '.shop-badge{font-size:11px;font-weight:bold}' .
            '.shop-title{font-size:11px;font-style:italic;color:var(--muted)}' .
            '.shop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px;margin-top:8px}' .
            '.shop-card{border:1px solid var(--border);background:var(--row);padding:8px 10px}' .
            '.shop-card .price{color:var(--muted);font-size:11px}' .
            '.shop-card .swatch{display:inline-block;width:12px;height:12px;border:1px solid var(--border);vertical-align:-1px}' .
            '.shopform label{display:block;margin:6px 0;font-size:12px}' .
            '.shopform input,.shopform select{background:var(--input-bg);color:var(--input-text);border:1px solid var(--border);padding:4px 6px;font:inherit}' .
            '</style>';
    }

    public static function headerChip(): void
    {
        self::maybeDaily();
        $me = Auth::user();
        if (!$me) {
            return;
        }
        $bal = self::balance((int) $me['id']);
        echo '<div class="shopbar">💰 <b>' . (int) $bal . ' Credits</b> · <a href="' . e(url('/shop')) . '">Shop</a> · <a href="' . e(url('/wallet')) . '">Wallet</a></div>';
    }

    // ---------------- routing (boot hook) ----------------

    public static function route(): void
    {
        $path = self::baseStripped(Router::currentPath());
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($path === '/shop' && $method === 'GET') {
            $me = Auth::user();
            $uid = $me ? (int) $me['id'] : 0;
            self::page('shop', [
                'items' => self::allItems(),
                'owned' => $uid > 0 ? self::ownedMap($uid) : [],
                'balance' => $uid > 0 ? self::balance($uid) : 0,
            ], 'Shop');
        } elseif ($path === '/shop/buy' && $method === 'POST') {
            $me = Auth::user();
            if (!$me) {
                redirect('/login?next=' . urlencode('/shop'));
            }
            if (!Csrf::verify($_POST['csrf'] ?? null)) {
                http_response_code(419);
                echo 'CSRF mismatch';
                self::stop();
                return;
            }
            $r = self::buy((int) $me['id'], (int) ($_POST['item_id'] ?? 0));
            self::flash($r['ok'] ? 'Purchase complete — equip it below.' : (string) ($r['error'] ?? 'Failed.'), $r['ok']);
            redirect(url('/shop'));
        } elseif ($path === '/shop/equip' && $method === 'POST') {
            $me = Auth::user();
            if (!$me) {
                redirect('/login?next=' . urlencode('/shop'));
            }
            if (!Csrf::verify($_POST['csrf'] ?? null)) {
                http_response_code(419);
                echo 'CSRF mismatch';
                self::stop();
                return;
            }
            $r = self::equip((int) $me['id'], (int) ($_POST['item_id'] ?? 0));
            self::flash((string) ($r['error'] ?? 'Done.'), $r['ok']);
            redirect(url('/shop'));
        } elseif ($path === '/wallet' && $method === 'GET') {
            $me = Auth::user();
            if (!$me) {
                redirect('/login?next=' . urlencode('/wallet'));
            }
            $uid = (int) $me['id'];
            self::page('wallet', [
                'balance' => self::balance($uid),
                'ledger' => self::ledgerFor($uid),
            ], 'Wallet');
        } elseif ($path === '/shop/admin') {
            if (!Auth::isAdmin()) {
                http_response_code(403);
                echo 'Forbidden';
                self::stop();
                return;
            }
            if ($method === 'POST') {
                if (!Csrf::verify($_POST['csrf'] ?? null)) {
                    http_response_code(419);
                    echo 'CSRF mismatch';
                    self::stop();
                    return;
                }
                $action = (string) ($_POST['shop_action'] ?? '');
                if ($action === 'add_item') {
                    $r = self::addItem((string) ($_POST['name'] ?? ''), (string) ($_POST['kind'] ?? ''), (int) ($_POST['price'] ?? 0), (string) ($_POST['payload'] ?? ''));
                } elseif ($action === 'grant') {
                    $r = self::grant((string) ($_POST['username'] ?? ''), (int) ($_POST['amount'] ?? 0));
                } else {
                    $r = ['ok' => false, 'error' => 'Unknown action.'];
                }
                self::flash($r['ok'] ? 'Saved.' : (string) ($r['error'] ?? 'Failed.'), $r['ok']);
                redirect(url('/shop/admin'));
            }
            self::page('admin', [
                'items' => self::allItems(),
                'ledger' => self::recentLedger(),
            ], 'Shop admin');
        }
        // Not ours — fall through to the core router.
    }

    private static function baseStripped(string $path): string
    {
        $bp = base_path();
        if ($bp !== '' && ($path === $bp || str_starts_with($path, $bp . '/'))) {
            $path = substr($path, strlen($bp)) ?: '/';
        }
        return $path;
    }

    /** @param array<string,mixed> $vars */
    public static function page(string $view, array $vars, string $title): void
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require FORUMSHOP_DIR . '/views/' . $view . '.php';
        $content = (string) ob_get_clean();
        $pageTitle = $title . ' — ' . board_name();
        $layout = FORUMSHOP_ROOT . '/views/layout.php';
        if (is_file($layout)) {
            require $layout;
        } else {
            echo $content; // bare context (tests): no layout available
        }
        self::stop();
    }

    private static function stop(): void
    {
        if (!defined('FORUMSHOP_NO_EXIT')) {
            exit;
        }
    }

    private static function flash(string $msg, bool $ok = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        }
    }
}
