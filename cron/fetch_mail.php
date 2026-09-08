<?php
/**
 * RepairDesk – Ticket & Reparaturverwaltung
 *
 * @copyright  2026 NORIX IT Support und Webdesign e.U. – norix.at
 * @license    Proprietär – Alle Rechte vorbehalten
 * @version    1.4.0
 *
 * Dieses Programm ist urheberrechtlich geschützt.
 * Weitergabe, Vervielfältigung oder Modifikation ohne
 * ausdrückliche schriftliche Genehmigung ist untersagt.
 */

// ================================================================
// E-Mail-Abruf (Cronjob)
//
// Aufruf per Kommandozeile:
//   php /pfad/zu/cron/fetch_mail.php
//
// Oder per URL (Cron-URL beim Hoster):
//   https://ihre-domain.de/cron/fetch_mail.php?key=IHR_CRON_KEY
//
// Der Schlüssel steht unter Einstellungen → E-Mail → E-Mail-Empfang.
// Empfohlenes Intervall: alle 5 Minuten.
// ================================================================

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail_inbox.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    // Schlüssel prüfen (schützt vor fremden Aufrufen)
    $cronKey = getSetting('imap_cron_key', '');
    $given   = $_GET['key'] ?? '';
    if ($cronKey === '' || !hash_equals($cronKey, (string)$given)) {
        http_response_code(403);
        die("Zugriff verweigert. Cron-Schlüssel fehlt oder ist falsch.\n");
    }
}

$result = fetchInboxMails();

if ($result['ok']) {
    echo "OK – " . mailFetchSummary($result) . "\n";
    if (!empty($result['skipped']) || !empty($result['scanned'])) {
        echo "   (angesehen: " . (int)$result['scanned'] . " E-Mail(s))\n";
    }
} else {
    if (!$isCli) http_response_code(500);
    echo "FEHLER: " . $result['error'] . "\n";
}