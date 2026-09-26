<?php
/**
 * Docker-Host-Modul.
 *
 * Verwaltet die (verschlüsselten) SSH-Zugangsdaten für einen Docker-Host,
 * führt Docker-Kommandos remote per SSH aus und stellt Hilfsfunktionen für
 * Container, Volumes, Netzwerke, Logs und Container-Notizen bereit.
 */

declare(strict_types=1);

/**
 * Liefert die rohe docker_host-Zeile (id = 1) oder null.
 *
 * @return array<string,mixed>|null
 */
function qi_docker_row(): ?array
{
    $stmt = qi_db()->prepare('SELECT * FROM docker_host WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Verschlüsselt Klartext mit AES-256-GCM. Das Ergebnis ist base64(iv|ciphertext|tag).
 */
function qi_docker_encrypt(string $plaintext): string
{
    $key = qi_docker_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Docker: Verschlüsselung fehlgeschlagen.');
    }
    return base64_encode($iv . $tag . $cipher);
}

/**
 * Entschlüsselt einen mit qi_docker_encrypt() erzeugten Wert.
 */
function qi_docker_decrypt(string $payload): ?string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = qi_docker_key();
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/**
 * Leitet den 32-Byte-Verschlüsselungsschlüssel aus der Konfiguration ab.
 * Ohne konfigurierten Schlüssel wird eine stabile Fallback-Ableitung verwendet,
 * damit bestehende Daten nicht unlesbar werden.
 */
function qi_docker_key(): string
{
    $cfg = qi_config();
    $secret = $cfg['docker']['encryption_key'] ?? '';
    if (is_string($secret) && $secret !== '') {
        return hash('sha256', $secret, true);
    }
    // Fallback: deterministisch aus Installationspfad abgeleitet.
    $base = $cfg['base_path'] ?? dirname(__DIR__);
    return hash('sha256', 'quickinfo-docker:' . $base, true);
}

/**
 * Öffentliche Konfiguration (ohne Secrets) für das Frontend.
 *
 * @return array<string,mixed>
 */
function qi_docker_config_public(): array
{
    $row = qi_docker_row();
    return [
        'enabled' => (bool)($row['enabled'] ?? false),
        'host' => (string)($row['host'] ?? ''),
        'port' => (int)($row['port'] ?? 22),
        'username' => (string)($row['username'] ?? ''),
        'auth_type' => (string)($row['auth_type'] ?? 'password'),
        'has_password' => !empty($row['password_enc']),
        'has_private_key' => !empty($row['private_key_enc']),
        'ssh_available' => qi_command_exists('ssh'),
        'docker_cli_available' => qi_command_exists('docker'),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

/**
 * Gibt die entschlüsselten Zugangsdaten zurück oder null, wenn keine vollständig
 * hinterlegt sind.
 *
 * @return array{host:string,port:int,username:string,auth_type:string,password:string,private_key:string}|null
 */
function qi_docker_credentials(): ?array
{
    $row = qi_docker_row();
    if (!$row || empty($row['enabled']) || $row['host'] === '' || $row['username'] === '') {
        return null;
    }
    $password = '';
    $key = '';
    if ($row['auth_type'] === 'key') {
        if (empty($row['private_key_enc'])) {
            return null;
        }
        $key = (string)qi_docker_decrypt($row['private_key_enc']);
    } else {
        if (empty($row['password_enc'])) {
            return null;
        }
        $password = (string)qi_docker_decrypt($row['password_enc']);
    }
    return [
        'host' => (string)$row['host'],
        'port' => (int)$row['port'],
        'username' => (string)$row['username'],
        'auth_type' => (string)$row['auth_type'],
        'password' => $password,
        'private_key' => $key,
    ];
}

/**
 * Führt ein Docker-Kommando remote per SSH aus und liefert Stdout als String.
 * Wirft RuntimeException bei Fehlern (inkl. SSH/Docker-Exitcode).
 *
 * @param bool $returnResult true liefert ['out' => ..., 'err' => ..., 'exit' => ...]
 * @return string|array<string,mixed>
 */
function qi_docker_run(string $dockerArgs, int $timeout = 30, bool $returnResult = false)
{
    $cred = qi_docker_credentials();
    if ($cred === null) {
        throw new RuntimeException('Docker-Host ist nicht konfiguriert.');
    }

    $argv = ['ssh'];
    $argv[] = '-o'; $argv[] = 'BatchMode=yes';
    $argv[] = '-o'; $argv[] = 'StrictHostKeyChecking=accept-new';
    $argv[] = '-o'; $argv[] = 'ConnectTimeout=10';
    $argv[] = '-p'; $argv[] = (string)$cred['port'];

    $tmp = null;
    $sshpass = false;
    if ($cred['auth_type'] === 'key') {
        $tmp = tempnam(sys_get_temp_dir(), 'qi-key-');
        if ($tmp === false) {
            throw new RuntimeException('Temporäre Datei für SSH-Key konnte nicht erstellt werden.');
        }
        file_put_contents($tmp, $cred['private_key']);
        chmod($tmp, 0600);
        $argv[] = '-i'; $argv[] = $tmp;
    } else {
        if (!qi_command_exists('sshpass')) {
            throw new RuntimeException('Passwort-Authentifizierung erfordert das Paket "sshpass".');
        }
        $sshpass = true;
        putenv('SSHPASS=' . $cred['password']);
    }

    $argv[] = $cred['username'] . '@' . $cred['host'];
    $argv[] = 'docker ' . $dockerArgs;

    $cmdline = implode(' ', array_map('escapeshellarg', $argv));
    if ($sshpass) {
        $cmdline = 'sshpass -e ' . $cmdline;
    }

    try {
        $res = qi_docker_proc($cmdline, $timeout);
    } finally {
        if ($sshpass) {
            putenv('SSHPASS');
        }
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp);
        }
    }

    if ($returnResult) {
        return $res;
    }
    if ($res['exit'] !== 0) {
        $msg = trim($res['err'] !== '' ? $res['err'] : $res['out']);
        throw new RuntimeException($msg !== '' ? $msg : 'Docker-Kommando fehlgeschlagen (Exit ' . $res['exit'] . ').');
    }
    return $res['out'];
}

/**
 * Führt eine Kommandozeile aus und liefert stdout, stderr und Exit-Code.
 *
 * @return array{out:string,err:string,exit:int}
 */
function qi_docker_proc(string $cmdline, int $timeout): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmdline, $descriptors, $pipes, null, [
        'LC_ALL' => 'C',
        'PATH'   => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    ]);
    if (!is_resource($proc)) {
        return ['out' => '', 'err' => 'Kommando konnte nicht gestartet werden.', 'exit' => 127];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $deadline = microtime(true) + $timeout;
    $status = proc_get_status($proc);
    while ($status['running']) {
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return ['out' => $out, 'err' => $err !== '' ? $err : 'Zeitüberschreitung.', 'exit' => 124];
        }
        usleep(20000);
        $status = proc_get_status($proc);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return ['out' => $out, 'err' => $err, 'exit' => (int)($status['exitcode'] ?? 0)];
}

