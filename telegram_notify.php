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
$config = require(__DIR__ . '/ticketbot_config.php');

// Konfiguration übernehmen
$telegramToken  = $config['telegram_token'];
$chatId         = $config['telegram_chat_id'];
$db_host        = $config['db_host'];
$db_user        = $config['db_user'];
$db_pass        = $config['db_pass'];
$db_name        = $config['db_name'];
$ticketBaseURL  = $config['ticket_url_base'];
$debug          = (bool)($config['debug'] ?? false);
$maxPerRun      = 20;   // Telegram drosselt Gruppen bei etwa 20 Nachrichten/Minute

// Die folgenden Werte sind nur fuer Tests gedacht und brauchen im Regelbetrieb
// keinen Eintrag in der Konfiguration.
$apiBase        = rtrim($config['api_base'] ?? 'https://api.telegram.org', '/');
$stateDir       = rtrim($config['state_dir'] ?? '/var/lib/ticketbot', '/');
$logFile        = $config['log_file'] ?? '/var/log/ticketbot.log';
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

// Sperre gegen parallele Laeufe.
// Cron startet jede Minute; ein Lauf mit Rueckstand dauert laenger als eine
// Minute. Ohne Sperre arbeiten mehrere Prozesse dieselbe Warteschlange ab und
// senden dieselben Tickets mehrfach - am 01.09.2026 bis zu 4x pro Ticket.
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    logMessage("⏭️ Ein anderer Lauf ist noch aktiv, dieser Lauf wird uebersprungen.");
    exit(0);
}

// Verbindung zur Datenbank
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    logMessage("❌ DB-Verbindung fehlgeschlagen: " . $conn->connect_error);
    exit(1);
}
$conn->set_charset("utf8mb4");

// letzte Ticket-ID laden
$last_id = file_exists($lastIdFile) ? (int)file_get_contents($lastIdFile) : 0;

// Kein brauchbarer Stand? Dann auf die aktuell hoechste Ticket-ID setzen und
// nichts versenden. Ohne diese Absicherung startet der Bot bei ID 0 und
// schickt die komplette Ticket-Historie erneut in die Gruppe - genau das ist
// am 01.09.2026 nach einem Reboot passiert, als die State-Datei noch in /tmp
// lag und mit dem Neustart verschwand.
if ($last_id <= 0) {
    $seed = $conn->query("SELECT MAX(ticket_id) AS m FROM ost_ticket");
    $last_id = $seed ? (int)($seed->fetch_assoc()['m'] ?? 0) : 0;
    file_put_contents($lastIdFile, $last_id, LOCK_EX);
    logMessage("🔰 Kein gueltiger Stand gefunden. Startpunkt auf hoechste Ticket-ID $last_id gesetzt, es wird nichts nachgesendet.");
    $conn->close();
    exit(0);
}

// neue Tickets abfragen
$sql = "SELECT T.ticket_id, T.number, U.name, C.subject, T.created
        FROM ost_ticket T
        LEFT JOIN ost_user U ON T.user_id = U.id
        LEFT JOIN ost_ticket__cdata C ON T.ticket_id = C.ticket_id
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

        // Nachricht vorbereiten
        $message = "📬 [Neues Ticket eingegangen\\!]($link)\n"
                 . "🆔 [Ticket\\-ID: \\#$ticketNumber]($link)\n\n"
		 . "📝 Betreff: $subject\n\n"
                 . "👤 Von: $name 🕒 Zeit: $created";

        if ($debug) {
            logMessage("🔍 DEBUG Text für Ticket $ticketNumber:\n$message");
        }

        $url = "$apiBase/bot$telegramToken/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'MarkdownV2',
            'disable_web_page_preview' => true
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // Voruebergehende Stoerung? Dann darf die ID nicht weiterruecken.
        $transient = false;

        if ($response === false || !empty($error)) {
            logMessage("❌ cURL-Fehler bei Telegram für Ticket $ticketNumber: $error");
            $transient = true;
        } else {
            $json = json_decode($response, true);
            if (!$json || !isset($json['ok']) || $json['ok'] !== true) {
                $code = (int)($json['error_code'] ?? 0);
                $desc = $json['description'] ?? 'Unbekannter Fehler';
                logMessage("❌ Telegram-Fehler für Ticket $ticketNumber (Code $code): $desc");
                // 429 = Rate-Limit, 5xx = Stoerung bei Telegram. Beides geht vorbei.
                $transient = ($code === 429 || $code >= 500);
                if ($code === 429) {
                    $wait = (int)($json['parameters']['retry_after'] ?? 5);
                    logMessage("⏳ Rate-Limit erreicht, Telegram bittet um $wait Sekunden Pause.");
                }
            } else {
                logMessage("✅ Telegram gesendet für Ticket #$ticketNumber (ID $ticketId), msg_id: " . $json['result']['message_id']);
            }
        }

        if ($transient) {
            logMessage("↩️ Lauf beendet. Ticket-ID $ticketId wird im naechsten Lauf erneut versucht.");
            break;
        }

        // Weiterruecken bei Erfolg und bei dauerhaften Fehlern. Wuerde auch ein
        // dauerhaft unzustellbares Ticket die ID blockieren, stuende die gesamte
        // Warteschlange still.
        file_put_contents($lastIdFile, $ticketId, LOCK_EX);
    }
} else {
    logMessage("ℹ️ Keine neuen Tickets gefunden (ab ID $last_id).");
}

$conn->close();
