<?php
declare(strict_types=1);
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/csrf.php';

auth_start_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metodo non consentito.');
}
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Richiesta non valida.');
}
logout_user();

header('Location: login.php', true, 303);
exit;
