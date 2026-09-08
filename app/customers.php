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
$pageTitle = 'Kundenliste';
$activeMenu = 'customers';
require_once __DIR__ . '/../includes/header.php';

$db     = getDB();
$search = trim($_GET['q'] ?? '');

$where  = '1=1';
$params = [];
if ($search) {
    $where = "(c.lastname LIKE ? OR c.firstname LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.city LIKE ? OR c.company LIKE ?)";
    $s = '%' . $search . '%';
    $params = [$s, $s, $s, $s, $s, $s];
}

$stmt = $db->prepare("
    SELECT c.*,
           COUNT(t.id) as ticket_count,
           SUM(CASE WHEN t.status NOT IN ('abgeschlossen','storniert') THEN 1 ELSE 0 END) as open_tickets
    FROM customers c
    LEFT JOIN tickets t ON c.id = t.customer_id
    WHERE $where
    GROUP BY c.id
    ORDER BY c.lastname ASC, c.firstname ASC
");
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>

<div style="display:flex;gap:12px;margin-bottom:16px;align-items:center;">
  <form method="get" action="" style="display:flex;gap:8px;flex:1;">
    <input type="text" name="q" class="form-control"
           placeholder="Suche nach Nachname, Vorname, E-Mail, Telefon, Ort…"
           value="<?= h($search) ?>" style="max-width:400px;">
    <button type="submit" class="btn btn-primary">Suchen</button>
    <?php if ($search): ?>
    <a href="<?= BASE_URL ?>/customers.php" class="btn btn-secondary">Reset</a>
    <?php endif; ?>
  </form>
  <a href="<?= BASE_URL ?>/customer_new.php" class="btn btn-primary">➕ Neuer Kunde</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Nachname</th>
          <th>Vorname</th>
          <th>Firma</th>
          <th>Telefon</th>
          <th>E-Mail</th>
          <th>Ort</th>
          <th>Tickets</th>
          <th>Offen</th>
          <th>Seit</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
        <tr>
          <td>
            <a href="<?= BASE_URL ?>/customer_view.php?id=<?= $c['id'] ?>" style="font-weight:700;">
              <?= h($c['lastname']) ?>
            </a>
          </td>
          <td><?= h($c['firstname']) ?></td>
          <td><?= h($c['company'] ?? '—') ?></td>
          <td><?= $c['phone'] ? '<a href="tel:' . h($c['phone']) . '">' . h($c['phone']) . '</a>' : '—' ?></td>
          <td><?= $c['email'] ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '—' ?></td>
          <td><?= h($c['city'] ?? '—') ?></td>
          <td><span class="badge badge-gray"><?= $c['ticket_count'] ?></span></td>
          <td>
            <?php if ($c['open_tickets'] > 0): ?>
            <span class="badge badge-blue"><?= $c['open_tickets'] ?></span>
            <?php else: ?>
            <span style="color:#94a3b8;">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#64748b;"><?= date('d.m.Y', strtotime($c['created_at'])) ?></td>
          <td style="white-space:nowrap;">
            <a href="<?= BASE_URL ?>/customer_view.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Profil</a>
            <a href="<?= BASE_URL ?>/ticket_new.php?customer_id=<?= $c['id'] ?>" class="btn btn-primary btn-sm">+ Ticket</a>
            <button onclick="if(confirm('Möchten Sie diesen Kunden wirklich löschen? Alle zugehörigen Tickets bleiben erhalten.')) { window.location.href='<?= BASE_URL ?>/customer_delete.php?id=<?= $c['id'] ?>'; }" class="btn btn-danger btn-sm">🗑️</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($customers)): ?>
        <tr>
          <td colspan="10" style="text-align:center;color:#94a3b8;padding:40px;">
            Keine Kunden gefunden. <a href="<?= BASE_URL ?>/customer_new.php">Neuen Kunden anlegen →</a>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
