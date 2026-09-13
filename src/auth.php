<?php
declare(strict_types=1);

/**
 * quickinfo – Session-basierte Authentifizierung, CSRF-Schutz, Login-Throttling.
 */

function qi_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $auth = qi_config()['auth'];
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name($auth['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)(int)$auth['session_lifetime']);
    session_start();

    // Absolute Session-Lebensdauer erzwingen
    $now = time();
    if (isset($_SESSION['created']) && ($now - (int)$_SESSION['created']) > (int)$auth['session_lifetime']) {
        qi_logout();
        session_start();
    }
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = $now;
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function qi_current_user(): ?array
{
    qi_session_start();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return ['id' => (int)$_SESSION['user_id'], 'username' => (string)($_SESSION['username'] ?? '')];
}

function qi_require_auth(): array
{
    $user = qi_current_user();
    if ($user === null) {
        qi_json_error('Nicht angemeldet.', 401);
    }
    return $user;
}

function qi_csrf_token(): string
{
    qi_session_start();
    return (string)$_SESSION['csrf'];
}

function qi_require_csrf(): void
{
    qi_session_start();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sent === '' || !hash_equals((string)$_SESSION['csrf'], (string)$sent)) {
        qi_json_error('Ungültiges CSRF-Token.', 403);
    }
}

function qi_login_locked(string $ip): int
{
    $auth = qi_config()['auth'];
    $stmt = qi_db()->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }
    $remaining = ((int)$row['last_attempt'] + (int)$auth['lockout_seconds']) - time();
    if ((int)$row['attempts'] >= (int)$auth['max_attempts'] && $remaining > 0) {
        return $remaining;
    }
    return 0;
}

function qi_login_record_failure(string $ip): void
{
    $auth = qi_config()['auth'];
    $now = time();
    $stmt = qi_db()->prepare(
        'INSERT INTO login_attempts (ip, attempts, last_attempt) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE
            attempts = IF(last_attempt < ? , 1, attempts + 1),
            last_attempt = VALUES(last_attempt)'
    );
    $stmt->execute([$ip, $now, $now - (int)$auth['lockout_seconds']]);
}

function qi_login_clear(string $ip): void
{
    qi_db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
}

/**
 * @return array{ok:bool, error?:string, retry_after?:int, user?:array}
 */
function qi_login(string $username, string $password): array
{
    $ip = qi_client_ip();
    $locked = qi_login_locked($ip);
    if ($locked > 0) {
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte später erneut versuchen.', 'retry_after' => $locked];
    }

    $stmt = qi_db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    // Konstante Laufzeit auch bei unbekanntem Benutzer
    $hash = $row ? $row['password_hash'] : '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvali';
    $valid = password_verify($password, $hash) && $row;

    if (!$valid) {
        qi_login_record_failure($ip);
        usleep(random_int(150000, 400000));
        return ['ok' => false, 'error' => 'Benutzername oder Passwort falsch.'];
    }

    qi_login_clear($ip);
    qi_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['created']  = time();
    $_SESSION['csrf']     = bin2hex(random_bytes(32));

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        qi_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['id']]);
    }
    qi_db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), (int)$row['id']]);

    return ['ok' => true, 'user' => ['id' => (int)$row['id'], 'username' => $row['username']]];
}

function qi_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        qi_session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

function qi_change_password(int $userId, string $current, string $new): array
{
    if (strlen($new) < 10) {
        return ['ok' => false, 'error' => 'Das neue Passwort muss mindestens 10 Zeichen lang sein.'];
    }
    $stmt = qi_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    if ($hash === false || !password_verify($current, (string)$hash)) {
        return ['ok' => false, 'error' => 'Das aktuelle Passwort ist falsch.'];
    }
    qi_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    return ['ok' => true];
}
