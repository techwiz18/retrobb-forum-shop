<?php
declare(strict_types=1);

namespace ForumShop;

use PDO;
use RetroBB\Core\Auth;
use RetroBB\Core\Csrf;
use RetroBB\Core\Db;

/**
 * Forum currency ("Credits") + flair shop.
 *
 * Earn: new topic / reply / daily login bonus. Spend: badges, titles, glows
 * rendered server-side in the postbit (postbit_user + post_username_html).
 * Pages ride core routing (routes action); tables come from migrations/*.sql
 * and seed on plugin_enabled.
 */
class Shop
{
    /** Default earn rates; overridable via plugin settings on /shop/admin. */
    public const EARN_TOPIC = 10;
    public const EARN_REPLY = 5;
    public const EARN_DAILY = 2;

    public const KINDS = ['badge', 'title', 'glow'];

    /** @var array<int,int> per-request balance cache */
    private static array $balanceCache = [];
    /** @var array<int,array> per-request flair cache */
    private static array $flairCache = [];
    /** @var bool */
    private static bool $seeded = false;

    public static function resetCache(): void
    {
        self::$balanceCache = [];
        self::$flairCache = [];
        self::$seeded = false;
    }

    // ---------------- settings ----------------

    /** @return array{topic:int,reply:int,daily:int} */
    public static function rates(): array
    {
        return [
            'topic' => self::rate('earn_topic', self::EARN_TOPIC),
            'reply' => self::rate('earn_reply', self::EARN_REPLY),
            'daily' => self::rate('earn_daily', self::EARN_DAILY),
        ];
    }

    private static function rate(string $key, int $fallback): int
    {
        $v = function_exists('plugin_setting') ? plugin_setting('forum-shop', $key, '') : '';
        return $v !== '' ? max(0, min(100000, (int) $v)) : $fallback;
    }

