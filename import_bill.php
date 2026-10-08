<?php
declare(strict_types=1);

require __DIR__ . '/app/auth.php';
require_admin();
require __DIR__ . '/app/csrf.php';
require __DIR__ . '/app/importers/octopus_electricity.php';

const OCTOPUS_UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const OCTOPUS_PREVIEW_TTL = 1800;

function importDirectories(int $year): array
{
    $base = __DIR__ . "/data/imports/luce/$year";
    $directories = [
        'incoming' => "$base/da_elaborare",
        'processed' => "$base/importate",
        'errors' => "$base/errori",
    ];
    foreach ($directories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Impossibile creare la cartella di importazione: $directory");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("La cartella di importazione non è scrivibile: $directory");
        }
    }
    return $directories;
}

function findPdfToText(): ?string
{
    foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return null;
}

function extractPdfText(string $path): string
{
    $binary = findPdfToText();
    if ($binary === null) throw new RuntimeException('pdftotext non è installato sul server.');
    $command = escapeshellarg($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null';
    $lines = [];
    $status = 0;
    exec($command, $lines, $status);
    $text = implode("\n", $lines);
    if ($status !== 0 || trim($text) === '') {
        throw new RuntimeException('Il PDF non contiene testo leggibile. Le scansioni OCR non sono ancora supportate.');
    }
    return $text;
}

function safePdfName(string $name): string
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'bolletta';
    $base = trim($base, '.-_');
    return ($base !== '' ? $base : 'bolletta') . '.pdf';
}

function uniqueDestination(string $directory, string $fileName): string
{
    $path = $directory . '/' . $fileName;
    if (!file_exists($path)) return $path;
    $base = pathinfo($fileName, PATHINFO_FILENAME);
    return $directory . '/' . $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)) . '.pdf';
}

