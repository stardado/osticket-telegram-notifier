<?php
/**
 * osTicket Telegram Notifier - Button-Klicks verarbeiten
 *
 * Holt per getUpdates die Klicks auf die Buttons unter den Benachrichtigungen
 * und setzt den Ticketstatus ueber osTickets eigene Klassen. Es wird nichts
 * direkt in die Datenbank geschrieben: Ticket::setStatus() erzeugt den
 * Ereignis-Eintrag im Ticketverlauf, prueft Pflichtfelder und offene
 * Aufgaben - genau wie ein Klick im Agenten-Panel.
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

$config   = tb_load_config(['telegram_token', 'telegram_chat_id', 'osticket_dir']);
$chatId   = (string)$config['telegram_chat_id'];
$stateDir = $config['state_dir'];
$actions  = $config['actions'] ?? [];
$offsetFile = "$stateDir/update_offset.txt";

if (!$actions) {
    // Keine Buttons konfiguriert, also auch nichts auszuwerten.
    exit(0);
}
$allowedStatus = [];
foreach ($actions as $a) {
    $allowedStatus[(int)$a['status_id']] = $a['label'];
}

$lock = tb_lock($config, 'actions');

// Long-Polling: Cron kann hoechstens minuetlich starten, ein Klick soll aber
// sofort wirken. Deshalb wartet jeder Lauf bis zu poll_seconds aktiv auf
// Updates (getUpdates mit timeout) und beendet sich rechtzeitig vor dem
// naechsten Cron-Start. Nebeneffekt: answerCallbackQuery kommt innerhalb der
// wenigen Sekunden an, die Telegram dafuer zulaesst - das Popup am Button
// funktioniert nur so. In Tests steht poll_seconds auf 0: ein Durchlauf.
$pollBudget = (int)($config['poll_seconds'] ?? 50);
$started    = time();

do {
    $remaining = $pollBudget - (time() - $started);
    $wait      = max(0, min(25, $remaining));

    // Nur callback_query anfordern: Gruppennachrichten sind fuer dieses Script
    // uninteressant, und so bleibt der Privacy-Mode des Bots ohne Bedeutung.
    $offset = tb_read_state($offsetFile);
    $r = tb_api($config, 'getUpdates', [
        'offset'          => $offset,
        'timeout'         => $wait,
        'allowed_updates' => json_encode(['callback_query']),
    ], $wait + 15);
    if (!$r['ok']) {
    if ($r['code'] === 401) {
        tb_log("🚨 [actions] KONFIGURATIONSFEHLER (401): {$r['desc']} - Token pruefen!");
    } elseif ($r['code'] === 409) {
        tb_log("🚨 [actions] Konflikt (409): {$r['desc']} - ist fuer den Bot ein Webhook gesetzt oder laeuft getUpdates noch woanders?");
    } else {
        tb_log("❌ [actions] getUpdates fehlgeschlagen ({$r['code']}): {$r['desc']}");
    }
    if ($r['code'] === 409) {
        tb_alert($config, 'actions:getupdates', "getUpdates meldet 409-Konflikt: {$r['desc']}\nIst ein Webhook gesetzt oder laeuft getUpdates noch woanders? Button-Klicks werden nicht verarbeitet.");
    }
    exit(1);
}

    tb_alerts_resolve($config, 'actions:');
    $updates = is_array($r['result']) ? $r['result'] : [];

foreach ($updates as $u) {
    // Verarbeitung in einer Funktion: der osTicket-Bootstrap laeuft auf
    // Top-Level und ueberschreibt dabei globale Variablen mit generischen
    // Namen ($msg, $offset, $data ...). Funktionslokale Variablen sind davor
    // sicher. Braucht die Funktion osTicket, meldet sie das, der Bootstrap
    // passiert hier, und sie wird erneut aufgerufen.
    if (tb_handle_update($config, $u, $allowedStatus, $offsetFile) === 'boot') {
        // osTicket laden, wie es dessen eigener api/cron.php tut. Das MUSS auf
        // Top-Level passieren, nicht in einer Funktion: osTicket legt beim
        // Einbinden Variablen im Dateiscope an (z.B. $StopIteration in
        // class.orm.php) und erwartet sie spaeter als Globals. In einer Funktion
        // waeren sie lokal - und die erste ORM-Abfrage stirbt mit
        // 'Can only throw objects'.
        $tbApiInc = rtrim($config['osticket_dir'], '/') . '/api/api.inc.php';
        if (!is_file($tbApiInc)) {
            tb_log("❌ [actions] osticket_dir '{$config['osticket_dir']}' enthaelt kein osTicket (api/api.inc.php fehlt).");
            tb_alert($config, 'actions:osticket', "osticket_dir '{$config['osticket_dir']}' enthaelt kein osTicket. Button-Klicks koennen nicht verarbeitet werden.");
            exit(1);
        }
        $tbCwd = getcwd();
        chdir(dirname($tbApiInc));
        ob_start();
        require_once $tbApiInc;
        ob_end_clean();
        chdir($tbCwd);
        tb_handle_update($config, $u, $allowedStatus, $offsetFile);
    }
}

} while (time() - $started < $pollBudget);

/**
 * Ein Update verarbeiten. Rueckgabe 'boot', wenn osTicket noch nicht geladen
 * ist, aber gebraucht wird - der Aufrufer laedt es und ruft erneut auf.
 */
