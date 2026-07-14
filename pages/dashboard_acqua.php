<?php
declare(strict_types=1);
require __DIR__ . '/../app/db.php';

$pdo = db();

/* Utility ACQUA */
$utStmt = $pdo->prepare("SELECT id, name FROM utilities WHERE code = 'acqua'");
$utStmt->execute();
$utility = $utStmt->fetch(PDO::FETCH_ASSOC);
if (!$utility) die("Utility ACQUA non trovata");
$utilityId = (int)$utility['id'];

/* Bollette */
$stmt = $pdo->prepare("
  SELECT 
    b.id,
    b.amount_total,
    b.notes,
    b.period_start,
    b.period_end,
    b.issue_date,
    strftime('%Y', COALESCE(b.issue_date, b.period_start)) AS year,
    m1.value AS mc_start,
    m2.value AS mc_end,
    m3.value AS consumo_mc,
    m4.value AS mc_conguaglio
  FROM bills b
  LEFT JOIN bill_metrics m1 ON b.id = m1.bill_id AND m1.key = 'mc_start'
  LEFT JOIN bill_metrics m2 ON b.id = m2.bill_id AND m2.key = 'mc_end'
  LEFT JOIN bill_metrics m3 ON b.id = m3.bill_id AND m3.key = 'consumo_mc'
  LEFT JOIN bill_metrics m4 ON b.id = m4.bill_id AND m4.key = 'mc_conguaglio'
  WHERE b.utility_id = ?
  ORDER BY 
    strftime('%Y', COALESCE(b.issue_date, b.period_start)) DESC,
    COALESCE(b.issue_date, b.period_start) DESC
");


$stmt->execute([$utilityId]);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* Raggruppa per anno */
$billsByYear = [];
foreach ($bills as $b) {
  $billsByYear[$b['year']][] = $b;
}

/* Grafico */
$stmtChart = $pdo->prepare("
  SELECT
    strftime('%Y', COALESCE(b.issue_date, b.period_start)) AS year,
    SUM(
      CAST(COALESCE(m_cons.value, 0) AS REAL) +
      CAST(COALESCE(m_cong.value, 0) AS REAL)
    ) AS consumo
  FROM bills b
  LEFT JOIN bill_metrics m_cons
    ON b.id = m_cons.bill_id AND m_cons.key = 'consumo_mc'
  LEFT JOIN bill_metrics m_cong
    ON b.id = m_cong.bill_id AND m_cong.key = 'mc_conguaglio'
  WHERE b.utility_id = ?
  GROUP BY year
  ORDER BY year ASC
");
$stmtChart->execute([$utilityId]);
$chartData = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

$years   = array_column($chartData, 'year');
$consumi = array_map(fn($v) => (float)$v, array_column($chartData, 'consumo'));

?>





<!-- HEADER -->
  <header class="topbar">
    <div>
      <h1>💧 Acqua</h1>
      <div class="sub">Gestione bollette acqua</div>
    </div>
    <a class="btn" href="new_bill.php?u=acqua">+ Nuova Bolletta</a>
  </header>

  <?php if (!$billsByYear): ?>
<section class="card muted" style="text-align:center;">
  Nessuna bolletta acqua inserita.
</section>
<?php endif; ?>




<section class="card">
  <h2>Consumo Acqua per Anno (m³)</h2>
  <div style="height:280px">
    <canvas id="acquaConsumiChart"></canvas>
  </div>
</section>

<section class="card">
  <h2>Riepilogo Annuale</h2>

  <ul class="list">
    <?php foreach ($billsByYear as $year => $items):

      $totConsumo = 0.0;
      $totSpesa   = 0.0;

      foreach ($items as $b) {
        $consumoLordo = (float)$b['consumo_mc'];
        $conguaglio   = (float)($b['mc_conguaglio'] ?? 0);
        $consumoNetto = $consumoLordo + $conguaglio;
        $totConsumo += $consumoNetto;
        $totSpesa   += (float)$b['amount_total'];
      }

      $costoMedio = $totConsumo > 0 ? $totSpesa / $totConsumo : 0;
    ?>
      <li style="flex-direction:column; gap:6px;">
        <div style="display:flex; justify-content:space-between;">
          <strong><?= $year ?></strong>
          <strong>€ <?= number_format($totSpesa, 2, ',', '.') ?></strong>
        </div>

        <div class="muted">
          Consumo: <?= number_format($totConsumo, 1, ',', '.') ?> m³ |
          Costo medio: € <?= number_format($costoMedio, 2, ',', '.') ?>/m³ |
          Bollette: <?= count($items) ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>


<?php foreach ($billsByYear as $year => $items): ?>
<section class="card">
  <div class="card-year-header">
    <h2>Bollette Acqua – <?= $year ?></h2>
    <a class="btn-reset-year"
       href="reset_year.php?u=acqua&year=<?= $year ?>&csrf=<?= urlencode($csrfToken) ?>"
       onclick="return confirm('Eliminare TUTTE le bollette Acqua del <?= $year ?>? Operazione irreversibile.');"
       title="Elimina tutte le bollette Acqua di questo anno">
      🗑️ Svuota anno
    </a>
  </div>

  <table>

<thead>
  <tr>
    <th>Data immissione</th>
    <th>Periodo</th>
    <th>Letture</th>
    <th class="right">Conguaglio m³</th>
    <th class="right">Consumo m³</th>
    <th class="right">€/m³</th>
    <th class="right">Importo €</th>
    <th>Note</th>
    <th style="text-align:center;">Azioni</th>
  </tr>
</thead>


<tbody>
<?php foreach ($items as $b):

  $consumoLordo = (float)$b['consumo_mc'];
  $conguaglio   = (float)($b['mc_conguaglio'] ?? 0);
  $consumoNetto = $consumoLordo + $conguaglio;

  $costoMc = $consumoNetto > 0
    ? $b['amount_total'] / $consumoNetto
    : null;
?>
<tr>

  <!-- DATA IMMISSIONE (prima colonna) -->
  <td>
    <?= $b['issue_date']
      ? date('d/m/Y', strtotime($b['issue_date']))
      : '-' ?>
  </td>

  <!-- PERIODO -->
  <td class="muted">
    <?= date('d/m/Y', strtotime($b['period_start'])) ?> —
    <?= date('d/m/Y', strtotime($b['period_end'])) ?>
  </td>

  <!-- LETTURE -->
  <td class="muted">
  <?= ($b['mc_start'] !== null && $b['mc_end'] !== null)
      ? $b['mc_start'].' → '.$b['mc_end']
      : '-' ?>
  </td>
  
  <!-- CONGUAGLIO -->
  <td class="right">
    <?php if ($conguaglio != 0): ?>
      <strong style="color:<?= $conguaglio < 0 ? '#b45309' : '#065f46' ?>">
        <?= number_format($conguaglio,1,',','.') ?>
      </strong>
    <?php else: ?>
      <span class="muted">0,0</span>
    <?php endif; ?>
  </td>

  <!-- CONSUMO NETTO -->
  <td class="right">
    <strong><?= number_format($consumoNetto,1,',','.') ?></strong>
  </td>

  <!-- COSTO -->
  <td class="right">
    <?= $costoMc !== null
      ? '€ '.number_format($costoMc,2,',','.')
      : '-' ?>
  </td>

  <!-- IMPORTO -->
  <td class="right">
    <strong>€ <?= number_format((float)$b['amount_total'],2,',','.') ?></strong>
  </td>

  <td class="muted"><?= nl2br(htmlspecialchars($b['notes'] ?? '')) ?></td>

  <td style="text-align:center;">
    <a href="edit_bill.php?id=<?= $b['id'] ?>&u=acqua">✏️</a>
    <a href="delete_bill.php?id=<?= $b['id'] ?>&u=acqua&csrf=<?= urlencode($csrfToken) ?>"
       onclick="return confirm('Eliminare questa bolletta acqua?');">🗑️</a>
  </td>
</tr>
<?php endforeach; ?>



<script>
new Chart(document.getElementById('acquaConsumiChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($years) ?>,
    datasets: [{
      label: 'Consumo acqua (m³)',
      data: <?= json_encode($consumi) ?>,
      borderWidth: 1
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      y: {
        beginAtZero: true,
        title: {
          display: true,
          text: 'm³'
        }
      }
    }
  }
});
</script>







</tbody>

</table>

</section>

<?php endforeach; ?>
