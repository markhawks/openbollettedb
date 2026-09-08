<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_login();
require_once __DIR__ . '/../app/csrf.php';
$csrfToken = csrf_token();

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
    b.issue_date,
    b.period_start,
    b.period_end,
    strftime('%Y', b.period_start) AS year,
    m1.value AS raccolta_diff,
    m2.value AS tipo_avviso,
    m3.value AS numero_fattura,
    m4.value AS periodo_competenza,
    m5.value AS data_fattura,
    m6.value AS extra_adjust,
    m7.value AS codice_cliente,
    m8.value AS codice_utenza,
    m9.value AS svuotature_grigio,
    m10.value AS stima
  FROM bills b
  LEFT JOIN bill_metrics m1 ON b.id = m1.bill_id AND m1.key = 'raccolta_diff'
  LEFT JOIN bill_metrics m2 ON b.id = m2.bill_id AND m2.key = 'tipo_avviso'
  LEFT JOIN bill_metrics m3 ON b.id = m3.bill_id AND m3.key = 'numero_fattura'
  LEFT JOIN bill_metrics m4 ON b.id = m4.bill_id AND m4.key = 'periodo_competenza'
  LEFT JOIN bill_metrics m5 ON b.id = m5.bill_id AND m5.key = 'data_fattura'
  LEFT JOIN bill_metrics m6 ON b.id = m6.bill_id AND m6.key = 'extra_adjust'
  LEFT JOIN bill_metrics m7 ON b.id = m7.bill_id AND m7.key = 'codice_cliente'
  LEFT JOIN bill_metrics m8 ON b.id = m8.bill_id AND m8.key = 'codice_utenza'
  LEFT JOIN bill_metrics m9 ON b.id = m9.bill_id AND m9.key = 'svuotature_grigio'
  LEFT JOIN bill_metrics m10 ON b.id = m10.bill_id AND m10.key = 'stima'

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

$annualTariStats = [];
foreach ($billsByYear as $year => $items) {
  $annualTariStats[(string)$year] = [
    'total' => array_sum(array_map(fn($item) => (float)$item['amount_total'], $items)),
  ];
}
foreach ($annualTariStats as $year => &$stats) {
  $previousYear = (string)((int)$year - 1);
  $stats['previous_year'] = $previousYear;
  $stats['has_previous'] = array_key_exists($previousYear, $annualTariStats);
  $stats['difference'] = $stats['has_previous']
    ? $stats['total'] - $annualTariStats[$previousYear]['total']
    : null;
  $stats['percentage'] = $stats['has_previous'] && $annualTariStats[$previousYear]['total'] != 0
    ? ($stats['difference'] / $annualTariStats[$previousYear]['total']) * 100
    : null;
}
unset($stats);
?>

