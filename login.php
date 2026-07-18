<?php
declare(strict_types=1);
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/csrf.php';

auth_start_session();
if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Sessione scaduta, ricarica la pagina e riprova.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($username !== '' && attempt_login($username, $password)) {
            header('Location: index.php');
            exit;
        }
        $error = 'Utente o password non validi.';
    }
}

$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Accedi – OpenBolletteDB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/index_style.css">
  <link rel="stylesheet" href="assets/css/login_style.css">
</head>
<body class="login-body">

<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <span class="logo-icon">🧾</span>
      <h1><span class="logo-open">Open</span><span class="logo-name">BolletteDB</span></h1>
    </div>
    <div class="sub login-sub">Accedi per gestire le bollette domestiche</div>

    <?php if ($error): ?>
      <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">

      <label for="utenza">Utenza</label>
      <select id="utenza" name="utenza" disabled title="Multi-utenza non ancora disponibile">
        <option selected>Default</option>
      </select>
      <p class="login-hint">Multi-utenza non ancora implementata: al momento è disponibile solo l'utenza &quot;Default&quot;.</p>

      <label for="username">Utente</label>
      <input type="text" id="username" name="username" autocomplete="username" required autofocus
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>

      <button type="submit" class="btn login-btn">Accedi</button>
    </form>

    <div class="login-footer">OpenBolletteDB &middot; uso locale/LAN</div>
  </div>
</div>

</body>
</html>
