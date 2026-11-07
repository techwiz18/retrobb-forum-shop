-- forum-shop 002: idempotency + ledger lookup index.
-- Applied once via bin/migrate.php, tracked in plugin_migrations.
-- The delete heals boards that double-seeded before this fix: without it
-- the unique index below aborts the whole migrate run (MySQL 1062 is not
-- in the migrator's ignore list), and editing this file is safe either way
-- (it re-runs whole when unmarked, never when marked). Portable to SQLite.
DELETE FROM shop_items WHERE id NOT IN (SELECT id FROM (SELECT MIN(id) AS id FROM shop_items GROUP BY kind, name) AS keep);
CREATE UNIQUE INDEX uniq_shop_items_kind_name ON shop_items (kind, name);
CREATE INDEX idx_shop_ledger_user ON shop_ledger (user_id);