<?php
$stmtChart = $pdo->prepare("
  SELECT 
    strftime('%Y', period_start) AS year,
    SUM(CASE WHEN EXISTS (
      SELECT 1 FROM bill_metrics sm
      WHERE sm.bill_id = bills.id AND sm.key = 'stima' AND sm.value = 1
    ) THEN 0 ELSE amount_total END) AS totale_reale,
    SUM(CASE WHEN EXISTS (
      SELECT 1 FROM bill_metrics sm
      WHERE sm.bill_id = bills.id AND sm.key = 'stima' AND sm.value = 1
    ) THEN amount_total ELSE 0 END) AS totale_stimato
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
$amountsReal = array_map(fn($v) => (float)$v, array_column($chartData, 'totale_reale'));
$amountsEstimated = array_map(fn($v) => (float)$v, array_column($chartData, 'totale_stimato'));
?>


  <!-- HEADER -->
  <header class="topbar">
    <div>
      <h1>🗑️ TARI – Tassa Rifiuti</h1>
      <div class="sub">Gestione bollette rifiuti</div>
    </div>
    <?php if (is_admin()): ?>
    <a class="btn" href="new_bill.php?u=tari">+ Nuova TARI</a>
    <?php endif; ?>
  </header>

 <section class="card">
  <h2>Storico Importi TARI per Anno</h2>
  <div style="height:280px">
    <canvas id="tariAmountChart"></canvas>
  </div>
</section>


  <!-- ELENCO AVVISI -->
  <?php foreach ($billsByYear as $year => $items):
    $yearStats = $annualTariStats[(string)$year];
    $differenceSign = $yearStats['difference'] !== null
      ? ($yearStats['difference'] > 0 ? '+' : ($yearStats['difference'] < 0 ? '−' : ''))
      : '';
    $percentagePrefix = $yearStats['percentage'] !== null && $yearStats['percentage'] > 0 ? '+' : '';
  ?>
    <section class="card tari-data-card">
      <div class="card-year-header">
        <h2 class="tari-year-title">
          <span>Avvisi TARI – <?= $year ?></span>
          <span class="tari-year-stat">Totale annuo: € <?= number_format($yearStats['total'], 2, ',', '.') ?></span>
          <span class="tari-year-stat">Differenza dal <?= $yearStats['previous_year'] ?>:
            <?= $yearStats['difference'] !== null
              ? $differenceSign . '€ ' . number_format(abs($yearStats['difference']), 2, ',', '.')
              : 'non disponibile' ?>
          </span>
          <span class="tari-year-stat">Variazione:
            <?= $yearStats['percentage'] !== null
              ? $percentagePrefix . number_format($yearStats['percentage'], 1, ',', '.') . '%'
              : 'non disponibile' ?>
          </span>
        </h2>
        <?php if (is_admin()): ?>
        <form method="post" action="reset_year.php" class="inline-action-form" onsubmit="return confirmResetAnno('TARI', <?= $year ?>);">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="u" value="tari">
          <input type="hidden" name="year" value="<?= $year ?>">
          <button class="btn-reset-year" type="submit" title="Elimina tutti gli avvisi TARI di questo anno">🗑️ Svuota anno</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="tari-table-container" tabindex="0" role="region" aria-label="Tabella avvisi TARI, scorribile orizzontalmente">
      <table class="tari-table">
        <thead>
          <tr>
            <th>Anno</th>
            <th>Codice Utente/Cliente</th>
            <th>Codice Utenza/Contratto n.</th>
            <th>Periodo (Dal / Al)</th>
            <th>Trimestre</th>
            <th>Data fattura</th>
            <th>Numero avviso/Fattura n.</th>
            <th>Tipo</th>
            <th class="right">% Diff.</th>
            <th class="right">Svuotature grigio</th>
            <th class="right">Importo lordo</th>
            <th class="right">Credito/rimborso</th>
            <th class="right">Da pagare</th>
            <th>Data scadenza</th>
            <th>Note</th>
            <th style="text-align:center;">Azioni</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($items as $b):
  $daPagare = (float)$b['amount_total'];
  $creditoRimborso = (float)($b['extra_adjust'] ?? 0);
  $importoLordo = $daPagare - $creditoRimborso;
  $isStima = !empty($b['stima']);
?>
  <tr<?= $isStima ? ' class="estimated-row"' : '' ?>>
    <td><strong><?= $year ?></strong></td>

    <?php $codiceCliente = (string)($b['codice_cliente'] ?? ''); ?>
    <td><?= $codiceCliente !== '' ? htmlspecialchars($codiceCliente) : '<span class="muted">—</span>' ?></td>

    <?php $codiceUtenza = (string)($b['codice_utenza'] ?? ''); ?>
    <td><?= $codiceUtenza !== '' ? htmlspecialchars($codiceUtenza) : '<span class="muted">—</span>' ?></td>

    <td class="muted tari-period">
        <?= date('d/m/Y', strtotime($b['period_start'])) ?>
        —
        <?= date('d/m/Y', strtotime($b['period_end'])) ?>
      </td>

    <td>
      <?= htmlspecialchars((string)($b['periodo_competenza'] ?? '-')) ?>
      <?php if ($isStima): ?>
        <span class="estimate-badge" title="Bolletta stimata, non ancora reale">📊 Stima</span>
      <?php endif; ?>
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
      <?php if ($b['raccolta_diff'] !== null): ?>
        <?php
          $raccoltaDiff = (float)$b['raccolta_diff'];
          $diffClass = $raccoltaDiff < 25
            ? 'tari-diff-high-rate'
            : ($raccoltaDiff < 80 ? 'tari-diff-base-rate' : 'tari-diff-reward-rate');
        ?>
        <strong class="tari-diff-value <?= $diffClass ?>" tabindex="0"
                title="Legenda tariffe: meno del 25% = tariffa con maggiorazione; dal 25% a meno dell'80% = tariffa base; dall'80% = tariffa con premialità."
                aria-label="Raccolta differenziata <?= number_format($raccoltaDiff, 1, ',', '.') ?> per cento. Consulta la legenda delle tariffe.">
          <?= number_format($raccoltaDiff, 1, ',', '.') ?>%
        </strong>
      <?php else: ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </td>

    <td class="right gray-bin-cell">
      <?php if ($b['svuotature_grigio'] !== null): ?>
        <?php $svuotatureGrigio = max(0, (int)$b['svuotature_grigio']); ?>
        <span class="gray-bin-icons" role="img"
              aria-label="<?= $svuotatureGrigio ?> <?= $svuotatureGrigio === 1 ? 'svuotatura' : 'svuotature' ?> dell'indifferenziato"
              title="<?= $svuotatureGrigio ?> <?= $svuotatureGrigio === 1 ? 'svuotatura' : 'svuotature' ?> da 20 litri">
          <?php if ($svuotatureGrigio === 0): ?>
            <span class="gray-bin is-empty" aria-hidden="true"></span>
          <?php else: ?>
            <?php for ($i = 0; $i < $svuotatureGrigio; $i++): ?>
              <span class="gray-bin" aria-hidden="true"></span>
            <?php endfor; ?>
          <?php endif; ?>
        </span>
        <span class="gray-bin-count"><?= $svuotatureGrigio ?></span>
      <?php else: ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </td>

    <td class="right">
      € <?= number_format($importoLordo, 2, ',', '.') ?>
    </td>

    <td class="right">
      <?php if ($creditoRimborso != 0): ?>
        <strong class="<?= $creditoRimborso < 0 ? 'tari-credit' : 'tari-extra-charge' ?>">
          <?= $creditoRimborso > 0 ? '+' : '−' ?>€ <?= number_format(abs($creditoRimborso), 2, ',', '.') ?>
        </strong>
      <?php else: ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </td>

    <td class="right">
      <strong>€ <?= number_format($daPagare, 2, ',', '.') ?></strong>
    </td>

    <td>
      <?= $b['issue_date']
        ? date('d/m/Y', strtotime($b['issue_date']))
        : '<span class="muted">—</span>' ?>
    </td>

    <td class="muted tari-notes">
      <?= nl2br(htmlspecialchars($b['notes'] ?? '')) ?>
    </td>

    <!-- AZIONI -->
    <td style="text-align:center; white-space:nowrap;">
      <?php if (is_admin()): ?>
      <a href="edit_bill.php?id=<?= $b['id'] ?>&u=tari" title="Modifica">✏️</a>
      <form method="post" action="delete_bill.php" class="inline-action-form" onsubmit="return confirm('Eliminare questo avviso TARI?');">
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

</tbody>
</table>
</div>
</section>

<?php endforeach; ?>


<!-- RIEPILOGO -->
<section class="card">
  <h2>Riepilogo Annuale</h2>
  <ul class="list">
    <?php foreach ($billsByYear as $year => $items):
      $yearStats = $annualTariStats[(string)$year];
      $totale = $yearStats['total'];
      $differenceSign = $yearStats['difference'] !== null
        ? ($yearStats['difference'] > 0 ? '+' : ($yearStats['difference'] < 0 ? '−' : ''))
        : '';
      $percentagePrefix = $yearStats['percentage'] !== null && $yearStats['percentage'] > 0 ? '+' : '';
      $totaleStimato = array_sum(array_map(
        fn($item) => !empty($item['stima']) ? (float)$item['amount_total'] : 0,
        $items
      ));
      $totaleReale = $totale - $totaleStimato;
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
        <div class="muted">
          Differenza dal <?= $yearStats['previous_year'] ?>:
          <strong><?= $yearStats['difference'] !== null
            ? $differenceSign . '€ ' . number_format(abs($yearStats['difference']), 2, ',', '.')
            : 'non disponibile' ?></strong> |
          Variazione:
          <strong><?= $yearStats['percentage'] !== null
            ? $percentagePrefix . number_format($yearStats['percentage'], 1, ',', '.') . '%'
            : 'non disponibile' ?></strong>
        </div>
        <?php if ($totaleStimato > 0): ?>
          <div class="muted">
            Reale: € <?= number_format($totaleReale,2,',','.') ?> |
            <span class="consumption-estimated">📊 Stimato: € <?= number_format($totaleStimato,2,',','.') ?></span>
          </div>
        <?php endif; ?>
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
      datasets: [
        {
          label: 'Importo reale (€)',
          data: <?= json_encode($amountsReal) ?>,
          backgroundColor: '#3b82f6'
        },
        {
          label: '📊 Importo stimato (€)',
          data: <?= json_encode($amountsEstimated) ?>,
          backgroundColor: '#f59e0b'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: true }
      },
      scales: {
        x: { stacked: true },
        y: {
          stacked: true,
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
