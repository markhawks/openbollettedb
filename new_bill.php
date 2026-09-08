<?php
declare(strict_types=1);

require __DIR__ . '/app/auth.php';
require_admin();
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/csrf.php';
require __DIR__ . '/app/validation.php';

function normalizeItalianDateInput(mixed $value): mixed
{
  if (!is_scalar($value)) return $value;
  $date = trim((string)$value);
  if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $match)) {
    return $match[3] . '-' . $match[2] . '-' . $match[1];
  }
  return $date;
}

function displayItalianDate(mixed $value): string
{
  if (!is_scalar($value)) return '';
  $date = trim((string)$value);
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match)) {
    return $match[3] . '/' . $match[2] . '/' . $match[1];
  }
  return $date;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach (['issue_date', 'period_start', 'period_end', 'data_fattura', 'next_reading_start', 'next_reading_end', 'data_scadenza', 'data_pagamento'] as $dateKey) {
    if (array_key_exists($dateKey, $_POST)) {
      $_POST[$dateKey] = normalizeItalianDateInput($_POST[$dateKey]);
    }
  }
  if (isset($_POST['reading_date']) && is_array($_POST['reading_date'])) {
    $_POST['reading_date'] = array_map('normalizeItalianDateInput', $_POST['reading_date']);
  }
}

$firstDayPrevMonth = date('Y-m-01', strtotime('first day of last month'));
$lastDayPrevMonth  = date('Y-m-t',  strtotime('first day of last month'));

$suggested_year = date('Y', strtotime('first day of last month'));
$suggested_month = date('m', strtotime('first day of last month'));
$postedPeriodStart = isset($_POST['period_start']) && is_scalar($_POST['period_start'])
  ? (string)$_POST['period_start']
  : '';
$selectedFormYear = preg_match('/^(\d{4})-\d{2}-\d{2}$/', $postedPeriodStart, $yearMatch)
  ? (int)$yearMatch[1]
  : (int)$suggested_year;
$firstSelectableYear = 2000;
$lastSelectableYear = (int)date('Y') + 5;
$valore_canone = ((int)$suggested_month <= 10) ? (($suggested_year == "2024") ? 7.00 : 9.00) : 0.00;

$pdo = db();
$utilityCode = $_GET['u'] ?? 'luce';

$utStmt = $pdo->prepare("SELECT id, code, name FROM utilities WHERE code = ?");
$utStmt->execute([$utilityCode]);
$utility = $utStmt->fetch(PDO::FETCH_ASSOC);

if (!$utility) { http_response_code(404); die("Utenza non trovata"); }

