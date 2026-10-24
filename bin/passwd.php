#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * quickinfo – Passwort eines Benutzers per Kommandozeile zurücksetzen
 * (Notfall-Werkzeug bei vergessenem Admin-Passwort).
 *
 *   sudo php bin/passwd.php                      Benutzer "admin", Passwort wird verdeckt abgefragt
 *   sudo php bin/passwd.php <benutzer>           anderer Benutzername
 *   sudo php bin/passwd.php [<benutzer>] --random  Zufallspasswort erzeugen und einmalig ausgeben
 *
 * Nicht-interaktiv (z. B. aus einem Skript) wird das Passwort aus stdin gelesen:
 *   printf '%s\n' 'neuesPasswort' | sudo php bin/passwd.php admin
 *
 * Der Befehl setzt den Hash in der Tabelle users neu und hebt bestehende
 * Login-Sperren (login_attempts) auf.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur über die Kommandozeile ausführbar.\n");
    exit(1);
}

const QI_PASSWORD_MIN_LENGTH = 10;
const QI_DEFAULT_USER = 'admin';

function usage(): void
{
    $self = basename(__FILE__);
    fwrite(STDOUT, <<<TXT
        Aufruf: php {$self} [<benutzer>] [--random]

          <benutzer>   Benutzername (Standard: admin)
          --random     Zufälliges Passwort erzeugen und einmalig ausgeben

        Ohne --random wird das neue Passwort verdeckt abgefragt. Ist stdin kein
        Terminal, wird eine Zeile aus stdin gelesen (für Skripte).

        TXT);
}

/**
 * Liest eine Zeile von stdin. Auf einem Terminal wird die Eingabe verdeckt.
 */
function read_password(string $prompt): string
{
    fwrite(STDERR, $prompt);

    $isTty = function_exists('stream_isatty') && @stream_isatty(STDIN);
    $sttyState = null;
    if ($isTty) {
        $sttyState = trim((string)@shell_exec('stty -g 2>/dev/null'));
        @shell_exec('stty -echo 2>/dev/null');
    }

    try {
        $line = fgets(STDIN);
    } finally {
        if ($isTty) {
            if ($sttyState !== '') {
                @shell_exec('stty ' . escapeshellarg($sttyState) . ' 2>/dev/null');
            }
            fwrite(STDERR, "\n");
        }
    }

    return $line === false ? '' : rtrim($line, "\r\n");
}

function random_password(int $length = 20): string
{
    // Ohne leicht verwechselbare Zeichen (0/O, 1/l/I)
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

$args = array_slice($argv, 1);
$random = false;
$positional = [];
foreach ($args as $arg) {
    if ($arg === '--random' || $arg === '-r') {
        $random = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        usage();
        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unbekannte Option: {$arg}\n");
        usage();
        exit(2);
    } else {
        $positional[] = $arg;
    }
}

if (count($positional) > 1) {
    fwrite(STDERR, "Zu viele Argumente.\n");
    usage();
    exit(2);
}

$username = $positional[0] ?? QI_DEFAULT_USER;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $isTty = function_exists('stream_isatty') && @stream_isatty(STDIN);

    if ($random) {
        $password = random_password();
    } elseif ($isTty) {
        $password = read_password("Neues Passwort für '{$username}': ");
        $confirm  = read_password('Passwort wiederholen: ');
        if ($password !== $confirm) {
            fwrite(STDERR, "Fehler: Die Passwörter stimmen nicht überein.\n");
            exit(1);
        }
    } else {
        $password = read_password('');
    }

    if (strlen($password) < QI_PASSWORD_MIN_LENGTH) {
        fwrite(STDERR, sprintf(
            "Fehler: Das neue Passwort muss mindestens %d Zeichen lang sein.\n",
            QI_PASSWORD_MIN_LENGTH
        ));
        exit(1);
    }

    $stmt = qi_db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $userId = $stmt->fetchColumn();
    if ($userId === false) {
        fwrite(STDERR, "Fehler: Benutzer '{$username}' nicht gefunden.\n");
        exit(1);
    }

    qi_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$userId]);
    qi_db()->prepare('DELETE FROM login_attempts')->execute();

    if ($random) {
        echo "Neues Passwort für '{$username}' (wird nur einmal angezeigt):\n\n  {$password}\n\n";
    } else {
        echo "Passwort für '{$username}' wurde zurückgesetzt.\n";
    }
    echo "Login-Sperren (Fehlversuche) wurden aufgehoben.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
