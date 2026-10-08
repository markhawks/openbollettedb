<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo comando può essere eseguito soltanto da CLI.\n");
}

require __DIR__ . '/db.php';
require __DIR__ . '/importers/octopus_electricity.php';

$options = getopt('', ['year:', 'prepare', 'commit', 'help']);
if (isset($options['help'])) {
    echo "Uso: php app/import_octopus.php --year=AAAA [--prepare] [--commit]\n";
    exit(0);
}

$year = isset($options['year']) ? (int)$options['year'] : (int)date('Y');
if ($year < 2000 || $year > (int)date('Y') + 5) {
    fwrite(STDERR, "Anno non valido: $year.\n");
    exit(1);
}

$baseDir = dirname(__DIR__) . "/data/imports/luce/$year";
$incomingDir = "$baseDir/da_elaborare";
$processedDir = "$baseDir/importate";
$errorDir = "$baseDir/errori";
foreach ([$incomingDir, $processedDir, $errorDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        fwrite(STDERR, "Impossibile creare la cartella: $directory\n");
        exit(1);
    }
}

if (isset($options['prepare'])) {
    echo "Struttura pronta: $baseDir\n";
    exit(0);
}

$pdftotext = null;
foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
    if (is_executable($candidate)) {
        $pdftotext = $candidate;
        break;
    }
}
if ($pdftotext === null) {
    fwrite(STDERR, "pdftotext non trovato. Su Fedora installa il pacchetto poppler-utils.\n");
    exit(2);
}

$pdfFiles = glob($incomingDir . '/*.{pdf,PDF}', GLOB_BRACE) ?: [];
sort($pdfFiles, SORT_NATURAL | SORT_FLAG_CASE);
if ($pdfFiles === []) {
    echo "Nessun PDF da elaborare in $incomingDir\n";
    exit(0);
}

$commit = isset($options['commit']);
$pdo = db();
$utilityStmt = $pdo->query("SELECT id FROM utilities WHERE code = 'luce'");
$utilityId = (int)($utilityStmt->fetchColumn() ?: 0);
if ($utilityId <= 0) {
    fwrite(STDERR, "Utenza Luce non trovata. Esegui prima php app/migrate.php.\n");
    exit(1);
}

