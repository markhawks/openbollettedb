<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$pdo = db();

$pdo->exec("
CREATE TABLE IF NOT EXISTS utilities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,          -- luce, gas, acqua, tari, bonifica...
  name TEXT NOT NULL                  -- Etichetta umana
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS bills (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  utility_id INTEGER NOT NULL,
  period_start TEXT NOT NULL,         -- YYYY-MM-DD
  period_end   TEXT NOT NULL,         -- YYYY-MM-DD
  issue_date   TEXT,                  -- YYYY-MM-DD (opzionale)
  amount_total REAL NOT NULL,         -- importo totale
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY (utility_id) REFERENCES utilities(id) ON DELETE RESTRICT
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS bill_metrics (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bill_id INTEGER NOT NULL,
  key TEXT NOT NULL,                  -- kwh, smc, canone_rai, lettura_inizio, lettura_fine, ecc.
  value REAL NOT NULL,
  unit TEXT,                          -- kWh, Smc, EUR, mc...
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
);
");

$pdo->exec("
CREATE INDEX IF NOT EXISTS idx_bills_utility_period
ON bills(utility_id, period_start, period_end);
");

$pdo->exec("
CREATE INDEX IF NOT EXISTS idx_metrics_bill_key
ON bill_metrics(bill_id, key);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name TEXT NOT NULL,         -- nome dell'utenza mostrato nel menu di login
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
");

# Seed utente di default (un'unica utenza finché la multi-utenza non è implementata)
$stmt = $pdo->prepare("INSERT OR IGNORE INTO users(username, password_hash, display_name) VALUES (?, ?, ?)");
$stmt->execute(['admin', password_hash('admin2026', PASSWORD_DEFAULT), 'Default']);

# Seed utilities
$seed = [
  ['luce', 'Energia Elettrica'],
  ['gas',  'Gas'],
  ['acqua','Acqua'],
  ['tari', 'TARI'],
  ['bonifica','Consorzio di bonifica']
];

$stmt = $pdo->prepare("INSERT OR IGNORE INTO utilities(code,name) VALUES(?,?)");
foreach ($seed as $u) $stmt->execute($u);

echo "OK: migrazione completata.\n";
