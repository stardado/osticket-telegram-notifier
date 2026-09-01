<?php
/**
 * Minimaler Telegram-API-Nachbau fuer Funktionstests.
 *
 * Start:   php -S 127.0.0.1:8089 tests/mock_telegram.php
 * Steuern: echo ok > /tmp/mock_scenario   (Datei per MOCK_SCENARIO_FILE waehlbar)
 *
 * Szenarien:
 *   ok           200, Nachricht "gesendet"
 *   429          Rate-Limit mit retry_after
 *   400parse     Bad Request: can't parse entities (nur bei parse_mode)
 *   400chat      Bad Request: chat not found
 *   401          Unauthorized (Token widerrufen)
 *   403          Forbidden (Bot aus Gruppe entfernt)
 *   500          Internal Server Error
 *   html         HTML-Fehlerseite statt JSON (Proxy/CDN)
 *   hang         antwortet 90 Sekunden nicht
 *
 * Jede Anfrage wird als JSON-Zeile in MOCK_LOG (Default /tmp/mock_requests.log)
 * protokolliert, damit Tests pruefen koennen, was der Bot wirklich gesendet hat.
 */
$scenarioFile = getenv('MOCK_SCENARIO_FILE') ?: '/tmp/mock_scenario';
$logFile      = getenv('MOCK_LOG') ?: '/tmp/mock_requests.log';
$scenario     = trim(@file_get_contents($scenarioFile) ?: 'ok');

file_put_contents($logFile, json_encode([
    'scenario' => $scenario,
    'uri'      => $_SERVER['REQUEST_URI'],
    'post'     => $_POST,
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

static $msgId = 0;
$msgId = (int)(@file_get_contents("$logFile.counter") ?: 0) + 1;
file_put_contents("$logFile.counter", $msgId);

header('Content-Type: application/json');
switch ($scenario) {
    case '429':
        http_response_code(429);
        echo json_encode(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]]);
        break;
    case '400parse':
        if (!empty($_POST['parse_mode'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities: Character '.' is reserved"]);
        } else {
            echo json_encode(['ok' => true, 'result' => ['message_id' => $msgId]]);
        }
        break;
    case '400chat':
        http_response_code(400);
        echo json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']);
        break;
    case '401':
        http_response_code(401);
        echo json_encode(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized']);
        break;
    case '403':
        http_response_code(403);
        echo json_encode(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked from the supergroup chat']);
        break;
    case '500':
        http_response_code(500);
        echo json_encode(['ok' => false, 'error_code' => 500, 'description' => 'Internal Server Error']);
        break;
    case 'html':
        http_response_code(502);
        header('Content-Type: text/html');
        echo "<html><body><h1>502 Bad Gateway</h1></body></html>";
        break;
    case 'hang':
        sleep(90);
        echo json_encode(['ok' => true, 'result' => ['message_id' => $msgId]]);
        break;
    default:
        echo json_encode(['ok' => true, 'result' => ['message_id' => $msgId]]);
}