$insertBill = $pdo->prepare('INSERT INTO bills (utility_id, issue_date, period_start, period_end, amount_total, notes) VALUES (?, ?, ?, ?, ?, ?)');
$insertMetric = $pdo->prepare('INSERT INTO bill_metrics (bill_id, `key`, value, unit) VALUES (?, ?, ?, ?)');
$upsertMetric = $pdo->prepare('INSERT INTO bill_metrics (bill_id, `key`, value, unit) VALUES (?, ?, ?, ?) ON CONFLICT(bill_id, `key`) DO UPDATE SET value = excluded.value, unit = excluded.unit');
$hashCheck = $pdo->prepare("SELECT bill_id FROM bill_metrics WHERE `key` = 'import_source_sha256' AND value = ? LIMIT 1");
$monthCheck = $pdo->prepare("
    SELECT b.id, b.notes,
           EXISTS(SELECT 1 FROM bill_metrics sm WHERE sm.bill_id = b.id AND sm.key = 'stima' AND sm.value = '1') AS is_estimate
    FROM bills b
    WHERE b.utility_id = ? AND strftime('%Y-%m', b.period_start) = substr(?, 1, 7)
    LIMIT 1
");
$updateEstimatedBill = $pdo->prepare('UPDATE bills SET issue_date = ?, period_start = ?, period_end = ?, amount_total = ?, notes = ? WHERE id = ?');
$deleteEstimateFlag = $pdo->prepare("DELETE FROM bill_metrics WHERE bill_id = ? AND `key` = 'stima'");
$imported = 0;
$failed = 0;

foreach ($pdfFiles as $pdfPath) {
    $fileName = basename($pdfPath);
    $command = escapeshellarg($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' - 2>/dev/null';
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    $text = implode("\n", $lines);
    $parsed = $exitCode === 0 && trim($text) !== ''
        ? parseOctopusElectricityText($text, $fileName)
        : ['errors' => ['Impossibile estrarre testo dal PDF: potrebbe essere una scansione OCR.']];

    if (isset($parsed['period_start']) && $parsed['period_start'] !== null
        && (int)substr((string)$parsed['period_start'], 0, 4) !== $year) {
        $parsed['errors'][] = "Il periodo estratto non appartiene alla cartella dell'anno $year.";
    }

    $hash = hash_file('sha256', $pdfPath);
    $hashCheck->execute([$hash]);
    if ($hashCheck->fetchColumn()) $parsed['errors'][] = 'PDF già importato in precedenza.';

    $existingBill = null;
    if (($parsed['period_start'] ?? null) !== null) {
        $monthCheck->execute([$utilityId, $parsed['period_start']]);
        $existingBill = $monthCheck->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingBill !== null && (int)$existingBill['is_estimate'] !== 1) {
            $parsed['errors'][] = 'Esiste già una bolletta reale Luce per il mese estratto.';
        }
    }

    echo "\n$fileName\n";
    foreach (['provider', 'period_start', 'period_end', 'invoice_date', 'due_date', 'amount_total', 'kwh', 'billed_kwh', 'physical_kwh', 'grid_losses_kwh', 'grid_losses_cost', 'grid_losses_mode', 'canone_rai', 'invoice_number', 'pod'] as $key) {
        $value = $parsed[$key] ?? null;
        echo '  ' . str_pad($key, 16) . ': ' . ($value === null ? '—' : (string)$value) . "\n";
    }

    if (($parsed['errors'] ?? []) !== []) {
        $failed++;
        foreach ($parsed['errors'] as $error) echo "  ERRORE: $error\n";
        if ($commit) {
            file_put_contents($errorDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '.txt', implode("\n", $parsed['errors']) . "\n");
        }
        continue;
    }

    if (!$commit) {
        if ($existingBill !== null && (int)$existingBill['is_estimate'] === 1) {
            echo '  ANTEPRIMA: sostituirà la bolletta stimata ID ' . (int)$existingBill['id'] . ".\n";
        }
        echo "  ANTEPRIMA: nessun dato scritto. Usa --commit per importare.\n";
        continue;
    }

    $pdo->beginTransaction();
    try {
        $replacedEstimate = $existingBill !== null && (int)$existingBill['is_estimate'] === 1;
        if ($replacedEstimate) {
            $oldNotes = trim((string)($existingBill['notes'] ?? ''));
            if (preg_match('/^stima(?:[-\s_].*)?$/iu', $oldNotes)) $oldNotes = '';
            $billId = (int)$existingBill['id'];
            $updateEstimatedBill->execute([$parsed['due_date'], $parsed['period_start'], $parsed['period_end'], $parsed['amount_total'], $oldNotes !== '' ? $oldNotes : null, $billId]);
            $deleteEstimateFlag->execute([$billId]);
        } else {
            $insertBill->execute([$utilityId, $parsed['due_date'], $parsed['period_start'], $parsed['period_end'], $parsed['amount_total'], null]);
            $billId = (int)$pdo->lastInsertId();
        }
        foreach ([
            ['kwh', $parsed['kwh'], 'kWh'],
            ['billed_kwh', $parsed['billed_kwh'], 'kWh'],
            ['physical_kwh', $parsed['physical_kwh'], 'kWh'],
            ['grid_losses_kwh', $parsed['grid_losses_kwh'], 'kWh'],
            ['grid_losses_cost', $parsed['grid_losses_cost'], 'EUR'],
            ['grid_losses_mode', $parsed['grid_losses_mode'], 'text'],
            ['canone_rai', $parsed['canone_rai'], 'EUR'],
            ['provider', $parsed['provider'], 'text'],
            ['data_fattura', $parsed['invoice_date'], 'date'],
            ['numero_fattura', $parsed['invoice_number'], 'text'],
            ['pod', $parsed['pod'], 'text'],
            ['import_source_sha256', $hash, 'sha256'],
            ['import_source_file', $fileName, 'text'],
        ] as [$key, $value, $unit]) {
            if ($value !== null && $value !== '') {
                ($replacedEstimate ? $upsertMetric : $insertMetric)->execute([$billId, $key, (string)$value, $unit]);
            }
        }
        $pdo->commit();

        $destination = $processedDir . '/' . $fileName;
        if (file_exists($destination)) {
            $destination = $processedDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '-' . date('YmdHis') . '.pdf';
        }
        if (!rename($pdfPath, $destination)) {
            fwrite(STDERR, "  ATTENZIONE: bolletta inserita, ma PDF non spostato in importate.\n");
        }
        $errorReport = $errorDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '.txt';
        if (is_file($errorReport)) unlink($errorReport);
        echo $replacedEstimate
            ? "  AGGIORNATA: la stima ID $billId è stata sostituita con la bolletta reale.\n"
            : "  IMPORTATA: bolletta ID $billId.\n";
        $imported++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed++;
        $message = str_contains($e->getMessage(), 'Esiste già una bolletta')
            ? 'Esiste già una bolletta Luce per il mese estratto.'
            : 'Inserimento fallito; consulta il log PHP.';
        error_log('Import Octopus fallito per ' . $fileName . ': ' . $e->getMessage());
        echo "  ERRORE: $message\n";
        file_put_contents($errorDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '.txt', $message . "\n");
    }
}

echo "\nModalità: " . ($commit ? 'IMPORTAZIONE' : 'ANTEPRIMA') . ". Importate: $imported; errori: $failed.\n";
exit($failed > 0 ? 1 : 0);
