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
 *  /api/v1/*                        Öffentliche Read-Only-API (Bearer-Token) → src/api_v1.php
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
