<?php
declare(strict_types=1);

/**
 * quickinfo – API-Schlüssel (Bearer Token) für das Management-Board.
 *
 * Es existiert genau ein aktiver Schlüssel. Er wird kryptografisch zufällig erzeugt
 * (256 Bit Entropie), ausschließlich als SHA-256-Hash gespeichert und kann jederzeit
 * rotiert oder widerrufen werden. Da der Schlüssel selbst hochentropisch ist, ist ein
 * schneller Hash (statt bcrypt) ausreichend und ermöglicht eine Prüfung ohne spürbare
 * Latenz pro Request.
 */

const QI_API_KEY_PREFIX = 'qi_';
const QI_API_KEY_BYTES  = 32;

function qi_api_key_generate(): string
{
    return QI_API_KEY_PREFIX . bin2hex(random_bytes(QI_API_KEY_BYTES));
}

function qi_api_key_hash(string $key): string
{
    return hash('sha256', $key);
}

function qi_api_key_display_prefix(string $key): string
{
    return substr($key, 0, strlen(QI_API_KEY_PREFIX) + 8);
}

/**
 * Metadaten des aktiven Schlüssels (ohne Hash) oder null, wenn keiner konfiguriert ist.
 */
function qi_api_key_info(): ?array
{
    $row = qi_db()->query(
        'SELECT id, label, key_prefix, created_at, created_by, last_used_at, last_used_ip, use_count
           FROM api_keys ORDER BY id DESC LIMIT 1'
    )->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id'           => (int)$row['id'],
        'label'        => (string)$row['label'],
        'prefix'       => (string)$row['key_prefix'],
        'created_at'   => (int)$row['created_at'],
        'created_by'   => $row['created_by'] !== null ? (string)$row['created_by'] : null,
        'last_used_at' => $row['last_used_at'] !== null ? (int)$row['last_used_at'] : null,
        'last_used_ip' => $row['last_used_ip'] !== null ? (string)$row['last_used_ip'] : null,
        'use_count'    => (int)$row['use_count'],
    ];
}

/**
 * Erzeugt einen neuen Schlüssel, ersetzt den bisherigen und liefert den Klartext
 * (einmalig!) zurück.
 */
function qi_api_key_rotate(?string $createdBy = null, string $label = 'management-board'): string
{
    $key = qi_api_key_generate();
    $db = qi_db();
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM api_keys');
        $db->prepare('INSERT INTO api_keys (label, key_prefix, key_hash, created_at, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                mb_substr($label, 0, 64),
                qi_api_key_display_prefix($key),
                qi_api_key_hash($key),
                time(),
                $createdBy !== null ? mb_substr($createdBy, 0, 64) : null,
            ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $key;
}

function qi_api_key_revoke(): bool
{
    return qi_db()->exec('DELETE FROM api_keys') > 0;
}

/**
 * Prüft einen Klartext-Schlüssel in konstanter Zeit. Liefert die Schlüssel-Zeile oder null.
 */
function qi_api_key_verify(string $key): ?array
{
    if ($key === '' || strlen($key) > 256) {
        return null;
    }
    $row = qi_db()->query('SELECT id, key_hash FROM api_keys ORDER BY id DESC LIMIT 1')->fetch();
    // Auch ohne konfigurierten Schlüssel wird ein Vergleich durchgeführt (konstante Laufzeit).
    $stored = $row ? (string)$row['key_hash'] : str_repeat('0', 64);
    $valid = hash_equals($stored, qi_api_key_hash($key)) && $row;
    return $valid ? ['id' => (int)$row['id']] : null;
}

/**
 * Liest den API-Schlüssel aus "Authorization: Bearer <key>" oder "X-API-Key: <key>".
 */
function qi_api_key_from_request(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $auth = (string)$value;
                break;
            }
        }
    }
    if ($auth !== '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
        return $m[1];
    }
    $header = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return $header !== '' ? $header : null;
}

function qi_api_throttle_key(): string
{
    return substr('api:' . qi_client_ip(), 0, 45);
}

function qi_api_locked(): int
{
    $api = qi_config()['api'];
    $stmt = qi_db()->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?');
    $stmt->execute([qi_api_throttle_key()]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }
    $remaining = ((int)$row['last_attempt'] + (int)$api['lockout_seconds']) - time();
    return ((int)$row['attempts'] >= (int)$api['max_failures'] && $remaining > 0) ? $remaining : 0;
}

function qi_api_record_failure(): void
{
    $api = qi_config()['api'];
    $now = time();
    qi_db()->prepare(
        'INSERT INTO login_attempts (ip, attempts, last_attempt) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE
            attempts = IF(last_attempt < ?, 1, attempts + 1),
            last_attempt = VALUES(last_attempt)'
    )->execute([qi_api_throttle_key(), $now, $now - (int)$api['lockout_seconds']]);
}

/**
 * Erzwingt einen gültigen API-Schlüssel. Bricht mit 401/429 ab.
 * @return array{id:int}
 */
function qi_api_require_key(): array
{
    $locked = qi_api_locked();
    if ($locked > 0) {
        header('Retry-After: ' . $locked);
        qi_json_error('Zu viele fehlgeschlagene Authentifizierungen. Bitte später erneut versuchen.', 429, ['retry_after' => $locked]);
    }

    $key = qi_api_key_from_request();
    $row = $key !== null ? qi_api_key_verify($key) : null;
    if ($row === null) {
        qi_api_record_failure();
        header('WWW-Authenticate: Bearer realm="quickinfo", error="invalid_token"');
        qi_json_error('Nicht autorisiert. Gültiger API-Schlüssel erforderlich (Authorization: Bearer <key>).', 401);
    }

    // Nutzungsstatistik höchstens einmal pro Minute schreiben
    qi_db()->prepare(
        'UPDATE api_keys SET use_count = use_count + 1, last_used_at = ?, last_used_ip = ?
          WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)'
    )->execute([time(), qi_client_ip(), $row['id'], time() - 60]);

    qi_db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([qi_api_throttle_key()]);
    return $row;
}

/**
 * Setzt CORS-Header gemäß Konfiguration. Liefert true, wenn der Origin erlaubt ist.
 */
function qi_api_cors_headers(): bool
{
    $origins = qi_config()['api']['cors_origins'] ?? [];
    if (!is_array($origins) || !$origins) {
        return false;
    }
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowed = false;

    if (in_array('*', $origins, true)) {
        header('Access-Control-Allow-Origin: *');
        $allowed = true;
    } elseif ($origin !== '') {
        foreach ($origins as $o) {
            if (is_string($o) && strcasecmp(rtrim($o, '/'), rtrim($origin, '/')) === 0) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                $allowed = true;
                break;
            }
        }
    }
    if ($allowed) {
        header('Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type, Accept');
        header('Access-Control-Expose-Headers: Retry-After');
        header('Access-Control-Max-Age: 86400');
    }
    return $allowed;
}
