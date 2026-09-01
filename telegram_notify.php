<?php
/**
 * osTicket Telegram Notifier
 * Meldet neu eingegangene osTicket-Tickets in eine Telegram-Gruppe.
 *
 * Copyright (C) 2025-2026 Denis Apel (stardado)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

// Konfiguration laden
$configFile = __DIR__ . '/ticketbot_config.php';
if (!is_readable($configFile)) {
    fwrite(STDERR, "Konfiguration $configFile fehlt oder ist nicht lesbar. Vorlage: config.example.php\n");
    exit(1);
}
$config = require($configFile);

// Pflichtschluessel pruefen, bevor irgendetwas anderes passiert. Ein fehlender
// Wert wuerde sonst erst als kryptischer Telegram- oder DB-Fehler auffallen.
$required = ['telegram_token', 'telegram_chat_id', 'db_host', 'db_user', 'db_pass', 'db_name', 'ticket_url_base'];
$missing  = array_filter($required, fn($k) => !isset($config[$k]) || $config[$k] === '');
$logFile  = $config['log_file'] ?? '/var/log/ticketbot.log';
if ($missing) {
    $msg = "Konfiguration unvollstaendig, fehlende Schluessel: " . implode(', ', $missing);
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] - ❌ $msg\n", FILE_APPEND);
    fwrite(STDERR, "$msg\n");
    exit(1);
}

// Konfiguration übernehmen
$telegramToken  = $config['telegram_token'];
$chatId         = $config['telegram_chat_id'];
$db_host        = $config['db_host'];
$db_user        = $config['db_user'];
$db_pass        = $config['db_pass'];
$db_name        = $config['db_name'];
$ticketBaseURL  = $config['ticket_url_base'];
$debug          = (bool)($config['debug'] ?? false);
$prefix         = $config['db_prefix'] ?? 'ost_';   // TABLE_PREFIX aus ost-config.php
if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "db_prefix darf nur Buchstaben, Ziffern und Unterstrich enthalten.\n");
    exit(1);
}
$maxPerRun      = 20;   // Telegram drosselt Gruppen bei etwa 20 Nachrichten/Minute

// Die folgenden Werte sind nur fuer Tests gedacht und brauchen im Regelbetrieb
// keinen Eintrag in der Konfiguration.
$apiBase        = rtrim($config['api_base'] ?? 'https://api.telegram.org', '/');
$stateDir       = rtrim($config['state_dir'] ?? '/var/lib/ticketbot', '/');
$lastIdFile     = "$stateDir/last_ticket_id.txt";
$lockFile       = "$stateDir/ticketbot.lock";

// Logfunktion
function logMessage($text) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] - $text\n", FILE_APPEND);
}

// Markdown escapen (ohne Bindestriche escapen)
function cleanAndEscape($text) {
    // Ungueltige Byte-Sequenzen verwerfen. utf8_encode() deutete den Text
    // faelschlich als Latin-1 und ist seit PHP 8.2 ausserdem deprecated.
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    // Nur Steuer- und Formatzeichen entfernen.
    // Der /u-Modifier ist zwingend: ohne ihn arbeitet die Zeichenklasse
    // byteweise und loescht jedes Multibyte-Zeichen - Umlaute inklusive.
    $text = preg_replace('/\p{C}/u', '', $text);
    $replacements = [
        '_' => '\_',
        '*' => '\*',
        '[' => '\[',
        ']' => '\]',
        '(' => '\(',
        ')' => '\)',
        '~' => '\~',
        '`' => '\`',
        '>' => '\>',
        '#' => '\#',
        '+' => '\+',
        '=' => '\=',
        '|' => '\|',
        '{' => '\{',
        '}' => '\}',
        '.' => '\.',
        '!' => '\!',
        '-' => '\-'
    ];
    return strtr($text, $replacements);
}

/**
 * Zuletzt gemeldete Ticket-ID atomar schreiben.
 *
 * file_put_contents leert die Datei und schreibt dann. Ein Stromausfall
 * dazwischen hinterlaesst eine leere Datei, (int)'' ist 0, und die Seed-Logik
 * setzt beim naechsten Lauf auf MAX(ticket_id) - alle inzwischen eingegangenen
 * Tickets waeren still uebersprungen. Schreiben in eine Tempdatei und rename()
 * ist auf POSIX atomar: es gibt nur den alten oder den neuen Inhalt.
 */
function writeState(string $file, int $id): void {
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, (string)$id, LOCK_EX) === false || !rename($tmp, $file)) {
        logMessage("❌ State-Datei $file konnte nicht geschrieben werden!");
        exit(1);
    }
}

