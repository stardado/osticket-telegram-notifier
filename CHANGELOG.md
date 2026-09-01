# Changelog

## v1.0.0 – 2026-09-01

Erste versionierte Ausgabe. Der Bot lief seit Juli 2025 als einzelnes,
unversioniertes Script; dieser Stand ist die Aufarbeitung nach einem Vorfall,
bei dem ein Reboot den Zaehlerstand loeschte und die gesamte Ticket-Historie
erneut in die Gruppe lief.

### Neu
- **Tickets aus Telegram schliessen**: Buttons unter jeder Meldung (z. B.
  „Gelöst", „Geschlossen"), Statuswechsel ueber osTickets eigene Klassen mit
  Ereignis und interner Notiz. Reaktion binnen einer Sekunde (Long-Polling),
  Rueckmeldung als Popup und als Antwort in der Gruppe.
- **Selbstueberwachung**: DB-Ausfall, haengender Prozess, unzustellbares
  Ticket u. a. werden per Telegram gemeldet – hoechstens einmal pro Stunde je
  Fehlerart, mit Entwarnung.
- **Testsuite**: 42 Funktionstests gegen einen Telegram-Mock, dazu ein
  End-to-End-Test gegen die echte osTicket-Installation.
- Tabellenpraefix (`db_prefix`), Zeitzone (`timezone`), Alarm-Chat
  (`alert_chat_id`) und Debug-Log konfigurierbar.

### Behoben
- Umlaute wurden aus jeder Nachricht entfernt (Regex ohne `/u`).
- Sichtbare Backslashes durch falschen Markdown-Modus (jetzt MarkdownV2 mit
  Klartext-Fallback).
- Uhrzeiten standen in Server-UTC statt in osTickets Zeitzone.
- Tickets gingen bei Sendefehlern still verloren; der Zaehler rueckte auch bei
  Rate-Limit, widerrufenem Token oder Proxy-Fehlerseite weiter.
- Parallele Cron-Laeufe sendeten dieselben Tickets mehrfach (jetzt `flock`).
- Zaehlerstand lag in `/tmp` und ging beim Reboot verloren; ohne Stand wird
  jetzt nichts nachgesendet, sondern auf die hoechste Ticket-ID gesetzt.
- Zaehlerstand wird atomar geschrieben.
- DB-Fehler wurden auf PHP 8.1+ nicht geloggt (mysqli wirft Exceptions).
- Keine cURL-Timeouts: eine haengende Verbindung hielt die Sperre dauerhaft.
- `utf8_encode()` (deprecated) entfernt.
