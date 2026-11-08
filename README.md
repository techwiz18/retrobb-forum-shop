# forum-shop — currency + flair shop for RetroBB

First community plugin for [RetroBB](https://github.com/techwiz18/retrobb).
Members earn currency for posting and spend it on flair (badges, custom
titles, username glows) shown everywhere names render.

## Requirements

A RetroBB board with the plugin APIs (routes, display hooks, `username_html`,
plugin migrations + lifecycle). If AdminCP → Plugins exists, you have them.

## Install

1. Back up your board (DB dump + `config.php`).
2. Download `forum-shop-0.2.0.zip` from the
   [releases page](https://github.com/techwiz18/retrobb-forum-shop/releases)
   and extract it so it lands at `plugins/forum-shop/` (one folder — keeping
   the name preserves existing data and settings across updates).
3. AdminCP → Database updates (applies `migrations/*`), then AdminCP →
   Plugins → enable. Enabling seeds the 8 starter items.
4. Visit `/shop`.

## What it does

- **Earn**: per-topic / per-reply rates (global defaults + per-forum
  overrides), daily login bonus, renameable currency. All editable on
  `/shop/admin`; rates ship at +10 / +5 / +2 "Credits".
- **Spend** in `/shop`: badges, titles, glows. Glow colors the name, ★
  badges sit beside it, titles sit under it — memberlist, profiles, board
  index, PMs, and alerts included, all server-side.
- **Wallet** at `/wallet`: balance + transaction history + sending gifts to
  other members (username autocomplete, no self-gifts).
- **Admin**: item CRUD, give/deduct Credits with a balance floor, earn-rate
  and per-forum-rate forms, recent-transaction ledger, plus a dashboard card
  and an AdminCP nav entry.
- **Lifecycle**: disabling keeps all data (re-enable restores everything);
  super-admin Uninstall (confirmed) drops the tables and settings.

## How it works

- **Routes** (`routes` action): `/shop`, `/shop/buy`, `/shop/equip`,
  `/wallet`, `/wallet/gift`, `/shop/admin` register with core routing.
- **Display**: Shop/Wallet links via `nav_links`, balance chip via `userbox`,
  wallet card on profiles via `profile_after`, admin spots via `admin_nav` +
  `admin_dashboard`.
- **Flair**: badges + name glow via the `post_username_html` filter, title
  + balance via the `postbit_user` template hook.
- **Earn events**: `post_created` passes post + author ids (direct award,
  ledger-deduped); legacy single-arg calls still work via re-query fallback.
- **Money rules**: every movement is journaled in `shop_ledger` (unique
  post/day keys make awards idempotent); buys deduct atomically; gifts move
  both sides in one transaction.

## Test

Needs Docker only (no PHP/MySQL locally):

```sh
docker run --rm -v $PWD:/p -w /p composer:2 php tests/smoke.php
```

79 checks against SQLite with stubbed core: earn idempotency, correct-author
payout, daily-once, configurable + per-forum rates, atomic buy, free-item
buy, no double-buy/double-charge, equip per-kind, gift flows + refusals,
route registration, admin gates, CSRF refusal, delete cascade, uninstall
drop, migration self-heal.