function loadExistingElectricityBill(PDO $pdo, int $utilityId, string $periodStart): ?array
{
    $stmt = $pdo->prepare("
        SELECT b.*
        FROM bills b
        WHERE b.utility_id = ? AND strftime('%Y-%m', b.period_start) = substr(?, 1, 7)
        LIMIT 1
    ");
    $stmt->execute([$utilityId, $periodStart]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bill) return null;

    $metricStmt = $pdo->prepare('SELECT `key`, value FROM bill_metrics WHERE bill_id = ?');
    $metricStmt->execute([(int)$bill['id']]);
    $bill['metrics'] = $metricStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return $bill;
}

function importedValues(array $parsed): array
{
    return [
        'period_start' => $parsed['period_start'] ?? null,
        'period_end' => $parsed['period_end'] ?? null,
        'invoice_date' => $parsed['invoice_date'] ?? null,
        'due_date' => $parsed['due_date'] ?? null,
        'amount_total' => $parsed['amount_total'] ?? null,
        'kwh' => $parsed['kwh'] ?? null,
        'billed_kwh' => $parsed['billed_kwh'] ?? null,
        'physical_kwh' => $parsed['physical_kwh'] ?? null,
        'grid_losses_kwh' => $parsed['grid_losses_kwh'] ?? null,
        'grid_losses_cost' => $parsed['grid_losses_cost'] ?? null,
        'grid_losses_mode' => $parsed['grid_losses_mode'] ?? null,
        'canone_rai' => $parsed['canone_rai'] ?? null,
        'invoice_number' => $parsed['invoice_number'] ?? null,
        'pod' => $parsed['pod'] ?? null,
        'provider' => $parsed['provider'] ?? null,
    ];
}

function existingValues(?array $bill): array
{
    if ($bill === null) return [];
    $metrics = $bill['metrics'] ?? [];
    return [
        'period_start' => $bill['period_start'] ?? null,
        'period_end' => $bill['period_end'] ?? null,
        'invoice_date' => $metrics['data_fattura'] ?? null,
        'due_date' => $bill['issue_date'] ?? null,
        'amount_total' => isset($bill['amount_total']) ? (float)$bill['amount_total'] : null,
        'kwh' => isset($metrics['kwh']) ? (float)$metrics['kwh'] : null,
        'billed_kwh' => isset($metrics['billed_kwh']) ? (float)$metrics['billed_kwh'] : null,
        'physical_kwh' => isset($metrics['physical_kwh']) ? (float)$metrics['physical_kwh'] : null,
        'grid_losses_kwh' => isset($metrics['grid_losses_kwh']) ? (float)$metrics['grid_losses_kwh'] : null,
        'grid_losses_cost' => isset($metrics['grid_losses_cost']) ? (float)$metrics['grid_losses_cost'] : null,
        'grid_losses_mode' => $metrics['grid_losses_mode'] ?? null,
        'canone_rai' => isset($metrics['canone_rai']) ? (float)$metrics['canone_rai'] : null,
        'invoice_number' => $metrics['numero_fattura'] ?? null,
        'pod' => $metrics['pod'] ?? null,
        'provider' => $metrics['provider'] ?? null,
    ];
}

function displayImportValue(string $key, mixed $value): string
{
    if ($value === null || $value === '') return '—';
    if (in_array($key, ['period_start', 'period_end', 'invoice_date', 'due_date'], true)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        return $date ? $date->format('d/m/Y') : (string)$value;
    }
    if (in_array($key, ['amount_total', 'canone_rai', 'grid_losses_cost'], true)) return '€ ' . number_format((float)$value, 2, ',', '.');
    if (in_array($key, ['kwh', 'billed_kwh', 'physical_kwh', 'grid_losses_kwh'], true)) return number_format((float)$value, 2, ',', '.') . ' kWh';
    if ($key === 'grid_losses_mode') return [
        'separate' => 'Addebitate separatamente',
        'incluse_consumo' => 'Incluse nel consumo fatturato',
        'incluse_prezzo' => 'Incluse nel prezzo',
    ][(string)$value] ?? (string)$value;
    return (string)$value;
}

function sameImportValue(string $key, mixed $existing, mixed $imported): bool
{
    if ($existing === null || $existing === '') return $imported === null || $imported === '';
    if ($imported === null || $imported === '') return false;
    if (in_array($key, ['amount_total', 'kwh', 'billed_kwh', 'physical_kwh', 'grid_losses_kwh', 'grid_losses_cost', 'canone_rai'], true)) return abs((float)$existing - (float)$imported) < 0.0001;
    return trim((string)$existing) === trim((string)$imported);
}

$pdo = db();
$utilityId = (int)($pdo->query("SELECT id FROM utilities WHERE code = 'luce'")->fetchColumn() ?: 0);
if ($utilityId <= 0) die('Utenza Luce non trovata.');

$errors = [];
$preview = null;
$existingBill = null;
$successBillId = isset($_GET['success']) ? (int)$_GET['success'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify(isset($_POST['csrf']) && is_scalar($_POST['csrf']) ? (string)$_POST['csrf'] : '')) {
        http_response_code(403);
        die('Richiesta non valida (token CSRF mancante o scaduto).');
    }

    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'upload') {
        $upload = $_FILES['bill_pdf'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Seleziona un PDF valido.';
        } elseif ((int)($upload['size'] ?? 0) <= 0 || (int)$upload['size'] > OCTOPUS_UPLOAD_MAX_BYTES) {
            $errors[] = 'Il PDF deve avere una dimensione massima di 10 MB.';
        } else {
            $tmpPath = (string)$upload['tmp_name'];
            $signature = file_get_contents($tmpPath, false, null, 0, 5);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
            if ($signature !== '%PDF-' || $mime !== 'application/pdf') {
                $errors[] = 'Il file caricato non è un PDF valido.';
            } else {
                try {
                    $originalName = safePdfName((string)($upload['name'] ?? 'bolletta.pdf'));
                    $parsed = parseOctopusElectricityText(extractPdfText($tmpPath), $originalName);
                    if (($parsed['errors'] ?? []) !== []) {
                        $errors = array_merge($errors, $parsed['errors']);
                    } else {
                        $year = (int)substr((string)$parsed['period_start'], 0, 4);
                        $directories = importDirectories($year);
                        $storedPath = uniqueDestination($directories['incoming'], bin2hex(random_bytes(6)) . '-' . $originalName);
                        if (!move_uploaded_file($tmpPath, $storedPath)) throw new RuntimeException('Impossibile archiviare temporaneamente il PDF.');

                        $hash = hash_file('sha256', $storedPath);
                        $hashStmt = $pdo->prepare("SELECT bill_id FROM bill_metrics WHERE `key` = 'import_source_sha256' AND value = ? LIMIT 1");
                        $hashStmt->execute([$hash]);
                        if ($hashStmt->fetchColumn()) {
                            unlink($storedPath);
                            throw new RuntimeException('Questo PDF è già stato importato.');
                        }

                        $token = bin2hex(random_bytes(24));
                        $_SESSION['octopus_import'][$token] = [
                            'path' => $storedPath,
                            'original_name' => $originalName,
                            'hash' => $hash,
                            'created_at' => time(),
                        ];
                        $preview = ['token' => $token, 'parsed' => $parsed, 'original_name' => $originalName];
                        $existingBill = loadExistingElectricityBill($pdo, $utilityId, (string)$parsed['period_start']);
                    }
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    } elseif ($action === 'confirm') {
        $token = isset($_POST['import_token']) && is_scalar($_POST['import_token']) ? (string)$_POST['import_token'] : '';
        $state = $_SESSION['octopus_import'][$token] ?? null;
        try {
            if (!is_array($state) || time() - (int)($state['created_at'] ?? 0) > OCTOPUS_PREVIEW_TTL) {
                throw new RuntimeException('Anteprima scaduta: carica nuovamente il PDF.');
            }
            $path = (string)($state['path'] ?? '');
            if (!is_file($path) || !hash_equals((string)$state['hash'], (string)hash_file('sha256', $path))) {
                throw new RuntimeException('Il PDF temporaneo non è più disponibile o è stato modificato.');
            }

            $parsed = parseOctopusElectricityText(extractPdfText($path), (string)$state['original_name']);
            if (($parsed['errors'] ?? []) !== []) throw new RuntimeException(implode(' ', $parsed['errors']));
            $existingBill = loadExistingElectricityBill($pdo, $utilityId, (string)$parsed['period_start']);
            if ($existingBill !== null && empty($_POST['overwrite_existing'])) {
                throw new RuntimeException('Per aggiornare la bolletta esistente devi confermare esplicitamente la sovrascrittura.');
            }

            $hashStmt = $pdo->prepare("SELECT bill_id FROM bill_metrics WHERE `key` = 'import_source_sha256' AND value = ? LIMIT 1");
            $hashStmt->execute([(string)$state['hash']]);
            $hashBillId = $hashStmt->fetchColumn();
            if ($hashBillId && ($existingBill === null || (int)$hashBillId !== (int)$existingBill['id'])) {
                throw new RuntimeException('Questo PDF risulta già importato in un’altra bolletta.');
            }

            $pdo->beginTransaction();
            if ($existingBill !== null) {
                $billId = (int)$existingBill['id'];
                $update = $pdo->prepare('UPDATE bills SET issue_date = ?, period_start = ?, period_end = ?, amount_total = ? WHERE id = ?');
                $update->execute([$parsed['due_date'], $parsed['period_start'], $parsed['period_end'], $parsed['amount_total'], $billId]);
                $pdo->prepare("DELETE FROM bill_metrics WHERE bill_id = ? AND `key` = 'stima'")->execute([$billId]);
            } else {
                $insert = $pdo->prepare('INSERT INTO bills (utility_id, issue_date, period_start, period_end, amount_total, notes) VALUES (?, ?, ?, ?, ?, NULL)');
                $insert->execute([$utilityId, $parsed['due_date'], $parsed['period_start'], $parsed['period_end'], $parsed['amount_total']]);
                $billId = (int)$pdo->lastInsertId();
            }

            $upsert = $pdo->prepare('INSERT INTO bill_metrics (bill_id, `key`, value, unit) VALUES (?, ?, ?, ?) ON CONFLICT(bill_id, `key`) DO UPDATE SET value = excluded.value, unit = excluded.unit');
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
                ['import_source_sha256', $state['hash'], 'sha256'],
                ['import_source_file', $state['original_name'], 'text'],
            ] as [$key, $value, $unit]) {
                if ($value !== null && $value !== '') $upsert->execute([$billId, $key, (string)$value, $unit]);
            }
            $pdo->commit();

            $year = (int)substr((string)$parsed['period_start'], 0, 4);
            $directories = importDirectories($year);
            $destination = uniqueDestination($directories['processed'], (string)$state['original_name']);
            if (!rename($path, $destination)) {
                error_log('Bolletta importata, ma PDF non spostato: ' . $path);
            }
            unset($_SESSION['octopus_import'][$token]);
            header('Location: import_bill.php?success=' . $billId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
            if (isset($state) && is_array($state) && is_file((string)($state['path'] ?? ''))) {
                $parsed = parseOctopusElectricityText(extractPdfText((string)$state['path']), (string)$state['original_name']);
                $preview = ['token' => $token, 'parsed' => $parsed, 'original_name' => (string)$state['original_name']];
                $existingBill = loadExistingElectricityBill($pdo, $utilityId, (string)$parsed['period_start']);
            }
        }
    } elseif ($action === 'cancel') {
        $token = isset($_POST['import_token']) && is_scalar($_POST['import_token']) ? (string)$_POST['import_token'] : '';
        $state = $_SESSION['octopus_import'][$token] ?? null;
        if (is_array($state) && is_file((string)($state['path'] ?? ''))) unlink((string)$state['path']);
        unset($_SESSION['octopus_import'][$token]);
        header('Location: import_bill.php');
        exit;
    }
}

$labels = [
    'period_start' => 'Inizio periodo',
    'period_end' => 'Fine periodo',
    'invoice_date' => 'Data fattura',
    'due_date' => 'Scadenza fattura',
    'amount_total' => 'Importo totale',
    'kwh' => 'Consumo reale',
    'billed_kwh' => 'Quantità fatturata con perdite',
    'physical_kwh' => 'Consumo reale',
    'grid_losses_kwh' => 'Perdite di rete',
    'grid_losses_cost' => 'Costo perdite',
    'grid_losses_mode' => 'Trattamento perdite',
    'canone_rai' => 'Canone RAI',
    'invoice_number' => 'Numero fattura',
    'pod' => 'POD',
    'provider' => 'Fornitore',
];
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Importa bolletta Octopus – OpenBolletteDB</title>
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/new_bill_style.css?v=<?= filemtime('assets/css/new_bill_style.css') ?>">
</head>
<body>
<div class="container import-container">
  <header class="topbar">
    <div>
      <h1>📄 Importa bolletta Octopus</h1>
      <div class="sub">Energia elettrica · PDF con anteprima e confronto</div>
    </div>
    <a class="btn secondary" href="index.php?u=luce">← Torna a Luce</a>
  </header>

  <?php if ($successBillId > 0): ?>
    <section class="card import-success">
      <strong>Importazione completata.</strong>
      La bolletta ID <?= $successBillId ?> è stata salvata e il PDF è stato archiviato.
      <a href="edit_bill.php?id=<?= $successBillId ?>&u=luce">Apri la bolletta</a>
    </section>
  <?php endif; ?>

  <?php if ($errors): ?>
    <section class="card import-errors">
      <strong>Importazione non completata</strong>
      <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
    </section>
  <?php endif; ?>

  <?php if ($preview === null): ?>
    <section class="card">
      <h2>Seleziona il PDF</h2>
      <p class="muted">Sono accettate bollette Octopus Energy con testo selezionabile, massimo 10 MB.</p>
      <form method="post" enctype="multipart/form-data" class="import-upload-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <label class="import-drop-zone">
          <span>📎 Scegli una bolletta PDF</span>
          <input type="file" name="bill_pdf" accept="application/pdf,.pdf" required>
        </label>
        <button class="btn" type="submit">Analizza PDF</button>
      </form>
    </section>
  <?php else: ?>
    <?php
      $parsedValues = importedValues($preview['parsed']);
      $oldValues = existingValues($existingBill);
    ?>
    <section class="card">
      <h2>Anteprima: <?= htmlspecialchars($preview['original_name']) ?></h2>
      <?php if ($existingBill !== null): ?>
        <div class="import-warning">
          Esiste già la bolletta ID <?= (int)$existingBill['id'] ?> per questo mese.
          Controlla le differenze prima di autorizzare la sovrascrittura.
        </div>
      <?php else: ?>
        <div class="import-new">Nessuna bolletta esistente per questo mese: verrà creato un nuovo record.</div>
      <?php endif; ?>

      <div class="import-comparison-wrap">
        <table class="import-comparison">
          <thead><tr><th>Dato</th><th>Esistente</th><th>PDF Octopus</th><th>Esito</th></tr></thead>
          <tbody>
          <?php foreach ($labels as $key => $label):
            $old = $oldValues[$key] ?? null;
            $new = $parsedValues[$key] ?? null;
            $preserved = $existingBill !== null && $key === 'canone_rai' && ($new === null || $new === '');
            $same = $existingBill !== null && sameImportValue($key, $old, $new);
          ?>
            <tr class="<?= $existingBill === null ? '' : (($same || $preserved) ? 'import-same' : 'import-different') ?>">
              <th><?= htmlspecialchars($label) ?></th>
              <td><?= htmlspecialchars(displayImportValue($key, $old)) ?></td>
              <td><strong><?= htmlspecialchars(displayImportValue($key, $new)) ?></strong></td>
              <td><?= $existingBill === null ? 'Nuovo' : ($preserved ? 'Conservato' : ($same ? 'Uguale' : 'Modifica')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="import-preserve-note">
        Le note non saranno modificate. Le metriche manuali non presenti nel PDF saranno conservate.
        Il flag “stima”, se presente, sarà rimosso.
      </div>

      <div class="import-confirm-actions">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="import_token" value="<?= htmlspecialchars($preview['token']) ?>">
          <button class="btn secondary" type="submit">Annulla</button>
        </form>
        <form method="post" class="import-confirm-form">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="confirm">
          <input type="hidden" name="import_token" value="<?= htmlspecialchars($preview['token']) ?>">
          <?php if ($existingBill !== null): ?>
            <label class="import-overwrite-confirm">
              <input type="checkbox" name="overwrite_existing" value="1" required>
              Confermo di voler sovrascrivere i dati mostrati della bolletta esistente ID <?= (int)$existingBill['id'] ?>.
            </label>
          <?php endif; ?>
          <button class="btn" type="submit"><?= $existingBill !== null ? 'Sovrascrivi e importa' : 'Importa bolletta' ?></button>
        </form>
      </div>
    </section>
  <?php endif; ?>
</div>
</body>
</html>
