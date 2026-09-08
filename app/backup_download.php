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
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if (!isAdmin()) {
    http_response_code(403);
    exit('Kein Zugriff.');
}

$file = $_GET['file'] ?? '';
$path = safeBackupPath($file);

if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('Backup nicht gefunden.');
}


while (ob_get_level() > 0) {
    ob_end_clean();
}

$name = basename($path);
header('Content-Description: File Transfer');
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($path));

readfile($path);
exit;
