<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const AUTH_IDLE_TIMEOUT = 1800;
const AUTH_MAX_ATTEMPTS = 5;
const AUTH_ATTEMPT_WINDOW = 900;
const AUTH_BLOCK_TIME = 900;

function auth_app_url(string $path): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (in_array(basename($dir), ['app', 'pages', 'partials'], true)) $dir = dirname($dir);
    return rtrim($dir, '/') . '/' . ltrim($path, '/');
}

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => auth_app_url(''),
    ]);
    session_start();
}

function logout_user(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function current_user(): ?array
{
    auth_start_session();
    if (empty($_SESSION['user']['id'])) return null;
    $now = time();
    if (!empty($_SESSION['last_activity']) && $now - (int)$_SESSION['last_activity'] > AUTH_IDLE_TIMEOUT) {
        logout_user();
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, display_name, role, session_version FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user']['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int)$user['session_version'] !== (int)($_SESSION['user']['session_version'] ?? -1)) {
        logout_user();
        return null;
    }
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'display_name' => (string)$user['display_name'],
        'role' => (string)$user['role'],
        'session_version' => (int)$user['session_version'],
    ];
    $_SESSION['last_activity'] = $now;
    return $_SESSION['user'];
}

function require_login(): void
{
    if (current_user() === null) {
        header('Location: ' . auth_app_url('login.php'));
        exit;
    }
}

function login_attempt_key(string $username): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
}

function attempt_login(string $username, string $password): bool
{
    auth_start_session();
    $pdo = db();
    $key = login_attempt_key($username);
    $now = time();
    $attemptStmt = $pdo->prepare('SELECT attempts, first_attempt, blocked_until FROM login_attempts WHERE identifier = ?');
    $attemptStmt->execute([$key]);
    $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
    if ($attempt && (int)$attempt['blocked_until'] > $now) return false;

    $stmt = $pdo->prepare('SELECT id, username, password_hash, display_name, role, session_version FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $resetWindow = !$attempt || $now - (int)$attempt['first_attempt'] > AUTH_ATTEMPT_WINDOW;
        $first = $resetWindow ? $now : (int)$attempt['first_attempt'];
        $count = $resetWindow ? 1 : ((int)$attempt['attempts'] + 1);
        $blockedUntil = $count >= AUTH_MAX_ATTEMPTS ? $now + AUTH_BLOCK_TIME : 0;
        $save = $pdo->prepare('INSERT INTO login_attempts(identifier, attempts, first_attempt, blocked_until) VALUES(?,?,?,?) ON CONFLICT(identifier) DO UPDATE SET attempts=excluded.attempts, first_attempt=excluded.first_attempt, blocked_until=excluded.blocked_until');
        $save->execute([$key, $count, $first, $blockedUntil]);
        return false;
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$key]);
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'display_name' => (string)$user['display_name'],
        'role' => (string)$user['role'],
        'session_version' => (int)$user['session_version'],
    ];
    $_SESSION['last_activity'] = $now;
    return true;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && ($user['role'] ?? 'user') === 'admin';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('Il tuo utente ha accesso in sola lettura: non puoi modificare le bollette.');
    }
}
