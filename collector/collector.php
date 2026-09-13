#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * quickinfo – Data Collector (CLI).
 *
 * Wird jede Minute per systemd-Timer ausgeführt. Liest CPU-Auslastung (/proc/stat),
 * Temperaturen (hwmon / sensors / thermal_zone), NVIDIA-GPU (nvidia-smi), Speicherplatz (df)
 * und systemd-Dienststatus aus und schreibt die Werte in MySQL.
 * Einmal pro Stunde werden alte Rohdaten verdichtet und bereinigt.
 *
 * Aufruf:  php collector.php [--verbose] [--maintenance]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur über die Kommandozeile ausführbar.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/api.php'; // für qi_probe_service()

$opts = getopt('', ['verbose', 'maintenance']);
$verbose = isset($opts['verbose']);
$forceMaintenance = isset($opts['maintenance']);

function qi_log(string $msg): void
{
    global $verbose;
    if ($verbose) {
        fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $msg . "\n");
    }
}

// Verhindern, dass zwei Läufe gleichzeitig arbeiten
$lockFile = sys_get_temp_dir() . '/quickinfo-collector.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Collector läuft bereits.\n");
    exit(0);
}

$cfg = qi_config();
$ccfg = $cfg['collector'];
$ts = (int)(floor(time() / 60) * 60);
$metrics = [];   // metric => value
$snapshot = ['ts' => $ts, 'hostname' => php_uname('n')];

// ---------------------------------------------------------------------------
// CPU-Auslastung (Gesamt & pro Kern) über zwei /proc/stat-Samples
// ---------------------------------------------------------------------------
function qi_read_proc_stat(): array
{
    $result = [];
    $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (!str_starts_with($line, 'cpu')) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        $name = array_shift($parts);
        $vals = array_map('intval', $parts);
        // user nice system idle iowait irq softirq steal
        $idle = ($vals[3] ?? 0) + ($vals[4] ?? 0);
        $total = array_sum(array_slice($vals, 0, 8));
        $result[$name] = ['idle' => $idle, 'total' => $total];
    }
    return $result;
}

$s1 = qi_read_proc_stat();
usleep(max(200, (int)$ccfg['cpu_sample_ms']) * 1000);
$s2 = qi_read_proc_stat();

$cores = [];
foreach ($s2 as $name => $b) {
    if (!isset($s1[$name])) {
        continue;
    }
    $a = $s1[$name];
    $dTotal = $b['total'] - $a['total'];
    $dIdle = $b['idle'] - $a['idle'];
    $usage = $dTotal > 0 ? max(0.0, min(100.0, (1 - $dIdle / $dTotal) * 100)) : 0.0;
    $usage = round($usage, 2);
    if ($name === 'cpu') {
        $metrics['cpu.total'] = $usage;
        $snapshot['cpu']['total'] = $usage;
    } else {
        $idx = (int)substr($name, 3);
        $metrics['cpu.core.' . $idx] = $usage;
        $cores[$idx] = $usage;
    }
}
ksort($cores);
$snapshot['cpu']['cores'] = array_values($cores);
$snapshot['cpu']['count'] = count($cores);

$model = 'CPU';
foreach (@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^(model name|Hardware|Model)\s*:\s*(.+)$/i', $line, $m)) {
        $model = trim($m[2]);
        break;
    }
}
$snapshot['cpu']['model'] = $model;

// Load & Uptime & RAM (für die Kopfzeile)
$load = @file_get_contents('/proc/loadavg');
if ($load) {
    $l = preg_split('/\s+/', trim($load)) ?: [];
    $snapshot['load'] = [(float)($l[0] ?? 0), (float)($l[1] ?? 0), (float)($l[2] ?? 0)];
    $metrics['sys.load1'] = (float)($l[0] ?? 0);
}
$up = @file_get_contents('/proc/uptime');
if ($up) {
    $snapshot['uptime'] = (int)explode(' ', trim($up))[0];
}
$memInfo = [];
foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
        $memInfo[$m[1]] = (int)$m[2] * 1024;
    }
}
if (isset($memInfo['MemTotal'], $memInfo['MemAvailable'])) {
    $memUsed = $memInfo['MemTotal'] - $memInfo['MemAvailable'];
    $snapshot['memory'] = [
        'total' => $memInfo['MemTotal'],
        'used'  => $memUsed,
        'pct'   => round($memUsed / max(1, $memInfo['MemTotal']) * 100, 2),
    ];
    $metrics['mem.used_pct'] = $snapshot['memory']['pct'];
}

