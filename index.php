<?php
require __DIR__ . '/app/csrf.php';
$csrfToken = csrf_token();

$u = $_GET['u'] ?? 'luce';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>OpenBolletteDB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/index_style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="assets/js/confirm-reset.js"></script>
</head>
<body>

<div class="container">

<?php require 'partials/header.php'; ?>


<?php
switch ($u) {
  case 'luce':
    require 'pages/dashboard_luce.php';
    break;
  case 'gas':
    require 'pages/dashboard_gas.php';
    break;
  case 'luce gas':
    require 'pages/dashboard_luce_gas.php';
    break;
  case 'tari':
    require 'pages/dashboard_tari.php';
    break;
  case 'bonifica':
    require 'pages/dashboard_bonifica.php';
    break;
  default:
    require 'pages/dashboard_acqua.php';
}
?>





<?php
$stmt = $pdo->prepare("
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
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years   = array_column($data, 'year');
$amounts = array_map(fn($v) => (float)$v, array_column($data, 'totale'));
?>




<?php require 'partials/footer.php'; ?>

</div>
</body>
</html>
