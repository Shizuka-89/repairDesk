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
$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
require_once __DIR__ . '/includes/header.php';

$db = getDB();

// Statistiken
$stats = [
    'offen' => $db->query("SELECT COUNT(*) FROM tickets WHERE status = 'offen'")->fetchColumn(),
    'in_bearbeitung' => $db->query("SELECT COUNT(*) FROM tickets WHERE status = 'in_bearbeitung'")->fetchColumn(),
    'warten_auf_kunde' => $db->query("SELECT COUNT(*) FROM tickets WHERE status = 'warten_auf_kunde'")->fetchColumn(),
    'gesamt' => $db->query("SELECT COUNT(*) FROM tickets")->fetchColumn(),
    'kunden' => $db->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
    'heute' => $db->query("SELECT COUNT(*) FROM tickets WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
];

// Letzte Tickets
$recent = $db->query("
    SELECT t.*, CONCAT(COALESCE(c.lastname,''), IF(c.firstname != '' AND c.lastname != '', ' ', ''), COALESCE(c.firstname,'')) as customer_name, te.name as technician_name
    FROM tickets t
    JOIN customers c ON t.customer_id = c.id
    LEFT JOIN technicians te ON t.technician_id = te.id
    ORDER BY t.updated_at DESC
    LIMIT 10
")->fetchAll();
?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="label"><a href="<?= BASE_URL ?>/tickets.php?status=offen" </a>🔵 Offene Tickets</div>
    <div class="value" style="color:#3b82f6"><?= $stats['offen'] ?></div>
  </div>
  <div class="stat-card">
    <div class="label"><a href="<?= BASE_URL ?>/tickets.php?status=in_bearbeitung" </a>🟡 In Bearbeitung</div>
    <div class="value" style="color:#f59e0b"><?= $stats['in_bearbeitung'] ?></div>
  </div>
  <div class="stat-card">
    <div class="label"><a href="<?= BASE_URL ?>/tickets.php?status=warten_auf_kunde" </a>🟣 Wartet auf Kunde</div>
    <div class="value" style="color:#8b5cf6"><?= $stats['warten_auf_kunde'] ?></div>
  </div>
  <div class="stat-card">
    <div class="label"><a href="<?= BASE_URL ?>/tickets.php" </a>📋 Gesamt Tickets</div>
    <div class="value"><?= $stats['gesamt'] ?></div>
  </div>
  <div class="stat-card">
    <div class="label"><a href="<?= BASE_URL ?>/customers.php" </a>👥 Kunden</div>
    <div class="value"><?= $stats['kunden'] ?></div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3>🕐 Zuletzt aktualisierte Tickets</h3>
    <a href="<?= BASE_URL ?>/ticket_new.php" class="btn btn-primary btn-sm">➕ Neues Ticket</a>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Ticket-Nr.</th>
          <th>Kunde</th>
          <th>Titel</th>
          <th>Techniker</th>
          <th>Status</th>
          <th>Priorität</th>
          <th>Aktualisiert</th>
          <th>Aktion</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $t): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $t['id'] ?>" style="font-weight:600;font-family:monospace;"><?= h($t['ticket_number']) ?></a></td>
          <td><a href="<?= BASE_URL ?>/customer_view.php?id=<?= $t['customer_id'] ?>"><?= h($t['customer_name']) ?></a></td>
          <td><?= h($t['title']) ?></td>
          <td><?= h($t['technician_name'] ?? '—') ?></td>
          <td><?= statusLabel($t['status']) ?></td>
          <td><?= priorityLabel($t['priority']) ?></td>
          <td style="font-size:12px;color:#64748b;"><?= formatDate($t['updated_at']) ?></td>
          <td><a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">Öffnen</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
        <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:30px;">Noch keine Tickets vorhanden. <a href="<?= BASE_URL ?>/ticket_new.php">Jetzt erstellen →</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
