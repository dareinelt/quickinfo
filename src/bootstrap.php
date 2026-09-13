<?php
declare(strict_types=1);

/**
 * quickinfo – Bootstrap: Konfiguration, Datenbank, Hilfsfunktionen.
 */

define('QI_VERSION', '1.1.0');
define('QI_ROOT', dirname(__DIR__));

function qi_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $candidates = [];
    $env = getenv('QUICKINFO_CONFIG');
    if ($env) {
        $candidates[] = $env;
    }
    $candidates[] = '/etc/quickinfo/config.php';
    $candidates[] = QI_ROOT . '/config.php';

    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $config = qi_merge_defaults($loaded);
                return $config;
            }
        }
    }
    throw new RuntimeException('Konfigurationsdatei nicht gefunden (/etc/quickinfo/config.php).');
}

function qi_merge_defaults(array $cfg): array
{
    $defaults = [
        'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'quickinfo', 'user' => 'quickinfo', 'password' => '', 'socket' => null],
        'retention' => ['raw_days' => 4, 'agg_days' => 30, 'log_days' => 30],
        'collector' => ['root_fs' => '/', 'cpu_sample_ms' => 1000, 'nvidia_smi' => 'nvidia-smi', 'sensors' => 'sensors'],
        'auth' => ['max_attempts' => 5, 'lockout_seconds' => 900, 'session_lifetime' => 43200, 'session_name' => 'quickinfo_sid'],
        'api'  => ['cors_origins' => ['*'], 'max_failures' => 10, 'lockout_seconds' => 300],
    ];
    foreach ($defaults as $section => $values) {
        $cfg[$section] = array_merge($values, $cfg[$section] ?? []);
    }
    return $cfg;
}

function qi_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db = qi_config()['db'];
    if (!empty($db['socket'])) {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $db['socket'], $db['name']);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int)$db['port'], $db['name']);
    }
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function qi_meta_get(string $key, ?string $default = null): ?string
{
    $stmt = qi_db()->prepare('SELECT v FROM meta WHERE k = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function qi_meta_set(string $key, string $value): void
{
    $stmt = qi_db()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $stmt->execute([$key, $value]);
}

function qi_json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function qi_json_error(string $message, int $status = 400, array $extra = []): never
{
    qi_json_response(['error' => $message] + $extra, $status);
}

function qi_request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        qi_json_error('Ungültiger JSON-Body.', 400);
    }
    return $data;
}

function qi_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr($ip, 0, 45);
}

/**
 * Prüft, ob ein systemd-Unit-Name plausibel ist (keine Shell-Metazeichen).
 */
function qi_valid_service_name(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9@._:\-]{0,119}$/', $name);
}

function qi_normalize_service_name(string $name): string
{
    $name = trim($name);
    if (str_ends_with($name, '.service')) {
        $name = substr($name, 0, -8);
    }
    return $name;
}

/**
 * Führt ein externes Kommando aus und liefert stdout (oder null bei Fehler).
 */
function qi_exec(array $argv, int $timeoutSeconds = 10): ?string
{
    $cmd = implode(' ', array_map('escapeshellarg', $argv));
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $deadline = microtime(true) + $timeoutSeconds;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $out .= (string)stream_get_contents($pipes[1]);
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return null;
        }
        usleep(20000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    // Exit-Code ist nur aus dem letzten proc_get_status() zuverlässig lesbar.
    if (($status['exitcode'] ?? 0) !== 0 && $out === '') {
        return null;
    }
    return $out;
}

function qi_command_exists(string $binary): bool
{
    if (str_contains($binary, '/')) {
        return is_executable($binary);
    }
    foreach (['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'] as $dir) {
        if (is_executable($dir . '/' . $binary)) {
            return true;
        }
    }
    return false;
}
