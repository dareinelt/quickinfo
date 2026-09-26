<?php
declare(strict_types=1);

/**
 * quickinfo – REST-API (JSON).
 *
 *  GET    /api/session              Login-Status + CSRF-Token
 *  POST   /api/login                {username, password}
 *  POST   /api/logout
 *  GET    /api/overview             Aktueller Snapshot + Dienststatus
 *  GET    /api/history?range=1h     Zeitreihen (1h | 3h | 24h | 3d | 14d)
 *  GET    /api/services             Überwachte Dienste
 *  POST   /api/services             {name, display_name}
 *  PUT    /api/services/{id}        {display_name, sort_order}
 *  DELETE /api/services/{id}
 *  GET    /api/services/available   Auf dem System bekannte systemd-Units
 *  POST   /api/password             {current, new}
 *  POST   /api/system               {description, inventory} – Kurzbeschreibung / Inventarnummer
 *  GET    /api/apikey               Metadaten des API-Schlüssels (Management-Board)
 *  POST   /api/apikey/rotate        Neuen Schlüssel erzeugen (Klartext einmalig in der Antwort)
 *  DELETE /api/apikey               Schlüssel widerrufen
 *
 *  /api/v1/*                        Öffentliche API (Bearer-Token) → src/api_v1.php
 *                                   (read-only; Ausnahme: Docker-Container-Aktionen per POST)
 */

const QI_RANGES = [
    '1h'  => ['seconds' => 3600,       'step' => 60],
    '3h'  => ['seconds' => 3 * 3600,   'step' => 60],
    '24h' => ['seconds' => 24 * 3600,  'step' => 300],
    '3d'  => ['seconds' => 3 * 86400,  'step' => 600],
    '14d' => ['seconds' => 14 * 86400, 'step' => 3600],
];

