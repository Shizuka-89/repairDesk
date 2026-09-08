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

// Kundenansicht
require_once __DIR__ . '/../includes/functions.php';

startSession();

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    die('<h2 style="font-family:sans-serif;padding:40px;">Ungültiger Link.</h2>');
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT t.*,
           CONCAT(COALESCE(c.lastname,''), IF(c.firstname != '' AND c.lastname != '', ' ', ''), COALESCE(c.firstname,'')) as customer_name,
           c.email as customer_email
    FROM tickets t
    JOIN customers c ON t.customer_id = c.id
    WHERE t.customer_token = ?
");
$stmt->execute([$token]);
$ticket = $stmt->fetch();

if (!$ticket) {
    die('<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Nicht gefunden</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h2>❌ Ticket nicht gefunden.</h2><p>Der Link ist ungültig oder abgelaufen.</p></body></html>');
}

// Kommentare via Prepared Statement
$stmtC = $db->prepare("SELECT * FROM ticket_comments WHERE ticket_id = ? AND is_internal = 0 ORDER BY created_at ASC");
$stmtC->execute([$ticket['id']]);
$comments = $stmtC->fetchAll();

// CSRF-Token für Kundenformulare (ticketspezifisch)
$csrfKey = 'csrf_customer_' . $ticket['id'];
if (empty($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[$csrfKey];

// Empfänger dynamisch aus Settings laden
$companyName  = getSetting('company_name',  defined('COMPANY_NAME')  ? COMPANY_NAME  : 'RepairDesk');
$companyEmail = getSetting('company_email', defined('COMPANY_EMAIL') ? COMPANY_EMAIL : '');

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION[$csrfKey] ?? '')) {
        die('<h2 style="font-family:sans-serif;padding:40px;color:red;">Ungültige Anfrage.</h2>');
    }
    $action = $_POST['action'] ?? '';

    // ── Kostenvoranschlag bestätigen/ablehnen ─────────────────────
    if ($action === 'respond_price') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $response  = ($_POST['response'] ?? '') === 'accepted' ? 'accepted' : 'declined';

        $db->prepare("UPDATE ticket_comments SET customer_response = ? WHERE id = ? AND ticket_id = ?")
           ->execute([$response, $commentId, $ticket['id']]);

        if ($response === 'accepted') {
            $db->prepare("UPDATE tickets SET status = 'in_bearbeitung' WHERE id = ?")->execute([$ticket['id']]);
            $successMsg = '✅ Sie haben den Kostenvoranschlag akzeptiert. Wir werden uns umgehend um Ihr Gerät kümmern.';
            $notiz = "Kunde hat Kostenvoranschlag AKZEPTIERT.";
        } else {
            $db->prepare("UPDATE tickets SET status = 'offen' WHERE id = ?")->execute([$ticket['id']]);
            $successMsg = 'Sie haben den Kostenvoranschlag abgelehnt. Wir werden uns mit Ihnen in Verbindung setzen.';
            $notiz = "Kunde hat Kostenvoranschlag ABGELEHNT.";
        }

        // Interne Notiz
        $db->prepare("INSERT INTO ticket_comments (ticket_id, author_type, author_name, message, is_internal) VALUES (?, 'customer', ?, ?, 1)")
           ->execute([$ticket['id'], $ticket['customer_name'], $notiz]);

        // E-Mail an Techniker – dynamisch aus Settings
        sendMail(
            $companyEmail,
            $companyName,
            "Entscheidung Ticket: " . $ticket['ticket_number'],
            "Der Kunde hat den Kostenvoranschlag soeben <b>" . ($response === 'accepted' ? 'AKZEPTIERT' : 'ABGELEHNT') . "</b>.<br><br>"
            . "Ticket: " . h($ticket['ticket_number'])
        );

        // Ticket neu laden
        $stmt->execute([$token]);
        $ticket = $stmt->fetch();
        $stmtC->execute([$ticket['id']]);
        $comments = $stmtC->fetchAll();
    }

    // ── Kunden-Nachricht ──────────────────────────────────────────
    elseif ($action === 'customer_reply') {
        $message = trim($_POST['message'] ?? '');
        if (!empty($message)) {
            $db->prepare("INSERT INTO ticket_comments (ticket_id, author_type, author_name, message, is_internal) VALUES (?, 'customer', ?, ?, 0)")
               ->execute([$ticket['id'], $ticket['customer_name'], $message]);

            // E-Mail an Techniker – dynamisch aus Settings
            sendMail(
                $companyEmail,
                $companyName,
                "Update zu Ticket: " . $ticket['ticket_number'],
                "Hallo " . h($companyName) . " Team,<br><br>"
                . "Der Kunde <b>" . h($ticket['customer_name']) . "</b> hat eine Nachricht gesendet:<br><br>"
                . "<i>" . nl2br(h($message)) . "</i><br><br>"
                . "<a href='" . BASE_URL . "/ticket_view.php?id=" . $ticket['id'] . "'>Zum Ticket</a>"
            );

            $successMsg = '✅ Ihre Nachricht wurde gesendet.';
            $stmtC->execute([$ticket['id']]);
            $comments = $stmtC->fetchAll();
        } else {
            $errorMsg = 'Bitte geben Sie eine Nachricht ein.';
        }
    }
}

