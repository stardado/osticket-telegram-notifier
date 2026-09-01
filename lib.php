<?php
/**
 * osTicket Telegram Notifier - gemeinsame Funktionen
 *
 * Copyright (C) 2025-2026 Denis Apel (stardado)
 * Lizenz: GPL-3.0-or-later, siehe LICENSE
 */

/**
 * Konfiguration laden, Pflichtschluessel pruefen, abgeleitete Pfade setzen.
 * Bricht mit Exit 1 ab, wenn etwas fehlt - ein fehlender Wert fiele sonst erst
 * als kryptischer Telegram- oder DB-Fehler auf.
 */
function tb_load_config(array $required): array {
    $file = __DIR__ . '/ticketbot_config.php';
    if (!is_readable($file)) {
        fwrite(STDERR, "Konfiguration $file fehlt oder ist nicht lesbar. Vorlage: config.example.php\n");
        exit(1);
    }
    $c = require $file;
    $GLOBALS['tb_log_file'] = $c['log_file'] ?? '/var/log/ticketbot.log';

    $missing = array_filter($required, fn($k) => !isset($c[$k]) || $c[$k] === '');
    if ($missing) {
        $msg = "Konfiguration unvollstaendig, fehlende Schluessel: " . implode(', ', $missing);
        tb_log("❌ $msg");
        fwrite(STDERR, "$msg\n");
        exit(1);
    }
    $prefix = $c['db_prefix'] ?? 'ost_';
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
        fwrite(STDERR, "db_prefix darf nur Buchstaben, Ziffern und Unterstrich enthalten.\n");
        exit(1);
    }
    // Die folgenden Werte sind nur fuer Tests gedacht und brauchen im
    // Regelbetrieb keinen Eintrag in der Konfiguration.
    $c['db_prefix'] = $prefix;
    $c['api_base']  = rtrim($c['api_base'] ?? 'https://api.telegram.org', '/');
    $c['state_dir'] = rtrim($c['state_dir'] ?? '/var/lib/ticketbot', '/');
    $c['debug']     = (bool)($c['debug'] ?? false);
    return $c;
}

function tb_log(string $text): void {
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($GLOBALS['tb_log_file'] ?? '/var/log/ticketbot.log', "[$ts] - $text\n", FILE_APPEND);
}

/**
 * Exklusive Sperre gegen parallele Cron-Laeufe. Gibt das Handle zurueck; es
 * muss bis zum Ende des Laufs leben, sonst faellt die Sperre.
 */
function tb_lock(string $stateDir, string $name) {
    if (!is_dir($stateDir) || !is_writable($stateDir)) {
        tb_log("❌ State-Verzeichnis $stateDir fehlt oder ist nicht beschreibbar. Anlegen mit: install -d -o www-data -g www-data $stateDir");
        exit(1);
    }
    $lockFile = "$stateDir/$name.lock";
    $lock = fopen($lockFile, 'c');
    if ($lock === false) {
        tb_log("❌ Sperrdatei $lockFile kann nicht geoeffnet werden.");
        exit(1);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        tb_log("⏭️ Ein anderer Lauf ($name) ist noch aktiv, dieser Lauf wird uebersprungen.");
        exit(0);
    }
    return $lock;
}

/**
 * Zaehlerstand atomar schreiben.
 * file_put_contents leert die Datei und schreibt dann. Ein Stromausfall
 * dazwischen hinterlaesst eine leere Datei - (int)'' ist 0. Tempdatei plus
 * rename() ist auf POSIX atomar: es gibt nur den alten oder den neuen Inhalt.
 */
function tb_write_state(string $file, int $value): void {
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, (string)$value, LOCK_EX) === false || !rename($tmp, $file)) {
        tb_log("❌ State-Datei $file konnte nicht geschrieben werden!");
        exit(1);
    }
}

function tb_read_state(string $file): int {
    return file_exists($file) ? (int)file_get_contents($file) : 0;
}

/**
 * Text fuer MarkdownV2 aufbereiten: kaputte Bytes verwerfen, Steuerzeichen
 * entfernen, Sonderzeichen escapen.
 * Der /u-Modifier ist zwingend: ohne ihn arbeitet die Zeichenklasse byteweise
 * und loescht jedes Multibyte-Zeichen - Umlaute inklusive.
 */
function tb_escape(string $text): string {
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $text = preg_replace('/\p{C}/u', '', $text) ?? '';
    $special = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '=', '|', '{', '}', '.', '!', '-'];
    return strtr($text, array_combine($special, array_map(fn($c) => "\\$c", $special)));
}

/**
 * Einen Aufruf an die Telegram-Bot-API senden.
 *
 * Rueckgabe: ['ok' => bool, 'code' => int, 'desc' => string, 'result' => mixed]
 * code 0 bedeutet: keine auswertbare Antwort (Netzwerkfehler, Timeout,
 * HTML-Fehlerseite eines Proxys statt JSON).
 */
function tb_api(array $config, string $method, array $data): array {
    $url = "{$config['api_base']}/bot{$config['telegram_token']}/$method";
    $ch  = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Ohne Timeouts haelt eine haengende Verbindung die flock-Sperre fuer
    // immer, und jeder weitere Cron-Lauf beendet sich mit 'anderer Lauf
    // aktiv'. Der Bot stuende still, das Log saehe harmlos aus.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error !== '') {
        return ['ok' => false, 'code' => 0, 'desc' => "cURL: $error", 'result' => null];
    }
    $json = json_decode($response, true);
    if (!is_array($json) || !array_key_exists('ok', $json)) {
        return ['ok' => false, 'code' => 0, 'desc' => 'Keine JSON-Antwort: ' . substr(strip_tags($response), 0, 120), 'result' => null];
    }
    return [
        'ok'     => $json['ok'] === true,
        'code'   => (int)($json['error_code'] ?? 0),
        'desc'   => (string)($json['description'] ?? ''),
        'result' => $json['result'] ?? null,
    ];
}

/** Verbindung zur osTicket-Datenbank, Fehler landen im Log statt als Exception. */
function tb_db(array $c): mysqli {
    // Seit PHP 8.1 wirft mysqli standardmaessig Exceptions; connect_error
    // wuerde nie gesetzt. Ohne try/catch stirbt der Bot bei DB-Ausfall mit
    // einem unbehandelten Fehler, der nur in der Cron-Mail landet.
    try {
        $conn = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name']);
        if (!$conn->set_charset('utf8mb4')) {
            throw new RuntimeException('set_charset utf8mb4: ' . $conn->error);
        }
        return $conn;
    } catch (Throwable $e) {
        tb_log("❌ DB-Verbindung fehlgeschlagen: " . $e->getMessage());
        exit(1);
    }
}