/**
 * Eine Nachricht an die Telegram-API senden.
 *
 * Rueckgabe: ['ok' => bool, 'code' => int, 'desc' => string, 'msg_id' => int]
 * code 0 bedeutet: keine auswertbare Antwort (Netzwerkfehler, Timeout,
 * HTML-Fehlerseite eines Proxys statt JSON).
 */
function sendTelegram(string $url, array $data): array {
    $ch = curl_init();
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
        return ['ok' => false, 'code' => 0, 'desc' => "cURL: $error", 'msg_id' => 0];
    }
    $json = json_decode($response, true);
    if (!is_array($json) || !array_key_exists('ok', $json)) {
        return ['ok' => false, 'code' => 0, 'desc' => 'Keine JSON-Antwort: ' . substr(strip_tags($response), 0, 120), 'msg_id' => 0];
    }
    return [
        'ok'     => $json['ok'] === true,
        'code'   => (int)($json['error_code'] ?? 0),
        'desc'   => (string)($json['description'] ?? ''),
        'msg_id' => (int)($json['result']['message_id'] ?? 0),
    ];
}

// Sperre gegen parallele Laeufe.
// Cron startet jede Minute; ein Lauf mit Rueckstand dauert laenger als eine
// Minute. Ohne Sperre arbeiten mehrere Prozesse dieselbe Warteschlange ab und
// senden dieselben Tickets mehrfach - am 01.09.2026 bis zu 4x pro Ticket.
if (!is_dir($stateDir) || !is_writable($stateDir)) {
    logMessage("❌ State-Verzeichnis $stateDir fehlt oder ist nicht beschreibbar. Anlegen mit: install -d -o www-data -g www-data $stateDir");
    exit(1);
}
$lock = fopen($lockFile, 'c');
if ($lock === false) {
    logMessage("❌ Sperrdatei $lockFile kann nicht geoeffnet werden.");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    logMessage("⏭️ Ein anderer Lauf ist noch aktiv, dieser Lauf wird uebersprungen.");
    exit(0);
}

// Verbindung zur Datenbank.
// Seit PHP 8.1 wirft mysqli standardmaessig Exceptions; connect_error wuerde
// nie gesetzt. Ohne diesen Block stirbt der Bot bei DB-Ausfall mit einem
// unbehandelten Fehler, der nur in der Cron-Mail landet - nicht im Log.
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if (!$conn->set_charset('utf8mb4')) {
        throw new RuntimeException('set_charset utf8mb4: ' . $conn->error);
    }
} catch (Throwable $e) {
    logMessage("❌ DB-Verbindung fehlgeschlagen: " . $e->getMessage());
    exit(1);
}

// letzte Ticket-ID laden
$last_id = file_exists($lastIdFile) ? (int)file_get_contents($lastIdFile) : 0;

// Kein brauchbarer Stand? Dann auf die aktuell hoechste Ticket-ID setzen und
// nichts versenden. Ohne diese Absicherung startet der Bot bei ID 0 und
// schickt die komplette Ticket-Historie erneut in die Gruppe - genau das ist
// am 01.09.2026 nach einem Reboot passiert, als die State-Datei noch in /tmp
// lag und mit dem Neustart verschwand.
if ($last_id <= 0) {
    $seed = $conn->query("SELECT MAX(ticket_id) AS m FROM {$prefix}ticket");
    $last_id = $seed ? (int)($seed->fetch_assoc()['m'] ?? 0) : 0;
    writeState($lastIdFile, $last_id);
    logMessage("🔰 Kein gueltiger Stand gefunden. Startpunkt auf hoechste Ticket-ID $last_id gesetzt, es wird nichts nachgesendet.");
    $conn->close();
    exit(0);
}

// neue Tickets abfragen
$sql = "SELECT T.ticket_id, T.number, U.name, C.subject, T.created
        FROM {$prefix}ticket T
        LEFT JOIN {$prefix}user U ON T.user_id = U.id
        LEFT JOIN {$prefix}ticket__cdata C ON T.ticket_id = C.ticket_id
        WHERE T.ticket_id > $last_id
        ORDER BY T.ticket_id ASC";

$result = $conn->query($sql);
if ($result === false) {
    logMessage("❌ Fehler bei DB-Abfrage: " . $conn->error);
    $conn->close();
    exit(1);
}

