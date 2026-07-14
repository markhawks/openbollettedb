<?php
$totaleBollette = (int)$pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();
?>
<footer class="footer">
  <div class="sub">
    OpenBolletteDB &copy; <?= date('Y') ?>
    &middot; <?= number_format($totaleBollette, 0, ',', '.') ?> bollette registrate
  </div>
</footer>