function qi_api_dispatch(): never
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#^/api/?#', '', $path) ?? '';
    $path = trim($path, '/');
    $parts = $path === '' ? [] : explode('/', $path);
    $resource = $parts[0] ?? '';
    $id = $parts[1] ?? null;

    if ($resource === 'v1') {
        // Externe API: eigene Authentifizierung (Bearer-Token), kein Session-/CSRF-Kontext
        qi_api_v1_dispatch(implode('/', array_slice($parts, 1)), $method);
    }

    if ($method !== 'GET' && $method !== 'HEAD' && $resource !== 'login') {
        // Alle schreibenden Anfragen benötigen ein gültiges CSRF-Token
        qi_require_csrf();
    }

    switch (true) {
        case $resource === '' && $method === 'GET':
            qi_json_response(['name' => 'quickinfo', 'version' => QI_VERSION]);

        case $resource === 'session' && $method === 'GET':
            qi_api_session();

        case $resource === 'login' && $method === 'POST':
            qi_api_login();

        case $resource === 'logout' && $method === 'POST':
            qi_require_auth();
            qi_logout();
            qi_json_response(['ok' => true]);

        case $resource === 'overview' && $method === 'GET':
            qi_require_auth();
            qi_api_overview();

        case $resource === 'history' && $method === 'GET':
            qi_require_auth();
            qi_api_history((string)($_GET['range'] ?? '1h'));

        case $resource === 'services' && $id === 'available' && $method === 'GET':
            qi_require_auth();
            qi_api_services_available();

        case $resource === 'services' && $id === null && $method === 'GET':
            qi_require_auth();
            qi_json_response(['services' => qi_services_list()]);

        case $resource === 'services' && $id === null && $method === 'POST':
            qi_require_auth();
            qi_api_service_create();

        case $resource === 'services' && $id !== null && $method === 'PUT':
            qi_require_auth();
            qi_api_service_update((int)$id);

        case $resource === 'services' && $id !== null && $method === 'DELETE':
            qi_require_auth();
            qi_api_service_delete((int)$id);

        case $resource === 'password' && $method === 'POST':
            $user = qi_require_auth();
            $body = qi_request_json();
            $result = qi_change_password($user['id'], (string)($body['current'] ?? ''), (string)($body['new'] ?? ''));
            qi_json_response($result, $result['ok'] ? 200 : 400);

        case $resource === 'apikey' && $id === null && $method === 'GET':
            qi_require_auth();
            qi_api_apikey_info();

        case $resource === 'apikey' && $id === 'rotate' && $method === 'POST':
            $user = qi_require_auth();
            qi_api_apikey_rotate($user['username']);

        case $resource === 'apikey' && $id === null && $method === 'DELETE':
            qi_require_auth();
            qi_api_key_revoke();
            qi_json_response(['ok' => true] + qi_api_apikey_payload());

        case $resource === 'system' && $method === 'POST':
            qi_require_auth();
            qi_api_system_update();

        case $resource === 'docker' && $id === 'config' && $method === 'GET':
            qi_require_auth();
            qi_json_response(qi_docker_config_public());

        case $resource === 'docker' && $id === 'config' && $method === 'PUT':
            qi_require_auth();
            qi_api_docker_save();

        case $resource === 'docker' && $id === 'status' && $method === 'GET':
            qi_require_auth();
            qi_json_response(['ok' => qi_docker_ping()]);

        case $resource === 'docker' && $id === 'containers' && $method === 'GET':
            qi_require_auth();
            qi_json_response(['containers' => qi_docker_containers()]);

        case $resource === 'docker' && $id === 'volumes' && $method === 'GET':
            qi_require_auth();
            qi_json_response(['volumes' => qi_docker_volumes()]);

        case $resource === 'docker' && $id === 'networks' && $method === 'GET':
            qi_require_auth();
            qi_json_response(['networks' => qi_docker_networks()]);

        case $resource === 'docker' && $id === 'folders' && $method === 'GET':
            qi_require_auth();
            qi_json_response(['folders' => qi_docker_folders()]);

        case $resource === 'docker' && $id === 'folders' && $method === 'POST':
            qi_require_auth();
            qi_api_docker_folder_create();

        case $resource === 'docker' && $id === 'folders' && ($parts[2] ?? '') === 'order' && $method === 'PUT':
            qi_require_auth();
            qi_api_docker_folder_order();

        case $resource === 'docker' && $id === 'folders' && ($parts[2] ?? '') !== '' && ($parts[3] ?? '') === 'containers' && $method === 'PUT':
            qi_require_auth();
            qi_api_docker_folder_set_containers((int)$parts[2]);

        case $resource === 'docker' && $id === 'folders' && ($parts[2] ?? '') !== '' && $method === 'PUT':
            qi_require_auth();
            qi_api_docker_folder_rename((int)$parts[2]);

        case $resource === 'docker' && $id === 'folders' && ($parts[2] ?? '') !== '' && $method === 'DELETE':
            qi_require_auth();
            qi_api_docker_folder_delete((int)$parts[2]);

        case $resource === 'docker' && $id === 'containers':
            qi_require_auth();
            qi_api_docker_container(array_slice($parts, 2), $method);
    }

    qi_json_error('Endpunkt nicht gefunden.', 404);
}

function qi_api_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function qi_api_apikey_payload(): array
{
    $cfg = qi_config()['api'];
    return [
        'key'          => qi_api_key_info(),
        'configured'   => qi_api_key_info() !== null,
        'base_url'     => qi_api_base_url(),
        'cors_origins' => array_values(array_filter((array)($cfg['cors_origins'] ?? []), 'is_string')),
        'endpoints'    => array_map(static fn(string $k) => '/api/v1/' . $k, array_keys(QI_API_V1_ENDPOINTS)),
    ];
}

function qi_api_apikey_info(): never
{
    qi_json_response(qi_api_apikey_payload());
}