// ---------------------------------------------------------------------------
// Temperaturen
// ---------------------------------------------------------------------------
function qi_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
    return trim($s, '_');
}

/**
 * Liest CPU-Temperaturen aus /sys/class/hwmon (coretemp, k10temp, zenpower, cpu_thermal …).
 * @return array<int, array{key:string,label:string,value:float}>
 */
function qi_temps_hwmon(): array
{
    $cpuChips = ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal', 'cpu-thermal', 'soc_thermal', 'acpitz'];
    $found = [];
    foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $dir) {
        $chip = trim((string)@file_get_contents($dir . '/name'));
        if (!in_array($chip, $cpuChips, true)) {
            continue;
        }
        foreach (glob($dir . '/temp*_input') ?: [] as $inputFile) {
            $raw = trim((string)@file_get_contents($inputFile));
            if ($raw === '' || !is_numeric($raw)) {
                continue;
            }
            $value = round((int)$raw / 1000, 1);
            if ($value <= -100 || $value > 200) {
                continue;
            }
            $labelFile = preg_replace('/_input$/', '_label', $inputFile);
            $label = trim((string)@file_get_contents((string)$labelFile));
            if ($label === '') {
                $label = $chip . ' ' . basename($inputFile, '_input');
            }
            $found[] = ['chip' => $chip, 'label' => $label, 'value' => $value];
        }
    }
    return qi_temps_finalize($found);
}

/**
 * Fallback über `sensors -j` (lm-sensors ≥ 3.5).
 */
function qi_temps_sensors(string $binary): array
{
    if (!qi_command_exists($binary)) {
        return [];
    }
    $out = qi_exec([$binary, '-j'], 8);
    $data = $out ? json_decode($out, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $found = [];
    foreach ($data as $chipName => $features) {
        if (!is_array($features)) {
            continue;
        }
        $chip = strtolower(explode('-', (string)$chipName)[0]);
        if (!preg_match('/coretemp|k10temp|zenpower|cpu|acpitz/', $chip)) {
            continue;
        }
        foreach ($features as $label => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $k => $v) {
                if (preg_match('/^temp\d+_input$/', (string)$k) && is_numeric($v)) {
                    $found[] = ['chip' => $chip, 'label' => (string)$label, 'value' => round((float)$v, 1)];
                    break;
                }
            }
        }
    }
    return qi_temps_finalize($found);
}

/**
 * Letzter Fallback: /sys/class/thermal/thermal_zone*.
 */
function qi_temps_thermal_zones(): array
{
    $found = [];
    foreach (glob('/sys/class/thermal/thermal_zone*') ?: [] as $dir) {
        $type = trim((string)@file_get_contents($dir . '/type'));
        $raw = trim((string)@file_get_contents($dir . '/temp'));
        if ($raw === '' || !is_numeric($raw)) {
            continue;
        }
        if (!preg_match('/cpu|x86_pkg_temp|soc|acpitz|core/i', $type)) {
            continue;
        }
        $found[] = ['chip' => 'thermal', 'label' => $type, 'value' => round((int)$raw / 1000, 1)];
    }
    return qi_temps_finalize($found);
}

/**
 * Bildet stabile Metrik-Schlüssel: "Core 0" → temp.core.0, "Package id 0" → temp.package.0,
 * "Tctl" → temp.tctl, sonst temp.<slug>.
 */
function qi_temps_finalize(array $found): array
{
    $result = [];
    $used = [];
    foreach ($found as $t) {
        $label = $t['label'];
        if (preg_match('/^Core\s+(\d+)$/i', $label, $m)) {
            $key = 'temp.core.' . (int)$m[1];
            $label = 'Kern ' . (int)$m[1];
        } elseif (preg_match('/^Package id\s+(\d+)$/i', $label, $m)) {
            $key = 'temp.package.' . (int)$m[1];
            $label = 'Package ' . (int)$m[1];
        } elseif (preg_match('/^Tccd(\d+)$/i', $label, $m)) {
            $key = 'temp.ccd.' . (int)$m[1];
            $label = 'CCD ' . (int)$m[1];
        } else {
            $key = 'temp.' . qi_slug($label);
        }
        $key = substr($key, 0, 48);
        if (isset($used[$key])) {
            continue;
        }
        $used[$key] = true;
        $result[] = ['key' => $key, 'label' => $label, 'value' => $t['value']];
    }
    usort($result, static fn($a, $b) => strnatcmp($a['key'], $b['key']));
    return $result;
}

