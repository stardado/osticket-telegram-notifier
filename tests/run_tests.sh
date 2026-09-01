#!/bin/bash
# Funktionstests gegen den Mock-Server. Beruehrt weder die echte Telegram-Gruppe
# noch den Live-State: eigenes State-Verzeichnis, eigenes Log, eigene Config.
# Die osTicket-Datenbank wird nur lesend benutzt.
#
# Aufruf:  tests/run_tests.sh /pfad/zur/echten/ticketbot_config.php
set -u
REAL_CONFIG="${1:-/opt/ticketbot/ticketbot_config.php}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"
trap 'kill $MOCK 2>/dev/null; rm -rf "$T"' EXIT

export MOCK_SCENARIO_FILE="$T/scenario" MOCK_LOG="$T/requests.log" MOCK_UPDATES="$T/updates.json"
php -S 127.0.0.1:8089 "$HERE/tests/mock_telegram.php" >"$T/mock.out" 2>&1 &
MOCK=$!
sleep 0.5

# Test-Config: echte DB-Zugangsdaten, alles andere umgebogen
php -r '
$c = require $argv[1];
$c["api_base"]  = "http://127.0.0.1:8089";
$c["state_dir"] = $argv[2];
$c["log_file"]  = $argv[2] . "/bot.log";
$c["telegram_token"] = "000:TESTTOKEN";
$c["telegram_chat_id"] = "-100999"; $c["poll_seconds"] = 0;
$c["actions"] = [["label"=>"Geloest","status_id"=>2],["label"=>"Geschlossen","status_id"=>3]];
$c["osticket_dir"] = $argv[2] . "/kein-osticket";   // darf in diesen Tests nie geladen werden
file_put_contents($argv[2] . "/ticketbot_config.php", "<?php\nreturn " . var_export($c, true) . ";\n");
' "$REAL_CONFIG" "$T"
# Script + Test-Config zusammen in ein Testverzeichnis, weil __DIR__ die Config bestimmt
cp "$HERE/telegram_notify.php" "$HERE/telegram_actions.php" "$HERE/lib.php" "$T/"
MAXID=$(php -r '$c=require $argv[1]; $m=new mysqli($c["db_host"],$c["db_user"],$c["db_pass"],$c["db_name"]); echo $m->query("SELECT MAX(ticket_id) m FROM ".($c["db_prefix"]??"ost_")."ticket")->fetch_assoc()["m"];' "$REAL_CONFIG")

PASS=0; FAIL=0
run()   { php "$T/telegram_notify.php" >"$T/stdout" 2>"$T/stderr"; echo $? >"$T/exit"; }
runact(){ php "$T/telegram_actions.php" >"$T/stdout" 2>"$T/stderr"; echo $? >"$T/exit"; }
offset(){ cat "$T/update_offset.txt" 2>/dev/null || echo 0; }
cq()    { # update_id chat_id data
  printf '[{"update_id":%s,"callback_query":{"id":"cq%s","from":{"id":42,"first_name":"Testa","username":"testa"},"message":{"message_id":7,"chat":{"id":%s}},"data":"%s"}}]' "$1" "$1" "$2" "$3" >"$T/updates.json"; }
state() { cat "$T/last_ticket_id.txt" 2>/dev/null || echo "(keine)"; }
reqs()  { [ -f "$MOCK_LOG" ] && wc -l <"$MOCK_LOG" || echo 0; }
reset() { echo "$1" >"$T/scenario"; rm -f "$MOCK_LOG" "$MOCK_LOG.counter"; : >"$T/bot.log"; }
check() { # name  bedingung
    if eval "$2"; then PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"
    else FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; sed 's/^/       log: /' "$T/bot.log" | tail -4; fi
}

echo "Hoechste Ticket-ID in der DB: $MAXID"
echo

echo "[1] Erststart ohne State"
reset ok; rm -f "$T/last_ticket_id.txt"; run
check "setzt Startpunkt auf MAX(ticket_id)"            '[ "$(state)" = "$MAXID" ]'
check "sendet dabei nichts"                             '[ "$(reqs)" = 0 ]'

echo "[2] Normalbetrieb: 3 neue Tickets, alles ok"
reset ok; echo $((MAXID-3)) >"$T/last_ticket_id.txt"; run
check "3 Nachrichten gesendet"                          '[ "$(reqs)" = 3 ]'
check "State auf MAX"                                   '[ "$(state)" = "$MAXID" ]'
check "MarkdownV2 verwendet"                            'grep -q "\"parse_mode\":\"MarkdownV2\"" "$MOCK_LOG"'
check "Umlaute unversehrt oder nicht vorhanden"         '! grep -q "verfgbar\|fr \|gendert" "$MOCK_LOG"'

echo "[3] Rate-Limit 429"
reset 429; echo $((MAXID-3)) >"$T/last_ticket_id.txt"; run
check "State rueckt NICHT weiter"                       '[ "$(state)" = "$((MAXID-3))" ]'
check "nur ein Versuch, dann Abbruch"                   '[ "$(reqs)" = 1 ]'