$statusLabels = [
    'offen'             => ['Offen',                      '#3b82f6'],
    'in_bearbeitung'    => ['In Bearbeitung',              '#f59e0b'],
    'warten_auf_kunde'  => ['Ihre Rückmeldung erforderlich','#8b5cf6'],
    'abgeschlossen'     => ['Abgeschlossen',               '#10b981'],
    'storniert'         => ['Storniert',                   '#ef4444'],
];
$statusInfo = $statusLabels[$ticket['status']] ?? ['Unbekannt', '#6b7280'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Auftrag <?= h($ticket['ticket_number']) ?> – <?= h($companyName) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #1e293b; }
.header { background: #1e293b; color: white; padding: 20px; text-align: center; }
.header h1 { font-size: 22px; }
.header p { color: #94a3b8; font-size: 14px; margin-top: 4px; }
.container { max-width: 700px; margin: 30px auto; padding: 0 16px 40px; }
.card { background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px; overflow: hidden; }
.card-header { background: #f8fafc; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 15px; }
.card-body { padding: 20px; }
.status-badge { display: inline-block; padding: 5px 14px; border-radius: 20px; font-size: 14px; font-weight: 600; color: white; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.detail-item label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 2px; }
.detail-item p { font-size: 14px; font-weight: 500; }
.comment { border-radius: 8px; padding: 14px; margin-bottom: 12px; }
.comment.technician { background: #f8fafc; border-left: 3px solid #3b82f6; }
.comment.customer { background: #eff6ff; border-left: 3px solid #06b6d4; }
.comment-meta { font-size: 12px; color: #64748b; margin-bottom: 6px; }
.comment-body { font-size: 14px; line-height: 1.6; }
.price-box { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: 16px; margin-top: 12px; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
.btn-success { background: #10b981; color: white; }
.btn-danger { background: #ef4444; color: white; }
.btn-primary { background: #2563eb; color: white; }
.btn:hover { opacity: 0.9; }
textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; min-height: 80px; resize: vertical; }
textarea:focus { outline: none; border-color: #2563eb; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
.alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.ticket-num { font-family: monospace; font-size: 20px; font-weight: 700; color: #2563eb; }
@media (max-width: 500px) { .detail-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<div class="header">
  <h1>🔧 <?= h($companyName) ?></h1>
  <p>Reparaturauftrag-Verfolgung</p>
</div>

<div class="container">

  <?php if ($successMsg): ?>
  <div class="alert alert-success"><?= h($successMsg) ?></div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
  <div class="alert alert-error"><?= h($errorMsg) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
          <p style="font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Auftragsnummer</p>
          <span class="ticket-num"><?= h($ticket['ticket_number']) ?></span>
          <p style="margin-top:6px;font-size:16px;font-weight:600;"><?= h($ticket['title']) ?></p>
        </div>
        <div style="text-align:right;">
          <span class="status-badge" style="background:<?= $statusInfo[1] ?>;"><?= $statusInfo[0] ?></span>
          <p style="font-size:12px;color:#94a3b8;margin-top:6px;">Erstellt: <?= formatDate($ticket['created_at']) ?></p>
        </div>
      </div>
    </div>
  </div>

  <?php if (getSetting('show_device_info', '1') === '1'): ?>
  <div class="card">
    <div class="card-header">📱 <?= h(getSetting('label_device_section', 'Geräteinformationen')) ?></div>
    <div class="card-body">
      <div class="detail-grid">
        <div class="detail-item">
          <label><?= h(getSetting('label_device_model', 'Gerät / Modell')) ?></label>
          <p><?= h($ticket['device_model'] ?: '—') ?></p>
        </div>
        <div class="detail-item">
          <label><?= h(getSetting('label_serial_number', 'Seriennummer / IMEI')) ?></label>
          <p style="font-family:monospace;"><?= h($ticket['serial_number'] ?: '—') ?></p>
        </div>
        <div class="detail-item">
          <label><?= h(getSetting('label_condition_on_arrival', 'Zustand bei Annahme')) ?></label>
          <p><?= h($ticket['condition_on_arrival'] ?: '—') ?></p>
        </div>
        <?php if ($ticket['estimated_price']): ?>
        <div class="detail-item">
          <label>Kostenvoranschlag</label>
          <p style="color:#92400e;font-weight:700;"><?= number_format($ticket['estimated_price'], 2, ',', '.') ?> €</p>
        </div>
        <?php endif; ?>
        <?php if ($ticket['final_price']): ?>
        <div class="detail-item">
          <label>Endpreis</label>
          <p style="color:#065f46;font-weight:700;"><?= number_format($ticket['final_price'], 2, ',', '.') ?> €</p>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($ticket['damage_description']): ?>
      <div style="margin-top:14px;">
        <label style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:4px;"><?= h(getSetting('label_damage_description', 'Schadensbeschreibung')) ?></label>
        <p style="font-size:14px;line-height:1.6;"><?= nl2br(h($ticket['damage_description'])) ?></p>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($comments)): ?>
  <div class="card">
    <div class="card-header">💬 Nachrichten</div>
    <div class="card-body">
      <?php foreach ($comments as $c): ?>
      <div class="comment <?= $c['author_type'] ?>">
        <div class="comment-meta">
          <?= $c['author_type'] === 'technician' ? '👨‍🔧 ' . h($companyName) : '👤 Sie' ?>
          &nbsp;·&nbsp; <?= formatDate($c['created_at']) ?>
        </div>
        <div class="comment-body"><?= nl2br(h($c['message'])) ?></div>

        <?php if ($c['price_proposal'] !== null && $c['author_type'] === 'technician'): ?>
        <div class="price-box">
          <p style="font-weight:700;font-size:16px;margin-bottom:8px;">
            💰 Kostenvoranschlag: <?= number_format($c['price_proposal'], 2, ',', '.') ?> €
          </p>
          <?php if ($c['customer_response']): ?>
            <p style="font-weight:600;color:<?= $c['customer_response'] === 'accepted' ? '#065f46' : '#991b1b' ?>;">
              <?= $c['customer_response'] === 'accepted' ? '✅ Von Ihnen akzeptiert' : '❌ Von Ihnen abgelehnt' ?>
            </p>
          <?php else: ?>
            <p style="margin-bottom:12px;font-size:14px;">Bitte bestätigen Sie, ob wir die Reparatur zu diesem Preis durchführen sollen:</p>
            <form method="post" action="" style="display:flex;gap:10px;flex-wrap:wrap;">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="respond_price">
              <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
              <button type="submit" name="response" value="accepted" class="btn btn-success">✅ Akzeptieren</button>
              <button type="submit" name="response" value="declined" class="btn btn-danger">❌ Ablehnen</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!in_array($ticket['status'], ['abgeschlossen', 'storniert'])): ?>
  <div class="card">
    <div class="card-header">✏️ Nachricht senden</div>
    <div class="card-body">
      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="customer_reply">
        <div style="margin-bottom:12px;">
          <textarea name="message" placeholder="Ihre Fragen oder Anmerkungen..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary">📨 Senden</button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div style="background:#f0fdf4;border:1px solid #a7f3d0;border-radius:8px;padding:16px;text-align:center;">
    <p style="color:#065f46;font-weight:600;font-size:15px;">✅ Dieser Auftrag ist abgeschlossen.</p>
    <p style="color:#64748b;font-size:14px;margin-top:4px;">Vielen Dank für Ihr Vertrauen in <?= h($companyName) ?>!</p>
  </div>
  <?php endif; ?>

  <p style="text-align:center;color:#94a3b8;font-size:12px;margin-top:20px;">
    <?= h($companyName) ?> · <?= h($companyEmail) ?><?= COMPANY_PHONE ? ' · ' . h(COMPANY_PHONE) : '' ?>
  </p>
</div>

</body>
</html>