$temps = qi_temps_hwmon();
if (!$temps) {
    $temps = qi_temps_sensors((string)$ccfg['sensors']);
}
if (!$temps) {
    $temps = qi_temps_thermal_zones();
}
foreach ($temps as $t) {
    $metrics[$t['key']] = $t['value'];
}
$snapshot['temps'] = $temps;
if ($temps) {
    $snapshot['temp_max'] = max(array_column($temps, 'value'));
    $metrics['temp.max'] = $snapshot['temp_max'];
}
qi_log(sprintf('CPU %.1f%% | %d Kerne | %d Temperatursensoren', $metrics['cpu.total'] ?? 0, count($cores), count($temps)));

// ---------------------------------------------------------------------------
// NVIDIA GPU
// ---------------------------------------------------------------------------
$gpus = [];
$nvidiaBin = (string)$ccfg['nvidia_smi'];
if (qi_command_exists($nvidiaBin)) {
    $out = qi_exec([
        $nvidiaBin,
        '--query-gpu=index,name,utilization.gpu,temperature.gpu,memory.used,memory.total,power.draw',
        '--format=csv,noheader,nounits',
    ], 15);
    foreach (preg_split('/\R/', (string)$out) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_contains(strtolower($line), 'failed') || str_contains(strtolower($line), 'error')) {
            continue;
        }
        $cols = array_map('trim', explode(',', $line));
        if (count($cols) < 4 || !is_numeric($cols[0])) {
            continue;
        }
        $idx = (int)$cols[0];
        $util = is_numeric($cols[2]) ? (float)$cols[2] : null;
        $temp = is_numeric($cols[3]) ? (float)$cols[3] : null;
        $memUsed = isset($cols[4]) && is_numeric($cols[4]) ? (int)$cols[4] : null;
        $memTotal = isset($cols[5]) && is_numeric($cols[5]) ? (int)$cols[5] : null;
        $power = isset($cols[6]) && is_numeric($cols[6]) ? (float)$cols[6] : null;
        $gpus[] = [
            'index' => $idx, 'name' => $cols[1], 'util' => $util, 'temp' => $temp,
            'mem_used_mb' => $memUsed, 'mem_total_mb' => $memTotal, 'power_w' => $power,
        ];
        if ($util !== null) {
            $metrics['gpu.' . $idx . '.util'] = $util;
        }
        if ($temp !== null) {
            $metrics['gpu.' . $idx . '.temp'] = $temp;
        }
        if ($memUsed !== null && $memTotal) {
            $metrics['gpu.' . $idx . '.mem_pct'] = round($memUsed / $memTotal * 100, 2);
        }
    }
}
$snapshot['gpus'] = $gpus;
qi_log(count($gpus) . ' GPU(s) erkannt');

// ---------------------------------------------------------------------------
// Root-Dateisystem
// ---------------------------------------------------------------------------
$rootFs = (string)$ccfg['root_fs'];
$df = qi_exec(['df', '-kP', $rootFs], 8);
$disk = null;
if ($df) {
    $lines = preg_split('/\R/', trim($df)) ?: [];
    $last = end($lines);
    $cols = preg_split('/\s+/', trim((string)$last)) ?: [];
    if (count($cols) >= 6) {
        $total = (int)$cols[1] * 1024;
        $used = (int)$cols[2] * 1024;
        $avail = (int)$cols[3] * 1024;
        $disk = [
            'filesystem' => $cols[0],
            'mount'      => $cols[5],
            'total'      => $total,
            'used'       => $used,
            'available'  => $avail,
            'pct'        => $total > 0 ? round($used / ($used + $avail) * 100, 2) : 0,
        ];
    }
}
if ($disk === null) {
    $total = (float)@disk_total_space($rootFs);
    $free = (float)@disk_free_space($rootFs);
    if ($total > 0) {
        $disk = [
            'filesystem' => 'unknown', 'mount' => $rootFs,
            'total' => (int)$total, 'used' => (int)($total - $free), 'available' => (int)$free,
            'pct' => round(($total - $free) / $total * 100, 2),
        ];
    }
}
if ($disk) {
    $snapshot['disk'] = $disk;
    $metrics['disk.used_pct'] = $disk['pct'];
    $metrics['disk.used_gb'] = round($disk['used'] / 1073741824, 3);
    $metrics['disk.free_gb'] = round($disk['available'] / 1073741824, 3);
    qi_log(sprintf('Disk %s: %.1f%% belegt', $disk['mount'], $disk['pct']));
}

