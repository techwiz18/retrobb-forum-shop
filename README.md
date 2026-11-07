# forum-shop — Credits + flair shop for RetroBB

First community plugin for [RetroBB](https://github.com/techwiz18/retrobb).
Drop the folder into `plugins/` as `forum-shop` and enable it in
AdminCP → Plugins. No core changes required.

## What it does

- **Earn Credits**: +10 per new topic, +5 per reply, +2 daily login bonus.
- **Spend** in `/shop`: badges, custom titles, username glows — shown under
  each postbit via the `postbit_after` template hook.
- **Wallet** at `/wallet`: balance + transaction history.
- **Admin** at `/shop/admin` (admins only): add items, grant Credits, recent
  transactions.

## How it works with stock hooks only

- Pages are served from the `boot` action (fires before the core router), so
  no route API is needed.
- Earn events reuse `topic_created` / `post_created`. `post_created` only
  passes the topic id, so the plugin pays the newest *unawarded* post in that
  topic — each post pays its real author exactly once, even if events race.
- Balances live in `shop_wallet`, every movement is journaled in
  `shop_ledger` (unique `post_id` + unique daily key = idempotent awards).
  Tables self-create in `bootstrap.php`; buys deduct atomically
  (`UPDATE … WHERE balance >= ?`).

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

## Known limits (filed as RetroBB core issues, not worked around here)

1. `boot`+`exit` routing bypasses core canonical-host enforcement on `/shop*`.
2. `post_created`/`topic_created` should pass post + author ids.
3. No profile-page or nav-row hooks (balance chip uses the `header` action).
4. No plugin install/migrate/settings API (tables self-create; no uninstall).
