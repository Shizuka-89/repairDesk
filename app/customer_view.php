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
$activeMenu = 'customers';
$pageTitle = 'Kunden Details';  // Default-Titel vor header.php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/customers.php'); exit; }

$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { flash('Kunde nicht gefunden.', 'error'); header('Location: ' . BASE_URL . '/customers.php'); exit; }

// Tickets des Kunden
$tickets = $db->query("
    SELECT t.*, te.name as technician_name
    FROM tickets t
    LEFT JOIN technicians te ON t.technician_id = te.id
    WHERE t.customer_id = $id
    ORDER BY t.created_at DESC
")->fetchAll();

$totalTickets = count($tickets);
$openTickets = array_filter($tickets, fn($t) => !in_array($t['status'], ['abgeschlossen', 'storniert']));
$totalRevenue = array_sum(array_column(array_filter($tickets, fn($t) => $t['final_price']), 'final_price'));
?>

<!-- Kunden-Header -->
<div style="display:flex;gap:12px;margin-bottom:20px;align-items:center;">
  <a href="<?= BASE_URL ?>/customers.php" style="color:#64748b;font-size:14px;">← Alle Kunden</a>
  <span style="color:#d1d5db;">|</span>
  <span style="margin-left:auto;display:flex;gap:8px;">
    <a href="<?= BASE_URL ?>/customer_new.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">✏️ Bearbeiten</a>
    <a href="<?= BASE_URL ?>/ticket_new.php?customer_id=<?= $id ?>" class="btn btn-primary btn-sm">➕ Neues Ticket</a>
    <button onclick="if(confirm('Möchten Sie diesen Kunden wirklich löschen? Alle zugehörigen Tickets bleiben erhalten.')) { window.location.href='<?= BASE_URL ?>/customer_delete.php?id=<?= $id ?>'; }" class="btn btn-danger btn-sm">🗑️ Löschen</button>
  </span>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px;align-items:start;">
  
  <!-- Linke Spalte: Kundendaten -->
  <div>
    <div class="card">
      <div class="card-body">
        <div style="text-align:center;padding:10px 0 20px;">
          <div style="width:72px;height:72px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 12px;">👤</div>
          <h2 style="font-size:20px;font-weight:700;"><?= h(customerFullName($customer)) ?></h2>
          <?php if ($customer['company']): ?><p style="color:#64748b;font-size:14px;"><?= h($customer['company']) ?></p><?php endif; ?>
        </div>
        
        <div style="border-top:1px solid #e2e8f0;padding-top:16px;">
          <?php if ($customer['phone']): ?>
          <div style="display:flex;gap:10px;margin-bottom:10px;align-items:center;">
            <span style="font-size:18px;">📞</span>
            <div>
              <p style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">Telefon</p>
              <a href="tel:<?= h($customer['phone']) ?>" style="font-size:14px;font-weight:500;"><?= h($customer['phone']) ?></a>
            </div>
          </div>
          <?php endif; ?>
          
          <?php if ($customer['email']): ?>
          <div style="display:flex;gap:10px;margin-bottom:10px;align-items:center;">
            <span style="font-size:18px;">✉️</span>
            <div>
              <p style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">E-Mail</p>
              <a href="mailto:<?= h($customer['email']) ?>" style="font-size:14px;font-weight:500;word-break:break-all;"><?= h($customer['email']) ?></a>
            </div>
          </div>
          <?php endif; ?>
          
          <?php if ($customer['street'] || $customer['city']): ?>
          <div style="display:flex;gap:10px;margin-bottom:10px;align-items:flex-start;">
            <span style="font-size:18px;">📍</span>
            <div>
              <p style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">Adresse</p>
              <p style="font-size:14px;">
                <?= h($customer['street']) ?><br>
                <?= h($customer['zip']) ?> <?= h($customer['city']) ?>
              </p>
            </div>
          </div>
          <?php endif; ?>
          
          <div style="display:flex;gap:10px;margin-bottom:10px;align-items:center;">
            <span style="font-size:18px;">📅</span>
            <div>
              <p style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">Kunde seit</p>
              <p style="font-size:14px;"><?= date('d.m.Y', strtotime($customer['created_at'])) ?></p>
            </div>
          </div>
        </div>
        
        <?php if ($customer['notes']): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:12px;margin-top:12px;">
          <p style="font-size:11px;color:#92400e;font-weight:600;margin-bottom:4px;">🔒 INTERNE NOTIZEN</p>
          <p style="font-size:13px;line-height:1.5;"><?= nl2br(h($customer['notes'])) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Statistik-Mini -->
    <div class="card">
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;text-align:center;">
          <div style="padding:12px;background:#eff6ff;border-radius:6px;">
            <div style="font-size:24px;font-weight:700;color:#2563eb;"><?= $totalTickets ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Tickets gesamt</div>
          </div>
          <div style="padding:12px;background:#ecfdf5;border-radius:6px;">
            <div style="font-size:24px;font-weight:700;color:#059669;"><?= count($openTickets) ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Aktuell offen</div>
          </div>
          <?php if ($totalRevenue > 0): ?>
          <div style="padding:12px;background:#fef3c7;border-radius:6px;grid-column:span 2;">
            <div style="font-size:20px;font-weight:700;color:#92400e;"><?= number_format($totalRevenue, 2, ',', '.') ?> €</div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Gesamtumsatz</div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Rechte Spalte: Ticket-Verlauf -->
  <div>
    <div class="card">
      <div class="card-header">
        <h3>🎫 Ticket-Verlauf (<?= $totalTickets ?>)</h3>
        <a href="<?= BASE_URL ?>/ticket_new.php?customer_id=<?= $id ?>" class="btn btn-primary btn-sm">➕ Neues Ticket</a>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Ticket-Nr.</th>
              <th>Titel</th>
              <th>Gerät</th>
              <th>Techniker</th>
              <th>Status</th>
              <th>Preis</th>
              <th>Datum</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
            <tr>
              <td><a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $t['id'] ?>" style="font-weight:700;font-family:monospace;"><?= h($t['ticket_number']) ?></a></td>
              <td><?= h($t['title']) ?></td>
              <td style="font-size:12px;"><?= h($t['device_model'] ?? '—') ?></td>
              <td style="font-size:12px;"><?= h($t['technician_name'] ?? '—') ?></td>
              <td><?= statusLabel($t['status']) ?></td>
              <td style="font-size:13px;">
                <?php if ($t['final_price']): ?>
                  <strong style="color:#065f46;"><?= number_format($t['final_price'], 2, ',', '.') ?> €</strong>
                <?php elseif ($t['estimated_price']): ?>
                  <span style="color:#92400e;"><?= number_format($t['estimated_price'], 2, ',', '.') ?> €</span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?= date('d.m.Y', strtotime($t['created_at'])) ?></td>
              <td><a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">→</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($tickets)): ?>
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:30px;">Noch keine Tickets. <a href="<?= BASE_URL ?>/ticket_new.php?customer_id=<?= $id ?>">Jetzt erstellen →</a></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
