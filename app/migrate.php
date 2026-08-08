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
  display_name TEXT NOT NULL,         -- nome mostrato nell'header e nella pagina utenti
  role TEXT NOT NULL DEFAULT 'user',  -- 'admin' (può scrivere) oppure 'user' (sola lettura)
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
");

# Utenti già esistenti creati prima dell'introduzione dei ruoli: aggiunge la colonna
# se manca (CREATE TABLE IF NOT EXISTS non tocca lo schema di una tabella già presente).
$hasRoleColumn = false;
foreach ($pdo->query("PRAGMA table_info(users)") as $col) {
    if ($col['name'] === 'role') { $hasRoleColumn = true; break; }
}
if (!$hasRoleColumn) {
    $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
}

# Seed utente di default, come unico amministratore
$stmt = $pdo->prepare("INSERT OR IGNORE INTO users(username, password_hash, display_name, role) VALUES (?, ?, ?, 'admin')");
$stmt->execute(['admin', password_hash('admin2026', PASSWORD_DEFAULT), 'Default']);

# Promuove l'account admin anche se la riga esisteva già da prima dei ruoli
$pdo->exec("UPDATE users SET role = 'admin' WHERE username = 'admin'");

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
