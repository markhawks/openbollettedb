<!-- .github/copilot-instructions.md for OpenBolletteDB -->
# OpenBolletteDB — Quick guidance for AI coding agents

Be brief and concrete. This repo is a small PHP app that stores utility bills in a local SQLite DB.
Target output: small, tested edits to PHP, SQL, HTML, or CSS. Avoid broad architectural rewrites.

- Entry points: `index.php`, `new_bill.php`, `edit_bill.php`, `delete_bill.php`.
- DB wiring: `app/db.php` returns a PDO SQLite connection to `data/openbollettedb.sqlite`.
- Migrations & schema: `app/migrate.php` creates tables (`utilities`, `bills`, `bill_metrics`) and seeds `utilities`.

Key project patterns to follow
- Utility codes: short strings used in URLs and queries (examples: `luce`, `gas`). See `index.php` and `new_bill.php` where `$_GET['u'] ?? 'luce'` is used.
- Bills + metrics model: `bills` holds main rows; `bill_metrics` stores key/value metric rows for a bill (keys: `kwh`, `canone_rai`, `smc`, `lettura_ini`, `lettura_fin`). Use the same keys/units if adding metrics.
- DB constraints: `bill_metrics.bill_id` has `ON DELETE CASCADE`; `bills.utility_id` has `ON DELETE RESTRICT`. Be careful when deleting or changing FK behavior.
- SQL style: plain PDO prepared statements and small helper functions. Use `db()` from `app/db.php` to obtain the PDO instance.
- Date format: strings are stored as `YYYY-MM-DD` (see `migrate.php` column comments and code in forms).

Developer workflows (how to run common tasks)
- Run migrations / (re)create DB and seed utilities:
  - from project root: `php app/migrate.php` (creates `data/openbollettedb.sqlite` if missing)
- Quick local dev server:
  - from project root: `php -S localhost:8000 -t .` then open `http://localhost:8000/`

Project conventions and gotchas
- Strict types enabled in PHP files (`declare(strict_types=1);`) — keep type-safe code when possible.
- SQLite pragmas are enabled in `app/db.php` (`foreign_keys = ON`, `journal_mode = WAL`). Do not remove them.
- Numeric fields are stored as `REAL`; inputs are validated with `is_numeric()` in `new_bill.php`/`edit_bill.php` before INSERT/UPDATE.
- UI/UX: `new_bill.php` contains vanilla JS year/month selectors that set the `period_start`/`period_end` fields. If you modify those inputs, keep the JS in sync.

Where to look for examples
- Adding a metric on bill creation: `new_bill.php` shows how metrics are collected and inserted (see `INSERT INTO bill_metrics ...`).
- Updating metrics: `edit_bill.php` deletes existing metrics for the bill and re-inserts the relevant ones for a utility (LUCE example).
- Listing with joins: `index.php` demonstrates `LEFT JOIN` of `bill_metrics` to show `kwh` and `canone_rai` alongside bills.

Safety and small-change rules for AI agents
- Prefer small, testable changes: one file or SQL change per PR. Run `php app/migrate.php` locally when changing schema.
- Do not change the DB path (`data/openbollettedb.sqlite`) without updating `app/db.php` and documenting the reason.
- Preserve `ON DELETE` semantics unless the change is explicitly requested and tested.
- When adding new `bill_metrics` keys, add or reference units (e.g., `kWh`, `Smc`, `EUR`)—the UI expects those conventions.

If you need more context
- Open `app/migrate.php` to see the exact schema and seeded utilities. Use it as the ground truth for migrations.
- Inspect `index.php`, `new_bill.php`, and `edit_bill.php` for typical SQL and form handling patterns.

If any guidance above is unclear or you'd like additional examples (e.g., how to add a new metric type or a new utility), say which part to expand.
