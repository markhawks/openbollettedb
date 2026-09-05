<?php
declare(strict_types=1);
require __DIR__ . '/app/auth.php';
require_admin();
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metodo non consentito.');
}
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(403);
    die('Richiesta non valida (token CSRF mancante o scaduto). Torna indietro e riprova.');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$utilityCode = 'luce';

if ($id > 0) {
    $pdo = db();
    $lookup = $pdo->prepare('SELECT u.code FROM bills b JOIN utilities u ON u.id = b.utility_id WHERE b.id = ?');
    $lookup->execute([$id]);
    $utilityCode = (string)($lookup->fetchColumn() ?: 'luce');
    // Grazie alle chiavi esterne (ON DELETE CASCADE), eliminando la bolletta
    // verranno eliminati automaticamente anche i relativi record in bill_metrics.
    $stmt = $pdo->prepare("DELETE FROM bills WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: index.php?u=" . urlencode($utilityCode), true, 303);
exit;
