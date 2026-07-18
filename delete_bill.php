<?php
declare(strict_types=1);
require __DIR__ . '/app/auth.php';
require_login();
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/csrf.php';

if (!csrf_verify($_GET['csrf'] ?? null)) {
    http_response_code(403);
    die('Richiesta non valida (token CSRF mancante o scaduto). Torna indietro e riprova.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$utilityCode = $_GET['u'] ?? 'luce';

if ($id > 0) {
    $pdo = db();
    // Grazie alle chiavi esterne (ON DELETE CASCADE), eliminando la bolletta
    // verranno eliminati automaticamente anche i relativi record in bill_metrics.
    $stmt = $pdo->prepare("DELETE FROM bills WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: index.php?u=" . urlencode($utilityCode));
exit;