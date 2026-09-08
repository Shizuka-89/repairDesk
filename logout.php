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
require_once __DIR__ . '/includes/functions.php';
startSession();
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
