<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function current_user(): ?array
{
    auth_start_session();
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    auth_start_session();
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

function attempt_login(string $username, string $password): bool
{
    auth_start_session();

    $stmt = db()->prepare('SELECT id, username, password_hash, display_name FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => (int)$user['id'],
        'username'     => $user['username'],
        'display_name' => $user['display_name'],
    ];
    return true;
}

function logout_user(): void
{
    auth_start_session();
    $_SESSION = [];
    session_destroy();
}
