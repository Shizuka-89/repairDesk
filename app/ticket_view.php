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

// Zugriffsschutz
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/tickets.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    
    $db     = getDB();
    $action = $_POST['action'];

    // Ticket + Kunde für alle Actions laden
    $stmt = $db->prepare("SELECT t.*, c.firstname, c.lastname, c.email as customer_email FROM tickets t JOIN customers c ON t.customer_id = c.id WHERE t.id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $stmtCust = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmtCust->execute([$ticket['customer_id']]);
        $customer = $stmtCust->fetch();
    } else {
        $customer = null;
    }

    // Hilfsfunktion
    function showSuccess(string $headline, string $msg, int $ticketId): void {
        $url = BASE_URL . '/ticket_view.php?id=' . $ticketId;
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
        <meta http-equiv="refresh" content="2;url=' . $url . '">
        <title>Gespeichert</title>
        <style>body{font-family:system-ui,sans-serif;background:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
        .box{background:#1e293b;border-radius:12px;padding:2.5rem 3rem;max-width:480px;text-align:center;color:#e2e8f0;}
        h2{color:#4ade80;font-size:1.6rem;margin-bottom:.75rem;}
        p{color:#94a3b8;line-height:1.6;margin-bottom:1.5rem;}
        a{display:inline-block;background:#2563eb;color:#fff;padding:.65rem 1.75rem;border-radius:8px;text-decoration:none;font-weight:600;}
        a:hover{background:#1d4ed8;}.note{font-size:.8rem;color:#64748b;margin-top:1.25rem;}</style>
        </head><body><div class="box">
        <h2>✅ ' . htmlspecialchars($headline) . '</h2>
        <p>' . $msg . '</p>
        <a href="' . $url . '">← Zurück zum Ticket</a>
        <p class="note">Weiterleitung in 2 Sekunden…</p>
        </div></body></html>';
        exit;
    }

    // Status-Labels für E-Mail
    $statusLabels = [
        'offen'            => 'Offen',
        'in_bearbeitung'   => 'In Bearbeitung',
        'warten_auf_kunde' => 'Wartet auf Kundenrückmeldung',
        'abgeschlossen'    => 'Abgeschlossen',
        'storniert'        => 'Storniert',
    ];

    if ($action === 'update_management') {
        $newStatus     = $_POST['new_status'] ?? $ticket['status'];
        $newTechId     = (int)($_POST['technician_id'] ?? 0) ?: null;
        $newFinalPrice = !empty($_POST['final_price']) ? (float)str_replace(',', '.', $_POST['final_price']) : null;
        $validStatuses = array_keys($statusLabels);

        if (!in_array($newStatus, $validStatuses)) $newStatus = $ticket['status'];

        // Änderungen erkennen
        $statusChanged = ($newStatus !== $ticket['status']);
        $techChanged   = ((int)($newTechId ?? 0) !== (int)($ticket['technician_id'] ?? 0));
        $oldPrice      = $ticket['final_price'] !== null ? round((float)$ticket['final_price'], 2) : null;
        $newPriceRound = $newFinalPrice !== null ? round($newFinalPrice, 2) : null;
        $priceChanged  = ($newPriceRound !== $oldPrice);

        if (!$statusChanged && !$techChanged && !$priceChanged) {
            // Nichts geändert → zurück
            header('Location: ' . BASE_URL . '/ticket_view.php?id=' . $id);
            exit;
        }

        // ── DB-Update ──────────────────────────────────────────
        $db->prepare("UPDATE tickets SET status = ?, technician_id = ?, final_price = ? WHERE id = ?")
           ->execute([$newStatus, $newTechId, $newFinalPrice, $id]);

        // ── E-Mail-Logik ───────────────────────────────────────
        $statusColor = ['offen'=>'#3b82f6','in_bearbeitung'=>'#f59e0b','warten_auf_kunde'=>'#8b5cf6','abgeschlossen'=>'#10b981','storniert'=>'#ef4444'];
        $ticketNum   = htmlspecialchars($ticket['ticket_number']);
        $customerLink = BASE_URL . '/customer/view.php?token=' . urlencode($ticket['customer_token']);
        $customerMailSent = false;
        $techMailSent     = false;

        // ── 1) Kunde: kombinierte E-Mail bei Status- und/oder Preisänderung ──
        if (($statusChanged || $priceChanged) && $customer && !empty($customer['email'])) {
            $fullName   = htmlspecialchars(customerFullName($customer));
            $contentParts = [];
            $contentParts[] = "<p>Hallo {$fullName},</p>";
            $contentParts[] = "<p>es gibt Neuigkeiten zu Ihrem Ticket <strong>#{$ticketNum}</strong>:</p>";

            $emailTitle = '';

            if ($statusChanged) {
                $statusText = $statusLabels[$newStatus];
                $color = $statusColor[$newStatus] ?? '#64748b';
                $contentParts[] = '<div style="background:#f8fafc;border-left:4px solid ' . $color . ';padding:16px 20px;margin:20px 0;border-radius:0 8px 8px 0;">'
                    . '<strong style="color:' . $color . ';font-size:16px;">Status: ' . $statusText . '</strong></div>';
                $emailTitle = "Status: {$statusText}";
            }

            if ($priceChanged && $newFinalPrice > 0) {
                $priceFormatted = number_format($newFinalPrice, 2, ',', '.');
                $contentParts[] = '<div style="background:#f0fdf4;border-left:4px solid #10b981;padding:16px 20px;margin:20px 0;border-radius:0 8px 8px 0;">'
                    . '<strong style="color:#065f46;font-size:16px;">Endpreis: ' . $priceFormatted . ' €</strong></div>';
                $emailTitle = $emailTitle ? $emailTitle . ' | Endpreis: ' . $priceFormatted . ' €' : 'Endpreis: ' . $priceFormatted . ' €';
            }

            $contentParts[] = '<p><a href="' . $customerLink . '" style="background:#2563eb;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;">Ticket online ansehen</a></p>';

            sendMail($customer['email'], customerFullName($customer), "Ticket #{$ticketNum} aktualisiert", emailTemplate($emailTitle, implode("\n", $contentParts)));
            $customerMailSent = true;
        }

        // ── 2) Techniker: Zuweisungs-E-Mail ──
        $newTech = null;
        if ($techChanged && $newTechId) {
            $techStmt = $db->prepare("SELECT name, email FROM technicians WHERE id = ?");
            $techStmt->execute([$newTechId]);
            $newTech = $techStmt->fetch();

            if ($newTech && !empty($newTech['email'])) {
                $techName  = htmlspecialchars($newTech['name']);
                $techParts = [];
                $techParts[] = "<p>Hallo {$techName},</p>";
                $techParts[] = "<p>Du wurdest als Techniker zu Ticket <strong>#{$ticketNum}</strong> zugewiesen.</p>";

                // Status-Info mitgeben
                $currentStatus = $statusChanged ? $newStatus : $ticket['status'];
                $sColor = $statusColor[$currentStatus] ?? '#64748b';
                $sText  = $statusLabels[$currentStatus];
                $techParts[] = '<div style="background:#f8fafc;border-left:4px solid ' . $sColor . ';padding:12px 16px;margin:16px 0;border-radius:0 8px 8px 0;">'
                    . '<span style="font-size:13px;color:#64748b;">Status:</span> <strong style="color:' . $sColor . ';">' . $sText . '</strong></div>';

                // Endpreis-Info mitgeben falls vorhanden
                $displayPrice = $priceChanged ? $newFinalPrice : $ticket['final_price'];
                if ($displayPrice > 0) {
                    $techParts[] = '<p style="color:#065f46;">💰 Endpreis: <strong>' . number_format((float)$displayPrice, 2, ',', '.') . ' €</strong></p>';
                }

                // Kundeninfos
                if ($customer) {
                    $techParts[] = '<div style="background:#f1f5f9;padding:12px 16px;border-radius:8px;margin-top:16px;">'
                        . '<p style="font-size:13px;color:#64748b;margin-bottom:4px;">KUNDE</p>'
                        . '<p><strong>' . htmlspecialchars(customerFullName($customer)) . '</strong></p>'
                        . (!empty($customer['phone']) ? '<p>📞 ' . htmlspecialchars($customer['phone']) . '</p>' : '')
                        . (!empty($customer['email']) ? '<p>✉️ ' . htmlspecialchars($customer['email']) . '</p>' : '')
                        . '</div>';
                }

                $techParts[] = '<p style="margin-top:20px;"><a href="' . BASE_URL . '/ticket_view.php?id=' . $id . '" style="background:#2563eb;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;">Ticket öffnen</a></p>';

                sendMail($newTech['email'], $newTech['name'], "Ticket #{$ticketNum} – Dir zugewiesen", emailTemplate("Ticket-Zuweisung: #{$ticketNum}", implode("\n", $techParts)));
                $techMailSent = true;
            }
        }

        // ── Erfolgs-Meldung ────────────────────────────────────
        $changes = [];
        if ($statusChanged) $changes[] = 'Status → <strong>' . htmlspecialchars($statusLabels[$newStatus]) . '</strong>';
        if ($priceChanged)  $changes[] = 'Endpreis → <strong>' . ($newFinalPrice > 0 ? number_format($newFinalPrice, 2, ',', '.') . ' €' : 'entfernt') . '</strong>';
        if ($techChanged)   $changes[] = 'Techniker → <strong>' . ($newTechId ? htmlspecialchars($newTech['name'] ?? '') : 'nicht zugewiesen') . '</strong>';

        $mailInfo = '';
        if ($customerMailSent) $mailInfo .= '<br>📧 Kunde wurde per E-Mail benachrichtigt.';
        if ($techMailSent)     $mailInfo .= '<br>📧 Techniker wurde per E-Mail benachrichtigt.';

        showSuccess('Änderungen gespeichert', implode('<br>', $changes) . $mailInfo, $id);
    }

    elseif ($action === 'add_comment') {
        $message       = trim($_POST['comment_message'] ?? '');
        $isInternal    = isset($_POST['is_internal']) ? 1 : 0;
        $priceProposal = !empty($_POST['price_proposal']) ? (float)str_replace(',', '.', $_POST['price_proposal']) : null;

        // Speichern wenn Nachricht ODER Kostenvoranschlag vorhanden
        if (!empty($message) || $priceProposal !== null) {
            // Technikername aus Session holen (header.php noch nicht geladen)
            $techName = '';
            if (isLoggedIn()) {
                $t = $db->prepare("SELECT name FROM technicians WHERE id = ?");
                $t->execute([$_SESSION['technician_id']]);
                $techName = $t->fetchColumn() ?: '';
            }
            $db->prepare("INSERT INTO ticket_comments (ticket_id, author_type, author_name, message, is_internal, price_proposal) VALUES (?, 'technician', ?, ?, ?, ?)")
               ->execute([$id, $techName, $message, $isInternal, $priceProposal]);
            $newCommentId = (int)$db->lastInsertId();

            if ($priceProposal !== null) {
                $db->prepare("UPDATE tickets SET status = 'warten_auf_kunde', estimated_price = ? WHERE id = ?")->execute([$priceProposal, $id]);
            }
            if (!$isInternal && $ticket && $customer) {
                sendTicketUpdateMail($ticket, $customer, ['id' => $newCommentId, 'message' => $message, 'price_proposal' => $priceProposal]);
            }
            $mailInfo = (!$isInternal) ? '<br>📧 Der Kunde wurde per E-Mail informiert.' : '';
            showSuccess('Nachricht gesendet', 'Die Nachricht wurde gespeichert.' . $mailInfo, $id);
        }
        // Wenn weder Nachricht noch KVA → einfach zurück
        header('Location: ' . BASE_URL . '/ticket_view.php?id=' . $id);
        exit;
    }
    
    // Fallback-Redirect für unbekannte Actions
    header('Location: ' . BASE_URL . '/ticket_view.php?id=' . $id);
    exit;
}


$pageTitle  = 'Ticket Details';
$activeMenu = 'tickets';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
if (!$id) { echo '<p>Ungültige ID.</p>'; require_once __DIR__ . '/../includes/footer.php'; exit; }

$stmt = $db->prepare("
    SELECT t.*, c.firstname, c.lastname, c.email as customer_email, c.phone as customer_phone,
           te.name as technician_name
    FROM tickets t
    JOIN customers c ON t.customer_id = c.id
    LEFT JOIN technicians te ON t.technician_id = te.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$ticket = $stmt->fetch();
if (!$ticket) { flash('Ticket nicht gefunden.', 'error'); header('Location: ' . BASE_URL . '/tickets.php'); exit; }

$stmtCust = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmtCust->execute([$ticket['customer_id']]);
$customer = $stmtCust->fetch();
$technicians         = $db->query("SELECT id, name FROM technicians ORDER BY name ASC")->fetchAll();
$stmt = $db->prepare("SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at ASC");
$stmt->execute([$id]);
$comments = $stmt->fetchAll();
$customDefs          = getCustomFieldDefs();
$customValues        = getCustomFieldValues($id);
$customBySection     = getCustomFieldDefsBySection();
$stmtCount = $db->prepare("SELECT COUNT(*) FROM tickets WHERE customer_id = ?");
$stmtCount->execute([$ticket['customer_id']]);
$customerTicketCount = $stmtCount->fetchColumn();

$pageTitle = 'Ticket #' . h($ticket['ticket_number']);
?>

<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
  <a href="<?= BASE_URL ?>/tickets.php" style="color:#64748b;font-size:14px;">← Alle Tickets</a>
  <span style="color:#d1d5db;">|</span>
  <?= statusLabel($ticket['status']) ?>
  <?= priorityLabel($ticket['priority']) ?>
  <span style="margin-left:auto;display:flex;gap:8px;">
    <a href="<?= BASE_URL ?>/ticket_edit.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">✏️ Bearbeiten</a>
    <a href="<?= BASE_URL ?>/ticket_delete.php?id=<?= $id ?>" class="btn btn-danger btn-sm" onclick="return confirm('Möchten Sie dieses Ticket wirklich löschen?')">🗑️ Löschen</a>
  </span>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">


  <div>
    <!-- Ticket Info -->
    <div class="card">
      <div class="card-header">
        <h3>🎫 <?= h($ticket['ticket_number']) ?> – <?= h($ticket['title']) ?></h3>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <?php if (getSetting('show_device_info', '1') === '1'): ?>
          <div>
            <p style="font-size:12px;color:#64748b;margin-bottom:2px;"><?= strtoupper(h(getSetting('label_device_model', 'Gerät / Modell'))) ?></p>
            <p style="font-weight:600;"><?= h($ticket['device_model'] ?: '—') ?></p>
          </div>
          <div>
            <p style="font-size:12px;color:#64748b;margin-bottom:2px;"><?= strtoupper(h(getSetting('label_serial_number', 'Seriennummer'))) ?></p>
            <p style="font-family:monospace;"><?= h($ticket['serial_number'] ?: '—') ?></p>
          </div>
          <div>
            <p style="font-size:12px;color:#64748b;margin-bottom:2px;"><?= strtoupper(h(getSetting('label_condition_on_arrival', 'Zustand bei Annahme'))) ?></p>
            <p><?= h($ticket['condition_on_arrival'] ?: '—') ?></p>
          </div>
          <?php endif; ?>
          <div>
            <p style="font-size:12px;color:#64748b;margin-bottom:2px;">ERSTELLT AM</p>
            <p><?= formatDate($ticket['created_at']) ?></p>
          </div>
        </div>
        <?php if (getSetting('show_device_info', '1') === '1' && $ticket['damage_description']): ?>
        <hr class="section-divider" style="margin:16px 0;">
        <p style="font-size:12px;color:#64748b;margin-bottom:6px;"><?= strtoupper(h(getSetting('label_damage_description', 'Schadensbeschreibung'))) ?></p>
        <p style="line-height:1.6;"><?= nl2br(h($ticket['damage_description'])) ?></p>
        <?php endif; ?>
        <?php if ($ticket['description']): ?>
        <hr class="section-divider" style="margin:16px 0;">
        <p style="font-size:12px;color:#64748b;margin-bottom:6px;">ZUBEHÖR / SONSTIGE INFOS</p>
        <p><?= nl2br(h($ticket['description'])) ?></p>
        <?php endif; ?>
        <?php if ($ticket['internal_notes']): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:12px;margin-top:12px;">
          <p style="font-size:12px;color:#92400e;font-weight:600;margin-bottom:4px;">🔒 INTERNE NOTIZ</p>
          <p style="font-size:14px;"><?= nl2br(h($ticket['internal_notes'])) ?></p>
        </div>
        <?php endif; ?>

        <?php
        // Felder die in Geraetekarte gehören
        $deviceFields = $customBySection['device_info'] ?? [];
        if (!empty($deviceFields) && getSetting('show_device_info', '1') === '1'):
        ?>
        <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <?php foreach ($deviceFields as $cf):
              $val = $customValues[$cf['field_key']] ?? ''; ?>
          <div <?= $cf['field_type'] === 'textarea' ? 'style="grid-column:span 2"' : '' ?>>
            <p style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;"><?= h($cf['label']) ?></p>
            <p style="font-size:14px;"><?= renderCustomFieldValue($cf, $val) ?></p>
          </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    
    <?php
    foreach ($customBySection as $secKey => $secFields):
        if ($secKey === 'device_info') continue; // schon oben
        $secTitle = match($secKey) {
            'ticket_details' => '🎫 Weitere Ticket-Details',
            'custom'         => '🔧 Weitere Felder',
            default          => '🗂️ ' . $secKey,
        };
    ?>
    <div class="card">
      <div class="card-header"><h3><?= h($secTitle) ?></h3></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <?php foreach ($secFields as $cf):
              $val = $customValues[$cf['field_key']] ?? ''; ?>
          <div <?= $cf['field_type'] === 'textarea' ? 'style="grid-column:span 2"' : '' ?>>
            <p style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;"><?= h($cf['label']) ?></p>
            <p style="font-size:14px;"><?= renderCustomFieldValue($cf, $val) ?></p>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="card">
      <div class="card-header">
        <h3>💬 Kommunikation & Verlauf</h3>
      </div>
      <div class="card-body">
        <?php foreach ($comments as $c): ?>
        <div class="comment <?= $c['is_internal'] ? 'internal' : ($c['author_type'] === 'customer' ? 'customer' : '') ?>">
          <div class="comment-header">
            <span>
              <?= $c['author_type'] === 'technician' ? '👨‍🔧' : '👤' ?>
              <strong><?= h($c['author_name']) ?></strong>
              <?php if ($c['is_internal']): ?><span style="background:#fde68a;color:#92400e;padding:1px 6px;border-radius:10px;font-size:10px;margin-left:4px;">INTERN</span><?php endif; ?>
            </span>
            <span><?= formatDate($c['created_at']) ?></span>
          </div>
          <div class="comment-body"><?= nl2br(h($c['message'])) ?></div>
          <?php if ($c['price_proposal'] !== null): ?>
          <div class="price-proposal">
            💰 <strong>Kostenvoranschlag: <?= number_format($c['price_proposal'], 2, ',', '.') ?> €</strong>
            <?php if ($c['customer_response']): ?>
            <span style="margin-left:10px;color:<?= $c['customer_response'] === 'accepted' ? '#065f46' : '#991b1b' ?>;">
              <?= $c['customer_response'] === 'accepted' ? '✅ Akzeptiert' : '❌ Abgelehnt' ?>
            </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($comments)): ?>
        <p style="color:#94a3b8;text-align:center;padding:20px 0;font-size:14px;">Noch keine Nachrichten. Fügen Sie unten eine Nachricht oder einen Kostenvoranschlag hinzu.</p>
        <?php endif; ?>
        
        <hr class="section-divider">
        
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="add_comment">
          
          <div class="form-group">
            <label class="form-label">Nachricht / Kostenvoranschlag an Kunden</label>
            <textarea name="comment_message" class="form-control" rows="3" placeholder="Nachricht eingeben... Diese wird per E-Mail an den Kunden gesendet (außer 'Intern')."></textarea>
          </div>
          <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
            <div class="form-group" style="margin-bottom:0;flex:1;">
              <label class="form-label">Kostenvoranschlag (€)</label>
              <input type="text" name="price_proposal" class="form-control" placeholder="z.B. 89,90 – leer lassen wenn kein KVA">
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:20px;">
              <input type="checkbox" name="is_internal" id="is_internal" value="1">
              <label for="is_internal" style="font-size:14px;cursor:pointer;">🔒 Nur intern (keine E-Mail)</label>
            </div>
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="submit" class="btn btn-primary">📨 Senden<?= '&nbsp;' ?>& E-Mail</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  
  <div>
    <!-- Kunde -->
    <div class="card">
      <div class="card-header">
        <h3>👤 Kunde</h3>
      </div>
      <div class="card-body">
        <p style="font-weight:700;font-size:16px;margin-bottom:6px;"><?= h(customerFullName($customer)) ?></p>
        <?php if ($customer['company']): ?><p style="color:#64748b;font-size:13px;"><?= h($customer['company']) ?></p><?php endif; ?>
        <?php if ($customer['phone']): ?><p style="margin-top:6px;">📞 <a href="tel:<?= h($customer['phone']) ?>"><?= h($customer['phone']) ?></a></p><?php endif; ?>
        <?php if ($customer['email']): ?><p>✉️ <a href="mailto:<?= h($customer['email']) ?>"><?= h($customer['email']) ?></a></p><?php endif; ?>
        <p style="margin-top:10px;font-size:13px;color:#64748b;">
          🎫 <strong><?= $customerTicketCount ?></strong> Ticket<?= $customerTicketCount !== 1 ? 's' : '' ?> gesamt
        </p>
        <div style="margin-top:12px;display:flex;gap:8px;">
          <a href="<?= BASE_URL ?>/customer_view.php?id=<?= $customer['id'] ?>" class="btn btn-secondary btn-sm">Kundenprofil</a>
          <a href="<?= BASE_URL ?>/ticket_new.php?customer_id=<?= $customer['id'] ?>" class="btn btn-secondary btn-sm">Neues Ticket</a>
        </div>
      </div>
    </div>
    
    <!-- Status & Zuweisung -->
    <div class="card">
      <div class="card-header"><h3>⚙️ Verwaltung</h3></div>
      <div class="card-body">
        <form method="post" action="" id="managementForm">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="update_management">
          
          <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Status ändern</label>
            <select name="new_status" class="form-control" id="mgmt_status">
              <option value="offen" <?= $ticket['status'] === 'offen' ? 'selected' : '' ?>>🔵 Offen</option>
              <option value="in_bearbeitung" <?= $ticket['status'] === 'in_bearbeitung' ? 'selected' : '' ?>>🟡 In Bearbeitung</option>
              <option value="warten_auf_kunde" <?= $ticket['status'] === 'warten_auf_kunde' ? 'selected' : '' ?>>🟣 Wartet auf Kunden</option>
              <option value="abgeschlossen" <?= $ticket['status'] === 'abgeschlossen' ? 'selected' : '' ?>>🟢 Abgeschlossen</option>
              <option value="storniert" <?= $ticket['status'] === 'storniert' ? 'selected' : '' ?>>🔴 Storniert</option>
            </select>
          </div>
          
          <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Techniker zuweisen</label>
            <select name="technician_id" class="form-control" id="mgmt_tech">
              <option value="">— Nicht zugewiesen —</option>
              <?php foreach ($technicians as $t): ?>
              <option value="<?= $t['id'] ?>" <?= $ticket['technician_id'] == $t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Endpreis (€)</label>
            <input type="text" name="final_price" class="form-control" id="mgmt_price" value="<?= $ticket['final_price'] ? number_format($ticket['final_price'], 2, ',', '.') : '' ?>" placeholder="89,90">
          </div>
          
          <div id="mgmt_changes_hint" style="display:none;background:#1e3a5f;border:1px solid #2563eb;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#93c5fd;">
            ⚡ Ungespeicherte Änderungen
          </div>

          <button type="submit" id="mgmt_save_btn" class="btn btn-primary" style="width:100%;padding:10px;font-size:15px;font-weight:600;" disabled>
            💾 Änderungen speichern
          </button>
        </form>
        
        <script>
        (function(){
          const origStatus = <?= json_encode($ticket['status']) ?>;
          const origTech   = <?= json_encode((string)($ticket['technician_id'] ?? '')) ?>;
          const origPrice  = <?= json_encode($ticket['final_price'] ? number_format($ticket['final_price'], 2, ',', '.') : '') ?>;
          
          const elStatus = document.getElementById('mgmt_status');
          const elTech   = document.getElementById('mgmt_tech');
          const elPrice  = document.getElementById('mgmt_price');
          const elBtn    = document.getElementById('mgmt_save_btn');
          const elHint   = document.getElementById('mgmt_changes_hint');
          
          function checkChanges(){
            const changed = (elStatus.value !== origStatus) 
                         || (elTech.value !== origTech) 
                         || (elPrice.value !== origPrice);
            elBtn.disabled = !changed;
            elBtn.style.opacity = changed ? '1' : '0.5';
            elHint.style.display = changed ? 'block' : 'none';
          }
          
          elStatus.addEventListener('change', checkChanges);
          elTech.addEventListener('change', checkChanges);
          elPrice.addEventListener('input', checkChanges);
          checkChanges();
        })();
        </script>
        
        <?php if (!empty($ticket['estimated_price'] ?? null)): ?>
        <p style="font-size:13px;color:#64748b;">KVA: <strong><?= number_format($ticket['estimated_price'], 2, ',', '.') ?> €</strong></p>
        <?php endif; ?>
        <?php if ($ticket['final_price']): ?>
        <p style="font-size:13px;color:#065f46;">Endpreis: <strong><?= number_format($ticket['final_price'], 2, ',', '.') ?> €</strong></p>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Kunden-Link -->
    <div class="card">
      <div class="card-header"><h3>🔗 Kunden-Link</h3></div>
      <div class="card-body">
        <p style="font-size:13px;color:#64748b;margin-bottom:10px;">Dieser Link ermöglicht dem Kunden das Ticket zu verfolgen und zu antworten:</p>
        <div style="background:#f1f5f9;padding:8px;border-radius:4px;font-size:11px;word-break:break-all;font-family:monospace;">
          <?= BASE_URL ?>/customer/view.php?token=<?= h($ticket['customer_token']) ?>
        </div>
        <button onclick="navigator.clipboard.writeText('<?= BASE_URL ?>/customer/view.php?token=<?= $ticket['customer_token'] ?>');this.textContent='✅ Kopiert!';setTimeout(()=>this.textContent='📋 Link kopieren',2000)" class="btn btn-secondary btn-sm" style="margin-top:8px;width:100%;">📋 Link kopieren</button>
        <?php if (!empty($ticket['closed_at'] ?? null)): ?>
        <p style="margin-top:10px;font-size:12px;color:#64748b;">Geschlossen: <?= formatDate($ticket['closed_at']) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>