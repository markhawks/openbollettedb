# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

OpenBolletteDB is a small, dependency-free PHP app for tracking household utility bills (electricity,
gas, water, TARI waste tax) in a local SQLite database. No framework, no Composer, no npm, no build
step, no test suite — plain PHP files served directly by Apache/PHP's built-in server. UI text and
comments are in Italian.

## Commands

- Run/recreate the DB schema and seed utilities: `php app/migrate.php` (creates
  `data/openbollettedb.sqlite` if missing; safe to re-run, uses `CREATE TABLE IF NOT EXISTS`).
- Local dev server: `php -S localhost:8000 -t .` from the project root, then open `http://localhost:8000/`.
- There is no lint, build, or test command in this repo.

## Architecture

**Routing**: there's no router. `index.php` reads `$_GET['u']` (utility code) and `require`s the
matching file from `pages/dashboard_{luce,gas,acqua,tari}.php` (or `dashboard_luce_gas.php` for the
combined `"luce gas"` view). Each `pages/dashboard_*.php` is a self-contained script: it calls `db()`
itself, runs its own queries, and prints its own HTML section — there's no shared controller or view
layer. `partials/header.php` and `partials/footer.php` wrap the page.

Non-obvious quirk: `pages/dashboard_*.php` is `require`d directly into `index.php`'s scope (not called
as a function), so the `$pdo` variable it creates is what the trailing TARI chart query at the bottom
of `index.php` reuses. Keep that variable name/scope if you touch either file.

**Data model** (see `app/migrate.php` for ground truth):
- `utilities`: id/code/name, seeded with `luce`, `gas`, `acqua`, `tari`, `bonifica`. `code` is the
  short string used everywhere as `?u=` in URLs and in `WHERE code = ?` lookups.
- `bills`: one row per bill/invoice — `utility_id`, `period_start`/`period_end`/`issue_date` (all
  `YYYY-MM-DD` text), `amount_total` (REAL), `notes`. FK `utility_id → utilities(id) ON DELETE RESTRICT`.
- `bill_metrics`: EAV-style key/value rows attached to a bill (`bill_id`, `key`, `value` REAL, `unit`).
  FK `bill_id → bills(id) ON DELETE CASCADE` — deleting a bill auto-deletes its metrics.
  Metric keys are per-utility conventions, not enforced by schema:
  - `luce`: `kwh`, `energy_price` (EUR/kWh), `commercial_fee` (EUR/mese), `canone_rai` (EUR)
  - `gas`: `smc`, `lettura_ini`, `lettura_fin`
  - `acqua`: `mc_start`, `mc_end`, `consumo_mc`, `mc_conguaglio`
  - `tari`: `data_fattura`, `periodo_competenza` (e.g. `"Q1 2023"`), `numero_fattura`, `tipo_avviso`,
    `raccolta_diff`
  - shared: `extra_adjust` (EUR, can be negative for a bonus/credit)
  When adding a new metric, follow this pattern: pick a short snake_case key, a unit string, and gate
  insertion on `$utilityCode === '...'` plus `is_numeric()` (see `new_bill.php`).
- `bill_readings`: optional intermediate meter readings for a bill — `bill_id`, `reading_date`
  (`YYYY-MM-DD`), `reading_value` (REAL, m³). FK `bill_id → bills(id) ON DELETE CASCADE`. Currently only
  used by `acqua` (form fields `reading_date[]`/`reading_value[]`, repeatable rows), unlike
  `bill_metrics` it's a plain one-row-per-reading list, not a per-bill key/value pivot — there's no
  `key` column because a bill can have many readings of the same "kind". `edit_bill.php` deletes and
  re-inserts all of a bill's rows on save, same pattern as `bill_metrics`.
- `users`: id/username/password_hash/display_name/role, seeded with a single `admin` account
  (`role = 'admin'`) by `app/migrate.php`. `role` is `'admin'` (read/write) or `'user'` (read-only) —
  see Auth below. No FK to anything else — every user sees the same `bills`/`bill_metrics`, there's no
  per-user data ownership, `role` only gates which actions a session is allowed to perform.

**DB access**: `app/db.php` exposes a single `db(): PDO` function opening
`data/openbollettedb.sqlite` with `PRAGMA foreign_keys = ON` and `PRAGMA journal_mode = WAL` — every
entry point calls `db()` itself (no shared connection/DI container). Keep these pragmas; don't remove
them. All files use plain PDO prepared statements, `PDO::FETCH_ASSOC`, and `declare(strict_types=1);`.
`function db()` is wrapped in `if (!function_exists('db')) { ... }` — required because `app/db.php` is
loaded via plain `require` (not `require_once`) from several entry points (`app/auth.php` and every
`pages/dashboard_*.php`), so on any request that touches both, the file is parsed/executed twice in the
same process; without the guard the second pass fatals with "Cannot redeclare function db()". The guard
must wrap the function textually inside the `if` (not `if (...) return;` before an unconditional
declaration) — PHP early-binds a function that's unconditionally reachable in the file regardless of a
preceding runtime `return`, so only nesting it inside the `if` defers binding to runtime.

