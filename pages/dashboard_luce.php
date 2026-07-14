<?php
declare(strict_types=1);
require __DIR__ . '/../app/db.php';

$pdo = db();

/* Recupero utility LUCE */
$utStmt = $pdo->prepare("SELECT id, name FROM utilities WHERE code = 'luce'");
$utStmt->execute();
$utility = $utStmt->fetch(PDO::FETCH_ASSOC);
if (!$utility) {
  die("Utility LUCE non trovata");
}
$utilityId = (int)$utility['id'];

/* Query bollette LUCE */
$stmt = $pdo->prepare("
  SELECT b.*,
         strftime('%m', b.period_start) AS mese_num,
         strftime('%Y', b.period_start) AS anno,
         m1.value AS kwh,
         m2.value AS canone_rai,
         m3.value AS extra_adjust,
         m4.value AS energy_price,
         m5.value AS commercial_fee,
         m6.value AS stima
  FROM bills b
  LEFT JOIN bill_metrics m1 ON b.id = m1.bill_id AND m1.key = 'kwh'
  LEFT JOIN bill_metrics m2 ON b.id = m2.bill_id AND m2.key = 'canone_rai'
  LEFT JOIN bill_metrics m3 ON b.id = m3.bill_id AND m3.key = 'extra_adjust'
  LEFT JOIN bill_metrics m4 ON b.id = m4.bill_id AND m4.key = 'energy_price'
  LEFT JOIN bill_metrics m5 ON b.id = m5.bill_id AND m5.key = 'commercial_fee'
  LEFT JOIN bill_metrics m6 ON b.id = m6.bill_id AND m6.key = 'stima'
  WHERE b.utility_id = ?
  ORDER BY anno DESC, mese_num DESC
");
$stmt->execute([$utilityId]);
$allBills = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Raggruppo per anno */
$billsByYear = [];
foreach ($allBills as $b) {
  $billsByYear[$b['anno']][] = $b;
}

/* Totali annuali LUCE */
$sumStmt = $pdo->prepare("
  SELECT 
    strftime('%Y', period_start) AS year, 
    SUM(amount_total) AS total_complessivo,
    (
      SELECT SUM(value) 
      FROM bill_metrics m 
      JOIN bills b2 ON m.bill_id = b2.id 
      WHERE strftime('%Y', b2.period_start) = strftime('%Y', b.period_start) 
        AND m.key = 'canone_rai'
        AND b2.utility_id = ?
    ) AS total_canone,
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
        AND m.key = 'kwh'
        AND b2.utility_id = ?
    ) AS total_kwh,
    SUM(CASE WHEN EXISTS (
      SELECT 1 FROM bill_metrics sm
      WHERE sm.bill_id = b.id AND sm.key = 'stima' AND sm.value = 1
    ) THEN amount_total ELSE 0 END) AS total_stima,
    SUM(CASE WHEN NOT EXISTS (
      SELECT 1 FROM bill_metrics sm
      WHERE sm.bill_id = b.id AND sm.key = 'stima' AND sm.value = 1
    ) THEN amount_total ELSE 0 END) AS total_reale
  FROM bills b
  WHERE utility_id = ?
  GROUP BY year
  ORDER BY year DESC
");

$sumStmt->execute([$utilityId, $utilityId, $utilityId, $utilityId]);
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
    'kwh'    => (float)$yt['total_kwh'],
    'totale' => (float)$yt['total_complessivo'],
  ];
}

function luceCalcCrescita(?float $curr, ?float $prev): ?array {
  if ($prev === null || $prev == 0.0 || $curr === null) return null;
  $delta = $curr - $prev;
  $pct   = ($delta / $prev) * 100;
  return [$delta, $pct];
}

