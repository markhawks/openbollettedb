<?php
declare(strict_types=1);
require __DIR__ . '/../app/db.php';

$pdo = db();

/* Recuperiamo utility TARI */
$utStmt = $pdo->prepare("SELECT id, name FROM utilities WHERE code = 'tari'");
$utStmt->execute();
$utility = $utStmt->fetch(PDO::FETCH_ASSOC);
if (!$utility) {
  die("Utility TARI non trovata");
}
$utilityId = (int)$utility['id'];

/* Recupero bollette TARI */
$stmt = $pdo->prepare("
  SELECT 
    b.id,
    b.amount_total,
    b.notes,
    b.period_start,
    b.period_end,
    strftime('%Y', b.period_start) AS year,
    m1.value AS raccolta_diff,
    m2.value AS tipo_avviso,
    m3.value AS numero_fattura,
    m4.value AS periodo_competenza,
    m5.value AS data_fattura
  FROM bills b
  LEFT JOIN bill_metrics m1 ON b.id = m1.bill_id AND m1.key = 'raccolta_diff'
  LEFT JOIN bill_metrics m2 ON b.id = m2.bill_id AND m2.key = 'tipo_avviso'
  LEFT JOIN bill_metrics m3 ON b.id = m3.bill_id AND m3.key = 'numero_fattura'
  LEFT JOIN bill_metrics m4 ON b.id = m4.bill_id AND m4.key = 'periodo_competenza'
  LEFT JOIN bill_metrics m5 ON b.id = m5.bill_id AND m5.key = 'data_fattura'

  WHERE b.utility_id = ?
  ORDER BY year DESC, b.period_start DESC
");
$stmt->execute([$utilityId]);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Raggruppiamo per anno */
$billsByYear = [];
foreach ($bills as $b) {
  $billsByYear[$b['year']][] = $b;
}
?>

<?php
$stmtChart = $pdo->prepare("
  SELECT 
    strftime('%Y', period_start) AS year,
    SUM(amount_total) AS totale
  FROM bills
  WHERE utility_id = (
    SELECT id FROM utilities WHERE code = 'tari'
  )
  GROUP BY year
  ORDER BY year ASC
");
$stmtChart->execute();
$chartData = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

$years   = array_column($chartData, 'year');
$amounts = array_map(fn($v) => (float)$v, array_column($chartData, 'totale'));
?>


  <!-- HEADER -->
  <header class="topbar">
    <div>
      <h1>🗑️ TARI – Tassa Rifiuti</h1>
      <div class="sub">Gestione bollette rifiuti</div>
    </div>
    <a class="btn" href="new_bill.php?u=tari">+ Nuova TARI</a>
  </header>

 <section class="card">
  <h2>Storico Importi TARI per Anno</h2>
  <div style="height:280px">
    <canvas id="tariAmountChart"></canvas>
  </div>
</section>


  <!-- ELENCO AVVISI -->
  <?php foreach ($billsByYear as $year => $items): ?>
    <section class="card">
      <div class="card-year-header">
        <h2>Avvisi TARI – <?= $year ?></h2>
        <a class="btn-reset-year"
           href="reset_year.php?u=tari&year=<?= $year ?>&csrf=<?= urlencode($csrfToken) ?>"
           onclick="return confirmResetAnno('TARI', <?= $year ?>);"
           title="Elimina tutti gli avvisi TARI di questo anno">
          🗑️ Svuota anno
        </a>
      </div>
      <table>
        <thead>
          <tr>
            <th>Anno</th>
            <th>Periodo (Dal / Al)</th>
            <th>Trimestre</th>
            <th>Data fattura</th>
            <th>Numero</th>
            <th>Tipo</th>
            <th class="right">% Diff.</th>
            <th class="right">Importo €</th>
            <th>Note</th>
            <th style="text-align:center;">Azioni</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($items as $b): ?>
  <tr>
    <td><strong><?= $year ?></strong></td>

    <td class="muted">
        <?= date('d/m/Y', strtotime($b['period_start'])) ?>
        —
        <?= date('d/m/Y', strtotime($b['period_end'])) ?>
      </td>

    <td>
      <?= htmlspecialchars((string)($b['periodo_competenza'] ?? '-')) ?>
    </td>

    

    <td>
      <?= $b['data_fattura']
        ? date('d/m/Y', strtotime($b['data_fattura']))
        : '-' ?>
    </td>


    <td>
      <?= htmlspecialchars((string)($b['numero_fattura'] ?? '-')) ?>
    </td>

    <td>
      <?= htmlspecialchars((string)($b['tipo_avviso'] ?? 'Ordinaria')) ?>
    </td>

    <td class="right">
      <?= $b['raccolta_diff'] !== null
        ? number_format((float)$b['raccolta_diff'], 1, ',', '.') . '%'
        : '-' ?>
    </td>

    <td class="right">
      <strong>€ <?= number_format((float)$b['amount_total'], 2, ',', '.') ?></strong>
    </td>

    <td class="muted">
      <?= nl2br(htmlspecialchars($b['notes'] ?? '')) ?>
    </td>

    <!-- AZIONI -->
    <td style="text-align:center; white-space:nowrap;">
      <a href="edit_bill.php?id=<?= $b['id'] ?>&u=tari" title="Modifica">✏️</a>
      <a href="delete_bill.php?id=<?= $b['id'] ?>&u=tari&csrf=<?= urlencode($csrfToken) ?>"
         onclick="return confirm('Eliminare questo avviso TARI?');"
         title="Elimina">🗑️</a>
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
    <?php foreach ($billsByYear as $year => $items):
      $totale = array_sum(array_column($items, 'amount_total'));
      $media  = $totale / count($items);
    ?>
      <li style="flex-direction:column; gap:6px;">
        <div style="display:flex; justify-content:space-between;">
          <strong>Anno <?= $year ?></strong>
          <strong>€ <?= number_format($totale,2,',','.') ?></strong>
        </div>
        <div class="muted">
          Avvisi: <?= count($items) ?> |
          Media per avviso: € <?= number_format($media,2,',','.') ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>



<script>
const ctxTari = document.getElementById('tariAmountChart');

if (ctxTari) {
  new Chart(ctxTari, {
    type: 'bar',
    data: {
      labels: <?= json_encode($years) ?>,
      datasets: [{
        label: 'Importo TARI (€)',
        data: <?= json_encode($amounts) ?>,
        backgroundColor: '#3b82f6'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: value => '€ ' + value
          }
        }
      }
    }
  });
}
</script>


