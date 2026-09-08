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
ob_start(); // Output Buffering – damit header() nach HTML-Output noch funktioniert

require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $db     = getDB();
    $tech   = getCurrentTechnician();
    $action = $_POST['action'] ?? '';

    if ($action === 'enable_start') {
        $secret = TOTP::generateSecret();
        $_SESSION['totp_pending_secret'] = $secret;
        header('Location: ' . BASE_URL . '/2fa_setup.php?step=verify');
        exit;
    }

    if ($action === 'enable_confirm') {
        $secret = $_SESSION['totp_pending_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if ($secret && TOTP::verify($secret, $code)) {
            $db->prepare("UPDATE technicians SET totp_secret=?, totp_enabled=1 WHERE id=?")
               ->execute([$secret, $tech['id']]);
            unset($_SESSION['totp_pending_secret']);
            flash('2FA erfolgreich aktiviert! Ab sofort wird beim Login ein Code abgefragt.', 'success');
        } else {
            flash('Falscher Code. Bitte nochmal versuchen.', 'error');
        }
        header('Location: ' . BASE_URL . '/2fa_setup.php?step=verify');
        exit;
    }

    if ($action === 'disable') {
        $code = trim($_POST['code'] ?? '');
        if (TOTP::verify($tech['totp_secret'], $code)) {
            $db->prepare("UPDATE technicians SET totp_secret=NULL, totp_enabled=0 WHERE id=?")
               ->execute([$tech['id']]);
            flash('2FA wurde deaktiviert.', 'success');
        } else {
            flash('Falscher Code – 2FA wurde nicht deaktiviert.', 'error');
        }
        header('Location: ' . BASE_URL . '/2fa_setup.php');
        exit;
    }
}

$pageTitle  = '2-Faktor-Authentifizierung';
$activeMenu = '';
require_once __DIR__ . '/../includes/header.php';

$db   = getDB();
$tech = getCurrentTechnician();

$stmt = $db->prepare("SELECT totp_enabled, totp_secret FROM technicians WHERE id=?");
$stmt->execute([$tech['id']]);
$techData = $stmt->fetch();
$enabled  = (bool)$techData['totp_enabled'];
$step     = $_GET['step'] ?? 'status';
$pending  = $_SESSION['totp_pending_secret'] ?? '';

$appName  = getSetting('app_name', 'RepairDesk');
$qrData   = $pending ? TOTP::getQRCodeBase64($pending, $tech['email'], $appName) : '';
$otpUri   = $pending ? TOTP::getQRUrl($pending, $tech['email'], $appName) : '';
?>

<div style="max-width:540px;margin:0 auto;">

