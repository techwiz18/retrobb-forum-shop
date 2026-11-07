<?php
declare(strict_types=1);
namespace RetroBB\Core {
    class Hooks {
        public static function add_action(string $h, callable $c, int $p = 10): void {}
        public static function add_filter(string $h, callable $c, int $p = 10): void {}
        public static function add_template_hook(string $l, string $f): void {}
    }
    class Db {
        public static ?\PDO $pdo = null;
        public static function pdo(): \PDO { return self::$pdo; }
    }
    class Auth {
        public static ?array $user = ['id' => 3, 'username' => 'root', 'user_group' => 'admin'];
        public static function user(): ?array { return self::$user; }
        public static function isAdmin(): bool { return true; }
    }
    class Csrf {
        public static function verify(?string $t): bool { return true; }
        public static function field(): string { return ''; }
    }
}
namespace RetroBB\Models {
    class User {
        public static function findByUsername(string $u): ?array
        {
            $s = \RetroBB\Core\Db::pdo()->prepare('SELECT * FROM users WHERE username = ?');
            $s->execute([$u]);
            return $s->fetch() ?: null;
        }
    }
}
namespace {
    function e(?string $s): string { return (string) $s; }
    function url(string $p): string { return $p; }
    function board_name(): string { return 'T'; }
    function base_path(): string { return ''; }
    function plugin_setting(string $a, string $b, string $c = ''): string { return $c; }
    function redirect(string $u, int $x = 302): void { echo "REDIRECT:$u\n"; exit; }
    use RetroBB\Core\Db;
    use ForumShop\Shop;
    Db::$pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    Db::$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(50))');
    Db::$pdo->exec('CREATE TABLE settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT NOT NULL)');
    $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', file_get_contents('migrations/001_shop.sql'));
    Db::$pdo->exec($sql);
    Db::$pdo->exec('INSERT INTO users (username) VALUES (\'bob\')');
    require 'bootstrap.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 't', 'shop_action' => 'grant', 'username' => 'bob', 'amount' => 40];
    $r = Shop::grant('bob', 40);
    var_dump($r, Shop::balance(2));
    print_r(Db::$pdo->query('SELECT * FROM shop_ledger')->fetchAll());
    Shop::adminSubmit();
}
