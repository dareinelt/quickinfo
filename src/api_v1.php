<?php
declare(strict_types=1);

/**
 * quickinfo – Öffentliche REST-API v1 für das Management-Board (JSON).
 * Read-only mit einer Ausnahme: Docker-Container-Aktionen (start|stop|restart) per POST.
 *
 * Authentifizierung: Authorization: Bearer <API-KEY>   (alternativ: X-API-Key: <API-KEY>)
 *
 *  GET /api/v1/          Endpunktübersicht
 *  GET /api/v1/status    Aktuelle Messwerte (CPU, GPU, Temperatur, Speicherplatz, RAM, Dienste)
 *  GET /api/v1/history   ?range=1h|3h|24h|3d|14d[&metrics=cpu.total,temp.max]  Zeitreihen
 *  GET /api/v1/info      Server-Metadaten (Hostname, Uptime, Systemzeit, CPU-Kerne, GPU-Modell)
 *
 * Docker (nur wenn ein Docker-Host konfiguriert und aktiviert ist):
 *  GET  /api/v1/docker/containers                      Container-Übersicht
 *  GET  /api/v1/docker/containers/{name}               Detail (Inspect) inkl. Notiz
 *  GET  /api/v1/docker/containers/{name}/stats         Live-Auslastung
 *  POST /api/v1/docker/containers/{name}/start|stop|restart
 */

const QI_API_V1_ENDPOINTS = [
    'status'  => 'Aktuelle Messwerte und Dienststatus',
    'history' => 'Zeitreihen; Parameter range=1h|3h|24h|3d|14d, optional metrics=<liste>',
    'info'    => 'Server-Metadaten',
    'docker'  => 'Docker-Container, Details, Live-Stats und Aktionen (start|stop|restart)',
];

function qi_api_v1_dispatch(string $endpoint, string $method): never
{
    qi_api_cors_headers();
    header('Cache-Control: no-store');

    if ($method === 'OPTIONS') {
        // CORS-Preflight benötigt keine Authentifizierung und keinen Body
        http_response_code(204);
        exit;
    }
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST, OPTIONS');
        qi_json_error('Methode nicht erlaubt.', 405);
    }

    qi_api_require_key();

    // Docker-Endpunkte: lesend (GET/HEAD) plus Aktionen (POST, nur start|stop|restart).
    if ($endpoint === 'docker' || str_starts_with($endpoint, 'docker/')) {
        qi_api_v1_docker($endpoint, $method);
    }

    // Alle übrigen v1-Endpunkte bleiben schreibgeschützt.
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD, OPTIONS');
        qi_json_error('Methode nicht erlaubt. Die API ist schreibgeschützt.', 405);
    }

    switch ($endpoint) {
        case '':
            qi_json_response([
                'name'      => 'quickinfo',
                'version'   => QI_VERSION,
                'api'       => 'v1',
                'hostname'  => php_uname('n'),
                'endpoints' => array_map(
                    static fn(string $k, string $d) => ['path' => '/api/v1/' . $k, 'description' => $d],
                    array_keys(QI_API_V1_ENDPOINTS),
                    QI_API_V1_ENDPOINTS
                ),
            ]);
        case 'status':
            qi_api_v1_status();
        case 'history':
            qi_api_v1_history();
        case 'info':
            qi_api_v1_info();
    }
    qi_json_error('Endpunkt nicht gefunden.', 404);
}

/**
 * Liest den aktuellen Snapshot des Collectors.
 * @return array{snapshot:?array, ts:?int}
 */
function qi_api_v1_snapshot(): array
{
    $row = qi_db()->query("SELECT v, ts FROM snapshot WHERE k = 'latest'")->fetch();
    $snapshot = $row ? json_decode((string)$row['v'], true) : null;
    return ['snapshot' => is_array($snapshot) ? $snapshot : null, 'ts' => $row ? (int)$row['ts'] : null];
}

