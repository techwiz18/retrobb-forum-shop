-- forum-shop tables (RetroBB issue #20). Applied once via bin/migrate.php,
-- tracked in plugin_migrations. Seeding happens on plugin_enabled.
CREATE TABLE IF NOT EXISTS shop_wallet (
  user_id INT PRIMARY KEY,
  balance INT NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS shop_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  price INT NOT NULL DEFAULT 0,
  payload VARCHAR(200) NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS shop_inventory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  equipped INT NOT NULL DEFAULT 0,
  UNIQUE(user_id, item_id)
);
CREATE TABLE IF NOT EXISTS shop_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NULL UNIQUE,
  user_id INT NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(30) NOT NULL,
  day VARCHAR(10) NULL,
  created_at VARCHAR(32) NOT NULL,
  UNIQUE(user_id, reason, day)
);
