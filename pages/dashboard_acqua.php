<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_login();
require_once __DIR__ . '/../app/csrf.php';
$csrfToken = csrf_token();

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
    m4.value AS mc_conguaglio,
    m5.value AS stima,
    m6.value AS next_reading_start,
    m7.value AS next_reading_end
  FROM bills b
  LEFT JOIN bill_metrics m1 ON b.id = m1.bill_id AND m1.key = 'mc_start'
  LEFT JOIN bill_metrics m2 ON b.id = m2.bill_id AND m2.key = 'mc_end'
  LEFT JOIN bill_metrics m3 ON b.id = m3.bill_id AND m3.key = 'consumo_mc'
  LEFT JOIN bill_metrics m4 ON b.id = m4.bill_id AND m4.key = 'mc_conguaglio'
  LEFT JOIN bill_metrics m5 ON b.id = m5.bill_id AND m5.key = 'stima'
  LEFT JOIN bill_metrics m6 ON b.id = m6.bill_id AND m6.key = 'next_reading_start'
  LEFT JOIN bill_metrics m7 ON b.id = m7.bill_id AND m7.key = 'next_reading_end'
  WHERE b.utility_id = ?
  ORDER BY 
    strftime('%Y', COALESCE(b.issue_date, b.period_start)) DESC,
    COALESCE(b.issue_date, b.period_start) DESC
");


$stmt->execute([$utilityId]);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Storico letture (data + m³) di ogni bolletta, inserito manualmente in fase di
   creazione/modifica: raggruppo per bill_id per popolare la colonna in tabella */