function qi_api_v1_status(): never
{
    ['snapshot' => $s, 'ts' => $snapshotTs] = qi_api_v1_snapshot();
    $now = time();
    $s = $s ?? [];

    $gpus = [];
    foreach ($s['gpus'] ?? [] as $g) {
        $gpus[] = [
            'index'        => (int)($g['index'] ?? 0),
            'name'         => (string)($g['name'] ?? ''),
            'utilization'  => $g['util'] ?? null,
            'temperature'  => $g['temp'] ?? null,
            'memory_used_mb'  => $g['mem_used_mb'] ?? null,
            'memory_total_mb' => $g['mem_total_mb'] ?? null,
            'memory_pct'   => (!empty($g['mem_total_mb']) && isset($g['mem_used_mb']))
                ? round((float)$g['mem_used_mb'] / (float)$g['mem_total_mb'] * 100, 2) : null,
            'power_w'      => $g['power_w'] ?? null,
        ];
    }

    $services = [];
    $up = 0;
    $total = 0;
    foreach (qi_services_list() as $svc) {
        $total++;
        if ($svc['last_active'] === true) {
            $up++;
        }
        $services[] = [
            'name'         => $svc['name'],
            'display_name' => $svc['display_name'],
            'active'       => $svc['last_active'],
            'state'        => $svc['last_state'],
            'last_check'   => $svc['last_check'],
            'uptime_24h'   => $svc['uptime_24h'],
        ];
    }

    $age = $snapshotTs !== null ? $now - $snapshotTs : null;
    $disk = $s['disk'] ?? null;
    $mem = $s['memory'] ?? null;

    qi_json_response([
        'hostname'     => $s['hostname'] ?? php_uname('n'),
        'now'          => $now,
        'snapshot_ts'  => $snapshotTs,
        'snapshot_age' => $age,
        'stale'        => $age === null || $age > 180,
        'cpu' => [
            'utilization' => $s['cpu']['total'] ?? null,
            'cores'       => $s['cpu']['cores'] ?? [],
            'count'       => $s['cpu']['count'] ?? null,
            'temperature' => $s['temp_max'] ?? null,
            'sensors'     => $s['temps'] ?? [],
        ],
        'gpus'   => $gpus,
        'memory' => $mem ? [
            'total' => $mem['total'] ?? null,
            'used'  => $mem['used'] ?? null,
            'pct'   => $mem['pct'] ?? null,
        ] : null,
        'disk' => $disk ? [
            'mount'      => $disk['mount'] ?? '/',
            'filesystem' => $disk['filesystem'] ?? null,
            'total'      => $disk['total'] ?? null,
            'used'       => $disk['used'] ?? null,
            'available'  => $disk['available'] ?? null,
            'pct'        => $disk['pct'] ?? null,
        ] : null,
        'load'   => $s['load'] ?? null,
        'uptime' => $s['uptime'] ?? null,
        'services' => [
            'total' => $total,
            'up'    => $up,
            'down'  => $total - $up,
            'items' => $services,
        ],
    ]);
}

function qi_api_v1_history(): never
{
    $range = (string)($_GET['range'] ?? '1h');
    if (!isset(QI_RANGES[$range])) {
        qi_json_error('Ungültiger Zeitraum. Erlaubt: ' . implode(', ', array_keys(QI_RANGES)), 400);
    }
    $data = qi_history_build($range);

    // Optionaler Filter auf einzelne Metriken (Komma-getrennt)
    $filter = trim((string)($_GET['metrics'] ?? ''));
    if ($filter !== '') {
        $wanted = array_flip(array_filter(array_map('trim', explode(',', $filter))));
        $data['series'] = array_intersect_key($data['series'], $wanted);
    }
    $data['hostname'] = php_uname('n');
    $data['metrics'] = array_keys($data['series']);
    qi_json_response($data);
}

function qi_api_v1_info(): never
{
    ['snapshot' => $s] = qi_api_v1_snapshot();
    $s = $s ?? [];

    $os = ['name' => PHP_OS, 'pretty_name' => null, 'version_id' => null];
    foreach (@file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^(NAME|PRETTY_NAME|VERSION_ID)=(.*)$/', $line, $m)) {
            $val = trim($m[2], "\"'");
            if ($m[1] === 'NAME') {
                $os['name'] = $val;
            } elseif ($m[1] === 'PRETTY_NAME') {
                $os['pretty_name'] = $val;
            } else {
                $os['version_id'] = $val;
            }
        }
    }

    // Uptime bevorzugt live aus /proc, sonst aus dem Snapshot
    $uptime = null;
    $up = @file_get_contents('/proc/uptime');
    if ($up !== false && $up !== '') {
        $uptime = (int)explode(' ', trim($up))[0];
    } elseif (isset($s['uptime'])) {
        $uptime = (int)$s['uptime'];
    }
    $now = time();

    $gpus = [];
    foreach ($s['gpus'] ?? [] as $g) {
        $gpus[] = [
            'index'           => (int)($g['index'] ?? 0),
            'model'           => (string)($g['name'] ?? ''),
            'memory_total_mb' => $g['mem_total_mb'] ?? null,
        ];
    }

    qi_json_response([
        'hostname'     => $s['hostname'] ?? php_uname('n'),
        'version'      => QI_VERSION,
        'time'         => $now,
        'time_iso'     => date(DATE_ATOM, $now),
        'timezone'     => date_default_timezone_get(),
        'uptime'       => $uptime,
        'boot_time'    => $uptime !== null ? $now - $uptime : null,
        'os'           => $os,
        'kernel'       => php_uname('r'),
        'arch'         => php_uname('m'),
        'cpu' => [
            'model' => $s['cpu']['model'] ?? null,
            'cores' => $s['cpu']['count'] ?? null,
        ],
        'gpus'         => $gpus,
        'gpu_model'    => $gpus ? $gpus[0]['model'] : null,
        'memory_total' => $s['memory']['total'] ?? null,
        'disk' => isset($s['disk']) ? [
            'mount'      => $s['disk']['mount'] ?? '/',
            'filesystem' => $s['disk']['filesystem'] ?? null,
            'total'      => $s['disk']['total'] ?? null,
        ] : null,
        'services_monitored' => (int)qi_db()->query('SELECT COUNT(*) FROM services')->fetchColumn(),
        'ranges'       => array_keys(QI_RANGES),
    ]);
}

