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
```

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

## Dateien zur Laufzeit

| Pfad | Zweck |
|---|---|
| `/var/lib/ticketbot/last_ticket_id.txt` | zuletzt gemeldete Ticket-ID |
| `/var/lib/ticketbot/ticketbot.lock` | Sperre gegen parallele Laeufe |
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
- **Dauerhafte Ablehnungen** ruecken den Stand weiter, damit ein einzelnes
  unzustellbares Ticket die Warteschlange nicht blockiert.

## Zugangsdaten

`ticketbot_config.php` steht in `.gitignore` und gehoert nie ins Repository.

Zusaetzlich blockiert `.githooks/pre-commit` Commits, die einen Bot-Token, eine
Chat-ID, einen internen Hostnamen oder ein DB-Passwort enthalten. Der Hook ist
erst nach `git config core.hooksPath .githooks` aktiv -- dieser Schritt gehoert
zur Installation.

## Lizenz

Copyright (C) 2025-2026 stardado

GNU General Public License v3.0, siehe [LICENSE](LICENSE).
