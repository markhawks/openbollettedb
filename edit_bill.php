<?php
declare(strict_types=1);
require __DIR__ . '/app/auth.php';
require_login();
require __DIR__ . '/app/db.php';

$pdo = db();

$billId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$utilityCode = $_GET['u'] ?? 'luce';

if ($billId <= 0) {
  die("ID bolletta mancante o non valido");
}

/* 1) Carico bolletta */
$stmt = $pdo->prepare("SELECT * FROM bills WHERE id = ?");
$stmt->execute([$billId]);
$bill = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$bill) die("Bolletta non trovata");

/* 2) Carico metriche in array associativo */
$mStmt = $pdo->prepare("SELECT `key`, value FROM bill_metrics WHERE bill_id = ?");
$mStmt->execute([$billId]);
$metrics = $mStmt->fetchAll(PDO::FETCH_KEY_PAIR);

/* Helper: upsert semplice per metriche (DELETE + INSERT) */
function saveMetric(PDO $pdo, int $billId, string $key, $value, string $unit): void {
  $pdo->prepare("DELETE FROM bill_metrics WHERE bill_id = ? AND `key` = ?")
      ->execute([$billId, $key]);

  if ($value !== '' && $value !== null) {
    $pdo->prepare("INSERT INTO bill_metrics (bill_id, `key`, value, unit) VALUES (?,?,?,?)")
        ->execute([$billId, $key, $value, $unit]);
  }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $period_start = trim($_POST['period_start'] ?? '');
  $period_end   = trim($_POST['period_end'] ?? '');
  $amount_total = trim($_POST['amount_total'] ?? '');
  $notes        = trim($_POST['notes'] ?? '');

  // Metriche generiche
  $extra_adjust = trim($_POST['extra_adjust'] ?? '0');

  // LUCE
  $kwh = trim($_POST['kwh'] ?? '');
  $canone_rai = trim($_POST['canone_rai'] ?? '');
  $energy_price   = trim($_POST['energy_price'] ?? '');
  $commercial_fee = trim($_POST['commercial_fee'] ?? '');
  $stima          = isset($_POST['stima']) ? 1 : 0;


  // GAS
  $lettura_ini = trim($_POST['lettura_ini'] ?? '');
  $lettura_fin = trim($_POST['lettura_fin'] ?? '');
  $smc         = trim($_POST['smc'] ?? '');

  // TARI
  $periodo_competenza = trim($_POST['periodo_competenza'] ?? '');
  $numero_fattura     = trim($_POST['numero_fattura'] ?? '');
  $tipo_avviso        = trim($_POST['tipo_avviso'] ?? 'Ordinaria');
  $raccolta_diff      = trim($_POST['raccolta_diff'] ?? '');
  $data_fattura       = trim($_POST['data_fattura'] ?? '');

  // ACQUA
  $mc_start   = trim($_POST['mc_start'] ?? '');
  $mc_end     = trim($_POST['mc_end'] ?? '');
  $consumo_mc = trim($_POST['consumo_mc'] ?? '');

  // BONIFICA
  $data_scadenza  = trim($_POST['data_scadenza'] ?? '');
  $data_pagamento = trim($_POST['data_pagamento'] ?? '');


  /* Validazioni base */
  if (!$period_start || !$period_end) $errors[] = "Le date del periodo sono obbligatorie.";
  if ($amount_total === '' || !is_numeric($amount_total)) $errors[] = "L'importo deve essere un numero.";

  if ($utilityCode === 'tari') {

  // duplicato TARI = stesso trimestre
  $dupStmt = $pdo->prepare("
    SELECT bm.bill_id
    FROM bill_metrics bm
    JOIN bills b ON b.id = bm.bill_id
    WHERE b.utility_id = ?
      AND bm.key = 'periodo_competenza'
      AND bm.value = ?
      AND bm.bill_id != ?
  ");
  $dupStmt->execute([(int)$bill['utility_id'], $periodo_competenza, $billId]);

  if ($dupStmt->fetch()) {
    $errors[] = "Esiste già una bolletta TARI per il trimestre $periodo_competenza.";
  }

} 

/* SOLO LUCE / GAS */
if (in_array($utilityCode, ['luce','gas'], true)) {

  $checkStmt = $pdo->prepare("
    SELECT id FROM bills
    WHERE utility_id = ?
      AND strftime('%Y-%m', period_start) = strftime('%Y-%m', ?)
      AND id != ?
  ");
  $checkStmt->execute([(int)$bill['utility_id'], $period_start, $billId]);

  if ($checkStmt->fetch()) {
    $errors[] = "Attenzione: esiste già un'altra bolletta salvata per questo mese e anno.";
  }
}

/* SOLO BONIFICA: un avviso per anno */
if ($utilityCode === 'bonifica') {

  $checkStmt = $pdo->prepare("
    SELECT id FROM bills
    WHERE utility_id = ?
      AND strftime('%Y', period_start) = strftime('%Y', ?)
      AND id != ?
  ");
  $checkStmt->execute([(int)$bill['utility_id'], $period_start, $billId]);

  if ($checkStmt->fetch()) {
    $errors[] = "Esiste già un'altra bolletta Bonifica per questo anno.";
  }
}


  if (!$errors) {
    $pdo->beginTransaction();
    try {
    /* Update tabella bills */
    $issue_date = trim($_POST['issue_date'] ?? '');
    $upd = $pdo->prepare("
      UPDATE bills
      SET
        issue_date   = ?,
        period_start = ?,
        period_end   = ?,
        amount_total = ?,
        notes        = ?
      WHERE id = ?
    ");

    $upd->execute([
      $issue_date ?: null,
      $period_start,
      $period_end,
      (float)$amount_total,
      $notes ?: null,
      $billId
    ]);

      if ($utilityCode === 'luce') {

        if ($kwh !== '' && is_numeric($kwh))
          saveMetric($pdo, $billId, 'kwh', (float)$kwh, 'kWh');
        else
          saveMetric($pdo, $billId, 'kwh', null, 'kWh');

        if ($energy_price !== '' && is_numeric($energy_price))
          saveMetric($pdo, $billId, 'energy_price', (float)$energy_price, 'EUR/kWh');
        else
          saveMetric($pdo, $billId, 'energy_price', null, 'EUR/kWh');

        if ($commercial_fee !== '' && is_numeric($commercial_fee))
          saveMetric($pdo, $billId, 'commercial_fee', (float)$commercial_fee, 'EUR/mese');
        else
          saveMetric($pdo, $billId, 'commercial_fee', null, 'EUR/mese');

        if ($canone_rai !== '' && is_numeric($canone_rai))
          saveMetric($pdo, $billId, 'canone_rai', (float)$canone_rai, 'EUR');
        else
          saveMetric($pdo, $billId, 'canone_rai', null, 'EUR');

        if ($extra_adjust !== '' && is_numeric($extra_adjust) && (float)$extra_adjust != 0)
          saveMetric($pdo, $billId, 'extra_adjust', (float)$extra_adjust, 'EUR');
        else
          saveMetric($pdo, $billId, 'extra_adjust', null, 'EUR');

        if ($stima)
          saveMetric($pdo, $billId, 'stima', 1, 'bool');
        else
          saveMetric($pdo, $billId, 'stima', null, 'bool');
      }


      if ($utilityCode === 'gas') {

        if ($lettura_ini !== '' && is_numeric($lettura_ini))
          saveMetric($pdo, $billId, 'lettura_ini', (float)$lettura_ini, 'num');
        else
          saveMetric($pdo, $billId, 'lettura_ini', null, 'num');

        if ($lettura_fin !== '' && is_numeric($lettura_fin))
          saveMetric($pdo, $billId, 'lettura_fin', (float)$lettura_fin, 'num');
        else
          saveMetric($pdo, $billId, 'lettura_fin', null, 'num');

        if ($smc !== '' && is_numeric($smc))
          saveMetric($pdo, $billId, 'smc', (float)$smc, 'Smc');
        else
          saveMetric($pdo, $billId, 'smc', null, 'Smc');

        // ✅ COMM. €/MESE (NUOVO)
        if ($commercial_fee !== '' && is_numeric($commercial_fee))
          saveMetric($pdo, $billId, 'commercial_fee', (float)$commercial_fee, 'EUR/mese');
        else
          saveMetric($pdo, $billId, 'commercial_fee', null, 'EUR/mese');

        if ($extra_adjust !== '' && is_numeric($extra_adjust) && (float)$extra_adjust != 0)
          saveMetric($pdo, $billId, 'extra_adjust', (float)$extra_adjust, 'EUR');
        else
          saveMetric($pdo, $billId, 'extra_adjust', null, 'EUR');
      }

    if ($utilityCode === 'tari') {

      saveMetric($pdo, $billId, 'data_fattura', $data_fattura ?: null, 'date');

      saveMetric($pdo, $billId, 'periodo_competenza', $periodo_competenza ?: null, 'text');
      saveMetric($pdo, $billId, 'numero_fattura', $numero_fattura ?: null, 'text');
      saveMetric($pdo, $billId, 'tipo_avviso', $tipo_avviso ?: 'Ordinaria', 'text');

      if ($raccolta_diff !== '' && is_numeric($raccolta_diff)) {
        saveMetric($pdo, $billId, 'raccolta_diff', (float)$raccolta_diff, '%');
      } else {
        saveMetric($pdo, $billId, 'raccolta_diff', null, '%');
      }

      if ($extra_adjust !== '' && is_numeric($extra_adjust) && (float)$extra_adjust != 0) {
        saveMetric($pdo, $billId, 'extra_adjust', (float)$extra_adjust, 'EUR');
      } else {
        saveMetric($pdo, $billId, 'extra_adjust', null, 'EUR');
      }
    }

    if ($utilityCode === 'acqua') {

      if ($mc_start !== '' && is_numeric($mc_start))
        saveMetric($pdo, $billId, 'mc_start', (float)$mc_start, 'm3');
      else
        saveMetric($pdo, $billId, 'mc_start', null, 'm3');

      if ($mc_end !== '' && is_numeric($mc_end))
        saveMetric($pdo, $billId, 'mc_end', (float)$mc_end, 'm3');
      else
        saveMetric($pdo, $billId, 'mc_end', null, 'm3');

      if ($consumo_mc !== '' && is_numeric($consumo_mc))
        saveMetric($pdo, $billId, 'consumo_mc', (float)$consumo_mc, 'm3');
      else
        saveMetric($pdo, $billId, 'consumo_mc', null, 'm3');

      if ($extra_adjust !== '' && is_numeric($extra_adjust) && (float)$extra_adjust != 0)
        saveMetric($pdo, $billId, 'extra_adjust', (float)$extra_adjust, 'EUR');
      else
        saveMetric($pdo, $billId, 'extra_adjust', null, 'EUR');
      $mc_conguaglio = trim($_POST['mc_conguaglio'] ?? '0');

      if ($mc_conguaglio !== '' && is_numeric($mc_conguaglio))
        saveMetric($pdo, $billId, 'mc_conguaglio', (float)$mc_conguaglio, 'm3');
      else
        saveMetric($pdo, $billId, 'mc_conguaglio', null, 'm3');
    }

    if ($utilityCode === 'bonifica') {
      saveMetric($pdo, $billId, 'data_scadenza', $data_scadenza ?: null, 'date');
      saveMetric($pdo, $billId, 'data_pagamento', $data_pagamento ?: null, 'date');
    }



      $pdo->commit();
      header("Location: index.php?u=" . urlencode($utilityCode));
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = "Errore durante il salvataggio: " . $e->getMessage();
    }
  }
}

/* Per precompilare select trimestre su edit */
$existingTrimestre = (string)($metrics['periodo_competenza'] ?? '');
if ($existingTrimestre === '') {
  $existingTrimestre = 'Q' . (int)ceil(((int)date('n', strtotime($bill['period_start']))) / 3) . ' ' . (int)date('Y', strtotime($bill['period_start']));
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Modifica Bolletta</title>
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/new_bill_style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="container">
  <header class="topbar">
    <div>
      <h1>Modifica Bolletta</h1>
      <div class="sub"><?= htmlspecialchars(strtoupper($utilityCode)) ?> • ID <?= (int)$billId ?></div>
    </div>
    <a class="btn secondary" href="index.php?u=<?= htmlspecialchars($utilityCode) ?>">Annulla</a>
  </header>

  <section class="card">
    <?php if ($errors): ?>
      <div style="background:#fff3f3; color:#a00; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #ffd0d0;">
        <?php foreach ($errors as $er): ?><div>• <?= htmlspecialchars($er) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="form-inline">
        
        <div class="field">
          <label>Data immissione</label>
          <input type="date" name="issue_date"
                value="<?= htmlspecialchars((string)($bill['issue_date'] ?? '')) ?>">
        </div>

        <div class="field">
          <label>Dal</label>
          <input type="date" name="period_start" id="period_start"
                 value="<?= htmlspecialchars($bill['period_start']) ?>">
        </div>

        <div class="field">
          <label>Al</label>
          <input type="date" name="period_end" id="period_end"
                 value="<?= htmlspecialchars($bill['period_end']) ?>">
        </div>

        <div class="field">
          <label>Importo Totale (€)</label>
          <input type="number" step="0.01" name="amount_total"
                 value="<?= htmlspecialchars((string)$bill['amount_total']) ?>">
        </div>

        <?php if ($utilityCode === 'luce'): ?>
          <div class="field">
            <label>kWh</label>
            <input type="number" step="1" name="kwh"
                   value="<?= htmlspecialchars((string)($metrics['kwh'] ?? '')) ?>">
          </div>
          
          <div class="field">
            <label>Materia energia (€/kWh)</label>
            <input type="number" step="0.0001" name="energy_price"
                  value="<?= htmlspecialchars((string)($metrics['energy_price'] ?? '')) ?>">
          </div>

          <div class="field">
            <label>Commercializzazione (€/mese)</label>
            <input type="number" step="0.01" name="commercial_fee"
                  value="<?= htmlspecialchars((string)($metrics['commercial_fee'] ?? '')) ?>">
          </div>

          <div class="field">
            <label>Canone RAI (€)</label>
            <input type="number" step="0.01" name="canone_rai"
                   value="<?= htmlspecialchars((string)($metrics['canone_rai'] ?? '')) ?>">
          </div>

          <div class="field">
            <label style="display:flex; align-items:center; gap:6px;">
              <input type="checkbox" name="stima" value="1" <?= !empty($metrics['stima']) ? 'checked' : '' ?>>
              📊 Bolletta stimata (previsione)
            </label>
          </div>
        <?php endif; ?>

        <?php if ($utilityCode === 'gas'): ?>
          <div class="field">
            <label>Lettura Ini</label>
            <input type="number" step="0.01" name="lettura_ini" id="l_ini"
                  value="<?= htmlspecialchars((string)($metrics['lettura_ini'] ?? '')) ?>">
          </div>

          <div class="field">
            <label>Lettura Fin</label>
            <input type="number" step="0.01" name="lettura_fin" id="l_fin"
                  value="<?= htmlspecialchars((string)($metrics['lettura_fin'] ?? '')) ?>">
          </div>

          <div class="field">
            <label>Smc</label>
            <input type="number" step="0.01" name="smc" id="smc_res"
                  value="<?= htmlspecialchars((string)($metrics['smc'] ?? '')) ?>">
            <small class="muted">Puoi anche modificarlo a mano</small>
          </div>

          <!-- ✅ COMM. €/MESE (NUOVO – GAS) -->
          <div class="field">
            <label>Commercializzazione (€/mese)</label>
            <input type="number" step="0.01" name="commercial_fee"
                  value="<?= htmlspecialchars((string)($metrics['commercial_fee'] ?? '')) ?>">
            <small class="muted">Costo fisso ricorrente</small>
          </div>

        <?php endif; ?>


        <?php if ($utilityCode === 'acqua'): ?>
              <div class="field">
                <label>Lettura iniziale (m³)</label>
                <input type="number" step="0.01" name="mc_start" id="mc_start"
                      value="<?= htmlspecialchars((string)($metrics['mc_start'] ?? '')) ?>">
              </div>

              <div class="field">
                <label>Lettura finale (m³)</label>
                <input type="number" step="0.01" name="mc_end" id="mc_end"
                      value="<?= htmlspecialchars((string)($metrics['mc_end'] ?? '')) ?>">
              </div>

              <div class="field">
                <label>Consumo (m³)</label>
                <input type="number" step="0.01" name="consumo_mc" id="consumo_mc"
                      readonly
                      value="<?= htmlspecialchars((string)($metrics['consumo_mc'] ?? '')) ?>">
                <small class="muted">Calcolato automaticamente</small>
              </div>
              <div class="field">
                <label>Conguaglio m³</label>
                <input type="number"
                      step="0.01"
                      name="mc_conguaglio"
                      value="<?= htmlspecialchars((string)($metrics['mc_conguaglio'] ?? '0')) ?>">
                <small class="muted">
                  Metri cubi già fatturati o stimati
                </small>
              </div>

            <?php endif; ?>

        <?php if ($utilityCode === 'bonifica'): ?>
          <div class="field">
            <label>Data scadenza</label>
            <input type="date" name="data_scadenza"
                   value="<?= htmlspecialchars((string)($metrics['data_scadenza'] ?? '')) ?>">
          </div>

          <div class="field">
            <label>Data di pagamento</label>
            <input type="date" name="data_pagamento"
                   value="<?= htmlspecialchars((string)($metrics['data_pagamento'] ?? '')) ?>">
          </div>
        <?php endif; ?>


        <?php if ($utilityCode === 'tari'): ?>
          <div class="field">
            <label>Trimestre di competenza</label>
            <select name="periodo_competenza" id="trimestre" data-current-year="<?= (int)date('Y', strtotime($bill['period_start'])) ?>">

              <?php
                $annoCorrente = (int)date('Y', strtotime($bill['period_start']));

                // se nel DB hai già "Qx YYYY", usiamo quell'anno per centrare la lista
                $annoDaTrimestre = 0;
                if (preg_match('/^Q[1-4]\s+(\d{4})$/', $existingTrimestre, $m)) {
                  $annoDaTrimestre = (int)$m[1];
                }

                $baseYear = $annoDaTrimestre ?: $annoCorrente;

                // range anni: baseYear-2 .. baseYear+2 (puoi allargare)
                for ($y = $baseYear - 2; $y <= $baseYear + 2; $y++):
                  for ($q = 1; $q <= 4; $q++):
                    $val = "Q$q $y";
                    $sel = ($existingTrimestre === $val) ? 'selected' : '';
              ?>
                    <option value="<?= $val ?>" <?= $sel ?>><?= $val ?></option>
              <?php
                  endfor;
                endfor;
              ?>

            


            </select>
            <small class="muted">Se cambi trimestre, possiamo aggiornare Dal/Al automaticamente</small>
          </div>

          <div class="field">
              <label>Data fattura</label>
              <input type="date" name="data_fattura"
              value="<?= htmlspecialchars((string)($metrics['data_fattura'] ?? '')) ?>">
          </div>

          <div class="field wide">
            <label>Numero avviso</label>
            <input type="text" name="numero_fattura"
                   value="<?= htmlspecialchars((string)($metrics['numero_fattura'] ?? '')) ?>">
          </div>

          <div class="field">
            <label>Tipo avviso</label>
            <select name="tipo_avviso">
              <?php
                $tipi = ['Ordinaria','Rettifica','Conguaglio'];
                $curr = (string)($metrics['tipo_avviso'] ?? 'Ordinaria');
                foreach ($tipi as $t):
                  $sel = ($curr === $t) ? 'selected' : '';
              ?>
                <option value="<?= $t ?>" <?= $sel ?>><?= $t ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>% Raccolta differenziata</label>
            <input type="number" step="0.1" min="0" max="100" name="raccolta_diff"
                   value="<?= htmlspecialchars((string)($metrics['raccolta_diff'] ?? '')) ?>">
          </div>
        <?php endif; ?>

        <div class="field">
          <label>Extra / Bonus (€)</label>
          <input type="number" step="0.01" name="extra_adjust"
                 value="<?= htmlspecialchars((string)($metrics['extra_adjust'] ?? '0.00')) ?>">
          <small class="muted">Usa (-) per i bonus</small>
        </div>

        <div class="field wide">
          <label>Note</label>
          <input type="text" name="notes" placeholder="Opzionale..."
                 value="<?= htmlspecialchars((string)($bill['notes'] ?? '')) ?>">
        </div>

        <div class="actions">
          <button class="btn" type="submit">Salva Modifiche</button>
        </div>

      </div>
    </form>
  </section>
</div>

<script>
// Calcolo automatico Smc (solo se presenti i campi)
(function(){
  const lIni = document.getElementById('l_ini');
  const lFin = document.getElementById('l_fin');
  const smcRes = document.getElementById('smc_res');
  if (!lIni || !lFin || !smcRes) return;

  const calcola = () => {
    const a = parseFloat(lIni.value);
    const b = parseFloat(lFin.value);
    if (Number.isFinite(a) && Number.isFinite(b)) {
      const v = b - a;
      smcRes.value = (v > 0) ? v.toFixed(2) : "0.00";
    }
  };
  lIni.addEventListener('input', calcola);
  lFin.addEventListener('input', calcola);
})();

// Trimestre -> aggiorna Dal/Al (solo per TARI)
(function(){
  const trimestreSel = document.getElementById('trimestre');
  const startInput = document.getElementById('period_start');
  const endInput   = document.getElementById('period_end');
  if (!trimestreSel || !startInput || !endInput) return;

  function fmt(d){
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  }

  trimestreSel.addEventListener('change', () => {
    const match = trimestreSel.value.match(/^Q([1-4])\s+(\d{4})$/);
    if (!match) return;

    const qNum = parseInt(match[1], 10);
    const year = parseInt(match[2], 10);

    if (!year) return;

    let sm = 0, em = 0;
    switch(qNum){
      case 1: sm=0;  em=2;  break;
      case 2: sm=3;  em=5;  break;
      case 3: sm=6;  em=8;  break;
      case 4: sm=9;  em=11; break;
      default: return;
    }


    const start = new Date(year, sm, 1);
    const end   = new Date(year, em + 1, 0);
    startInput.value = fmt(start);
    endInput.value   = fmt(end);
  });
})();
</script>

<script>
(function(){
  const a = document.getElementById('mc_start');
  const b = document.getElementById('mc_end');
  const c = document.getElementById('consumo_mc');
  if (!a || !b || !c) return;

  const calc = () => {
    const x = parseFloat(a.value);
    const y = parseFloat(b.value);
    if (Number.isFinite(x) && Number.isFinite(y) && y >= x) {
      c.value = (y - x).toFixed(2);
    } else {
      c.value = '';
    }
  };

  a.addEventListener('input', calc);
  b.addEventListener('input', calc);
})();
</script>



</body>
</html>
