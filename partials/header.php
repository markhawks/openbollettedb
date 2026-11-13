<header class="topbar">
  <div>
    <div class="brand-title">
      <span class="logo-icon">🧾</span>
      <h1><span class="logo-open">Open</span><span class="logo-name">BolletteDB</span></h1>
      <span class="version-badge">v1.9.0 &middot; 13/11/2026</span>
      <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener" title="Note di rilascio – cosa c'è di nuovo">📝</a>
    </div>
    <div class="sub"><?= htmlspecialchars(t('app.subtitle')) ?></div>
  </div>

  <div class="utility-selector">
    <a class="btn secondary" href="index.php?u=luce">💡 <?= htmlspecialchars(t('nav.electricity')) ?></a>
    <a class="btn secondary" href="index.php?u=gas">🔥 <?= htmlspecialchars(t('nav.gas')) ?></a>
    <a class="btn secondary" href="index.php?u=luce gas">💡🔥 <?= htmlspecialchars(t('nav.electricity_gas')) ?></a>
    <a class="btn secondary" href="index.php?u=tari">🗑️ <?= htmlspecialchars(t('nav.waste')) ?></a>
    <a class="btn secondary" href="index.php?u=acqua">💧 <?= htmlspecialchars(t('nav.water')) ?></a>
    <a class="btn secondary" href="index.php?u=bonifica">🌾 <?= htmlspecialchars(t('nav.reclamation')) ?></a>
    <a class="btn secondary" href="settings.php" title="<?= htmlspecialchars(t('settings.subtitle')) ?>">⚙️ <?= htmlspecialchars(t('nav.settings')) ?></a>
    <form method="post" action="logout.php" class="inline-action-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
      <button class="btn secondary" type="submit" title="<?= htmlspecialchars(t('nav.logout')) ?>">🔓 <?= htmlspecialchars(t('nav.logout')) ?></button>
    </form>
  </div>
</header>
