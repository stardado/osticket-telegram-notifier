#!/bin/bash
# End-to-End-Test der Schliessen-Buttons gegen eine ECHTE osTicket-Installation.
#
# Legt ueber osTickets eigene Ticket::create() ein Testticket an (ohne
# Autoresponder, ohne Agenten-Alarm), simuliert Button-Klicks ueber den
# Mock-Server, prueft Status und interne Notiz in der Datenbank und loescht
# das Ticket wieder. Waehrend des Anlegens wird die Sperre von
# telegram_notify.php gehalten und dessen Stand vorgerueckt, damit das
# Testticket NICHT in der Telegram-Gruppe gemeldet wird.
#
# Schreibt in die Produktivdatenbank (ein Ticket, das wieder geloescht wird).
# Deshalb nicht Teil von run_tests.sh, sondern bewusst separat aufzurufen:
#
#   sudo tests/e2e_close.sh /opt/ticketbot/ticketbot_config.php
set -u
REAL_CONFIG="${1:-/opt/ticketbot/ticketbot_config.php}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; chmod 777 "$T"
TID=""
cleanup() {
    kill ${MOCK:-} 2>/dev/null
    if [ -n "$TID" ]; then
        sudo -u www-data php "$T/ost.php" delete "$TID" >/dev/null 2>&1 && echo "Testticket ID $TID geloescht." || echo "WARNUNG: Testticket ID $TID konnte nicht geloescht werden!"
    fi
    rm -rf "$T"
}
trap cleanup EXIT

export MOCK_SCENARIO_FILE="$T/scenario" MOCK_LOG="$T/requests.log" MOCK_UPDATES="$T/updates.json"
echo ok >"$T/scenario"
php -S 127.0.0.1:8089 "$HERE/tests/mock_telegram.php" >"$T/mock.out" 2>&1 & MOCK=$!
sleep 0.5

php -r '
$c = require $argv[1];
$c["api_base"]="http://127.0.0.1:8089"; $c["state_dir"]=$argv[2]; $c["log_file"]=$argv[2]."/bot.log";
$c["telegram_token"]="000:TESTTOKEN"; $c["telegram_chat_id"]="-100999"; $c["poll_seconds"]=0;
$c["actions"]=[["label"=>"✅ Gelöst","status_id"=>2],["label"=>"🔒 Geschlossen","status_id"=>3]];
$c["osticket_dir"]=$c["osticket_dir"] ?? "/var/www/osTicket/upload";
file_put_contents($argv[2]."/ticketbot_config.php","<?php\nreturn ".var_export($c,true).";\n");
' "$REAL_CONFIG" "$T"
cp "$HERE/telegram_actions.php" "$HERE/lib.php" "$T/"
OSTDIR=$(php -r '$c=require $argv[1]; echo $c["osticket_dir"] ?? "/var/www/osTicket/upload";' "$REAL_CONFIG")

# Helfer, der osTicket wie api/cron.php laedt: create | status | delete
cat >"$T/ost.php" <<PHP
<?php
chdir("$OSTDIR/api"); ob_start(); require "api.inc.php"; ob_end_clean();
[\$_, \$cmd, \$arg] = \$argv + [null, null, null];
if (\$cmd === 'create') {
    \$topic = Topic::objects()->first();
    \$vars = ['name' => 'Ticketbot E2E-Test', 'email' => 'ticketbot-e2e@example.com',
              'subject' => '[E2E-TEST] Telegram-Button, wird sofort geloescht',
              'message' => new TextThreadEntryBody('Automatischer Test von tests/e2e_close.sh.'),
              'source' => 'API', 'topicId' => \$topic ? \$topic->getId() : 0, 'ip' => '127.0.0.1'];
    \$errors = [];
    \$t = Ticket::create(\$vars, \$errors, 'api', false, false);
    if (!\$t) { fwrite(STDERR, "create fehlgeschlagen: " . json_encode(\$errors) . "\n"); exit(1); }
    echo \$t->getId();
} elseif (\$cmd === 'status') {
    \$t = Ticket::lookup((int)\$arg); echo \$t ? \$t->getStatus()->getId() . ' ' . \$t->getStatus()->getName() : 'weg';
} elseif (\$cmd === 'delete') {
    \$t = Ticket::lookup((int)\$arg); if (\$t) \$t->delete(); echo Ticket::lookup((int)\$arg) ? 'FEHLER' : 'ok';
}
PHP
chmod 644 "$T/ost.php"