function tb_handle_update(array $config, array $u, array $allowedStatus, string $offsetFile): string {
    $chatId = (string)$config['telegram_chat_id'];
    $next   = (int)$u['update_id'] + 1;
    $cq     = $u['callback_query'] ?? null;
    if (!$cq) {
        tb_write_state($offsetFile, $next);
        return 'done';
    }

    $cqId  = (string)$cq['id'];
    $data  = (string)($cq['data'] ?? '');
    $from  = $cq['from'] ?? [];
    $msg   = $cq['message'] ?? null;
    $msgId = (int)($msg['message_id'] ?? 0);
    $first = $from['first_name'] ?? 'Unbekannt';
    $who   = trim($first . ' ' . ($from['last_name'] ?? ''));
    $who  .= isset($from['username']) ? " (@{$from['username']})" : '';

    $answer = fn(string $text, bool $alert = false) => tb_api($config, 'answerCallbackQuery', [
        'callback_query_id' => $cqId,
        'text'              => $text,
        'show_alert'        => $alert ? 'true' : 'false',
    ]);
    // Rueckmeldung als Antwort auf die Ticket-Nachricht in der Gruppe. Das
    // Popup (answerCallbackQuery) ist fluechtig und kommt nur an, wenn wir
    // schnell genug sind; die Nachricht bleibt sichtbar.
    $reply = function (string $text) use ($config, $chatId, $msgId): void {
        $x = tb_api($config, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'reply_to_message_id' => $msgId]);
        if (!$x['ok']) {
            tb_log("⚠️ [actions] Rueckmeldung konnte nicht gesendet werden ({$x['code']}): {$x['desc']}");
        }
    };

    // Der 'erledigt'-Button nach einem erfolgreichen Statuswechsel.
    if ($data === 'noop') {
        $answer('');
        tb_write_state($offsetFile, $next);
        return 'done';
    }

    // Einzige Berechtigungspruefung: der Klick muss aus der konfigurierten
    // Gruppe kommen. Wer dort Mitglied ist, darf Tickets schliessen.
    $fromChat = (string)($msg['chat']['id'] ?? '');
    if ($fromChat !== $chatId) {
        tb_log("⛔ [actions] Klick von $who aus fremdem Chat $fromChat ignoriert.");
        $answer('Nicht erlaubt.', true);
        tb_write_state($offsetFile, $next);
        return 'done';
    }

    if (!preg_match('/^close:(\d+):(\d+)$/', $data, $m)) {
        tb_log("⚠️ [actions] Unbekannte Aktion '$data' von $who.");
        $answer('Unbekannte Aktion.');
        tb_write_state($offsetFile, $next);
        return 'done';
    }
    $ticketId = (int)$m[1];
    $statusId = (int)$m[2];
    if (!isset($allowedStatus[$statusId])) {
        tb_log("⛔ [actions] Status $statusId ist nicht konfiguriert, Klick von $who auf Ticket-ID $ticketId ignoriert.");
        $answer('Dieser Status ist nicht erlaubt.', true);
        tb_write_state($offsetFile, $next);
        return 'done';
    }

    // APICALL wird von osTickets api.inc.php definiert.
    if (!defined('APICALL')) {
        return 'boot';
    }

    $res = tb_set_ticket_status($ticketId, $statusId, $who);
    if ($res['ok']) {
        tb_log("✅ [actions] Ticket #{$res['number']} (ID $ticketId) von $who auf '{$res['status']}' gesetzt.");
        $a = $answer("✅ #{$res['number']} → {$res['status']}");
        if (!$a['ok']) {
            tb_log("ℹ️ [actions] Popup nicht zugestellt ({$a['desc']}) - Klick lag zu lange zurueck, Rueckmeldung kommt als Nachricht.");
        }
        $reply("✅ Ticket #{$res['number']} → {$res['status']}\nvon $first, " . date('d.m.Y H:i'));
        // Buttons durch eine Erledigt-Zeile ersetzen, damit niemand doppelt klickt.
        $label = $allowedStatus[$statusId] . ' · ' . $first . ' · ' . date('d.m. H:i');
        $e = tb_api($config, 'editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => $label, 'callback_data' => 'noop']]]], JSON_UNESCAPED_UNICODE),
        ]);
        if (!$e['ok']) {
            tb_log("⚠️ [actions] Buttons konnten nicht ersetzt werden ({$e['code']}): {$e['desc']}");
        }
    } else {
        tb_log("❌ [actions] Ticket-ID $ticketId, Klick von $who: {$res['msg']}");
        $answer($res['msg'], true);
        $reply("⚠️ $first: {$res['msg']}");
    }
    tb_write_state($offsetFile, $next);
    return 'done';
}

