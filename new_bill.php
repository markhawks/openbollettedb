<?php
declare(strict_types=1);

require __DIR__ . '/app/auth.php';
require_login();
require __DIR__ . '/app/db.php';

$firstDayPrevMonth = date('Y-m-01', strtotime('first day of last month'));
$lastDayPrevMonth  = date('Y-m-t',  strtotime('first day of last month'));

$suggested_year = date('Y', strtotime('first day of last month'));
$suggested_month = date('m', strtotime('first day of last month'));
$valore_canone = ((int)$suggested_month <= 10) ? (($suggested_year == "2024") ? 7.00 : 9.00) : 0.00;

$pdo = db();
$utilityCode = $_GET['u'] ?? 'luce';

$utStmt = $pdo->prepare("SELECT id, code, name FROM utilities WHERE code = ?");
$utStmt->execute([$utilityCode]);
$utility = $utStmt->fetch(PDO::FETCH_ASSOC);

if (!$utility) { http_response_code(404); die("Utenza non trovata"); }


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
  $period_start = trim($_POST['period_start'] ?? '');
  $period_end   = trim($_POST['period_end'] ?? '');
  $amount_total = trim($_POST['amount_total'] ?? '');
  $notes        = trim($_POST['notes'] ?? '');
  $data_fattura = trim($_POST['data_fattura'] ?? '');
  $anno_periodo = (int)date('Y', strtotime($period_start));
  $periodo_competenza_full = '';
  if (!empty($_POST['periodo_competenza'])) {
    $q = trim($_POST['periodo_competenza']); // Q1, Q2, ...
    $periodo_competenza_full = $q . ' ' . $anno_periodo; // Q1 2023
  }
  $issue_date = trim($_POST['issue_date'] ?? '');





  // Metriche
  $kwh = trim($_POST['kwh'] ?? '');
  $smc = trim($_POST['smc'] ?? '');
  $canone_rai = trim($_POST['canone_rai'] ?? '');
  $extra_adjust = trim($_POST['extra_adjust'] ?? '0');
  $lettura_ini = trim($_POST['lettura_ini'] ?? '');
  $lettura_fin = trim($_POST['lettura_fin'] ?? '');
  $energy_price   = trim($_POST['energy_price'] ?? '');
  $commercial_fee = trim($_POST['commercial_fee'] ?? '');
  $stima          = isset($_POST['stima']) ? 1 : 0;

  // metriche ACQUA
  $mc_start   = trim($_POST['mc_start'] ?? '');
  $mc_end     = trim($_POST['mc_end'] ?? '');
  $consumo_mc = trim($_POST['consumo_mc'] ?? '');
  $mc_conguaglio = trim($_POST['mc_conguaglio'] ?? '0');

  // metriche BONIFICA
  $data_scadenza  = trim($_POST['data_scadenza'] ?? '');
  $data_pagamento = trim($_POST['data_pagamento'] ?? '');








  if (!$period_start || !$period_end) $errors[] = "Periodo obbligatorio.";
  if (!is_numeric($amount_total)) $errors[] = "Importo non valido.";


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

  if (!empty($_POST['tipo_avviso'])) {
    $m[] = ['tipo_avviso', $_POST['tipo_avviso'], 'text'];
  }

  if ($_POST['raccolta_diff'] !== '') {
    $m[] = ['raccolta_diff', (float)$_POST['raccolta_diff'], '%'];
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

      $pdo->commit();
      header("Location: index.php?u=" . urlencode($utilityCode));
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = "Errore: " . $e->getMessage();
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
          <ul><?php foreach ($errors as $er): ?><li><?=$er?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <div class="year-selector">
        <?php for ($y = 2021; $y <= 2030; $y++): ?>
          <button type="button" class="year-btn" data-year="<?=$y?>"><?=$y?></button>
        <?php endfor; ?>
      </div>

      <div class="month-selector">
        <?php foreach ($months = [1=>'Gen',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mag',6=>'Giu',7=>'Lug',8=>'Ago',9=>'Set',10=>'Ott',11=>'Nov',12=>'Dic'] as $m=>$label): ?>
          <button type="button" class="month-btn" data-month="<?=$m?>"><?=$label?></button>
        <?php endforeach; ?>
      </div>

<form method="post">
  <div class="form-inline">

    <div class="field">
      <label>Data immissione</label>
      <input type="date"
            name="issue_date"
            value="<?= htmlspecialchars($_POST['issue_date'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="field">
      <label>Dal</label>
      <input type="date" name="period_start" value="<?=$firstDayPrevMonth?>">
    </div>
    <div class="field">
      <label>Al</label>
      <input type="date" name="period_end" value="<?=$lastDayPrevMonth?>">
    </div>
    <div class="field">
      <label>Importo Totale (€)</label>
      <input type="number" step="0.01" name="amount_total" value="60.00">
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
      <div class="field"><label>Data fattura</label><input type="date" name="data_fattura" value="<?= date('Y-m-d') ?>"></div>
      <div class="field">
        <label>Trimestre</label>
        <select name="periodo_competenza" id="trimestre">
          <option value="">— seleziona —</option>
          <option value="Q1">Q1 (Gen–Mar)</option>
          <option value="Q2">Q2 (Apr–Giu)</option>
          <option value="Q3">Q3 (Lug–Set)</option>
          <option value="Q4">Q4 (Ott–Dic)</option>
        </select>
      </div>

      <div class="field"><label>Numero avviso</label><input type="text" name="numero_fattura"></div>
      <div class="field">
        <label>Tipo avviso</label>
        <select name="tipo_avviso">
          <option value="Ordinaria">Ordinaria</option>
          <option value="Rettifica">Rettifica</option>
          <option value="Conguaglio">Conguaglio</option>
        </select>
      </div>

      <div class="field"><label>% Raccolta diff.</label><input type="number" step="0.1" name="raccolta_diff"></div>
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
    <?php endif; ?>

    <?php if ($utilityCode === 'bonifica'): ?>
      <div class="field">
        <label>Anno</label>
        <select name="anno_bonifica" id="anno_bonifica">
          <?php for ($y = 2021; $y <= 2030; $y++): ?>
            <option value="<?=$y?>" <?= ($y == (int)$suggested_year) ? 'selected' : '' ?>><?=$y?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field">
        <label>Data scadenza</label>
        <input type="date" name="data_scadenza">
      </div>
      <div class="field">
        <label>Data di pagamento</label>
        <input type="date" name="data_pagamento">
      </div>
    <?php endif; ?>

    <div class="field">
      <label>Extra/Bonus (€)</label>
      <input type="number" step="0.01" name="extra_adjust" value="0.00">
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

  const yearBtns   = document.querySelectorAll('.year-btn');
  const monthBtns  = document.querySelectorAll('.month-btn');
  const startInput = document.querySelector('input[name="period_start"]');
  const endInput   = document.querySelector('input[name="period_end"]');
  const trimestreSel = document.getElementById('trimestre');
  const fatturaInput = document.querySelector('input[name="data_fattura"]');

  let selectedYear  = <?= (int)$suggested_year ?>;
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
   ANNO BONIFICA -> Dal/Al
   =========================== */
const annoBonifica = document.getElementById('anno_bonifica');

function updateBonificaPeriod() {
  if (!annoBonifica) return;
  const y = parseInt(annoBonifica.value, 10);
  startInput.value = `${y}-01-01`;
  endInput.value   = `${y}-12-31`;
}

if (annoBonifica) {
  annoBonifica.addEventListener('change', updateBonificaPeriod);
  updateBonificaPeriod();
}









  function formatDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  }

  function updateMonthDates() {
    const start = new Date(selectedYear, selectedMonth - 1, 1);
    const end   = new Date(selectedYear, selectedMonth, 0);
    startInput.value = formatDate(start);
    endInput.value   = formatDate(end);
  }

  function updateFatturaYear(newYear) {
    if (!fatturaInput || !fatturaInput.value) return;

    const d = new Date(fatturaInput.value);
    if (isNaN(d)) return;

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
      updateMonthDates();
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

  trimestreSel.addEventListener('change', () => {
    if (!trimestreSel.value) return;

    // anno preso dalla data "Dal"
    const year = new Date(startInput.value || new Date()).getFullYear();
    updateFatturaYear(year);
    let startMonth = 0;
    let endMonth   = 0;

    switch (trimestreSel.value) {
      case 'Q1': startMonth = 0; endMonth = 2; break;
      case 'Q2': startMonth = 3; endMonth = 5; break;
      case 'Q3': startMonth = 6; endMonth = 8; break;
      case 'Q4': startMonth = 9; endMonth = 11; break;
      default: return;
    }

    const start = new Date(year, startMonth, 1);
    const end   = new Date(year, endMonth + 1, 0);

    startInput.value = formatDate(start);
    endInput.value   = formatDate(end);
  });

    // trigger automatico iniziale
    trimestreSel.dispatchEvent(new Event('change'));
  }

});
</script>

</body>
</html>