    private static function setSetting(string $key, string $value): void
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('UPDATE settings SET `value` = ? WHERE `key` = ?');
        $st->execute([$value, 'forum-shop.' . $key]);
        if ($st->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)')->execute(['forum-shop.' . $key, $value]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
    }

    private static function delSetting(string $key): void
    {
        try {
            Db::pdo()->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['forum-shop.' . $key]);
        } catch (\Throwable $t) {
            error_log('forum-shop delSetting failed: ' . $t->getMessage());
        }
    }

    public static function currency(): string
    {
        $v = function_exists('plugin_setting') ? trim(plugin_setting('forum-shop', 'currency', '')) : '';
        if ($v === '' || mb_strlen($v) > 20) {
            return 'Credits';
        }
        return $v;
    }

    /** @return array{ok:bool,error?:string} */
    public static function saveRates(int $topic, int $reply, int $daily, string $currency): array
    {
        foreach (['topic' => $topic, 'reply' => $reply, 'daily' => $daily] as $k => $v) {
            if ($v < 0 || $v > 1000) {
                return ['ok' => false, 'error' => 'Rates must be 0–1000.'];
            }
        }
        $currency = trim($currency);
        if (mb_strlen($currency) > 20) {
            return ['ok' => false, 'error' => 'Currency name must be 1–20 characters.'];
        }
        try {
            self::setSetting('earn_topic', (string) $topic);
            self::setSetting('earn_reply', (string) $reply);
            self::setSetting('earn_daily', (string) $daily);
            if ($currency === '' || $currency === 'Credits') {
                self::delSetting('currency');
            } else {
                self::setSetting('currency', $currency);
            }
            return ['ok' => true];
        } catch (\Throwable $t) {
            error_log('forum-shop saveRates failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Could not save rates.'];
        }
    }

    /** Per-forum topic/reply override; falls back to the global rate. */
    public static function forumRate(int $fid, string $kind): int
    {
        if ($fid > 0 && in_array($kind, ['topic', 'reply'], true)) {
            $v = function_exists('plugin_setting') ? plugin_setting('forum-shop', "forum_{$fid}_{$kind}", '') : '';
            if ($v !== '' && (int) $v >= 0) {
                return max(0, min(1000, (int) $v));
            }
        }
        $r = self::rates();
        return $r[$kind] ?? 0;
    }

    /** @param array<mixed> $topics @param array<mixed> $replies @return array{ok:bool,error?:string} */
    public static function saveForumRates(array $topics, array $replies): array
    {
        try {
            foreach (['topic' => $topics, 'reply' => $replies] as $kind => $rows) {
                foreach ($rows as $fid => $v) {
                    $fid = (int) $fid;
                    if ($fid <= 0) {
                        continue;
                    }
                    $v = (int) $v;
                    if ($v < 0 || $v > 1000) {
                        return ['ok' => false, 'error' => 'Forum rates must be 0–1000.'];
                    }
                    if ($v === self::rates()[$kind]) {
                        self::delSetting("forum_{$fid}_{$kind}");
                    } else {
                        self::setSetting("forum_{$fid}_{$kind}", (string) $v);
                    }
                }
            }
            return ['ok' => true];
        } catch (\Throwable $t) {
            error_log('forum-shop saveForumRates failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Could not save forum rates.'];
        }
    }

    // ---------------- lifecycle ----------------

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

    /** Seed default items. Runs on plugin_enabled; also lazily on shop pages. */
    public static function seed(): void
    {
        try {
            $pdo = Db::pdo();
            $n = (int) $pdo->query('SELECT COUNT(*) FROM shop_items')->fetchColumn();
            if ($n > 0) {
                return;
            }
            $st = $pdo->prepare('INSERT INTO shop_items (kind, name, price, payload) VALUES (?,?,?,?)');
            foreach (self::defaultItems() as $d) {
                $st->execute($d);
            }
        } catch (\Throwable $t) {
            error_log('forum-shop seed failed: ' . $t->getMessage());
        }
    }

    public static function onPluginToggled(string $name, bool $enabled): void
    {
        if ($name !== basename(FORUMSHOP_DIR)) {
            return;
        }
        if ($enabled) {
            self::seed();
        }
        // Disable keeps all tables: re-enabling restores balances intact.
    }

    private static function ensureSeeded(): void
    {
        if (!self::$seeded) {
            self::$seeded = true;
            self::seed();
        }
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
            $forumId = (int) (\RetroBB\Models\Topic::find($tid)['forum_id'] ?? 0);
            self::award((int) $row['user_id'], self::forumRate($forumId, 'topic'), 'new_topic', (int) $row['id'], null);
        } catch (\Throwable $t) {
            error_log('forum-shop topic earn failed: ' . $t->getMessage());
        }
    }

    public static function onPostCreated(int $topicId, ?int $postId = null, ?int $userId = null): void
    {
        try {
            $rate = self::forumRate((int) (\RetroBB\Models\Topic::find($topicId)['forum_id'] ?? 0), 'reply');
            if ($postId !== null && $userId !== null) {
                if ($userId > 0) {
                    self::award($userId, $rate, 'reply', $postId, null);
                }
                return;
            }
            // Legacy core (post_created passed only the topic id): pay the
            // newest unawarded post so each post pays its real author once.
            $pdo = Db::pdo();
            $st = $pdo->prepare('SELECT p.id, p.user_id FROM posts p LEFT JOIN shop_ledger l ON l.post_id = p.id WHERE p.topic_id = ? AND l.id IS NULL ORDER BY p.id DESC LIMIT 1');
            $st->execute([$topicId]);
            $row = $st->fetch();
            if (!$row || (int) $row['user_id'] <= 0) {
                return;
            }
            self::award((int) $row['user_id'], $rate, 'reply', (int) $row['id'], null);
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
        self::award((int) $me['id'], self::rates()['daily'], 'daily', null, date('Y-m-d'));
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

    // ---------------- items / flair ----------------

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

    /** @return array{badges:array<int,string>,title:?string,glow:?string} */
    public static function flairFor(int $uid): array
    {
        $out = ['badges' => [], 'title' => null, 'glow' => null];
        if ($uid <= 0) {
            return $out;
        }
        if (isset(self::$flairCache[$uid])) {
            return self::$flairCache[$uid];
        }
        try {
            $st = Db::pdo()->prepare('SELECT i.kind, i.name, i.payload FROM shop_inventory v JOIN shop_items i ON i.id = v.item_id WHERE v.user_id = ? AND v.equipped = 1');
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $r) {
                if ($r['kind'] === 'badge') {
                    $out['badges'][] = (string) $r['name'];
                } elseif ($r['kind'] === 'title' && $out['title'] === null) {
                    $out['title'] = (string) $r['name'];
                } elseif ($r['kind'] === 'glow' && $out['glow'] === null && preg_match('/^#[0-9a-f]{6}$/i', (string) $r['payload'])) {
                    $out['glow'] = (string) $r['payload'];
                }
            }
        } catch (\Throwable) {
        }
        return self::$flairCache[$uid] = $out;
    }

    /**
     * post_username_html filter: glow on the name, nameless ★ badges left of
     * it (names survive as tooltips). Context may be a full row (user_id) or
     * a partial one (id) depending on the calling view.
     */
    public static function usernameFlair(string $html, array $post): string
    {
        $f = self::flairFor((int) ($post['user_id'] ?? $post['id'] ?? 0));
        if ($f['badges'] === [] && $f['glow'] === null) {
            return $html;
        }
        $out = '';
        foreach ($f['badges'] as $i => $b) {
            if ($i > 0) {
                $out .= ' ';
            }
            $out .= '<span class="shop-badge" title="' . e($b) . '"' . ($f['glow'] !== null ? ' style="color:' . e($f['glow']) . '"' : '') . '>★</span>';
        }
        if ($out !== '') {
            $out .= ' ';
        }
        if ($f['glow'] !== null) {
            $html = preg_replace('/<a /', '<a style="color:' . e($f['glow']) . '" ', $html, 1) ?? $html;
        }
        return $out . $html;
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
            unset(self::$balanceCache[$uid], self::$flairCache[$uid]);
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
            unset(self::$flairCache[$uid]);
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

    /**
     * Delete an item and strip it from all owners (no refunds — the Credits
     * were spent). Ledger history is kept.
     * @return array{ok:bool,error?:string}
     */
    public static function deleteItem(int $itemId): array
    {
        if ($itemId <= 0) {
            return ['ok' => false, 'error' => 'No such item.'];
        }
        try {
            $pdo = Db::pdo();
            $st = $pdo->prepare('SELECT id FROM shop_items WHERE id = ?');
            $st->execute([$itemId]);
            if (!$st->fetch()) {
                return ['ok' => false, 'error' => 'No such item.'];
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM shop_inventory WHERE item_id = ?')->execute([$itemId]);
            $pdo->prepare('DELETE FROM shop_items WHERE id = ?')->execute([$itemId]);
            $pdo->commit();
            self::$flairCache = [];
            return ['ok' => true];
        } catch (\Throwable $t) {
            error_log('forum-shop deleteItem failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Could not delete item.'];
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
            if ($amount < 0 && self::balance((int) $u['id']) < abs($amount)) {
                return ['ok' => false, 'error' => 'That user only has ' . self::balance((int) $u['id']) . ' ' . self::currency() . '.'];
            }
            $reason = $amount > 0 ? 'grant' : 'deduct';
            return self::award((int) $u['id'], $amount, $reason) ? ['ok' => true] : ['ok' => false, 'error' => 'Grant failed.'];
        } catch (\Throwable $t) {
            error_log('forum-shop grant failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Grant failed.'];
        }
    }

    /** @return array{items:int,circulation:int} */
    public static function stats(): array
    {
        try {
            $pdo = Db::pdo();
            $items = (int) $pdo->query('SELECT COUNT(*) FROM shop_items')->fetchColumn();
            $circ = (int) $pdo->query('SELECT COALESCE(SUM(balance),0) FROM shop_wallet')->fetchColumn();
            return ['items' => $items, 'circulation' => $circ];
        } catch (\Throwable) {
            return ['items' => 0, 'circulation' => 0];
        }
    }

    /** @return array{ok:bool,error?:string} */
    public static function gift(int $fromUid, string $toUsername, int $amount): array
    {
        if ($fromUid <= 0) {
            return ['ok' => false, 'error' => 'Log in to send gifts.'];
        }
        if ($amount < 1 || $amount > 100000) {
            return ['ok' => false, 'error' => 'Gift must be 1–100000.'];
        }
        try {
            $to = \RetroBB\Models\User::findByUsername(trim($toUsername));
            if (!$to || (int) $to['id'] <= 0) {
                return ['ok' => false, 'error' => 'No such user.'];
            }
            $toUid = (int) $to['id'];
            if ($toUid === $fromUid) {
                return ['ok' => false, 'error' => 'You cannot gift yourself.'];
            }
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            $st = $pdo->prepare('UPDATE shop_wallet SET balance = balance - ? WHERE user_id = ? AND balance >= ?');
            $st->execute([$amount, $fromUid, $amount]);
            if ($st->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Not enough ' . self::currency() . '.'];
            }
            self::addBalance($pdo, $toUid, $amount);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO shop_ledger (post_id, user_id, delta, reason, day, created_at) VALUES (NULL,?,?,\'gift_sent\',NULL,?)')->execute([$fromUid, -$amount, $now]);
            $pdo->prepare('INSERT INTO shop_ledger (post_id, user_id, delta, reason, day, created_at) VALUES (NULL,?,?,\'gift_received\',NULL,?)')->execute([$toUid, $amount, $now]);
            $pdo->commit();
            unset(self::$balanceCache[$fromUid], self::$balanceCache[$toUid]);
            return ['ok' => true];
        } catch (\Throwable $t) {
            error_log('forum-shop gift failed: ' . $t->getMessage());
            return ['ok' => false, 'error' => 'Gift failed.'];
        }
    }

    /** @return array<int,array> */
    public static function memberOptions(int $limit = 200): array
    {
        try {
            $st = Db::pdo()->prepare('SELECT username FROM users ORDER BY username ASC LIMIT ?');
            $st->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll();
        } catch (\Throwable) {
            return [];
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

    // ---------------- display ----------------

    public static function headCss(): void
    {
        echo '<style>' .
            '.shop-badge{font-size:11px;font-weight:bold}' .
            '.shop-ptitle{font-size:11px;color:var(--muted);order:3}' .
            '.shop-pbal{font-size:11px;color:var(--muted);order:6}' .
            '.postleft{display:flex;flex-direction:column;align-items:center;gap:2px}' .
            '.postleft .postuser{order:2}' .
            '.postleft .group{order:4}' .
            '.postleft .postmeta{order:5}' .
            '.shop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px;margin-top:8px}' .
            '.shop-card{border:1px solid var(--border);background:var(--row);padding:8px 10px}' .
            '.shop-card .price{color:var(--muted);font-size:11px}' .
            '.shop-card .swatch{display:inline-block;width:12px;height:12px;border:1px solid var(--border);vertical-align:-1px}' .
            '.shop-usercard{display:flex;gap:8px;align-items:center}' .
            '.shop-usercard .bal{font-size:15px;font-weight:bold}' .
            '.shopform label{display:block;margin:6px 0;font-size:12px}' .
            '.shopform input,.shopform select{background:var(--input-bg);color:var(--input-text);border:1px solid var(--border);padding:4px 6px;font:inherit}' .
            '.shop-anchors{margin:8px 0;font-size:12px}' .
            '.shopform .frow{display:flex;align-items:center;gap:8px;margin:6px 0}' .
            '.shopform .frow>label{width:150px;flex:none;margin:0}' .
            '.shop-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12px}' .
            '.shop-table th,.shop-table td{border:1px solid var(--border);padding:5px 8px;text-align:left}' .
            '.shop-table th{background:var(--bar-bg)}' .
            '.shop-table td.num,.shop-table th.num{text-align:right}' .
            '</style>';
    }

    // ---------------- routes ----------------

    /** Register with core routing: canonical-host 301s and the shared 404 come free. */
    public static function registerRoutes(object $r): void
    {
        $r->get('#^/shop$#', function () { Shop::shopPage(); });
        $r->post('#^/shop/buy$#', function () { Shop::buySubmit(); });
        $r->post('#^/shop/equip$#', function () { Shop::equipSubmit(); });
        $r->get('#^/wallet$#', function () { Shop::walletPage(); });
        $r->post('#^/wallet/gift$#', function () { Shop::giftSubmit(); });
        $r->get('#^/shop/admin$#', function () { Shop::adminPage(); });
        $r->post('#^/shop/admin$#', function () { Shop::adminSubmit(); });
    }

    public static function shopPage(): void
    {
        self::ensureSeeded();
        $me = Auth::user();
        $uid = $me ? (int) $me['id'] : 0;
        self::page('shop', [
            'items' => self::allItems(),
            'owned' => $uid > 0 ? self::ownedMap($uid) : [],
            'balance' => $uid > 0 ? self::balance($uid) : 0,
            'rates' => self::rates(),
            'currency' => self::currency(),
        ], 'Shop');
    }

    public static function buySubmit(): void
    {
        $me = Auth::user();
        if (!$me) {
            redirect('/login?next=' . urlencode('/shop'));
        }
        if (!Csrf::verify($_POST['csrf'] ?? null)) {
            http_response_code(419);
            echo 'CSRF mismatch';
            return;
        }
        $r = self::buy((int) $me['id'], (int) ($_POST['item_id'] ?? 0));
        self::flash($r['ok'] ? 'Purchase complete — equip it below.' : (string) ($r['error'] ?? 'Failed.'), $r['ok']);
        redirect(url('/shop'));
    }

    public static function equipSubmit(): void
    {
        $me = Auth::user();
        if (!$me) {
            redirect('/login?next=' . urlencode('/shop'));
        }
        if (!Csrf::verify($_POST['csrf'] ?? null)) {
            http_response_code(419);
            echo 'CSRF mismatch';
            return;
        }
        $r = self::equip((int) $me['id'], (int) ($_POST['item_id'] ?? 0));
        self::flash((string) ($r['error'] ?? 'Done.'), $r['ok']);
        redirect(url('/shop'));
    }

    public static function walletPage(): void
    {
        $me = Auth::user();
        if (!$me) {
            redirect('/login?next=' . urlencode('/wallet'));
        }
        $uid = (int) $me['id'];
        self::page('wallet', [
            'balance' => self::balance($uid),
            'ledger' => self::ledgerFor($uid),
            'rates' => self::rates(),
            'currency' => self::currency(),
            'to' => substr(trim((string) ($_GET['to'] ?? '')), 0, 50),
            'members' => self::memberOptions(),
        ], 'Wallet');
    }

    public static function giftSubmit(): void
    {
        $me = Auth::user();
        if (!$me) {
            redirect('/login?next=' . urlencode('/wallet'));
        }
        if (!Csrf::verify($_POST['csrf'] ?? null)) {
            http_response_code(419);
            echo 'CSRF mismatch';
            return;
        }
        $r = self::gift((int) $me['id'], (string) ($_POST['username'] ?? ''), (int) ($_POST['amount'] ?? 0));
        self::flash($r['ok'] ? 'Gift sent.' : (string) ($r['error'] ?? 'Failed.'), $r['ok']);
        redirect(url('/wallet'));
    }

    public static function adminPage(): void
    {
        if (!Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        self::ensureSeeded();
        self::page('admin', [
            'items' => self::allItems(),
            'ledger' => self::recentLedger(),
            'rates' => self::rates(),
            'currency' => self::currency(),
            'forums' => self::forumList(),
            'members' => self::memberOptions(),
        ], 'Shop admin');
    }

    public static function adminSubmit(): void
    {
        if (!Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        if (!Csrf::verify($_POST['csrf'] ?? null)) {
            http_response_code(419);
            echo 'CSRF mismatch';
            return;
        }
        $action = (string) ($_POST['shop_action'] ?? '');
        if ($action === 'add_item') {
            $r = self::addItem((string) ($_POST['name'] ?? ''), (string) ($_POST['kind'] ?? ''), (int) ($_POST['price'] ?? 0), (string) ($_POST['payload'] ?? ''));
        } elseif ($action === 'grant') {
            $amt = abs((int) ($_POST['amount'] ?? 0));
            if (($_POST['direction'] ?? 'give') === 'deduct') {
                $amt = -$amt;
            }
            $r = self::grant((string) ($_POST['username'] ?? ''), $amt);
        } elseif ($action === 'delete_item') {
            $r = self::deleteItem((int) ($_POST['item_id'] ?? 0));
        } elseif ($action === 'save_rates') {
            $r = self::saveRates((int) ($_POST['earn_topic'] ?? 0), (int) ($_POST['earn_reply'] ?? 0), (int) ($_POST['earn_daily'] ?? 0), (string) ($_POST['currency'] ?? ''));
            if ($r['ok']) {
                $fr = self::saveForumRates((array) ($_POST['rate_topic'] ?? []), (array) ($_POST['rate_reply'] ?? []));
                if (!$fr['ok']) {
                    $r = $fr;
                }
            }
        } else {
            $r = ['ok' => false, 'error' => 'Unknown action.'];
        }
        self::flash($r['ok'] ? 'Saved.' : (string) ($r['error'] ?? 'Failed.'), $r['ok']);
        redirect(url('/shop/admin'));
    }

    /** @return array<int,array{id:int,category:string,name:string}> */
    public static function forumList(): array
    {
        $out = [];
        try {
            foreach (\RetroBB\Models\Board::index() as $c) {
                foreach ($c['forums'] ?? [] as $f) {
                    $out[] = ['id' => (int) $f['id'], 'category' => (string) ($c['title'] ?? ''), 'name' => (string) $f['name']];
                }
            }
        } catch (\Throwable) {
        }
        return $out;
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
    }

    private static function flash(string $msg, bool $ok = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        }
    }
}
