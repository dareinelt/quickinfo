#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * quickinfo – API-Schlüssel per Kommandozeile verwalten (wird u.a. von install.sh genutzt).
 *
 *   php bin/apikey.php status              Metadaten des aktiven Schlüssels anzeigen
 *   php bin/apikey.php ensure [--key-only] Schlüssel anlegen, falls noch keiner existiert
 *   php bin/apikey.php rotate [--key-only] Neuen Schlüssel erzeugen (alter wird ungültig)
 *   php bin/apikey.php revoke              Schlüssel widerrufen
 *
 * --key-only gibt ausschließlich den Klartext-Schlüssel aus (leer, wenn bei "ensure"
 * bereits ein Schlüssel vorhanden war) – praktisch für Skripte.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur über die Kommandozeile ausführbar.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/apikey.php';

$args = array_slice($argv, 1);
$keyOnly = in_array('--key-only', $args, true);
$args = array_values(array_filter($args, static fn(string $a) => $a !== '--key-only'));
$cmd = $args[0] ?? 'status';
$label = $args[1] ?? 'management-board';

function print_info(?array $info): void
{
    if ($info === null) {
        echo "Kein API-Schlüssel konfiguriert.\n";
        return;
    }
    printf("Schlüssel:       %s…\n", $info['prefix']);
    printf("Bezeichnung:     %s\n", $info['label']);
    printf("Erstellt:        %s (%s)\n", date('c', $info['created_at']), $info['created_by'] ?? 'unbekannt');
    printf("Zuletzt genutzt: %s%s\n",
        $info['last_used_at'] ? date('c', $info['last_used_at']) : 'nie',
        $info['last_used_ip'] ? ' von ' . $info['last_used_ip'] : '');
    printf("Aufrufe:         %d\n", $info['use_count']);
}

try {
    switch ($cmd) {
        case 'status':
            print_info(qi_api_key_info());
            exit(0);

        case 'ensure':
            $existing = qi_api_key_info();
            if ($existing !== null) {
                if (!$keyOnly) {
                    echo "API-Schlüssel bereits vorhanden – wird beibehalten.\n";
                    print_info($existing);
                }
                exit(0);
            }
            // kein Schlüssel vorhanden → wie "rotate" erzeugen
        case 'rotate':
            $key = qi_api_key_rotate('install.sh', $label);
            if ($keyOnly) {
                echo $key, "\n";
            } else {
                echo "Neuer API-Schlüssel (wird nur einmal angezeigt):\n\n  ", $key, "\n\n";
                echo "Verwendung: Authorization: Bearer ", $key, "\n";
            }
            exit(0);

        case 'revoke':
            echo qi_api_key_revoke() ? "API-Schlüssel widerrufen.\n" : "Kein API-Schlüssel vorhanden.\n";
            exit(0);

        default:
            fwrite(STDERR, "Unbekanntes Kommando: {$cmd}\nVerfügbar: status | ensure | rotate | revoke\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