if ($result->num_rows > 0) {
    $sent = 0;
    while ($row = $result->fetch_assoc()) {
        if ($sent >= $maxPerRun) {
            logMessage("⏸️ Obergrenze von $maxPerRun Nachrichten erreicht, Rest folgt im naechsten Lauf.");
            break;
        }
        if ($sent > 0) {
            sleep(1);   // Abstand halten, damit Telegram nicht drosselt
        }
        $sent++;

        $ticketId     = (int)$row['ticket_id'];
        $ticketNumber = cleanAndEscape($row['number'] ?? '');
        $name         = cleanAndEscape($row['name'] ?? 'Unbekannt');
        $subject      = cleanAndEscape($row['subject'] ?? '(kein Betreff)');

        // Datum formatiert (dd.mm.yyyy HH:MM)
        $dt = new DateTime($row['created'] ?? 'now');
        $created = cleanAndEscape($dt->format('d.m.Y H:i'));

        $link = $ticketBaseURL . $ticketId;
        // Innerhalb von (...) eines MarkdownV2-Links muessen ')' und '\\' escaped sein.
        $mdLink = strtr($link, ['\\' => '\\\\', ')' => '\\)']);

        // Nachricht vorbereiten
        $message = "📬 [Neues Ticket eingegangen\\!]($mdLink)\n"
                 . "🆔 [Ticket\\-ID: \\#$ticketNumber]($mdLink)\n\n"
                 . "📝 Betreff: $subject\n\n"
                 . "👤 Von: $name 🕒 Zeit: $created";

        if ($debug) {
            logMessage("🔍 DEBUG Text für Ticket $ticketNumber:\n$message");
        }

        $url  = "$apiBase/bot$telegramToken/sendMessage";
        $data = [
            'chat_id'                  => $chatId,
            'text'                     => $message,
            'parse_mode'               => 'MarkdownV2',
            'disable_web_page_preview' => true,
        ];
        $r = sendTelegram($url, $data);

        // MarkdownV2 ist fragil. Weist Telegram die Formatierung zurueck, geht
        // die Nachricht einmal als Klartext raus - unschoen, aber zugestellt.
        if (!$r['ok'] && $r['code'] === 400 && preg_match('/parse|entit|too long/i', $r['desc'])) {
            logMessage("⚠️ MarkdownV2 abgelehnt fuer Ticket $ticketNumber ({$r['desc']}), sende Klartext.");
            $plain = [
                'chat_id'                  => $chatId,
                'text'                     => "📬 Neues Ticket eingegangen!\n🆔 Ticket-ID: #" . ($row['number'] ?? '')
                                            . "\n\n📝 Betreff: " . ($row['subject'] ?? '(kein Betreff)')
                                            . "\n\n👤 Von: " . ($row['name'] ?? 'Unbekannt')
                                            . " 🕒 Zeit: " . $dt->format('d.m.Y H:i') . "\n$link",
                'disable_web_page_preview' => true,
            ];
            $r = sendTelegram($url, $plain);
        }

        if ($r['ok']) {
            logMessage("✅ Telegram gesendet für Ticket #$ticketNumber (ID $ticketId), msg_id: {$r['msg_id']}");
            writeState($lastIdFile, $ticketId);
            continue;
        }

        // Ab hier ist die Nachricht NICHT zugestellt. Grundsatz: die ID rueckt
        // nur weiter, wenn das Problem an dieser einen Nachricht liegt. Alles
        // andere - Rate-Limit, Stoerung, widerrufener Token, Bot aus der Gruppe
        // geworfen, falsche Chat-ID, Proxy-Fehlerseite - haelt den Lauf an und
        // wird im naechsten Lauf erneut versucht. Telegram ist der Alarmkanal;
        // faellt er aus, darf das nicht zum stillen Verlust aller Tickets fuehren.
        $code = $r['code'];
        if ($code === 400) {
            // Immer noch 400 nach Klartext-Fallback, oder ein 400, das nichts
            // mit der Nachricht zu tun hat ('chat not found' ist Konfiguration).
            if (stripos($r['desc'], 'chat not found') === false && stripos($r['desc'], 'chat_id') === false) {
                logMessage("❌ Ticket $ticketNumber nicht zustellbar (400: {$r['desc']}), wird uebersprungen.");
                writeState($lastIdFile, $ticketId);
                continue;
            }
        }
        if ($code === 429) {
            logMessage("⏳ Rate-Limit (429): {$r['desc']}. Lauf beendet, Ticket $ticketNumber folgt im naechsten Lauf.");
        } elseif ($code === 401 || $code === 403 || $code === 400) {
            logMessage("🚨 KONFIGURATIONSFEHLER ($code): {$r['desc']} - Token, Chat-ID oder Gruppenmitgliedschaft pruefen! Lauf beendet, nichts wird uebersprungen.");
        } elseif ($code >= 500) {
            logMessage("❌ Telegram-Stoerung ($code): {$r['desc']}. Lauf beendet, Ticket $ticketNumber folgt im naechsten Lauf.");
        } else {
            logMessage("❌ Keine brauchbare Antwort von Telegram ({$r['desc']}). Lauf beendet, Ticket $ticketNumber folgt im naechsten Lauf.");
        }
        break;
    }
} else {
    logMessage("ℹ️ Keine neuen Tickets gefunden (ab ID $last_id).");
}

$conn->close();