/**
 * Liefert true, wenn der Docker-Host erreichbar und der Daemon ansprechbar ist.
 */
function qi_docker_ping(): bool
{
    try {
        qi_docker_run('version --format "{{json .Server.Version}}"', 20);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Passt Docker-Konfiguration an und speichert sie (Secrets nur bei Bedarf neu verschlüsseln).
 *
 * @param array<string,mixed> $in
 */
function qi_docker_save(array $in): void
{
    $db = qi_db();
    $row = qi_docker_row();

    $enabled = (bool)($in['enabled'] ?? false);
    $host = trim((string)($in['host'] ?? ''));
    $port = (int)($in['port'] ?? 22);
    $username = trim((string)($in['username'] ?? ''));
    $authType = (string)($in['auth_type'] ?? 'password');
    if (!in_array($authType, ['password', 'key'], true)) {
        $authType = 'password';
    }

    $passwordEnc = isset($row['password_enc']) ? $row['password_enc'] : null;
    $keyEnc = isset($row['private_key_enc']) ? $row['private_key_enc'] : null;

    if (isset($in['password']) && is_string($in['password']) && $in['password'] !== '') {
        $passwordEnc = qi_docker_encrypt($in['password']);
    }
    if (isset($in['private_key']) && is_string($in['private_key']) && trim($in['private_key']) !== '') {
        $keyEnc = qi_docker_encrypt($in['private_key']);
    }

    $db->prepare(
        'INSERT INTO docker_host
            (id, enabled, host, port, username, auth_type, password_enc, private_key_enc, updated_at)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled), host = VALUES(host), port = VALUES(port),
            username = VALUES(username), auth_type = VALUES(auth_type),
            password_enc = VALUES(password_enc), private_key_enc = VALUES(private_key_enc),
            updated_at = VALUES(updated_at)'
    )->execute([
        $enabled ? 1 : 0,
        $host,
        $port,
        $username,
        $authType,
        $passwordEnc,
        $keyEnc,
        time(),
    ]);
}

/**
 * Listet Container (nur solche mit Namen) als normalisierte Datensätze.
 *
 * @return array<int,array<string,mixed>>
 */
function qi_docker_containers(): array
{
    $fmt = implode('|', [
        '{{json .ID}}',
        '{{json .Names}}',
        '{{json .Image}}',
        '{{json .State}}',
        '{{json .Status}}',
        '{{json .Ports}}',
    ]);
    $out = qi_docker_run('ps -a --format "' . $fmt . '"', 30);
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = explode('|', $line);
        if (count($cols) < 6) {
            continue;
        }
        $id = trim((string)json_decode($cols[0], true) ?: '');
        $name = trim((string)json_decode($cols[1], true) ?: '');
        $image = trim((string)json_decode($cols[2], true) ?: '');
        $state = trim((string)json_decode($cols[3], true) ?: '');
        $status = trim((string)json_decode($cols[4], true) ?: '');
        $ports = trim((string)json_decode($cols[5], true) ?: '');
        if ($name === '') {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'name' => $name,
            'image' => $image,
            'state' => $state,
            'status' => $status,
            'ports' => $ports,
        ];
    }
    return $rows;
}