$readingsStmt = $pdo->prepare("
  SELECT br.bill_id, br.reading_date, br.reading_value
  FROM bill_readings br
  JOIN bills b ON b.id = br.bill_id
  WHERE b.utility_id = ?
  ORDER BY br.reading_date ASC
");
$readingsStmt->execute([$utilityId]);
$readingsByBill = [];
foreach ($readingsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $readingsByBill[(int)$r['bill_id']][] = $r;
}


/* Raggruppa per anno */
$billsByYear = [];
foreach ($bills as $b) {
  $billsByYear[$b['year']][] = $b;
}

/* Grafico: consumo per anno, diviso tra reale e stimato (colore diverso nella barra) */
$stmtChart = $pdo->prepare("
  SELECT
    strftime('%Y', COALESCE(b.issue_date, b.period_start)) AS year,
    SUM(CASE WHEN EXISTS (
      SELECT 1 FROM bill_metrics sm WHERE sm.bill_id = b.id AND sm.key = 'stima' AND sm.value = 1
    ) THEN 0 ELSE
      CAST(COALESCE(m_cons.value, 0) AS REAL) + CAST(COALESCE(m_cong.value, 0) AS REAL)
    END) AS consumo_reale,
    SUM(CASE WHEN EXISTS (
      SELECT 1 FROM bill_metrics sm WHERE sm.bill_id = b.id AND sm.key = 'stima' AND sm.value = 1
    ) THEN
      CAST(COALESCE(m_cons.value, 0) AS REAL) + CAST(COALESCE(m_cong.value, 0) AS REAL)
    ELSE 0 END) AS consumo_stima
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

$years         = array_column($chartData, 'year');
$consumiReali  = array_map(fn($v) => (float)$v, array_column($chartData, 'consumo_reale'));
$consumiStima  = array_map(fn($v) => (float)$v, array_column($chartData, 'consumo_stima'));

/* Letture del contatore uniformi: niente zeri decimali superflui, ma conserva
   gli eventuali decimali significativi. */
function formatAcquaReading(mixed $value): string
{
  $formatted = number_format((float)$value, 3, ',', '.');
  return rtrim(rtrim($formatted, '0'), ',');
}

?>





<!-- HEADER -->
  <header class="topbar">
    <div>
      <h1>💧 Acqua</h1>
      <div class="sub">Consumi rilevati, stimati e conguagli separati</div>
    </div>
    <?php if (is_admin()): ?>
    <a class="btn" href="new_bill.php?u=acqua">+ Nuova Bolletta</a>
    <?php endif; ?>
  </header>

  <section class="card acqua-consumption-info">
    <strong>💧 Come leggere i consumi</strong>
    <span>La voce <b>Rilevato</b> indica il consumo riportato nella bolletta; <b>Conguaglio</b> storna o recupera consumi già fatturati; <b>Addebitato</b> è la loro somma. La classificazione dipende dall'opzione “Bolletta stimata” presente in modifica.</span>
  </section>

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

      $totConsumo    = 0.0;
      $totSpesa      = 0.0;
      $totSpesaStima = 0.0;
      $totSpesaReale = 0.0;
      $totConsumoReale = 0.0;
      $totConsumoStima = 0.0;
      $totConguagli = 0.0;

      foreach ($items as $b) {
        $consumoLordo = (float)$b['consumo_mc'];
        $conguaglio   = (float)($b['mc_conguaglio'] ?? 0);
        $consumoNetto = $consumoLordo + $conguaglio;
        $totConsumo += $consumoNetto;
        $totConguagli += $conguaglio;
        $totSpesa   += (float)$b['amount_total'];
        if (!empty($b['stima'])) {
          $totConsumoStima += $consumoLordo;
          $totSpesaStima += (float)$b['amount_total'];
        } else {
          $totConsumoReale += $consumoLordo;
          $totSpesaReale += (float)$b['amount_total'];
        }
      }

      $costoMedio = $totConsumo > 0 ? $totSpesa / $totConsumo : 0;
    ?>
      <li style="flex-direction:column; gap:6px;">
        <div style="display:flex; justify-content:space-between;">
          <strong><?= $year ?></strong>
          <strong>€ <?= number_format($totSpesa, 2, ',', '.') ?></strong>
        </div>

        <div class="consumption-summary-grid">
          <span class="consumption-real">📍 Rilevato: <strong><?= formatAcquaReading($totConsumoReale) ?> m³</strong></span>
          <span class="consumption-estimated">📊 Stimato: <strong><?= formatAcquaReading($totConsumoStima) ?> m³</strong></span>
          <span class="consumption-adjustment">↩ Conguagli: <strong><?= formatAcquaReading($totConguagli) ?> m³</strong></span>
          <span>Addebitato: <strong><?= formatAcquaReading($totConsumo) ?> m³</strong></span>
        </div>

        <?php if ($totSpesaStima > 0): ?>
        <div style="display:flex; justify-content:flex-end; font-size:0.85rem;">
          <div style="color:#64748b;">
            Reale: € <?= number_format($totSpesaReale,2,',','.') ?> |
            <span style="color:#92400e;">📊 Stimato: € <?= number_format($totSpesaStima,2,',','.') ?></span>
          </div>
        </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>


<?php foreach ($billsByYear as $year => $items): ?>
<section class="card">
  <div class="card-year-header">
    <h2>Bollette Acqua – <?= $year ?></h2>
    <?php if (is_admin()): ?>
    <form method="post" action="reset_year.php" class="inline-action-form" onsubmit="return confirmResetAnno('Acqua', <?= $year ?>);">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="u" value="acqua">
      <input type="hidden" name="year" value="<?= $year ?>">
      <button class="btn-reset-year" type="submit" title="Elimina tutte le bollette Acqua di questo anno">🗑️ Svuota anno</button>
    </form>
    <?php endif; ?>
  </div>

  <table>

<thead>
  <tr>
    <th>Data immissione</th>
    <th>Periodo</th>
    <th>Letture</th>
    <th>Prossima lettura</th>
    <th>Storico letture</th>
    <th>Dettaglio consumo</th>
    <th class="right">m³/giorno</th>
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

  // Il periodo indicato in bolletta include sia il giorno iniziale sia quello finale.
  $giorniPeriodo = (int)(new DateTimeImmutable($b['period_start']))
    ->diff(new DateTimeImmutable($b['period_end']))->days + 1;
  $isStima = !empty($b['stima']);
  $consumoGiornaliero = $giorniPeriodo > 0 ? $consumoLordo / $giorniPeriodo : null;
?>
<tr<?= $isStima ? ' style="opacity:0.7; font-style:italic;"' : '' ?>>

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
    <?php if ($isStima): ?>
      <span title="Bolletta stimata, non ancora reale"
            style="margin-left:6px; padding:2px 8px; background:#fef3c7; color:#92400e;
                   border-radius:10px; font-size:0.75rem; font-style:normal; white-space:nowrap;">
        📊 Stima
      </span>
    <?php endif; ?>
  </td>

  <!-- LETTURE -->
  <td class="muted">
  <?= ($b['mc_start'] !== null && $b['mc_end'] !== null)
      ? formatAcquaReading($b['mc_start']).' → '.formatAcquaReading($b['mc_end'])
      : '-' ?>
  </td>

  <!-- PERIODO UTILE PER LA PROSSIMA LETTURA -->
  <td class="muted">
    <?php if (!empty($b['next_reading_start']) && !empty($b['next_reading_end'])): ?>
      <?php
        $today = date('Y-m-d');
        $startIsFuture = $b['next_reading_start'] >= $today;
        $endIsFuture = $b['next_reading_end'] >= $today;
      ?>
      <?php if ($startIsFuture || $endIsFuture): ?>
        <span class="next-reading-reminder" title="Periodo futuro: ricordati di comunicare la lettura">🔔</span>
      <?php endif; ?>
      <span<?= $startIsFuture ? ' class="next-reading-future"' : '' ?>>
        <?= date('d/m/Y', strtotime($b['next_reading_start'])) ?>
      </span> —<br>
      <span<?= $endIsFuture ? ' class="next-reading-future"' : '' ?>>
        <?= date('d/m/Y', strtotime($b['next_reading_end'])) ?>
      </span>
    <?php else: ?>
      -
    <?php endif; ?>
  </td>

  <!-- STORICO LETTURE -->
  <td class="muted">
    <?php $readings = $readingsByBill[(int)$b['id']] ?? []; ?>
    <?php if ($readings): ?>
      <?php foreach ($readings as $r): ?>
        <?= date('d/m/Y', strtotime($r['reading_date'])) ?>:
        <?= formatAcquaReading($r['reading_value']) ?> m³<br>
      <?php endforeach; ?>
    <?php else: ?>
      -
    <?php endif; ?>
  </td>

  <!-- DETTAGLIO: rilevato/stimato, conguaglio e addebitato -->
  <td class="consumption-detail">
    <?php if ($isStima): ?>
      <div class="consumption-line consumption-estimated"><span>📊 Stimato</span><strong><?= formatAcquaReading($consumoLordo) ?> m³</strong></div>
    <?php else: ?>
      <div class="consumption-line consumption-real"><span>📍 Rilevato</span><strong><?= formatAcquaReading($consumoLordo) ?> m³</strong></div>
    <?php endif; ?>
    <?php if ($conguaglio != 0): ?>
      <div class="consumption-line consumption-adjustment"><span>↩ Conguaglio</span><strong><?= formatAcquaReading($conguaglio) ?> m³</strong></div>
    <?php endif; ?>
    <div class="consumption-line consumption-charged"><span>Addebitato</span><strong><?= formatAcquaReading($consumoNetto) ?> m³</strong></div>
  </td>

  <!-- CONSUMO MEDIO GIORNALIERO -->
  <td class="right muted">
    <?= $consumoGiornaliero !== null
      ? number_format($consumoGiornaliero,2,',','.').' m³/g'
      : '-' ?>
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
    <?php if (is_admin()): ?>
    <a href="edit_bill.php?id=<?= $b['id'] ?>&u=acqua">✏️</a>
    <form method="post" action="delete_bill.php" class="inline-action-form" onsubmit="return confirm('Eliminare questa bolletta acqua?');">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <button type="submit" class="icon-action" title="Elimina">🗑️</button>
    </form>
    <?php else: ?>
    <span class="muted">—</span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>



<script>
new Chart(document.getElementById('acquaConsumiChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($years) ?>,
    datasets: [
      {
        label: 'Consumo reale (m³)',
        data: <?= json_encode($consumiReali) ?>,
        backgroundColor: 'rgba(37, 99, 235, 0.6)',
        borderColor: '#2563eb',
        borderWidth: 1,
        stack: 'consumo'
      },
      {
        label: '📊 Consumo stimato (m³)',
        data: <?= json_encode($consumiStima) ?>,
        backgroundColor: 'rgba(245, 158, 11, 0.5)',
        borderColor: '#d97706',
        borderWidth: 1,
        stack: 'consumo'
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      x: { stacked: true },
      y: {
        stacked: true,
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
