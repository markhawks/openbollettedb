<?php
declare(strict_types=1);
require __DIR__ . '/../app/db.php';

$pdo = db();

/* Recuperiamo utility BONIFICA */
$utStmt = $pdo->prepare("SELECT id, name FROM utilities WHERE code = 'bonifica'");
$utStmt->execute();
$utility = $utStmt->fetch(PDO::FETCH_ASSOC);
if (!$utility) {
  die("Utility BONIFICA non trovata");
}
$utilityId = (int)$utility['id'];

/* Recupero bollette BONIFICA */
$stmt = $pdo->prepare("
  SELECT
    b.id,
    b.amount_total,
    b.notes,
    b.period_start,
    strftime('%Y', b.period_start) AS year,
    m1.value AS data_scadenza,
    m2.value AS data_pagamento
  FROM bills b
  LEFT JOIN bill_metrics m1 ON b.id = m1.bill_id AND m1.key = 'data_scadenza'
  LEFT JOIN bill_metrics m2 ON b.id = m2.bill_id AND m2.key = 'data_pagamento'
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

/* Totale per anno, usato per calcolare la crescita anno su anno nel riepilogo */
$yearTotals = [];
foreach ($billsByYear as $year => $items) {
  $yearTotals[(int)$year] = array_sum(array_column($items, 'amount_total'));
}
?>

<?php
$stmtChart = $pdo->prepare("
  SELECT
    strftime('%Y', period_start) AS year,
    SUM(amount_total) AS totale
  FROM bills
  WHERE utility_id = (
    SELECT id FROM utilities WHERE code = 'bonifica'
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
      <h1>🌾 Bonifica – Consorzio di bonifica</h1>
      <div class="sub">Gestione bollette annuali</div>
    </div>
    <a class="btn" href="new_bill.php?u=bonifica">+ Nuova Bonifica</a>
  </header>

  <section class="card">
    <h2>Storico Importi Bonifica per Anno</h2>
    <div style="height:280px">
      <canvas id="bonificaAmountChart"></canvas>
    </div>
  </section>


  <!-- ELENCO AVVISI -->
  <?php foreach ($billsByYear as $year => $items): ?>
    <section class="card">
      <div class="card-year-header">
        <h2>Avvisi Bonifica – <?= $year ?></h2>
        <a class="btn-reset-year"
           href="reset_year.php?u=bonifica&year=<?= $year ?>&csrf=<?= urlencode($csrfToken) ?>"
           onclick="return confirmResetAnno('Bonifica', <?= $year ?>);"
           title="Elimina tutti gli avvisi Bonifica di questo anno">
          🗑️ Svuota anno
        </a>
      </div>
      <table>
        <thead>
          <tr>
            <th>Anno</th>
            <th>Data scadenza</th>
            <th>Data pagamento</th>
            <th class="right">Importo €</th>
            <th>Note</th>
            <th style="text-align:center;">Azioni</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($items as $b):
  $scadenza  = $b['data_scadenza'] ?: null;
  $pagamento = $b['data_pagamento'] ?: null;

  $pagamentoInRitardo = ($pagamento && $scadenza && strtotime($pagamento) > strtotime($scadenza));
  $pagamentoMancante  = !$pagamento;

  if ($pagamentoInRitardo) {
    $warningMsg = 'Pagato dopo la scadenza';
  } elseif ($pagamentoMancante) {
    $warningMsg = 'Pagamento non registrato';
  } else {
    $warningMsg = '';
  }
?>
  <tr>
    <td><strong><?= $year ?></strong></td>

    <td>
      <?= $scadenza ? date('d/m/Y', strtotime($scadenza)) : '-' ?>
    </td>

    <td>
      <?= $pagamento ? date('d/m/Y', strtotime($pagamento)) : '-' ?>
      <?php if ($warningMsg): ?>
        <span title="<?= htmlspecialchars($warningMsg) ?>" style="cursor:help;">⚠️</span>
      <?php endif; ?>
    </td>

    <td class="right">
      <strong>€ <?= number_format((float)$b['amount_total'], 2, ',', '.') ?></strong>
    </td>

    <td class="muted">
      <?= nl2br(htmlspecialchars($b['notes'] ?? '')) ?>
    </td>

    <!-- AZIONI -->
    <td style="text-align:center; white-space:nowrap;">
      <a href="edit_bill.php?id=<?= $b['id'] ?>&u=bonifica" title="Modifica">✏️</a>
      <a href="delete_bill.php?id=<?= $b['id'] ?>&u=bonifica&csrf=<?= urlencode($csrfToken) ?>"
         onclick="return confirm('Eliminare questo avviso Bonifica?');"
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

      $prevYear    = (int)$year - 1;
      $prevTotale  = $yearTotals[$prevYear] ?? null;
      $crescitaImporto = null;
      $crescitaPct     = null;
      if ($prevTotale !== null && $prevTotale != 0) {
        $crescitaImporto = $totale - $prevTotale;
        $crescitaPct     = ($crescitaImporto / $prevTotale) * 100;
      }
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
        <?php if ($crescitaPct !== null): ?>
          <?php $segno = $crescitaImporto >= 0 ? '+' : ''; ?>
          <div style="color: <?= $crescitaImporto >= 0 ? '#dc2626' : '#16a34a' ?>;">
            <?= $segno ?>€ <?= number_format($crescitaImporto,2,',','.') ?>
            (<?= $segno ?><?= number_format($crescitaPct,1,',','.') ?>%)
            rispetto al <?= $prevYear ?>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>



<script>
const ctxBonifica = document.getElementById('bonificaAmountChart');

if (ctxBonifica) {
  new Chart(ctxBonifica, {
    type: 'bar',
    data: {
      labels: <?= json_encode($years) ?>,
      datasets: [{
        label: 'Importo Bonifica (€)',
        data: <?= json_encode($amounts) ?>,
        backgroundColor: 'rgba(22, 163, 74, 0.5)',
        borderColor: '#16a34a',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
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