/**
 * Liefert Detailinformationen eines Containers (per Name oder ID).
 *
 * @return array<string,mixed>|null
 */
function qi_docker_inspect(string $ref): ?array
{
    $out = qi_docker_run('inspect ' . escapeshellarg($ref), 30);
    $data = json_decode($out, true);
    if (!is_array($data) || !isset($data[0])) {
        return null;
    }
    $inspect = $data[0];
    $state = $inspect['State'] ?? [];
    $config = $inspect['Config'] ?? [];
    $hostConfig = $inspect['HostConfig'] ?? [];
    $netSettings = $inspect['NetworkSettings'] ?? [];
    $labels = $config['Labels'] ?? [];

    $mounts = [];
    foreach ($inspect['Mounts'] ?? [] as $m) {
        $mounts[] = [
            'type' => $m['Type'] ?? '',
            'name' => $m['Name'] ?? '',
            'source' => $m['Source'] ?? '',
            'destination' => $m['Destination'] ?? '',
            'mode' => $m['Mode'] ?? '',
            'rw' => (bool)($m['RW'] ?? true),
        ];
    }

    $networks = [];
    foreach ($netSettings['Networks'] ?? [] as $n => $cfg) {
        $networks[] = [
            'name' => $n,
            'ip' => $cfg['IPAddress'] ?? '',
            'gateway' => $cfg['Gateway'] ?? '',
            'mac' => $cfg['MacAddress'] ?? '',
            'aliases' => $cfg['Aliases'] ?? [],
        ];
    }

    $ports = [];
    foreach ($netSettings['Ports'] ?? [] as $containerPort => $bindings) {
        if (is_array($bindings)) {
            foreach ($bindings as $b) {
                $ports[] = [
                    'container' => (string)$containerPort,
                    'host_ip' => (string)($b['HostIp'] ?? ''),
                    'host_port' => (string)($b['HostPort'] ?? ''),
                ];
            }
        }
    }

    return [
        'id' => $inspect['Id'] ?? '',
        'name' => $inspect['Name'] ?? '',
        'image' => $config['Image'] ?? '',
        'command' => is_array($config['Cmd'] ?? null) ? implode(' ', $config['Cmd']) : (string)($config['Cmd'] ?? ''),
        'created' => $inspect['Created'] ?? '',
        'running' => (bool)($state['Running'] ?? false),
        'status' => (string)($state['Status'] ?? ''),
        'restart_policy' => $hostConfig['RestartPolicy']['Name'] ?? '',
        'labels' => $labels,
        'compose_project' => $labels['com.docker.compose.project'] ?? null,
        'compose_service' => $labels['com.docker.compose.service'] ?? null,
        'mounts' => $mounts,
        'networks' => $networks,
        'ports' => $ports,
    ];
}