echo "[4] Telegram-Stoerung 500"
reset 500; echo $((MAXID-2)) >"$T/last_ticket_id.txt"; run
check "State rueckt NICHT weiter"                       '[ "$(state)" = "$((MAXID-2))" ]'

echo "[5] Token widerrufen 401"
reset 401; echo $((MAXID-2)) >"$T/last_ticket_id.txt"; run
check "State rueckt NICHT weiter (Konfigfehler)"        '[ "$(state)" = "$((MAXID-2))" ]'

echo "[6] Bot aus Gruppe entfernt 403"
reset 403; echo $((MAXID-2)) >"$T/last_ticket_id.txt"; run
check "State rueckt NICHT weiter (Konfigfehler)"        '[ "$(state)" = "$((MAXID-2))" ]'

echo "[7] Chat-ID falsch (400 chat not found)"
reset 400chat; echo $((MAXID-2)) >"$T/last_ticket_id.txt"; run
check "State rueckt NICHT weiter (Konfigfehler)"        '[ "$(state)" = "$((MAXID-2))" ]'

echo "[8] HTML statt JSON (Proxy-Fehlerseite)"
reset html; echo $((MAXID-2)) >"$T/last_ticket_id.txt"; run
check "State rueckt NICHT weiter"                       '[ "$(state)" = "$((MAXID-2))" ]'

echo "[9] MarkdownV2-Parsefehler 400"
reset 400parse; echo $((MAXID-1)) >"$T/last_ticket_id.txt"; run
check "Klartext-Fallback gesendet (2 Requests)"         '[ "$(reqs)" = 2 ]'
check "zweiter Request ohne parse_mode"                 '! tail -1 "$MOCK_LOG" | grep -q parse_mode'
check "State rueckt weiter (zugestellt)"                '[ "$(state)" = "$MAXID" ]'

echo "[10] Haengende Verbindung"
reset hang; echo $((MAXID-1)) >"$T/last_ticket_id.txt"
START=$(date +%s); run; DUR=$(( $(date +%s) - START ))
check "Lauf endet durch Timeout (<60s, war ${DUR}s)"    '[ "$DUR" -lt 60 ]'
check "State rueckt NICHT weiter"                       '[ "$(state)" = "$((MAXID-1))" ]'
# Mock-Prozess haengt noch im sleep, neu starten
kill $MOCK 2>/dev/null; wait $MOCK 2>/dev/null
php -S 127.0.0.1:8089 "$HERE/tests/mock_telegram.php" >"$T/mock.out" 2>&1 & MOCK=$!; sleep 0.5

echo "[11] Falsches DB-Passwort"
reset ok; sed -i "s/'db_pass' => '[^']*'/'db_pass' => 'falsch'/" "$T/ticketbot_config.php"; run
check "Exit 1 und Meldung im Log, keine Exception"      '[ "$(cat "$T/exit")" = 1 ] && grep -q "DB-Verbindung" "$T/bot.log" && ! grep -q "Uncaught" "$T/stderr"'
check "Alarm per Telegram gesendet"                     'grep -q "Ticketbot-Fehler \[notify:db\]" "$MOCK_LOG"'
run
check "zweiter Lauf: kein zweiter Alarm (Cooldown)"     '[ "$(grep -c "Ticketbot-Fehler" "$MOCK_LOG")" = 1 ]'
php -r '$c=require $argv[1]; $r=require $argv[2]; $c["db_pass"]=$r["db_pass"]; file_put_contents($argv[1],"<?php\nreturn ".var_export($c,true).";\n");' "$T/ticketbot_config.php" "$REAL_CONFIG"

echo "[12] Fehlender Config-Schluessel"
reset ok; sed -i "/'telegram_chat_id'/d" "$T/ticketbot_config.php"; run
check "Exit 1 und Schluessel im Log benannt"            '[ "$(cat "$T/exit")" = 1 ] && grep -q "telegram_chat_id" "$T/bot.log"'
sed -i "s/'telegram_token' => /'telegram_chat_id' => '-100999',\n  'telegram_token' => /" "$T/ticketbot_config.php"

echo "[13] State-Verzeichnis fehlt"
reset ok; sed -i "s|'state_dir' => '[^']*'|'state_dir' => '$T/gibtsnicht'|" "$T/ticketbot_config.php"; run
check "klare Meldung, nicht 'anderer Lauf aktiv'"       'grep -q "Verzeichnis" "$T/bot.log" && ! grep -q "anderer Lauf" "$T/bot.log"'
sed -i "s|'state_dir' => '[^']*'|'state_dir' => '$T'|" "$T/ticketbot_config.php"

