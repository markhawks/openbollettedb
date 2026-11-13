<?php
declare(strict_types=1);
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/csrf.php';

auth_start_session();
if (isset($_GET['lang']) && is_scalar($_GET['lang']) && in_array((string)$_GET['lang'], SUPPORTED_LANGUAGES, true)) {
    set_current_language((string)$_GET['lang']);
}

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = t('login.expired');
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($username !== '' && attempt_login($username, $password)) {
            header('Location: index.php');
            exit;
        }
        $error = t('login.invalid');
    }
}

$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_language()) ?>">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars(t('login.title')) ?> – OpenBolletteDB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/index_style.css">
  <link rel="stylesheet" href="assets/css/login_style.css?v=<?= filemtime(__DIR__ . '/assets/css/login_style.css') ?>">
</head>
<body class="login-body">

<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <span class="logo-icon">🧾</span>
      <h1><span class="logo-open">Open</span><span class="logo-name">BolletteDB</span></h1>
      <span class="version-badge">v1.9.0 &middot; 13/11/2026</span>
    </div>
    <div class="sub login-sub"><?= htmlspecialchars(t('login.subtitle')) ?></div>
    <div class="login-language"><a href="?lang=it" lang="it">Italiano</a><span>·</span><a href="?lang=en" lang="en">English</a></div>

    <?php if ($error): ?>
      <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">

      <label for="utenza"><?= htmlspecialchars(t('login.supply')) ?></label>
      <select id="utenza" name="utenza" disabled title="Multi-utenza non ancora disponibile">
        <option selected>Default</option>
      </select>
      <p class="login-hint"><?= htmlspecialchars(t('login.default_hint')) ?></p>

      <label for="username"><?= htmlspecialchars(t('login.user')) ?></label>
      <input type="text" id="username" name="username" autocomplete="username" required autofocus
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

      <label for="password"><?= htmlspecialchars(t('login.password')) ?></label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>

      <button type="submit" class="btn login-btn"><?= htmlspecialchars(t('login.submit')) ?></button>
    </form>

    <div class="login-footer">OpenBolletteDB &middot; <?= htmlspecialchars(t('login.local')) ?></div>
  </div>
</div>

</body>
</html>