function qi_api_apikey_rotate(string $username): never
{
    $key = qi_api_key_rotate($username);
    qi_json_response(['ok' => true, 'api_key' => $key] + qi_api_apikey_payload(), 201);
}

function qi_api_session(): never
{
    $user = qi_current_user();
    qi_json_response([
        'authenticated' => $user !== null,
        'user'          => $user ? $user['username'] : null,
        'csrf'          => qi_csrf_token(),
        'version'       => QI_VERSION,
        'system'        => qi_system_info(),
    ]);
}

/**
 * Liefert die öffentlich sichtbaren System-Informationen für die Anmeldeseite:
 * Hostname, Kurzbeschreibung, Inventarnummer und VM-Erkennung. Die Inventarnummer
 * ist ausschließlich auf Bare-Metal-Servern (keine virtuelle Maschine) relevant.
 */
function qi_system_info(): array
{
    $row = qi_db()->query("SELECT v FROM snapshot WHERE k = 'latest'")->fetch();
    $snapshot = $row ? json_decode((string)$row['v'], true) : null;
    $snapshot = is_array($snapshot) ? $snapshot : [];

    $isVm = array_key_exists('is_vm', $snapshot)
        ? (bool)$snapshot['is_vm']
        : qi_is_virtual_machine();

    return [
        'hostname'    => !empty($snapshot['hostname']) ? (string)$snapshot['hostname'] : php_uname('n'),
        'description' => (string)qi_meta_get('system_description', ''),
        'inventory'   => (string)qi_meta_get('system_inventory', ''),
        'is_vm'       => $isVm,
    ];
}

/**
 * Speichert Kurzbeschreibung und/oder Inventarnummer (POST /api/system).
 */
function qi_api_system_update(): never
{
    $body = qi_request_json();
    $isVm = qi_system_info()['is_vm'];
    $changed = false;

    if (array_key_exists('description', $body)) {
        $description = trim((string)$body['description']);
        if (mb_strlen($description) > 255) {
            qi_json_error('Kurzbeschreibung zu lang (max. 255 Zeichen).', 400);
        }
        qi_meta_set('system_description', $description);
        $changed = true;
    }
    if (array_key_exists('inventory', $body)) {
        $inventory = trim((string)$body['inventory']);
        if (mb_strlen($inventory) > 255) {
            qi_json_error('Inventarnummer zu lang (max. 255 Zeichen).', 400);
        }
        if ($inventory !== '' && $isVm) {
            qi_json_error('Auf virtuellen Maschinen kann keine Inventarnummer vergeben werden.', 400);
        }
        qi_meta_set('system_inventory', $inventory);
        $changed = true;
    }
    if (!$changed) {
        qi_json_error('Keine Änderungen übergeben.', 400);
    }

    qi_json_response(['ok' => true, 'system' => qi_system_info()]);
}

function qi_api_login(): never
{
    $body = qi_request_json();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
        qi_json_error('Benutzername und Passwort erforderlich.', 400);
    }
    $result = qi_login($username, $password);
    if (!$result['ok']) {
        $status = isset($result['retry_after']) ? 429 : 401;
        qi_json_response($result, $status);
    }
    qi_json_response(['ok' => true, 'user' => $result['user']['username'], 'csrf' => qi_csrf_token()]);
}

function qi_api_overview(): never
{
    $stmt = qi_db()->query("SELECT v, ts FROM snapshot WHERE k = 'latest'");
    $row = $stmt->fetch();
    $snapshot = $row ? json_decode((string)$row['v'], true) : null;

    qi_json_response([
        'now'       => time(),
        'snapshot'  => is_array($snapshot) ? $snapshot : null,
        'snapshot_ts' => $row ? (int)$row['ts'] : null,
        'services'  => qi_services_list(),
    ]);
}

