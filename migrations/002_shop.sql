-- forum-shop 002: idempotency + ledger lookup index.
-- Applied once via bin/migrate.php, tracked in plugin_migrations.
CREATE UNIQUE INDEX uniq_shop_items_kind_name ON shop_items (kind, name);
CREATE INDEX idx_shop_ledger_user ON shop_ledger (user_id);
