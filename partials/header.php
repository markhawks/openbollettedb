<header class="topbar">
  <div>
    <div class="brand-title">
      <span class="logo-icon">🧾</span>
      <h1><span class="logo-open">Open</span><span class="logo-name">BolletteDB</span></h1>
      <span class="version-badge">v1.2 &middot; 18/07/2026</span>
      <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener" title="Note di rilascio – cosa c'è di nuovo">📝</a>
    </div>
    <div class="sub">Gestione bollette domestiche</div>
  </div>

  <div class="utility-selector">
    <a class="btn secondary" href="index.php?u=luce">💡 Luce</a>
    <a class="btn secondary" href="index.php?u=gas">🔥 Gas</a>
    <a class="btn secondary" href="index.php?u=luce gas">💡🔥 Luce + Gas</a>
    <a class="btn secondary" href="index.php?u=tari">🗑️ Tari</a>
    <a class="btn secondary" href="index.php?u=acqua">💧 Acqua</a>
    <a class="btn secondary" href="index.php?u=bonifica">🌾 Bonifica</a>
    <a class="btn secondary" href="logout.php?csrf=<?= urlencode($csrfToken) ?>" title="Esci da OpenBolletteDB">🔓 Esci</a>
  </div>
</header>
