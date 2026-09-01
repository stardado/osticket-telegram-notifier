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

require __DIR__ . '/lib.php';

$config   = tb_load_config(['telegram_token', 'telegram_chat_id', 'db_host', 'db_user', 'db_pass', 'db_name', 'ticket_url_base']);
$chatId   = $config['telegram_chat_id'];
$prefix   = $config['db_prefix'];
$debug    = $config['debug'];
$stateDir = $config['state_dir'];
$maxPerRun  = 20;   // Telegram drosselt Gruppen bei etwa 20 Nachrichten/Minute

// Buttons unter jeder Nachricht, ausgewertet von telegram_actions.php.
// callback_data ist auf 64 Bytes begrenzt; 'close:<ticket_id>:<status_id>' passt.
$actions = $config['actions'] ?? [];
$keyboard = function (int $ticketId) use ($actions): ?string {
    if (!$actions) {
        return null;
    }
    $row = array_map(fn($a) => ['text' => $a['label'], 'callback_data' => "close:$ticketId:{$a['status_id']}"], $actions);
    return json_encode(['inline_keyboard' => [$row]], JSON_UNESCAPED_UNICODE);
};
$lastIdFile = "$stateDir/last_ticket_id.txt";

// Sperre gegen parallele Laeufe. Cron startet jede Minute; ein Lauf mit
// Rueckstand dauert laenger. Ohne Sperre senden mehrere Prozesse dieselben
// Tickets mehrfach - am 01.09.2026 bis zu 4x pro Ticket.
$lock = tb_lock($stateDir, 'ticketbot');
$conn = tb_db($config);

// letzte Ticket-ID laden
$last_id = tb_read_state($lastIdFile);

// Kein brauchbarer Stand? Dann auf die aktuell hoechste Ticket-ID setzen und
// nichts versenden. Ohne diese Absicherung startet der Bot bei ID 0 und
// schickt die komplette Ticket-Historie erneut in die Gruppe - genau das ist
// am 01.09.2026 nach einem Reboot passiert, als die State-Datei noch in /tmp
// lag und mit dem Neustart verschwand.
if ($last_id <= 0) {
    $seed = $conn->query("SELECT MAX(ticket_id) AS m FROM {$prefix}ticket");
    $last_id = $seed ? (int)($seed->fetch_assoc()['m'] ?? 0) : 0;
    tb_write_state($lastIdFile, $last_id);
    tb_log("🔰 Kein gueltiger Stand gefunden. Startpunkt auf hoechste Ticket-ID $last_id gesetzt, es wird nichts nachgesendet.");
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
    tb_log("❌ Fehler bei DB-Abfrage: " . $conn->error);
    $conn->close();
    exit(1);
}

if ($result->num_rows === 0) {
    tb_log("ℹ️ Keine neuen Tickets gefunden (ab ID $last_id).");
    $conn->close();
    exit(0);
}

