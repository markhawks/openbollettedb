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

**DB access**: `app/db.php` exposes a single `db(): PDO` function opening
`data/openbollettedb.sqlite` with `PRAGMA foreign_keys = ON` and `PRAGMA journal_mode = WAL` — every
entry point calls `db()` itself (no shared connection/DI container). Keep these pragmas; don't remove
them. All files use plain PDO prepared statements, `PDO::FETCH_ASSOC`, and `declare(strict_types=1);`.

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
  - `delete_bill.php?id=&u=` deletes the `bills` row; `bill_metrics` cascade-delete via the FK.
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
