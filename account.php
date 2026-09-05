<?php
declare(strict_types=1);

require __DIR__ . '/app/auth.php';
require_login();
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/csrf.php';

$pdo  = db();
$user = current_user();

$successProfile  = null;
$errorProfile    = null;
$successPassword = null;
$errorPassword   = null;
$successUsers    = null;
$errorUsers      = null;

$validRoles = ['admin', 'user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        http_response_code(403);
        die('Richiesta non valida (token CSRF mancante o scaduto). Torna indietro e riprova.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        if ($displayName === '') {
            $errorProfile = 'Il nome non può essere vuoto.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
            $stmt->execute([$displayName, $user['id']]);
            $_SESSION['user']['display_name'] = $displayName;
            $user['display_name'] = $displayName;
            $successProfile = 'Nome aggiornato.';
        }
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errorPassword = 'La password attuale non è corretta.';
        } elseif (strlen($new) < 8) {
            $errorPassword = 'La nuova password deve avere almeno 8 caratteri.';
        } elseif ($new !== $confirm) {
            $errorPassword = 'La nuova password e la conferma non coincidono.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            $_SESSION['user']['session_version'] = (int)($_SESSION['user']['session_version'] ?? 1) + 1;
            session_regenerate_id(true);
            $successPassword = 'Password aggiornata.';
        }
    }

    // --- Sezione riservata agli amministratori: gestione degli altri utenti ---
    if (in_array($action, ['add_user', 'update_role', 'reset_user_password', 'delete_user'], true)) {
        if (!is_admin()) {
            http_response_code(403);
            die('Il tuo utente ha accesso in sola lettura: non puoi gestire gli altri utenti.');
        }

        if ($action === 'add_user') {
            $newUsername    = trim((string)($_POST['new_username'] ?? ''));
            $newDisplayName = trim((string)($_POST['new_display_name'] ?? ''));
            $newPassword    = (string)($_POST['new_user_password'] ?? '');
            $newRole        = (string)($_POST['new_role'] ?? 'user');

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $stmt->execute([$newUsername]);
            $usernameTaken = (int)$stmt->fetchColumn() > 0;

            if ($newUsername === '' || $newDisplayName === '') {
                $errorUsers = 'Utente e nome visualizzato sono obbligatori.';
            } elseif ($usernameTaken) {
                $errorUsers = 'Esiste già un utente con questo nome di accesso.';
            } elseif (strlen($newPassword) < 8) {
                $errorUsers = 'La password del nuovo utente deve avere almeno 8 caratteri.';
            } elseif (!in_array($newRole, $validRoles, true)) {
                $errorUsers = 'Ruolo non valido.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO users(username, password_hash, display_name, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$newUsername, password_hash($newPassword, PASSWORD_DEFAULT), $newDisplayName, $newRole]);
                $successUsers = 'Utente "' . $newUsername . '" creato.';
            }
        }

        // Le azioni seguenti operano su un altro utente: non è permesso auto-modificarsi
        // da qui (si usano invece le sezioni "Il mio profilo" / "Cambia password" sopra),
        // così l'amministratore che sta agendo non può mai auto-eliminarsi o auto-degradarsi.
        if (in_array($action, ['update_role', 'reset_user_password', 'delete_user'], true)) {
            $targetId = (int)($_POST['user_id'] ?? 0);

            if ($targetId === (int)$user['id']) {
                $errorUsers = 'Non puoi modificare il tuo stesso account da qui: usa "Il mio profilo" qui sopra.';
            } else {
                $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
                $stmt->execute([$targetId]);
                $target = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$target) {
                    $errorUsers = 'Utente non trovato.';
                } elseif ($action === 'update_role') {
                    $newRole = (string)($_POST['role'] ?? '');
                    if (!in_array($newRole, $validRoles, true)) {
                        $errorUsers = 'Ruolo non valido.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET role = ?, session_version = session_version + 1 WHERE id = ?');
                        $stmt->execute([$newRole, $targetId]);
                        $successUsers = 'Ruolo di "' . $target['username'] . '" aggiornato.';
                    }
                } elseif ($action === 'reset_user_password') {
                    $newPassword = (string)($_POST['reset_password'] ?? '');
                    if (strlen($newPassword) < 8) {
                        $errorUsers = 'La nuova password deve avere almeno 8 caratteri.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?');
                        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $targetId]);
                        $successUsers = 'Password di "' . $target['username'] . '" reimpostata.';
                    }
                } elseif ($action === 'delete_user') {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$targetId]);
                    $successUsers = 'Utente "' . $target['username'] . '" eliminato.';
                }
            }
        }
    }
}