/**
 * Verteilt Docker-Sub-Routen der öffentlichen v1-API:
 *   (leer)                                   GET  → Konfiguration + Endpunktübersicht
 *   containers                               GET  → Container-Übersicht
 *   containers/{name}                        GET  → Detail (Inspect) inkl. Notiz
 *   containers/{name}/stats                  GET  → Live-Auslastung
 *   containers/{name}/start|stop|restart     POST → Aktion
 */
function qi_api_v1_docker(string $endpoint, string $method): never
{
    $parts = explode('/', $endpoint);
    // $parts[0] === 'docker'
    $resource = (string)($parts[1] ?? '');
    $ref = rawurldecode((string)($parts[2] ?? ''));
    $action = (string)($parts[3] ?? '');

    if ($resource === '' && in_array($method, ['GET', 'HEAD'], true)) {
        qi_json_response([
            'config'    => qi_docker_config_public(),
            'endpoints' => [
                '/api/v1/docker/containers',
                '/api/v1/docker/containers/{name}',
                '/api/v1/docker/containers/{name}/stats',
                '/api/v1/docker/containers/{name}/start|stop|restart',
            ],
        ]);
    }

    if ($resource !== 'containers') {
        qi_json_error('Endpunkt nicht gefunden.', 404);
    }

    if ($ref === '' && in_array($method, ['GET', 'HEAD'], true)) {
        qi_api_v1_docker_containers();
    }

    if ($ref !== '' && $action === '' && in_array($method, ['GET', 'HEAD'], true)) {
        qi_api_v1_docker_container_detail($ref);
    }

    if ($ref !== '' && $action === 'stats' && in_array($method, ['GET', 'HEAD'], true)) {
        qi_api_v1_docker_container_stats($ref);
    }

    if ($ref !== '' && in_array($action, ['start', 'stop', 'restart'], true) && $method === 'POST') {
        qi_api_v1_docker_container_action($ref, $action);
    }

    qi_json_error('Endpunkt nicht gefunden.', 404);
}

/**
 * Stellt sicher, dass ein Docker-Host konfiguriert und aktiviert ist.
 */
function qi_api_v1_docker_require_host(): void
{
    $config = qi_docker_config_public();
    if (!$config['enabled']) {
        qi_json_error('Docker-Modul ist nicht aktiviert.', 404, ['docker' => $config]);
    }
    if (qi_docker_credentials() === null) {
        qi_json_error('Docker-Host-Zugangsdaten sind unvollständig.', 404, ['docker' => $config]);
    }
}

function qi_api_v1_docker_containers(): never
{
    qi_api_v1_docker_require_host();
    try {
        $containers = qi_docker_containers();
    } catch (Throwable $e) {
        qi_json_error('Docker-Host nicht erreichbar: ' . $e->getMessage(), 502);
    }
    qi_json_response(['containers' => $containers]);
}

function qi_api_v1_docker_container_detail(string $ref): never
{
    qi_api_v1_docker_require_host();
    try {
        $detail = qi_docker_inspect($ref);
    } catch (Throwable $e) {
        qi_json_error('Docker-Host nicht erreichbar: ' . $e->getMessage(), 502);
    }
    if ($detail === null) {
        qi_json_error('Container nicht gefunden.', 404);
    }
    $name = ltrim((string)($detail['name'] ?? $ref), '/');
    $note = qi_docker_note_row($name);
    $detail['note'] = $note['note'];
    $detail['note_updated_at'] = $note['updated_at'];
    qi_json_response($detail);
}

function qi_api_v1_docker_container_stats(string $ref): never
{
    qi_api_v1_docker_require_host();
    try {
        $stats = qi_docker_stats($ref);
    } catch (Throwable $e) {
        qi_json_error('Docker-Host nicht erreichbar: ' . $e->getMessage(), 502);
    }
    if ($stats === null) {
        qi_json_error('Keine Statistik verfügbar.', 404);
    }
    qi_json_response($stats);
}

function qi_api_v1_docker_container_action(string $ref, string $action): never
{
    qi_api_v1_docker_require_host();
    try {
        qi_docker_action($ref, $action);
    } catch (Throwable $e) {
        qi_json_error('Aktion fehlgeschlagen: ' . $e->getMessage(), 502);
    }
    qi_json_response(['ok' => true]);
}
