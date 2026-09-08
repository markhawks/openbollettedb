<header class="topbar">
  <div>
    <div class="brand-title">
      <span class="logo-icon">🧾</span>
      <h1><span class="logo-open">Open</span><span class="logo-name">BolletteDB</span></h1>
      <span class="version-badge">v1.6.0 &middot; 08/09/2026</span>
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
    <a class="btn secondary" href="account.php" title="Gestione utenti">⚙️ Utente</a>
    <form method="post" action="logout.php" class="inline-action-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
      <button class="btn secondary" type="submit" title="Esci da OpenBolletteDB">🔓 Esci</button>
    </form>
  </div>
</header>
