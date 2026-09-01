# osTicket Telegram Notifier

Meldet neu eingegangene osTicket-Tickets in eine Telegram-Gruppe.

Kein osTicket-Plugin, sondern ein eigenstaendiges PHP-Script, das per Cron
laeuft und die osTicket-Datenbank direkt abfragt. Dadurch ueberlebt es
osTicket-Updates unbeschadet und muss nicht gegen die Plugin-API gepflegt
werden.

## Wie es arbeitet

Jeder Lauf liest die zuletzt gemeldete Ticket-ID aus einer State-Datei, holt
alle Tickets mit hoeherer ID und schickt fuer jedes eine Nachricht in die
konfigurierte Gruppe. Danach wird der Stand fortgeschrieben.

Eine Nachricht sieht so aus:

```
📬 Neues Ticket eingegangen!
🆔 Ticket-ID: #303859

📝 Betreff: BookStack Update verfügbar: v26.05.2 -> v26.05.3

👤 Von: Max Mustermann 🕒 Zeit: 01.09.2026 09:28
```

Titel und Ticket-ID sind mit dem Ticket im Agenten-Panel verlinkt.

## Tickets aus Telegram heraus schliessen

Optional erscheinen unter jeder Benachrichtigung Buttons, zum Beispiel
„✅ Gelöst" und „🔒 Geschlossen". Ein Klick setzt den Ticketstatus - ohne das
Ticket im Agenten-Panel oeffnen zu muessen.

- Der Status wird ueber **osTickets eigene Klassen** gesetzt
  (`Ticket::setStatus()`), nicht per SQL. Der Ereignis-Eintrag im Verlauf
  entsteht wie bei einem Klick im Panel, Pflichtfelder und offene Aufgaben
  werden geprueft; scheitert das, bekommt der Klickende die Begruendung
  als Telegram-Hinweis.
- Zusaetzlich entsteht eine **interne Notiz** („Per Telegram von … auf
  „Gelöst" gesetzt"), fuer den Kunden unsichtbar.
- Nach dem Statuswechsel werden die Buttons durch eine Erledigt-Zeile mit
  Name und Uhrzeit ersetzt, damit niemand doppelt klickt.
- **Berechtigung ist die Gruppenmitgliedschaft.** Wer in der Gruppe ist, darf
  klicken. Klicks aus anderen Chats werden abgewiesen. Wer feinere Kontrolle
  braucht, sollte die Gruppe entsprechend klein halten.

Dafuer laeuft ein zweites Script, `telegram_actions.php`, ebenfalls minuetlich
per Cron. Es holt die Klicks per `getUpdates` ab (nur `callback_query`, der
Privacy-Mode des Bots spielt keine Rolle) und braucht `osticket_dir` sowie
`actions` in der Konfiguration. Ist `actions` leer, gibt es keine Buttons und
das Script beendet sich sofort. Ein Klick wird spaetestens nach einer Minute
verarbeitet.

Fuer den Bot darf **kein Webhook** gesetzt sein, sonst liefert `getUpdates`
einen 409-Konflikt.

