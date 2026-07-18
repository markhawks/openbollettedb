<?php
declare(strict_types=1);
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/csrf.php';

auth_start_session();
if (csrf_verify($_GET['csrf'] ?? null)) {
    logout_user();
}

header('Location: login.php');
exit;
