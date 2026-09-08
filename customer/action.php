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

// ================================================================
// Ein-Klick-Aktion aus der E-Mail: Kostenvoranschlag
// zustimmen oder ablehnen – ohne Login ins Kundenportal.
//
// Sicherheit: Der GET-Aufruf zeigt nur eine Bestätigungsseite an
// (E-Mail-Link-Scanner lösen sonst versehentlich Aktionen aus).
// Erst der Klick auf "Bestätigen" (POST) führt die Aktion aus.
// ================================================================

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail_inbox.php'; // für applyPriceDecision()

startSession();

$token     = trim($_REQUEST['token'] ?? '');
$commentId = (int)($_REQUEST['c'] ?? 0);
$do        = ($_REQUEST['do'] ?? '') === 'accept' ? 'accepted' : 'declined';

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

// Kostenvoranschlag laden
$stmtC = $db->prepare("SELECT * FROM ticket_comments WHERE id = ? AND ticket_id = ? AND price_proposal IS NOT NULL");
$stmtC->execute([$commentId, $ticket['id']]);
$kva = $stmtC->fetch();

$companyName = getSetting('company_name', defined('COMPANY_NAME') ? COMPANY_NAME : 'RepairDesk');
$portalLink  = BASE_URL . '/customer/view.php?token=' . urlencode($token);

