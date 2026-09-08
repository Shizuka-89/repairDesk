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
$pageTitle = 'Kunde löschen';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('Ungültige Kunden-ID.', 'error');
    header('Location: ' . BASE_URL . '/customers.php');
    exit;
}

// Kunden prüfen
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) {
    flash('Kunde nicht gefunden.', 'error');
    header('Location: ' . BASE_URL . '/customers.php');
    exit;
}

// Prüfen, ob der Kunde Tickets hat
$stmtCount = $db->prepare("SELECT COUNT(*) FROM tickets WHERE customer_id = ?");
$stmtCount->execute([$id]);
$ticketsCount = $stmtCount->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    
    try {
        // Kunden löschen (Tickets bleiben bestehen, customer_id wird NULL)
        $stmt = $db->prepare("UPDATE tickets SET customer_id = NULL WHERE customer_id = ?");
        $stmt->execute([$id]);
        
        $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        
        $customerName = customerFullName($customer);
        echo "<div style='background:#10b981;color:white;padding:20px;margin:20px;'>";
        echo "<h2>✅ Kunde erfolgreich gelöscht!</h2>";
        echo "<p style='margin:10px 0;'>Der Kunde '$customerName' wurde erfolgreich gelöscht.</p>";
        echo "<p style='font-size:14px;opacity:0.9;'>Die zugehörigen Tickets bleiben im System erhalten.</p>";
        echo "<a href='" . BASE_URL . "/customers.php' style='color:white;text-decoration:underline;display:inline-block;margin-top:10px;'>Zur Kundenliste</a>";
        echo "</div>";
        
        exit;
    } catch (Exception $e) {
        flash('Fehler beim Löschen des Kunden: ' . $e->getMessage(), 'error');
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3>🗑️ Kunde löschen</h3>
    </div>
    <div class="card-body">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:20px;margin-bottom:20px;">
            <h4 style="color:#dc2626;margin-bottom:10px;">⚠️ Achtung</h4>
            <p style="margin-bottom:10px;">Sie sind dabei, den folgenden Kunden zu löschen:</p>
            <div style="background:#f8fafc;padding:15px;border-radius:6px;margin:10px 0;">
                <strong><?= h(customerFullName($customer)) ?></strong>
                <?php if ($customer['company']): ?><br><span style="color:#64748b;"><?= h($customer['company']) ?></span><?php endif; ?>
                <?php if ($customer['email']): ?><br><span style="color:#64748b;"><?= h($customer['email']) ?></span><?php endif; ?>
            </div>
            
            <?php if ($ticketsCount > 0): ?>
            <div style="background:#fffbeb;border:1px solid #fed7aa;border-radius:6px;padding:12px;margin:10px 0;">
                <strong style="color:#ea580c;">📊 Hinweis:</strong> Dieser Kunde hat <strong><?= $ticketsCount ?></strong> Ticket(s).
                Die Tickets bleiben im System erhalten, werden aber nicht mehr diesem Kunden zugeordnet.
            </div>
            <?php endif; ?>
            
            <p style="color:#dc2626;font-weight:500;margin-top:15px;">Diese Aktion kann nicht rückgängig gemacht werden!</p>
        </div>
        
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <a href="<?= BASE_URL ?>/customer_view.php?id=<?= $id ?>" class="btn btn-secondary">Abbrechen</a>
                <button type="submit" class="btn btn-danger">🗑️ Kunde endgültig löschen</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>