function qi_services_list(): array
{
    $rows = qi_db()->query('SELECT id, name, display_name, sort_order, last_state, last_active, last_check FROM services ORDER BY sort_order, display_name')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['sort_order'] = (int)$r['sort_order'];
        $r['last_active'] = $r['last_active'] === null ? null : (bool)$r['last_active'];
        $r['last_check'] = $r['last_check'] === null ? null : (int)$r['last_check'];
    }
    unset($r);

    // Verfügbarkeit der letzten 24h aus dem Statusverlauf
    $since = time() - 86400;
    $stmt = qi_db()->prepare('SELECT service_id, AVG(active) AS uptime, COUNT(*) AS n FROM service_log WHERE ts >= ? GROUP BY service_id');
    $stmt->execute([$since]);
    $uptime = [];
    foreach ($stmt->fetchAll() as $u) {
        $uptime[(int)$u['service_id']] = round((float)$u['uptime'] * 100, 2);
    }
    foreach ($rows as &$r) {
        $r['uptime_24h'] = $uptime[$r['id']] ?? null;
    }
    unset($r);
    return $rows;
}

function qi_api_service_create(): never
{
    $body = qi_request_json();
    $name = qi_normalize_service_name((string)($body['name'] ?? ''));
    $display = trim((string)($body['display_name'] ?? ''));
    if ($display === '') {
        $display = $name;
    }
    if (!qi_valid_service_name($name)) {
        qi_json_error('Ungültiger Unit-Name. Erlaubt: Buchstaben, Ziffern, @ . _ : -', 400);
    }
    if (mb_strlen($display) > 128) {
        qi_json_error('Anzeigename zu lang.', 400);
    }
    $exists = qi_db()->prepare('SELECT id FROM services WHERE name = ?');
    $exists->execute([$name]);
    if ($exists->fetchColumn() !== false) {
        qi_json_error('Dieser Dienst wird bereits überwacht.', 409);
    }
    $maxOrder = (int)qi_db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM services')->fetchColumn();
    qi_db()->prepare('INSERT INTO services (name, display_name, sort_order, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$name, $display, $maxOrder + 10, time()]);
    $id = (int)qi_db()->lastInsertId();

    // Status sofort ermitteln, damit die Oberfläche nicht bis zum nächsten Lauf warten muss
    $state = qi_probe_service($name);
    qi_db()->prepare('UPDATE services SET last_state = ?, last_active = ?, last_check = ? WHERE id = ?')
        ->execute([$state['state'], $state['active'] ? 1 : 0, time(), $id]);

    qi_json_response(['ok' => true, 'services' => qi_services_list()], 201);
}

function qi_api_service_update(int $id): never
{
    $body = qi_request_json();
    $fields = [];
    $params = [];
    if (isset($body['display_name'])) {
        $display = trim((string)$body['display_name']);
        if ($display === '' || mb_strlen($display) > 128) {
            qi_json_error('Ungültiger Anzeigename.', 400);
        }
        $fields[] = 'display_name = ?';
        $params[] = $display;
    }
    if (isset($body['sort_order'])) {
        $fields[] = 'sort_order = ?';
        $params[] = (int)$body['sort_order'];
    }
    if (!$fields) {
        qi_json_error('Keine Änderungen übergeben.', 400);
    }
    $params[] = $id;
    $stmt = qi_db()->prepare('UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        $check = qi_db()->prepare('SELECT id FROM services WHERE id = ?');
        $check->execute([$id]);
        if ($check->fetchColumn() === false) {
            qi_json_error('Dienst nicht gefunden.', 404);
        }
    }
    qi_json_response(['ok' => true, 'services' => qi_services_list()]);
}

function qi_api_service_delete(int $id): never
{
    $stmt = qi_db()->prepare('DELETE FROM services WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        qi_json_error('Dienst nicht gefunden.', 404);
    }
    qi_json_response(['ok' => true, 'services' => qi_services_list()]);
}

function qi_api_services_available(): never
{
    $out = qi_exec(['systemctl', 'list-units', '--type=service', '--all', '--no-legend', '--plain', '--no-pager'], 8);
    $units = [];
    if ($out !== null) {
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = preg_split('/\s+/', $line, 5) ?: [];
            $unit = $cols[0] ?? '';
            if (!str_ends_with($unit, '.service')) {
                continue;
            }
            $name = substr($unit, 0, -8);
            if (!qi_valid_service_name($name)) {
                continue;
            }
            $units[] = [
                'name'        => $name,
                'active'      => $cols[2] ?? '',
                'sub'         => $cols[3] ?? '',
                'description' => $cols[4] ?? '',
            ];
        }
    }
    usort($units, static fn($a, $b) => strcmp($a['name'], $b['name']));
    qi_json_response(['units' => $units]);
}

