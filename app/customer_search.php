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

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

$db   = getDB();
$like = '%' . $q . '%';

// Suche primär nach Nachname, sekundär auch Vorname / Firma
$stmt = $db->prepare("
    SELECT id, firstname, lastname, company, email, phone
    FROM customers
    WHERE lastname LIKE ?
       OR firstname LIKE ?
       OR company LIKE ?
    ORDER BY lastname ASC, firstname ASC
    LIMIT 20
");
$stmt->execute([$like, $like, $like]);
$rows = $stmt->fetchAll();

// Nur benötigte Felder ausgeben
$result = array_map(fn($r) => [
    'id'        => (int)$r['id'],
    'firstname' => $r['firstname'],
    'lastname'  => $r['lastname'],
    'company'   => $r['company'] ?? '',
    'email'     => $r['email']   ?? '',
    'phone'     => $r['phone']   ?? '',
], $rows);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
