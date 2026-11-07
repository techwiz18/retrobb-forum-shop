# forum-shop — Credits + flair shop for RetroBB

First community plugin for [RetroBB](https://github.com/techwiz18/retrobb).
Drop the folder into `plugins/` as `forum-shop` and enable it in
AdminCP → Plugins. No core changes required.

## What it does

- **Earn Credits**: +10 per new topic, +5 per reply, +2 daily login bonus.
  Rates are global by default, overridable per forum; the currency name is
  renameable. All editable on `/shop/admin`.
- **Spend** in `/shop`: badges, custom titles, username glows — woven into
  each postbit (glow on the name, badge beside it, title under it) via
  server-side hooks. Flair follows names onto the memberlist, profiles, and
  the board index.
- **Wallet** at `/wallet`: balance + transaction history + sending gifts to
  other members.
- **Admin** at `/shop/admin` (admins only): add items, grant Credits, recent
  transactions.

## How it works on the new plugin APIs

- **Routes** (`routes` action, RetroBB #19): `/shop`, `/shop/buy`, `/shop/equip`,
  `/wallet`, `/shop/admin` register with core routing — canonical-host 301s,
  method matching and the shared 404 come free. No more `boot`+`exit`.
- **Display** (#17): Shop/Wallet links via `nav_links`, balance chip via
  `userbox`, wallet card on profiles via `profile_after`.
- **Postbit** (#18): badges + name glow via the `post_username_html` filter,
  title via the `postbit_user` template hook — all server-side, no JS.
- **Lifecycle** (#20): tables live in `migrations/001_shop.sql` (applied once
  by `bin/migrate.php`); defaults seed on `plugin_enabled`; disable keeps all
  data. Earn rates are plugin settings, editable on `/shop/admin`.
- **Earn events**: `post_created` now passes post + author ids (direct award,
  ledger-deduped); legacy single-arg calls still work via re-query fallback.

## Earn rates

Constants at the top of `src/Shop.php` (`EARN_TOPIC`, `EARN_REPLY`,
`EARN_DAILY`).

## Test

Needs Docker only (no PHP/MySQL locally):

```sh
docker run --rm -v $PWD:/p -w /p composer:2 php tests/smoke.php
```

27 assertions against SQLite with stubbed core: earn idempotency, correct
author payout, daily-once, atomic buy, no double-buy/double-charge, equip
per-kind, route fall-through, admin gate, CSRF refusal, grant validation.

## Known limits

Adopted the new core APIs (RetroBB #17–#20); those issues stay open pending
live confirmation on a real board.
