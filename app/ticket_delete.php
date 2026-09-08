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
$pageTitle = 'Ticket löschen';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('Ungültige Ticket-ID.', 'error');
    header('Location: ' . BASE_URL . '/tickets.php');
    exit;
}

// Ticket prüfen
$stmt = $db->prepare("SELECT t.*, c.firstname, c.lastname, c.company FROM tickets t LEFT JOIN customers c ON t.customer_id = c.id WHERE t.id = ?");
$stmt->execute([$id]);
$ticket = $stmt->fetch();
if (!$ticket) {
    flash('Ticket nicht gefunden.', 'error');
    header('Location: ' . BASE_URL . '/tickets.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    
    try {
        // Ticket direkt löschen
        $stmt = $db->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        
        echo "<div style='background:#10b981;color:white;padding:20px;margin:20px;'>";
        echo "<h2>✅ Ticket erfolgreich gelöscht!</h2>";
        echo "<p style='margin:10px 0;'>Das Ticket '#{$ticket['ticket_number']}' wurde erfolgreich gelöscht.</p>";
        echo "<a href='" . BASE_URL . "/tickets.php' style='color:white;text-decoration:underline;display:inline-block;margin-top:10px;'>Zur Ticketliste</a>";
        echo "</div>";
        
        exit;
    } catch (Exception $e) {
        flash('Fehler beim Löschen des Tickets: ' . $e->getMessage(), 'error');
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3>🗑️ Ticket löschen</h3>
    </div>
    <div class="card-body">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:20px;margin-bottom:20px;">
            <h4 style="color:#dc2626;margin-bottom:10px;">⚠️ Achtung</h4>
            <p style="margin-bottom:10px;">Sie sind dabei, das folgende Ticket zu löschen:</p>
            <div style="background:#f8fafc;padding:15px;border-radius:6px;margin:10px 0;">
                <strong><?= h($ticket['ticket_number']) ?></strong><br>
                <span style="color:#374151;"><?= h($ticket['title']) ?></span>
                <?php if ($ticket['customer_id']): ?>
                <br><span style="color:#64748b;font-size:14px;">
                    Kunde: <?= h(trim($ticket['firstname'] . ' ' . $ticket['lastname'])) ?>
                    <?php if ($ticket['company']): ?> (<?= h($ticket['company']) ?>)<?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
            
            <div style="background:#fffbeb;border:1px solid #fed7aa;border-radius:6px;padding:12px;margin:10px 0;">
                <strong style="color:#ea580c;">📊 Hinweis:</strong> Dieses Ticket wird endgültig aus dem System entfernt.
            </div>
            
            <p style="color:#dc2626;font-weight:500;margin-top:15px;">Diese Aktion kann nicht rückgängig gemacht werden!</p>
        </div>
        
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $id ?>" class="btn btn-secondary">Abbrechen</a>
                <button type="submit" class="btn btn-danger">🗑️ Ticket endgültig löschen</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
