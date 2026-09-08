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
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json');

verifyCsrf();

$to = trim($_POST['email'] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Ungueltige E-Mail-Adresse.']);
    exit;
}

$subject = 'Test-E-Mail von ' . getSetting('company_name', COMPANY_NAME);
$html    = '<p>Hallo,</p><p>diese Test-E-Mail bestaetigt, dass deine E-Mail-Einstellungen in <strong>RepairDesk</strong> korrekt konfiguriert sind.</p><p>Alles funktioniert!</p>';

$ok = sendMail($to, $to, $subject, $html);
echo json_encode(['ok' => $ok, 'error' => $ok ? '' : 'E-Mail konnte nicht gesendet werden. Bitte SMTP-Einstellungen pruefen.']);
