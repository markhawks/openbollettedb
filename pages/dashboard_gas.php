<?php
declare(strict_types=1);
require __DIR__ . '/../app/db.php';

$pdo = db();

/* Recupero utility GAS */
$utStmt = $pdo->prepare("SELECT id, name FROM utilities WHERE code = 'gas'");
$utStmt->execute();
$utility = $utStmt->fetch(PDO::FETCH_ASSOC);
if (!$utility) {
  die("Utility GAS non trovata");
}
$utilityId = (int)$utility['id'];

/* Query bollette GAS */
$stmt = $pdo->prepare("
  SELECT b.*,
         strftime('%m', b.period_start) AS mese_num,
         strftime('%Y', b.period_start) AS anno,
         m1.value AS smc,
         m2.value AS extra_adjust,
         m3.value AS lettura_ini,
         m4.value AS lettura_fin,
         m5.value AS commercial_fee
  FROM bills b
  LEFT JOIN bill_metrics m1 ON b.id = m1.bill_id AND m1.key = 'smc'
  LEFT JOIN bill_metrics m2 ON b.id = m2.bill_id AND m2.key = 'extra_adjust'
  LEFT JOIN bill_metrics m3 ON b.id = m3.bill_id AND m3.key = 'lettura_ini'
  LEFT JOIN bill_metrics m4 ON b.id = m4.bill_id AND m4.key = 'lettura_fin'
  LEFT JOIN bill_metrics m5 ON b.id = m5.bill_id AND m5.key = 'commercial_fee'
  WHERE b.utility_id = ?
  ORDER BY anno DESC, mese_num DESC
");
$stmt->execute([$utilityId]);
$allBills = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Raggruppamento per anno */
$billsByYear = [];
foreach ($allBills as $b) {
  $billsByYear[$b['anno']][] = $b;
}

/* Totali annuali GAS */
$sumStmt = $pdo->prepare("
  SELECT 
    strftime('%Y', period_start) AS year,
    SUM(amount_total) AS total_complessivo,
    (
      SELECT SUM(value)
      FROM bill_metrics m
      JOIN bills b2 ON m.bill_id = b2.id
      WHERE strftime('%Y', b2.period_start) = strftime('%Y', b.period_start)
        AND m.key = 'smc'
        AND b2.utility_id = ?
    ) AS total_smc,
    (
      SELECT SUM(value)
      FROM bill_metrics m
      JOIN bills b2 ON m.bill_id = b2.id
      WHERE strftime('%Y', b2.period_start) = strftime('%Y', b.period_start)
        AND m.key = 'extra_adjust'
        AND b2.utility_id = ?
    ) AS total_extra,
    (
      SELECT SUM(value)
      FROM bill_metrics m
      JOIN bills b2 ON m.bill_id = b2.id
      WHERE strftime('%Y', b2.period_start) = strftime('%Y', b.period_start)
        AND m.key = 'commercial_fee'
        AND b2.utility_id = ?
    ) AS total_comm_fee
  FROM bills b
  WHERE utility_id = ?
  GROUP BY year
  ORDER BY year DESC
");

$sumStmt->execute([
  $utilityId,
  $utilityId,
  $utilityId,
  $utilityId
]);

$yearTotals = $sumStmt->fetchAll(PDO::FETCH_ASSOC);


$mesiItaliani = [
  "01"=>"Gennaio","02"=>"Febbraio","03"=>"Marzo","04"=>"Aprile",
  "05"=>"Maggio","06"=>"Giugno","07"=>"Luglio","08"=>"Agosto",
  "09"=>"Settembre","10"=>"Ottobre","11"=>"Novembre","12"=>"Dicembre"
];

/* Mappa anno => totali, usata per calcolare la crescita rispetto all'anno precedente */
$yearMap = [];
foreach ($yearTotals as $yt) {
  $yearMap[(int)$yt['year']] = [
    'smc'    => (float)$yt['total_smc'],
    'totale' => (float)$yt['total_complessivo'],
  ];
}

function gasCalcCrescita(?float $curr, ?float $prev): ?array {
  if ($prev === null || $prev == 0.0 || $curr === null) return null;
  $delta = $curr - $prev;
  $pct   = ($delta / $prev) * 100;
  return [$delta, $pct];
}

function gasGrowthBadge(array $crescita, string $label, string $unit): string {
  [$delta, $pct] = $crescita;
  $segno  = $delta >= 0 ? '+' : '-';
  $colore = $delta >= 0 ? '#dc2626' : '#16a34a';
  $deltaFmt = $unit === '€'
    ? '€ ' . number_format(abs($delta), 2, ',', '.')
    : number_format(abs($delta), 1, ',', '.') . ' ' . $unit;
  return '<span style="color:' . $colore . '; font-weight:700; font-size:1rem;">'
    . htmlspecialchars($label) . ' ' . $segno . number_format(abs($pct), 1, ',', '.') . '% '
    . '<span style="font-weight:400;">(' . $segno . $deltaFmt . ')</span></span>';
}
?>

<header class="topbar">
  <div>
    <h1>🔥 Gas Naturale</h1>
    <div class="sub">Consumi, letture e costi</div>
  </div>
  <a class="btn" href="new_bill.php?u=gas">+ Nuova bolletta</a>
</header>

<!-- GRAFICO SMC -->
<section class="card">
  <h2>Andamento Consumi (Smc)</h2>
  <div style="height:300px">
    <canvas id="gasChart"></canvas>
  </div>
</section>

<!-- TABELLE PER ANNO -->
<?php foreach ($billsByYear as $year => $bills):
  $prevYear = (int)$year - 1;
  $curr = $yearMap[(int)$year] ?? null;
  $prev = $yearMap[$prevYear] ?? null;
  $crescitaSmc   = ($curr && $prev) ? gasCalcCrescita($curr['smc'], $prev['smc']) : null;
  $crescitaSpesa = ($curr && $prev) ? gasCalcCrescita($curr['totale'], $prev['totale']) : null;
?>
<section class="card">
  <div class="card-year-header">
    <h2>
      Bollette <?= $year ?>
      <?php if ($crescitaSmc || $crescitaSpesa): ?>
        <span style="font-size:1rem; margin-left:12px; display:inline-flex; gap:14px; vertical-align:middle;">
          <?= $crescitaSmc ? gasGrowthBadge($crescitaSmc, 'Consumo', 'Smc') : '' ?>
          <?= $crescitaSpesa ? gasGrowthBadge($crescitaSpesa, 'Spesa', '€') : '' ?>
          <span class="muted" style="font-size:0.85rem; align-self:center;">rispetto al <?= $prevYear ?></span>
        </span>
      <?php endif; ?>
    </h2>
    <a class="btn-reset-year"
       href="reset_year.php?u=gas&year=<?= $year ?>&csrf=<?= urlencode($csrfToken) ?>"
       onclick="return confirmResetAnno('Gas', <?= $year ?>);"
       title="Elimina tutte le bollette Gas di questo anno">
      🗑️ Svuota anno
    </a>
  </div>
  <table>
    <thead>
      <tr>
        <th class="muted">#</th>
        <th>Mese</th>
        <th>Periodo</th>
        <th class="right">Lettura Inizio</th>
        <th class="right">Lettura Fine</th>
        <th class="right">Smc</th>
        <th class="right">Comm. €/mese</th>
        <th class="right">Extra</th>
        <th class="right">Importo</th>
        <th class="right">€/Smc</th>
        <th>Note</th>
        <th style="text-align:center;">Azioni</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $letturaPrecedenteIni = null;
      foreach ($bills as $b):
        $l_ini = $b['lettura_ini'] !== null ? (float)$b['lettura_ini'] : null;
        $l_fin = $b['lettura_fin'] !== null ? (float)$b['lettura_fin'] : null;
        $smc   = (float)($b['smc'] ?? 0);
        $commFee = ($b['commercial_fee'] !== null && $b['commercial_fee'] !== '')
            ? (float)$b['commercial_fee']
            : null;

        $extra = (float)($b['extra_adjust'] ?? 0);

        $colore = '#64748b';
        $colore = '#64748b'; // neutro di default

        if ($l_fin !== null && $letturaPrecedenteIni !== null) {
          // se la lettura finale NON coincide con l'inizio della bolletta precedente (più vecchia)
          $colore = abs($l_fin - $letturaPrecedenteIni) > 0.01
            ? '#ef4444'   // rosso: discontinuità
            : '#10b981';  // verde: continuità OK
        }

        // memorizzo la lettura iniziale per il confronto con la bolletta più recente
        $letturaPrecedenteIni = $l_ini;


        $netto = (float)$b['amount_total'] - $extra;
        $media = $smc > 0 ? $netto / $smc : 0;
    ?>
<tr>
  <td class="muted"><?= $b['mese_num'] ?></td>

  <td><strong><?= $mesiItaliani[$b['mese_num']] ?></strong></td>

  <td class="muted">
    <?= date('d/m/Y', strtotime($b['period_start'])) ?> –
    <?= date('d/m/Y', strtotime($b['period_end'])) ?>
  </td>

  <td class="right" style="color:<?= $colore ?>;">
    <?= $l_ini !== null ? number_format($l_ini,2,',','.') : '-' ?>
  </td>

  <td class="right">
    <?= $l_fin !== null ? number_format($l_fin,2,',','.') : '-' ?>
  </td>

  <td class="right">
    <strong><?= $smc ? number_format($smc,2,',','.') : '-' ?></strong>
  </td>

  <td class="right">
    <?= $commFee !== null ? '€ '.number_format($commFee,2,',','.') : '-' ?>
  </td>


  <td class="right" style="color: <?= $extra < 0 ? '#16a34a' : '#ea580c' ?>;">
    <?= $extra != 0 ? '€ '.number_format($extra,2,',','.') : '-' ?>
  </td>

  <td class="right">
    <strong>€ <?= number_format((float)$b['amount_total'],2,',','.') ?></strong>
  </td>

  <td class="right">
    <?= $media ? '€ '.number_format($media,3,',','.') : '-' ?>
  </td>

  <td class="muted"><?= htmlspecialchars($b['notes'] ?? '') ?></td>

  <!-- AZIONI -->
  <td style="text-align:center; white-space: nowrap;">
    <a href="edit_bill.php?id=<?= $b['id'] ?>&u=gas"
       title="Modifica"
       style="text-decoration:none; margin-right:8px;">
      ✏️
    </a>
    <a href="delete_bill.php?id=<?= $b['id'] ?>&u=gas&csrf=<?= urlencode($csrfToken) ?>"
       title="Elimina"
       onclick="return confirm('Eliminare questa bolletta gas?');"
       style="text-decoration:none;">
      🗑️
    </a>
  </td>
</tr>

    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

<!-- RIEPILOGO -->
<section class="card">
  <h2>Riepilogo Annuale</h2>
  <ul class="list">
  <?php foreach ($yearTotals as $yt):
  $commTot = $yt['total_comm_fee'];
  $curr = $yearMap[(int)$yt['year']] ?? null;
  $prev = $yearMap[(int)$yt['year'] - 1] ?? null;
  $crescitaSmc   = ($curr && $prev) ? gasCalcCrescita($curr['smc'], $prev['smc']) : null;
  $crescitaSpesa = ($curr && $prev) ? gasCalcCrescita($curr['totale'], $prev['totale']) : null;
?>
<li style="flex-direction:column; gap:6px;">
  <div style="width:100%; display:flex; justify-content:space-between;">
    <span>
      <strong><?= $yt['year'] ?></strong> –
      <?= number_format((float)$yt['total_smc'],2,',','.') ?> Smc
    </span>

    <span>
      <?php if ($commTot === null): ?>
        <em>Comm.: non applicabile</em>
      <?php else: ?>
        Comm.: <strong>€ <?= number_format((float)$commTot,2,',','.') ?></strong>
      <?php endif; ?>
    </span>

    <span>
      <strong>€ <?= number_format((float)$yt['total_complessivo'],2,',','.') ?></strong>
    </span>
  </div>
  <?php if ($crescitaSmc || $crescitaSpesa): ?>
    <div style="display:flex; gap:16px; align-items:center; font-size:1rem;">
      <?= $crescitaSmc ? gasGrowthBadge($crescitaSmc, 'Consumo', 'Smc') : '' ?>
      <?= $crescitaSpesa ? gasGrowthBadge($crescitaSpesa, 'Spesa', '€') : '' ?>
      <span class="muted" style="font-size:0.85rem;">rispetto al <?= (int)$yt['year'] - 1 ?></span>
    </div>
  <?php endif; ?>
</li>
<?php endforeach; ?>

</section>

<?php
/* Dati grafico */
$labels = ["Gen","Feb","Mar","Apr","Mag","Giu","Lug","Ago","Set","Ott","Nov","Dic"];
$dataSets = [];

foreach ($billsByYear as $year => $bills) {
  $vals = array_fill(0,12,0);
  foreach ($bills as $b) {
    $vals[(int)$b['mese_num']-1] = (float)($b['smc'] ?? 0);
  }
  $dataSets[] = [
    'label' => "Anno $year",
    'data' => $vals,
    'tension' => 0.3
  ];
}
?>

<script>
new Chart(document.getElementById('gasChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: <?= json_encode($dataSets) ?>
  },
  options: {
    responsive: true,
    maintainAspectRatio: false
  }
});
</script>