/**
 * Status ueber osTickets eigene Logik setzen und eine interne Notiz anlegen.
 * Rueckgabe: ['ok' => bool, 'msg' => string, 'number' => string, 'status' => string]
 */
function tb_set_ticket_status(int $ticketId, int $statusId, string $who): array {
    $ticket = Ticket::lookup($ticketId);
    if (!$ticket) {
        return ['ok' => false, 'msg' => "Ticket-ID $ticketId nicht gefunden.", 'number' => '', 'status' => ''];
    }
    $status = TicketStatus::lookup($statusId);
    if (!$status) {
        return ['ok' => false, 'msg' => "Status $statusId existiert nicht.", 'number' => $ticket->getNumber(), 'status' => ''];
    }
    $current = $ticket->getStatus();
    if ($current && (int)$current->getId() === $statusId) {
        return ['ok' => false, 'msg' => "Ticket #{$ticket->getNumber()} ist bereits '{$status->getName()}'.", 'number' => $ticket->getNumber(), 'status' => $status->getName()];
    }

    $note   = "Per Telegram von $who auf „{$status->getName()}“ gesetzt.";
    $errors = [];
    // $set_closing_agent = false: es gibt keinen angemeldeten Agenten.
    // $force_close = false: Pflichtfelder und offene Aufgaben gelten wie im Panel.
    if (!$ticket->setStatus($status, $note, $errors, false, false)) {
        $why = $errors ? implode(' ', array_map('strip_tags', (array)$errors)) : 'osTicket hat den Statuswechsel abgelehnt (Pflichtfelder leer oder offene Aufgaben?).';
        return ['ok' => false, 'msg' => $why, 'number' => $ticket->getNumber(), 'status' => $status->getName()];
    }
    // setStatus() legt mit $note bereits eine interne Notiz ('Status Changed')
    // an, fuer den Kunden unsichtbar. Eine zweite waere doppelt.

    return ['ok' => true, 'msg' => '', 'number' => $ticket->getNumber(), 'status' => $status->getName()];
}
