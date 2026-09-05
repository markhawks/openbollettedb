<?php
$totaleBollette = (int)$pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();
?>
<footer class="footer">
  <div class="sub">
    OpenBolletteDB &copy; <?= date('Y') ?>
    &middot; <?= number_format($totaleBollette, 0, ',', '.') ?> bollette registrate
    &middot; <a href="https://github.com/markhawks/openbollettedb" target="_blank" rel="noopener">Codice sorgente &middot; AGPL-3.0-or-later</a>
  </div>
</footer>