/**
 * Liefert Live-Ressourcen-Auslastung eines Containers.
 *
 * @return array<string,mixed>|null
 */
function qi_docker_stats(string $ref): ?array
{
    $out = qi_docker_run('stats --no-stream --format "{{json .}}" ' . escapeshellarg($ref), 30);
    $line = trim(explode("\n", $out)[0] ?? '');
    if ($line === '') {
        return null;
    }
    $s = json_decode($line, true);
    if (!is_array($s)) {
        return null;
    }
    return [
        'name' => $s['Name'] ?? '',
        'cpu' => (string)($s['CPUPerc'] ?? ''),
        'memory' => (string)($s['MemUsage'] ?? ''),
        'memory_percent' => (string)($s['MemPerc'] ?? ''),
        'network_io' => (string)($s['NetIO'] ?? ''),
        'block_io' => (string)($s['BlockIO'] ?? ''),
        'pids' => (string)($s['PIDs'] ?? ''),
    ];
}

/**
 * Startet/stoppt/startet neu einen Container. Liefert true bei Erfolg.
 */
function qi_docker_action(string $ref, string $action): bool
{
    if (!in_array($action, ['start', 'stop', 'restart'], true)) {
        throw new RuntimeException('Unbekannte Aktion.');
    }
    qi_docker_run($action . ' ' . escapeshellarg($ref), 60);
    return true;
}

/**
 * Liefert die letzten Logzeilen eines Containers.
 */
function qi_docker_logs(string $ref, int $lines = 200): array
{
    $n = max(1, min($lines, 1000));
    $out = qi_docker_run('logs --tail ' . $n . ' --timestamps ' . escapeshellarg($ref), 30);
    $result = [];
    foreach (explode("\n", $out) as $line) {
        if ($line !== '') {
            $result[] = $line;
        }
    }
    return $result;
}

/**
 * Listet Docker-Volumes (Name, Treiber, Mountpoint).
 *
 * @return array<int,array<string,mixed>>
 */
function qi_docker_volumes(): array
{
    $out = qi_docker_run('volume ls --format "{{json .Name}}|{{json .Driver}}|{{json .Mountpoint}}"', 30);
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = explode('|', $line);
        if (count($cols) < 3) {
            continue;
        }
        $rows[] = [
            'name' => trim((string)json_decode($cols[0], true) ?: ''),
            'driver' => trim((string)json_decode($cols[1], true) ?: ''),
            'mountpoint' => trim((string)json_decode($cols[2], true) ?: ''),
        ];
    }
    return $rows;
}

/**
 * Listet Docker-Netzwerke (Name, Treiber, Subnetze).
 *
 * @return array<int,array<string,mixed>>
 */
function qi_docker_networks(): array
{
    $out = qi_docker_run('network ls --format "{{json .Name}}|{{json .Driver}}"', 30);
    $names = [];
    $drivers = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = explode('|', $line);
        $name = trim((string)json_decode($cols[0], true) ?: '');
        $driver = trim((string)json_decode($cols[1], true) ?: '');
        if ($name !== '') {
            $names[] = $name;
            $drivers[$name] = $driver;
        }
    }
    $rows = [];
    foreach ($names as $name) {
        $subnets = [];
        try {
            $info = json_decode(qi_docker_run('network inspect ' . escapeshellarg($name), 30), true);
            if (is_array($info) && isset($info[0]['IPAM']['Config'])) {
                foreach ($info[0]['IPAM']['Config'] as $c) {
                    $subnets[] = ($c['Subnet'] ?? '') . (($c['Gateway'] ?? '') !== '' ? ' (gw ' . $c['Gateway'] . ')' : '');
                }
            }
        } catch (Throwable $e) {
            $subnets = [];
        }
        $rows[] = [
            'name' => $name,
            'driver' => $drivers[$name] ?? '',
            'subnets' => $subnets,
        ];
    }
    return $rows;
}