/**
 * Ermittelt den Zustand eines systemd-Dienstes.
 * @return array{active:bool,state:string}
 */
function qi_probe_service(string $name): array
{
    $out = qi_exec(['systemctl', 'show', $name . '.service', '-p', 'LoadState', '-p', 'ActiveState', '-p', 'SubState'], 8);
    $props = [];
    foreach (preg_split('/\R/', (string)$out) ?: [] as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $props[trim($k)] = trim($v);
        }
    }
    $load = $props['LoadState'] ?? 'unknown';
    $active = $props['ActiveState'] ?? 'unknown';
    $sub = $props['SubState'] ?? '';

    if ($load === 'not-found') {
        return ['active' => false, 'state' => 'not-found'];
    }
    $state = $sub !== '' && $sub !== $active ? sprintf('%s (%s)', $active, $sub) : $active;
    return ['active' => $active === 'active', 'state' => $state];
}

function qi_api_history(string $range): never
{
    if (!isset(QI_RANGES[$range])) {
        qi_json_error('Ungültiger Zeitraum. Erlaubt: ' . implode(', ', array_keys(QI_RANGES)), 400);
    }
    qi_json_response(qi_history_build($range));
}

/**
 * Baut die aggregierten Zeitreihen für einen gültigen Zeitraum auf (gemeinsam genutzt
 * vom Web-Frontend und von /api/v1/history).
 */
function qi_history_build(string $range): array
{
    $spec = QI_RANGES[$range];
    $step = $spec['step'];
    $now = time();
    $to = (int)(floor($now / 60) * 60) + 60;
    $from = $to - $spec['seconds'];
    $from = (int)(floor($from / $step) * $step);

    $db = qi_db();
    $series = [];

    $rawRetentionFrom = $now - (int)qi_config()['retention']['raw_days'] * 86400;
    $aggUntil = (int)qi_meta_get('agg_until', '0');

    // 1) Verdichtete Daten (10-Minuten-Buckets), sofern der Zeitraum über die Rohdaten hinausreicht
    if ($from < $rawRetentionFrom || $step >= 600) {
        $aggEnd = min($to, $aggUntil);
        if ($aggEnd > $from) {
            $stmt = $db->prepare(
                'SELECT metric, FLOOR(ts / :step1) * :step2 AS bucket,
                        SUM(avg_value * samples) / SUM(samples) AS v
                   FROM metrics_agg
                  WHERE ts >= :from AND ts < :to
               GROUP BY metric, bucket
               ORDER BY metric, bucket'
            );
            $stmt->bindValue(':step1', $step, PDO::PARAM_INT);
            $stmt->bindValue(':step2', $step, PDO::PARAM_INT);
            $stmt->bindValue(':from', $from, PDO::PARAM_INT);
            $stmt->bindValue(':to', $aggEnd, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt as $row) {
                $series[$row['metric']][(int)$row['bucket']] = round((float)$row['v'], 3);
            }
        }
        $rawFrom = max($from, $aggEnd);
    } else {
        $rawFrom = $from;
    }

    // 2) Rohdaten (1-Minuten-Auflösung), per SQL auf den Ziel-Step gebündelt
    if ($rawFrom < $to) {
        $stmt = $db->prepare(
            'SELECT metric, FLOOR(ts / :step1) * :step2 AS bucket, AVG(value) AS v
               FROM metrics
              WHERE ts >= :from AND ts < :to
           GROUP BY metric, bucket
           ORDER BY metric, bucket'
        );
        $stmt->bindValue(':step1', $step, PDO::PARAM_INT);
        $stmt->bindValue(':step2', $step, PDO::PARAM_INT);
        $stmt->bindValue(':from', $rawFrom, PDO::PARAM_INT);
        $stmt->bindValue(':to', $to, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt as $row) {
            $series[$row['metric']][(int)$row['bucket']] = round((float)$row['v'], 3);
        }
    }

    // In sortierte [ts, value]-Listen umwandeln
    $out = [];
    foreach ($series as $metric => $points) {
        ksort($points);
        $list = [];
        foreach ($points as $ts => $v) {
            $list[] = [$ts, $v];
        }
        $out[$metric] = $list;
    }

    return [
        'range'  => $range,
        'from'   => $from,
        'to'     => $to,
        'step'   => $step,
        'now'    => $now,
        'series' => $out,
    ];
}

