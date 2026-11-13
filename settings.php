<?php
declare(strict_types=1);

require __DIR__ . '/app/auth.php';
require_login();
require __DIR__ . '/app/csrf.php';
require __DIR__ . '/app/data_transfer.php';

$csrfToken = csrf_token();
$user = current_user();
$pdo = db();
$transferError = null;
$transferSuccess = null;
$transferPreview = null;
$languageSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) { http_response_code(403); die('Token CSRF non valido.'); }
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string)$_POST['action'] : '';
    try {
        if ($action === 'update_language') {
            $language = isset($_POST['language']) && is_scalar($_POST['language']) ? (string)$_POST['language'] : 'it';
            if (!in_array($language, SUPPORTED_LANGUAGES, true)) throw new InvalidArgumentException('Lingua non valida.');
            $pdo->prepare('UPDATE users SET language=? WHERE id=?')->execute([$language, (int)$user['id']]);
            $_SESSION['user']['language'] = $language;
            set_current_language($language);
            $user['language'] = $language;
            $languageSuccess = t('settings.language_saved');
        } elseif (!is_admin()) {
            http_response_code(403);
            throw new RuntimeException(t('settings.admin_only'));
        }
        if ($action === 'export') {
            $codes = isset($_POST['utilities']) && is_array($_POST['utilities']) ? $_POST['utilities'] : [];
            $yearFrom = isset($_POST['year_from']) && $_POST['year_from'] !== '' ? (int)$_POST['year_from'] : null;
            $yearTo = isset($_POST['year_to']) && $_POST['year_to'] !== '' ? (int)$_POST['year_to'] : null;
            if ($yearFrom !== null && $yearTo !== null && $yearFrom > $yearTo) throw new InvalidArgumentException('L’anno iniziale non può essere successivo a quello finale.');
            $bundle = transferExport($pdo, $codes, $yearFrom, $yearTo);
            $filename = 'openbollettedb-export-' . date('Y-m-d-His') . '.json';
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            exit;
        }
        if ($action === 'upload_import') {
            $upload = $_FILES['transfer_file'] ?? null;
            if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Seleziona un file JSON valido.');
            if ((int)$upload['size'] > 10 * 1024 * 1024) throw new RuntimeException('Il file supera il limite di 10 MB.');
            $raw = file_get_contents((string)$upload['tmp_name']);
            if ($raw === false) throw new RuntimeException('Impossibile leggere il file caricato.');
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('Contenuto JSON non valido.');
            $bills = transferValidate($decoded);
            $_SESSION['transfer_import'] = ['bills'=>$bills, 'source_name'=>(string)$upload['name'], 'created'=>time()];
            $transferPreview = transferPreview($pdo, $bills);
        }
        if ($action === 'confirm_import') {
            $state = $_SESSION['transfer_import'] ?? null;
            if (!is_array($state) || !is_array($state['bills'] ?? null) || time()-(int)($state['created']??0)>3600) throw new RuntimeException('Anteprima scaduta: carica nuovamente il file.');
            $mode = isset($_POST['conflict_mode']) && is_scalar($_POST['conflict_mode']) ? (string)$_POST['conflict_mode'] : 'keep';
            $pdo->exec('PRAGMA wal_checkpoint(FULL)');
            $backup = __DIR__ . '/data/backup_before_transfer_' . date('Ymd_His') . '.sqlite';
            if (!copy(__DIR__ . '/data/openbollettedb.sqlite', $backup)) throw new RuntimeException('Creazione del backup fallita. Importazione annullata.');
            $pdo->beginTransaction();
            try { $result = transferImport($pdo, $state['bills'], $mode); $pdo->commit(); }
            catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            unset($_SESSION['transfer_import']);
            $transferSuccess = sprintf('Importazione completata: %d nuove, %d sostituite, %d identiche, %d ignorate. Backup: %s', $result['inserted'], $result['replaced'], $result['identical'], $result['skipped'], basename($backup));
        }
        if ($action === 'cancel_import') unset($_SESSION['transfer_import']);
    } catch (Throwable $e) { $transferError = $e->getMessage(); }
}