// ---------------------------------------------------------------------------
// systemd-Dienste
// ---------------------------------------------------------------------------
$db = qi_db();
$services = $db->query('SELECT id, name FROM services')->fetchAll();
$serviceRows = [];
$updService = $db->prepare('UPDATE services SET last_state = ?, last_active = ?, last_check = ? WHERE id = ?');
$logService = $db->prepare('INSERT INTO service_log (service_id, ts, active) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE active = VALUES(active)');
foreach ($services as $svc) {
    $state = qi_probe_service($svc['name']);
    $updService->execute([$state['state'], $state['active'] ? 1 : 0, time(), (int)$svc['id']]);
    $logService->execute([(int)$svc['id'], $ts, $state['active'] ? 1 : 0]);
    $serviceRows[] = $svc['name'] . '=' . $state['state'];
}
qi_log('Dienste: ' . implode(', ', $serviceRows));

// ---------------------------------------------------------------------------
// Persistieren
// ---------------------------------------------------------------------------
if ($metrics) {
    $placeholders = [];
    $params = [];
    foreach ($metrics as $metric => $value) {
        $placeholders[] = '(?, ?, ?)';
        $params[] = $metric;
        $params[] = $ts;
        $params[] = (float)$value;
    }
    $db->prepare(
        'INSERT INTO metrics (metric, ts, value) VALUES ' . implode(',', $placeholders)
        . ' ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute($params);
}

$db->prepare("INSERT INTO snapshot (k, v, ts) VALUES ('latest', ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), ts = VALUES(ts)")
    ->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE), time()]);
qi_log(count($metrics) . ' Metriken gespeichert');

// ---------------------------------------------------------------------------
// Wartung: Downsampling & Cleanup (stündlich)
// ---------------------------------------------------------------------------
$lastMaintenance = (int)qi_meta_get('last_maintenance', '0');
if ($forceMaintenance || time() - $lastMaintenance >= 3600) {
    $ret = $cfg['retention'];
    $now = time();

    // Rohdaten älter als 2h in 10-Minuten-Buckets verdichten (nur vollständige Buckets)
    $aggFrom = (int)qi_meta_get('agg_until', '0');
    $aggTo = (int)(floor(($now - 7200) / 600) * 600);
    if ($aggFrom === 0) {
        $minTs = (int)$db->query('SELECT COALESCE(MIN(ts), 0) FROM metrics')->fetchColumn();
        $aggFrom = (int)(floor($minTs / 600) * 600);
    }
    if ($aggTo > $aggFrom) {
        $stmt = $db->prepare(
            'INSERT INTO metrics_agg (metric, ts, avg_value, min_value, max_value, samples)
             SELECT metric, FLOOR(ts / 600) * 600 AS bucket, AVG(value), MIN(value), MAX(value), COUNT(*)
               FROM metrics
              WHERE ts >= ? AND ts < ?
           GROUP BY metric, bucket
                 ON DUPLICATE KEY UPDATE
                    avg_value = VALUES(avg_value), min_value = VALUES(min_value),
                    max_value = VALUES(max_value), samples = VALUES(samples)'
        );
        $stmt->execute([$aggFrom, $aggTo]);
        qi_meta_set('agg_until', (string)$aggTo);
        qi_log(sprintf('Verdichtet: %d Buckets (%s – %s)', $stmt->rowCount(), date('c', $aggFrom), date('c', $aggTo)));
    }

    $delRaw = $db->prepare('DELETE FROM metrics WHERE ts < ? LIMIT 50000');
    $delRaw->execute([$now - (int)$ret['raw_days'] * 86400]);
    $delAgg = $db->prepare('DELETE FROM metrics_agg WHERE ts < ? LIMIT 50000');
    $delAgg->execute([$now - (int)$ret['agg_days'] * 86400]);
    $delLog = $db->prepare('DELETE FROM service_log WHERE ts < ? LIMIT 50000');
    $delLog->execute([$now - (int)$ret['log_days'] * 86400]);
    $db->prepare('DELETE FROM login_attempts WHERE last_attempt < ?')->execute([$now - 86400]);

    qi_meta_set('last_maintenance', (string)$now);
    qi_log(sprintf('Cleanup: %d Rohdaten, %d Aggregat-, %d Log-Zeilen entfernt',
        $delRaw->rowCount(), $delAgg->rowCount(), $delLog->rowCount()));
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