PASS=0; FAIL=0
check() { if eval "$2"; then PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; else FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; tail -3 "$T/bot.log" 2>/dev/null | sed 's/^/       log: /'; fi; }
cq() { printf '[{"update_id":%s,"callback_query":{"id":"cq%s","from":{"id":42,"first_name":"Testa","username":"testa"},"message":{"message_id":7,"chat":{"id":-100999}},"data":"%s"}}]' "$1" "$1" "$2" >"$T/updates.json"; }
runact() { sudo -u www-data php "$T/telegram_actions.php" >"$T/stdout" 2>"$T/stderr"; echo $? >"$T/exit"; }
dbq() { php -r '$c=require $argv[1]; $m=new mysqli($c["db_host"],$c["db_user"],$c["db_pass"],$c["db_name"]); echo $m->query($argv[2])->fetch_row()[0] ?? "";' "$REAL_CONFIG" "$1"; }
P=$(php -r '$c=require $argv[1]; echo $c["db_prefix"] ?? "ost_";' "$REAL_CONFIG")
LIVE_STATE=/var/lib/ticketbot/last_ticket_id.txt

echo "[E1] Testticket anlegen (Notify-Sperre gehalten, Stand vorgerueckt)"
TID=$(flock -x /var/lib/ticketbot/ticketbot.lock -c "
    id=\$(sudo -u www-data php '$T/ost.php' create) || exit 1
    cur=\$(cat '$LIVE_STATE' 2>/dev/null || echo 0)
    if [ \"\$id\" -gt \"\$cur\" ]; then printf '%s' \"\$id\" > '$LIVE_STATE.tmp' && chown www-data:www-data '$LIVE_STATE.tmp' && mv '$LIVE_STATE.tmp' '$LIVE_STATE'; fi
    echo \"\$id\"")
check "Ticket angelegt (ID $TID)"                       '[ -n "$TID" ] && [ "$TID" -gt 0 ]'
check "Notify-Stand deckt Testticket ab"                '[ "$(cat $LIVE_STATE)" -ge "$TID" ]'
check "Status ist Offen"                                '[ "$(sudo -u www-data php "$T/ost.php" status "$TID")" = "1 Offen" ]'

echo "[E2] Klick auf 'Gelöst'"
cq 900 "close:$TID:2"; runact
check "Exit 0"                                          '[ "$(cat "$T/exit")" = 0 ]'
check "Status in DB = 2"                                '[ "$(dbq "SELECT status_id FROM ${P}ticket WHERE ticket_id=$TID")" = 2 ]'
check "interne Notiz mit Telegram-Hinweis vorhanden"    '[ "$(dbq "SELECT COUNT(*) FROM ${P}thread_entry e JOIN ${P}thread t ON e.thread_id=t.id WHERE t.object_id=$TID AND t.object_type=\"T\" AND e.type=\"N\" AND e.body LIKE \"%Telegram%\"")" -ge 1 ]'
check "Ereignis 'closed' im Verlauf"                    '[ "$(dbq "SELECT COUNT(*) FROM ${P}thread_event ev JOIN ${P}thread t ON ev.thread_id=t.id JOIN ${P}event e ON ev.event_id=e.id WHERE t.object_id=$TID AND t.object_type=\"T\" AND e.name=\"closed\"")" -ge 1 ]'
check "answerCallbackQuery mit Bestaetigung"            'grep -q "answerCallbackQuery" "$MOCK_LOG" && grep -q "→ Gelöst" "$MOCK_LOG"'
check "Buttons durch Erledigt-Zeile ersetzt"            'grep -q "editMessageReplyMarkup" "$MOCK_LOG" && grep -q "noop" "$MOCK_LOG"'
check "Rueckmeldung als Antwort in der Gruppe"          'grep -q "reply_to_message_id\":\"7\"" "$MOCK_LOG" && grep -q "→ Gelöst" "$MOCK_LOG"'
check "genau EINE Notiz, nicht doppelt"                 '[ "$(dbq "SELECT COUNT(*) FROM ${P}thread_entry e JOIN ${P}thread t ON e.thread_id=t.id WHERE t.object_id=$TID AND t.object_type=\"T\" AND e.type=\"N\"")" = 1 ]'

echo "[E3] Nochmal 'Gelöst' klicken"
cq 901 "close:$TID:2"; runact
check "Hinweis 'bereits', kein Fehler-Exit"             'grep -q "bereits" "$T/bot.log" && [ "$(cat "$T/exit")" = 0 ]'
check "Fehlgrund als Nachricht in der Gruppe"           'grep -q "⚠️ Testa: Ticket" "$MOCK_LOG"'

echo "[E4] Klick auf 'Geschlossen'"
cq 902 "close:$TID:3"; runact
check "Status in DB = 3"                                '[ "$(dbq "SELECT status_id FROM ${P}ticket WHERE ticket_id=$TID")" = 3 ]'

echo "[E5] Testticket loeschen"
R=$(sudo -u www-data php "$T/ost.php" delete "$TID"); [ "$R" = ok ] && TID_DEL=$TID && TID=""
check "geloescht"                                       '[ "$R" = ok ] && [ -z "$(dbq "SELECT ticket_id FROM ${P}ticket WHERE ticket_id=$TID_DEL")" ]'

echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" -eq 0 ]