if ($transferPreview === null && is_array($_SESSION['transfer_import'] ?? null)) {
    $state = $_SESSION['transfer_import'];
    if (time()-(int)($state['created']??0)<=3600 && is_array($state['bills']??null)) $transferPreview = transferPreview($pdo, $state['bills']);
}
$utilities = $pdo->query('SELECT code,name FROM utilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$yearBounds = $pdo->query("SELECT MIN(CAST(strftime('%Y',period_start) AS INTEGER)) min_year, MAX(CAST(strftime('%Y',period_start) AS INTEGER)) max_year FROM bills")->fetch(PDO::FETCH_ASSOC);
$profileText = t(is_admin() ? 'settings.profile_for' : 'settings.profile_for_user', [
    'name' => (string)$user['display_name'],
]);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_language()) ?>">
<head>
  <meta charset="utf-8">
  <title>Settings – OpenBolletteDB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="assets/css/index_style.css">
  <link rel="stylesheet" href="assets/css/settings_style.css?v=<?= filemtime(__DIR__ . '/assets/css/settings_style.css') ?>">
</head>
<body>
<div class="container">
  <?php require 'partials/header.php'; ?>

  <section class="card settings-intro">
    <div>
      <h2>⚙️ <?= htmlspecialchars(t('settings.title')) ?></h2>
      <p class="sub"><?= htmlspecialchars(t('settings.subtitle')) ?></p>
    </div>
  </section>

  <div class="settings-grid">
    <section class="card settings-card">
      <div class="settings-icon">👤</div>
      <div class="settings-content">
        <h2><?= htmlspecialchars(t('settings.account')) ?></h2>
        <p><?= htmlspecialchars($profileText) ?></p>
        <a class="btn" href="account.php"><?= htmlspecialchars(t('settings.open_users')) ?></a>
      </div>
    </section>

    <section class="card settings-card settings-language-card">
      <div class="settings-icon">🌐</div>
      <div class="settings-content">
        <h2><?= htmlspecialchars(t('settings.language')) ?></h2>
        <p><?= htmlspecialchars(t('settings.language_text')) ?></p>
        <?php if ($languageSuccess): ?><div class="settings-message success"><?= htmlspecialchars($languageSuccess) ?></div><?php endif; ?>
        <form method="post" class="language-form">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="update_language">
          <label for="language"><?= htmlspecialchars(t('common.language')) ?></label>
          <select id="language" name="language">
            <?php foreach (SUPPORTED_LANGUAGES as $language): ?>
              <option value="<?= htmlspecialchars($language) ?>" <?= current_language()===$language?'selected':'' ?>><?= htmlspecialchars(language_name($language)) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn" type="submit"><?= htmlspecialchars(t('settings.save_language')) ?></button>
        </form>
      </div>
    </section>

    <section class="card settings-card settings-transfer-card">
      <div class="settings-icon">🔄</div>
      <div class="settings-content">
        <h2><?= htmlspecialchars(t('settings.transfer')) ?></h2>
        <?php if (is_admin()): ?>
          <p><?= htmlspecialchars(t('settings.transfer_text')) ?></p>

          <?php if ($transferSuccess): ?><div class="settings-message success"><?= htmlspecialchars($transferSuccess) ?></div><?php endif; ?>
          <?php if ($transferError): ?><div class="settings-message error"><?= htmlspecialchars($transferError) ?></div><?php endif; ?>

          <div class="transfer-panels">
            <form method="post" class="transfer-panel">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="action" value="export">
              <h3><?= htmlspecialchars(t('transfer.export')) ?></h3>
              <div class="transfer-utilities">
                <?php foreach ($utilities as $utility): ?>
                  <label><input type="checkbox" name="utilities[]" value="<?= htmlspecialchars((string)$utility['code']) ?>" checked> <?= htmlspecialchars(utility_label((string)$utility['code'], (string)$utility['name'])) ?></label>
                <?php endforeach; ?>
              </div>
              <div class="transfer-years">
                <label><?= htmlspecialchars(t('transfer.from')) ?> <input type="number" name="year_from" min="2000" max="2100" value="<?= (int)($yearBounds['min_year'] ?: date('Y')) ?>"></label>
                <label><?= htmlspecialchars(t('transfer.to')) ?> <input type="number" name="year_to" min="2000" max="2100" value="<?= (int)($yearBounds['max_year'] ?: date('Y')) ?>"></label>
              </div>
              <button class="btn" type="submit">⬇️ <?= htmlspecialchars(t('transfer.download')) ?></button>
            </form>

            <form method="post" enctype="multipart/form-data" class="transfer-panel">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="action" value="upload_import">
              <h3><?= htmlspecialchars(t('transfer.import')) ?></h3>
              <label class="transfer-file"><?= htmlspecialchars(t('transfer.file')) ?>
                <input type="file" name="transfer_file" accept="application/json,.json" required>
              </label>
              <p class="settings-hint"><?= htmlspecialchars(t('transfer.preview_hint')) ?></p>
              <button class="btn secondary" type="submit"><?= htmlspecialchars(t('transfer.analyze')) ?></button>
            </form>
          </div>

          <?php if ($transferPreview !== null): ?>
            <div class="transfer-preview">
              <h3><?= htmlspecialchars(t('transfer.preview')) ?></h3>
              <div class="transfer-counts">
                <span><?= htmlspecialchars(t('transfer.new')) ?>: <strong><?= (int)$transferPreview['counts']['new'] ?></strong></span>
                <span><?= htmlspecialchars(t('transfer.identical')) ?>: <strong><?= (int)$transferPreview['counts']['identical'] ?></strong></span>
                <span><?= htmlspecialchars(t('transfer.conflicts')) ?>: <strong><?= (int)$transferPreview['counts']['different'] ?></strong></span>
              </div>
              <div class="table-container"><table><thead><tr><th><?= htmlspecialchars(t('transfer.utility')) ?></th><th><?= htmlspecialchars(t('transfer.period')) ?></th><th><?= htmlspecialchars(t('transfer.amount')) ?></th><th><?= htmlspecialchars(t('transfer.status')) ?></th></tr></thead><tbody>
              <?php foreach ($transferPreview['rows'] as $row): $bill=$row['bill']; ?>
                <tr><td><?= htmlspecialchars(utility_label((string)$bill['utility']['code'], (string)$bill['utility']['name'])) ?></td><td><?= htmlspecialchars(format_ui_date($bill['period_start'])) ?> – <?= htmlspecialchars(format_ui_date($bill['period_end'])) ?></td><td>€ <?= number_format((float)$bill['amount_total'],2,',','.') ?></td><td><span class="transfer-state <?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(['new'=>t('transfer.state_new'),'identical'=>t('transfer.state_identical'),'different'=>t('transfer.state_conflict')][$row['status']]) ?></span></td></tr>
              <?php endforeach; ?>
              </tbody></table></div>
              <form method="post" class="transfer-confirm">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="confirm_import">
                <label><?= htmlspecialchars(t('transfer.on_conflict')) ?> <select name="conflict_mode"><option value="keep"><?= htmlspecialchars(t('transfer.keep')) ?></option><option value="replace"><?= htmlspecialchars(t('transfer.replace')) ?></option></select></label>
                <button class="btn" type="submit"><?= htmlspecialchars(t('transfer.confirm')) ?></button>
              </form>
              <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="cancel_import"><button class="btn secondary" type="submit"><?= htmlspecialchars(t('transfer.cancel')) ?></button></form>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p><?= htmlspecialchars(t('settings.admin_only')) ?></p>
          <div class="settings-status muted"><?= htmlspecialchars(t('settings.read_only')) ?></div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card settings-card settings-card-future">
      <div class="settings-icon">🧩</div>
      <div class="settings-content">
        <h2><?= htmlspecialchars(t('settings.future')) ?></h2>
        <p><?= htmlspecialchars(t('settings.future_text')) ?></p>
      </div>
    </section>
  </div>

  <?php require 'partials/footer.php'; ?>
</div>
</body>
</html>