/**
 * Liefert die Notiz eines Containers (per Name) oder null.
 */
function qi_docker_note_get(string $name): ?string
{
    $stmt = qi_db()->prepare('SELECT note FROM docker_container_notes WHERE container_name = ?');
    $stmt->execute([$name]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

/**
 * Liefert Notiz + Änderungszeitpunkt eines Containers (per Name).
 *
 * @return array{note:?string,updated_at:?int}
 */
function qi_docker_note_row(string $name): array
{
    $stmt = qi_db()->prepare('SELECT note, updated_at FROM docker_container_notes WHERE container_name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['note' => null, 'updated_at' => null];
    }
    return ['note' => $row['note'] ?? null, 'updated_at' => (int)($row['updated_at'] ?? 0)];
}

/**
 * Speichert die Notiz eines Containers (per Name).
 */
function qi_docker_note_set(string $name, string $note): void
{
    qi_db()->prepare(
        'INSERT INTO docker_container_notes (container_name, note, updated_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = VALUES(updated_at)'
    )->execute([$name, $note, time()]);
}

/**
 * Normalisiert einen Ordnernamen (trimmen, Steuerzeichen entfernen, Länge begrenzen).
 */
function qi_docker_folder_normalize(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 128);
    }
    return substr($name, 0, 128);
}

/**
 * Liefert einen einzelnen Ordner als Datensatz oder null.
 *
 * @return array{id:int,name:string,sort_order:int}|null
 */
function qi_docker_folder_row(int $id): ?array
{
    $stmt = qi_db()->prepare('SELECT id, name, sort_order FROM docker_folders WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'sort_order' => (int)$row['sort_order'],
    ];
}

/**
 * Listet alle Ordner inkl. der ihnen zugeordneten Container (in Reihenfolge).
 *
 * @return array<int,array{id:int,name:string,sort_order:int,containers:array<int,string>}>
 */
function qi_docker_folders(): array
{
    $db = qi_db();
    $folders = [];
    $stmt = $db->query('SELECT id, name, sort_order FROM docker_folders ORDER BY sort_order ASC, id ASC');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $folders[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sort_order' => (int)$row['sort_order'],
            'containers' => [],
        ];
    }
    $stmt = $db->query(
        'SELECT container_name, folder_id FROM docker_container_folders
         WHERE folder_id IS NOT NULL ORDER BY sort_order ASC, container_name ASC'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fid = (int)$row['folder_id'];
        if (isset($folders[$fid])) {
            $folders[$fid]['containers'][] = (string)$row['container_name'];
        }
    }
    return array_values($folders);
}

/**
 * Legt einen neuen Ordner an und liefert den Datensatz.
 *
 * @return array{id:int,name:string,sort_order:int}
 */
function qi_docker_folder_create(string $name): array
{
    $name = qi_docker_folder_normalize($name);
    if ($name === '') {
        throw new RuntimeException('Ordnername darf nicht leer sein.');
    }
    $db = qi_db();
    $chk = $db->prepare('SELECT id FROM docker_folders WHERE name = ?');
    $chk->execute([$name]);
    if ($chk->fetchColumn() !== false) {
        throw new RuntimeException('Ein Ordner mit diesem Namen existiert bereits.');
    }
    $max = (int)$db->query('SELECT COALESCE(MAX(sort_order), -1) FROM docker_folders')->fetchColumn();
    $db->prepare('INSERT INTO docker_folders (name, sort_order, created_at) VALUES (?, ?, ?)')
       ->execute([$name, $max + 1, time()]);
    $row = qi_docker_folder_row((int)$db->lastInsertId());
    if ($row === null) {
        throw new RuntimeException('Ordner konnte nicht angelegt werden.');
    }
    return $row;
}

/**
 * Benennt einen Ordner um.
 */