$sent = 0;
while ($row = $result->fetch_assoc()) {
    if ($sent >= $maxPerRun) {
        tb_log("⏸️ Obergrenze von $maxPerRun Nachrichten erreicht, Rest folgt im naechsten Lauf.");
        break;
    }
    if ($sent > 0) {
        sleep(1);   // Abstand halten, damit Telegram nicht drosselt
    }
    $sent++;

    $ticketId     = (int)$row['ticket_id'];
    $rawNumber    = $row['number'] ?? '';
    $rawName      = $row['name'] ?? 'Unbekannt';
    $rawSubject   = $row['subject'] ?? '(kein Betreff)';
    $rawCreated   = (new DateTime($row['created'] ?? 'now'))->format('d.m.Y H:i');

    $link = $config['ticket_url_base'] . $ticketId;
    // Innerhalb von (...) eines MarkdownV2-Links muessen ')' und '\' escaped sein.
    $mdLink = strtr($link, ['\\' => '\\\\', ')' => '\\)']);

    $message = "📬 [Neues Ticket eingegangen\\!]($mdLink)\n"
             . "🆔 [Ticket\\-ID: \\#" . tb_escape($rawNumber) . "]($mdLink)\n\n"
             . "📝 Betreff: " . tb_escape($rawSubject) . "\n\n"
             . "👤 Von: " . tb_escape($rawName) . " 🕒 Zeit: " . tb_escape($rawCreated);

    if ($debug) {
        tb_log("🔍 DEBUG Text für Ticket $rawNumber:\n$message");
    }

    $data = [
        'chat_id'                  => $chatId,
        'text'                     => $message,
        'parse_mode'               => 'MarkdownV2',
        'disable_web_page_preview' => true,
    ];
    if ($kb = $keyboard($ticketId)) {
        $data['reply_markup'] = $kb;
    }
    $r = tb_api($config, 'sendMessage', $data);

    // MarkdownV2 ist fragil. Weist Telegram die Formatierung zurueck, geht
    // die Nachricht einmal als Klartext raus - unschoen, aber zugestellt.
    if (!$r['ok'] && $r['code'] === 400 && preg_match('/parse|entit|too long/i', $r['desc'])) {
        tb_log("⚠️ MarkdownV2 abgelehnt fuer Ticket $rawNumber ({$r['desc']}), sende Klartext.");
        $plain = [
            'chat_id'                  => $chatId,
            'text'                     => "📬 Neues Ticket eingegangen!\n🆔 Ticket-ID: #$rawNumber"
                                        . "\n\n📝 Betreff: $rawSubject"
                                        . "\n\n👤 Von: $rawName 🕒 Zeit: $rawCreated\n$link",
            'disable_web_page_preview' => true,
        ];
        if ($kb) {
            $plain['reply_markup'] = $kb;
        }
        $r = tb_api($config, 'sendMessage', $plain);
    }

    if ($r['ok']) {
        tb_log("✅ Telegram gesendet für Ticket #$rawNumber (ID $ticketId), msg_id: " . ($r['result']['message_id'] ?? '?'));
        tb_write_state($lastIdFile, $ticketId);
        continue;
    }

    // Ab hier ist die Nachricht NICHT zugestellt. Grundsatz: die ID rueckt
    // nur weiter, wenn das Problem an dieser einen Nachricht liegt. Alles
    // andere - Rate-Limit, Stoerung, widerrufener Token, Bot aus der Gruppe
    // geworfen, falsche Chat-ID, Proxy-Fehlerseite - haelt den Lauf an und
    // wird im naechsten Lauf erneut versucht. Telegram ist der Alarmkanal;
    // faellt er aus, darf das nicht zum stillen Verlust aller Tickets fuehren.
    $code = $r['code'];
    if ($code === 400 && stripos($r['desc'], 'chat not found') === false && stripos($r['desc'], 'chat_id') === false) {
        // Immer noch 400 nach Klartext-Fallback: liegt an dieser Nachricht.
        tb_log("❌ Ticket $rawNumber nicht zustellbar (400: {$r['desc']}), wird uebersprungen.");
        tb_write_state($lastIdFile, $ticketId);
        continue;
    }
    if ($code === 429) {
        tb_log("⏳ Rate-Limit (429): {$r['desc']}. Lauf beendet, Ticket $rawNumber folgt im naechsten Lauf.");
    } elseif ($code === 401 || $code === 403 || $code === 400) {
        tb_log("🚨 KONFIGURATIONSFEHLER ($code): {$r['desc']} - Token, Chat-ID oder Gruppenmitgliedschaft pruefen! Lauf beendet, nichts wird uebersprungen.");
    } elseif ($code >= 500) {
        tb_log("❌ Telegram-Stoerung ($code): {$r['desc']}. Lauf beendet, Ticket $rawNumber folgt im naechsten Lauf.");
    } else {
        tb_log("❌ Keine brauchbare Antwort von Telegram ({$r['desc']}). Lauf beendet, Ticket $rawNumber folgt im naechsten Lauf.");
    }
    break;
}

$conn->close();