/**
 * Speichert die Docker-Host-Konfiguration. Erwartet JSON-Body.
 */
function qi_api_docker_save(): never
{
    $body = qi_request_json();
    $host = trim((string)($body['host'] ?? ''));
    $port = (int)($body['port'] ?? 22);
    $username = trim((string)($body['username'] ?? ''));

    if (($body['enabled'] ?? false) && ($host === '' || $username === '')) {
        qi_json_error('Host und Benutzername sind erforderlich, wenn Docker aktiviert ist.', 400);
    }
    if ($port < 1 || $port > 65535) {
        qi_json_error('Ungültiger SSH-Port.', 400);
    }
    $authType = (string)($body['auth_type'] ?? 'password');
    if (!in_array($authType, ['password', 'key'], true)) {
        qi_json_error('Ungültiger Authentifizierungstyp.', 400);
    }

    try {
        qi_docker_save($body);
    } catch (Throwable $e) {
        qi_json_error('Speichern fehlgeschlagen: ' . $e->getMessage(), 500);
    }

    // Bei aktiviertem Host Verbindung prüfen (nicht-blockierend fürs Speichern).
    $ping = false;
    if (($body['enabled'] ?? false)) {
        $ping = qi_docker_ping();
    }

    qi_json_response(['ok' => true, 'status' => $ping ? 'ok' : 'unreachable'] + qi_docker_config_public());
}

/**
 * Legt einen Docker-Ordner an (POST /api/docker/folders).
 */
function qi_api_docker_folder_create(): never
{
    $body = qi_request_json();
    $name = (string)($body['name'] ?? '');
    try {
        $folder = qi_docker_folder_create($name);
    } catch (Throwable $e) {
        qi_json_error($e->getMessage(), 400);
    }
    qi_json_response(['folder' => $folder], 201);
}

/**
 * Benennt einen Docker-Ordner um (PUT /api/docker/folders/{id}).
 */
function qi_api_docker_folder_rename(int $id): never
{
    $body = qi_request_json();
    $name = (string)($body['name'] ?? '');
    try {
        qi_docker_folder_rename($id, $name);
    } catch (Throwable $e) {
        qi_json_error($e->getMessage(), 400);
    }
    qi_json_response(['ok' => true]);
}

/**
 * Löscht einen Docker-Ordner (DELETE /api/docker/folders/{id}).
 */
function qi_api_docker_folder_delete(int $id): never
{
    try {
        qi_docker_folder_delete($id);
    } catch (Throwable $e) {
        qi_json_error($e->getMessage(), 400);
    }
    qi_json_response(['ok' => true]);
}