**Warum nicht ueber die osTicket-REST-API?** Die
[offizielle API](https://docs.osticket.com/en/latest/Developer%20Documentation/API%20Docs.html)
kann nur Tickets *anlegen* (`POST /api/tickets.json`) und den Cron anstossen;
Statuswechsel, Notizen oder das Abrufen eines Tickets sind nicht vorgesehen
(„For now, only ticket creation is supported"). Auch in osTicket 1.18 sind
das die einzigen Routen in `api/http.php`. Deshalb laedt
`telegram_actions.php` osTicket lokal so, wie es `api/cron.php` selbst tut,
und nutzt dieselben Klassen wie das Agenten-Panel. Das setzt voraus, dass der
Bot auf demselben Server laeuft wie osTicket.

## Voraussetzungen

- osTicket mit MySQL/MariaDB
- PHP CLI mit den Erweiterungen `mysqli`, `curl` und `mbstring`
- Ein Telegram-Bot ([@BotFather](https://t.me/BotFather)), als Mitglied der
  Zielgruppe hinzugefuegt

## Installation

```bash
git clone <repo-url> /opt/ticketbot
cd /opt/ticketbot

# Secret-Schutz aktivieren (siehe unten)
git config core.hooksPath .githooks

# Konfiguration anlegen
cp config.example.php ticketbot_config.php
chown root:www-data ticketbot_config.php
chmod 640 ticketbot_config.php
$EDITOR ticketbot_config.php

# Verzeichnis fuer State- und Sperrdatei
install -d -o www-data -g www-data -m 755 /var/lib/ticketbot

# Log-Rotation
cp deploy/ticketbot.logrotate /etc/logrotate.d/ticketbot
```

Cron-Eintrag ergaenzen mit `crontab -u www-data -e`:

```cron
*/1 * * * * /usr/bin/php /opt/ticketbot/telegram_notify.php
*/1 * * * * /usr/bin/php /opt/ticketbot/telegram_actions.php
```

Die zweite Zeile ist nur noetig, wenn die Schliessen-Buttons genutzt werden.

Der Bot laeuft bewusst als `www-data`: dieser Benutzer darf die Konfiguration
lesen und die State-Datei schreiben. Als DB-Benutzer muss ein eigener Account
eingetragen werden, nicht `root` -- `root@localhost` scheitert unter `www-data`
mit "Access denied".

Beim ersten Lauf findet der Bot keinen Stand vor. Er setzt den Startpunkt dann
auf die hoechste vorhandene Ticket-ID und sendet nichts. Gemeldet werden also
nur Tickets, die ab der Installation eingehen.

## Konfiguration

Alle Werte stehen in `ticketbot_config.php`, siehe `config.example.php`.
Der Schalter `debug` schreibt zusaetzlich den vollstaendigen Text jeder
Nachricht ins Log und sollte im Regelbetrieb `false` bleiben.

## Tests

`tests/run_tests.sh` fuehrt Funktionstests gegen einen lokalen Nachbau der
Telegram-API aus (`tests/mock_telegram.php`). Die echte Gruppe wird dabei nie
angesprochen, State und Log liegen in einem Temporaerverzeichnis, die
osTicket-Datenbank wird nur lesend benutzt.

```bash
tests/run_tests.sh /opt/ticketbot/ticketbot_config.php
```

`tests/e2e_close.sh` prueft die Schliessen-Buttons gegen die echte
osTicket-Installation: es legt ein Testticket an (ohne Autoresponder, ohne
Agenten-Alarm, ohne Telegram-Meldung), simuliert die Klicks, prueft Status,
Ereignis und Notiz in der Datenbank und loescht das Ticket wieder. Weil es in
die Produktivdatenbank schreibt, ist es bewusst nicht Teil von
`run_tests.sh`.

Abgedeckt sind unter anderem Erststart, Rate-Limit, Telegram-Stoerung,
widerrufener Token, Bot aus der Gruppe entfernt, HTML statt JSON,
MarkdownV2-Parsefehler mit Klartext-Fallback, haengende Verbindung, falsches
DB-Passwort, unvollstaendige Konfiguration und parallele Laeufe.

## Dateien zur Laufzeit

| Pfad | Zweck |
|---|---|
| `/var/lib/ticketbot/last_ticket_id.txt` | zuletzt gemeldete Ticket-ID |
| `/var/lib/ticketbot/ticketbot.lock` | Sperre gegen parallele Laeufe |
| `/var/lib/ticketbot/update_offset.txt` | zuletzt verarbeitetes Telegram-Update (Buttons) |
| `/var/lib/ticketbot/actions.lock` | Sperre fuer `telegram_actions.php` |
| `/var/log/ticketbot.log` | Protokoll |

Die State-Datei liegt bewusst unter `/var/lib` und nicht in `/tmp`: dort ginge
sie beim Reboot verloren, woraufhin die gesamte Ticket-Historie erneut in die
Gruppe laufen wuerde.

## Betriebsverhalten

- **Parallele Laeufe** werden per `flock` verhindert. Cron startet minuetlich,
  ein Lauf mit Rueckstand dauert laenger -- ohne Sperre wuerden mehrere
  Prozesse dieselben Tickets senden.
- **Drosselung**: eine Sekunde Abstand zwischen Nachrichten, hoechstens 20 pro
  Lauf. Telegram begrenzt Gruppen auf etwa 20 Nachrichten pro Minute.
- **Voruebergehende Fehler** (HTTP 429, 5xx, Netzwerkfehler) brechen den Lauf
  ab, ohne den Stand fortzuschreiben. Der naechste Lauf setzt an derselben
  Stelle an, es geht keine Benachrichtigung verloren.
- **Nur nachrichtenspezifische Fehler** (Telegram lehnt genau diese eine
  Nachricht ab) ruecken den Stand weiter, damit ein einzelnes unzustellbares
  Ticket die Warteschlange nicht blockiert. Vorher wird die Nachricht einmal
  als Klartext ohne Formatierung erneut versucht.
- **Konfigurationsfehler** (Token widerrufen, Bot aus der Gruppe entfernt,
  falsche Chat-ID) halten den Lauf an und werden im Log als solche markiert.
  Kein Ticket geht verloren, bis der Fehler behoben ist.

## Zugangsdaten

`ticketbot_config.php` steht in `.gitignore` und gehoert nie ins Repository.

Zusaetzlich blockiert `.githooks/pre-commit` Commits, die einen Bot-Token, eine
Chat-ID, einen internen Hostnamen oder ein DB-Passwort enthalten. Der Hook ist
erst nach `git config core.hooksPath .githooks` aktiv -- dieser Schritt gehoert
zur Installation.

## Lizenz

Copyright (C) 2025-2026 Denis Apel (stardado)

GNU General Public License v3.0, siehe [LICENSE](LICENSE).