**Auth**: `app/auth.php` (`require_login()`, `require_admin()`, `is_admin()`, `attempt_login()`,
`logout_user()`, `current_user()`) guards every entry point via PHP sessions. `attempt_login()` copies
`role` into `$_SESSION['user']['role']` at login time; `is_admin()` just checks that cached value, it
does not re-query the DB. `require_login()` — call as the very first statement (before any output) in
any new top-level script that only *reads* bill data. `require_admin()` (which calls `require_login()`
internally, then 403s with `die()` if `role !== 'admin'`) guards the four write entry points
(`new_bill.php`, `edit_bill.php`, `delete_bill.php`, `reset_year.php`) — use it instead of
`require_login()` for any new script that inserts/updates/deletes bills. `login.php`/`logout.php` are
the only unguarded routes. Because the role lives in the session, a role change made by an admin via
`account.php` only takes effect for the affected user on their *next* login — their current session
keeps the old role until they re-authenticate.

**Roles & `account.php`**: `users.role` is `'admin'` (read/write) or `'user'` (read-only); every user
sees the same bills, `role` only gates write access. `account.php` (linked from the "⚙️ Utente" item in
`partials/header.php`) has two parts: a self-service section, open to any logged-in user, to change
their own `display_name`/`password_hash` (updates `$_SESSION['user']['display_name']` in place so the
header reflects it without a re-login); and a `is_admin()`-gated user-management section where an admin
adds users, changes another user's role, resets another user's password, or deletes another user. Every
admin-only action there explicitly rejects `user_id === current_user()['id']` server-side — an admin
can't demote/reset/delete *themselves* from that table (self-service section is the only way to touch
your own row), which is what keeps at least one admin always able to log in without needing an
explicit "last admin" count check. `pages/dashboard_*.php` wrap every write control (`+ Nuova
bolletta`, ✏️, 🗑️, "Svuota anno") in `<?php if (is_admin()): ?>` — this is presentation only, the real
enforcement is `require_admin()` in the target scripts; keep both in sync when adding a new write
action.

**CRUD flow**:
- Create: `new_bill.php?u=<code>` — one big form whose visible fields switch on `$utilityCode` (see the
  `<?php if ($utilityCode === 'luce'): ?>` blocks); inserts into `bills` then loops inserting the
  relevant `bill_metrics` rows inside a transaction (`beginTransaction`/`commit`/`rollBack`). Also
  blocks a second bill for the same utility+month via a `strftime('%Y-%m', ...)` duplicate check
  (skipped for `acqua`, which isn't monthly). Client-side vanilla JS computes derived fields (gas `smc`
  from lettura ini/fin, acqua `consumo_mc` from mc_start/mc_end, and the year/month quick-picker that
  sets `period_start`/`period_end`).
  - `edit_bill.php` mirrors this: loads the existing bill + metrics, deletes all existing
    `bill_metrics` for that bill on submit, and re-inserts the relevant ones for that utility (same
    per-utility gating as `new_bill.php` — keep the two in sync when changing metric fields).
  - `delete_bill.php?id=&u=` deletes the `bills` row; `bill_metrics` and `bill_readings` cascade-delete
    via their FKs.
- Dashboards (`pages/dashboard_*.php`) join `bills` to `bill_metrics` with one `LEFT JOIN` per metric
  key (aliased `m1`, `m2`, ...) to pivot the EAV rows into columns, group bills by year in PHP, and
  render a Chart.js line chart (loaded from CDN in `index.php`) plus year-total summaries computed
  with correlated subqueries per metric key.

**Stray files**: `edit_bill.php-bak` and `index-09012026.php` are backup/dated copies of live files —
diff against them before assuming behavior lives only in the current file, but don't treat them as
active code.

## Conventions

- `declare(strict_types=1);` at the top of every PHP file.
- Dates stored and compared as `YYYY-MM-DD` text; use `strftime()`/`strtotime()`/`date()` as existing
  code does rather than introducing a DateTime dependency.
- Money/quantity inputs are validated with `is_numeric()` before casting to `(float)` and inserting.
- When adding a new utility or metric, update the seed list in `app/migrate.php`, the per-utility
  branches in `new_bill.php` and `edit_bill.php`, and add a `pages/dashboard_<code>.php` plus a case in
  `index.php`'s switch and a link in `partials/header.php`.
