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
$pageTitle = 'Alle Tickets';
$activeMenu = 'tickets';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Filter
$filterStatus = $_GET['status'] ?? '';
$filterTech = (int)($_GET['tech'] ?? 0);
$search = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];

if ($filterStatus) {
    $where[] = 't.status = ?';
    $params[] = $filterStatus;
}
if ($filterTech) {
    $where[] = 't.technician_id = ?';
    $params[] = $filterTech;
}
if ($search) {
    $where[] = '(t.ticket_number LIKE ? OR c.lastname LIKE ? OR c.firstname LIKE ? OR t.title LIKE ? OR t.device_model LIKE ? OR t.serial_number LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s, $s, $s, $s);
}

$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT t.*, CONCAT(COALESCE(c.lastname,''), IF(c.firstname != '' AND c.lastname != '', ', ', ''), COALESCE(c.firstname,'')) as customer_name, te.name as technician_name
    FROM tickets t
    JOIN customers c ON t.customer_id = c.id
    LEFT JOIN technicians te ON t.technician_id = te.id
    WHERE $whereStr
    ORDER BY t.created_at DESC
");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$technicians = $db->query("SELECT id, name FROM technicians ORDER BY name ASC")->fetchAll();

// Zähler für Status-Tabs
$counts = [];
$allStatuses = ['offen', 'in_bearbeitung', 'warten_auf_kunde', 'abgeschlossen', 'storniert'];
$countStmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE status = ?");
foreach ($allStatuses as $s) {
    $countStmt->execute([$s]);
    $counts[$s] = $countStmt->fetchColumn();
}
$counts['alle'] = array_sum($counts);
?>

<!-- Suche & Filter -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:14px 20px;">
    <form method="get" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
      <div style="flex:1;min-width:200px;">
        <label class="form-label">Suche</label>
        <input type="text" name="q" class="form-control" placeholder="Ticket-Nr, Kunde, Gerät, Seriennummer..." value="<?= h($search) ?>">
      </div>
      <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="">Alle</option>
          <option value="offen" <?= $filterStatus === 'offen' ? 'selected' : '' ?>>Offen</option>
          <option value="in_bearbeitung" <?= $filterStatus === 'in_bearbeitung' ? 'selected' : '' ?>>In Bearbeitung</option>
          <option value="warten_auf_kunde" <?= $filterStatus === 'warten_auf_kunde' ? 'selected' : '' ?>>Wartet auf Kunden</option>
          <option value="abgeschlossen" <?= $filterStatus === 'abgeschlossen' ? 'selected' : '' ?>>Abgeschlossen</option>
          <option value="storniert" <?= $filterStatus === 'storniert' ? 'selected' : '' ?>>Storniert</option>
        </select>
      </div>
      <div>
        <label class="form-label">Techniker</label>
        <select name="tech" class="form-control">
          <option value="">Alle</option>
          <?php foreach ($technicians as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $filterTech === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary">Filtern</button>
        <a href="<?= BASE_URL ?>/tickets.php" class="btn btn-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Status Quick-Tabs -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
  <a href="<?= BASE_URL ?>/tickets.php" class="btn btn-sm <?= !$filterStatus ? 'btn-primary' : 'btn-secondary' ?>">Alle (<?= $counts['alle'] ?>)</a>
  <a href="<?= BASE_URL ?>/tickets.php?status=offen" class="btn btn-sm <?= $filterStatus === 'offen' ? 'btn-primary' : 'btn-secondary' ?>">🔵 Offen (<?= $counts['offen'] ?>)</a>
  <a href="<?= BASE_URL ?>/tickets.php?status=in_bearbeitung" class="btn btn-sm <?= $filterStatus === 'in_bearbeitung' ? 'btn-primary' : 'btn-secondary' ?>">🟡 In Bearbeitung (<?= $counts['in_bearbeitung'] ?>)</a>
  <a href="<?= BASE_URL ?>/tickets.php?status=warten_auf_kunde" class="btn btn-sm <?= $filterStatus === 'warten_auf_kunde' ? 'btn-primary' : 'btn-secondary' ?>">🟣 Wartet auf Kunde (<?= $counts['warten_auf_kunde'] ?>)</a>
  <a href="<?= BASE_URL ?>/tickets.php?status=abgeschlossen" class="btn btn-sm <?= $filterStatus === 'abgeschlossen' ? 'btn-primary' : 'btn-secondary' ?>">🟢 Abgeschlossen (<?= $counts['abgeschlossen'] ?>)</a>
  <a href="<?= BASE_URL ?>/ticket_new.php" class="btn btn-primary btn-sm" style="margin-left:auto;">➕ Neues Ticket</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Ticket-Nr.</th>
          <th>Kunde</th>
          <th>Titel / Gerät</th>
          <th>Techniker</th>
          <th>Priorität</th>
          <th>Status</th>
          <th>Erstellt</th>
          <th>KVA / Preis</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tickets as $t): ?>
        <tr>
          <td>
            <a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $t['id'] ?>" style="font-weight:700;font-family:monospace;color:#2563eb;">
              <?= h($t['ticket_number']) ?>
            </a>
          </td>
          <td>
            <a href="<?= BASE_URL ?>/customer_view.php?id=<?= $t['customer_id'] ?>"><?= h($t['customer_name']) ?></a>
          </td>
          <td>
            <span style="font-weight:600;"><?= h($t['title']) ?></span>
            <?php if ($t['device_model']): ?><br><span style="font-size:12px;color:#64748b;"><?= h($t['device_model']) ?></span><?php endif; ?>
          </td>
          <td><?= h($t['technician_name'] ?? '—') ?></td>
          <td><?= priorityLabel($t['priority']) ?></td>
          <td><?= statusLabel($t['status']) ?></td>
          <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?= formatDate($t['created_at']) ?></td>
          <td style="font-size:13px;">
            <?php if ($t['final_price']): ?>
              <span style="color:#065f46;font-weight:600;"><?= number_format($t['final_price'], 2, ',', '.') ?> €</span>
            <?php elseif ($t['estimated_price']): ?>
              <span style="color:#92400e;">KVA: <?= number_format($t['estimated_price'], 2, ',', '.') ?> €</span>
            <?php else: ?>
              <span style="color:#94a3b8;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">Öffnen</a>
            <a href="<?= BASE_URL ?>/ticket_delete.php?id=<?= $t['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Möchten Sie dieses Ticket wirklich löschen?')">🗑️</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($tickets)): ?>
        <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:40px;">Keine Tickets gefunden. <a href="<?= BASE_URL ?>/ticket_new.php">Neues Ticket erstellen →</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