function qi_docker_folder_rename(int $id, string $name): void
{
    $name = qi_docker_folder_normalize($name);
    if ($name === '') {
        throw new RuntimeException('Ordnername darf nicht leer sein.');
    }
    $db = qi_db();
    $chk = $db->prepare('SELECT id FROM docker_folders WHERE id = ?');
    $chk->execute([$id]);
    if ($chk->fetchColumn() === false) {
        throw new RuntimeException('Ordner nicht gefunden.');
    }
    $dup = $db->prepare('SELECT id FROM docker_folders WHERE name = ? AND id <> ?');
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn() !== false) {
        throw new RuntimeException('Ein Ordner mit diesem Namen existiert bereits.');
    }
    $db->prepare('UPDATE docker_folders SET name = ? WHERE id = ?')->execute([$name, $id]);
}

/**
 * Löscht einen Ordner. Die enthaltenen Container werden automatisch freigegeben
 * (FK ON DELETE SET NULL), bleiben aber selbst unangetastet.
 */
function qi_docker_folder_delete(int $id): void
{
    qi_db()->prepare('DELETE FROM docker_folders WHERE id = ?')->execute([$id]);
}

/**
 * Setzt die Reihenfolge der Ordner. Erwartet die vollständige, sortierte ID-Liste.
 *
 * @param array<int,mixed> $ids
 */
function qi_docker_folder_set_order(array $ids): void
{
    $db = qi_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE docker_folders SET sort_order = ? WHERE id = ?');
        foreach ($ids as $i => $id) {
            $stmt->execute([$i, (int)$id]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Setzt die vollständige, sortierte Container-Liste eines Ordners.
 * Alle genannten Container werden dem Ordner zugeordnet (Reihenfolge = Listenindex);
 * Container, die dem Ordner zugeordnet waren, aber nicht mehr genannt werden,
 * werden freigegeben.
 *
 * @param array<int,string> $names
 */
function qi_docker_folder_set_containers(int $folderId, array $names): void
{
    $db = qi_db();
    $chk = $db->prepare('SELECT id FROM docker_folders WHERE id = ?');
    $chk->execute([$folderId]);
    if ($chk->fetchColumn() === false) {
        throw new RuntimeException('Ordner nicht gefunden.');
    }

    $ordered = [];
    $seen = [];
    foreach ($names as $n) {
        $n = (string)$n;
        if ($n === '' || isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $ordered[] = $n;
    }

    $db->beginTransaction();
    try {
        $upsert = $db->prepare(
            'INSERT INTO docker_container_folders (container_name, folder_id, sort_order) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id), sort_order = VALUES(sort_order)'
        );
        foreach ($ordered as $i => $n) {
            $upsert->execute([$n, $folderId, $i]);
        }
        if ($ordered === []) {
            $db->prepare('UPDATE docker_container_folders SET folder_id = NULL, sort_order = 0 WHERE folder_id = ?')
               ->execute([$folderId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($ordered), '?'));
            $db->prepare(
                'UPDATE docker_container_folders SET folder_id = NULL, sort_order = 0
                 WHERE folder_id = ? AND container_name NOT IN (' . $placeholders . ')'
            )->execute(array_merge([$folderId], $ordered));
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Ordnet einen Container einem Ordner zu (oder gibt ihn frei, wenn $folderId null ist).
 */
function qi_docker_container_set_folder(string $name, ?int $folderId): void
{
    $db = qi_db();
    if ($folderId === null) {
        $db->prepare(
            'INSERT INTO docker_container_folders (container_name, folder_id, sort_order) VALUES (?, NULL, 0)
             ON DUPLICATE KEY UPDATE folder_id = NULL, sort_order = 0'
        )->execute([$name]);
        return;
    }
    $chk = $db->prepare('SELECT id FROM docker_folders WHERE id = ?');
    $chk->execute([$folderId]);
    if ($chk->fetchColumn() === false) {
        throw new RuntimeException('Ordner nicht gefunden.');
    }
    $max = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM docker_container_folders WHERE folder_id = ?');
    $max->execute([$folderId]);
    $next = (int)$max->fetchColumn() + 1;
    $db->prepare(
        'INSERT INTO docker_container_folders (container_name, folder_id, sort_order) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id), sort_order = VALUES(sort_order)'
    )->execute([$name, $folderId, $next]);
}