// CSRF-Token für das Bestätigungsformular
$csrfKey = 'csrf_action_' . $ticket['id'];
if (empty($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[$csrfKey];

$state = 'confirm'; // confirm | done | already | invalid
$doneResponse = '';

if (!$kva) {
    $state = 'invalid';
} elseif (!empty($kva['customer_response'])) {
    $state = 'already';
    $doneResponse = $kva['customer_response'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION[$csrfKey] ?? '', (string)$_POST['csrf_token'])) {
        die('<h2 style="font-family:sans-serif;padding:40px;color:red;">Ungültige Anfrage.</h2>');
    }
    $response = ($_POST['response'] ?? '') === 'accepted' ? 'accepted' : 'declined';

    // Direkt diesen KVA beantworten (gleiche Logik wie Portal)
    $db->prepare("UPDATE ticket_comments SET customer_response = ? WHERE id = ? AND ticket_id = ?")
       ->execute([$response, $kva['id'], $ticket['id']]);

    if ($response === 'accepted') {
        $db->prepare("UPDATE tickets SET status = 'in_bearbeitung' WHERE id = ?")->execute([$ticket['id']]);
        $notiz = "Kunde hat Kostenvoranschlag AKZEPTIERT (per E-Mail-Button).";
    } else {
        $db->prepare("UPDATE tickets SET status = 'offen' WHERE id = ?")->execute([$ticket['id']]);
        $notiz = "Kunde hat Kostenvoranschlag ABGELEHNT (per E-Mail-Button).";
    }

    $db->prepare("INSERT INTO ticket_comments (ticket_id, author_type, author_name, message, is_internal) VALUES (?, 'customer', ?, ?, 1)")
       ->execute([$ticket['id'], $ticket['customer_name'], $notiz]);

    // Firma benachrichtigen
    $companyEmail = getSetting('company_email', defined('COMPANY_EMAIL') ? COMPANY_EMAIL : '');
    if (!empty($companyEmail)) {
        sendMail(
            $companyEmail,
            $companyName,
            "Entscheidung Ticket: " . $ticket['ticket_number'],
            "Der Kunde hat den Kostenvoranschlag per E-Mail-Button <b>" . ($response === 'accepted' ? 'AKZEPTIERT' : 'ABGELEHNT') . "</b>.<br><br>"
            . "Ticket: " . h($ticket['ticket_number']) . "<br>"
            . "<a href='" . BASE_URL . "/ticket_view.php?id=" . $ticket['id'] . "'>Zum Ticket</a>"
        );
    }

    $state = 'done';
    $doneResponse = $response;
}

$price = $kva ? number_format((float)$kva['price_proposal'], 2, ',', '.') . ' €' : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Kostenvoranschlag – <?= h($companyName) ?></title>
<style>
  body { margin:0; background:#f4f6f9; font-family:Arial,Helvetica,sans-serif; color:#1e293b; }
  .wrap { max-width:520px; margin:40px auto; padding:0 16px; }
  .card { background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.08); overflow:hidden; }
  .head { background:#2563eb; color:#fff; padding:20px 24px; }
  .head h1 { margin:0; font-size:19px; }
  .body { padding:26px 24px; }
  .price { background:#fef3c7; border:1px solid #fbbf24; border-radius:8px; padding:16px; margin:16px 0; font-size:17px; text-align:center; }
  .btn { display:inline-block; border:none; cursor:pointer; padding:14px 26px; border-radius:8px; font-size:16px; font-weight:bold; text-decoration:none; }
  .btn-green { background:#10b981; color:#fff; }
  .btn-red   { background:#ef4444; color:#fff; }
  .btn-grey  { background:#e2e8f0; color:#1e293b; font-weight:normal; font-size:14px; padding:10px 18px; }
  .center { text-align:center; }
  .muted { color:#64748b; font-size:13px; }
  .ok    { background:#ecfdf5; border:1px solid #10b981; color:#065f46; border-radius:8px; padding:16px; }
  .warn  { background:#fef2f2; border:1px solid #ef4444; color:#7f1d1d; border-radius:8px; padding:16px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="head"><h1><?= h($companyName) ?> – Ticket #<?= h($ticket['ticket_number']) ?></h1></div>
    <div class="body">

    <?php if ($state === 'invalid'): ?>
      <div class="warn">Dieser Kostenvoranschlag wurde nicht gefunden oder ist nicht mehr gültig.</div>
      <p class="center" style="margin-top:20px;"><a class="btn btn-grey" href="<?= h($portalLink) ?>">Ticket online ansehen</a></p>

    <?php elseif ($state === 'already'): ?>
      <div class="ok">
        Sie haben diesen Kostenvoranschlag bereits
        <strong><?= $doneResponse === 'accepted' ? 'akzeptiert ✅' : 'abgelehnt ❌' ?></strong>.
      </div>
      <p class="center" style="margin-top:20px;"><a class="btn btn-grey" href="<?= h($portalLink) ?>">Ticket online ansehen</a></p>

    <?php elseif ($state === 'done'): ?>
      <?php if ($doneResponse === 'accepted'): ?>
        <div class="ok">✅ <strong>Vielen Dank!</strong> Sie haben den Kostenvoranschlag akzeptiert.<br>Wir kümmern uns umgehend um Ihr Gerät.</div>
      <?php else: ?>
        <div class="ok">Sie haben den Kostenvoranschlag <strong>abgelehnt</strong>.<br>Wir setzen uns mit Ihnen in Verbindung.</div>
      <?php endif; ?>
      <p class="center" style="margin-top:20px;"><a class="btn btn-grey" href="<?= h($portalLink) ?>">Ticket online ansehen</a></p>

    <?php else: /* confirm */ ?>
      <p>Hallo <?= h($ticket['customer_name']) ?>,</p>
      <p>bitte bestätigen Sie Ihre Entscheidung zum Kostenvoranschlag:</p>
      <div class="price">💰 <strong>Kostenvoranschlag: <?= h($price) ?></strong></div>

      <form method="post" class="center" style="margin-top:22px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="c" value="<?= (int)$kva['id'] ?>">
        <?php if ($do === 'accepted'): ?>
          <button type="submit" name="response" value="accepted" class="btn btn-green">✅ Ja, Kostenvoranschlag annehmen</button>
          <p style="margin-top:16px;"><button type="submit" name="response" value="declined" class="btn btn-grey">Stattdessen ablehnen</button></p>
        <?php else: ?>
          <button type="submit" name="response" value="declined" class="btn btn-red">❌ Ja, Kostenvoranschlag ablehnen</button>
          <p style="margin-top:16px;"><button type="submit" name="response" value="accepted" class="btn btn-grey">Stattdessen annehmen</button></p>
        <?php endif; ?>
      </form>
      <p class="muted center" style="margin-top:20px;">Ein Klick genügt – Sie müssen sich nicht anmelden.</p>
    <?php endif; ?>

    </div>
  </div>
  <p class="muted center" style="margin-top:14px;">Diese Seite wurde über einen Link aus Ihrer E-Mail geöffnet.</p>
</div>
</body>
</html>
