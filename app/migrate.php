<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Questo comando può essere eseguito soltanto da CLI.');
}
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
  value TEXT NOT NULL,                -- testo per preservare anche date/codici; CAST nelle somme numeriche
  unit TEXT,                          -- kWh, Smc, EUR, mc...
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
);
");

# Le prime versioni dichiaravano value come REAL, pur usandola anche per date e
# identificativi testuali. SQLite convertiva quindi, per esempio, "00123" in 123.
# La migrazione a TEXT conserva fedelmente i nuovi valori e resta compatibile con
# i calcoli esistenti, nei quali SQLite converte automaticamente i numeri.
$metricValueType = null;
foreach ($pdo->query("PRAGMA table_info(bill_metrics)") as $col) {
    if ($col['name'] === 'value') {
        $metricValueType = strtoupper((string)$col['type']);
        break;
    }
}
if ($metricValueType !== 'TEXT') {
    $pdo->beginTransaction();
    try {
        $pdo->exec("ALTER TABLE bill_metrics RENAME TO bill_metrics_legacy");
        $pdo->exec("
            CREATE TABLE bill_metrics (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              bill_id INTEGER NOT NULL,
              key TEXT NOT NULL,
              value TEXT NOT NULL,
              unit TEXT,
              FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("
            INSERT INTO bill_metrics (id, bill_id, key, value, unit)
            SELECT id, bill_id, key, CAST(value AS TEXT), unit
            FROM bill_metrics_legacy
        ");
        $pdo->exec("DROP TABLE bill_metrics_legacy");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS bill_readings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bill_id INTEGER NOT NULL,
  reading_date TEXT NOT NULL,         -- YYYY-MM-DD
  reading_value REAL NOT NULL,        -- lettura contatore in m3
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
);
");

$pdo->exec("
CREATE INDEX IF NOT EXISTS idx_bills_utility_period
ON bills(utility_id, period_start, period_end);
");

$pdo->exec("
CREATE INDEX IF NOT EXISTS idx_readings_bill
ON bill_readings(bill_id, reading_date);
");

$pdo->exec("DROP INDEX IF EXISTS idx_metrics_bill_key");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_metrics_bill_key ON bill_metrics(bill_id, key)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name TEXT NOT NULL,         -- nome mostrato nell'header e nella pagina utenti
  role TEXT NOT NULL DEFAULT 'user',  -- 'admin' (può scrivere) oppure 'user' (sola lettura)
  session_version INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
");

# Utenti già esistenti creati prima dell'introduzione dei ruoli: aggiunge la colonna
# se manca (CREATE TABLE IF NOT EXISTS non tocca lo schema di una tabella già presente).
$hasRoleColumn = false;
$hasSessionVersionColumn = false;
foreach ($pdo->query("PRAGMA table_info(users)") as $col) {
    if ($col['name'] === 'role') $hasRoleColumn = true;
    if ($col['name'] === 'session_version') $hasSessionVersionColumn = true;
}
if (!$hasRoleColumn) {
    $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
}
if (!$hasSessionVersionColumn) {
    $pdo->exec("ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1");
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS login_attempts (
  identifier TEXT PRIMARY KEY,
  attempts INTEGER NOT NULL,
  first_attempt INTEGER NOT NULL,
  blocked_until INTEGER NOT NULL DEFAULT 0
)
");

$pdo->exec("DROP TRIGGER IF EXISTS prevent_duplicate_bill_insert");
$pdo->exec("DROP TRIGGER IF EXISTS prevent_duplicate_bill_update");
$duplicateRule = "
  SELECT CASE WHEN EXISTS (
    SELECT 1 FROM bills existing
    JOIN utilities u ON u.id = NEW.utility_id
    WHERE existing.utility_id = NEW.utility_id
      AND (%EXCLUDE%)
      AND (
        (u.code IN ('luce','gas') AND strftime('%Y-%m', existing.period_start) = strftime('%Y-%m', NEW.period_start))
        OR (u.code = 'bonifica' AND strftime('%Y', existing.period_start) = strftime('%Y', NEW.period_start))
        OR (u.code = 'tari'
            AND strftime('%Y', existing.period_start) = strftime('%Y', NEW.period_start)
            AND CAST((CAST(strftime('%m', existing.period_start) AS INTEGER) - 1) / 3 AS INTEGER)
                = CAST((CAST(strftime('%m', NEW.period_start) AS INTEGER) - 1) / 3 AS INTEGER))
      )
  ) THEN RAISE(ABORT, 'Esiste già una bolletta per questo periodo') END;
";
$pdo->exec("CREATE TRIGGER prevent_duplicate_bill_insert BEFORE INSERT ON bills BEGIN " . str_replace('%EXCLUDE%', '1=1', $duplicateRule) . " END");
$pdo->exec("CREATE TRIGGER prevent_duplicate_bill_update BEFORE UPDATE OF utility_id, period_start ON bills BEGIN " . str_replace('%EXCLUDE%', 'existing.id != OLD.id', $duplicateRule) . " END");

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
