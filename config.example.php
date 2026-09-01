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

    // Basis-URL fuer Ticket-Links, die Ticket-ID wird angehaengt
    'ticket_url_base' => 'https://ticket.example.org/scp/tickets.php?id=',
];
