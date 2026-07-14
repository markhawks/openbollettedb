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
  SELECT
    strftime('%Y', COALESCE(b.period_start, b.issue_date)) AS anno,
    strftime('%m', COALESCE(b.period_start, b.issue_date)) AS mese,
    SUM(mk.value) AS kwh,
    SUM(b.amount_total) AS importo_luce
  FROM bills b
  LEFT JOIN bill_metrics mk
    ON b.id = mk.bill_id AND mk.key = 'kwh'
  WHERE b.utility_id = ?
  AND strftime('%Y', COALESCE(b.period_start, b.issue_date)) >= '2021'
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
WHERE strftime('%Y',COALESCE(period_start,issue_date)) >= '2021'
GROUP BY year
ORDER BY year DESC
");

$sumStmt->execute();
$yearTotals = $sumStmt->fetchAll(PDO::FETCH_ASSOC);

$mesiItaliani = [
  "01"=>"Gennaio","02"=>"Febbraio","03"=>"Marzo","04"=>"Aprile",
  "05"=>"Maggio","06"=>"Giugno","07"=>"Luglio","08"=>"Agosto",
  "09"=>"Settembre","10"=>"Ottobre","11"=>"Novembre","12"=>"Dicembre"
];




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
  AND strftime('%Y', COALESCE(b.period_start, b.issue_date)) >= '2021'
  GROUP BY anno, mese
");
/* GAS */
$gasId = (int)$utility['id'];
$stmt->execute([$gasId]);
$gasRows = $stmt->fetchAll(PDO::FETCH_ASSOC);




$data = [];

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
                'importo_gas'=>0
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
}

/* riempi gas */
foreach ($gasRows as $r) {
    $y=$r['anno'];
    $m=$r['mese'];

    if (!isset($data[$y])) continue;

    $data[$y][$m]['smc']=(float)$r['smc'];
    $data[$y][$m]['importo_gas']=(float)$r['importo_gas'];
}









/* ordina */
krsort($data);
foreach ($data as &$months) {
    krsort($months);
}

/* Ordino */
krsort($data);
foreach ($data as &$months) {
  krsort($months);
}



?>





<header class="topbar">
  <div>
    <h1>💡🔥 Luce + Gas</h1>
    <div class="sub">Storico consumi e costi Luce + Gas</div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</header>

<!-- GRAFICO CONSUMI -->
<section class="card">
  <h2>Andamento Consumi Luce + Gas</h2>
  <div style="height:300px">
    <canvas id="consumptionChart"></canvas>

      <div id="yearFilters" style="margin-top:15px; display:flex; gap:12px; flex-wrap:wrap;"></div>

  </div>
</section>

<!-- TABELLE PER ANNO -->
<?php foreach ($data as $year => $months): ?>
<section class="card">
  <h2>Bollette Luce + Gas <?= $year ?></h2>
  <table>
    <thead>
      <tr>
        <th class="muted">#</th>
        <th>Mese</th>
        <th class="right">kWh</th>
        <th class="right">Importo Luce</th>
        <th class="right">Smc</th>
        <th class="right">Importo Gas</th>
        <th class="right">Totale Mese</th>
      </tr>
    </thead>
    <tbody>
<?php
foreach ($months as $m => $v):
  $totaleMese = $v['importo_luce'] + $v['importo_gas'];
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



</tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

</tbody>









<!-- foreach RIEPILOGO -->
<section class="card">
  <h2>Riepilogo Annuale</h2>
  <ul class="list">

<?php foreach ($yearTotals as $yt): 

  $luceTot = (float)$yt['luce_totale'];
  $gasTot  = (float)$yt['gas_totale'];

  $kwhTot  = (float)$yt['total_kwh'];
  $smcTot  = (float)$yt['total_smc'];

  $totale = $luceTot + $gasTot;

  $mediaLuce = $luceTot / 12;
  $mediaGas  = $gasTot / 12;
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

    /* default ON solo 2025 e 2026 */
    if (year === "2025" || year === "2026") {
      checkbox.checked = true;
      chart.setDatasetVisibility(index, true);
    } else {
      checkbox.checked = false;
      chart.setDatasetVisibility(index, false);
    }

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
