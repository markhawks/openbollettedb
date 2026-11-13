<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_login();

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
  SELECT
    strftime('%Y', COALESCE(b.period_start, b.issue_date)) AS anno,
    strftime('%m', COALESCE(b.period_start, b.issue_date)) AS mese,
    SUM(mk.value) AS kwh,
    SUM(b.amount_total) AS importo_luce
  FROM bills b
  LEFT JOIN bill_metrics mk
    ON b.id = mk.bill_id AND mk.key = 'kwh'
  WHERE b.utility_id = ?
  GROUP BY anno, mese
");
/* LUCE */
$luceId = (int)$utility['id'];
$stmt->execute([$luceId]);
$luceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* Raggruppo per anno 
$billsByYear = [];
foreach ($allBills as $b) {
  $billsByYear[$b['anno']][] = $b;
} */

/* Totali annuali LUCE e GAS */
$sumStmt = $pdo->prepare("
SELECT 
  strftime('%Y', COALESCE(period_start,issue_date)) AS year,

  /* totale luce */
  SUM(CASE WHEN utility_id = (
      SELECT id FROM utilities WHERE code='luce'
  ) THEN amount_total ELSE 0 END) AS luce_totale,

  /* totale gas */
  SUM(CASE WHEN utility_id = (
      SELECT id FROM utilities WHERE code='gas'
  ) THEN amount_total ELSE 0 END) AS gas_totale,

  COUNT(DISTINCT CASE WHEN utility_id = (SELECT id FROM utilities WHERE code='luce')
    THEN strftime('%Y-%m', COALESCE(period_start,issue_date)) END) AS luce_months,
  COUNT(DISTINCT CASE WHEN utility_id = (SELECT id FROM utilities WHERE code='gas')
    THEN strftime('%Y-%m', COALESCE(period_start,issue_date)) END) AS gas_months,

  /* consumo gas */
  (
    SELECT SUM(value)
    FROM bill_metrics m
    JOIN bills b2 ON m.bill_id=b2.id
    WHERE m.key='smc'
    AND strftime('%Y',COALESCE(b2.period_start,b2.issue_date)) =
        strftime('%Y',COALESCE(b.period_start,b.issue_date))
  ) AS total_smc,

  /* consumo luce */
  (
    SELECT SUM(value)
    FROM bill_metrics m
    JOIN bills b2 ON m.bill_id=b2.id
    WHERE m.key='kwh'
    AND strftime('%Y',COALESCE(b2.period_start,b2.issue_date)) =
        strftime('%Y',COALESCE(b.period_start,b.issue_date))
  ) AS total_kwh

FROM bills b
WHERE utility_id IN (SELECT id FROM utilities WHERE code IN ('luce','gas'))
GROUP BY year
ORDER BY year DESC
");

$sumStmt->execute();
$yearTotals = $sumStmt->fetchAll(PDO::FETCH_ASSOC);

$mesiItaliani = localized_months();




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
  SELECT
    strftime('%Y', COALESCE(b.period_start, b.issue_date)) AS anno,
    strftime('%m', COALESCE(b.period_start, b.issue_date)) AS mese,
    SUM(ms.value) AS smc,
    SUM(b.amount_total) AS importo_gas
  FROM bills b
  LEFT JOIN bill_metrics ms
    ON b.id = ms.bill_id AND ms.key = 'smc'
  WHERE b.utility_id = ?
  GROUP BY anno, mese
");
/* GAS */
$gasId = (int)$utility['id'];
$stmt->execute([$gasId]);
$gasRows = $stmt->fetchAll(PDO::FETCH_ASSOC);




$data = [];
$gasSupplyEndedFrom = '2026-07';

/* inizializza struttura anni */
foreach ($luceRows as $r) {
    $y = $r['anno'];
    if (!isset($data[$y])) {
        for ($i=1;$i<=12;$i++) {
            $m = str_pad((string)$i,2,"0",STR_PAD_LEFT);
            $data[$y][$m] = [
                'kwh'=>0,
                'importo_luce'=>0,
                'smc'=>0,
                'importo_gas'=>0,
                'has_data'=>false,
                'has_luce'=>false,
                'has_gas'=>false
            ];
        }
    }
}

/* riempi luce */
foreach ($luceRows as $r) {
    $y=$r['anno'];
    $m=$r['mese'];

    $data[$y][$m]['kwh']=(float)$r['kwh'];
    $data[$y][$m]['importo_luce']=(float)$r['importo_luce'];
    $data[$y][$m]['has_data']=true;
    $data[$y][$m]['has_luce']=true;
}

/* riempi gas */
foreach ($gasRows as $r) {
    $y=$r['anno'];
    $m=$r['mese'];

    if (!isset($data[$y])) {
        for ($i=1;$i<=12;$i++) {
            $month = str_pad((string)$i,2,"0",STR_PAD_LEFT);
            $data[$y][$month] = [
                'kwh'=>0, 'importo_luce'=>0, 'smc'=>0, 'importo_gas'=>0,
                'has_data'=>false, 'has_luce'=>false, 'has_gas'=>false
            ];
        }
    }

    $data[$y][$m]['smc']=(float)$r['smc'];
    $data[$y][$m]['importo_gas']=(float)$r['importo_gas'];
    $data[$y][$m]['has_data']=true;
    $data[$y][$m]['has_gas']=true;
}









/* ordina */
krsort($data);
foreach ($data as &$months) {
    krsort($months);
}
unset($months);

function monthlyCostDifference(?float $difference, ?float $previous): string
{
    if ($difference === null) return '<span class="muted">—</span>';
    $color = $difference > 0 ? '#dc2626' : ($difference < 0 ? '#16a34a' : '#64748b');
    $sign = $difference > 0 ? '+' : ($difference < 0 ? '−' : '');
    $percentage = $previous !== null && $previous != 0.0
        ? (($difference / $previous) * 100)
        : null;
    $percentageText = $percentage !== null
        ? ' (' . ($percentage > 0 ? '+' : '') . number_format($percentage, 1, ',', '.') . '%)'
        : '';

    return '<strong style="color:' . $color . '; white-space:nowrap;">'
        . $sign . '€ ' . number_format(abs($difference), 2, ',', '.')
        . $percentageText . '</strong>';
}



?>





<header class="topbar">
  <div>
    <h1>💡🔥 <?= htmlspecialchars(t('dashboard.electricity_gas')) ?></h1>
    <div class="sub"><?= htmlspecialchars(t('dashboard.electricity_gas_sub')) ?></div>
  </div>
</header>

<!-- GRAFICO CONSUMI -->
<section class="card">
  <h2><?= htmlspecialchars(t('dashboard.consumption')) ?> <?= htmlspecialchars(t('dashboard.electricity_gas')) ?></h2>
  <div style="height:300px">
    <canvas id="consumptionChart"></canvas>

      <div id="yearFilters" style="margin-top:15px; display:flex; gap:12px; flex-wrap:wrap;"></div>

  </div>
</section>

<!-- TABELLE PER ANNO -->
<?php foreach ($data as $year => $months): ?>
<section class="card">
  <h2>
    <?= htmlspecialchars(t('dashboard.bills')) ?> <?= htmlspecialchars(t('dashboard.electricity_gas')) ?> <?= $year ?>
    <?php if ((int)$year >= 2026): ?>
      <span class="muted" style="font-size:0.85rem; margin-left:8px;">Gas cessato dal 01/07/2026</span>
    <?php endif; ?>
  </h2>
  <table>
    <thead>
      <tr>
        <th class="muted">#</th>
        <th><?= htmlspecialchars(t('common.month')) ?></th>
        <th class="right">kWh</th>
        <th class="right"><?= htmlspecialchars(t('dashboard.electricity_amount')) ?></th>
        <th class="right">Smc</th>
        <th class="right"><?= htmlspecialchars(t('dashboard.gas_amount')) ?></th>
        <th class="right"><?= htmlspecialchars(t('dashboard.month_total')) ?></th>
        <th class="right"><?= htmlspecialchars(t('dashboard.electricity_diff')) ?></th>
        <th class="right"><?= htmlspecialchars(t('dashboard.gas_diff')) ?></th>
        <th class="right"><?= htmlspecialchars(t('dashboard.total_diff')) ?></th>
      </tr>
    </thead>
    <tbody>
<?php
foreach ($months as $m => $v):
  $totaleMese = $v['importo_luce'] + $v['importo_gas'];
  $previousYear = (string)((int)$year - 1);
  $previousMonth = $data[$previousYear][$m] ?? null;
  $gasSupplyInactive = sprintf('%04d-%02d', (int)$year, (int)$m) >= $gasSupplyEndedFrom;

  $hasLuceComparison = !empty($v['has_luce'])
    && is_array($previousMonth) && !empty($previousMonth['has_luce']);
  $previousLuce = $hasLuceComparison ? (float)$previousMonth['importo_luce'] : null;
  $luceDifference = $hasLuceComparison ? (float)$v['importo_luce'] - $previousLuce : null;

  $hasGasComparison = is_array($previousMonth) && !empty($previousMonth['has_gas'])
    && (!empty($v['has_gas']) || $gasSupplyInactive);
  $previousGas = $hasGasComparison ? (float)$previousMonth['importo_gas'] : null;
  $currentGas = !empty($v['has_gas']) ? (float)$v['importo_gas'] : 0.0;
  $gasDifference = $hasGasComparison ? $currentGas - $previousGas : null;

  $hasTotalComparison = $luceDifference !== null && $gasDifference !== null;
  $previousTotal = $hasTotalComparison ? $previousLuce + $previousGas : null;
  $yearDifference = $hasTotalComparison ? $luceDifference + $gasDifference : null;
?>

<tr>

  <td class="muted"><?= $m ?></td>
  <td><strong><?= $mesiItaliani[$m] ?> <?= $year ?></strong></td>

  <td class="right">
    <?= $v['kwh'] ? number_format($v['kwh'],0,',','.') : '-' ?>
  </td>

  <td class="right importo-luce">
    <?= $v['importo_luce'] ? '€ '.number_format($v['importo_luce'],2,',','.') : '-' ?>
  </td>

  <td class="right">
    <?= $v['smc'] ? number_format($v['smc'],2,',','.') : '-' ?>
  </td>

  <td class="right importo-gas">
    <?= $v['importo_gas'] ? '€ '.number_format($v['importo_gas'],2,',','.') : '-' ?>
  </td>


  <td class="right importo-totale">
    <?= $totaleMese > 0
        ? '€ '.number_format($totaleMese,2,',','.')
        : '-' ?>
  </td>

  <td class="right" title="Confronto Luce con <?= htmlspecialchars($mesiItaliani[$m] . ' ' . $previousYear) ?>">
    <?= monthlyCostDifference($luceDifference, $previousLuce) ?>
  </td>

  <td class="right" title="<?= $gasSupplyInactive && empty($v['has_gas'])
      ? 'Fornitura Gas cessata: confronto con il costo Gas dello stesso mese dell’anno precedente'
      : 'Confronto Gas con ' . htmlspecialchars($mesiItaliani[$m] . ' ' . $previousYear) ?>">
    <?= monthlyCostDifference($gasDifference, $previousGas) ?>
  </td>

  <td class="right" title="Confronto complessivo con <?= htmlspecialchars($mesiItaliani[$m] . ' ' . $previousYear) ?>">
    <?= monthlyCostDifference($yearDifference, $previousTotal) ?>
  </td>



</tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

</tbody>









<!-- foreach RIEPILOGO -->
<section class="card">
  <h2><?= htmlspecialchars(t('common.annual_summary')) ?></h2>
  <ul class="list">

<?php foreach ($yearTotals as $yt): 

  $luceTot = (float)$yt['luce_totale'];
  $gasTot  = (float)$yt['gas_totale'];

  $kwhTot  = (float)$yt['total_kwh'];
  $smcTot  = (float)$yt['total_smc'];

  $totale = $luceTot + $gasTot;

  $mediaLuce = $luceTot / max(1, (int)$yt['luce_months']);
  $mediaGas  = $gasTot / max(1, (int)$yt['gas_months']);
?>

<li style="flex-direction:column;gap:10px;padding:15px 0">

<!-- riga titolo -->
<div style="display:flex;justify-content:space-between;width:100%">
  <strong>Anno <?= $yt['year'] ?></strong>
  <strong style="font-size:1.1rem">
    € <?= number_format($totale,2,',','.') ?>
  </strong>
</div>


<!-- LUCE -->
<div style="display:flex;justify-content:space-between;font-size:0.9rem">

<div>
⚡ Energia  
<?= number_format($kwhTot,0,',','.') ?> kWh
</div>

<div>
Costo luce  
€ <?= number_format($luceTot,2,',','.') ?>
</div>

<div>
Media mese  
€ <?= number_format($mediaLuce,2,',','.') ?>
</div>

</div>


<!-- GAS -->
<div style="display:flex;justify-content:space-between;font-size:0.9rem">

<div>
🔥 Gas  
<?= number_format($smcTot,0,',','.') ?> Smc
</div>

<div>
Costo gas  
€ <?= number_format($gasTot,2,',','.') ?>
</div>

<div>
Media mese  
€ <?= number_format($mediaGas,2,',','.') ?>
</div>

</div>


</li>

<?php endforeach; ?>


    
  </ul>
</section>


<?php
$labels = ["Gen","Feb","Mar","Apr","Mag","Giu","Lug","Ago","Set","Ott","Nov","Dic"];
$dataSets = [];
$colors = ['#2563eb', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed'];

$i = 0;
// Usiamo una copia di $data per non rovinare l'ordinamento della tabella sottostante
$chartData = $data; 
ksort($chartData); // Ordiniamo gli anni in modo crescente per la legenda del grafico

foreach ($chartData as $year => $months) {
    // Prepariamo 12 slot vuoti (uno per mese)
    $vals = array_fill(0, 12, 0);

    foreach ($months as $m => $v) {
        $monthIndex = (int)$m - 1; // "01" diventa 0, "12" diventa 11
        if ($monthIndex >= 0 && $monthIndex <= 11) {
            $totaleMese = (float)$v['importo_luce'] + (float)$v['importo_gas'];
            $vals[$monthIndex] = round($totaleMese, 2);
        }
    }

    $color = $colors[$i % count($colors)];
    $dataSets[] = [
        'label' => "Totale $year",
        'data' => $vals,
        'borderColor' => $color,
        'backgroundColor' => $color,
        'borderWidth' => 2,
        'tension' => 0.3,
        'fill' => false,
        'pointRadius' => 4
    ];
    $i++;
}
?>



<script>

document.addEventListener('DOMContentLoaded', function () {

  const ctx = document.getElementById('consumptionChart');
  if (!ctx) return;

  const datasets = <?= json_encode($dataSets) ?>;

  const chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($labels) ?>,
      datasets: datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: 'index',
        intersect: false
      },
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          ticks: {
            callback: value => '€ ' + value
          }
        }
      }
    }
  });

  /* ---------- FILTRI ANNO ---------- */

  const filterDiv = document.getElementById('yearFilters');

  chart.data.datasets.forEach((dataset, index) => {

    const year = dataset.label.replace("Totale ","");

    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.id = "year_" + year;
    checkbox.dataset.index = index;

    // Tutti gli anni disponibili sono visibili al caricamento.
    checkbox.checked = true;
    chart.setDatasetVisibility(index, true);

    checkbox.addEventListener("change", function () {
      const i = this.dataset.index;
      chart.setDatasetVisibility(i, this.checked);
      chart.update();
    });

    const label = document.createElement("label");
    label.htmlFor = checkbox.id;
    label.style.cursor = "pointer";
    label.style.fontSize = "0.9rem";
    label.appendChild(checkbox);
    label.appendChild(document.createTextNode(" " + year));

    filterDiv.appendChild(label);

  });

  chart.update();

});

</script>
