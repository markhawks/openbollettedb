<?php
declare(strict_types=1);
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/csrf.php';

if (!csrf_verify($_GET['csrf'] ?? null)) {
    http_response_code(403);
    die('Richiesta non valida (token CSRF mancante o scaduto). Torna indietro e riprova.');
}

$utilityCode = $_GET['u'] ?? '';
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$utilitaValide = ['luce', 'gas', 'acqua', 'tari', 'bonifica'];

if (in_array($utilityCode, $utilitaValide, true) && $year >= 2000 && $year <= 2100) {
    $pdo = db();
    // Grazie alle chiavi esterne (ON DELETE CASCADE), le bill_metrics collegate
    // vengono eliminate automaticamente insieme alle bollette.
    $stmt = $pdo->prepare("
        DELETE FROM bills
        WHERE utility_id = (SELECT id FROM utilities WHERE code = ?)
          AND strftime('%Y', period_start) = ?
    ");
    $stmt->execute([$utilityCode, (string)$year]);
}

header('Location: index.php?u=' . urlencode($utilityCode));
exit;