/**
 * Setzt die Reihenfolge der Docker-Ordner (PUT /api/docker/folders/order).
 * Body: {"ids": [3, 1, 2]}
 */
function qi_api_docker_folder_order(): never
{
    $body = qi_request_json();
    $ids = $body['ids'] ?? [];
    if (!is_array($ids)) {
        qi_json_error('Ungültige Reihenfolge.', 400);
    }
    try {
        qi_docker_folder_set_order(array_values($ids));
    } catch (Throwable $e) {
        qi_json_error($e->getMessage(), 400);
    }
    qi_json_response(['ok' => true]);
}

/**
 * Setzt die Container eines Ordners (PUT /api/docker/folders/{id}/containers).
 * Body: {"containers": ["name-a", "name-b"]}
 */
function qi_api_docker_folder_set_containers(int $id): never
{
    $body = qi_request_json();
    $containers = $body['containers'] ?? [];
    if (!is_array($containers)) {
        qi_json_error('Ungültige Container-Liste.', 400);
    }
    try {
        qi_docker_folder_set_containers($id, array_map('strval', array_values($containers)));
    } catch (Throwable $e) {
        qi_json_error($e->getMessage(), 400);
    }
    qi_json_response(['ok' => true]);
}

/**
 * Verteilt Container-Sub-Routen:
 *   [name]                    GET  → Detail + Notiz
 *   [name]/stats              GET  → Live-Auslastung
 *   [name]/logs               GET  → letzte Logzeilen
 *   [name]/note               GET/PUT
 *   [name]/folder             PUT  → Ordnerzuordnung {folder_id|null}
 *   [name]/start|stop|restart POST
 *
 * @param array<int,string> $sub
 */
function qi_api_docker_container(array $sub, string $method): never
{
    $ref = (string)($sub[0] ?? '');
    $action = (string)($sub[1] ?? '');

    if ($ref === '') {
        qi_json_error('Container-Name fehlt.', 400);
    }

    if ($method === 'GET' && $action === '') {
        $detail = qi_docker_inspect($ref);
        if ($detail === null) {
            qi_json_error('Container nicht gefunden.', 404);
        }
        $name = ltrim((string)($detail['name'] ?? $ref), '/');
        $note = qi_docker_note_row($name);
        $detail['note'] = $note['note'];
        $detail['note_updated_at'] = $note['updated_at'];
        qi_json_response($detail);
    }

    if ($method === 'GET' && $action === 'stats') {
        $stats = qi_docker_stats($ref);
        if ($stats === null) {
            qi_json_error('Keine Statistik verfügbar.', 404);
        }
        qi_json_response($stats);
    }

    if ($method === 'GET' && $action === 'logs') {
        $lines = (int)($_GET['lines'] ?? 200);
        qi_json_response(['logs' => qi_docker_logs($ref, $lines)]);
    }

    if ($method === 'GET' && $action === 'note') {
        qi_json_response(['note' => qi_docker_note_get($ref)]);
    }

    if ($method === 'PUT' && $action === 'note') {
        $body = qi_request_json();
        $note = (string)($body['note'] ?? '');
        qi_docker_note_set($ref, $note);
        qi_json_response(['ok' => true, 'note' => $note]);
    }

    if ($method === 'PUT' && $action === 'folder') {
        $body = qi_request_json();
        $folderId = $body['folder_id'] ?? null;
        $folderId = $folderId === null ? null : (int)$folderId;
        try {
            qi_docker_container_set_folder(ltrim($ref, '/'), $folderId);
        } catch (Throwable $e) {
            qi_json_error($e->getMessage(), 400);
        }
        qi_json_response(['ok' => true]);
    }

    if ($method === 'POST' && in_array($action, ['start', 'stop', 'restart'], true)) {
        qi_docker_action($ref, $action);
        qi_json_response(['ok' => true]);
    }

    qi_json_error('Endpunkt nicht gefunden.', 404);
}
