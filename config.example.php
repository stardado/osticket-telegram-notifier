<?php
/**
 * Vorlage fuer ticketbot_config.php
 *
 * Einrichtung:
 *   cp config.example.php ticketbot_config.php
 *   chown root:www-data ticketbot_config.php
 *   chmod 640 ticketbot_config.php
 *
 * ticketbot_config.php steht in .gitignore und darf NIE committet werden.
 */
return [
    // BotFather -> /mybots -> API Token
    'telegram_token'   => 'DEIN_BOT_TOKEN',

    // Gruppen-Chat-ID, bei Supergruppen mit fuehrendem Minus
    'telegram_chat_id' => '-100XXXXXXXXXX',

    // osTicket-Datenbank. Muss ein User sein, auf den www-data zugreifen darf
    // (NICHT root@localhost - das schlaegt als www-data mit "Access denied" fehl).
    'db_host' => 'localhost',
    'db_user' => 'osticket',
    'db_pass' => 'DEIN_DB_PASSWORT',
    'db_name' => 'osticket',
    // Tabellenpraefix, entspricht TABLE_PREFIX in include/ost-config.php
    'db_prefix' => 'ost_',

    // Schreibt den vollstaendigen Nachrichtentext jeder Meldung ins Log.
    // Nur zur Fehlersuche - laesst das Log sonst schnell auf Hunderte MB wachsen.
    'debug' => false,

    // Basis-URL fuer Ticket-Links, die Ticket-ID wird angehaengt
    'ticket_url_base' => 'https://ticket.example.org/scp/tickets.php?id=',

    // --- Tickets per Button aus Telegram heraus schliessen (optional) ---
    //
    // Unter jeder Benachrichtigung erscheint pro Eintrag ein Button. Ein Klick
    // setzt den Status ueber osTickets eigene Logik (Ereignis im Verlauf,
    // Pflichtfelder und offene Aufgaben werden geprueft) und legt eine interne
    // Notiz an. JEDES Mitglied der Gruppe darf klicken - die Gruppe selbst ist
    // die Berechtigung. Leeres Array = keine Buttons, telegram_actions.php
    // beendet sich dann sofort.
    //
    // status_id: Admin-Panel -> Einstellungen -> Listen -> Ticketstatus, oder
    //            SELECT id, name FROM ost_ticket_status;
    'actions' => [
        ['label' => '✅ Gelöst',      'status_id' => 2],
        ['label' => '🔒 Geschlossen', 'status_id' => 3],
    ],

    // osTicket-Installationsverzeichnis (enthaelt main.inc.php und api/).
    // Nur fuer telegram_actions.php noetig.
    'osticket_dir' => '/var/www/osTicket/upload',
];