function luceGrowthBadge(array $crescita, string $label, string $unit): string {
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
    <h1>💡 Energia Elettrica</h1>
    <div class="sub">Storico consumi e costi</div>
  </div>
  <a class="btn" href="new_bill.php?u=luce">+ Nuova bolletta</a>
</header>

<!-- GRAFICO CONSUMI -->
<section class="card">
  <h2>Andamento Consumi (kWh)</h2>
  <div style="height:300px">
    <canvas id="consumptionChart"></canvas>
  </div>
</section>

<!-- TABELLE PER ANNO -->
<?php foreach ($billsByYear as $year => $bills):
  $prevYear = (int)$year - 1;
  $curr = $yearMap[(int)$year] ?? null;
  $prev = $yearMap[$prevYear] ?? null;
  $crescitaKwh   = ($curr && $prev) ? luceCalcCrescita($curr['kwh'], $prev['kwh']) : null;
  $crescitaSpesa = ($curr && $prev) ? luceCalcCrescita($curr['totale'], $prev['totale']) : null;
?>
<section class="card">
  <div class="card-year-header">
    <h2>
      Bollette <?= $year ?>
      <?php if ($crescitaKwh || $crescitaSpesa): ?>
        <span style="font-size:1rem; margin-left:12px; display:inline-flex; gap:14px; vertical-align:middle;">
          <?= $crescitaKwh ? luceGrowthBadge($crescitaKwh, 'Consumo', 'kWh') : '' ?>
          <?= $crescitaSpesa ? luceGrowthBadge($crescitaSpesa, 'Spesa', '€') : '' ?>
          <span class="muted" style="font-size:0.85rem; align-self:center;">rispetto al <?= $prevYear ?></span>
        </span>
      <?php endif; ?>
    </h2>
    <a class="btn-reset-year"
       href="reset_year.php?u=luce&year=<?= $year ?>&csrf=<?= urlencode($csrfToken) ?>"
       onclick="return confirmResetAnno('Luce', <?= $year ?>);"
       title="Elimina tutte le bollette Luce di questo anno">
      🗑️ Svuota anno
    </a>
  </div>
  <table>
    <thead>
      <tr>
        <th class="muted">#</th>
        <th>Mese</th>
        <th>Periodo</th>
        <th class="right">kWh</th>
        <th class="right">Materia €/kWh</th>
        <th class="right">Comm. €/mese</th>
        <th class="right">Canone</th>
        <th class="right">Extra</th>
        <th class="right">Importo</th>
        <th class="right">€/kWh</th>
        <th>Note</th>
        <th style="text-align:center;">Azioni</th>
      </tr>
    </thead>
<tbody>
<?php
foreach ($bills as $b):
  $canone = (float)($b['canone_rai'] ?? 0);
  $extra  = (float)($b['extra_adjust'] ?? 0);
  $netto  = (float)$b['amount_total'] - $canone - $extra;
  $media  = ($b['kwh'] > 0) ? $netto / (float)$b['kwh'] : 0;
  $energyPrice = isset($b['energy_price']) ? (float)$b['energy_price'] : 0;
  $commFee     = isset($b['commercial_fee']) ? (float)$b['commercial_fee'] : 0;
  $hasEnergyPrice = ($b['energy_price'] !== null && $b['energy_price'] !== '');
  $hasCommFee     = ($b['commercial_fee'] !== null && $b['commercial_fee'] !== '');
  $isStima        = !empty($b['stima']);
?>
  <tr<?= $isStima ? ' style="opacity:0.7; font-style:italic;"' : '' ?>>
    <td class="muted"><?= $b['mese_num'] ?></td>
    <td>
      <strong><?= $mesiItaliani[$b['mese_num']] ?></strong>
      <?php if ($isStima): ?>
        <span title="Bolletta stimata, non ancora reale"
              style="margin-left:6px; padding:2px 8px; background:#fef3c7; color:#92400e;
                     border-radius:10px; font-size:0.75rem; font-style:normal;">
          📊 Stima
        </span>
      <?php endif; ?>
    </td>
    <td class="muted">
      <?= date('d/m/Y', strtotime($b['period_start'])) ?> –
      <?= date('d/m/Y', strtotime($b['period_end'])) ?>
    </td>
    <td class="right">
      <?= $b['kwh'] ? number_format((float)$b['kwh'], 0, ',', '.') : '-' ?>
    </td>
    <td class="right">
      <?= $hasEnergyPrice ? '€ '.number_format($energyPrice, 4, ',', '.') : '-' ?>
    </td>
    <td class="right">
      <?= $hasCommFee ? '€ '.number_format($commFee, 2, ',', '.') : '-' ?>
    </td>
    <td class="right" style="color:#d63384;">
      <?= $canone != 0 ? '€ '.number_format($canone,2,',','.') : '-' ?>
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
    <td style="text-align:center; white-space: nowrap;">
      <a href="edit_bill.php?id=<?= $b['id'] ?>&u=luce"
        title="Modifica"
        style="text-decoration:none; margin-right:8px;">
        ✏️
      </a>
      <a href="delete_bill.php?id=<?= $b['id'] ?>&u=luce&csrf=<?= urlencode($csrfToken) ?>"
        title="Elimina"
        style="text-decoration:none;"
        onclick="return confirm('Eliminare questa bolletta?');">
        🗑️
      </a>
    </td>

  </tr>
<?php endforeach; ?>
</tbody>


  </table>
</section>
<?php endforeach; ?>






<!-- foreach RIEPILOGO -->
<section class="card">
  <h2>Riepilogo Annuale</h2>
  <ul class="list">

    <?php foreach ($yearTotals as $yt):
      $canoneTot = (float)$yt['total_canone'];
      $extraTot  = (float)$yt['total_extra'];
      $kwhTot    = (float)$yt['total_kwh'];
      $totale    = (float)$yt['total_complessivo'];
      $stimaTot  = (float)$yt['total_stima'];
      $realeTot  = (float)$yt['total_reale'];

      $energiaPura = $totale - $canoneTot - $extraTot;
      $mediaMese   = $totale / 12;

      $prevYear = (int)$yt['year'] - 1;
      $curr = $yearMap[(int)$yt['year']] ?? null;
      $prev = $yearMap[$prevYear] ?? null;
      $crescitaKwh   = ($curr && $prev) ? luceCalcCrescita($curr['kwh'], $prev['kwh']) : null;
      $crescitaSpesa = ($curr && $prev) ? luceCalcCrescita($curr['totale'], $prev['totale']) : null;
    ?>
      <li style="flex-direction: column; align-items: flex-start; gap: 8px; padding: 15px 0;">
        
        <!-- Riga principale -->
        <div style="width:100%; display:flex; justify-content:space-between;">
          <span>
            <strong>Anno <?= $yt['year'] ?></strong>
            <span style="margin-left:10px; padding:2px 8px; background:#e2e8f0;
                         border-radius:10px; font-size:0.8rem; color:#475569;">
              Consumo: <?= number_format($kwhTot,0,',','.') ?> kWh
            </span>
          </span>
          <span style="font-size:1.1rem;">
            <strong>€ <?= number_format($totale,2,',','.') ?></strong>
          </span>
        </div>

        <!-- Riga dettagli -->
        <div style="width:100%; display:flex; justify-content:space-between; font-size:0.85rem;">
          <div style="color:#64748b;">
            Energia: € <?= number_format($energiaPura,2,',','.') ?> |
            <span style="color:#d63384;">Canone: € <?= number_format($canoneTot,2,',','.') ?></span> |
            <span style="color:#16a34a;">Extra/Bonus: € <?= number_format($extraTot,2,',','.') ?></span>
          </div>
          <div style="text-align:right; color:#0f172a;">
            <strong>Media: € <?= number_format($mediaMese,2,',','.') ?> / mese</strong>
          </div>
        </div>

        <?php if ($crescitaKwh || $crescitaSpesa): ?>
        <!-- Riga crescita rispetto all'anno precedente -->
        <div style="width:100%; display:flex; gap:16px; align-items:center; font-size:1rem;">
          <?= $crescitaKwh ? luceGrowthBadge($crescitaKwh, 'Consumo', 'kWh') : '' ?>
          <?= $crescitaSpesa ? luceGrowthBadge($crescitaSpesa, 'Spesa', '€') : '' ?>
          <span class="muted" style="font-size:0.85rem;">rispetto al <?= $prevYear ?></span>
        </div>
        <?php endif; ?>

        <?php if ($stimaTot > 0): ?>
        <!-- Riga split reale/stima -->
        <div style="width:100%; display:flex; justify-content:flex-end; font-size:0.85rem;">
          <div style="color:#64748b;">
            Reale: € <?= number_format($realeTot,2,',','.') ?> |
            <span style="color:#92400e;">📊 Stimato: € <?= number_format($stimaTot,2,',','.') ?></span>
          </div>
        </div>
        <?php endif; ?>

      </li>
    <?php endforeach; ?>
  </ul>
</section>


<?php
/* Dati grafico */
$labels = ["Gen","Feb","Mar","Apr","Mag","Giu","Lug","Ago","Set","Ott","Nov","Dic"];
$dataSets = [];
$stimaFlags = []; // parallelo a $dataSets: per ogni anno, quali mesi sono stimati

foreach ($billsByYear as $year => $bills) {
  $vals = array_fill(0,12,0);
  $stime = array_fill(0,12,false);
  foreach ($bills as $b) {
    $idx = (int)$b['mese_num']-1;
    $vals[$idx]  = (float)($b['kwh'] ?? 0);
    $stime[$idx] = !empty($b['stima']);
  }
  $dataSets[] = [
    'label' => "Anno $year",
    'data' => $vals,
    'tension' => 0.3
  ];
  $stimaFlags[] = $stime;
}
?>

<script>
const stimaFlagsPerAnno = <?= json_encode($stimaFlags) ?>;

const luceDatasets = <?= json_encode($dataSets) ?>.map((ds, i) => ({
  ...ds,
  pointStyle: ds.data.map((_, idx) => stimaFlagsPerAnno[i][idx] ? 'rectRot' : 'circle'),
  segment: {
    borderDash: (ctx) => {
      const flags = stimaFlagsPerAnno[i];
      const isStima = flags[ctx.p0DataIndex] || flags[ctx.p1DataIndex];
      return isStima ? [6, 6] : undefined;
    }
  }
}));

new Chart(document.getElementById('consumptionChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: luceDatasets
  },
  options: {
    responsive: true,
    maintainAspectRatio: false
  }
});
</script>


