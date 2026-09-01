<?php
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
$lastIdFile     = '/tmp/last_ticket_id.txt';
$logFile        = '/var/log/ticketbot.log';

// Logfunktion
function logMessage($text) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] - $text\n", FILE_APPEND);
}

// Markdown escapen (ohne Bindestriche escapen)
function cleanAndEscape($text) {
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = utf8_encode($text);
    }
    $text = preg_replace('/[[:^print:]]/', '', $text);
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
        '!' => '\!'
        // '-' wird nicht escaped
    ];
    return strtr($text, $replacements);
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
    while ($row = $result->fetch_assoc()) {
        $ticketId     = (int)$row['ticket_id'];
        $ticketNumber = cleanAndEscape($row['number'] ?? '');
        $name         = cleanAndEscape($row['name'] ?? 'Unbekannt');
        $subject      = cleanAndEscape($row['subject'] ?? '(kein Betreff)');

        // Datum formatiert (dd.mm.yyyy HH:MM)
        $dt = new DateTime($row['created'] ?? 'now');
        $created = $dt->format('d.m.Y H:i');

        $link = $ticketBaseURL . $ticketId;

        // Nachricht vorbereiten
                $message = "📬 [Neues Ticket eingegangen!]($link)\n"
                 . "🆔 [Ticket-ID: #$ticketNumber]($link)\n\n"
		 . "📝 Betreff: $subject\n\n"
                 . "👤 Von: $name 🕒 Zeit: $created";

        logMessage("🔍 DEBUG Text für Ticket $ticketNumber:\n$message");

        $url = "https://api.telegram.org/bot$telegramToken/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
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

        if ($response === false || !empty($error)) {
            logMessage("❌ cURL-Fehler bei Telegram für Ticket $ticketNumber: $error");
        } else {
            $json = json_decode($response, true);
            if (!$json || !isset($json['ok']) || $json['ok'] !== true) {
                $desc = $json['description'] ?? 'Unbekannter Fehler';
                logMessage("❌ Telegram-Fehler für Ticket $ticketNumber: $desc");
            } else {
                logMessage("✅ Telegram gesendet für Ticket #$ticketNumber (ID $ticketId), msg_id: " . $json['result']['message_id']);
            }
        }

        file_put_contents($lastIdFile, $ticketId);
    }
} else {
    logMessage("ℹ️ Keine neuen Tickets gefunden (ab ID $last_id).");
}

$conn->close();
