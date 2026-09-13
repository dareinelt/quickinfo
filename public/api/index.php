<?php
declare(strict_types=1);

/**
 * quickinfo – API-Einstiegspunkt. Alle /api/* Anfragen werden von Nginx hierher geleitet.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/auth.php';
require dirname(__DIR__, 2) . '/src/api.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('[quickinfo] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Interner Serverfehler.']);
    exit;
});

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

qi_api_dispatch();