echo "[14] Sperre wird gehalten"
reset ok; echo $((MAXID-1)) >"$T/last_ticket_id.txt"
flock -x "$T/ticketbot.lock" -c 'sleep 2' & FL=$!; sleep 0.3; run; wait $FL
check "Lauf uebersprungen, State unveraendert"          'grep -q "anderer Lauf" "$T/bot.log" && [ "$(state)" = "$((MAXID-1))" ]'

echo "[15] Link-URL mit Sonderzeichen"
reset ok; echo $((MAXID-1)) >"$T/last_ticket_id.txt"
sed -i "s|'ticket_url_base' => '[^']*'|'ticket_url_base' => 'https://x.example/t(1)/?id='|" "$T/ticketbot_config.php"; run
check "')' im Link escaped"                            'grep -qF "t(1\\\\)\\/" "$MOCK_LOG"'
check "Nachricht zugestellt"                            '[ "$(state)" = "$MAXID" ]'
sed -i "s|'ticket_url_base' => '[^']*'|'ticket_url_base' => 'https://ticket.example.org/scp/tickets.php?id='|" "$T/ticketbot_config.php"

echo "[16] State-Datei wird atomar geschrieben"
reset ok; echo $((MAXID-1)) >"$T/last_ticket_id.txt"; run
check "keine .tmp-Datei zurueckgelassen"                '[ ! -e "$T/last_ticket_id.txt.tmp" ]'
check "Inhalt korrekt"                                  '[ "$(state)" = "$MAXID" ]'

echo "[17] Buttons unter der Nachricht"
reset ok; echo $((MAXID-1)) >"$T/last_ticket_id.txt"; run
check "reply_markup mit close:<id>:2 und :3"           'grep -q "close:$MAXID:2" "$MOCK_LOG" && grep -q "close:$MAXID:3" "$MOCK_LOG"'

echo "[18] Klick aus fremdem Chat"
reset ok; rm -f "$T/update_offset.txt"; cq 500 -100777 "close:$MAXID:2"; runact
check "abgelehnt, osTicket nicht geladen, Offset 501"  'grep -q "fremdem Chat" "$T/bot.log" && [ "$(offset)" = 501 ] && [ "$(cat "$T/exit")" = 0 ]'

echo "[19] noop-Klick und unbekannte Aktion"
reset ok; cq 502 -100999 "noop"; runact
check "noop still verarbeitet, Offset 503"             '[ "$(offset)" = 503 ] && ! grep -q "❌" "$T/bot.log"'
cq 503 -100999 "irgendwas"; runact
check "unbekannte Aktion geloggt, Offset 504"          'grep -q "Unbekannte Aktion" "$T/bot.log" && [ "$(offset)" = 504 ]'

echo "[20] Nicht konfigurierter Status"
reset ok; cq 505 -100999 "close:$MAXID:5"; runact
check "Status 5 abgelehnt ohne osTicket-Zugriff"       'grep -q "nicht konfiguriert" "$T/bot.log" && [ "$(offset)" = 506 ]'

echo "[21] getUpdates mit widerrufenem Token"
reset 401; cq 507 -100999 "noop"; runact
check "Exit 1, Offset unveraendert"                     '[ "$(cat "$T/exit")" = 1 ] && [ "$(offset)" = 506 ] && grep -q "401" "$T/bot.log"'

echo "[22] Klick auf erlaubten Status ohne osTicket-Installation"
reset ok; cq 508 -100999 "close:$MAXID:2"; runact
check "klare Meldung zu osticket_dir, Exit 1"          'grep -q "osticket_dir" "$T/bot.log" && [ "$(cat "$T/exit")" = 1 ]'

echo "[23] Entwarnung nach behobenem Fehler"
reset ok; echo "$MAXID" >"$T/last_ticket_id.txt"
printf '{"notify:db":%s}' "$(( $(date +%s) - 120 ))" >"$T/alerts.json"; run
check "Entwarnung gesendet, Alarm geloescht"            'grep -q "wieder in Ordnung: notify:db" "$MOCK_LOG" && [ "$(cat "$T/alerts.json")" = "{}" ]'

echo "[24] Haengender Prozess"
reset ok; rm -f "$T/ticketbot.skips"
flock -x "$T/ticketbot.lock" -c 'sleep 4' & FL=$!; sleep 0.3
for i in 1 2 3 4 5; do run; done; wait $FL
check "nach 5 Skips ein Alarm, nicht fuenf"             '[ "$(grep -c "Ticketbot-Fehler \[ticketbot:haengt\]" "$MOCK_LOG")" = 1 ]'
run
check "Skip-Zaehler nach freiem Lauf zurueckgesetzt"    '[ ! -e "$T/ticketbot.skips" ]'

echo "[25] Alarm bei getUpdates-Konflikt (actions)"
reset 409; cq 600 -100999 "noop"; runact
check "409 alarmiert"                                   'grep -q "Ticketbot-Fehler \[actions:getupdates\]" "$MOCK_LOG"'

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" -eq 0 ]