$allUsers = $pdo->query('SELECT id, username, display_name, role, created_at FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Gestione utenti – OpenBolletteDB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/index_style.css">
  <link rel="stylesheet" href="assets/css/account_style.css">
</head>
<body>

<div class="container">

<?php require 'partials/header.php'; ?>

<section class="card">
  <h2 class="account-title">👤 Il mio profilo</h2>
  <p class="sub account-note">
    Utente: <strong><?= htmlspecialchars($user['username']) ?></strong> &middot;
    Ruolo: <strong><?= $user['role'] === 'admin' ? 'Amministratore (lettura e scrittura)' : 'Sola lettura' ?></strong>
  </p>

  <?php if ($successProfile): ?><div class="account-success"><?= htmlspecialchars($successProfile) ?></div><?php endif; ?>
  <?php if ($errorProfile): ?><div class="account-error"><?= htmlspecialchars($errorProfile) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="update_profile">

    <div class="account-field">
      <label for="display_name">Nome visualizzato</label>
      <input type="text" id="display_name" name="display_name"
             value="<?= htmlspecialchars($user['display_name']) ?>" required>
    </div>

    <button type="submit" class="btn">Salva nome</button>
  </form>
</section>

<section class="card">
  <h2 class="account-title">🔒 Cambia password</h2>

  <?php if ($successPassword): ?><div class="account-success"><?= htmlspecialchars($successPassword) ?></div><?php endif; ?>
  <?php if ($errorPassword): ?><div class="account-error"><?= htmlspecialchars($errorPassword) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="change_password">

    <div class="account-field">
      <label for="current_password">Password attuale</label>
      <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
    </div>

    <div class="account-field">
      <label for="new_password">Nuova password</label>
      <input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="8">
    </div>
    <p class="account-hint">Almeno 8 caratteri.</p>

    <div class="account-field">
      <label for="confirm_password">Conferma nuova password</label>
      <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required minlength="8">
    </div>

    <button type="submit" class="btn">Aggiorna password</button>
  </form>
</section>

<?php if (is_admin()): ?>
<section class="card">
  <h2 class="account-title">🧑‍🤝‍🧑 Utenti</h2>
  <p class="sub account-note">
    Tutti gli utenti condividono le stesse bollette. Un amministratore può aggiungere/modificare/eliminare
    bollette; un utente in sola lettura può solo consultare le dashboard.
  </p>

  <?php if ($successUsers): ?><div class="account-success"><?= htmlspecialchars($successUsers) ?></div><?php endif; ?>
  <?php if ($errorUsers): ?><div class="account-error"><?= htmlspecialchars($errorUsers) ?></div><?php endif; ?>

  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Utente</th>
          <th>Nome visualizzato</th>
          <th>Ruolo</th>
          <th>Creato il</th>
          <th>Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allUsers as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['display_name']) ?></td>
            <?php if ((int)$u['id'] === (int)$user['id']): ?>
              <td><?= $u['role'] === 'admin' ? 'Amministratore' : 'Sola lettura' ?></td>
              <td class="muted"><?= htmlspecialchars((string)$u['created_at']) ?></td>
              <td class="muted">— (il tuo account)</td>
            <?php else: ?>
              <td>
                <form method="post" class="account-inline-form">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                  <input type="hidden" name="action" value="update_role">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <select name="role">
                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Amministratore</option>
                    <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>Sola lettura</option>
                  </select>
                  <button type="submit" class="btn secondary" title="Salva ruolo">💾</button>
                </form>
              </td>
              <td class="muted"><?= htmlspecialchars((string)$u['created_at']) ?></td>
              <td>
                <div class="account-actions">
                  <form method="post" class="account-inline-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="reset_user_password">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="password" name="reset_password" placeholder="Nuova password" minlength="8" required>
                    <button type="submit" class="btn secondary" title="Reimposta password">🔑</button>
                  </form>
                  <form method="post" class="account-inline-form"
                        onsubmit="return confirm('Eliminare questo utente? Non potrà più accedere.');">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn-reset-year" title="Elimina utente">🗑️ Elimina</button>
                  </form>
                </div>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h3 class="account-subtitle">Aggiungi utente</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="add_user">

    <div class="account-field">
      <label for="new_username">Utente (login)</label>
      <input type="text" id="new_username" name="new_username" autocomplete="off" required>
    </div>

    <div class="account-field">
      <label for="new_display_name">Nome visualizzato</label>
      <input type="text" id="new_display_name" name="new_display_name" autocomplete="off" required>
    </div>

    <div class="account-field">
      <label for="new_user_password">Password</label>
      <input type="password" id="new_user_password" name="new_user_password" autocomplete="new-password" required minlength="8">
    </div>
    <p class="account-hint">Almeno 8 caratteri.</p>

    <div class="account-field">
      <label for="new_role">Ruolo</label>
      <select id="new_role" name="new_role">
        <option value="user" selected>Sola lettura</option>
        <option value="admin">Amministratore</option>
      </select>
    </div>

    <button type="submit" class="btn">Crea utente</button>
  </form>
</section>
<?php endif; ?>

<?php require 'partials/footer.php'; ?>

</div>
</body>
</html>