$latestTariIdentifiers = ['codice_cliente' => '', 'codice_utenza' => ''];
if ($utilityCode === 'tari') {
  $latestIdentifiersStmt = $pdo->prepare("
    SELECT m.key, m.value
    FROM bill_metrics m
    JOIN bills b ON b.id = m.bill_id
    WHERE b.utility_id = ?
      AND m.key IN ('codice_cliente', 'codice_utenza')
      AND TRIM(CAST(m.value AS TEXT)) <> ''
    ORDER BY COALESCE(NULLIF(b.issue_date, ''), b.period_end) DESC, b.id DESC
  ");
  $latestIdentifiersStmt->execute([(int)$utility['id']]);
  foreach ($latestIdentifiersStmt->fetchAll(PDO::FETCH_ASSOC) as $identifier) {
    $key = (string)$identifier['key'];
    if (isset($latestTariIdentifiers[$key]) && $latestTariIdentifiers[$key] === '') {
      $latestTariIdentifiers[$key] = (string)$identifier['value'];
    }
  }
}


// 2. Cerco l'ultima lettura finale inserita per questa utenza
// 2. Cerco l'ultima lettura finale inserita per questa utenza
$lastReading = 0.00;
$readingStmt = null;

// GAS: ultima lettura_fin
if ($utilityCode === 'gas') {
  $readingStmt = $pdo->prepare("
    SELECT m.value
    FROM bill_metrics m
    JOIN bills b ON m.bill_id = b.id
    WHERE b.utility_id = ?
      AND m.key = 'lettura_fin'
    ORDER BY b.period_end DESC
    LIMIT 1
  ");
}

// ACQUA: ultima mc_end
if ($utilityCode === 'acqua') {
  $readingStmt = $pdo->prepare("
    SELECT m.value
    FROM bill_metrics m
    JOIN bills b ON m.bill_id = b.id
    WHERE b.utility_id = ?
      AND m.key = 'mc_end'
    ORDER BY b.period_end DESC
    LIMIT 1
  ");
}

if ($readingStmt) {
  $readingStmt->execute([(int)$utility['id']]);
  $resReading = $readingStmt->fetch(PDO::FETCH_ASSOC);
  if ($resReading && isset($resReading['value'])) {
    $lastReading = (float)$resReading['value'];
  }
}



$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify(post_string($_POST, 'csrf'))) {
    http_response_code(403);
    die('Richiesta non valida (token CSRF mancante o scaduto).');
  }
  $errors = validate_bill_input($utilityCode, $_POST);
  $period_start = post_string($_POST, 'period_start');
  $period_end   = post_string($_POST, 'period_end');
  $amount_total = post_string($_POST, 'amount_total');
  $notes        = post_string($_POST, 'notes');
  $data_fattura = post_string($_POST, 'data_fattura');
  $codice_cliente = post_string($_POST, 'codice_cliente');
  $codice_utenza = post_string($_POST, 'codice_utenza');
  $svuotature_grigio = post_string($_POST, 'svuotature_grigio');
  $anno_periodo = (int)date('Y', strtotime($period_start));
  $periodo_competenza_full = '';
  if (post_string($_POST, 'periodo_competenza') !== '') {
    $q = post_string($_POST, 'periodo_competenza'); // Q1, Q2, ...
    $periodo_competenza_full = $q . ' ' . $anno_periodo; // Q1 2023
  }
  $issue_date = post_string($_POST, 'issue_date');





  // Metriche
  $kwh = post_string($_POST, 'kwh');
  $smc = post_string($_POST, 'smc');
  $canone_rai = post_string($_POST, 'canone_rai');
  $extra_adjust = post_string($_POST, 'extra_adjust', '0');
  if ($utilityCode === 'tari') {
    $importoLordoTari = (float)$amount_total;
    $creditoTari = (float)post_string($_POST, 'tari_credit', '0');
    $amount_total = (string)max(0, $importoLordoTari - $creditoTari);
    $extra_adjust = $creditoTari > 0 ? (string)(-$creditoTari) : '0';
  }
  $lettura_ini = post_string($_POST, 'lettura_ini');
  $lettura_fin = post_string($_POST, 'lettura_fin');
  $energy_price   = post_string($_POST, 'energy_price');
  $commercial_fee = post_string($_POST, 'commercial_fee');
  $stima          = isset($_POST['stima']) ? 1 : 0;

  // metriche ACQUA
  $mc_start   = post_string($_POST, 'mc_start');
  $mc_end     = post_string($_POST, 'mc_end');
  $consumo_mc = post_string($_POST, 'consumo_mc');
  $mc_conguaglio = post_string($_POST, 'mc_conguaglio', '0');
  $next_reading_start = post_string($_POST, 'next_reading_start');
  $next_reading_end   = post_string($_POST, 'next_reading_end');

  // storico letture ACQUA (data + m³, righe ripetibili)
  $reading_dates  = $_POST['reading_date'] ?? [];
  $reading_values = $_POST['reading_value'] ?? [];

  // metriche BONIFICA
  $data_scadenza  = post_string($_POST, 'data_scadenza');
  $data_pagamento = post_string($_POST, 'data_pagamento');








  if ($utilityCode === 'acqua') {
  // niente controllo mensile per l'acqua
} elseif ($utilityCode === 'bonifica') {
  // controllo annuale (un avviso per anno) invece che mensile
  $checkStmt = $pdo->prepare("
    SELECT id FROM bills
    WHERE utility_id = ?
      AND strftime('%Y', period_start) = strftime('%Y', ?)
  ");
  $checkStmt->execute([(int)$utility['id'], $period_start]);
  if ($checkStmt->fetch()) {
    $errors[] = "Esiste già una bolletta Bonifica per questo anno.";
  }
} elseif ($utilityCode === 'tari') {
  // Una sola bolletta per trimestre e anno, non una per mese.
  $checkStmt = $pdo->prepare("
    SELECT bm.bill_id
    FROM bill_metrics bm
    JOIN bills b ON b.id = bm.bill_id
    WHERE b.utility_id = ?
      AND bm.key = 'periodo_competenza'
      AND bm.value = ?
  ");
  $checkStmt->execute([(int)$utility['id'], $periodo_competenza_full]);
  if ($checkStmt->fetch()) {
    $errors[] = "Esiste già una bolletta TARI per il trimestre $periodo_competenza_full.";
  }
} else {
  $checkStmt = $pdo->prepare("
    SELECT id FROM bills
    WHERE utility_id = ?
      AND strftime('%Y-%m', period_start) = strftime('%Y-%m', ?)
  ");
  $checkStmt->execute([(int)$utility['id'], $period_start]);
  if ($checkStmt->fetch()) {
    $errors[] = "Esiste già una bolletta per questo mese.";
  }
}


 

  if (!$errors) {
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("
  INSERT INTO bills (
    utility_id,
    issue_date,
    period_start,
    period_end,
    amount_total,
    notes
  ) VALUES (?,?,?,?,?,?)
");

$stmt->execute([
  (int)$utility['id'],
  $issue_date ?: date('Y-m-d'),
  $period_start,
  $period_end,
  (float)$amount_total,
  $notes ?: null
]);

      $billId = (int)$pdo->lastInsertId();

$m = [];

/* LUCE */
if ($utilityCode === 'luce') {

  if ($kwh !== '' && is_numeric($kwh)) {
    $m[] = ['kwh', (float)$kwh, 'kWh'];
  }

  if ($energy_price !== '' && is_numeric($energy_price)) {
    $m[] = ['energy_price', (float)$energy_price, 'EUR/kWh'];
  }

  if ($commercial_fee !== '' && is_numeric($commercial_fee)) {
    $m[] = ['commercial_fee', (float)$commercial_fee, 'EUR/mese'];
  }

  if ($canone_rai !== '' && is_numeric($canone_rai)) {
    $m[] = ['canone_rai', (float)$canone_rai, 'EUR'];
  }

  if ($stima) {
    $m[] = ['stima', 1, 'bool'];
  }

}


/* GAS */
if ($utilityCode === 'gas') {
  if ($smc !== '' && is_numeric($smc)) {
    $m[] = ['smc', (float)$smc, 'Smc'];
  }
  if ($lettura_ini !== '' && is_numeric($lettura_ini)) {
    $m[] = ['lettura_ini', (float)$lettura_ini, 'num'];
  }
  if ($lettura_fin !== '' && is_numeric($lettura_fin)) {
    $m[] = ['lettura_fin', (float)$lettura_fin, 'num'];
  }
}

/* TARI ✅ */
if ($utilityCode === 'tari') {

  if ($data_fattura !== '') {
    $m[] = ['data_fattura', $data_fattura, 'date'];
  }

  if ($periodo_competenza_full !== '') {
    $m[] = ['periodo_competenza', $periodo_competenza_full, 'text'];
  }

  if (!empty($_POST['numero_fattura'])) {
    $m[] = ['numero_fattura', $_POST['numero_fattura'], 'text'];
  }

  if ($codice_cliente !== '') {
    $m[] = ['codice_cliente', $codice_cliente, 'text'];
  }

  if ($codice_utenza !== '') {
    $m[] = ['codice_utenza', $codice_utenza, 'text'];
  }

  if ($svuotature_grigio !== '') {
    $m[] = ['svuotature_grigio', (int)$svuotature_grigio, 'num'];
  }

  if ($stima) {
    $m[] = ['stima', 1, 'bool'];
  }

  if (!empty($_POST['tipo_avviso'])) {
    $m[] = ['tipo_avviso', $_POST['tipo_avviso'], 'text'];
  }

  $raccoltaDiff = trim((string)($_POST['raccolta_diff'] ?? ''));
  if ($raccoltaDiff !== '' && is_numeric($raccoltaDiff)) {
    $m[] = ['raccolta_diff', (float)$raccoltaDiff, '%'];
  }
}

/* ACQUA */
if ($utilityCode === 'acqua') {

  if ($mc_start !== '' && is_numeric($mc_start)) {
    $m[] = ['mc_start', (float)$mc_start, 'm3'];
  }

  if ($mc_end !== '' && is_numeric($mc_end)) {
    $m[] = ['mc_end', (float)$mc_end, 'm3'];
  }

  if ($consumo_mc !== '' && is_numeric($consumo_mc)) {
    $m[] = ['consumo_mc', (float)$consumo_mc, 'm3'];
  }

  if ($mc_conguaglio !== '' && is_numeric($mc_conguaglio)) {
  $m[] = ['mc_conguaglio', (float)$mc_conguaglio, 'm3'];
}

  if ($stima) {
    $m[] = ['stima', 1, 'bool'];
  }

  if ($next_reading_start !== '' && $next_reading_end !== '') {
    $m[] = ['next_reading_start', $next_reading_start, 'date'];
    $m[] = ['next_reading_end', $next_reading_end, 'date'];
  }

}

/* BONIFICA */
if ($utilityCode === 'bonifica') {

  if ($data_scadenza !== '') {
    $m[] = ['data_scadenza', $data_scadenza, 'date'];
  }

  if ($data_pagamento !== '') {
    $m[] = ['data_pagamento', $data_pagamento, 'date'];
  }
}




      // AGGIUNTO: salvataggio extra_adjust
      if ($extra_adjust !== '' && (float)$extra_adjust != 0) {
          $m[] = ['extra_adjust', (float)$extra_adjust, 'EUR'];
      }

      if ($m) {
        $ins = $pdo->prepare("INSERT INTO bill_metrics (bill_id, key, value, unit) VALUES (?,?,?,?)");
        foreach ($m as [$key,$val,$unit]) $ins->execute([$billId,$key,$val,$unit]);
      }

      /* Storico letture ACQUA */
      if ($utilityCode === 'acqua') {
        $insReading = $pdo->prepare("INSERT INTO bill_readings (bill_id, reading_date, reading_value) VALUES (?,?,?)");
        foreach ($reading_dates as $i => $rDate) {
          $rDate  = trim((string)$rDate);
          $rValue = trim((string)($reading_values[$i] ?? ''));
          if ($rDate !== '' && $rValue !== '' && is_numeric($rValue)) {
            $insReading->execute([$billId, $rDate, (float)$rValue]);
          }
        }
      }

      $pdo->commit();
      header("Location: index.php?u=" . urlencode($utilityCode));
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      error_log('Errore salvataggio nuova bolletta: ' . $e->getMessage());
      $errors[] = "Errore durante il salvataggio. Riprova o consulta il log del server.";
    }
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Nuova bolletta</title>
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/new_bill_style.css?v=<?= filemtime('assets/css/new_bill_style.css') ?>">
</head>
<body>
  <div class="container">
    <header class="topbar">
      <div><h1>Nuova bolletta</h1><div class="sub"><?=htmlspecialchars($utility['name'])?></div></div>
      <a class="btn secondary" href="index.php?u=<?=htmlspecialchars($utilityCode)?>">← Indietro</a>
    </header>

    <section class="card">
      <?php if ($errors): ?>
        <div class="card" style="background:#fff3f3;border:1px solid #ffd0d0">
          <ul><?php foreach ($errors as $er): ?><li><?= htmlspecialchars($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <div class="year-selector">
        <?php for ($y = $firstSelectableYear; $y <= $lastSelectableYear; $y++): ?>
          <button type="button" class="year-btn" data-year="<?=$y?>"><?=$y?></button>
        <?php endfor; ?>
      </div>

      <?php if ($utilityCode !== 'tari'): ?>
      <div class="month-selector">
        <?php foreach ($months = [1=>'Gen',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mag',6=>'Giu',7=>'Lug',8=>'Ago',9=>'Set',10=>'Ott',11=>'Nov',12=>'Dic'] as $m=>$label): ?>
          <button type="button" class="month-btn" data-month="<?=$m?>"><?=$label?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
  <div class="form-inline">

    <div class="field">
      <label>Data scadenza</label>
      <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}"
            name="issue_date"
            value="<?= htmlspecialchars(displayItalianDate($_POST['issue_date'] ?? '')) ?>">
    </div>
    <div class="field">
      <label>Dal</label>
      <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="period_start" value="<?= htmlspecialchars(displayItalianDate($postedPeriodStart ?: $firstDayPrevMonth)) ?>"<?= $utilityCode === 'tari' ? ' readonly' : '' ?>>
    </div>
    <div class="field">
      <label>Al</label>
      <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="period_end" value="<?= htmlspecialchars(displayItalianDate(post_string($_POST, 'period_end', $lastDayPrevMonth))) ?>"<?= $utilityCode === 'tari' ? ' readonly' : '' ?>>
    </div>
    <div class="field">
      <label><?= $utilityCode === 'tari' ? 'Importo lordo (€)' : 'Importo Totale (€)' ?></label>
      <input type="number" step="0.01" min="0" name="amount_total" value="<?= htmlspecialchars(post_string($_POST, 'amount_total', '60.00')) ?>">
    </div>

    <?php if ($utilityCode === 'luce'): ?>
      <div class="field"><label>kWh</label><input type="number" name="kwh" value="100"></div>
      <div class="field"><label>Materia energia (€/kWh)</label><input type="number" step="0.0001" name="energy_price"></div>
      <div class="field"><label>Commercializzazione (€/mese)</label><input type="number" step="0.01" name="commercial_fee"></div>
      <div class="field"><label>Canone RAI (€)</label><input type="number" step="0.01" name="canone_rai" value="<?=$valore_canone?>"></div>
      <div class="field">
        <label style="display:flex; align-items:center; gap:6px;">
          <input type="checkbox" name="stima" value="1">
          📊 Bolletta stimata (previsione)
        </label>
      </div>
    <?php endif; ?>

    <?php if ($utilityCode === 'gas'): ?>
      <div class="field"><label>Lettura Ini</label><input type="number" step="0.01" name="lettura_ini" id="l_ini" value="<?= $lastReading ?>"></div>
      <div class="field"><label>Lettura Fin</label><input type="number" step="0.01" name="lettura_fin" id="l_fin"></div>
      <div class="field"><label>Smc</label><input type="number" step="0.01" name="smc" id="smc_res" readonly style="background:#f1f5f9;"></div>
    <?php endif; ?>

    <?php if ($utilityCode === 'tari'): ?>
      <div class="field"><label>Data fattura</label><input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="data_fattura" value="<?= htmlspecialchars(displayItalianDate(post_string($_POST, 'data_fattura', date('Y-m-d')))) ?>"></div>
      <div class="field">
        <label>Trimestre</label>
        <select name="periodo_competenza" id="trimestre" required>
          <option value="">— seleziona —</option>
          <?php $selectedQuarter = post_string($_POST, 'periodo_competenza'); ?>
          <option value="Q1" <?= $selectedQuarter === 'Q1' ? 'selected' : '' ?>>Q1 (Gen–Mar)</option>
          <option value="Q2" <?= $selectedQuarter === 'Q2' ? 'selected' : '' ?>>Q2 (Apr–Giu)</option>
          <option value="Q3" <?= $selectedQuarter === 'Q3' ? 'selected' : '' ?>>Q3 (Lug–Set)</option>
          <option value="Q4" <?= $selectedQuarter === 'Q4' ? 'selected' : '' ?>>Q4 (Ott–Dic)</option>
        </select>
        <small class="muted">Seleziona prima l'anno in alto, poi il trimestre.</small>
      </div>

      <div class="field"><label>Numero avviso/Fattura n.</label><input type="text" name="numero_fattura"></div>
      <div class="field">
        <label>Codice Utente/Cliente</label>
        <div class="field-input-action">
          <input type="text" name="codice_cliente" maxlength="100" value="<?= htmlspecialchars(post_string($_POST, 'codice_cliente')) ?>">
          <?php if ($latestTariIdentifiers['codice_cliente'] !== ''): ?>
            <button type="button" class="btn secondary use-latest-value" data-target="codice_cliente"
                    data-value="<?= htmlspecialchars($latestTariIdentifiers['codice_cliente'], ENT_QUOTES) ?>"
                    title="Inserisci <?= htmlspecialchars($latestTariIdentifiers['codice_cliente'], ENT_QUOTES) ?>">Usa più recente</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="field">
        <label>Codice Utenza/Contratto n.</label>
        <div class="field-input-action">
          <input type="text" name="codice_utenza" maxlength="100" value="<?= htmlspecialchars(post_string($_POST, 'codice_utenza')) ?>">
          <?php if ($latestTariIdentifiers['codice_utenza'] !== ''): ?>
            <button type="button" class="btn secondary use-latest-value" data-target="codice_utenza"
                    data-value="<?= htmlspecialchars($latestTariIdentifiers['codice_utenza'], ENT_QUOTES) ?>"
                    title="Inserisci <?= htmlspecialchars($latestTariIdentifiers['codice_utenza'], ENT_QUOTES) ?>">Usa più recente</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="field">
        <label>Tipo avviso</label>
        <select name="tipo_avviso">
          <option value="Ordinaria">Ordinaria</option>
          <option value="Rettifica">Rettifica</option>
          <option value="Conguaglio">Conguaglio</option>
        </select>
      </div>

      <div class="field"><label>% Raccolta diff.</label><input type="number" step="0.1" name="raccolta_diff"></div>
      <div class="field">
        <label>Svuotature indifferenziato (20 l)</label>
        <input type="number" name="svuotature_grigio" min="0" max="20" step="1"
               value="<?= htmlspecialchars(post_string($_POST, 'svuotature_grigio', '0')) ?>">
      </div>
      <div class="field">
        <label style="display:flex; align-items:center; gap:6px;">
          <input type="checkbox" name="stima" value="1" <?= isset($_POST['stima']) ? 'checked' : '' ?>>
          📊 Bolletta stimata (previsione)
        </label>
      </div>
    <?php endif; ?>

    <?php if ($utilityCode === 'acqua'): ?>
      <div class="field">
        <label>Lettura iniziale (m³)</label>
        <input type="number" step="0.01" name="mc_start" id="mc_start" value="<?= htmlspecialchars((string)$lastReading) ?>">
      </div>
      <div class="field">
        <label>Lettura finale (m³)</label>
        <input type="number" step="0.01" name="mc_end" id="mc_end">
      </div>
      <div class="field">
        <label>Consumo (m³)</label>
        <input type="number" step="0.01" name="consumo_mc" id="consumo_mc" readonly style="background:#f1f5f9; border:1px solid #d7d9e2;">
      </div>
      <div class="field">
        <label>Conguaglio m³</label>
        <input type="number"
              step="0.01"
              name="mc_conguaglio"
              value="<?= htmlspecialchars($_POST['mc_conguaglio'] ?? '0') ?>">
        <small class="muted">
          Usa valori negativi se già fatturati
        </small>
      </div>
      <div class="field">
        <label style="display:flex; align-items:center; gap:6px;">
          <input type="checkbox" name="stima" value="1">
          📊 Bolletta stimata (previsione)
        </label>
      </div>

      <div class="field">
        <label>Prossima lettura dal</label>
        <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="next_reading_start"
              value="<?= htmlspecialchars(displayItalianDate($_POST['next_reading_start'] ?? '')) ?>">
      </div>
      <div class="field">
        <label>Prossima lettura al</label>
        <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="next_reading_end"
              value="<?= htmlspecialchars(displayItalianDate($_POST['next_reading_end'] ?? '')) ?>">
        <small class="muted">Periodo indicato in bolletta per comunicare l'autolettura</small>
      </div>

      <div class="field wide">
        <label>Storico letture (opzionale)</label>
        <div id="readings-list">
          <?php
            $postDates  = $_POST['reading_date']  ?? [''];
            $postValues = $_POST['reading_value'] ?? [''];
          ?>
          <?php foreach ($postDates as $i => $rDate): ?>
            <div class="reading-row" style="display:flex; gap:8px; margin-bottom:6px;">
              <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="reading_date[]" value="<?= htmlspecialchars(displayItalianDate($rDate)) ?>">
              <input type="number" step="0.01" name="reading_value[]" placeholder="m³"
                     value="<?= htmlspecialchars($postValues[$i] ?? '') ?>">
              <button type="button" class="btn secondary remove-reading">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn secondary" id="add-reading">+ Aggiungi lettura</button>
        <small class="muted">Letture intermedie del contatore durante il periodo, per capire l'andamento del consumo</small>
      </div>
    <?php endif; ?>

    <?php if ($utilityCode === 'bonifica'): ?>
      <div class="field">
        <label>Anno</label>
        <select name="anno_bonifica" id="anno_bonifica">
          <?php for ($y = $firstSelectableYear; $y <= $lastSelectableYear; $y++): ?>
            <option value="<?=$y?>" <?= ($y == (int)$suggested_year) ? 'selected' : '' ?>><?=$y?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field">
        <label>Data scadenza</label>
        <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="data_scadenza" value="<?= htmlspecialchars(displayItalianDate($_POST['data_scadenza'] ?? '')) ?>">
      </div>
      <div class="field">
        <label>Data di pagamento</label>
        <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\d{2}/\d{2}/\d{4}" name="data_pagamento" value="<?= htmlspecialchars(displayItalianDate($_POST['data_pagamento'] ?? '')) ?>">
      </div>
    <?php endif; ?>

    <div class="field">
      <label><?= $utilityCode === 'tari' ? 'Credito/rimborso utilizzato (€)' : 'Extra/Bonus (€)' ?></label>
      <input type="number" step="0.01" <?= $utilityCode === 'tari' ? 'min="0" name="tari_credit"' : 'name="extra_adjust"' ?>
             value="<?= htmlspecialchars($utilityCode === 'tari' ? post_string($_POST, 'tari_credit', '0.00') : post_string($_POST, 'extra_adjust', '0.00')) ?>">
      <?php if ($utilityCode === 'tari'): ?>
        <small class="muted">Inserisci il credito come valore positivo: sarà sottratto automaticamente dall'importo lordo.</small>
      <?php endif; ?>
    </div>
    <div class="field wide">
      <label>Note</label>
      <input type="text" name="notes" placeholder="Opzionale...">
    </div>
    
    <div class="actions">
      <button class="btn" type="submit" style="width: 100%; min-height: 44px;">Salva Bolletta</button>
    </div>

  </div> 
    </form>
    </section>
  </div>

<script>
document.addEventListener('DOMContentLoaded', () => {

  document.querySelectorAll('.use-latest-value').forEach(button => {
    button.addEventListener('click', () => {
      const input = document.querySelector(`input[name="${button.dataset.target}"]`);
      if (!input) return;
      input.value = button.dataset.value || '';
      input.focus();
    });
  });

  const yearBtns   = document.querySelectorAll('.year-btn');
  const monthBtns  = document.querySelectorAll('.month-btn');
  const startInput = document.querySelector('input[name="period_start"]');
  const endInput   = document.querySelector('input[name="period_end"]');
  const trimestreSel = document.getElementById('trimestre');
  const fatturaInput = document.querySelector('input[name="data_fattura"]');

  let selectedYear  = <?= $selectedFormYear ?>;
  let selectedMonth = <?= (int)$suggested_month ?>;

  const lIni = document.getElementById('l_ini');
  const lFin = document.getElementById('l_fin');
  const smc  = document.getElementById('smc_res');

  function calcolaSmc() {
    if (!lIni || !lFin || !smc) return;

    const ini = parseFloat(lIni.value);
    const fin = parseFloat(lFin.value);

    if (!isNaN(ini) && !isNaN(fin) && fin >= ini) {
      smc.value = (fin - ini).toFixed(2);
    } else {
      smc.value = '';
    }
  }

  if (lIni && lFin) {
    lIni.addEventListener('input', calcolaSmc);
    lFin.addEventListener('input', calcolaSmc);
  }


const mcStart = document.getElementById('mc_start');
const mcEnd   = document.getElementById('mc_end');
const mcCons  = document.getElementById('consumo_mc');

function calcolaConsumoAcqua() {
  if (!mcStart || !mcEnd || !mcCons) return;

  const ini = parseFloat(mcStart.value);
  const fin = parseFloat(mcEnd.value);

  if (!isNaN(ini) && !isNaN(fin) && fin >= ini) {
    mcCons.value = (fin - ini).toFixed(2);
  } else {
    mcCons.value = '';
  }
}

if (mcStart && mcEnd) {
  mcStart.addEventListener('input', calcolaConsumoAcqua);
  mcEnd.addEventListener('input', calcolaConsumoAcqua);
}


/* ===========================
   STORICO LETTURE ACQUA
   =========================== */
const readingsList = document.getElementById('readings-list');
const addReadingBtn = document.getElementById('add-reading');

function bindRemoveReading(row) {
  const btn = row.querySelector('.remove-reading');
  if (!btn) return;
  btn.addEventListener('click', () => row.remove());
}

if (readingsList) {
  readingsList.querySelectorAll('.reading-row').forEach(bindRemoveReading);
}

if (addReadingBtn && readingsList) {
  addReadingBtn.addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'reading-row';
    row.style.cssText = 'display:flex; gap:8px; margin-bottom:6px;';
    row.innerHTML = `
      <input type="text" inputmode="numeric" placeholder="gg/mm/aaaa" pattern="\\d{2}/\\d{2}/\\d{4}" name="reading_date[]">
      <input type="number" step="0.01" name="reading_value[]" placeholder="m³">
      <button type="button" class="btn secondary remove-reading">✕</button>
    `;
    readingsList.appendChild(row);
    bindRemoveReading(row);
  });
}


/* ===========================
   ANNO BONIFICA -> Dal/Al
   =========================== */
const annoBonifica = document.getElementById('anno_bonifica');

function updateBonificaPeriod() {
  if (!annoBonifica) return;
  const y = parseInt(annoBonifica.value, 10);
  startInput.value = `01/01/${y}`;
  endInput.value   = `31/12/${y}`;
}

if (annoBonifica) {
  annoBonifica.addEventListener('change', updateBonificaPeriod);
  updateBonificaPeriod();
}









  function formatDate(d) {
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
  }

  function parseItalianDate(value) {
    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
    if (!match) return null;
    const date = new Date(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
    return date.getFullYear() === Number(match[3])
      && date.getMonth() === Number(match[2]) - 1
      && date.getDate() === Number(match[1]) ? date : null;
  }

  function updateMonthDates() {
    const start = new Date(selectedYear, selectedMonth - 1, 1);
    const end   = new Date(selectedYear, selectedMonth, 0);
    startInput.value = formatDate(start);
    endInput.value   = formatDate(end);
  }

  function updateQuarterDates() {
    if (!trimestreSel || !trimestreSel.value) return;
    const quarter = Number(trimestreSel.value.substring(1));
    if (quarter < 1 || quarter > 4) return;
    const startMonth = (quarter - 1) * 3;
    const start = new Date(selectedYear, startMonth, 1);
    const end = new Date(selectedYear, startMonth + 3, 0);
    startInput.value = formatDate(start);
    endInput.value = formatDate(end);
    updateFatturaYear(selectedYear);
  }

  function updateFatturaYear(newYear) {
    if (!fatturaInput || !fatturaInput.value) return;

    const d = parseItalianDate(fatturaInput.value);
    if (!d) return;

    d.setFullYear(newYear);
    fatturaInput.value = formatDate(d);
  }

  

  /* ===========================
     MESE / ANNO (LUCE/GAS)
     =========================== */
  yearBtns.forEach(btn => {
    if (+btn.dataset.year === selectedYear) btn.classList.add('active');
      btn.onclick = () => {
      yearBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      selectedYear = +btn.dataset.year;
      if (trimestreSel) {
        updateQuarterDates();
      } else {
        updateMonthDates();
      }
      updateFatturaYear(selectedYear);
    };
  });

  monthBtns.forEach(btn => {
    if (+btn.dataset.month === selectedMonth) btn.classList.add('active');
    btn.onclick = () => {
      monthBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      selectedMonth = +btn.dataset.month;
      updateMonthDates();
    };
  });

  /* ===========================
     TRIMESTRE TARI (CORRETTO)
     =========================== */
if (trimestreSel && startInput && endInput) {
    trimestreSel.addEventListener('change', updateQuarterDates);
    updateQuarterDates();
  }

});
</script>

<script src="assets/js/italian-date-picker.js?v=<?= filemtime('assets/js/italian-date-picker.js') ?>"></script>

</body>
</html>