<?php if ($step === 'verify' && $pending): ?>
  <!-- ── Schritt 2: QR scannen & Code bestätigen ── -->
  <div class="card">
    <div class="card-header"><h3>📱 2FA einrichten – Schritt 2 von 2</h3></div>
    <div class="card-body">
      <p style="margin-bottom:16px;font-size:14px;color:#374151;">
        Scanne diesen QR-Code mit deiner Authenticator-App
        (<strong>Google Authenticator</strong>, <strong>Authy</strong>, <strong>Microsoft Authenticator</strong>):
      </p>

      <div style="text-align:center;margin:20px 0;">
        <?php if ($qrData): ?>
          <img src="<?= $qrData ?>" alt="QR Code" width="200" height="200"
               style="border:8px solid #fff;box-shadow:0 2px 12px rgba(0,0,0,.15);border-radius:8px;">
        <?php else: ?>
          <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:16px;font-size:13px;color:#713f12;">
            ⚠️ QR Code konnte nicht geladen werden.<br>
            Bitte Secret manuell eingeben (siehe unten).
          </div>
        <?php endif; ?>
      </div>

      <p style="font-size:12px;color:#94a3b8;margin-bottom:6px;">OTPAuth URI (für kompatible Apps):</p>
      <div style="background:#f1f5f9;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:11px;word-break:break-all;margin-bottom:12px;">
        <?= h($otpUri) ?>
      </div>

      <p style="font-size:13px;color:#64748b;margin-bottom:6px;">Oder Secret manuell eingeben:</p>
      <div style="background:#f1f5f9;padding:10px 14px;border-radius:6px;font-family:monospace;font-size:15px;letter-spacing:2px;margin-bottom:20px;word-break:break-all;">
        <?= h($pending) ?>
      </div>

      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="action"     value="enable_confirm">
        <div class="form-group">
          <label class="form-label">6-stelliger Code aus der App <span class="required">*</span></label>
          <input type="text" name="code" class="form-control" maxlength="6" pattern="\d{6}"
                 placeholder="000000" autocomplete="one-time-code"
                 style="font-size:24px;letter-spacing:8px;text-align:center;max-width:200px;" autofocus>
          <p class="form-help">Gib den aktuellen 6-stelligen Code aus der App ein um die Einrichtung zu bestätigen.</p>
        </div>
        <div style="display:flex;gap:10px;">
          <a href="<?= BASE_URL ?>/2fa_setup.php" class="btn btn-secondary">Abbrechen</a>
          <button type="submit" class="btn btn-primary">✅ Bestätigen & aktivieren</button>
        </div>
      </form>
    </div>
  </div>

<?php elseif ($enabled): ?>
  <!-- ── 2FA ist aktiv ── -->
  <div class="card">
    <div class="card-header"><h3>🔐 2-Faktor-Authentifizierung</h3></div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:14px;padding:16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;margin-bottom:20px;">
        <span style="font-size:32px;">✅</span>
        <div>
          <p style="font-weight:700;color:#065f46;">2FA ist aktiv</p>
          <p style="font-size:13px;color:#065f46;">Dein Konto ist mit 2-Faktor-Authentifizierung geschützt.</p>
        </div>
      </div>

      <p style="font-size:14px;color:#64748b;margin-bottom:16px;">
        Um 2FA zu deaktivieren, gib deinen aktuellen Authenticator-Code ein:
      </p>

      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="action"     value="disable">
        <div class="form-group">
          <label class="form-label">Bestätigungscode</label>
          <input type="text" name="code" class="form-control" maxlength="6" pattern="\d{6}"
                 placeholder="000000"
                 style="font-size:20px;letter-spacing:6px;text-align:center;max-width:180px;">
        </div>
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('2FA wirklich deaktivieren? Das macht dein Konto weniger sicher!')">
          🔓 2FA deaktivieren
        </button>
      </form>
    </div>
  </div>

<?php else: ?>
  <!-- ── 2FA nicht aktiv ── -->
  <div class="card">
    <div class="card-header"><h3>🔐 2-Faktor-Authentifizierung</h3></div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:14px;padding:16px;background:#fef9c3;border:1px solid #fde047;border-radius:8px;margin-bottom:20px;">
        <span style="font-size:32px;">⚠️</span>
        <div>
          <p style="font-weight:700;color:#713f12;">2FA ist nicht aktiv</p>
          <p style="font-size:13px;color:#713f12;">Dein Konto ist nur durch ein Passwort geschützt.</p>
        </div>
      </div>

      <p style="font-size:14px;color:#374151;line-height:1.7;margin-bottom:20px;">
        Mit 2FA wird beim Login zusätzlich ein <strong>6-stelliger Code</strong> aus einer Authenticator-App abgefragt.
        Selbst wenn jemand dein Passwort kennt, kommt er ohne dein Handy nicht rein.
      </p>

      <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
        Du brauchst eine dieser Apps auf deinem Handy:
        <strong>Google Authenticator</strong>, <strong>Authy</strong> oder <strong>Microsoft Authenticator</strong>.
      </p>

      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="action"     value="enable_start">
        <button type="submit" class="btn btn-primary">🔐 2FA jetzt aktivieren</button>
      </form>
    </div>
  </div>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>